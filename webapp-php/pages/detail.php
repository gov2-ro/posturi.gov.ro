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
$page_title = $p['title'] ?: 'Anunț';
require __DIR__ . '/../inc/header.php';
?>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-6">

  <div class="mb-4 text-sm">
    <a href="javascript:history.back()" class="text-gov hover:underline">← Înapoi la căutare</a>
  </div>

  <!-- Title block -->
  <div class="border-b border-border-warm pb-5 mb-6">
    <h1 class="font-display text-2xl sm:text-3xl font-semibold italic text-ink leading-tight mb-2">
      <?= e($p['title']) ?>
    </h1>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-ink-muted">
      <a href="/angajator/<?= e($p['employer_slug'] ?? '') ?>/"
         class="font-medium text-gov hover:underline"><?= e($p['employer_name'] ?? '') ?></a>
      <?php if ($p['judet_name']): ?>
        <span class="text-ink-faint">·</span>
        <span><?= e($p['judet_name']) ?></span>
      <?php endif; ?>
      <?php if ($p['published_at']): ?>
        <span class="text-ink-faint">·</span>
        <span class="font-mono text-xs">Publicat <?= fmt_date($p['published_at']) ?></span>
      <?php endif; ?>
      <a href="<?= e($p['url']) ?>" target="_blank" rel="noopener"
         class="text-xs text-gov hover:underline font-mono ml-auto">sursă ↗</a>
    </div>

    <!-- Badges -->
    <div class="mt-3 flex flex-wrap gap-2">
      <?php if ($p['job_level']): ?>
        <span class="px-2 py-0.5 text-xs font-medium <?= $p['job_level'] === 'conducere' ? 'bg-blue-50 text-blue-800 border border-blue-200' : 'bg-slate-100 text-slate-700 border border-slate-200' ?>">
          <?= e($p['job_level']) ?>
        </span>
      <?php endif; ?>
      <?php if ($p['job_type']): ?>
        <span class="px-2 py-0.5 text-xs bg-slate-100 text-slate-600 border border-slate-200"><?= e($p['job_type']) ?></span>
      <?php endif; ?>
      <?php if ($p['categorie']): ?>
        <span class="px-2 py-0.5 text-xs <?= stripos($p['categorie'], 'public') !== false ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-amber-50 text-amber-800 border border-amber-200' ?>">
          <?= e($p['categorie']) ?>
        </span>
      <?php endif; ?>
      <?php if ($days !== null): ?>
        <?php if ($days < 0): ?>
          <span class="px-2 py-0.5 text-xs bg-slate-100 text-ink-faint border border-slate-200 font-mono">Expirat <?= fmt_date($p['expires_at']) ?></span>
        <?php elseif ($days <= 3): ?>
          <span class="px-2 py-0.5 text-xs bg-red-50 text-red-700 border border-red-200 font-mono font-semibold">Expiră în <?= $days ?> zile!</span>
        <?php elseif ($days <= 7): ?>
          <span class="px-2 py-0.5 text-xs bg-amber-50 text-amber-800 border border-amber-200 font-mono">Expiră în <?= $days ?> zile</span>
        <?php else: ?>
          <span class="px-2 py-0.5 text-xs bg-gov-light text-gov border border-blue-200 font-mono">Expiră <?= fmt_date($p['expires_at']) ?></span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Two-column layout -->
  <div class="flex gap-8 items-start">

    <!-- Sidebar -->
    <aside class="w-64 shrink-0 hidden sm:block">
      <div class="space-y-4 text-sm">

        <?php if ($p['employer_category']): ?>
        <div>
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-faint mb-1">Tip angajator</div>
          <div class="text-ink"><?= e($p['employer_category']) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($p['nr_posturi']): ?>
        <div>
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-faint mb-1">Nr. posturi</div>
          <div class="font-mono text-ink text-lg font-medium"><?= $p['nr_posturi'] ?></div>
        </div>
        <?php endif; ?>

        <?php if ($p['data_limita_depunere']): ?>
        <div>
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-faint mb-1">Termen depunere</div>
          <div class="font-mono text-ink"><?= fmt_datetime($p['data_limita_depunere'], 'd.m.Y') ?></div>
          <div class="text-xs text-ink-faint">ora <?= fmt_datetime($p['data_limita_depunere'], 'H:i') ?></div>
        </div>
        <?php endif; ?>

        <?php if ($p['data_proba_scrisa']): ?>
        <div>
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-faint mb-1">Probă scrisă</div>
          <div class="font-mono text-ink"><?= fmt_date($p['data_proba_scrisa']) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($p['data_interviu']): ?>
        <div>
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-faint mb-1">Interviu</div>
          <div class="font-mono text-ink"><?= fmt_date($p['data_interviu']) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($p['data_rezultate_finale']): ?>
        <div>
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-faint mb-1">Rezultate finale</div>
          <div class="font-mono text-ink"><?= fmt_date($p['data_rezultate_finale']) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($p['contact_person'] || $p['contact_phone'] || $p['contact_email']): ?>
        <div class="pt-3 border-t border-border-warm">
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-faint mb-2">Contact</div>
          <?php if ($p['contact_person']): ?>
            <div class="text-ink mb-0.5"><?= e($p['contact_person']) ?></div>
          <?php endif; ?>
          <?php if ($p['contact_phone']): ?>
            <div class="font-mono text-sm text-gov">
              <a href="tel:<?= e($p['contact_phone']) ?>"><?= e($p['contact_phone']) ?></a>
            </div>
          <?php endif; ?>
          <?php if ($p['contact_email']): ?>
            <div class="text-sm text-gov truncate">
              <a href="mailto:<?= e($p['contact_email']) ?>"><?= e($p['contact_email']) ?></a>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
        $fam = $inferred['profession_family'] ?? null;
        $sen = $inferred['seniority'] ?? null;
        if (($fam && $fam !== 'altele') || $sen || $anomaly_flags_list):
        ?>
        <div class="pt-3 border-t border-border-warm">
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-faint mb-2">Inferat automat</div>
          <?php if ($fam && $fam !== 'altele'): ?>
            <div class="mb-1"><span class="text-xs text-ink-faint">Domeniu</span> <span class="ml-1 text-sm text-ink"><?= e($fam) ?></span></div>
          <?php endif; ?>
          <?php if ($sen): ?>
            <div class="mb-1"><span class="text-xs text-ink-faint">Grad</span> <span class="ml-1 text-sm text-ink"><?= e($sen) ?></span></div>
          <?php endif; ?>
          <?php if ($anomaly_flags_list): ?>
            <div class="mt-1 flex flex-wrap gap-1">
              <?php foreach ($anomaly_flags_list as $flag): ?>
                <span class="px-1.5 py-0.5 text-xs bg-amber-50 text-amber-800 border border-amber-200"><?= e(ANOMALY_LABELS[$flag] ?? $flag) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($p['announcement_url']): ?>
        <div class="pt-3 border-t border-border-warm">
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-faint mb-1">Atașamente</div>
          <a href="<?= e($p['announcement_url']) ?>" target="_blank" rel="noopener"
             class="text-sm text-gov hover:underline break-all">Anunț oficial ↗</a>
        </div>
        <?php endif; ?>

        <?php if ($other_links): ?>
        <div>
          <?php foreach ($other_links as $link): ?>
            <a href="<?= e($link) ?>" target="_blank" rel="noopener"
               class="block text-sm text-gov hover:underline truncate">Link ↗</a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      </div>
    </aside>

    <!-- Main content -->
    <div class="flex-1 min-w-0">

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
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 p-4 bg-amber-50/30 border border-amber-200/60 rounded-lg mb-6 text-sm">
        <div class="flex flex-col gap-0.5">
          <span class="text-xs font-semibold uppercase tracking-widest text-ink-faint">Program</span>
          <?php if ($wt): ?><span class="font-medium text-ink"><?= e(work_type_label($wt)) ?></span>
          <?php else: ?><span class="text-ink-faint">—</span><?php endif; ?>
        </div>
        <div class="flex flex-col gap-0.5">
          <span class="text-xs font-semibold uppercase tracking-widest text-ink-faint">Telemuncă</span>
          <?php if ($re): ?><span class="font-medium text-ink">Da</span>
          <?php else: ?><span class="text-ink-faint">—</span><?php endif; ?>
        </div>
        <div class="flex flex-col gap-0.5">
          <span class="text-xs font-semibold uppercase tracking-widest text-ink-faint">Calculator</span>
          <?php if ($rc === true || $rc === 1): ?>
            <span class="font-medium text-ink">Solicitat<?= ($inferred['computer_level'] ?? '') === 'advanced' ? ' (avansat)' : '' ?></span>
          <?php elseif ($rc === false || $rc === 0): ?>
            <span class="font-medium text-ink">Nesolicitat</span>
          <?php else: ?>
            <span class="text-ink-faint">—</span>
          <?php endif; ?>
        </div>
        <div class="flex flex-col gap-0.5">
          <span class="text-xs font-semibold uppercase tracking-widest text-ink-faint">Experiență</span>
          <?php if ($exp): ?><span class="font-medium text-ink">Min. <?= $exp ?> ani</span>
          <?php else: ?><span class="text-ink-faint">—</span><?php endif; ?>
        </div>
        <div class="flex flex-col gap-0.5">
          <span class="text-xs font-semibold uppercase tracking-widest text-ink-faint">Studii</span>
          <?php if ($st): ?><span class="font-medium text-ink"><?= e(ucfirst($st)) ?></span>
          <?php else: ?><span class="text-ink-faint">—</span><?php endif; ?>
        </div>
        <div class="flex flex-col gap-0.5">
          <span class="text-xs font-semibold uppercase tracking-widest text-ink-faint">Salariu</span>
          <?php if ($smn || $smx): ?>
            <span class="font-medium text-ink font-mono">
              <?php
              if ($smn && $smx && $smn != $smx) echo $smn . '–' . $smx;
              elseif ($smn) echo $smn;
              else echo $smx;
              ?> RON
            </span>
          <?php else: ?><span class="text-ink-faint">—</span><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Structured schema sections OR body fallback -->
      <?php if ($schema_sections): ?>
        <?php foreach ($schema_sections as $section): ?>
        <div class="mb-6">
          <h2 class="font-display text-lg italic font-semibold text-ink mb-3 pb-2 border-b border-border-warm">
            <?= e($section['label']) ?>
          </h2>
          <div class="prose-body text-sm leading-relaxed text-ink max-w-none">
            <?= $section['html'] ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php elseif ($body_html): ?>
      <div class="mb-6">
        <h2 class="font-display text-lg italic font-semibold text-ink mb-3 pb-2 border-b border-border-warm">
          Detalii post
        </h2>
        <div class="prose-body text-sm leading-relaxed text-ink max-w-none">
          <?= $body_html ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Calendar timeline -->
      <?php if ($events): ?>
      <div class="mb-8">
        <h2 class="font-display text-lg italic font-semibold text-ink mb-3 pb-2 border-b border-border-warm">
          Calendar concurs
        </h2>
        <table class="w-full text-sm border-collapse">
          <thead>
            <tr class="border-b border-border-warm">
              <th class="text-left py-1.5 pr-4 text-xs font-semibold uppercase tracking-widest text-ink-faint w-32 font-sans font-medium">Data</th>
              <th class="text-left py-1.5 text-xs font-semibold uppercase tracking-widest text-ink-faint font-sans font-medium">Etapă</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-warm">
            <?php foreach ($events as $ev): ?>
            <tr class="hover:bg-parchment-dark transition-colors">
              <td class="py-2 pr-4 font-mono text-xs text-ink-muted whitespace-nowrap">
                <?= fmt_date($ev['data']) ?>
                <?php if ($ev['ora']): ?><span class="text-ink-faint"> <?= e($ev['ora']) ?></span><?php endif; ?>
              </td>
              <td class="py-2 text-ink"><?= e($ev['eveniment']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <!-- Mobile sidebar info -->
      <div class="sm:hidden mt-6 pt-4 border-t border-border-warm text-sm space-y-3">
        <?php if ($p['data_limita_depunere']): ?>
          <div><span class="font-semibold text-ink-faint text-xs uppercase">Termen:</span> <span class="font-mono"><?= fmt_datetime($p['data_limita_depunere']) ?></span></div>
        <?php endif; ?>
        <?php if ($p['contact_email']): ?>
          <div><span class="font-semibold text-ink-faint text-xs uppercase">Email:</span> <a href="mailto:<?= e($p['contact_email']) ?>" class="text-gov"><?= e($p['contact_email']) ?></a></div>
        <?php endif; ?>
        <?php if ($p['contact_phone']): ?>
          <div><span class="font-semibold text-ink-faint text-xs uppercase">Tel:</span> <a href="tel:<?= e($p['contact_phone']) ?>" class="text-gov font-mono"><?= e($p['contact_phone']) ?></a></div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>
