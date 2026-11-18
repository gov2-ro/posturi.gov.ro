<?php
/**
 * The statistics panel of a themed page (with the theme key `history`): the weekly chart with the buttons of the
 * occupations. The server writes the whole country (the same for every reader); static/theme-panel.js shows the county
 * that the reader selects, from the JSON block.
 *
 * The salary chart ($ts['salary']) is not in the panel: pages/theme.php renders it before the `recent` section (2026-11-18).
 *
 * In: $ts = ['weekly' => ?array (home_weekly_postings()), 'series' => ?array (theme_occupation_weeks()),
 *           'data' => the JSON block: the weekly values of each county]
 */
declare(strict_types=1);
?>
<div data-theme-stats class="flex min-w-0 flex-col gap-5 max-lg:order-last">
  <h2 class="sr-only">Statistici</h2>
  <?php if ($ts['weekly'] !== null): ?>
  <?php $wc = ['weekly' => $ts['weekly'], 'caption' => 'Posturi publicate pe săptămână', 'caption_hidden' => true, 'series' => $ts['series']];
        require __DIR__ . '/weekly_chart.php'; ?>
  <?php endif; ?>
  <?php /* The data of the county selection (static/theme-panel.js): the same panel for every reader; the page shows the whole country. */ ?>
  <script type="application/json" id="theme-panel-data"><?= json_encode($ts['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>
