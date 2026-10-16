<?php
// Variables expected from parent scope:
// $postings, $total_count, $page, $num_pages, $PAGE_SIZE, $q, $is_htmx
// $active_chips, $relaxations
$start = ($page - 1) * $PAGE_SIZE + 1;
$end   = min($page * $PAGE_SIZE, $total_count);
$chips = $active_chips ?? [];
?>

<?php if (!empty($is_htmx)): ?>
  <!-- Out-of-band updates for the chrome that lives outside #results -->
  <p id="results-status" role="status" aria-live="polite" aria-atomic="true" class="sr-only" hx-swap-oob="true"><?= $total_count ?> <?= $total_count === 1 ? 'anunț găsit' : 'anunțuri găsite' ?></p>
  <span id="filter-count" hx-swap-oob="true"
        class="<?= $chips ? '' : 'hidden ' ?>inline-flex min-w-[1.25rem] justify-center rounded-full bg-gov px-1.5 py-0.5 text-xs font-mono text-on-gov"><?= count($chips) ?></span>
  <span id="drawer-count" hx-swap-oob="true"><?= $total_count ?></span>
<?php endif; ?>

<!-- Top bar: count + pagination info -->
<div class="mb-3 flex items-center justify-between border-b border-line pb-2">
  <div class="text-sm text-ink-muted">
    <?php if ($total_count): ?>
      <span class="font-mono font-medium text-ink"><?= $total_count ?></span>
      <?= $total_count === 1 ? 'anunț găsit' : 'anunțuri găsite' ?>
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

<!-- Active filter chips — one per value, each removing only itself.
     The connectives between them are the only place the filter's logic is
     stated: values inside one group are alternatives ("sau"), groups narrow
     each other ("și"). active_filter_chips() walks FILTER_CHIP_GROUPS in order,
     so chips sharing a param are already adjacent and a look-back is enough. -->
<?php if ($chips): ?>
<div class="mb-3 flex flex-wrap items-center gap-1.5">
  <?php $prev_param = null; foreach ($chips as $chip): ?>
    <?php if ($prev_param !== null):
        $same = $prev_param === $chip['param'];
        // Within a group the word tracks that group's actual mode, so the
        // Competențe any/all switch is legible from the chips too.
        $word = ($same && facet_mode($chip['param']) !== 'all') ? 'sau' : 'și'; ?>
      <span class="px-0.5 text-[11px] uppercase tracking-wide text-ink-muted <?= $same ? '' : 'font-semibold' ?>"><?= $word ?></span>
    <?php endif; $prev_param = $chip['param']; ?>
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
  <?php /* UX-09: filled by static/saved.js from browser-local state. The server
     result and facet counts never change with it; this only says so. */ ?>
  <div data-pref-banner hidden class="mb-3 border border-info-line bg-info px-3 py-2 text-xs leading-snug text-info-ink">
    <p data-pref-banner-page hidden><span data-pref-banner-count></span> pe această pagină.
      <button type="button" data-pref-toggle-hidden aria-pressed="false"
              class="ml-1 min-h-[2rem] px-1 font-medium underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus"><span data-pref-toggle-label>Arată</span></button></p>
    <p data-pref-banner-all hidden class="font-medium">Toate anunțurile de pe această pagină sunt ascunse în acest browser. Paginarea rămâne disponibilă, iar rezultatele căutării de pe server nu s-au schimbat.</p>
    <p>Numărul rezultatelor include anunțurile ascunse în acest browser.</p>
  </div>
  <?php /* Column heads for the table view (lg+, html[data-layout="table"]); hidden in
     the list and grid views. Visual only: every row still carries its own labels. */ ?>
  <div class="pg-thead" aria-hidden="true">
    <span>Post</span><span>Angajator</span><span>Localitate</span><span>Salariu</span><span>Termen</span>
  </div>
  <ul class="pg-results divide-y divide-line border-t border-line">
    <?php foreach ($postings as $p):
        $inferred = json_decode($p['inferred'] ?? '{}', true) ?: [];
        // Countdown and printed date come from ONE value — this is the fix for
        // rows that showed "13 zile" beside a different date.
        $dl   = posting_deadline($p);
        $days = days_until($dl['date']);
        $est  = $dl['source'] === 'expirare';
    ?>
    <?php /* 261016: the whole row is the link (.pg-link's ::after covers the row);
       the employer link and the save/hide buttons sit above it (assets/app.css). */ ?>
    <li class="pg-row group relative data-[pref-hidden=true]:opacity-60"<?= pref_attrs($p) ?> data-pref-hideable>
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
          <?php $dt = display_title($p); ?>
          <?php /* Title and the two icon buttons share one line (the buttons wrap
             with the title's last word). The <h2> is inline so they can; the
             buttons stay outside it and outside the link. */ ?>
          <div class="pg-title leading-snug">
            <h2 class="font-display inline text-lg font-semibold italic text-ink"><a href="<?= e(job_url($p)) ?>" class="pg-link transition-colors group-hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus"><?= e($dt['primary']) ?></a></h2><?= pref_controls($p) ?>
          </div>
          <?php if ($dt['secondary']): ?>
            <p class="pg-sub truncate text-xs not-italic text-ink-faint"><?= e($dt['secondary']) ?></p>
          <?php endif; ?>
          <div class="pg-meta mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-muted">
            <a href="/angajator/<?= e($p['employer_slug_display'] ?? $p['employer_slug'] ?? '') ?>/"
               class="pg-employer max-w-xs truncate py-1 font-medium text-ink-muted hover:text-gov hover:underline">
              <?= e($p['employer_name']) ?>
            </a>
            <?php if ($place = place_label($p)): ?>
              <span aria-hidden="true" class="text-ink-muted">·</span>
              <span class="pg-place"><?= e($place) ?></span>
            <?php endif; ?>
          </div>

          <?php /* One line of facts, no boxes (261016): pay first — 💰, the amount and,
             for the draft-grid estimate, "(estimat)" (list_salary(): the stated
             salary wins) — then the source's own labels, then the inferred ones
             behind the "auto" marker. In the table view the line dissolves
             (display: contents): pay becomes the Salariu column, the rest hides. */ ?>
          <?php $sal = list_salary($p); $meta = inferred_meta($p);
                $labels = array_values(array_filter([
                    $p['job_level'] ? job_level_label((string)$p['job_level']) : '',
                    (string)($p['job_type'] ?? ''),
                    (string)($p['categorie'] ?? ''),
                    ($p['nr_posturi'] ?? 0) > 1 ? $p['nr_posturi'] . ' posturi' : '',
                ], fn($v) => $v !== '')); ?>
          <?php if ($sal || $labels || $meta): ?>
          <p class="pg-facts mt-1 text-xs leading-relaxed text-ink-muted">
            <?php if ($sal): ?>
              <span class="pg-salary whitespace-nowrap" title="<?= e($sal['label']) ?> (brut lunar)<?= ($n = list_salary_net($p)) !== '' ? ' · ' . e($n) . ', estimat' : '' ?>">
                <span class="pg-salary-label" role="img" aria-label="<?= e($sal['label']) ?>">💰</span>
                <span class="pg-salary-value font-medium text-ink"><?= e($sal['text']) ?></span><?php if ($sal['estimated']): ?>
                <span class="pg-salary-kind">(estimat)</span><?php endif; ?>
              </span>
            <?php endif; ?>
            <?php if ($labels): ?>
              <span class="pg-badges"><?php foreach ($labels as $i => $l): ?><?php if ($i || $sal): ?><span aria-hidden="true" class="pg-sep"> · </span><?php endif; ?><span><?= e($l) ?></span><?php endforeach; ?></span>
            <?php endif; ?>
            <?php if ($meta): ?>
              <span class="pg-auto"><?php if ($sal || $labels): ?><span aria-hidden="true" class="pg-sep"> · </span><?php endif; ?><abbr title="Atribute deduse automat din textul anunțului — pot fi incomplete sau greșite. Vezi metodologia."
                  class="cursor-help font-mono text-[10px] uppercase tracking-wider no-underline decoration-dotted underline-offset-2 [text-decoration:underline]">auto</abbr>
                <?php foreach ($meta as $k => $item): ?><?php if ($k): ?><span aria-hidden="true" class="pg-sep"> · </span><?php endif; ?><span><?= e($item) ?></span><?php endforeach; ?></span>
            <?php endif; ?>
          </p>
          <?php endif; ?>
        </div>

        <?php /* Deadline badge */ ?>
        <div class="pg-deadline sm:min-w-[80px] sm:max-w-[9.5rem] shrink-0 sm:text-right">
          <?php if ($days !== null): ?>
            <?php if ($days < 0): ?>
              <span class="font-mono text-xs text-ink-muted"><?= $est ? 'Termen estimat depășit' : 'Înscrieri închise' ?></span>
            <?php elseif ($days === 0): ?>
              <span class="font-mono text-xs font-semibold text-alert-ink"><?= $est ? 'Azi (estimat)' : 'Azi!' ?></span>
            <?php elseif ($days <= 3): ?>
              <span class="font-mono text-xs font-semibold text-alert-ink"><?= $est ? '≈ ' : '' ?><?= e(days_label($days)) ?></span>
            <?php elseif ($days <= 7): ?>
              <span class="font-mono text-xs font-medium text-note-ink"><?= $est ? '≈ ' : '' ?><?= e(days_label($days)) ?></span>
            <?php else: ?>
              <span class="font-mono text-xs text-ink-muted"><?= $est ? '≈ ' : '' ?><?= e(days_label($days)) ?></span>
            <?php endif; ?>
            <time datetime="<?= e($dl['date']) ?>" class="mt-0.5 block font-mono text-xs text-ink-muted"><?= fmt_date($dl['date']) ?></time>
            <?php if ($est): ?>
              <!-- Visible, not tooltip-only: this date is the announcement
                   expiry, not a confirmed application deadline. -->
              <span class="deadline-fallback mt-0.5 block text-[11px] italic leading-snug text-ink-muted" title="Data expirării anunțului; termenul de înscriere nu este confirmat"><?= e(DEADLINE_FALLBACK_NOTE) ?></span>
            <?php endif; ?>
          <?php else: ?>
            <span class="font-mono text-xs text-ink-muted">Termen neprecizat</span>
          <?php endif; ?>
        </div>
      </div>
      <?= pref_flag() ?>
    </li>
    <?php endforeach; ?>
  </ul>

  <!-- Pagination -->
  <?php if ($num_pages > 1): ?>
  <nav class="pg-pagination mt-6 flex flex-wrap items-center justify-between gap-3 text-sm" aria-label="Paginare rezultate">
    <div class="font-mono text-xs text-ink-muted"><?= $start ?>–<?= $end ?> din <?= $total_count ?></div>
    <div class="flex flex-wrap items-center gap-1">
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


    <?php if ($home_new || $home_soon): ?>
    <?php /* D15: two short lists on the unfiltered first page — below the results
       since 261016 ("people come for something specific"). Each title links to the
       posting; the heading links to the full list behind it. */ ?>
    <div class="pg-home mt-8 grid grid-cols-[minmax(0,1fr)] gap-x-8 gap-y-4 border-y border-line py-4 md:grid-cols-2">
      <?php
        $new_word = $home_new_day === ro_today() ? 'azi' : ($home_new_day !== '' ? fmt_date($home_new_day) : '');
        foreach ([
          ['Noi ' . $new_word, '/?published=' . rawurlencode($home_new_day), $home_new, false],
          ['Se închid în 3 zile', '/?status=soon&sort=deadline', $home_soon, true],
        ] as [$htitle, $hhref, $hrows, $show_deadline]):
          if (!$hrows) continue; ?>
      <section class="min-w-0">
        <h2 class="mb-1.5 text-xs font-semibold uppercase tracking-widest text-ink-muted">
          <a href="<?= e($hhref) ?>" class="hover:text-gov"><?= e($htitle) ?> <span aria-hidden="true">→</span></a>
        </h2>
        <ul class="space-y-1 text-sm">
          <?php foreach ($hrows as $hp):
              $hdt = display_title($hp); $hdl = posting_deadline($hp); ?>
          <li class="flex items-baseline justify-between gap-3">
            <span class="min-w-0 truncate"><a href="<?= e(job_url($hp)) ?>" class="font-medium text-ink hover:text-gov"><?= e($hdt['primary']) ?></a>
              <span class="text-xs text-ink-muted">· <?= e($hp['employer_name']) ?></span></span>
            <?php if ($show_deadline && $hdl['date']): ?>
              <span class="shrink-0 font-mono text-xs text-alert-ink"><?php $hd = (int)days_until($hdl['date']); ?><?= e($hd === 0 ? 'azi' : days_label($hd)) ?></span>
            <?php elseif ($place = place_label($hp)): ?>
              <span class="max-w-[40%] shrink-0 truncate text-xs text-ink-muted"><?= e($place) ?></span>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endforeach; ?>
    </div>
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
