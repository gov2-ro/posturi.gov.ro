<?php
/**
 * The salary chart (2026-11-18, brief UX-SALARY-CHART): the active postings by range of net monthly pay, as horizontal bars in
 * HTML and CSS (the labels are long, and the page must work at 320 px). One row for each range of SALARY_NET_BUCKETS, lowest at the
 * top, then a separate row "fără salariu estimat" in a neutral colour: it is not a pay level, it shows how much of the picture
 * is missing. A row is one link to the list with that range (and the base filter of the page); its count is the result count of
 * the link (salary_distribution() in helpers.php). The bar is `aria-hidden`: the link text is the label and the count.
 * The longest bar (the "fără" row included) fills the track (the row minus the count); a count above 0 has a bar of at least 2 px.
 * It draws nothing when no posting of the scope has a salary figure. Compact rows (owner, 2026-11-18): a row is at least 32 px
 * high, so each link target is at least 24 px and the targets do not overlap (as the rows of "Sau începe de aici").
 * 2026-11-18 (owner): the rows are at most 28rem wide, as the weekly bars (partials/weekly_chart.php); the labels are text-sm; a bar
 * is 24 px high in its 32 px row, so the bars are three times as high as the gaps between them. Below sm the label is above
 * the bar: the bar is 16 px high and the row has 4 px of padding.
 *
 * With `$sc['js']` (a themed page) the rows carry `data-salary-*` and static/theme-panel.js updates the bars, the counts and the
 * links on the county selection, from the JSON block #theme-panel-data. Without script the chart shows the whole country.
 *
 * In: $sc = ['dist' => result of salary_distribution(), 'js' => optional bool, 'id' => optional string, 'class' => optional classes of the figure]
 *     $fmt = Closure(int): string, the number format
 */
declare(strict_types=1);

$_sc_dist = $sc['dist'];
if ($_sc_dist['total'] > 0):
    $_sc_max = max(1, ...array_column($_sc_dist['rows'], 'n'));
    $_sc_js = !empty($sc['js']);
    // "397 anunțuri", "1 anunț", "25 de anunțuri" (static/theme-panel.js writes the same).
    $_sc_unit = static function (int $n): string {
        $mod = $n % 100;
        return $n === 1 ? 'anunț' : (($n > 0 && ($mod === 0 || $mod >= 20)) ? 'de anunțuri' : 'anunțuri');
    };
?>
<figure<?= !empty($sc['id']) ? ' id="' . e($sc['id']) . '"' : '' ?> class="<?= e($sc['class'] ?? 'flex flex-col gap-2') ?>"<?= $_sc_js ? ' data-salary-chart' : '' ?>>
  <figcaption class="g2-label">Salariul net lunar</figcaption>
  <ul class="flex max-w-md flex-col">
<?php foreach ($_sc_dist['rows'] as $_sc_r):
    $_sc_f = round($_sc_r['n'] / $_sc_max, 4);
?>
    <li<?= $_sc_r['none'] ? ' class="mt-1.5 border-t border-line pt-1.5"' : '' ?><?= $_sc_js ? ' data-salary-row="' . e($_sc_r['key']) . '"' : '' ?>>
      <a href="<?= e($_sc_r['href']) ?>" class="grid min-h-8 grid-cols-1 content-center gap-x-3 gap-y-0.5 py-1 text-sm sm:py-0 text-ink no-underline hover:text-gov sm:grid-cols-[9.5rem_minmax(0,1fr)] sm:items-center">
        <span><?= e($_sc_r['label']) ?></span>
        <?php /* The track is the row of the bar and the count: the bar is a fraction of the width left by the count (4 rem: w-14 and gap-2), so the count follows the end of the bar. */ ?>
        <span class="flex h-4 min-w-0 items-center gap-2 sm:h-6">
          <span<?= $_sc_js ? ' data-salary-bar' : '' ?> aria-hidden="true" class="block h-4 shrink-0 sm:h-6 <?= $_sc_r['none'] ? 'bg-ink-faint' : 'bg-gov' ?>" style="width:calc((100% - 4rem) * <?= $_sc_f ?>)<?= $_sc_r['n'] > 0 ? ';min-width:2px' : '' ?>"></span>
          <span class="g2-mono w-14 shrink-0"><span<?= $_sc_js ? ' data-salary-count' : '' ?>><?= e($fmt($_sc_r['n'])) ?></span><span class="sr-only"<?= $_sc_js ? ' data-salary-unit' : '' ?>> <?= $_sc_unit($_sc_r['n']) ?></span></span>
        </span>
      </a>
    </li>
<?php endforeach; ?>
  </ul>
  <p class="g2-text-meta">Salariul anunțat sau, pentru cele mai multe posturi, cel estimat după legea în vigoare (Legea 153/2017): de la gradația minimă cerută, cu indemnizația de hrană unde legea o dă.</p>
</figure>
<?php endif;
