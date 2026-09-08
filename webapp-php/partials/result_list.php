<?php
// Variables expected from parent scope:
// $postings, $total_count, $page, $num_pages, $PAGE_SIZE, $q, $is_htmx
// $active_chips, $relaxations
$today = date('Y-m-d');
$start = ($page - 1) * $PAGE_SIZE + 1;
$end   = min($page * $PAGE_SIZE, $total_count);
$chips = $active_chips ?? [];
?>

<?php if (!empty($is_htmx)): ?>
  <!-- Out-of-band updates for the chrome that lives outside #results -->
  <p id="results-status" role="status" aria-live="polite" aria-atomic="true" class="sr-only" hx-swap-oob="true"><?= $total_count ?> rezultate</p>
  <span id="filter-count" hx-swap-oob="true"
        class="<?= $chips ? '' : 'hidden ' ?>inline-flex min-w-[1.25rem] justify-center rounded-full bg-gov px-1.5 py-0.5 text-xs font-mono text-on-gov"><?= count($chips) ?></span>
  <span id="drawer-count" hx-swap-oob="true"><?= $total_count ?></span>
<?php endif; ?>

<!-- Top bar: count + pagination info -->
<div class="mb-3 flex items-center justify-between border-b border-line pb-2">
  <div class="text-sm text-ink-muted">
    <?php if ($total_count): ?>
      <span class="font-mono font-medium text-ink"><?= $total_count ?></span>
      <?= $total_count === 1 ? 'post găsit' : 'posturi găsite' ?>
    <?php else: ?>
      Niciun rezultat
    <?php endif; ?>
    <?php if ($q): ?>
      <span class="ml-1">pentru <em class="text-ink">„<?= e($q) ?>”</em></span>
    <?php endif; ?>
  </div>
  <?php if ($total_count && $num_pages > 1): ?>
    <span class="font-mono text-xs text-ink-muted">pag. <?= $page ?>/<?= $num_pages ?></span>
  <?php endif; ?>
</div>

<!-- Active filter chips — one per value, each removing only itself -->
<?php if ($chips): ?>
<div class="mb-3 flex flex-wrap items-center gap-1.5">
  <?php foreach ($chips as $chip): ?>
    <a href="<?= e($chip['href']) ?>"
       data-chip-param="<?= e($chip['param']) ?>"
       data-chip-value="<?= e($chip['value']) ?>"
       class="inline-flex items-center gap-1 rounded-full border border-info-line bg-gov-light px-2.5 py-1 text-xs text-gov transition-colors hover:border-alert-line hover:bg-alert hover:text-alert-ink">
      <?php if ($chip['group'] !== ''): ?><span class="opacity-70"><?= e($chip['group']) ?>:</span><?php endif; ?>
      <span class="font-medium"><?= e($chip['label']) ?></span>
      <span aria-hidden="true" class="text-sm leading-none">×</span>
      <span class="sr-only">— elimină filtrul</span>
    </a>
  <?php endforeach; ?>
  <?php if (count($chips) > 1): ?>
    <a href="/" class="px-2 py-1 text-xs text-ink-muted underline hover:text-gov">Șterge tot</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Results list -->
<?php if ($postings): ?>
  <ul class="divide-y divide-line border-t border-line">
    <?php foreach ($postings as $p):
        $inferred = json_decode($p['inferred'] ?? '{}', true) ?: [];
        $days = days_until($p['expires_at']);
    ?>
    <li class="group -mx-1 px-1 py-4 transition-colors hover:bg-sunken">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
          <h2 class="font-display text-base font-semibold italic leading-snug text-ink">
            <a href="<?= e(job_url($p)) ?>" class="inline-block py-0.5 transition-colors hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
              <?= e($p['title']) ?>
            </a>
          </h2>
          <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-muted">
            <a href="/angajator/<?= e($p['employer_slug_display'] ?? $p['employer_slug'] ?? '') ?>/"
               class="max-w-xs truncate py-1 font-medium text-ink-muted hover:text-gov hover:underline">
              <?= e($p['employer_name']) ?>
            </a>
            <?php if ($place = place_label($p)): ?>
              <span aria-hidden="true" class="text-ink-muted">·</span>
              <span><?= e($place) ?></span>
            <?php endif; ?>
            <?php if ($p['published_at']): ?>
              <span aria-hidden="true" class="text-ink-muted">·</span>
              <time datetime="<?= e(substr($p['published_at'], 0, 10)) ?>" class="font-mono"><?= fmt_date($p['published_at']) ?></time>
            <?php endif; ?>
          </div>

          <!-- Badges -->
          <div class="mt-2 flex flex-wrap gap-1.5">
            <?php if ($p['job_level']): ?>
              <span class="px-1.5 py-0.5 text-xs font-medium <?= $p['job_level'] === 'conducere' ? 'border border-info-line bg-info text-info-ink' : 'border border-neutral-line bg-neutral text-neutral-ink' ?>">
                <?= e($p['job_level']) ?>
              </span>
            <?php endif; ?>
            <?php if ($p['job_type']): ?>
              <span class="border border-neutral-line bg-neutral px-1.5 py-0.5 text-xs text-neutral-ink"><?= e($p['job_type']) ?></span>
            <?php endif; ?>
            <?php if ($p['categorie']): ?>
              <span class="px-1.5 py-0.5 text-xs <?= stripos($p['categorie'], 'public') !== false ? 'border border-ok-line bg-ok text-ok-ink' : 'border border-note-line bg-note text-note-ink' ?>">
                <?= e($p['categorie']) ?>
              </span>
            <?php endif; ?>
            <?php if (($p['nr_posturi'] ?? 0) > 1): ?>
              <span class="border border-info-line bg-gov-light px-1.5 py-0.5 font-mono text-xs text-gov">
                <?= $p['nr_posturi'] ?> posturi
              </span>
            <?php endif; ?>
          </div>

          <!-- Derived attributes. Visually separated from the badges above,
               which come straight from the source; these are inferred. -->
          <?php $meta = inferred_meta($p); ?>
          <?php if ($meta): ?>
          <div class="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-ink-muted">
            <abbr title="Atribute deduse automat din textul anunțului — pot fi incomplete sau greșite. Vezi metodologia."
                  class="cursor-help font-mono text-[10px] uppercase tracking-wider text-ink-muted no-underline decoration-dotted underline-offset-2 [text-decoration:underline]">auto</abbr>
            <?php foreach ($meta as $k => $item): ?>
              <?php if ($k): ?><span aria-hidden="true" class="text-line">·</span><?php endif; ?>
              <span><?= e($item) ?></span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Deadline badge -->
        <div class="min-w-[80px] shrink-0 text-right">
          <?php if ($p['expires_at'] && $days !== null): ?>
            <?php if ($days < 0): ?>
              <span class="font-mono text-xs text-ink-muted">Expirat</span>
            <?php elseif ($days === 0): ?>
              <span class="font-mono text-xs font-semibold text-alert-ink">Azi!</span>
            <?php elseif ($days <= 3): ?>
              <span class="font-mono text-xs font-semibold text-alert-ink"><?= e(days_label($days)) ?></span>
            <?php elseif ($days <= 7): ?>
              <span class="font-mono text-xs font-medium text-note-ink"><?= e(days_label($days)) ?></span>
            <?php else: ?>
              <span class="font-mono text-xs text-ink-muted"><?= e(days_label($days)) ?></span>
            <?php endif; ?>
            <time datetime="<?= e(substr($p['expires_at'], 0, 10)) ?>" class="mt-0.5 block font-mono text-xs text-ink-muted"><?= fmt_date($p['expires_at']) ?></time>
          <?php endif; ?>
        </div>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>

  <!-- Pagination -->
  <?php if ($num_pages > 1): ?>
  <nav class="mt-6 flex items-center justify-between text-sm" aria-label="Paginare rezultate">
    <div class="font-mono text-xs text-ink-muted"><?= $start ?>–<?= $end ?> din <?= $total_count ?></div>
    <div class="flex items-center gap-1">
      <?php if ($page > 1): ?>
        <a href="<?= e(qs_page($page - 1)) ?>" rel="prev" aria-label="Pagina anterioară"
           hx-get="<?= e(qs_page($page - 1)) ?>"
           hx-target="#results" hx-push-url="true" hx-swap="innerHTML"
           class="border border-line px-3 py-1 text-ink-muted transition-colors hover:border-gov hover:text-gov">
          ← Prev
        </a>
      <?php endif; ?>

      <?php for ($i = max(1, $page - 3); $i <= min($num_pages, $page + 3); $i++): ?>
        <?php if ($i === $page): ?>
          <span aria-current="page" class="bg-gov px-3 py-1 font-mono text-sm text-on-gov"><?= $i ?></span>
        <?php else: ?>
          <a href="<?= e(qs_page($i)) ?>" aria-label="Pagina <?= $i ?>"
             hx-get="<?= e(qs_page($i)) ?>"
             hx-target="#results" hx-push-url="true" hx-swap="innerHTML"
             class="border border-line px-3 py-1 font-mono text-xs text-ink-muted transition-colors hover:border-gov hover:text-gov">
            <?= $i ?>
          </a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($page < $num_pages): ?>
        <a href="<?= e(qs_page($page + 1)) ?>" rel="next" aria-label="Pagina următoare"
           hx-get="<?= e(qs_page($page + 1)) ?>"
           hx-target="#results" hx-push-url="true" hx-swap="innerHTML"
           class="border border-line px-3 py-1 text-ink-muted transition-colors hover:border-gov hover:text-gov">
          Următor →
        </a>
      <?php endif; ?>
    </div>
  </nav>
  <?php endif; ?>

<?php else: ?>
  <div class="py-12 text-center">
    <div class="mb-3 font-display text-4xl italic text-ink-muted">—</div>
    <p class="text-sm text-ink-muted">Niciun anunț nu corespunde filtrelor selectate.</p>

    <?php if (!empty($relaxations)): ?>
      <p class="mt-6 text-xs uppercase tracking-widest text-ink-muted">Încearcă fără</p>
      <div class="mt-2 flex flex-wrap justify-center gap-2">
        <?php foreach ($relaxations as $r): ?>
          <a href="<?= e($r['href']) ?>"
             data-chip-param="<?= e($r['param']) ?>"
             data-chip-value="<?= e($r['value']) ?>"
             class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1.5 text-xs text-ink transition-colors hover:border-gov hover:text-gov">
            <?php if ($r['group'] !== ''): ?><span class="opacity-70"><?= e($r['group']) ?>:</span><?php endif; ?>
            <span class="font-medium"><?= e($r['label']) ?></span>
            <span class="font-mono text-ink-muted">+<?= $r['gain'] ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($chips): ?>
      <a href="/" class="mt-6 inline-block text-sm text-gov hover:underline">Șterge toate filtrele</a>
    <?php endif; ?>
  </div>
<?php endif; ?>
