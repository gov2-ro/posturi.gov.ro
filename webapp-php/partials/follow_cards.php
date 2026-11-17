<?php
/**
 * The notification section: the email, calendar and RSS cards, and the line about saved items.
 *
 * In:  $fc = ['id' => string (the heading is "h-<id>"), 'calendar' => [href, link text], 'rss' => [href, link text]]
 *      optional 'band' => true: a `sunken` band from edge to edge of the window (.pg-band), in place of the box
 *      $heading = class of the section heading
 */
declare(strict_types=1);
?>
<section aria-labelledby="h-<?= e($fc['id']) ?>" class="flex flex-col gap-4 <?= !empty($fc['band']) ? 'pg-band py-8' : 'bg-sunken p-5 sm:p-6' ?>">
  <h2 id="h-<?= e($fc['id']) ?>" class="<?= $heading ?>">Abonează-te la notificări</h2>
  <p class="max-w-2xl">Alerte pe email și RSS pentru orice combinație de filtre / căutări.</p>
  <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
    <?php foreach ([
        ['i-mail', 'Email', 'Un rezumat la 3 zile cu posturile noi din căutarea ta.', 'Cum funcționează alertele', '/alerte/'],
        ['i-calendar', 'Calendar', 'Termenele de depunere și probele de concurs, în Google Calendar, Apple sau Outlook.', $fc['calendar'][1], $fc['calendar'][0]],
        ['i-feed', 'RSS', 'Un flux pentru cititorul tău de știri, pentru orice căutare.', $fc['rss'][1], $fc['rss'][0]],
    ] as [$ico, $ftitle, $ftext, $flink, $fhref]): ?>
    <div class="g2-tile flex flex-col gap-2 bg-surface p-4">
      <svg class="h-7 w-7 fill-none stroke-current stroke-2 [stroke-linecap:round] [stroke-linejoin:round]" aria-hidden="true" focusable="false"><use href="#<?= $ico ?>"/></svg>
      <h3 class="g2-heading-item"><?= e($ftitle) ?></h3>
      <p><?= e($ftext) ?></p>
      <a href="<?= e($fhref) ?>" class="inline-flex min-h-[44px] items-center"><?= e($flink) ?></a>
    </div>
    <?php endforeach; ?>
  </div>
  <p>Poți și să salvezi posturi și căutări în browser. Le găsești la <a href="/salvate/" class="inline-block py-3">Salvate</a>.</p>
</section>
