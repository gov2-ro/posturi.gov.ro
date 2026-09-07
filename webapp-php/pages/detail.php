<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); echo "Not found"; exit; }

$stmt = db()->prepare("
    SELECT j.*, e.name AS employer_name, e.slug AS employer_slug,
           jd.name AS judet_name, jd.slug AS judet_slug_val
    FROM job_postings j
    LEFT JOIN employers e ON e.id = j.employer_id
    LEFT JOIN judete jd ON jd.id = j.judet_id
    WHERE j.id = ?
");
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p) { http_response_code(404); echo "Anunț negăsit."; exit; }

// One canonical URL per posting: `/job/1234/` and stale slugs redirect to it.
$canonical_path = job_url($p);
if (isset($requested_path) && rtrim($requested_path, '/') !== rtrim($canonical_path, '/')) {
    header('Location: ' . $canonical_path, true, 301);
    exit;
}

$inferred = json_decode($p['inferred'] ?? '{}', true) ?: [];
$other_links = json_decode($p['other_links'] ?? '[]', true) ?: [];
$anomaly_flags_list = json_decode($p['inf_anomaly_flags'] ?? '[]', true) ?: [];

// Calendar events
$ev_stmt = db()->prepare("SELECT * FROM calendar_events WHERE posting_id = ? ORDER BY data, id");
$ev_stmt->execute([$id]);
$events = $ev_stmt->fetchAll();

// Render body
$body_html = '';
if ($p['body_markdown']) {
    $body_html = render_markdown($p['body_markdown']);
}

// Schema sections
$schema_sections = render_schema_sections($p['schema_json'] ?? null);

$days = days_until($p['expires_at']);

// Return to the filtered list the reader came from, when there is one.
// `javascript:history.back()` broke for anyone arriving from a feed, a shared
// link or a search result.
$back_url   = '/';
$back_label = 'Toate posturile';
if ($ref = ($_SERVER['HTTP_REFERER'] ?? '')) {
    $rp = parse_url($ref);
    if (($rp['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? '') && ($rp['path'] ?? '/') === '/') {
        $back_url   = '/' . (isset($rp['query']) ? '?' . $rp['query'] : '');
        $back_label = isset($rp['query']) ? 'Înapoi la căutare' : 'Toate posturile';
    }
}

// ---- Head metadata ----
$page_title = $p['title'] ?: 'Anunț';

$_desc_src = trim(strip_tags($body_html)) ?: (string)$p['title'];
$_desc_src = preg_replace('/\s+/u', ' ', $_desc_src) ?? '';
$meta_description = trim(implode(' · ', array_filter([
    $p['employer_name'] ?? null,
    place_label($p) ?: null,
    $p['expires_at'] ? 'termen ' . fmt_date($p['expires_at']) : null,
])) . '. ' . mb_substr($_desc_src, 0, 150));

// ---- JSON-LD (Google Jobs) ----
// Built from the same columns the page renders, plus the Schema.org-shaped
// fields the v2 extraction prompt already produces.
$schema = json_decode($p['schema_json'] ?? 'null', true);
$schema = is_array($schema) ? $schema : [];

$ld_description = '';
if ($schema_sections) {
    foreach ($schema_sections as $sec) {
        $ld_description .= '<h3>' . e($sec['label']) . '</h3>' . $sec['html'];
    }
}
if (trim(strip_tags($ld_description)) === '') $ld_description = $body_html;
if (trim(strip_tags($ld_description)) === '') $ld_description = e((string)$p['title']);

$employment_type = match ($inferred['work_type'] ?? '') {
    'norma_intreaga' => 'FULL_TIME',
    'norma_partiala' => 'PART_TIME',
    default          => stripos((string)$p['job_type'], 'temporar') !== false ? 'TEMPORARY' : 'FULL_TIME',
};

$ld = [
    '@context'    => 'https://schema.org/',
    '@type'       => 'JobPosting',
    'title'       => (string)$p['title'],
    'description' => $ld_description,
    'identifier'  => [
        '@type' => 'PropertyValue',
        'name'  => 'posturi.gov2.ro',
        'value' => (string)$p['id'],
    ],
    'employmentType'     => $employment_type,
    'hiringOrganization' => array_filter([
        '@type' => 'Organization',
        'name'  => (string)($p['employer_name'] ?: 'Instituție publică'),
        'sameAs' => $p['url'] ?: null,
    ]),
    'jobLocation' => [
        '@type'   => 'Place',
        'address' => array_filter([
            '@type'           => 'PostalAddress',
            'addressLocality' => ($p['locality'] ?? '') ?: null,
            'addressRegion'   => $p['judet_name'] ?: null,
            'addressCountry'  => 'RO',
        ]),
    ],
    'url' => site_origin() . $canonical_path,
];

if ($p['published_at']) {
    $ld['datePosted'] = substr($p['published_at'], 0, 10);
}
if ($p['expires_at']) {
    try {
        $vt = new DateTime(substr($p['expires_at'], 0, 10) . ' 23:59:59', new DateTimeZone('Europe/Bucharest'));
        $ld['validThrough'] = $vt->format('c');
    } catch (Exception $e) {
        $ld['validThrough'] = substr($p['expires_at'], 0, 10);
    }
}
if (($p['nr_posturi'] ?? 0) > 1) {
    $ld['totalJobOpenings'] = (int)$p['nr_posturi'];
}
if ($inferred['salary_min'] ?? null) {
    $ld['baseSalary'] = [
        '@type'    => 'MonetaryAmount',
        'currency' => 'RON',
        'value'    => array_filter([
            '@type'    => 'QuantitativeValue',
            'minValue' => (float)$inferred['salary_min'],
            'maxValue' => isset($inferred['salary_max']) ? (float)$inferred['salary_max'] : null,
            'unitText' => 'MONTH',
        ], fn($v) => $v !== null),
    ];
}
// The v2 extraction prompt already produces Schema.org JobPosting property
// names, so every text property it filled can go straight into the JSON-LD —
// previously only two of them did.
foreach ([
    'responsibilities',
    'educationRequirements',
    'experienceRequirements',
    'qualifications',
    'skills',
    'jobBenefits',
    'workHours',
] as $prop) {
    $value = $schema[$prop] ?? null;
    if (is_array($value)) $value = implode(', ', array_filter($value));
    $value = trim((string)$value);
    if ($value !== '') $ld[$prop] = $value;
}

// occupationalCategory carries the inferred profession family, but only when
// the classifier was confident — a wrong category is worse than none.
$ld_family = (string)($inferred['profession_family'] ?? '');
if ($ld_family !== '' && $ld_family !== 'altele'
    && (float)($inferred['profession_family_confidence'] ?? 0) >= FAMILY_MIN_CONFIDENCE) {
    $ld['occupationalCategory'] = ucfirst($ld_family);
}

// Applications go through the source site, never through us.
$ld['directApply'] = false;

$head_extra = "\n  <script type=\"application/ld+json\">"
    . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
    . "</script>\n";

require __DIR__ . '/../inc/header.php';
?>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-6">

  <nav aria-label="Firimituri" class="mb-4 text-sm">
    <a href="<?= e($back_url) ?>" class="text-gov hover:underline">← <?= e($back_label) ?></a>
  </nav>

  <!-- Title block -->
  <div class="border-b border-border-warm pb-5 mb-6">
    <h1 class="font-display text-2xl sm:text-3xl font-semibold italic text-ink leading-tight mb-2">
      <?= e($p['title']) ?>
    </h1>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-ink-muted">
      <a href="/angajator/<?= e($p['employer_slug'] ?? '') ?>/"
         class="font-medium text-gov hover:underline"><?= e($p['employer_name'] ?? '') ?></a>
      <?php if ($place = place_label($p)): ?>
        <span aria-hidden="true">·</span>
        <span><?= e($place) ?></span>
      <?php endif; ?>
      <?php if ($p['published_at']): ?>
        <span aria-hidden="true">·</span>
        <span class="font-mono text-xs">Publicat <time datetime="<?= e(substr($p['published_at'], 0, 10)) ?>"><?= fmt_date($p['published_at']) ?></time></span>
      <?php endif; ?>
      <a href="<?= e($p['url']) ?>" target="_blank" rel="noopener"
         class="text-xs text-gov hover:underline font-mono sm:ml-auto">anunțul original ↗</a>
    </div>

    <!-- Badges -->
    <div class="mt-3 flex flex-wrap gap-2">
      <?php if ($p['job_level']): ?>
        <span class="px-2 py-0.5 text-xs font-medium <?= $p['job_level'] === 'conducere' ? 'bg-blue-50 text-blue-800 border border-blue-200' : 'bg-slate-100 text-slate-700 border border-slate-200' ?>">
          <?= e($p['job_level']) ?>
        </span>
      <?php endif; ?>
      <?php if ($p['job_type']): ?>
        <span class="px-2 py-0.5 text-xs bg-slate-100 text-slate-700 border border-slate-200"><?= e($p['job_type']) ?></span>
      <?php endif; ?>
      <?php if ($p['categorie']): ?>
        <span class="px-2 py-0.5 text-xs <?= stripos($p['categorie'], 'public') !== false ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-amber-50 text-amber-800 border border-amber-200' ?>">
          <?= e($p['categorie']) ?>
        </span>
      <?php endif; ?>
      <?php if ($days !== null): ?>
        <?php if ($days < 0): ?>
          <span class="px-2 py-0.5 text-xs bg-slate-100 text-ink-muted border border-slate-200 font-mono">Expirat <?= fmt_date($p['expires_at']) ?></span>
        <?php elseif ($days <= 3): ?>
          <span class="px-2 py-0.5 text-xs bg-red-50 text-red-800 border border-red-200 font-mono font-semibold">Expiră în <?= e(days_label($days)) ?>!</span>
        <?php elseif ($days <= 7): ?>
          <span class="px-2 py-0.5 text-xs bg-amber-50 text-amber-900 border border-amber-200 font-mono">Expiră în <?= e(days_label($days)) ?></span>
        <?php else: ?>
          <span class="px-2 py-0.5 text-xs bg-gov-light text-gov border border-blue-200 font-mono">Expiră <?= fmt_date($p['expires_at']) ?></span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Structured column stacks above the body on phones; it used to be
       hidden outright, which removed the deadline and the contact details. -->
  <div class="flex flex-col sm:flex-row gap-6 sm:gap-8 items-start">

    <aside aria-label="Detalii concurs"
           class="w-full sm:w-64 sm:shrink-0 rounded-lg border border-border-warm bg-white/50 p-4 sm:border-0 sm:bg-transparent sm:p-0">
      <div class="grid grid-cols-2 gap-x-4 gap-y-4 text-sm sm:block sm:space-y-4">

        <?php if ($p['data_limita_depunere']): ?>
        <div class="col-span-2">
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1">Termen depunere</div>
          <div class="font-mono text-ink text-base"><?= fmt_datetime($p['data_limita_depunere'], 'd.m.Y') ?></div>
          <?php if (fmt_datetime($p['data_limita_depunere'], 'H:i') !== '00:00'): ?>
            <div class="text-xs text-ink-muted">ora <?= fmt_datetime($p['data_limita_depunere'], 'H:i') ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($p['contact_person'] || $p['contact_phone'] || $p['contact_email']): ?>
        <div class="col-span-2 sm:pt-3 sm:border-t sm:border-border-warm">
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-2">Contact</div>
          <?php if ($p['contact_person']): ?>
            <div class="text-ink mb-0.5"><?= e($p['contact_person']) ?></div>
          <?php endif; ?>
          <?php if ($p['contact_phone']): ?>
            <div class="font-mono text-sm">
              <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $p['contact_phone'])) ?>" class="text-gov hover:underline"><?= e($p['contact_phone']) ?></a>
            </div>
          <?php endif; ?>
          <?php if ($p['contact_email']): ?>
            <div class="text-sm break-all">
              <a href="mailto:<?= e($p['contact_email']) ?>" class="text-gov hover:underline"><?= e($p['contact_email']) ?></a>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($p['nr_posturi']): ?>
        <div>
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1">Nr. posturi</div>
          <div class="font-mono text-ink text-lg font-medium"><?= (int)$p['nr_posturi'] ?></div>
        </div>
        <?php endif; ?>

        <?php if ($p['data_proba_scrisa']): ?>
        <div>
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1">Probă scrisă</div>
          <div class="font-mono text-ink"><?= fmt_date($p['data_proba_scrisa']) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($p['data_interviu']): ?>
        <div>
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1">Interviu</div>
          <div class="font-mono text-ink"><?= fmt_date($p['data_interviu']) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($p['data_rezultate_finale']): ?>
        <div>
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1">Rezultate finale</div>
          <div class="font-mono text-ink"><?= fmt_date($p['data_rezultate_finale']) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($p['employer_category']): ?>
        <div>
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1">Tip angajator</div>
          <div class="text-ink"><?= e($p['employer_category']) ?></div>
        </div>
        <?php endif; ?>

        <?php
        $fam  = $inferred['profession_family'] ?? null;
        $conf = (float)($inferred['profession_family_confidence'] ?? 0);
        $show_fam = $fam && $fam !== 'altele' && $conf >= FAMILY_MIN_CONFIDENCE;
        $sen = $inferred['seniority'] ?? null;
        $grd = $inferred['grade'] ?? null;
        if ($show_fam || $sen || $grd || $anomaly_flags_list):
        ?>
        <div class="col-span-2 sm:pt-3 sm:border-t sm:border-border-warm">
          <div class="mb-2">
            <abbr title="Dedus automat din titlul și textul anunțului — poate fi incomplet sau greșit."
                  class="cursor-help text-xs font-semibold uppercase tracking-widest text-ink-muted decoration-dotted underline-offset-2 [text-decoration:underline]">Inferat automat</abbr>
          </div>
          <?php if ($show_fam): ?>
            <div class="mb-1">
              <span class="text-xs text-ink-muted">Domeniu</span>
              <a href="/?family%5B%5D=<?= urlencode($fam) ?>" class="ml-1 text-sm text-gov hover:underline"><?= e(ucfirst($fam)) ?></a>
            </div>
          <?php endif; ?>
          <?php if ($sen): ?>
            <div class="mb-1">
              <span class="text-xs text-ink-muted">Funcție</span>
              <a href="/?seniority%5B%5D=<?= urlencode($sen) ?>" class="ml-1 text-sm text-gov hover:underline"><?= e(seniority_label($sen)) ?></a>
            </div>
          <?php endif; ?>
          <?php if ($grd && strcasecmp((string)$grd, (string)$sen) !== 0): ?>
            <div class="mb-1"><span class="text-xs text-ink-muted">Grad</span> <span class="ml-1 text-sm text-ink"><?= e(grade_label((string)$grd)) ?></span></div>
          <?php endif; ?>
          <?php if ($anomaly_flags_list): ?>
            <div class="mt-1 flex flex-wrap gap-1">
              <?php foreach ($anomaly_flags_list as $flag): ?>
                <span class="px-1.5 py-0.5 text-xs bg-amber-50 text-amber-900 border border-amber-200"><?= e(ANOMALY_LABELS[$flag] ?? $flag) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php $attachments = attachment_list($p); ?>
        <?php if ($attachments): ?>
        <div class="col-span-2 sm:pt-3 sm:border-t sm:border-border-warm">
          <div class="mb-1.5 text-xs font-semibold uppercase tracking-widest text-ink-muted">Atașamente</div>
          <ul class="space-y-1.5">
            <?php foreach ($attachments as $att): ?>
            <li>
              <a href="<?= e($att['url']) ?>" target="_blank" rel="noopener"
                 aria-label="<?= e($att['label']) ?><?= $att['size'] ? ', ' . e($att['size']) : '' ?> (<?= e($att['ext']) ?>, se deschide într-o filă nouă)"
                 class="flex items-baseline gap-1.5 text-sm hover:underline <?= $att['important'] ? 'font-medium text-amber-800' : 'text-gov' ?>">
                <span class="shrink-0 rounded border px-1 py-0.5 font-mono text-[10px] font-medium <?= $att['important'] ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-border-warm bg-white text-ink-muted' ?>"><?= e($att['ext']) ?></span>
                <span class="min-w-0"><?= e($att['label']) ?></span>
                <?php if ($att['size']): ?><span class="shrink-0 font-mono text-xs text-ink-muted"><?= e($att['size']) ?></span><?php endif; ?>
                <span aria-hidden="true" class="shrink-0">↗</span>
              </a>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

      </div>
    </aside>

    <!-- Main content -->
    <div class="flex-1 min-w-0 w-full">

      <!-- Inferred conditions grid -->
      <?php
      $wt = $inferred['work_type'] ?? null;
      $re = $inferred['remote_eligible'] ?? null;
      $rc = isset($inferred['requires_computer']) ? $inferred['requires_computer'] : null;
      $exp = $inferred['experience_years'] ?? null;
      $st = $inferred['studies_required'] ?? null;
      $smn = $inferred['salary_min'] ?? null;
      $smx = $inferred['salary_max'] ?? null;
      if ($wt || $re || $rc !== null || $exp || $st || $smn):
      ?>
      <div class="mb-6 rounded-lg border border-amber-200/60 bg-amber-50/40 p-4">
      <div class="mb-3 flex items-baseline gap-1.5">
        <span class="text-xs font-semibold uppercase tracking-widest text-ink-muted">Condiții deduse automat</span>
        <a href="/despre/" class="text-xs text-gov hover:underline" title="Cum sunt deduse aceste date">metodologie</a>
      </div>
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 text-sm">
        <div class="flex flex-col gap-0.5">
          <span class="text-xs font-semibold uppercase tracking-widest text-ink-muted">Program</span>
          <?php if ($wt): ?><span class="font-medium text-ink"><?= e(work_type_label($wt)) ?></span>
          <?php else: ?><span class="text-ink-muted">—</span><?php endif; ?>
        </div>
        <div class="flex flex-col gap-0.5">
          <span class="text-xs font-semibold uppercase tracking-widest text-ink-muted">Telemuncă</span>
          <?php if ($re): ?><span class="font-medium text-ink">Da</span>
          <?php else: ?><span class="text-ink-muted">—</span><?php endif; ?>
        </div>
        <div class="flex flex-col gap-0.5">
          <span class="text-xs font-semibold uppercase tracking-widest text-ink-muted">Calculator</span>
          <?php if ($rc === true || $rc === 1): ?>
            <span class="font-medium text-ink">Solicitat<?= ($inferred['computer_level'] ?? '') === 'advanced' ? ' (avansat)' : '' ?></span>
          <?php elseif ($rc === false || $rc === 0): ?>
            <span class="font-medium text-ink">Nesolicitat</span>
          <?php else: ?>
            <span class="text-ink-muted">—</span>
          <?php endif; ?>
        </div>
        <div class="flex flex-col gap-0.5">
          <span class="text-xs font-semibold uppercase tracking-widest text-ink-muted">Experiență</span>
          <?php if ($exp): ?><span class="font-medium text-ink">Min. <?= e(years_label((int)$exp)) ?></span>
          <?php else: ?><span class="text-ink-muted">—</span><?php endif; ?>
        </div>
        <div class="flex flex-col gap-0.5">
          <span class="text-xs font-semibold uppercase tracking-widest text-ink-muted">Studii</span>
          <?php if ($st): ?><span class="font-medium text-ink"><?= e(STUDIES_LABELS[$st] ?? ucfirst($st)) ?></span>
          <?php else: ?><span class="text-ink-muted">—</span><?php endif; ?>
        </div>
        <div class="flex flex-col gap-0.5">
          <span class="text-xs font-semibold uppercase tracking-widest text-ink-muted">Salariu</span>
          <?php if ($smn || $smx): ?>
            <span class="font-medium text-ink font-mono">
              <?php
              if ($smn && $smx && $smn != $smx) echo (int)$smn . '–' . (int)$smx;
              elseif ($smn) echo (int)$smn;
              else echo (int)$smx;
              ?> RON
            </span>
          <?php else: ?><span class="text-ink-muted">—</span><?php endif; ?>
        </div>
      </div>

      <?php $tag_groups = inferred_tags($p); ?>
      <?php if ($tag_groups): ?>
      <div class="mt-4 space-y-2 border-t border-amber-200/60 pt-3">
        <?php foreach ($tag_groups as $group): ?>
        <div class="flex flex-wrap items-baseline gap-1.5">
          <span class="mr-1 text-xs font-semibold uppercase tracking-widest text-ink-muted"><?= e($group['label']) ?></span>
          <?php foreach ($group['values'] as $value): ?>
            <span class="rounded-full border border-amber-200 bg-white/70 px-2 py-0.5 text-xs text-ink"><?= e(ucfirst((string)$value)) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Prompt-v3 structured requirements. Absent until a v3 extraction has
           run, so this whole block simply does not render for v2 rows. -->
      <?php $v3 = ($p['schema_json'] ?? null) ? (json_decode($p['schema_json'], true) ?: []) : []; ?>
      <?php if (isset($v3['education']) || !empty($v3['skill_list']) || !empty($v3['credentials']) || !empty($v3['positions'])): ?>
      <section class="mb-6 rounded-lg border border-gov/20 bg-gov-light/40 p-4">
        <h2 class="mb-3 flex items-baseline gap-2 font-display text-lg font-semibold italic text-ink">
          Cerințe structurate
          <abbr title="Extrase automat și normalizate pe vocabularele europene EQF, ISCED-F, CEFR și ESCO — vezi metodologia."
                class="cursor-help font-mono text-[10px] font-normal uppercase not-italic tracking-wider text-ink-muted decoration-dotted underline-offset-2 [text-decoration:underline]">v3</abbr>
        </h2>

        <?php $edu = $v3['education'] ?? null; ?>
        <?php if ($edu && (($edu['minimum_level'] ?? null) || !empty($edu['fields_of_study']))): ?>
        <div class="mb-3">
          <div class="mb-1 text-xs font-semibold uppercase tracking-widest text-ink-muted">Studii</div>
          <div class="flex flex-wrap items-center gap-1.5 text-sm">
            <?php if ($lvl = $edu['eqf_level'] ?? null): ?>
              <a href="/?eqf=<?= (int)$lvl ?>" class="rounded-full border border-blue-200 bg-white px-2.5 py-0.5 text-xs text-gov hover:underline">
                <?= e(EQF_LABELS[(int)$lvl] ?? ('EQF ' . $lvl)) ?> <span class="font-mono opacity-60">EQF <?= (int)$lvl ?></span>
              </a>
            <?php endif; ?>
            <?php foreach ($edu['fields_of_study'] ?? [] as $f): ?>
              <a href="/?isced%5B%5D=<?= urlencode((string)($f['isced_field'] ?? '')) ?>"
                 title="<?= e(isced_label((string)($f['isced_field'] ?? ''))) ?>"
                 class="rounded-full border border-border-warm bg-white px-2.5 py-0.5 text-xs text-ink hover:border-gov hover:text-gov">
                <?= e(ucfirst((string)($f['label_ro'] ?? ''))) ?>
              </a>
            <?php endforeach; ?>
          </div>
          <?php if (($edu['fields_of_study'] ?? []) && count($edu['fields_of_study']) > 1): ?>
            <p class="mt-1 text-xs text-ink-muted">Oricare dintre aceste domenii este acceptat.</p>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($v3['skill_list'])): ?>
        <div class="mb-3">
          <div class="mb-1 text-xs font-semibold uppercase tracking-widest text-ink-muted">Competențe</div>
          <div class="flex flex-wrap gap-1.5">
            <?php foreach ($v3['skill_list'] as $k): ?>
              <a href="/?skill%5B%5D=<?= urlencode((string)($k['label'] ?? '')) ?>"
                 title="<?= e((string)($k['evidence'] ?? '')) ?>"
                 class="rounded-full border px-2.5 py-0.5 text-xs hover:border-gov hover:text-gov <?= ($k['required'] ?? true) ? 'border-border-warm bg-white text-ink' : 'border-dashed border-border-warm bg-transparent text-ink-muted' ?>">
                <?= e((string)($k['label'] ?? '')) ?><?php if ($pr = $k['proficiency'] ?? null): ?> <span class="font-mono opacity-70"><?= e($pr) ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
          <?php foreach ($v3['skill_list'] as $k) { if (!($k['required'] ?? true)) { echo '<p class="mt-1 text-xs text-ink-muted">Cele punctate sunt un avantaj, nu o condiție.</p>'; break; } } ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($v3['language_list'])): ?>
        <div class="mb-3">
          <div class="mb-1 text-xs font-semibold uppercase tracking-widest text-ink-muted">Limbi străine</div>
          <div class="flex flex-wrap gap-1.5">
            <?php foreach ($v3['language_list'] as $l): ?>
              <span class="rounded-full border border-border-warm bg-white px-2.5 py-0.5 text-xs text-ink">
                <?= e(ucfirst((string)($l['language'] ?? ''))) ?><?php if ($c = $l['cefr'] ?? null): ?> <span class="font-mono text-gov"><?= e($c) ?></span><?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($v3['credentials'])): ?>
        <div class="mb-3">
          <div class="mb-1 text-xs font-semibold uppercase tracking-widest text-ink-muted">Documente și autorizații</div>
          <ul class="space-y-0.5 text-sm">
            <?php foreach ($v3['credentials'] as $c): ?>
              <li class="flex items-baseline gap-1.5">
                <span aria-hidden="true" class="text-ink-muted">·</span>
                <span class="text-ink"><?= e((string)($c['label'] ?? '')) ?></span>
                <a href="/?credential%5B%5D=<?= urlencode((string)($c['kind'] ?? 'altele')) ?>"
                   class="font-mono text-[10px] uppercase text-ink-muted hover:text-gov"><?= e(credential_kind_label((string)($c['kind'] ?? 'altele'))) ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($v3['positions'])): ?>
        <div class="mb-3">
          <div class="mb-1 text-xs font-semibold uppercase tracking-widest text-ink-muted">
            Posturi scoase la concurs (<?= count($v3['positions']) ?>)
          </div>
          <p class="mb-2 text-xs text-ink-muted">Anunțul cuprinde mai multe roluri, fiecare cu cerințele lui.</p>
          <div class="space-y-2">
            <?php foreach ($v3['positions'] as $pos): ?>
            <div class="rounded border border-border-warm bg-white/60 px-3 py-2">
              <div class="text-sm font-medium text-ink">
                <?php if ($n = $pos['count'] ?? null): ?><span class="font-mono text-gov"><?= (int)$n ?>×</span> <?php endif; ?>
                <?= e((string)($pos['title'] ?? '')) ?>
              </div>
              <div class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-ink-muted">
                <?php $pe = $pos['education'] ?? null; if ($pe && ($pe['minimum_level'] ?? null)): ?>
                  <span>Studii: <?= e(STUDIES_LABELS[$pe['minimum_level']] ?? $pe['minimum_level']) ?></span>
                <?php endif; ?>
                <?php $px = $pos['experience'] ?? null; if ($px && ($px['years_minimum'] ?? null)): ?>
                  <span>Experiență: min. <?= e(years_label((int)$px['years_minimum'])) ?></span>
                <?php endif; ?>
                <?php if (!empty($pos['skill_list'])): ?>
                  <span>Competențe: <?= e(implode(', ', array_slice(array_column($pos['skill_list'], 'label'), 0, 4))) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php $pd = $v3['policy_domains'] ?? []; $st = $v3['exam_stages'] ?? []; ?>
        <?php if ($pd || $st): ?>
        <div class="flex flex-wrap gap-x-4 gap-y-1 border-t border-blue-200/50 pt-2 text-xs">
          <?php if ($pd): ?>
          <div><span class="text-ink-muted">Domeniu:</span>
            <?php foreach ($pd as $d): ?><a href="/?domain%5B%5D=<?= urlencode((string)$d) ?>" class="ml-1 text-gov hover:underline"><?= e(policy_domain_label((string)$d)) ?></a><?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ($st): ?>
          <div><span class="text-ink-muted">Etape:</span>
            <span class="ml-1 text-ink"><?= e(implode(' → ', array_map('exam_stage_label', $st))) ?></span>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <!-- Structured schema sections OR body fallback -->
      <?php if ($schema_sections): ?>
        <?php
        // These three are ours; everything else is a Schema.org JobPosting property.
        $ro_specific = ['application_docs', 'application_fee', 'application_contact'];
        ?>
        <?php foreach ($schema_sections as $section): ?>
        <section class="mb-6">
          <h2 class="mb-3 flex items-baseline gap-2 border-b border-border-warm pb-2 font-display text-lg font-semibold italic text-ink">
            <?= e($section['label']) ?>
            <?php if (!in_array($section['key'], $ro_specific, true)): ?>
              <a href="https://schema.org/JobPosting" target="_blank" rel="noopener"
                 title="Proprietate Schema.org JobPosting: <?= e($section['key']) ?>"
                 class="font-mono text-[10px] font-normal not-italic text-ink-muted hover:text-gov"><?= e($section['key']) ?></a>
            <?php endif; ?>
          </h2>
          <div class="prose-body text-sm leading-relaxed text-ink max-w-none">
            <?= $section['html'] ?>
          </div>
        </section>
        <?php endforeach; ?>
      <?php elseif ($body_html): ?>
      <section class="mb-6">
        <h2 class="mb-3 border-b border-border-warm pb-2 font-display text-lg font-semibold italic text-ink">
          Detalii post
        </h2>
        <p class="mb-3 rounded border border-border-warm bg-white/50 px-3 py-2 text-xs text-ink-muted">
          Textul de mai jos este preluat ca atare de pe posturi.gov.ro. Pentru acest anunț nu există încă
          o versiune structurată pe secțiuni (studii, experiență, atribuții, dosar) —
          <a href="/despre/" class="text-gov hover:underline">vezi metodologia</a>.
        </p>
        <div class="prose-body text-sm leading-relaxed text-ink max-w-none">
          <?= $body_html ?>
        </div>
      </section>
      <?php endif; ?>

      <!-- Calendar timeline -->
      <?php if ($events): ?>
      <section class="mb-8">
        <h2 class="font-display text-lg italic font-semibold text-ink mb-3 pb-2 border-b border-border-warm">
          Calendar concurs
        </h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm border-collapse">
            <thead>
              <tr class="border-b border-border-warm">
                <th scope="col" class="text-left py-1.5 pr-4 text-xs font-medium uppercase tracking-widest text-ink-muted w-32 font-sans">Data</th>
                <th scope="col" class="text-left py-1.5 text-xs font-medium uppercase tracking-widest text-ink-muted font-sans">Etapă</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-warm">
              <?php foreach ($events as $ev): ?>
              <tr class="hover:bg-parchment-dark transition-colors">
                <td class="py-2 pr-4 font-mono text-xs text-ink-muted whitespace-nowrap">
                  <?= fmt_date($ev['data']) ?>
                  <?php if ($ev['ora']): ?><span> <?= e($ev['ora']) ?></span><?php endif; ?>
                </td>
                <td class="py-2 text-ink"><?= e($ev['eveniment']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <p class="mt-8 border-t border-border-warm pt-4 text-xs text-ink-muted">
        Datele sunt preluate automat de pe posturi.gov.ro.
        <a href="<?= e($p['url']) ?>" target="_blank" rel="noopener" class="text-gov hover:underline">Verifică anunțul original ↗</a>
        înainte de a depune dosarul. <a href="/despre/" class="text-gov hover:underline">Metodologie</a>
      </p>

    </div>
  </div>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>
