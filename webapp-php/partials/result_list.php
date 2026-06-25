<?php
// Variables expected from parent scope:
// $postings, $total_count, $page, $num_pages, $PAGE_SIZE, $q
// $judet_slugs, $levels, $types, $categories, $emp_cats, $exp_before, $exp_after
$today = date('Y-m-d');
$start = ($page - 1) * $PAGE_SIZE + 1;
$end   = min($page * $PAGE_SIZE, $total_count);
?>

<!-- Top bar: count + pagination info -->
<div class="flex items-center justify-between mb-3 pb-2 border-b border-border-warm">
  <div class="text-sm text-ink-muted">
    <?php if ($total_count): ?>
      <span class="font-mono font-medium text-ink"><?= $total_count ?></span>
      postur<?= $total_count !== 1 ? 'i' : '...' ?> găsite
    <?php else: ?>
      Niciun rezultat
    <?php endif; ?>
    <?php if ($q): ?>
      <span class="ml-1">pentru <em class="text-ink">"<?= e($q) ?>"</em></span>
    <?php endif; ?>
  </div>
  <?php if ($total_count && $num_pages > 1): ?>
    <span class="text-xs text-ink-faint font-mono">pag. <?= $page ?>/<?= $num_pages ?></span>
  <?php endif; ?>
</div>

<!-- Active filter chips -->
<?php if ($q || $judet_slugs || $levels || $types || $categories || $emp_cats || $exp_before || $exp_after): ?>
<div class="flex flex-wrap gap-1.5 mb-3">
  <?php if ($q): ?>
    <a href="<?= e(qs_with('q', null)) ?>" class="inline-flex items-center gap-1 px-2 py-0.5 bg-gov-light text-gov text-xs border border-blue-200 hover:bg-red-50 hover:border-red-200 hover:text-red-700 transition-colors">
      "<?= e($q) ?>" ×
    </a>
  <?php endif; ?>
  <?php foreach ($judet_slugs as $s): ?>
    <a href="<?= e(qs_with('judet', null)) ?>" class="inline-flex items-center gap-1 px-2 py-0.5 bg-gov-light text-gov text-xs border border-blue-200 hover:bg-red-50 hover:text-red-700 transition-colors">
      Județ: <?= e($s) ?> ×
    </a>
  <?php endforeach; ?>
  <?php foreach ($levels as $l): ?>
    <a href="<?= e(qs_with('level', null)) ?>" class="inline-flex items-center gap-1 px-2 py-0.5 bg-gov-light text-gov text-xs border border-blue-200 hover:bg-red-50 hover:text-red-700 transition-colors">
      <?= e($l) ?> ×
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Results list -->
<?php if ($postings): ?>
  <div class="divide-y divide-border-warm border-t border-border-warm">
    <?php foreach ($postings as $p):
        $inferred = json_decode($p['inferred'] ?? '{}', true) ?: [];
        $days = days_until($p['expires_at']);
    ?>
    <article class="py-4 group hover:bg-parchment-dark transition-colors -mx-1 px-1">
      <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
          <a href="/job/<?= $p['id'] ?>/" class="block group-hover:text-gov transition-colors">
            <h2 class="font-display text-base font-semibold italic text-ink group-hover:text-gov leading-snug">
              <?= e($p['title']) ?>
            </h2>
          </a>
          <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-muted">
            <a href="/angajator/<?= e($p['employer_slug_display'] ?? $p['employer_slug'] ?? '') ?>/"
               class="font-medium text-ink-muted hover:text-gov hover:underline truncate max-w-xs">
              <?= e($p['employer_name']) ?>
            </a>
            <?php if ($p['judet_name']): ?>
              <span class="text-ink-faint">·</span>
              <span><?= e($p['judet_name']) ?></span>
            <?php endif; ?>
            <?php if ($p['published_at']): ?>
              <span class="text-ink-faint">·</span>
              <span class="font-mono"><?= fmt_date($p['published_at']) ?></span>
            <?php endif; ?>
          </div>

          <!-- Badges -->
          <div class="mt-2 flex flex-wrap gap-1.5">
            <?php if ($p['job_level']): ?>
              <span class="px-1.5 py-0.5 text-xs font-medium <?= $p['job_level'] === 'conducere' ? 'bg-blue-50 text-blue-800 border border-blue-200' : 'bg-slate-100 text-slate-700 border border-slate-200' ?>">
                <?= e($p['job_level']) ?>
              </span>
            <?php endif; ?>
            <?php if ($p['job_type']): ?>
              <span class="px-1.5 py-0.5 text-xs bg-slate-100 text-slate-600 border border-slate-200"><?= e($p['job_type']) ?></span>
            <?php endif; ?>
            <?php if ($p['categorie']): ?>
              <span class="px-1.5 py-0.5 text-xs <?= stripos($p['categorie'], 'public') !== false ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-amber-50 text-amber-800 border border-amber-200' ?>">
                <?= e($p['categorie']) ?>
              </span>
            <?php endif; ?>
            <?php if (($p['nr_posturi'] ?? 0) > 1): ?>
              <span class="px-1.5 py-0.5 text-xs bg-gov-light text-gov border border-blue-200 font-mono">
                <?= $p['nr_posturi'] ?> posturi
              </span>
            <?php endif; ?>
          </div>

          <!-- Inferred condition badges -->
          <?php $wt = $inferred['work_type'] ?? ''; $re = $inferred['remote_eligible'] ?? false;
                $rc = $inferred['requires_computer'] ?? null; $exp = $inferred['experience_years'] ?? null; ?>
          <?php if ($wt || $re || $rc !== null || $exp): ?>
          <div class="flex flex-wrap gap-1 mt-1">
            <?php if ($wt): ?>
              <span class="px-1.5 py-0.5 text-xs font-medium bg-stone-50 text-stone-600 border border-stone-200 rounded"><?= e(work_type_label($wt)) ?></span>
            <?php endif; ?>
            <?php if ($re): ?>
              <span class="px-1.5 py-0.5 text-xs font-medium bg-stone-50 text-stone-600 border border-stone-200 rounded">Telemuncă</span>
            <?php endif; ?>
            <?php if ($rc): ?>
              <span class="px-1.5 py-0.5 text-xs font-medium bg-stone-50 text-stone-600 border border-stone-200 rounded">Calculator</span>
            <?php endif; ?>
            <?php if ($exp): ?>
              <span class="px-1.5 py-0.5 text-xs font-medium bg-stone-50 text-stone-600 border border-stone-200 rounded">Min. <?= $exp ?> ani</span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Deadline badge -->
        <div class="shrink-0 text-right min-w-[80px]">
          <?php if ($p['expires_at'] && $days !== null): ?>
            <?php if ($days < 0): ?>
              <span class="text-xs text-ink-faint font-mono">Expirat</span>
            <?php elseif ($days === 0): ?>
              <span class="text-xs font-semibold text-red-600 font-mono">Azi!</span>
            <?php elseif ($days <= 3): ?>
              <span class="text-xs font-semibold text-red-600 font-mono"><?= $days ?>z !</span>
            <?php elseif ($days <= 7): ?>
              <span class="text-xs font-medium text-amber-700 font-mono"><?= $days ?>z</span>
            <?php else: ?>
              <span class="text-xs text-ink-muted font-mono"><?= $days ?>z</span>
            <?php endif; ?>
            <div class="text-xs text-ink-faint font-mono mt-0.5"><?= fmt_date($p['expires_at']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <?php if ($num_pages > 1): ?>
  <nav class="mt-6 flex items-center justify-between text-sm">
    <div class="text-xs text-ink-faint font-mono"><?= $start ?>–<?= $end ?> din <?= $total_count ?></div>
    <div class="flex items-center gap-1">
      <?php if ($page > 1): ?>
        <a href="<?= e(qs_page($page - 1)) ?>"
           hx-get="<?= e(qs_page($page - 1)) ?>"
           hx-target="#results" hx-push-url="true" hx-swap="innerHTML"
           class="px-3 py-1 border border-border-warm text-ink-muted hover:text-gov hover:border-gov transition-colors">
          ← Prev
        </a>
      <?php endif; ?>

      <?php for ($i = max(1, $page - 3); $i <= min($num_pages, $page + 3); $i++): ?>
        <?php if ($i === $page): ?>
          <span class="px-3 py-1 bg-gov text-white text-sm font-mono"><?= $i ?></span>
        <?php else: ?>
          <a href="<?= e(qs_page($i)) ?>"
             hx-get="<?= e(qs_page($i)) ?>"
             hx-target="#results" hx-push-url="true" hx-swap="innerHTML"
             class="px-3 py-1 border border-border-warm text-ink-muted hover:text-gov hover:border-gov transition-colors font-mono text-xs">
            <?= $i ?>
          </a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($page < $num_pages): ?>
        <a href="<?= e(qs_page($page + 1)) ?>"
           hx-get="<?= e(qs_page($page + 1)) ?>"
           hx-target="#results" hx-push-url="true" hx-swap="innerHTML"
           class="px-3 py-1 border border-border-warm text-ink-muted hover:text-gov hover:border-gov transition-colors">
          Următor →
        </a>
      <?php endif; ?>
    </div>
  </nav>
  <?php endif; ?>

<?php else: ?>
  <div class="py-16 text-center">
    <div class="font-display text-4xl text-ink-faint italic mb-3">—</div>
    <p class="text-ink-muted text-sm">Niciun anunț nu corespunde filtrelor selectate.</p>
    <a href="/" class="mt-4 inline-block text-sm text-gov hover:underline">Șterge filtrele</a>
  </div>
<?php endif; ?>
