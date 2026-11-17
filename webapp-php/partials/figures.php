<?php
/**
 * The figures of a page (the landing page): a big number and its label. A figure with an `href` is a link;
 * a figure with no `href` is plain text (no list has its predicate).
 *
 * In: $figs = list of ['n' => int, 'label' => string, 'href' => ?string]
 *     $fmt  = Closure(int): string, the number format
 */
declare(strict_types=1);

foreach ($figs as $_fig):
    $_fig_class = 'flex min-h-[44px] min-w-0 flex-col gap-0.5 text-ink no-underline';
    $_fig_inner = '<span class="text-xl font-bold leading-tight sm:text-3xl">' . e($fmt((int)$_fig['n'])) . '</span>' . "\n  "
                . '<span class="g2-text-meta">' . e($_fig['label']) . '</span>';
    if (!empty($_fig['href'])): ?>
<a href="<?= e($_fig['href']) ?>" class="<?= $_fig_class ?> hover:text-gov">
  <?= $_fig_inner . "\n" ?>
</a>
<?php else: ?>
<div class="<?= $_fig_class ?>">
  <?= $_fig_inner . "\n" ?>
</div>
<?php endif;
endforeach;
