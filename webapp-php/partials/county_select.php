<?php
/**
 * The county select: a hidden label and one option for each county, with its count of postings in brackets.
 *
 * In:  $cs = ['id' => string, 'name' => ?string (the field name; none = not part of a form),
 *             'class' => string, 'counts' => array<slug,int>]
 *      $county_options = slug => name (judet_names_sorted()), $fmt = Closure(int): string
 */
declare(strict_types=1);
?>
<label for="<?= e($cs['id']) ?>" class="sr-only">Județ</label>
<select id="<?= e($cs['id']) ?>"<?= $cs['name'] !== null ? ' name="' . e($cs['name']) . '"' : '' ?> class="<?= e($cs['class']) ?>">
  <option value="" data-name="Toate județele">Toate județele</option>
  <?php foreach ($county_options as $slug => $name): ?>
  <option value="<?= e($slug) ?>" data-name="<?= e($name) ?>"><?= e($name) ?> (<?= e($fmt($cs['counts'][$slug] ?? 0)) ?>)</option>
  <?php endforeach; ?>
</select>
