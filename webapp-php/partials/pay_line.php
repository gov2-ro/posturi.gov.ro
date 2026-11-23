<?php
/** The 💰 salary line of a list row (a copy of the one in result_list.php; UX-SALARY-BOX). Expects $p, the posting row. */
$sal = list_salary($p);
if ($sal):
    $_decl_row = ($sal['kind'] ?? 'estimat') !== 'estimat'; ?>
<p class="pg-facts mt-1 text-xs leading-relaxed text-ink-muted">
  <span class="pg-salary<?= $_decl_row ? '' : ' whitespace-nowrap' ?>" title="<?= isset($sal['title']) ? e($sal['title']) : e($sal['label']) . ' (net lunar) · brut: ' . e($sal['gross']) ?>">
    <span class="pg-salary-label" role="img" aria-label="<?= e($sal['label']) ?>">💰</span>
    <span class="pg-salary-value font-medium text-ink<?= $_decl_row ? ' whitespace-nowrap' : '' ?>"><?= e($sal['text']) ?></span><?php if ($sal['estimated']): ?>
    <span class="pg-salary-kind" data-pay-kind="<?= e($sal['kind'] ?? 'estimat') ?>">(<?= e($sal['kind'] ?? 'estimat') ?>)</span><?php endif; ?>
    <?= pay_meter($p) ?>
  </span>
</p>
<?php endif; ?>
