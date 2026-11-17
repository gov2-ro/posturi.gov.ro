<?php
/**
 * An item with a count: one list item with a link, and the count in brackets outside the link.
 * Used by the professional domains of the landing page and by the items of a themed page.
 * static/home.js updates `[data-tile-count]` and the link of each `[data-home-tile]` when the
 * reader selects a county; an item of the list with no `n` (a plain link) only changes its link.
 *
 * In:  $ci = ['id' => string, 'label' => string, 'href' => string, 'n' => ?int, 'icon' => ?string]
 *      optional: 'compact' => true (a 28 px row, a 24 px icon: the lists of a themed page); 'icon_file' => a file in static/
 *      that holds the symbol `icon` (ADR 0007); 'icon_size' => classes of the icon (default h-6 w-6 compact, h-8 w-8 else); 'icon_gap' => true (no icon, but the text lines up with the items that have one)
 *      'parts' => a list of ['id', 'label', 'href', 'n']: two or more items on one row, after one icon. Each part is a
 *      `[data-home-tile]`; the row (`[data-home-group]`) has no count of its own. static/home.js hides the row when it hides every part.
 *      $fmt = Closure(int): string, the number format
 */
declare(strict_types=1);

$_ci_n = $ci['n'] ?? null;
$_ci_compact = !empty($ci['compact']);
$_ci_isize = ($ci['icon_size'] ?? ($_ci_compact ? 'h-6 w-6' : 'h-8 w-8')) . ' shrink-0';
$_ci_parts = $ci['parts'] ?? null;
$_ci_link = $_ci_compact ? 'py-[3px]' : 'py-3';
?>
<?php if ($_ci_parts): ?>
<li data-home-group class="flex <?= $_ci_compact ? '' : 'min-h-[44px] ' ?>items-center gap-2.5">
<?php else: ?>
<li data-home-tile data-domain="<?= e($ci['id']) ?>"<?= $_ci_n !== null ? ' data-total="' . (int)$_ci_n . '"' : '' ?> class="flex <?= $_ci_compact ? '' : 'min-h-[44px] ' ?>items-center gap-2.5">
<?php endif; ?>
  <?php if (!empty($ci['icon'])): ?><?= !empty($ci['icon_file']) ? icon_file($ci['icon_file'], $ci['icon'], $_ci_isize) : icon($ci['icon'], $_ci_isize) ?>
  <?php elseif (!empty($ci['icon_gap'])): ?><span class="<?= $_ci_isize ?>" aria-hidden="true"></span>
  <?php endif; ?>
  <?php /* Inline text, as in the examples: the count follows the last word (no-break space); the 44 px target is padding on an inline link. */ ?>
  <?php if ($_ci_parts): ?>
  <span class="min-w-0<?= $_ci_compact ? ' leading-7' : '' ?>"><?php foreach ($_ci_parts as $_ci_p): ?><span data-home-tile data-domain="<?= e($_ci_p['id']) ?>" data-total="<?= (int)$_ci_p['n'] ?>" class="mr-4 inline-block last:mr-0"><a href="<?= e($_ci_p['href']) ?>" class="<?= $_ci_link ?> text-base font-semibold"><?= e($_ci_p['label']) ?></a><span data-tile-count class="text-sm text-ink-muted">&nbsp;(<?= e($fmt((int)$_ci_p['n'])) ?>)</span></span><?php endforeach; ?></span>
  <?php else: ?>
  <span class="min-w-0<?= $_ci_compact ? ' leading-7' : '' ?>"><a href="<?= e($ci['href']) ?>" class="<?= $_ci_link ?> text-base font-semibold"><?= e($ci['label']) ?></a><?php if ($_ci_n !== null): ?><span data-tile-count class="text-sm text-ink-muted">&nbsp;(<?= e($fmt((int)$_ci_n)) ?>)</span><?php endif; ?></span>
  <?php endif; ?>
</li>
