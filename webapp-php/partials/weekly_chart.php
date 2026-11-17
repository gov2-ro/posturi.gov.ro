<?php
/**
 * The weekly chart: the posts published in each of the last 12 complete ISO weeks (posts_sql() in helpers.php), as 12 thin bars.
 * It draws nothing when fewer than 4 weeks have postings.
 *
 * With `$wc['series']` (the occupation groups of a themed page) the chart has a row of buttons, "Toate" and one for each group, and
 * static/theme-panel.js draws the pressed button in the same chart. "Toate" is a stacked bar: one segment for each group, from the
 * bottom up, then "Alte ocupații" (the total minus the groups). A group button shows the bars of that group. The server draws "Toate";
 * the series are in the JSON block #theme-panel-data (partials/theme_stats.php). `caption_hidden`: the heading is for assistive
 * technology only. Segments touch: no gap and no border (owner, 2026-11-18). The chart shows no sentence about the estimate of the posts (owner); the rule is in posts_sql(). Without script the buttons stay hidden (`data-chart-js hidden`) and the chart shows "Toate".
 * The geometry is the one of static/theme-panel.js.
 *
 * In: $wc = ['weekly' => result of home_weekly_postings(), 'caption' => string, 'series' => ?list<array{name,values}>, 'caption_hidden' => optional bool]
 *     $fmt = Closure(int): string, the number format
 */
declare(strict_types=1);

$_wc_weekly = $wc['weekly'];
$_wc_with_data = count(array_filter($_wc_weekly, fn($w) => $w['n'] > 0));
if ($_wc_with_data >= 4):
    $_wc_vals = array_column($_wc_weekly, 'n');
    [$_wc_min, $_wc_max] = [min($_wc_vals), max($_wc_vals)];
    // The vertical axis, from 0 to the largest week. With buttons (a themed page): one axis for every button, the largest week of the
    // total and of each series, so that two buttons compare by height. static/theme-panel.js uses the same axis.
    $_wc_hi = $_wc_max;
    if (isset($wc['series'])) {
        foreach ($wc['series'] as $_wc_s) $_wc_hi = max($_wc_hi, ...array_map('intval', $_wc_s['values'] ?: [0]));
    }
    // The geometry (the same in static/theme-panel.js): 12 slots in a 320 x 64 box; a bar is half of its slot; the baseline is at
    // y = 63 and the top of the axis at y = 3, so the tallest bar is 60 high.
    $_wc_w = 320; $_wc_base = 63; $_wc_plot = 60;
    $_wc_series = $wc['series'] ?? [];
    $_wc_js = isset($wc['series']) ? ' data-chart' : '';   // with script data (a themed page), even with no buttons
    $_wc_n = count($_wc_weekly);
    $_wc_slot = $_wc_w / $_wc_n;
    $_wc_scale = $_wc_hi > 0 ? $_wc_plot / $_wc_hi : 0;
    $_wc_last = end($_wc_vals);
    // The colour class of group i: chart-2, chart-1, chart-3, chart-4. Written out, so that Tailwind (which reads the PHP files, not
    // static/theme-panel.js) keeps the classes.
    $_wc_cls = ['pg-c-1', 'pg-c-0', 'pg-c-2', 'pg-c-3'];
?>
        <figure class="flex flex-col gap-2 border-t border-line pt-4"<?= $_wc_js ?>>
          <figcaption class="<?= !empty($wc['caption_hidden']) ? 'sr-only' : 'g2-label' ?>"><?= e($wc['caption']) ?></figcaption>
          <?php if ($_wc_series): ?>
          <?php /* The DS chip as a toggle button (COMPONENTS.md, Chips): `aria-pressed="true"` marks the pressed one. */ ?>
          <?php /* Text buttons, no border and no background: the DS has no such toggle yet (DS proposal pending; `.pg-chart-btn` in assets/app.css). */ ?>
          <div data-chart-js hidden role="group" aria-label="Ocupație" class="flex flex-wrap gap-x-3 gap-y-1">
            <button type="button" class="pg-chart-btn" data-chart-series="-1" aria-pressed="true">Toate</button>
            <?php foreach ($_wc_series as $_wc_i => $_wc_s): ?>
            <button type="button" class="pg-chart-btn" data-chart-series="<?= (int)$_wc_i ?>" aria-pressed="false"><span class="pg-chart-sq <?= $_wc_cls[$_wc_i] ?? 'pg-c-3' ?>" aria-hidden="true"></span><?= e($_wc_s['name']) ?></button>
            <?php endforeach; ?>
            <?php /* A legend entry, not a button: the rest of the stack of "Toate". */ ?>
            <span data-chart-other class="pg-chart-legend"><span class="pg-chart-sq pg-c-other" aria-hidden="true"></span>Alte ocupații</span>
          </div>
          <?php endif; ?>
          <svg role="img" aria-label="<?= e($wc['caption']) ?>, ultimele 12 săptămâni complete: între <?= e($fmt($_wc_min)) ?> și <?= e(posturi_label($_wc_max)) ?>"
               viewBox="0 0 320 64" preserveAspectRatio="none" class="block h-16 w-full overflow-visible"<?= $_wc_js ? ' data-chart-svg' : '' ?>>
            <line x1="0" x2="320" y1="63.5" y2="63.5" class="pg-baseline" vector-effect="non-scaling-stroke"></line>
            <g<?= $_wc_js ? ' data-chart-bars' : '' ?>>
<?php foreach ($_wc_weekly as $_wc_i => $_wc_wk):
    $_wc_x = round($_wc_i * $_wc_slot + $_wc_slot / 4, 2);
    $_wc_bw = round($_wc_slot / 2, 2);
    $_wc_wl = 'săpt. ' . home_short_date($_wc_wk['start']);
    // The segments of the week, bottom up: [class, name, value]. A single series is one segment with no name.
    $_wc_segs = [];
    if ($_wc_series) {
        $_wc_rest = (int)$_wc_wk['n'];
        foreach ($_wc_series as $_wc_g => $_wc_sg) {
            $_wc_v = (int)($_wc_sg['values'][$_wc_i] ?? 0);
            $_wc_rest -= $_wc_v;
            $_wc_segs[] = ['pg-seg ' . ($_wc_cls[$_wc_g] ?? 'pg-c-3'), (string)$_wc_sg['name'], $_wc_v];
        }
        $_wc_segs[] = ['pg-seg pg-c-other', 'Alte ocupații', $_wc_rest];
    } else {
        $_wc_segs[] = ['pg-seg', '', (int)$_wc_wk['n']];
    }
    $_wc_segs = array_values(array_filter($_wc_segs, fn($sg) => $sg[2] > 0));
    $_wc_cum = 0;
?>
              <g data-bar>
<?php foreach ($_wc_segs as $_wc_k => $_wc_sg):
    $_wc_hgt = $_wc_sg[2] * $_wc_scale;
    $_wc_y = $_wc_base - ($_wc_cum + $_wc_sg[2]) * $_wc_scale;
    $_wc_cum += $_wc_sg[2];
    $_wc_t = $_wc_wl . ($_wc_sg[1] !== '' ? ', ' . $_wc_sg[1] : '') . ': ' . posturi_label($_wc_sg[2]);
?>
                <rect x="<?= $_wc_x ?>" y="<?= round($_wc_y, 2) ?>" width="<?= $_wc_bw ?>" height="<?= round($_wc_hgt, 2) ?>" class="<?= $_wc_sg[0] ?>"><title><?= e($_wc_t) ?></title></rect>
<?php endforeach; ?>
              </g>
<?php endforeach; ?>
            </g>
          </svg>
          <div class="g2-text-meta flex flex-wrap justify-between gap-x-3">
            <span><?= e(home_short_date($_wc_weekly[0]['start'])) ?></span>
            <span<?= $_wc_js ? ' data-chart-stats' : '' ?>>min <?= e($fmt($_wc_min)) ?> · max <?= e($fmt($_wc_max)) ?> · ultima <?= e($fmt($_wc_last)) ?></span>
            <span><?= e(home_short_date(end($_wc_weekly)['start'])) ?></span>
          </div>
          <?php if ($_wc_series): ?>
          <p data-chart-live role="status" aria-live="polite" class="sr-only"></p>
          <?php endif; ?>
        </figure>
<?php endif;
