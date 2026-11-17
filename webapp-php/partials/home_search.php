<?php
/**
 * The search form of the landing page and of a themed page: the text, the county, and the hidden
 * fields of the theme filter. With script, static/home.js sends the reader to the canonical list
 * URL of the filled fields (or to /posturi/ when nothing is filled). Without it, the server
 * (index.php) drops blank values and redirects.
 *
 * In:  $hs = ['placeholder' => string, 'counts' => array<slug,int>, 'hidden' => array<field,list<string>>,
 *             'selects' => optional list of ['id', 'name' (the field, `x[]`), 'label', 'all' (text of the empty option), 'values',
 *                          optional 'labels' (value => text of the option; a value with no text shows itself)]]
 *      With `selects` the form has those selects in place of the text field (theme key `search_selects`).
 *      $county_options, $fmt: see county_select.php
 */
declare(strict_types=1);
?>
<form method="get" action="/" data-home-search class="flex flex-wrap gap-2<?= empty($hs['selects']) ? '' : ' lg:flex-nowrap' ?>">
  <?php foreach ($hs['hidden'] as $_hs_field => $_hs_vals): foreach ($_hs_vals as $_hs_v): ?>
  <input type="hidden" name="<?= e($_hs_field) ?>" value="<?= e($_hs_v) ?>">
  <?php endforeach; endforeach; ?>
  <?php if (empty($hs['selects'])): ?>
  <label for="home-q" class="sr-only">Caută un post</label>
  <input id="home-q" type="search" name="q" placeholder="<?= e($hs['placeholder']) ?>"
         class="g2-input min-h-12 min-w-0 flex-[1_1_14rem] placeholder:text-ink-muted">
  <?php else: foreach ($hs['selects'] as $_hs_sel): ?>
  <label for="<?= e($_hs_sel['id']) ?>" class="sr-only"><?= e($_hs_sel['label']) ?></label>
  <select id="<?= e($_hs_sel['id']) ?>" name="<?= e($_hs_sel['name']) ?>" class="g2-input min-h-12 min-w-0 flex-[1_1_13rem] lg:flex-[1_1_10rem]">
    <option value=""><?= e($_hs_sel['all']) ?></option>
    <?php foreach ($_hs_sel['values'] as $_hs_val): ?>
    <option value="<?= e($_hs_val) ?>"><?= e((string)($_hs_sel['labels'][$_hs_val] ?? $_hs_val)) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endforeach; endif; ?>
  <?php $cs = ['id' => 'home-judet', 'name' => 'judet[]', 'class' => 'g2-input min-h-12 min-w-0 ' . (empty($hs['selects']) ? 'flex-[0_1_11rem]' : 'flex-[1_1_13rem] lg:flex-[1_1_10rem]'), 'counts' => $hs['counts']];
        require __DIR__ . '/county_select.php'; ?>
  <?php if (empty($hs['selects'])): ?>
  <button type="submit" class="g2-btn min-h-12 justify-center px-6">Caută</button>
  <?php else: /* With selects the form is one row from lg: the button is the search icon, its name is the text (hidden from lg). */ ?>
  <button type="submit" class="g2-btn min-h-12 w-full justify-center gap-2 px-4 sm:w-auto lg:min-w-12 lg:flex-none lg:px-0 lg:w-12"><?= icon('i-search', 'h-5 w-5 shrink-0') ?><span class="lg:sr-only">Caută</span></button>
  <?php endif; ?>
</form>
