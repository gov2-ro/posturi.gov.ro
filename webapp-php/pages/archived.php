<?php
/**
 * UX-03 (D14): the page of a posting that expired or was cancelled in the last 90
 * days (export-to-sqlite.py archived_postings). Required by detail.php with
 * $archived set. What it was, that it closed, and open jobs like it — never
 * indexed, never listed, so a saved or shared link does not end in a 404.
 */
declare(strict_types=1);

$a = $archived;
$canonical_path = job_url($a);
if (isset($requested_path) && rtrim($requested_path, '/') !== rtrim($canonical_path, '/')) {
    header('Location: ' . $canonical_path, true, 301);
    exit;
}

// Similar open jobs: the same occupation, else the same profession family; the
// same county first, then the nearest deadline.
$similar = [];
foreach ([['occ_canonical', (string)$a['occ_canonical']], ['inf_profession_family', (string)$a['inf_profession_family']]] as [$col, $val]) {
    if ($val === '' || $val === 'altele') continue;
    $st = db()->prepare("SELECT j.*, e.slug AS employer_slug_display FROM job_postings j
        LEFT JOIN employers e ON e.id = j.employer_id
        WHERE j.$col = ? AND j.application_status IN (" . OPEN_STATUS_SQL . ")
        ORDER BY (j.judet_slug = ?) DESC, " . DEADLINE_COL . " IS NULL, " . DEADLINE_COL . " ASC, j.published_at DESC
        LIMIT 8");
    $st->execute([$val, (string)$a['judet_slug']]);
    $similar = $st->fetchAll();
    if ($similar) break;
}

$state      = $a['cancelled'] ? 'Anunț anulat' : 'Anunț expirat';
$page_title = $a['title'] . ' — ' . mb_strtolower($state, 'UTF-8');
$head_extra = '<meta name="robots" content="noindex">';
require __DIR__ . '/../inc/header.php';
$place = place_label($a);
$fmt = static fn(?string $d): string => $d ? fmt_date($d) : '—';
?>
<div class="mx-auto max-w-screen-md px-4 py-6 sm:px-6">
  <nav aria-label="Firimituri" class="mb-4 text-sm">
    <a href="/" class="text-gov hover:underline">← Toate posturile active</a>
  </nav>

  <p class="mb-3 inline-block border border-alert-line bg-alert px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-alert-ink"><?= e($state) ?></p>
  <h1 class="font-display text-[1.7rem] font-semibold text-ink sm:text-[2rem]"><?= e($a['title']) ?></h1>
  <p class="mt-1 text-sm text-ink-muted">
    <?= e($a['employer_name']) ?><?php if ($place): ?> · <?= e($place) ?><?php endif; ?>
  </p>

  <p class="mt-4 text-sm leading-relaxed text-ink">
    <?php if ($a['cancelled']): ?>
      Instituția a anulat acest concurs. Nu se mai pot depune dosare.
    <?php else: ?>
      Anunțul a expirat pe posturi.gov.ro<?= $a['expires_at'] ? ' la ' . e($fmt($a['expires_at'])) : '' ?>; înscrierile s-au încheiat.
    <?php endif; ?>
    Păstrăm această pagină 90 de zile, ca să nu se piardă linkurile salvate sau trimise.
  </p>

  <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
    <dt class="text-ink-muted">Publicat</dt><dd><?= e($fmt($a['published_at'])) ?></dd>
    <dt class="text-ink-muted">Termen de depunere</dt><dd><?= e($fmt($a['apply_deadline'])) ?></dd>
    <?php if (($a['nr_posturi'] ?? 0) > 1): ?><dt class="text-ink-muted">Posturi</dt><dd><?= (int)$a['nr_posturi'] ?></dd><?php endif; ?>
    <?php if ($sal = list_salary($a)): ?><dt class="text-ink-muted">Salariu</dt><dd><?= e($sal['text']) ?><?= $sal['estimated'] ? ' (estimat)' : '' ?></dd><?php endif; ?>
  </dl>

  <?php if (trim((string)$a['summary']) !== ''): ?>
  <section class="mt-6">
    <h2 class="mb-2 text-xs font-semibold uppercase tracking-widest text-ink-muted">Începutul anunțului</h2>
    <div class="prose-body text-sm leading-relaxed text-ink"><?= render_markdown((string)$a['summary']) ?></div>
  </section>
  <?php endif; ?>

  <p class="mt-4 text-xs text-ink-muted">
    Anunțul original: <a href="<?= e(evidence_safe_url((string)$a['url']) ?? 'https://posturi.gov.ro/') ?>" target="_blank" rel="noopener" class="text-gov underline">posturi.gov.ro</a>
    (poate să nu mai fie disponibil).
  </p>

  <section class="mt-8 border-t border-line pt-5">
    <h2 class="mb-3 font-display text-lg font-semibold text-ink">Posturi asemănătoare, deschise acum</h2>
    <?php if ($similar): ?>
    <ul class="divide-y divide-line">
      <?php foreach ($similar as $sp): $sdl = posting_deadline($sp); $sd = days_until($sdl['date']); ?>
      <li class="flex items-baseline justify-between gap-3 py-2 text-sm">
        <span class="min-w-0">
          <a href="<?= e(job_url($sp)) ?>" class="font-medium text-ink hover:text-gov"><?= e(display_title($sp)['primary']) ?></a>
          <span class="block truncate text-xs text-ink-muted"><?= e($sp['employer_name']) ?><?php if ($pl = place_label($sp)): ?> · <?= e($pl) ?><?php endif; ?></span>
        </span>
        <?php if ($sd !== null && $sd >= 0): ?>
          <span class="shrink-0 font-mono text-xs text-ink-muted"><?= e($sd === 0 ? 'azi' : days_label($sd)) ?></span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
      <p class="text-sm text-ink-muted">Nu am găsit acum posturi deschise pentru aceeași ocupație.
        <a href="/" class="text-gov hover:underline">Vezi toate posturile active</a>.</p>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
