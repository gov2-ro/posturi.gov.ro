<?php
/**
 * /estimator-salariu/ — "What does function X pay?" (UX-SALARY-ESTIMATOR, brief 2026-11-18).
 *
 * GET only, no JavaScript. Every state has its own URL:
 *   ?q=consilier                     step 1: the functions that match
 *   ?b=lege&fn=<ref>&…               step 2: the choices of one function and the two figures
 *   ?rand=<ref>&g=<0-5>              direct entry: step 2 with that law row selected (the detail page uses it)
 * The pay comes from the export (salary_grid_pay): this file looks it up and converts gross to net.
 * Shape and form fields follow pages/eligibility_tool.php; the gov2 DS components first (ADR 0005).
 */
declare(strict_types=1);

$page_title = 'Estimator de salariu';
$meta_description = 'Cât se plătește o funcție din sectorul public? Salariul de bază brut și net după Legea 153/2017 și după proiectul de lege din iulie 2026.';

$se_url = '/estimator-salariu/';
require __DIR__ . '/../inc/header.php';
?>
<div class="w-full max-w-5xl px-4 sm:px-6 py-6" data-salary-estimator>
  <h1 class="g2-heading-page">Estimator de salariu</h1>
<?php if (!salary_est_available()): ?>
  <p class="mt-4" data-salary-unavailable>Estimatorul nu este disponibil acum.</p>
</div>
<?php
    require __DIR__ . '/../inc/footer.php';
    return;
endif;

$str = static fn(string $k, int $max): string => is_string($_GET[$k] ?? null) ? mb_substr(trim($_GET[$k]), 0, $max) : '';
$q = $str('q', 80);
$b = $str('b', 8) === 'proiect' ? 'proiect' : 'lege';
$fn = $str('fn', 80);
$rand = $str('rand', 80);
$g = ctype_digit($str('g', 1)) && $str('g', 1) !== '' ? min(5, (int)$str('g', 1)) : 0;
$cap_id = ctype_digit($str('cap', 3)) ? (int)$str('cap', 3) : 0;
$dims_in = [];
foreach (SALARY_EST_DIMS as $param => $_d) $dims_in[$param] = $str($param, 120);

// Direct entry: the law row names the function and the choices.
if ($rand !== '' && ($row = salary_est_row('lege', $rand))) {
    $b = 'lege';
    $fn = $row['ref'];
    $keep_local = $dims_in['nivel'] === 'local' && $row['nivel_administrativ'] === 'teritorial';
    foreach (SALARY_EST_DIMS as $param => [$col]) $dims_in[$param] = $row[$col] === '' ? '_' : (string)$row[$col];
    if ($keep_local) $dims_in['nivel'] = 'local';
}

$result = $fn !== '' ? salary_est_compute(['b' => $b, 'ref' => $fn, 'dims' => $dims_in, 'g' => $g, 'cap' => $cap_id]) : null;
$params = salary_est_params();
$fmt = static fn(int $n): string => number_format($n, 0, ',', '.');
/** "6.580" or "5.641–7.970" lei. */
$range = static fn(int $lo, int $hi): string => $lo === $hi ? $fmt($lo) : $fmt($lo) . '–' . $fmt($hi);
$gradation_label = static function (array $lv): string {
    $min = (int)$lv['vechime_min_ani']; $max = $lv['vechime_max_exclusiv_ani'];
    $years = $max === null ? "peste {$min} de ani" : ($min === 0 ? "sub {$max} ani" : "{$min}–{$max} ani");
    return 'Gradația ' . (int)$lv['nivel'] . ' (' . $years . ')';
};
?>
  <p class="g2-text-meta mt-2 max-w-3xl">
    Aflați salariul de bază brut și net al unei funcții din sectorul public: după Legea 153/2017, care este în vigoare,
    și după proiectul de lege din iulie 2026, care nu este adoptat. Sporurile nu sunt incluse.
  </p>

  <form class="mt-6 flex flex-wrap items-end gap-3" action="<?= e($se_url) ?>" method="get" role="search">
    <div class="g2-field w-full max-w-md">
      <label class="g2-label" for="se-q">Funcția</label>
      <input class="g2-input" id="se-q" name="q" type="search" value="<?= e($q) ?>" maxlength="80" autocomplete="off" placeholder="ex. consilier, asistent medical, șofer">
    </div>
    <button class="g2-btn" type="submit">Caută funcția</button>
  </form>

<?php if ($result): /* ---------------- step 2: the choices and the two figures ---------------- */
    $law = $result['law']; $draft = $result['draft'];
    $back = $se_url . ($q !== '' ? '?q=' . rawurlencode($q) : '');
    // The grid rows behind a figure: the reader can check them. At most 12; the pay is at the chosen gradation.
    $rows_table = static function (array $rows, array $pay) use ($fmt): string {
        $out = '<details class="text-sm"><summary class="cursor-pointer text-gov underline">Rândurile din grilă (' . count($rows) . ')</summary>'
             . '<div class="g2-table-wrap mt-2" role="region" aria-label="Rândurile din grilă" tabindex="0"><table class="g2-table">'
             . '<thead><tr><th scope="col">Grad sau treaptă</th><th scope="col">Studii</th><th scope="col" class="g2-cell-num">Brut, lei</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, 12) as $r) {
            [$lo, $hi] = $pay[$r['ref']] ?? [0, 0];
            $out .= '<tr><th scope="row">' . e($r['grad_treapta'] !== '' ? $r['grad_treapta'] : '—') . '</th><td>'
                  . e(salary_est_dim_label('studii', (string)$r['studii'])) . '</td><td class="g2-cell-num">'
                  . e($lo === $hi ? $fmt($lo) : $fmt($lo) . '–' . $fmt($hi)) . '</td></tr>';
        }
        $out .= '</tbody></table></div>';
        if (count($rows) > 12) $out .= '<p class="g2-text-meta mt-1">Și încă ' . (count($rows) - 12) . ' rânduri.</p>';
        return $out . '</details>';
    };
    $source_of = static function (array $rows): array {
        $s = [];
        foreach ($rows as $r) { $act = trim(explode(';', (string)$r['act_sursa'])[0]); if ($act !== '') $s[$act] = true; }
        return array_slice(array_keys($s), 0, 3);
    };
?>
  <section class="mt-8" aria-labelledby="se-fn" data-salary-step="2">
    <p class="text-sm"><a href="<?= e($back) ?>" class="text-gov underline">← Altă funcție</a></p>
    <h2 id="se-fn" class="g2-heading-section mt-2" data-salary-function><?= e(salary_est_short($result['f'], 200)) ?></h2>
    <p class="g2-text-meta">Anexa <?= e($result['a']) ?><?= $result['b'] === 'proiect' ? ' din proiect. Legea în vigoare nu are această funcție.' : '' ?></p>

    <form class="mt-4" action="<?= e($se_url) ?>" method="get" data-salary-form>
      <input type="hidden" name="b" value="<?= e($result['b']) ?>">
      <input type="hidden" name="fn" value="<?= e($result['ref']) ?>">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($result['dims'] as $param => $d): ?>
        <div class="g2-field">
          <label class="g2-label" for="se-<?= e($param) ?>"><?= e($d['label']) ?></label>
          <select class="g2-input" id="se-<?= e($param) ?>" name="<?= e($param) ?>">
            <option value="">toate variantele</option>
            <?php foreach ($d['options'] as $val => $label): ?>
            <option value="<?= e((string)$val) ?>"<?= (string)$result['chosen'][$param] === (string)$val ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endforeach; ?>
        <?php if ($result['local']): ?>
        <div class="g2-field">
          <label class="g2-label" for="se-cap">Tipul și populația localității</label>
          <select class="g2-input" id="se-cap" name="cap">
            <option value="">nu știu: cel mai mare plafon</option>
            <?php foreach ($result['caps'] as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= !empty($result['cap_chosen']) && (int)$result['cap']['id'] === (int)$c['id'] ? ' selected' : '' ?>><?= e(salary_est_cap_label($c)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="g2-field">
          <label class="g2-label" for="se-g">Gradația de vechime</label>
          <select class="g2-input" id="se-g" name="g">
            <?php foreach ($params['gradatii'] as $lv): ?>
            <option value="<?= (int)$lv['nivel'] ?>"<?= $result['g'] === (int)$lv['nivel'] ? ' selected' : '' ?>><?= e($gradation_label($lv)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mt-4 flex flex-wrap items-center gap-3">
        <button class="g2-btn" type="submit">Calculează</button>
        <span class="g2-text-meta">Adresa paginii păstrează alegerile. O puteți trimite mai departe.</span>
      </div>
    </form>

    <?php if ($result['hints'] && count($result['rows']) > 1): ?>
    <p class="g2-text-meta mt-4" data-salary-hint>Intervalul acoperă <?= count($result['rows']) ?> rânduri din grilă. Alegeți <?= e(implode(', ', $result['hints'])) ?> ca să îl restrângeți.</p>
    <?php endif; ?>

    <div class="mt-6 grid gap-4 lg:grid-cols-2">
      <?php /* ---- the law in force, first ---- */ ?>
      <section class="g2-tile flex flex-col gap-2 p-4" aria-labelledby="se-law" data-salary-law>
        <h3 id="se-law" class="g2-heading-item">Legea 153/2017 (în vigoare)</h3>
        <?php if ($law && $law['lo'] !== null): $lo = (int)$law['lo']; $hi = (int)$law['hi']; ?>
        <p class="text-2xl font-semibold text-ink" data-pay-net><?= e(net_label((float)$lo, (float)$hi)) ?> <span class="text-sm font-normal text-ink-muted">/ lună</span></p>
        <p class="text-sm text-ink-muted" data-pay-gross>Salariu de bază brut: <?= e($range($lo, $hi)) ?> lei / lună, la gradația <?= (int)$result['g'] ?></p>
        <?php if ($law['local']): ?>
        <p class="text-sm" data-pay-local>Consiliul local sau județean stabilește salariul, până la un plafon legal.
          Limita de jos este valoarea din tabelul legii. Limita de sus este plafonul de <?= e($fmt((int)$result['cap']['plafon_maxim_lei'])) ?> lei brut
          (<?= e(salary_est_cap_label($result['cap'])) ?>).<?= empty($result['cap_chosen']) ? ' Nu ați ales localitatea, deci folosim cel mai mare plafon, fără Capitală.' : '' ?></p>
        <?php endif; ?>
        <?php if ($law['mgmt']): ?>
        <p class="text-sm text-ink-muted">Funcțiile de conducere au deja gradația maximă (Art. 19 alin. (2)). Gradația aleasă nu schimbă cifra.</p>
        <?php endif; ?>
        <p class="g2-text-meta">Sursa: <?= e(implode('; ', $source_of($law['rows']))) ?>.
          Salariul minim brut de <?= e($fmt((int)$params['salariu_minim']['lei'])) ?> lei (din <?= e(date('d.m.Y', strtotime($params['salariu_minim']['de_la']))) ?>) este limita de jos.</p>
        <?= $rows_table($law['rows'], $law['pay']) ?>
        <?php else: ?>
        <p data-salary-none><?= $result['b'] === 'proiect' ? 'Nu avem grila legii pentru această funcție.' : 'Nicio variantă a funcției nu corespunde alegerilor.' ?></p>
        <?php endif; ?>
      </section>

      <?php /* ---- the draft, second ---- */ ?>
      <section class="g2-tile flex flex-col gap-2 p-4" aria-labelledby="se-draft" data-salary-draft>
        <h3 id="se-draft" class="g2-heading-item">După noua lege (proiect, neadoptat)</h3>
        <?php if ($draft): $lo = (int)$draft['lo']; $hi = (int)$draft['hi']; ?>
        <p class="text-2xl font-semibold text-ink" data-pay-net><?= e(net_label((float)$lo, (float)$hi)) ?> <span class="text-sm font-normal text-ink-muted">/ lună</span></p>
        <p class="text-sm text-ink-muted" data-pay-gross>Salariu de bază brut: <?= e($range($lo, $hi)) ?> lei / lună, la gradația <?= (int)$result['g'] ?></p>
        <p class="text-sm">Proiectul nu este adoptat. Cifra arată ce ar plăti funcția după proiect.
          <?= $draft['bands'] > 1 ? 'Proiectul are coeficienți pe banda de populație a localității: intervalul acoperă toate benzile.' : '' ?></p>
        <p class="g2-text-meta"><?= e($params['draft']['eticheta']) ?>, valoarea de referință <?= e($fmt((int)$params['draft']['valoare_referinta_lei'])) ?> lei.</p>
        <?= $rows_table($draft['rows'], $draft['pay']) ?>
        <?php else: ?>
        <p data-salary-none><?= $result['b'] === 'lege' ? 'Proiectul nu are o funcție corespunzătoare.' : 'Nicio variantă a funcției nu corespunde alegerilor.' ?></p>
        <?php endif; ?>
      </section>
    </div>

    <div class="g2-inset mt-6 max-w-3xl" data-salary-note>
      <p><strong>Sporurile nu sunt incluse.</strong> Estimarea nu include nici indemnizația de hrană. Angajatorul stabilește salariul real.
        Estimarea nu este un calcul oficial. Salariul net este calculat fără persoane în întreținere.
        <a href="/despre/#salarii" class="text-gov underline">Cum calculăm</a>.</p>
    </div>
  </section>

<?php elseif ($fn !== '' || $rand !== ''): ?>
  <p class="mt-8" data-salary-notfound>Nu am găsit funcția. Căutați din nou.</p>

<?php elseif ($q !== ''): /* ---------------- step 1: the matching functions ---------------- */
    $found = salary_est_search($q);
?>
  <section class="mt-8" aria-labelledby="se-results" data-salary-step="1">
    <h2 id="se-results" class="g2-heading-section">Funcții găsite</h2>
    <?php if ($found['total'] === 0): ?>
    <p class="mt-2" data-salary-empty>Nicio funcție nu corespunde căutării „<?= e($q) ?>”. Încercați un cuvânt mai scurt, de exemplu „inspector” sau „medic”.</p>
    <?php else: ?>
    <p class="g2-text-meta mt-1"><?= $found['total'] ?> funcții<?= $found['shown'] < $found['total'] ? '. Arătăm primele ' . $found['shown'] . '. Scrieți mai multe cuvinte ca să restrângeți lista' : '' ?>.</p>
    <?php foreach ($found['groups'] as $head => $items): ?>
    <h3 class="g2-label mt-5"><?= e($head) ?></h3>
    <ul class="mt-1 divide-y divide-line border-y border-line">
      <?php foreach ($items as $it): ?>
      <li><a class="block py-2" href="<?= e($se_url . '?b=' . $it['b'] . '&fn=' . rawurlencode($it['ref']) . '&q=' . rawurlencode($q)) ?>">
        <?= e(salary_est_short($it['name'])) ?>
        <?php if ($it['also'] !== ''): ?><span class="g2-text-meta"> (se potrivește: <?= e(salary_est_short($it['also'], 80)) ?>)</span><?php endif; ?>
      </a></li>
      <?php endforeach; ?>
    </ul>
    <?php endforeach; ?>
    <?php endif; ?>
  </section>

<?php else: /* ---------------- no search yet ---------------- */ ?>
  <p class="g2-text-meta mt-4">Începeți cu o funcție:
    <?php foreach (['consilier', 'asistent medical', 'inspector', 'referent', 'șofer', 'medic'] as $i => $ex): ?>
    <?= $i ? '· ' : '' ?><a class="text-gov underline" href="<?= e($se_url . '?q=' . rawurlencode($ex)) ?>"><?= e($ex) ?></a>
    <?php endforeach; ?>
  </p>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
