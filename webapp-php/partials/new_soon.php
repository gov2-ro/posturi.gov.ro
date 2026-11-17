<?php
/**
 * "Noi azi" and "Se închid în 3 zile": the two short lists of the landing page and of a themed page.
 * Each heading links to the list of the same postings (inside the filter $ns['base']).
 *
 * In:  $ns = ['id' => string (section id: the heading is "h-<id>"), 'base' => filter params,
 *             'day' => string, 'new' => rows, 'soon' => rows]
 *      $today = Y-m-d, $heading = class of the section heading
 */
declare(strict_types=1);

if (!$ns['new'] && !$ns['soon']) return;
$_ns_day = $ns['day'];
$_ns_word = $_ns_day === $today ? 'azi' : ($_ns_day !== '' ? fmt_date($_ns_day) : '');
$_ns_with = static fn(array $extra): string => '/?' . search_key(home_merge_params($ns['base'], $extra));
?>
<section aria-labelledby="h-<?= e($ns['id']) ?>" class="flex flex-col gap-4">
  <h2 id="h-<?= e($ns['id']) ?>" class="<?= $heading ?>">Noi și pe terminate</h2>
  <div class="grid grid-cols-[minmax(0,1fr)] gap-x-8 gap-y-6 md:grid-cols-2">
    <?php foreach ([
        ['Noi ' . $_ns_word, $_ns_with(['published' => $_ns_day]), $ns['new'], false],
        ['Se închid în 3 zile', $_ns_with(['status' => 'soon']) . '&sort=deadline', $ns['soon'], true],
    ] as [$htitle, $hhref, $hrows, $show_deadline]):
        if (!$hrows) continue; ?>
    <div class="min-w-0">
      <h3 class="g2-heading-item mb-1">
        <a href="<?= e($hhref) ?>" class="g2-link-title inline-block py-2.5"><?= e($htitle) ?> <span aria-hidden="true">→</span></a>
      </h3>
      <ul class="text-sm">
        <?php foreach ($hrows as $hp):
            $hdt = display_title($hp); $hdl = posting_deadline($hp); ?>
        <li class="flex items-baseline justify-between gap-3 border-t border-line">
          <span class="min-w-0 truncate"><a href="<?= e(job_url($hp)) ?>" class="inline-block py-3 font-medium"><?= e($hdt['primary']) ?></a>
            <span class="text-xs text-ink-muted">· <?= e($hp['employer_name']) ?></span></span>
          <?php if ($show_deadline && $hdl['date']): ?>
            <span class="shrink-0 font-mono text-xs text-alert-ink"><?php $hd = (int)days_until($hdl['date']); ?><?= e($hd === 0 ? 'azi' : days_label($hd)) ?></span>
          <?php elseif ($place = place_label($hp)): ?>
            <span class="max-w-[40%] shrink-0 truncate text-xs text-ink-muted"><?= e($place) ?></span>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>
</section>
