<?php
/**
 * The row "Ocupații frecvente" above the results of the list: the most frequent occupations as chips (occupation_chips()
 * has the rules), and the link "Toate ocupațiile". Part of the result region (partials/result_list.php), so each filter
 * change updates it. A chip is the current filter plus `occupation[]=<value>`, with no `page`. With script, a click sets the
 * checkbox of that value in #filter-form and sends the same request as a filter change (list.php). The link "Toate ocupațiile"
 * opens the group "Ocupație" of the filter list (the sidebar, or the drawer below lg); without script it goes to the anchor.
 *
 * In: $occupation_options, $total_count, $PAGE_SIZE, $occ_sel (list.php)
 */
declare(strict_types=1);

$_oc = occupation_chips($occupation_options ?? [], (int)$total_count, (int)$PAGE_SIZE, (array)($occ_sel ?? []), (array)($_GET['occupation_not'] ?? []));
if ($_oc): ?>
<div data-occ-chips role="group" aria-labelledby="occ-chips-label" class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-2">
  <span id="occ-chips-label" class="g2-text-meta">Ocupații frecvente</span>
  <?php foreach ($_oc as $_o): ?>
  <a href="<?= e(qs_with('occupation', [$_o['val']])) ?>" data-occ-chip="<?= e($_o['val']) ?>" class="g2-chip"><?= e($_o['val']) ?> <span class="g2-chip-count"><?= (int)$_o['cnt'] ?></span></a>
  <?php endforeach; ?>
  <a href="#facet-occupation" data-occ-all class="g2-link-quiet inline-block py-2 text-sm">Toate ocupațiile</a>
</div>
<?php endif; ?>
