<?php
/**
 * The weekly chart: postings published in each of the last 12 complete ISO weeks (a line and its area). It draws
 * nothing when fewer than 4 weeks have postings.
 *
 * With `$wc['series']` (theme_occupation_weeks()) the chart has a row of buttons, "Toate" and one for each occupation, and static/theme-panel.js
 * draws the series of the pressed button in the same chart. The server draws the total; the series are in the JSON block
 * #theme-panel-data (partials/theme_stats.php). `caption_hidden`: the heading is for assistive technology only. Without script the buttons stay hidden (`data-chart-js hidden`) and the chart shows the total.
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
    // The vertical axis. Without buttons: from the smallest to the largest week of the line. With buttons (a themed page): one axis
    // for every button, from 0 to the largest week of the total and of each series, so that two buttons compare by height.
    // static/theme-panel.js uses the same axis.
    [$_wc_lo, $_wc_hi] = [$_wc_min, $_wc_max];
    if (isset($wc['series'])) {
        $_wc_lo = 0;
        foreach ($wc['series'] as $_wc_s) $_wc_hi = max($_wc_hi, ...array_map('intval', $_wc_s['values'] ?: [0]));
    }
    $_wc_w = 320; $_wc_h = 64; $_wc_p = 4;
    $_wc_pts = [];
    foreach ($_wc_vals as $_wc_i => $_wc_v) {
        $_wc_x = $_wc_i / (count($_wc_vals) - 1) * $_wc_w;
        $_wc_y = $_wc_hi === $_wc_lo ? $_wc_h / 2 : $_wc_p + (1 - ($_wc_v - $_wc_lo) / ($_wc_hi - $_wc_lo)) * ($_wc_h - 2 * $_wc_p);
        $_wc_pts[] = round($_wc_x, 1) . ',' . round($_wc_y, 1);
    }
    $_wc_area = "M0,$_wc_h L" . implode(' L', $_wc_pts) . " L$_wc_w,$_wc_h Z";
    $_wc_last = end($_wc_vals);
    $_wc_series = $wc['series'] ?? [];
    $_wc_js = isset($wc['series']) ? ' data-chart' : '';   // with script data (a themed page), even with no buttons
?>
        <figure class="flex flex-col gap-2 border-t border-line pt-4"<?= $_wc_js ?>>
          <figcaption class="<?= !empty($wc['caption_hidden']) ? 'sr-only' : 'g2-label' ?>"><?= e($wc['caption']) ?></figcaption>
          <?php if ($_wc_series): ?>
          <?php /* The DS chip as a toggle button (COMPONENTS.md, Chips): `aria-pressed="true"` marks the pressed one. */ ?>
          <?php /* Text buttons, no border and no background: the DS has no such toggle yet (DS proposal pending; `.pg-chart-btn` in assets/app.css). */ ?>
          <div data-chart-js hidden role="group" aria-label="Ocupație" class="flex flex-wrap gap-x-3 gap-y-1">
            <button type="button" class="pg-chart-btn" data-chart-series="-1" aria-pressed="true">Toate</button>
            <?php foreach ($_wc_series as $_wc_i => $_wc_s): ?>
            <button type="button" class="pg-chart-btn" data-chart-series="<?= (int)$_wc_i ?>" aria-pressed="false"><?= e($_wc_s['name']) ?></button>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <svg role="img" aria-label="<?= e($wc['caption']) ?>, ultimele 12 săptămâni complete: între <?= e($fmt($_wc_min)) ?> și <?= e($fmt($_wc_max)) ?>"
               viewBox="0 0 320 64" preserveAspectRatio="none" class="block h-16 w-full overflow-visible"<?= $_wc_js ? ' data-chart-svg' : '' ?>>
            <path d="<?= e($_wc_area) ?>" class="fill-chart/15 stroke-none"<?= $_wc_js ? ' data-chart-area' : '' ?>></path>
            <polyline<?= $_wc_js ? ' data-chart-line' : '' ?> points="<?= e(implode(' ', $_wc_pts)) ?>" fill="none" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" class="stroke-chart"></polyline>
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
