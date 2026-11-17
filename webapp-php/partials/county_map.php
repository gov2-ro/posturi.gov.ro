<?php
/**
 * The county tile map and its legend. Shipped hidden (data-home-js); static/home.js shows it.
 *
 * In:  $cm_counts = array<slug,int> (postings of each county), $county_name = Closure(string): string
 *      optional $cm_label_hidden = true: the label above the map is for assistive technology only (a themed page)
 */
declare(strict_types=1);
?>
<div data-home-js hidden class="flex min-w-0 flex-col gap-2.5">
  <span class="<?= !empty($cm_label_hidden) ? 'sr-only' : 'g2-label' ?>" id="map-label">Posturi active pe județe</span>
  <div role="group" aria-label="Alege județul pe hartă" class="pg-map"<?= !empty($cm_label_hidden) ? ' aria-labelledby="map-label"' : '' ?>>
    <?php foreach (HOME_COUNTY_TILES as $code => [$slug, $col, $row]):
        $n = $cm_counts[$slug] ?? 0;
        $aria = $county_name($slug) . ': ' . posturi_label($n); ?>
    <button type="button" data-county="<?= e($slug) ?>" data-step="<?= home_map_step($n) ?>" aria-pressed="false"
            aria-label="<?= e($aria) ?>" title="<?= e($aria) ?>" style="grid-column: <?= $col ?>; grid-row: <?= $row ?>"
            class="pg-map-cell"><?= e($code) ?></button>
    <?php endforeach; ?>
  </div>
  <ul class="g2-text-meta flex flex-wrap items-center gap-x-3 gap-y-1">
    <?php foreach (['0–19', '20–39', '40–79', '80+'] as $step => $legend): ?>
    <li class="inline-flex items-center gap-1.5"><span aria-hidden="true" data-step="<?= $step ?>" class="pg-map-swatch"></span><?= e($legend) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
