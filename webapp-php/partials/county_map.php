<?php
/**
 * The county tile map and its legend. Shipped hidden (data-home-js); static/home.js shows it.
 *
 * In:  $cm_counts = array<slug,int> (postings of each county), $county_name = Closure(string): string
 *      optional $cm_label_hidden = true: the label above the map is for assistive technology only (a themed page)
 *      optional $cm_class = string: more classes on the root (the landing page puts the map first below lg)
 */
declare(strict_types=1);
?>
<?php // The steps and the legend follow the counts of this map (home_map_breaks(): a log scale between its smallest and largest count).
$_cm_counts = [];
foreach (HOME_COUNTY_TILES as [$_cm_slug]) $_cm_counts[$_cm_slug] = (int)($cm_counts[$_cm_slug] ?? 0);
$_cm_breaks = home_map_breaks($_cm_counts); ?>
<div data-home-js hidden class="flex min-w-0 flex-col gap-2.5<?= !empty($cm_class) ? ' ' . e($cm_class) : '' ?>">
  <span class="<?= !empty($cm_label_hidden) ? 'sr-only' : 'g2-label' ?>" id="map-label">Posturi active pe județe</span>
  <div role="group" aria-label="Alege județul pe hartă" class="pg-map"<?= !empty($cm_label_hidden) ? ' aria-labelledby="map-label"' : '' ?>>
    <?php foreach (HOME_COUNTY_TILES as $code => [$slug, $col, $row]):
        $n = $cm_counts[$slug] ?? 0;
        $aria = $county_name($slug) . ': ' . posturi_label($n); ?>
    <button type="button" data-county="<?= e($slug) ?>" data-step="<?= home_map_step($n, $_cm_breaks) ?>" aria-pressed="false"
            aria-label="<?= e($aria) ?>" title="<?= e($aria) ?>" style="grid-column: <?= $col ?>; grid-row: <?= $row ?>"
            class="pg-map-cell"><?= e($code) ?></button>
    <?php endforeach; ?>
  </div>
  <ul class="g2-text-meta flex flex-wrap items-center gap-x-3 gap-y-1">
    <?php foreach (home_map_legend($_cm_counts) as $_cm_l): ?>
    <li class="inline-flex items-center gap-1.5"><span aria-hidden="true" data-step="<?= e($_cm_l['step']) ?>" class="pg-map-swatch"></span><?= e($_cm_l['label']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
