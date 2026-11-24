<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); echo "Not found"; exit; }

$stmt = db()->prepare("
    SELECT j.*, t.body_markdown, t.schema_json, t.sal_json, t.attachment_meta,
           t.other_links, t.detalii_raw, t.v3_bibliography,
           e.name AS employer_name, e.slug AS employer_slug,
           jd.name AS judet_name, jd.slug AS judet_slug_val
    FROM job_postings j
    LEFT JOIN posting_text t ON t.posting_id = j.id
    LEFT JOIN employers e ON e.id = j.employer_id
    LEFT JOIN judete jd ON jd.id = j.judet_id
    WHERE j.id = ?
");
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p) {
    // UX-03 (D14): a posting that left the live slice in the last 90 days keeps a
    // slim page (pages/archived.php); anything else is a real 404, with the layout.
    $st = db()->prepare("SELECT * FROM archived_postings WHERE id = ?");
    $st->execute([$id]);
    if ($archived = $st->fetch()) { require __DIR__ . '/archived.php'; exit; }
    http_response_code(404);
    $page_title = 'Anunț negăsit';
    $head_extra = '<meta name="robots" content="noindex">';
    require __DIR__ . '/../inc/header.php';
    echo '<div class="mx-auto max-w-screen-md px-4 py-16 text-center">'
       . '<h1 class="mb-3 font-display text-2xl font-semibold text-ink">Anunțul nu mai este în baza noastră</h1>'
       . '<p class="text-sm text-ink-muted">Păstrăm anunțurile expirate sau anulate 90 de zile; acesta este mai vechi sau nu a existat.</p>'
       . '<a href="/posturi/" class="mt-4 inline-block text-gov hover:underline">← Toate posturile active</a></div>';
    require __DIR__ . '/../inc/footer.php';
    exit;
}

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

// UX-13: where each structured requirement's quote sits in the source (empty on an
// export that predates the table). Rendered only from this table, never from schema_json.
$evidence = load_requirement_evidence(db(), (int)$p['id']);
$evidence_url = (string)($p['url'] ?? '');
$evidence_doc = (string)($p['announcement_url'] ?? '');

// Render body
$body_html = '';
if ($p['body_markdown']) {
    $body_html = render_markdown($p['body_markdown']);
}

// Schema sections
$schema_sections = render_schema_sections($p['schema_json'] ?? null);

// Countdown, badges, sidebar, feeds and JSON-LD all read this one value.
$dl   = posting_deadline($p);
$deadline = $dl['date'];
$days = days_until($deadline);
$est  = $dl['source'] === 'expirare';

// Return to the filtered list the reader came from, when there is one.
// `javascript:history.back()` broke for anyone arriving from a feed, a shared
// link or a search result.
$back_url   = '/posturi/';
$back_label = 'Toate posturile';
if ($ref = ($_SERVER['HTTP_REFERER'] ?? '')) {
    $rp = parse_url($ref);
    // A bare `/` is the landing page (ADR 0004), not a list: only `/?…` is a search to return to.
    if (($rp['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? '') && ($rp['path'] ?? '/') === '/' && isset($rp['query'])) {
        $back_url   = '/?' . $rp['query'];
        $back_label = 'Înapoi la căutare';
    }
}

// ---- Head metadata ----
$page_title = $p['title'] ?: 'Anunț';

$_desc_src = trim(strip_tags($body_html)) ?: (string)$p['title'];
$_desc_src = preg_replace('/\s+/u', ' ', $_desc_src) ?? '';
$meta_description = trim(implode(' · ', array_filter([
    $p['employer_name'] ?? null,
    place_label($p) ?: null,
    $deadline ? ($est ? 'termen estimat ' : 'termen ') . fmt_date($deadline) : null,
])) . '. ' . mb_substr($_desc_src, 0, 150));

// ---- JSON-LD (Google Jobs) ----
// Built from the same columns the page renders, plus the Schema.org-shaped
// fields the v2 extraction prompt already produces.
$schema = json_decode($p['schema_json'] ?? 'null', true);
// BETA-V5: a v5 row's requirement texts are rebuilt from its structured keys.
$schema = is_array($schema) ? schema_text_fallbacks($schema) : [];

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
if ($deadline) {
    try {
        $tz = new DateTimeZone('Europe/Bucharest');
        // A deadline the source pins to an exact hour must not be described as
        // open until 23:59 merely because other surfaces only have date
        // precision. Only a same-day source time wins over end-of-day.
        $vt = null;
        $exact = $p['data_limita_depunere'] ?? null;
        if ($exact && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', (string)$exact)) {
            $vt = new DateTime((string)$exact, $tz);
            if ($vt->format('Y-m-d') !== $deadline) $vt = null;
        }
        if ($vt === null) {
            $vt = new DateTime($deadline . ' 23:59:59', $tz);
        }
        $ld['validThrough'] = $vt->format('c');
    } catch (Exception $e) {
        $ld['validThrough'] = $deadline;
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

  <nav aria-label="Firimituri" class="mb-3 text-sm">
    <a href="<?= e($back_url) ?>" class="text-gov hover:underline">← <?= e($back_label) ?></a>
  </nav>

  <?php /* D9: the same sentence opens and closes every posting. */ ?>
  <?= robots_disclaimer((string)$p['url'], 'mb-3') ?>

  <?php /* 261016: a posting whose submission date has passed says so first, in bold.
     A confirmed date is a fact; the expiry fallback only makes it likely. */
  if ($deadline && $days !== null && $days < 0): ?>
  <div role="note" data-closed-notice class="mb-5 rounded-md border-2 border-alert-line bg-alert px-4 py-3 text-alert-ink">
    <p class="text-base font-bold">
      <?= $est ? 'Înscrierile sunt probabil închise' : 'Înscrierile s-au închis' ?> — termenul a fost <?= fmt_date($deadline) ?>.
    </p>
    <p class="mt-0.5 text-sm"><?= $est
        ? 'Data este expirarea anunțului, nu un termen confirmat. Verificați în anunțul oficial.'
        : 'Anunțul rămâne afișat pentru calendarul concursului și rezultate.' ?></p>
  </div>
  <?php endif; ?>

  <!-- Title block. Left: who / where / when above the title, then the title with its
       icon buttons and the normalised-from line. Right (sm+): the link to the original,
       then the tags below it. On phones the right block simply follows the left. -->
  <?php $dt = display_title($p); ?>
  <div class="mb-5 border-b border-line pb-4"<?= pref_attrs($p) ?>>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-8">
      <div class="min-w-0">
        <div class="mb-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm text-ink-muted">
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
        </div>
        <?php /* The icon buttons sit right after the heading, inside the same block
           that carries the posting identity. Hiding here only marks the posting
           hidden in this browser (reversible); the page itself stays. */ ?>
        <div class="leading-tight">
        <h1 class="inline font-display text-[1.7rem] sm:text-[2rem] font-semibold italic text-ink">
          <?= e($dt['primary']) ?>
          <?php if ($dt['normalized']): ?>
            <abbr title="Titlu normalizat automat pe ocupațiile COR — poate fi incomplet sau greșit."
                  class="ml-1 cursor-help font-mono text-[10px] font-normal uppercase not-italic tracking-wider text-ink-muted decoration-dotted underline-offset-2 [text-decoration:underline]">normalizat</abbr>
          <?php endif; ?>
        </h1><?= pref_controls($p) ?>
        </div>
        <?= pref_flag() ?>
        <?php if ($dt['secondary']): ?>
          <p class="mt-0.5 text-sm not-italic text-ink-muted"><?= e($dt['secondary']) ?></p>
        <?php endif; ?>
      </div>

      <div class="flex min-w-0 flex-col gap-2 sm:max-w-[40%] sm:shrink-0 sm:items-end">
        <a href="<?= e($p['url']) ?>" target="_blank" rel="noopener"
           class="font-mono text-xs text-gov hover:underline">anunțul original ↗</a>
        <!-- Badges: below the link to the original, right-aligned from sm up -->
        <div class="flex flex-wrap gap-1.5 sm:justify-end">
          <?php if ($p['job_level']): ?>
            <span class="px-2 py-0.5 text-xs font-medium <?= $p['job_level'] === 'conducere' ? 'bg-info text-info-ink border border-info-line' : 'bg-neutral text-neutral-ink border border-neutral-line' ?>">
              <?= e(job_level_label((string)$p['job_level'])) ?>
            </span>
          <?php endif; ?>
          <?php if ($p['job_type']): ?>
            <span class="px-2 py-0.5 text-xs bg-neutral text-neutral-ink border border-neutral-line"><?= e($p['job_type']) ?></span>
          <?php endif; ?>
          <?php /* 261016: the regime read off the text, not the source's free-pick categorie. */
          if ($regime = JOB_REGIME_SHORT[(string)($p['job_regime'] ?? '')] ?? ''): ?>
            <span class="px-2 py-0.5 text-xs <?= ($p['job_regime'] ?? '') === 'publica' ? 'bg-ok text-ok-ink border border-ok-line' : 'bg-neutral text-neutral-ink border border-neutral-line' ?>" data-regime>
              <?= e($regime) ?>
            </span>
          <?php endif; ?>
          <?php /* 2026-10-18: the distinctive tags (row_tags), minus what a badge here already says —
             the level and type badges above included, which used to show twice. */
          $said = ['Perioadă determinată', 'Post de conducere', 'Funcție publică', 'Funcție militară', $regime, (string)($p['job_type'] ?? '')];
          if ($p['job_level']) $said[] = job_level_label((string)$p['job_level']);
          foreach (array_diff(row_tags($p), $said) as $tag): ?>
            <span class="px-2 py-0.5 text-xs bg-info text-info-ink border border-info-line" data-tag><?= e($tag) ?></span>
          <?php endforeach; ?>
          <?php if ($days !== null): ?>
            <?php if ($days < 0): ?>
              <span class="px-2 py-0.5 text-xs bg-neutral text-ink-muted border border-neutral-line font-mono"><?= $est ? 'Termen estimat depășit' : 'Înscrieri închise' ?> <?= fmt_date($deadline) ?></span>
            <?php elseif ($days <= 3): ?>
              <span class="px-2 py-0.5 text-xs bg-alert text-alert-ink border border-alert-line font-mono font-semibold"><?= $est ? 'Termen estimat: ' : 'Înscrieri: ' ?><?= e(days_label($days)) ?>!</span>
            <?php elseif ($days <= 7): ?>
              <span class="px-2 py-0.5 text-xs bg-note text-note-ink border border-note-line font-mono"><?= $est ? 'Termen estimat: ' : 'Înscrieri: ' ?><?= e(days_label($days)) ?></span>
            <?php elseif ($est): ?>
              <span class="px-2 py-0.5 text-xs bg-gov-light text-gov border border-info-line font-mono">Termen estimat: <?= fmt_date($deadline) ?></span>
            <?php else: ?>
              <span class="px-2 py-0.5 text-xs bg-gov-light text-gov border border-info-line font-mono">Înscrieri până la <?= fmt_date($deadline) ?></span>
            <?php endif; ?>
          <?php else: ?>
            <span class="px-2 py-0.5 text-xs bg-neutral text-ink-muted border border-neutral-line font-mono">Termen neprecizat</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Structured column stacks above the body on phones; it used to be
       hidden outright, which removed the deadline and the contact details. -->
  <div class="flex flex-col sm:flex-row gap-6 sm:gap-8 items-start">

    <?php /* No box. This used to be `rounded-lg border border-line bg-surface/50
       p-4 sm:border-0 sm:bg-transparent sm:p-0` — a card on phones, a bare
       column on desktop. The inner `sm:border-t` dividers already separate the
       sections at every width, so the outer frame was drawing a second, larger
       rectangle around them for no gain. */ ?>
    <aside aria-label="Detalii concurs"
           class="w-full sm:w-64 sm:shrink-0">
      <div class="grid grid-cols-2 gap-x-4 gap-y-4 text-sm sm:block sm:space-y-4">

        <?php if ($deadline): ?>
        <div class="col-span-2">
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1">Termen depunere</div>
          <div class="font-mono text-ink text-base"><?= fmt_date($deadline) ?></div>
          <?php if ($p['data_limita_depunere'] && fmt_datetime($p['data_limita_depunere'], 'H:i') !== '00:00'): ?>
            <div class="text-xs text-ink-muted">ora <?= fmt_datetime($p['data_limita_depunere'], 'H:i') ?></div>
          <?php endif; ?>
          <?php if (!empty($dl['source']) && isset(DEADLINE_SOURCE_LABELS[$dl['source']])): ?>
            <div class="text-xs text-ink-faint"><?= e(DEADLINE_SOURCE_LABELS[$dl['source']]) ?></div>
          <?php endif; ?>
          <?php if ($est): ?>
            <!-- The expiry fallback must read as uncertainty, not as the
                 confirmed submission date the heading above implies. -->
            <div class="mt-1 rounded border border-note-line bg-note px-2 py-1 text-xs text-note-ink">
              <?= e(DEADLINE_FALLBACK_NOTE) ?>.
            </div>
          <?php endif; ?>
          <?php
            // The competition runs on past the deadline — results, contestations.
            // Showing only one date is what made closed competitions read as open.
            $ends = $p['expires_at'] ? substr((string)$p['expires_at'], 0, 10) : null;
            if ($ends && $ends !== $deadline):
          ?>
            <div class="mt-1 text-xs text-ink-muted">concursul se încheie <?= fmt_date($p['expires_at']) ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($p['contact_person'] || $p['contact_phone'] || $p['contact_email']): ?>
        <div class="col-span-2 pt-3 border-t border-line">
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

        <?php if ((int)$p['nr_posturi'] > 1): ?>
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
        <div class="col-span-2 pt-3 border-t border-line">
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
                <span class="px-1.5 py-0.5 text-xs bg-note text-note-ink border border-note-line"><?= e(ANOMALY_LABELS[$flag] ?? $flag) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php $attachments = attachment_list($p); ?>
        <?php if ($attachments): ?>
        <div class="col-span-2 pt-3 border-t border-line">
          <div class="mb-1.5 text-xs font-semibold uppercase tracking-widest text-ink-muted">Atașamente</div>
          <ul class="space-y-1.5">
            <?php foreach ($attachments as $att): ?>
            <li>
              <a href="<?= e($att['url']) ?>" target="_blank" rel="noopener"
                 aria-label="<?= e($att['label']) ?><?= $att['size'] ? ', ' . e($att['size']) : '' ?> (<?= e($att['ext']) ?>, se deschide într-o filă nouă)"
                 class="flex items-baseline gap-1.5 text-sm hover:underline <?= $att['important'] ? 'font-medium text-note-ink' : 'text-gov' ?>">
                <span class="shrink-0 rounded border px-1 py-0.5 font-mono text-[10px] font-medium <?= $att['important'] ? 'border-note-line bg-note text-note-ink' : 'border-line bg-surface text-ink-muted' ?>"><?= e($att['ext']) ?></span>
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

    <!-- Main content: the summary strip, then the white sheet holding the posting -->
    <div class="flex-1 min-w-0 w-full">

      <?php /* The automatic summaries — deduced conditions, contract terms, exam topics, estimated
         pay — sit above the posting as plain rows: no boxes, no background (the white sheet
         below is the posting itself), one row per summary with its facts inline. */
      // A condition nobody could deduce is left out rather than shown as "—".
      $cond = posting_inferred_conditions($p);
      $tag_groups = $cond ? inferred_tags($p) : [];

      // Contract terms and bibliography — extracted by v3 since it first ran and, until
      // 2026-09-13, displayed nowhere. No new extraction: data the project had already paid for.
      $v3c = ($p['schema_json'] ?? null) ? (json_decode($p['schema_json'], true) ?: []) : [];
      $bib = $v3c['bibliography_topics'] ?? [];
      // BETA-V5: who can apply and how (temporary vacancy, norm, end date, channels…) is in the terms too.
      $terms = posting_contract_terms($v3c);

      // Estimated pay: the law in force, the declared pay or, as a last resort, the draft law. Derived,
      // never scraped: few postings state a salary in the text. A figure is never a bare number — the range, its legal status and the rows it came from are all
      // part of the claim, and "cum a fost calculat" is open to anyone who wants to check it.
      $sal = ($p['sal_json'] ?? null) ? (json_decode($p['sal_json'], true) ?: []) : [];
      $show_sal = !empty($sal['lei_min']);
      // SALARY-LABELS-LIMITS: the figure of the draft law is a last resort; one under the law in force gets a side note.
      $sbaza = sal_baza($p);
      $draft_note = $sbaza === 'lege_in_vigoare' ? sal_draft_note($sal) : '';
      // ADR 0008 rule 9: the figure comes from the asset declarations; the text follows docs/specs/2026-11-20-salary-declared-explainer.md.
      $decl = declared_view($sal, (string)($p['employer_name'] ?? ''));
      // No figure at all (no stated pay, no estimate): the page says so, and why (UX salary-net).
      $no_sal = !$show_sal && list_salary($p) === null;
      $eu_funded = in_array((string)($p['funding_kind'] ?? ''), ['fonduri_europene', 'pnrr'], true);
      // A salary stated in the posting wins over the estimate (as in the list rows, list_salary()): its box replaces the
      // estimate box, and the estimate becomes one note in it. The deduced conditions then leave out "Salariu" (the box has it).
      $stated = (float)($p['inf_salary_min'] ?? 0) > 0 ? list_salary($p) : null;
      if ($stated) unset($cond['Salariu']);
      // ADR 0008 rule 11: the pay range of the law in force (gradations + food allowance), for the stated or the estimated figure.
      $range = pay_range_view($p);
      // UX-SALARY-BOX: the seniority line with the years of each gradation (Legea 153/2017 art. 10 alin. (4)).
      $range_note = 'Gradația depinde de vechimea în muncă. Minimul vine din experiența cerută în anunț.';
      if ($range && $range['grad_min'] !== null && $range['grad_max'] !== null && $range['grad_min'] < $range['grad_max']) {
          $range_note = 'Salariul crește cu vechimea în muncă: de la gradația ' . $range['grad_min'] . ' (' . (SALARY_GRAD_YEARS[$range['grad_min']] ?? '') . ') la gradația '
              . $range['grad_max'] . ' (' . (SALARY_GRAD_YEARS[$range['grad_max']] ?? '') . ').'
              . ($range['grad_min'] > 0 ? ' Minimul vine din experiența cerută în anunț.' : '');
      }
      // The extracted section „Salarizare” repeats a stated pay that the top box already shows (when other sections remain).
      if ($stated && $schema_sections) {
          $_other = array_values(array_filter($schema_sections, fn($sec) => !in_array($sec['key'], ['baseSalary', 'salary'], true)));
          if ($_other) $schema_sections = $_other;
      }
      // A post hired for an EU/PNRR project (SALARY-EU-DETAIL): the pay is the hourly rate of the project, never the grid.
      // No grid figure, no meter, no estimator link, no deduced "Salariu"; the pay the text states, with its source, else the box alone.
      $proj = !empty($p['project_hire']);
      $proj_pay = $proj ? project_pay_view($p) : null;
      if ($proj) { $show_sal = false; $no_sal = false; $stated = null; unset($cond['Salariu']); }
      ?>
      <?php if ($cond || $tag_groups || $terms || $bib || $show_sal || $no_sal || $stated || $proj): ?>
      <div class="mb-4 divide-y divide-line text-sm">

        <?php if ($proj): ?>
        <section class="py-2" data-pay-project>
          <div class="block w-full max-w-2xl rounded-lg border border-info-line bg-info px-3 py-2">
            <p class="font-semibold text-info-ink" data-pay-project-note>Post finanțat din fonduri europene: salariul depinde de bugetul proiectului.</p>
            <?php if ($proj_pay): ?>
            <p class="mt-1 text-lg font-semibold text-ink [overflow-wrap:anywhere]" data-pay-project-figure><?= e($proj_pay['full']) ?></p>
            <?php if ($proj_pay['note'] !== ''): ?><p class="text-xs text-ink-muted"><?= e($proj_pay['note']) ?></p><?php endif; ?>
            <?php if ($proj_pay['quote'] !== ''): ?>
            <p class="mt-1 text-xs text-ink-muted [overflow-wrap:anywhere]" data-pay-project-quote>Din anunț: „<?= e($proj_pay['quote']) ?>”</p>
            <?php endif; ?>
            <?php endif; ?>
            <?php /* SALARY-EU-CAP: context, not a figure (ADR 0008 rule 5): the guide cap of the programme, only when the text states no pay. */ ?>
            <?php if (!$proj_pay && in_array((string)($p['proj_programme'] ?? ''), ['pids', 'peo'], true)): ?>
            <p class="mt-1 text-xs text-ink-muted" data-pay-project-cap title="Sursa: Ghidul solicitantului – condiții generale, martie 2023.">Ghidul programului plafonează plata la 35–90 lei net/oră, după rol și experiență. Plafonul este suma maximă decontată de program, nu salariul postului.</p>
            <?php endif; ?>
          </div>
        </section>
        <?php endif; ?>

        <?php /* The salary first: a stated figure, else the estimate. */ ?>
        <?php if ($stated): ?>
        <section class="py-2" data-pay-stated>
          <div class="block w-full max-w-2xl rounded-lg border border-note-line bg-note px-3 py-2">
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-0.5">
              <h2 class="text-xs font-semibold uppercase tracking-widest text-note-ink">Salariu anunțat</h2>
              <?php if ($range): ?>
              <p class="text-lg font-semibold text-ink" data-pay-net>de la <?= e(number_format($range['net_min'], 0, ',', '.')) ?><?= $range['net_max'] > $range['net_min'] ? ' la ' . e(number_format($range['net_max'], 0, ',', '.')) : '' ?> lei net <span class="text-sm font-normal text-ink-muted">/ lună</span></p>
            </div>
            <p class="text-xs text-ink-muted" data-pay-gross>brut: <?= e($range['gross']) ?> lei / lună<?= $range['detail'] !== '' ? ', ' . e($range['detail']) : '' ?>. În anunț: <?= e($range['stated']) ?> lei.</p>
            <p class="text-xs text-ink-muted" data-pay-range-note><?= e($range_note) ?></p>
              <?php else: ?>
              <p class="text-lg font-semibold text-ink" data-pay-net><?= e($stated['text']) ?> <span class="text-sm font-normal text-ink-muted">/ lună</span></p>
            </div>
            <p class="text-xs text-ink-muted" data-pay-gross>brut: <?= e($stated['gross']) ?> / lună, din anunț</p>
              <?php endif; ?>
            <?php if ($pl = pay_level($p)): ?>
            <p class="mt-0.5 flex items-center gap-2 text-ink" data-pay-level="<?= (int)$pl['level'] ?>"><?= pay_meter($p, 'pay-meter-lg') ?>
              <span><?= e($pl['text']) ?></span></p>
            <?php endif; ?>
            <?php if ($show_sal):
                $_lmin = (int)$sal['lei_min']; $_lmax = (int)($sal['lei_max'] ?? $_lmin);
                $_g = number_format($_lmin, 0, ',', '.') . ($_lmax > $_lmin ? '–' . number_format($_lmax, 0, ',', '.') : ''); ?>
            <?php if ($decl): ?>
            <p class="mt-1 text-xs text-ink-muted" data-pay-law>După declarațiile de avere: <?= e(net_label((float)$_lmin, (float)$_lmax)) ?> (brut <?= e($_g) ?> lei) / lună.</p>
            <?php elseif ($sbaza === 'proiect'): ?>
            <p class="mt-1 text-xs text-ink-muted" data-pay-law data-pay-project-figure>Estimare aproximativă, după proiectul de lege: <?= e(net_label((float)$_lmin, (float)$_lmax)) ?> (brut <?= e($_g) ?> lei) / lună, la gradația 0. <?= e(SAL_PROJECT_NOTE) ?></p>
            <?php elseif ($range && (float)($p['pay_est_min'] ?? 0) > 0):
                /* ADR 0008 rule 11: the estimate on the same basis as the range above (floor gradation to 5, food). */
                $_emin = (float)$p['pay_est_min']; $_emax = (float)($p['pay_est_max'] ?? $_emin);
                $_eg = number_format($_emin, 0, ',', '.') . ($_emax > $_emin ? '–' . number_format($_emax, 0, ',', '.') : ''); ?>
            <p class="mt-1 text-xs text-ink-muted" data-pay-law data-pay-law-range>Estimare după Legea 153/2017, pe aceeași bază: <?= e(net_label($_emin, $_emax)) ?> (brut <?= e($_eg) ?> lei) / lună.</p>
            <?php else: ?>
            <p class="mt-1 text-xs text-ink-muted" data-pay-law>Estimare după Legea 153/2017: <?= e(net_label((float)$_lmin, (float)$_lmax)) ?> (brut <?= e($_g) ?> lei) / lună, la gradația 0.</p>
            <?php endif; ?>
            <?php if ($draft_note !== ''): ?>
            <p class="mt-0.5 text-xs text-ink-muted" data-pay-draft-note><a href="/estimator-salariu/" class="underline hover:text-gov"><?= e($draft_note) ?></a></p>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </section>
        <?php elseif ($show_sal): ?>
        <?php /* UX-SALARY-BOX: one fixed width; the figure, what raises it, the actions in a footer row, the caveat under the box.
           „Cum a fost calculat” opens inside the box, so the box never changes its width. */
        $_pl = pay_level($p);
        // The estimator opens on the first law row of this estimate (brief UX-SALARY-ESTIMATOR, decision 7).
        $_est_ref = ($sal['baza'] ?? '') === 'lege_in_vigoare' && salary_est_available() ? (string)(($sal['randuri'] ?? [])[0] ?? '') : '';
        $_est_url = $_est_ref !== '' ? salary_est_row_url($_est_ref, 0, ($sal['selector']['nivel_administrativ'] ?? '') === 'local') : '';
        ?>
        <section class="py-2">
          <div class="block w-full max-w-2xl rounded-lg border border-note-line bg-note px-3 py-2" data-pay-estimate>
            <div class="flex items-center justify-between gap-3">
              <h2 class="text-xs font-semibold uppercase tracking-widest text-note-ink"><?= $sbaza === 'proiect' ? 'Estimare aproximativă' : 'Salariu estimat' ?></h2>
              <?= $_pl ? pay_meter($p, 'pay-meter-lg') : '' ?>
            </div>
            <?php /* Net first: the main figure. The gross range is one smaller line below. */ ?>
            <?php if ($decl): /* The median is the main figure; the band p25-p75 is the line below. No "la gradația 0". */ ?>
            <p class="text-lg font-semibold text-ink" data-pay-net>≈ <?= e($decl['estimare']) ?> lei net
              <span class="text-sm font-normal text-ink-muted">pe lună</span>
            </p>
            <p class="text-xs text-ink-muted" data-pay-gross>circa <?= e($decl['brut']) ?> lei brut</p>
            <p class="text-xs text-ink-muted" data-pay-band>Cei mai mulți primesc între <?= e($decl['p25']) ?> și <?= e($decl['p75']) ?> lei net.</p>
            <?php elseif ($range): ?>
            <p class="text-lg font-semibold text-ink" data-pay-net>de la <?= e(number_format($range['net_min'], 0, ',', '.')) ?><?= $range['net_max'] > $range['net_min'] ? ' la ' . e(number_format($range['net_max'], 0, ',', '.')) : '' ?> lei net
              <span class="text-sm font-normal text-ink-muted">/ lună</span>
            </p>
            <p class="text-xs text-ink-muted" data-pay-gross>brut: <?= e($range['gross']) ?> lei / lună<?= $range['detail'] !== '' ? ', ' . e($range['detail']) : '' ?></p>
            <p class="mt-0.5 text-xs text-ink-muted" data-pay-range-note><?= e($range_note) ?></p>
            <?php else: ?>
            <p class="text-lg font-semibold text-ink" data-pay-net>
              <?= e(net_label((float)$sal['lei_min'], (float)($sal['lei_max'] ?? $sal['lei_min']))) ?>
              <span class="text-sm font-normal text-ink-muted">/ lună, la gradația 0</span>
            </p>
            <p class="text-xs text-ink-muted" data-pay-gross>brut: <?= (int)$sal['lei_min'] === (int)$sal['lei_max']
                ? number_format((int)$sal['lei_min'], 0, ',', '.') : number_format((int)$sal['lei_min'], 0, ',', '.') . '–' . number_format((int)$sal['lei_max'], 0, ',', '.') ?> lei / lună, la gradația 0</p>
            <?php endif; ?>
            <?php if ($sbaza === 'proiect'): ?>
            <p class="mt-0.5 text-xs text-ink-muted" data-pay-project-figure><?= e(SAL_PROJECT_NOTE) ?></p>
            <?php endif; ?>
            <?php if ($_pl): ?>
            <p class="mt-0.5 text-ink" data-pay-level="<?= (int)$_pl['level'] ?>"><?= e($_pl['text']) ?></p>
            <?php endif; ?>
            <?php if ($draft_note !== ''): ?>
            <p class="mt-2 border-t border-note-line pt-2 text-xs text-ink-muted" data-pay-draft-note><a href="/estimator-salariu/" class="underline hover:text-gov"><?= e($draft_note) ?></a></p>
            <?php endif; ?>
            <?php /* Footer: the actions in one row. From sm up the link sits on the left and the summary on the right of the
               same line (the link is absolute so that the open panel can span the full width under both). */ ?>
            <div class="relative mt-2 border-t border-note-line pt-2 text-xs" data-pay-footer>
              <?php if ($_est_url !== ''): ?>
              <p class="mb-1 sm:absolute sm:left-0 sm:top-2 sm:mb-0" data-salary-estimator-link><a href="<?= e($_est_url) ?>" class="text-gov underline">Calculează cu altă gradație</a></p>
              <?php endif; ?>
              <details>
                <summary class="block cursor-pointer list-none text-xs font-semibold uppercase tracking-widest text-note-ink marker:content-none hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus<?= $_est_url !== '' ? ' sm:text-right' : '' ?>">
                  Cum a fost calculat ▾
                </summary>
                <?php if ($decl): ?>
                <div class="mt-2 border-t border-note-line pt-2 text-sm text-ink" data-pay-explainer>
                  <?php /* Copy: docs/specs/2026-11-20-salary-declared-explainer.md, section 3. */ ?>
                  <p class="mb-1 font-semibold">Cum am estimat salariul</p>
                  <ol class="mb-2 list-decimal space-y-1 pl-5">
                    <li data-explainer-step="1"><strong>Ce au declarat.</strong> În <?= (int)$decl['an_venit'] ?>, <?= e($decl['n']) ?> cu funcția <?= e($decl['functie']) ?> la <?= e($decl['institutie']) ?> au trecut venitul din salariu în declarațiile de avere. Valoarea din mijloc a fost <?= e($decl['mediana']) ?> lei net pe lună.</li>
                    <li data-explainer-step="2"><strong>Cât au crescut salariile.</strong> Din <?= (int)$decl['an_venit'] ?> până în 2026, salariul mediu net în administrația publică a crescut cu <?= (int)$decl['crestere'] ?>% (INS).</li>
                    <li data-explainer-step="3"><strong>Estimarea pentru 2026.</strong> Aproximativ <?= e($decl['estimare']) ?> lei net pe lună. Cei mai mulți primesc între <?= e($decl['p25']) ?> și <?= e($decl['p75']) ?> lei net.</li>
                    <li data-explainer-step="4"><strong>Cât de sigur este.</strong> Am verificat metoda pe anii trecuți. Am estimat salariile din <?= (int)$decl['an_venit'] ?> pornind de la cele din <?= (int)$decl['an_venit'] - 1 ?>. Eroarea tipică a fost de <?= (int)$decl['eroare'] ?>%.</li>
                  </ol>
                  <p class="mb-0.5 font-semibold">Ce nu știm</p>
                  <ul class="mb-2 list-disc space-y-0.5 pl-5">
                    <li>Salariul tău depinde de vechime și de sporuri. La angajare, primești de obicei mai puțin decât valoarea din mijloc.</li>
                    <li>Declarația arată venitul pe tot anul, cu prime și vouchere. Datele cele mai noi sunt din <?= (int)$decl['an_venit'] ?>.</li>
                  </ul>
                  <p class="text-xs text-ink-muted"><strong>Surse:</strong> declarațiile de avere, prin monitorul.ai/integritate; INS, seriile FOM106D și FOM106G.
                    <a href="/estimator-salariu/#metoda-declaratii" class="text-gov underline" data-pay-method-link>Metoda pe larg →</a></p>
                </div>
                <?php else: $_ex = salary_explainer($sal, $range, $range_note, $_est_url); ?>
                <?php /* The raw grid rows and the variant are for debugging only: attributes, never text. */ ?>
                <div class="mt-2 border-t border-note-line pt-2 text-sm text-ink" data-pay-law-explainer
                     data-pay-rows="<?= e(implode(',', (array)($sal['randuri'] ?? []))) ?>" data-pay-variant="<?= e((string)($sal['varianta'] ?? '')) ?>">
                  <ol class="mb-2 list-decimal space-y-1 pl-5">
                    <?php foreach ($_ex['steps'] as $_i => $_st): ?>
                    <li data-explainer-step="<?= $_i + 1 ?>"><strong><?= e($_st['head']) ?></strong> <?= e($_st['text']) ?><?php if (!empty($_st['link'])): ?>
                      <a href="<?= e($_st['link']) ?>" class="text-gov underline"><?= e($_st['link_text']) ?></a><?php endif; ?></li>
                    <?php endforeach; ?>
                  </ol>
                  <?php if ($_ex['notes']): ?>
                  <p class="mb-0.5 font-semibold">De reținut</p>
                  <ul class="mb-1 list-disc space-y-0.5 pl-5 text-xs text-ink-muted">
                    <?php foreach ($_ex['notes'] as $_w): ?><li><?= e($_w) ?></li><?php endforeach; ?>
                  </ul>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
              </details>
            </div>
          </div>
          <?php /* The caveat: one small line under the box, not inside it. */ ?>
          <p class="mt-1 max-w-2xl text-xs text-ink-muted" data-pay-disclaimer><?= e((string)($sal['disclaimer'] ?? '')) ?></p>
        </section>
        <?php endif; ?>

        <?php /* UX-INTERESTING (spec § 2.1): one line for each "Posturi interesante" filter that holds for this posting. A fact, not a judgement. */
        $_interes = interes_for_posting($p, pay_level($p)); ?>
        <?php if ($_interes): ?>
        <section class="py-1" data-interesting-list>
          <?php foreach ($_interes as $_iv => $_isent): ?>
          <p class="max-w-2xl text-sm text-ink" data-interesting="<?= e($_iv) ?>">Interesant: <a href="<?= e(home_list_url(['interes' => [$_iv]])) ?>" class="text-gov underline"><?= e(INTERES_LABELS[$_iv]) ?></a> — <?= e($_isent) ?></p>
          <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if ($cond || $tag_groups): ?>
        <section class="flex flex-wrap items-baseline gap-x-6 gap-y-1 py-2">
          <div class="flex items-baseline gap-1.5">
            <h2 class="text-xs font-semibold uppercase tracking-widest text-ink-muted">Condiții deduse automat</h2>
            <a href="/despre/" class="text-xs text-gov hover:underline" title="Cum sunt deduse aceste date">metodologie</a>
          </div>
          <?php foreach ($cond as $label => $value): ?>
            <span class="whitespace-nowrap"><span class="text-ink-muted"><?= e($label) ?></span> <span class="font-medium text-ink"><?= e($value) ?></span></span>
          <?php endforeach; ?>
          <?php foreach ($tag_groups as $group): ?>
            <span><span class="text-ink-muted"><?= e($group['label']) ?></span>
              <span class="font-medium text-ink"><?= e(implode(', ', array_map(fn($v) => ucfirst((string)$v), $group['values']))) ?></span></span>
          <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if ($terms): ?>
        <section class="flex flex-wrap items-baseline gap-x-6 gap-y-1 py-2">
          <h2 class="text-xs font-semibold uppercase tracking-widest text-ink-muted">Condiții contractuale</h2>
          <?php foreach ($terms as $k => $v): ?>
            <span><span class="text-ink-muted"><?= e($k) ?></span> <span class="font-medium text-ink"><?= e((string)$v) ?></span></span>
          <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if ($bib): ?>
        <?php /* Heading, then the topics as one inline-block of comma-separated links: beside the
           heading while they fit, dropped below it as a whole when they are many. */
        $topic_links = [];
        foreach ($bib as $topic) {
            $t = trim((string)$topic);
            if ($t !== '') $topic_links[] = '<a href="/?q=' . urlencode($t) . '" class="text-gov hover:underline">' . e($t) . '</a>';
        } ?>
        <section class="py-2 leading-[1.1rem]">
          <h2 class="mr-3 inline-block align-top text-xs font-semibold uppercase tracking-widest text-ink-muted">Tematica examenului</h2>
          <span class="inline-block max-w-full align-top"><?= implode(', ', $topic_links) ?></span>
          <p class="mt-0.5 text-xs text-ink-faint">Temele din bibliografie, pe scurt. Caută alte concursuri pe aceeași temă.</p>
        </section>
        <?php endif; ?>

        <?php if ($no_sal): ?>
        <section class="py-2" data-no-salary>
          <p class="text-ink-muted">Fără estimare de salariu. Titlul postului nu corespunde unei funcții din grila de salarizare.<?= $eu_funded ? ' Posturile pe proiecte cu fonduri europene sunt plătite de obicei în afara grilei.' : '' ?></p>
        </section>
        <?php endif; ?>

      </div>
      <?php endif; ?>

      <div class="pg-main">

      <!-- Structured schema sections OR body fallback -->
      <?php if ($schema_sections): ?>
        <?php
        // These three are ours; everything else is a Schema.org JobPosting property.
        $ro_specific = ['application_docs', 'application_fee', 'application_contact'];
        ?>
        <?php foreach ($schema_sections as $section): ?>
        <section class="mb-6">
          <h2 class="mb-3 flex items-baseline gap-2 border-b border-line pb-2 font-display text-xl font-semibold italic text-ink">
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
        <h2 class="mb-3 border-b border-line pb-2 font-display text-xl font-semibold italic text-ink">
          Detalii post
        </h2>
        <p class="mb-3 rounded border border-line bg-surface/50 px-3 py-2 text-xs text-ink-muted">
          Textul de mai jos este preluat ca atare de pe posturi.gov.ro. Pentru acest anunț nu există încă
          o versiune structurată pe secțiuni (studii, experiență, atribuții, dosar) —
          <a href="/despre/" class="text-gov hover:underline">vezi metodologia</a>.
        </p>
        <div class="prose-body text-sm leading-relaxed text-ink max-w-none">
          <?= $body_html ?>
        </div>
      </section>
      <?php endif; ?>

      <!-- Calendar timeline. BETA-V5 (261016 "render tables, normalised"): the
           calendar the extraction typed (v4 and v5) is the one table, stage names from
           one vocabulary; the scraped calendar_events rows are the fallback for a
           posting with no extracted calendar. -->
      <?php $llm_cal = llm_calendar_rows($schema); ?>
      <?php if ($llm_cal || $events): ?>
      <section class="mb-8">
        <h2 class="font-display text-xl italic font-semibold text-ink mb-3 pb-2 border-b border-line">
          Calendar concurs
        </h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm border-collapse" data-calendar="<?= $llm_cal ? 'extras' : 'tabel' ?>">
            <thead>
              <tr class="border-b border-line">
                <th scope="col" class="text-left py-1.5 pr-4 text-xs font-medium uppercase tracking-widest text-ink-muted w-32 font-sans">Data</th>
                <th scope="col" class="text-left py-1.5 text-xs font-medium uppercase tracking-widest text-ink-muted font-sans">Etapă</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <?php if ($llm_cal): ?>
                <?php foreach ($llm_cal as $row): ?>
                <tr class="hover:bg-sunken transition-colors">
                  <td class="py-2 pr-4 font-mono text-xs text-ink-muted whitespace-nowrap">
                    <?= $row['date'] !== '' ? fmt_date($row['date']) : '—' ?>
                    <?php if ($row['time']): ?><span> <?= e($row['time']) ?></span><?php endif; ?>
                  </td>
                  <td class="py-2 text-ink">
                    <?= e($row['label']) ?>
                    <?php if ($row['detail']): ?><span class="block text-xs text-ink-muted"><?= e($row['detail']) ?></span><?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <?php foreach ($events as $ev): ?>
                <tr class="hover:bg-sunken transition-colors">
                  <td class="py-2 pr-4 font-mono text-xs text-ink-muted whitespace-nowrap">
                    <?= fmt_date($ev['data']) ?>
                    <?php if ($ev['ora']): ?><span> <?= e($ev['ora']) ?></span><?php endif; ?>
                  </td>
                  <td class="py-2 text-ink"><?= e($ev['eveniment']) ?></td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($llm_cal): ?>
        <p class="mt-2 text-xs text-ink-muted">Etapele sunt extrase automat din anunț; datele exacte sunt cele din anunțul oficial.</p>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <!-- Prompt-v3 structured requirements — after the announcement and its calendar
           since 261016: the official text comes first, the robots' reading of it last. Absent until a v3 extraction has
           run, so this whole block simply does not render for v2 rows. -->
      <?php $v3 = ($p['schema_json'] ?? null) ? (json_decode($p['schema_json'], true) ?: []) : []; ?>
      <?php if (isset($v3['education']) || !empty($v3['skill_list']) || !empty($v3['credentials']) || !empty($v3['positions'])): ?>
      <section class="mb-6 rounded-lg border border-gov/20 bg-gov-light/40 p-4">
        <h2 class="mb-3 flex items-baseline gap-2 font-display text-xl font-semibold italic text-ink">
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
              <a href="/?eqf=<?= (int)$lvl ?>" class="rounded-full border border-info-line bg-surface px-2.5 py-0.5 text-xs text-gov hover:underline">
                <?= e(EQF_LABELS[(int)$lvl] ?? ('EQF ' . $lvl)) ?> <span class="font-mono opacity-60">EQF <?= (int)$lvl ?></span>
              </a>
            <?php endif; ?>
            <?php foreach ($edu['fields_of_study'] ?? [] as $f): ?>
              <a href="/?isced%5B%5D=<?= urlencode((string)($f['isced_field'] ?? '')) ?>"
                 title="<?= e(isced_label((string)($f['isced_field'] ?? ''))) ?>"
                 class="rounded-full border border-line bg-surface px-2.5 py-0.5 text-xs text-ink hover:border-gov hover:text-gov">
                <?= e(ucfirst((string)($f['label_ro'] ?? ''))) ?>
              </a>
            <?php endforeach; ?>
          </div>
          <?php if (($edu['fields_of_study'] ?? []) && count($edu['fields_of_study']) > 1): ?>
            <p class="mt-1 text-xs text-ink-muted">Oricare dintre aceste domenii este acceptat.</p>
          <?php endif; ?>
          <?= evidence_fact_list(evidence_items($v3, $evidence, 0, ['education']), $evidence_url, $evidence_doc, false) ?>
        </div>
        <?php endif; ?>

        <?php /* Experience was not shown in this block before; it is now, for a posting that is not
           split into roles, so that its source has a fact to sit beside. A decomposed posting shows
           each role's own experience below, and the posting-level one is not a role's. */ ?>
        <?php $flat_has_roles = (bool)array_filter((array)($v3['positions'] ?? []), 'is_array'); ?>
        <?php $flat_exp = $flat_has_roles ? '' : role_experience_label(is_array($v3['experience'] ?? null) ? $v3['experience'] : null); ?>
        <?php if ($flat_exp !== ''): ?>
        <div class="mb-3" data-flat-experience>
          <div class="mb-1 text-xs font-semibold uppercase tracking-widest text-ink-muted">Experiență</div>
          <div class="text-sm text-ink"><?= e(ucfirst(preg_replace('/^Experiență:\s*/u', '', $flat_exp) ?? $flat_exp)) ?></div>
          <?= evidence_fact_list(evidence_items($v3, $evidence, 0, ['experience']), $evidence_url, $evidence_doc, false) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($v3['skill_list'])): ?>
        <div class="mb-3">
          <div class="mb-1 text-xs font-semibold uppercase tracking-widest text-ink-muted">Competențe</div>
          <div class="flex flex-wrap gap-1.5">
            <?php foreach ($v3['skill_list'] as $ki => $k): ?>
              <?php /* The disclosure below carries the quote with its verification state, so the bare
                 hover text (an unlabelled model quote) stays only where no evidence row exists. */ ?>
              <a href="/?skill%5B%5D=<?= urlencode((string)($k['label'] ?? '')) ?>"
                 <?php if (!isset($evidence[0]['skill'][$ki])): ?>title="<?= e((string)($k['evidence'] ?? '')) ?>"<?php endif; ?>
                 class="rounded-full border px-2.5 py-0.5 text-xs hover:border-gov hover:text-gov <?= ($k['required'] ?? true) ? 'border-line bg-surface text-ink' : 'border-dashed border-line bg-transparent text-ink-muted' ?>">
                <?= e((string)($k['label'] ?? '')) ?><?php if ($pr = $k['proficiency'] ?? null): ?> <span class="font-mono opacity-70"><?= e($pr) ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
          <?php foreach ($v3['skill_list'] as $k) { if (!($k['required'] ?? true)) { echo '<p class="mt-1 text-xs text-ink-muted">Cele punctate sunt un avantaj, nu o condiție.</p>'; break; } } ?>
          <?= evidence_fact_list(evidence_items($v3, $evidence, 0, ['skill']), $evidence_url, $evidence_doc) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($v3['language_list'])): ?>
        <div class="mb-3">
          <div class="mb-1 text-xs font-semibold uppercase tracking-widest text-ink-muted">Limbi străine</div>
          <div class="flex flex-wrap gap-1.5">
            <?php foreach ($v3['language_list'] as $l): ?>
              <span class="rounded-full border border-line bg-surface px-2.5 py-0.5 text-xs text-ink">
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
          <?= evidence_fact_list(evidence_items($v3, $evidence, 0, ['credential']), $evidence_url, $evidence_doc) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($v3['positions'])): ?>
        <?php $n_roles = count($v3['positions']); ?>
        <div class="mb-3">
          <?php /* One entry is one role: say so, with no count and no "several roles"
             warning (it used to read "Posturi scoase la concurs (1)" under a sentence
             about "mai multe roluri"). Two or more keep the explanation, because each
             role there has its own requirements and the facts above are not role facts. */ ?>
          <div class="mb-1 text-xs font-semibold uppercase tracking-widest text-ink-muted">
            <?= $n_roles === 1 ? 'Post scos la concurs' : 'Posturi scoase la concurs (' . $n_roles . ')' ?>
          </div>
          <?php if ($n_roles > 1): ?>
          <p class="mb-2 text-xs text-ink-muted">Anunțul cuprinde mai multe roluri, fiecare cu cerințele lui.</p>
          <?php endif; ?>
          <div class="space-y-2">
            <?php foreach ($v3['positions'] as $pos_i => $pos): ?>
            <div class="rounded border border-line bg-surface/60 px-3 py-2" data-role>
              <div class="text-sm font-medium text-ink">
                <?php if ($n = $pos['count'] ?? null): ?><span class="font-mono text-gov"><?= (int)$n ?>×</span> <?php endif; ?>
                <?= e((string)($pos['title'] ?? '')) ?>
              </div>
              <div class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-ink-muted">
                <?php $pe = $pos['education'] ?? null; if ($pe && ($pe['minimum_level'] ?? null)): ?>
                  <span>Studii: <?= e(STUDIES_LABELS[$pe['minimum_level']] ?? $pe['minimum_level']) ?></span>
                <?php endif; ?>
                <?php /* Unknown experience renders nothing — never "fără experiență". */ ?>
                <?php if (($exp_text = role_experience_label(is_array($pos['experience'] ?? null) ? $pos['experience'] : null)) !== ''): ?>
                  <span data-role-experience><?= e($exp_text) ?></span>
                <?php endif; ?>
                <?php if (!empty($pos['skill_list'])): ?>
                  <span>Competențe: <?= e(implode(', ', array_slice(array_column($pos['skill_list'], 'label'), 0, 4))) ?></span>
                <?php endif; ?>
                <?php if (!empty($pos['credentials'])): ?>
                  <span data-role-credentials>Documente: <?= e(implode(', ', array_slice(array_filter(array_column($pos['credentials'], 'label'), fn($l) => is_string($l) && $l !== ''), 0, 4))) ?></span>
                <?php endif; ?>
              </div>
              <?php /* Evidence of THIS role only: ordinal = array position + 1, as posting_roles numbers it. */ ?>
              <?= is_array($pos) ? evidence_fact_list(evidence_items($pos, $evidence, (int)$pos_i + 1, ['education', 'experience', 'skill', 'credential']), $evidence_url, $evidence_doc) : '' ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php $pd = $v3['policy_domains'] ?? []; $st = $v3['exam_stages'] ?? []; ?>
        <?php if ($pd || $st): ?>
        <div class="flex flex-wrap gap-x-4 gap-y-1 border-t border-info-line/50 pt-2 text-xs">
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

      <?php /* UX-10: "Pot aplica?" preparation panel (emits nothing when no role states a comparable requirement). */ ?>
      <?php require __DIR__ . '/../partials/eligibility_panel.php'; ?>

      <?php /* UX-11: browser-local preparation checklist (documents, stages). */ ?>
      <?php require __DIR__ . '/../partials/checklist.php'; ?>

      <div class="mt-8 border-t border-line pt-4">
        <?= robots_disclaimer((string)$p['url']) ?>
        <p class="mt-1 text-xs text-ink-muted"><a href="/despre/#cum-functioneaza" class="text-gov hover:underline">Cum funcționează</a></p>
      </div>

      </div><!-- /pg-main -->
    </div>
  </div>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>
