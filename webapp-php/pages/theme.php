<?php
declare(strict_types=1);

/**
 * A themed landing page, `/{slug}/` (inc/themes.php). One template for every theme: it reads the
 * configuration in $theme and knows no theme by name. Each link is the base filter plus one more
 * filter, and each count is the result count of its link (home_item(), home_item_counts()). The
 * server output is the same for every reader, so the edge can cache it.
 *
 * In: $theme = the configuration of the theme, with `slug` (theme_for_uri()).
 */

$today = ro_today();
$fmt = static fn(int $n): string => number_format($n, 0, ',', '.');
$base = $theme['base'];

// ---- The base filter, and the counties inside it ----
$all = home_item('all', $theme['all_label'], $base);
$county_total = $all['counties'];
$names = judet_names();
$county_name = static fn(string $slug): string => $names[$slug] ?? ucwords(str_replace('-', ' ', $slug));
$county_options = judet_names_sorted();   // for the select of the search form

/** The `WHERE` of the postings inside the base filter (default status), for the queries that rank values. */
$bf = build_filters($base + ['status' => DEFAULT_STATUS]);
$base_where = $bf['where'] ? implode(' AND ', $bf['where']) : '1';

// ---- Items: each is a link with its count; items of count 0 are not offered ----
$items = ['all' => $all];          // id => item, for the JSON block
$sections = [];                    // the sections to render, in configuration order
foreach ($theme['sections'] as $sec) {
    if ($sec['type'] !== 'values') { $sections[] = $sec; continue; }

    $param = $sec['param'];
    $col = preg_match('/^[a-z0-9_]+$/', (string)($sec['column'] ?? '')) ? $sec['column'] : '';
    $limit = (int)($sec['limit'] ?? 0);
    $candidates = [];              // value => label, the values to try, in the order of their ranking
    $has_more = false;
    if ($sec['source'] === 'labels') {
        foreach ($sec['labels'] as $v => $label) $candidates[(string)$v] = (string)$label;
    } elseif ($sec['source'] === 'json' && $col !== '') {
        $st = db()->prepare("SELECT j.$col AS vals FROM job_postings j WHERE $base_where AND j.$col NOT IN ('', '[]')");
        $st->execute($bf['binds']);
        $tally = [];
        foreach ($st->fetchAll() as $r) {
            foreach ((array)(json_decode((string)$r['vals'], true) ?: []) as $v) {
                if ($v !== '' && $v !== null) $tally[(string)$v] = ($tally[(string)$v] ?? 0) + 1;
            }
        }
        uksort($tally, fn($a, $b) => $tally[$b] <=> $tally[$a] ?: strcmp((string)$a, (string)$b));
        $has_more = $limit > 0 && count($tally) > $limit;
        foreach (array_slice($tally, 0, $limit > 0 ? $limit : null, true) as $v => $n) $candidates[(string)$v] = (string)$v;
    } elseif ($sec['source'] === 'column' && $col !== '') {
        $st = db()->prepare("SELECT j.$col AS v FROM job_postings j WHERE $base_where AND j.$col <> '' GROUP BY 1 ORDER BY COUNT(*) DESC, 1" . ($limit > 0 ? ' LIMIT ' . $limit : ''));
        $st->execute($bf['binds']);
        foreach ($st->fetchAll() as $r) $candidates[(string)$r['v']] = (string)$r['v'];
    }
    $row = [];
    foreach ($candidates as $v => $label) {
        $it = home_item($param . ':' . $v, $label, home_merge_params($base, [$param => [$v]]));
        if ($it['n'] > 0) $row[] = $it;
    }
    // `labels` keeps its own order, unless the section says `order => 'count'` (then `limit` keeps the first items after the sort).
    $by_count = ($sec['order'] ?? '') === 'count';
    if ($sec['source'] !== 'labels' || $by_count) usort($row, fn($a, $b) => $b['n'] <=> $a['n']);
    if ($sec['source'] === 'labels' && $by_count && $limit > 0) $row = array_slice($row, 0, $limit);
    foreach ($row as $it) $items[$it['id']] = $it;
    $sec['items'] = $row;
    if ($has_more && !empty($sec['more'])) {
        $sec['more_item'] = ['id' => 'more:' . $param, 'label' => $sec['more'], 'href' => $all['href'], 'n' => null, 'icon' => null];
        $items['more:' . $param] = ['params' => $base, 'counties' => []];
    }
    if (!empty($theme['icon_file'])) {
        // Icons (ADR 0007): an item with none of them has no icon, but its text lines up with the others.
        $sec['has_icons'] = false;
        foreach ($row as $i => $it) {
            $row[$i]['icon'] = theme_icon_for($sec, (string)preg_replace('/^[^:]*:/', '', $it['id']));
            if ($row[$i]['icon'] !== null) $sec['has_icons'] = true;
        }
        $sec['items'] = $row;
    }
    if ($row) $sections[] = $sec;
}
$other = null;
if (!empty($theme['other'])) {
    $o = home_item('other', $theme['other']['label'], $theme['other']['params']);
    if ($o['n'] > 0) { $other = $o + ['note' => $theme['other']['note'] ?? '']; $items['other'] = $o; }
}

// ---- The statistics panel (a `stats` section, with the theme key `history`): the weekly chart and the data of the county selection ----
$ts = null;
$hist = $theme['history']['family'] ?? null;   // one family (string) or a list of families
foreach ($theme['sections'] as $sec) if ($sec['type'] === 'stats' && ((is_string($hist) && $hist !== '') || (is_array($hist) && $hist))) {
    $groups = $theme['history']['groups'] ?? [];
    $gs = theme_group_series($hist, $groups, 12);
    $weekly = [];
    foreach (home_week_starts(12) as $i => $start) $weekly[] = ['start' => $start, 'n' => $gs['total'][$i]];
    $series = [];
    foreach ($groups as $i => $g) $series[] = ['name' => $g['label'], 'values' => $gs['groups'][$i]];
    $ts = ['weekly' => $weekly, 'series' => $series];
    // For each county the weekly total and the weekly series of each group (the same sources as the chart); the page is the whole country.
    $ts['data'] = ['caption' => 'Posturi publicate pe săptămână', 'weeks' => array_map(fn($w) => home_short_date($w['start']), $weekly), 'total' => $gs['total'], 'series' => $series, 'counties' => $gs['counties']];
}

$ns = ['base' => $base] + home_new_soon($base, $today);
timing_mark('theme');

// ---- Page ----
$page_title       = $theme['title'];
$meta_description = $theme['description'];
$canonical_path   = '/' . $theme['slug'] . '/';
$head_extra       = '<script src="/static/home.js?v=' . (@filemtime(__DIR__ . '/../static/home.js') ?: '1') . '" defer></script>'
                  . ($ts !== null ? '<script src="/static/theme-panel.js?v=' . (@filemtime(__DIR__ . '/../static/theme-panel.js') ?: '1') . '" defer></script>' : '');
require __DIR__ . '/../inc/header.php';

// The selects of the search form (theme key `search_selects`): every value with a posting inside the theme.
$hs_selects = [];
foreach ($theme['search_selects'] ?? [] as $i => $sel) {
    $vals = theme_select_values($sel, $base_where, $bf['binds']);
    $sel_labels = (array)($sel['labels'] ?? []);
    // With `labels` the options sort by the text that the reader sees.
    if ($sel_labels) usort($vals, fn($a, $b) => strcmp(ro_sort_key((string)($sel_labels[$a] ?? $a)), ro_sort_key((string)($sel_labels[$b] ?? $b))) ?: strcmp($a, $b));
    $hs_selects[] = ['id' => 'home-sel-' . $i, 'name' => param_field((string)$sel['param']), 'label' => $sel['label'], 'all' => $sel['all'],
                     'values' => $vals, 'labels' => $sel_labels];
}

$home_data = ['total' => $all['n'], 'counties' => $county_total, 'items' => []];
foreach ($items as $id => $it) $home_data['items'][$id] = ['params' => home_item_fields($it['params']), 'counties' => $it['counties']];
$heading = 'g2-heading-section';
?>
<div class="g2-container flex flex-col gap-12 pb-14">

  <?php /* The first screen: left the title, the search and the link to all; right the county map when the theme has a `counties` section. */
  $has_map = false;
  foreach ($sections as $_s) if ($_s['type'] === 'counties') $has_map = true; ?>
  <section aria-labelledby="h-theme" class="pt-8">
    <div class="grid gap-8 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:items-start lg:gap-10">
      <div class="flex min-w-0 flex-col gap-4">
<?php /* The title and, with a map, the button "Toate județele": on one line from sm, below the title on a narrow screen. The button is
           not in the heading. Script only: static/home.js shows it while a county is selected and clears the county on a click. */ ?>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <h1 id="h-theme" class="g2-heading-page mb-1 flex items-center gap-3 text-xl leading-tight sm:text-2xl"><?php if (!empty($theme['icon'])): ?><?= icon($theme['icon'], 'h-8 w-8 shrink-0') ?><?php endif; ?><span><?= e($theme['h1']) ?><?php if ($has_map): ?><span data-county-title></span><span data-title-count class="text-base font-normal text-ink-muted">&nbsp;(<?= e($fmt($all['n'])) ?>)</span><?php endif; ?></span></h1>
        <?php if ($has_map): ?><button type="button" class="g2-chip" data-county-clear hidden><?= icon('i-x', 'h-4 w-4 shrink-0 self-center') ?>Toate județele</button><?php endif; ?>
        </div>
        <?php $hs = ['placeholder' => $theme['placeholder'], 'counts' => $county_total, 'hidden' => home_item_fields($base), 'selects' => $hs_selects];
              require __DIR__ . '/../partials/home_search.php'; ?>
        <?php if (!$has_map && $all['n'] > 0): /* A theme with a map has the link to all after the line "other" (below), and the count in the title. */ ?>
        <ul>
          <?php $ci = ['id' => 'all', 'label' => $all['label'], 'href' => $all['href'], 'n' => $all['n'], 'icon' => null];
                require __DIR__ . '/../partials/count_item.php'; ?>
        </ul>
        <?php endif; ?>
        <?php if ($ts !== null) require __DIR__ . '/../partials/theme_stats.php'; ?>
      </div>
      <?php if ($has_map): ?>
      <?php /* Script only: the map and its line need static/home.js, so they ship hidden (data-home-js). */ ?>
      <div class="flex min-w-0 flex-col gap-3">
        <?php $cm_counts = $county_total; $cm_label_hidden = true;
              require __DIR__ . '/../partials/county_map.php'; ?>
        <?php require __DIR__ . '/../partials/home_data.php'; ?>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <?php $other_done = false;
  /* The line "other" (employers of the theme, postings outside it) comes before the section `recent`, or at the end when there is none.
     On a theme with a map the link to all postings is the second column of the same row (from lg; below lg they stack). */
  $render_other = function () use (&$other, &$other_done, &$all, $fmt, $has_map) {
      $link_all = $has_map && $all['n'] > 0;
      if (($other === null && !$link_all) || $other_done) return;
      $other_done = true; ?>
  <div class="<?= $link_all ? 'grid gap-4 lg:grid-cols-2 lg:items-start' : 'flex flex-col gap-1' ?>">
    <?php if ($other !== null): ?>
    <div class="flex min-w-0 flex-col gap-1">
      <ul>
        <?php $ci = ['id' => 'other', 'label' => $other['label'], 'href' => $other['href'], 'n' => $other['n'], 'icon' => null];
              require __DIR__ . '/../partials/count_item.php'; ?>
      </ul>
      <?php if (!empty($other['note'])): ?><p class="g2-text-meta"><?= e($other['note']) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($link_all): ?>
    <ul class="min-w-0">
      <?php $ci = ['id' => 'all', 'label' => $all['label'], 'href' => $all['href'], 'n' => $all['n'], 'icon' => null];
            require __DIR__ . '/../partials/count_item.php'; ?>
    </ul>
    <?php endif; ?>
  </div>
  <?php };
  foreach ($sections as $sec): ?>
    <?php if ($sec['type'] === 'recent') $render_other(); ?>
    <?php if ($sec['type'] === 'values'): ?>
  <section aria-labelledby="h-<?= e($sec['id']) ?>" class="flex flex-col gap-3">
    <h2 id="h-<?= e($sec['id']) ?>" class="<?= $heading ?>"><?= e($sec['title']) ?></h2>
    <ul class="grid <?= ($sec['columns'] ?? 0) === 3 ? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3' : 'grid-cols-[repeat(auto-fill,minmax(min(100%,15rem),1fr))]' ?> gap-x-4 gap-y-0">
      <?php foreach ($sec['items'] as $it):
          $ci = ['id' => $it['id'], 'label' => $it['label'], 'href' => $it['href'], 'n' => $it['n'], 'icon' => $it['icon'] ?? null,
                 'compact' => true, 'icon_file' => $theme['icon_file'] ?? null, 'icon_gap' => !empty($sec['has_icons'])]
                 + (isset($sec['icon_size']) ? ['icon_size' => $sec['icon_size']] : []);
          require __DIR__ . '/../partials/count_item.php';
      endforeach;
      if (!empty($sec['more_item'])) { $ci = $sec['more_item'] + ['compact' => true, 'icon_gap' => !empty($sec['has_icons'])]
                                                      + (isset($sec['icon_size']) ? ['icon_size' => $sec['icon_size']] : []); require __DIR__ . '/../partials/count_item.php'; } ?>
    </ul>
  </section>
    <?php elseif ($sec['type'] === 'counties' || $sec['type'] === 'stats'): /* rendered in the first screen */ ?>
    <?php elseif ($sec['type'] === 'recent'):
        $ns['id'] = $sec['id'];
        require __DIR__ . '/../partials/new_soon.php';
    elseif ($sec['type'] === 'follow'):
        $fc = ['id' => $sec['id'],
               'calendar' => [feed_url_for('posturi.ics', $base), 'Calendarul acestor posturi'],
               'rss'      => [feed_url_for('posturi.rss', $base), 'Fluxul acestor posturi']];
        require __DIR__ . '/../partials/follow_cards.php';
    endif; ?>
  <?php endforeach; ?>

  <?php $render_other(); ?>

</div>
<?php
require __DIR__ . '/../inc/footer.php';
timing_mark('render');
