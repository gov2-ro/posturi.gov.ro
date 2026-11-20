<?php
/**
 * Declared pay of one institution (SALARY-DECLARATII-PAGE, ADR 0008 rule 9).
 *
 * Input: `$declared_slug`, the slug of the institution. Prints nothing when the slug is unknown.
 * The figures come from the export (declared_groups): net per month, 2026 level, rounded to 50 lei, groups of 5
 * or more declarants. No comparison with other employers, no name, no single declaration.
 * Copy: docs/briefs/2026-11-20-salary-declared-institutions.md step 2 and docs/specs/2026-11-20-salary-declared-explainer.md.
 */
$_dp_inst = declared_institution((string)($declared_slug ?? ''));
if (!$_dp_inst) return;
$_dp_groups = declared_groups_of($_dp_inst['slug']);
if (!$_dp_groups) return;
$_dp_fmt = static fn($v): string => number_format((int)round((float)$v), 0, ',', '.');
$_dp_err = (int)round(max(array_map(static fn($g) => (float)$g['eroare_tipica'], $_dp_groups)));
$_dp_top = $_dp_groups[0];                                   // the largest group: the example of step 1
$_dp_year = 2023;
$_dp_up = (int)round(((float)$_dp_top['factor'] - 1) * 100);
?>
<section class="mt-8 max-w-3xl" aria-labelledby="dp-h-<?= e($_dp_inst['slug']) ?>" data-declared-pay>
  <h2 id="dp-h-<?= e($_dp_inst['slug']) ?>" class="g2-heading-section">Cât câștigă angajații care depun declarații de avere</h2>
  <p class="mt-2" data-declared-scope>Doar funcționarii și conducerea, care depun declarații de avere. Personalul contractual (de exemplu șoferi, muncitori, asistenți) nu apare aici.</p>

  <div class="g2-table-wrap mt-4" role="region" aria-label="Venitul net pe funcții" tabindex="0">
    <table class="g2-table g2-table-stacked" role="table">
      <caption class="g2-text-meta"><?= e($_dp_inst['name']) ?>: funcțiile cu cel puțin 5 declaranți.</caption>
      <thead role="rowgroup"><tr role="row">
        <th scope="col" role="columnheader">Funcția</th>
        <th scope="col" role="columnheader" class="g2-cell-num">Declaranți</th>
        <th scope="col" role="columnheader" class="g2-cell-num">Net pe lună, tipic</th>
        <th scope="col" role="columnheader" class="g2-cell-num">Cei mai mulți între</th>
      </tr></thead>
      <tbody role="rowgroup">
      <?php foreach ($_dp_groups as $g): ?>
        <tr role="row" data-declared-row>
          <th scope="row" role="rowheader" data-label="Funcția"><?= e($g['functie']) ?></th>
          <td role="cell" class="g2-cell-num" data-label="Declaranți"><?= (int)$g['n'] ?></td>
          <td role="cell" class="g2-cell-num" data-label="Net pe lună, tipic"><?= $_dp_fmt($g['mediana_net']) ?> lei</td>
          <td role="cell" class="g2-cell-num" data-label="Cei mai mulți între"><?= $_dp_fmt($g['p25_net']) ?>–<?= $_dp_fmt($g['p75_net']) ?> lei</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="g2-text-meta mt-3" data-declared-note>Venitul din <?= $_dp_year ?>, adus la nivelul din 2026 cu creșterea salariilor din administrația publică (INS, +<?= $_dp_up ?> %). Eroare tipică: <?= $_dp_err ?> %.</p>

  <details class="mt-3" data-declared-how>
    <summary class="cursor-pointer text-sm font-semibold text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus">Cum am calculat ▾</summary>
    <div class="pt-2 text-sm text-ink" data-pay-explainer>
      <?php /* The four steps of the explainer (docs/specs/2026-11-20-salary-declared-explainer.md, section 3), for an institution. */ ?>
      <ol class="mb-2 list-decimal space-y-1 pl-5">
        <li data-explainer-step="1"><strong>Ce au declarat.</strong> În <?= $_dp_year ?>, <?= e(ro_count_de((int)$_dp_inst['n_total'], 'angajați')) ?> la <?= e($_dp_inst['name']) ?> au trecut venitul din salariu în declarațiile de avere. Noi i-am grupat după funcție, la această instituție. De exemplu, <?= e(ro_count_de((int)$_dp_top['n'], 'angajați')) ?> cu funcția <?= e($_dp_top['functie']) ?>: valoarea din mijloc a fost <?= $_dp_fmt($_dp_top['mediana_net_2023']) ?> lei net pe lună.</li>
        <li data-explainer-step="2"><strong>Cât au crescut salariile.</strong> Din <?= $_dp_year ?> până în 2026, salariul mediu net în administrația publică a crescut cu <?= $_dp_up ?>% (INS).</li>
        <li data-explainer-step="3"><strong>Estimarea pentru 2026.</strong> Tabelul arată, pentru fiecare funcție, valoarea din mijloc în 2026, în lei net pe lună. Cei mai mulți primesc între cele două valori din ultima coloană.</li>
        <li data-explainer-step="4"><strong>Cât de sigur este.</strong> Am verificat metoda pe anii trecuți. Am estimat salariile din <?= $_dp_year ?> pornind de la cele din <?= $_dp_year - 1 ?>. Eroarea tipică a fost de <?= $_dp_err ?>%.</li>
      </ol>
      <p class="mb-2">Salariul tău depinde de vechime și de sporuri. La angajare, primești de obicei mai puțin decât valoarea din mijloc. Arătăm o funcție numai când are cel puțin 5 declaranți. Nu arătăm nume și nu arătăm nicio declarație singură.</p>
      <p class="text-xs text-ink-muted"><strong>Surse:</strong> declarațiile de avere, prin monitorul.ai/integritate; INS, seriile FOM106D și FOM106G.
        <a href="/estimator-salariu/#metoda-declaratii" class="text-gov underline" data-pay-method-link>Metoda pe larg →</a></p>
    </div>
  </details>
</section>
