<?php
/**
 * /estimator-salariu/ — "What does function X pay?" (UX-SALARY-ESTIMATOR, brief 2026-11-18).
 *
 * GET only, no JavaScript. Every state has its own URL. The wizard (UX-SALARY-WIZARD, brief 2026-11-19):
 *   (none)                           domain (the annex)
 *   ?d=II                            studies (only the levels of the domain)
 *   ?d=II&st=S[&fq=text]             function (the list for domain + studies)
 *   ?b=lege&fn=<ref>&st=S&d=II&…     variants and the result
 *   ?q=asistent                      the title shortcut: titles of postings first, then the names of the grid
 *   ?rand=<ref>&g=<0-5>              direct entry: the variants with that law row selected (the detail page uses it)
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
$dom = $str('d', 4);
$dom = isset(SALARY_EST_DOMAINS[$dom]) ? $dom : '';
$st = $str('st', 3);
$st = isset(SALARY_EST_LEVELS[$st]) ? $st : '';
$fq = $str('fq', 80);
$jud_in = $str('jud', 40);
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

$result = $fn !== '' ? salary_est_compute(['b' => $b, 'ref' => $fn, 'dims' => $dims_in, 'g' => $g, 'cap' => $cap_id, 'st' => $st]) : null;
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

  <?php /* The shortcut: the job title of a posting. It stays above every step. */ ?>
  <form class="mt-6 flex flex-wrap items-end gap-3" action="<?= e($se_url) ?>" method="get" role="search" data-salary-title-search>
    <div class="g2-field w-full max-w-md">
      <label class="g2-label" for="se-q">Caută după titlul postului</label>
      <input class="g2-input" id="se-q" name="q" type="search" value="<?= e($q) ?>" maxlength="80" autocomplete="off" placeholder="ex. asistent medical, inspector de specialitate, șofer">
    </div>
    <button class="g2-btn" type="submit">Caută</button>
  </form>

<?php
// The breadcrumb: every part is a link to its step (the DS component .g2-breadcrumbs).
$crumbs = static function (array $trail): string {
    $out = '<nav class="g2-breadcrumbs mt-6" aria-label="Pașii estimatorului" data-salary-crumbs><ol>';
    foreach ($trail as $i => [$label, $href]) {
        $out .= $href !== null && $i < count($trail) - 1
            ? '<li><a class="g2-link-quiet" href="' . e($href) . '">' . e($label) . '</a></li>'
            : '<li aria-current="page">' . e($label) . '</li>';
    }
    return $out . '</ol></nav>';
};
// A choice of a step: a large row that is one link (at least 44 px high).
// DS-PROPOSAL (not filed): a list of choices as links, like radios. Local until the DS has one.
$choice = static function (string $href, string $label, string $meta = '', string $attr = ''): string {
    return '<li><a class="flex min-h-[44px] items-center justify-between gap-3 border border-line bg-surface px-4 py-3 text-ink no-underline hover:bg-sunken" href="'
        . e($href) . '" ' . $attr . '><span>' . e($label) . '</span>'
        . ($meta !== '' ? '<span class="g2-text-meta shrink-0">' . e($meta) . '</span>' : '') . '</a></li>';
};
$domain_label = static fn(string $a): string => SALARY_EST_DOMAINS[$a][0] ?? ('Anexa ' . $a);
$domain_url = static fn(string $a): string => $se_url . '?d=' . rawurlencode($a);
$level_url = static fn(string $a, string $l): string => $se_url . '?d=' . rawurlencode($a) . '&st=' . rawurlencode($l);
?>

<?php if ($result): /* ---------------- step 4: the variants and the result ---------------- */
    $law = $result['law']; $draft = $result['draft'];
    // The domain and the studies come from the URL, or from the annex of the function (a direct entry).
    $crumb_dom = $dom !== '' ? $dom : (string)$result['a'];
    $trail = [['Estimator de salariu', $se_url], [$domain_label($crumb_dom), $domain_url($crumb_dom)]];
    if ($result['st'] !== '') $trail[] = [SALARY_EST_LEVELS[$result['st']], $level_url($crumb_dom, $result['st'])];
    $trail[] = [salary_est_short($result['f'], 80), null];
    // The INS line: the annex of the function names the sector; the county is optional and does not change the estimate.
    $ins_section = SALARY_EST_DOMAINS[$result['a']][1] ?? null;
    $ins_counties = $ins_section !== null ? salary_est_ins_counties($ins_section) : [];
    $jud = in_array($jud_in, $ins_counties, true) ? $jud_in : '';
    $ins = salary_est_ins_line((string)$result['a'], $jud);
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
  <?= $crumbs($trail) ?>
  <section class="mt-4" aria-labelledby="se-fn" data-salary-step="2">
    <h2 id="se-fn" class="g2-heading-section" data-salary-function><?= e(salary_est_short($result['f'], 200)) ?></h2>
    <p class="g2-text-meta">Anexa <?= e($result['a']) ?><?= $result['b'] === 'proiect' ? ' din proiect. Legea în vigoare nu are această funcție.' : '' ?></p>

    <form class="mt-4" action="<?= e($se_url) ?>" method="get" data-salary-form>
      <input type="hidden" name="b" value="<?= e($result['b']) ?>">
      <input type="hidden" name="fn" value="<?= e($result['ref']) ?>">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <?php if ($dom !== ''): ?><input type="hidden" name="d" value="<?= e($dom) ?>"><?php endif; ?>
      <?php if ($result['st'] !== ''): ?><input type="hidden" name="st" value="<?= e($result['st']) ?>"><?php endif; ?>
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($result['dims'] as $param => $dim): ?>
        <div class="g2-field">
          <label class="g2-label" for="se-<?= e($param) ?>"><?= e($dim['label']) ?></label>
          <select class="g2-input" id="se-<?= e($param) ?>" name="<?= e($param) ?>">
            <option value="">toate variantele</option>
            <?php foreach ($dim['options'] as $val => $label): ?>
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
        <?php if ($ins_counties): ?>
        <div class="g2-field">
          <label class="g2-label" for="se-jud">Județul (pentru context)</label>
          <select class="g2-input" id="se-jud" name="jud">
            <option value="">România</option>
            <?php foreach ($ins_counties as $c): ?>
            <option value="<?= e($c) ?>"<?= $jud === $c ? ' selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
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

    <?php if ($ins): /* INS context: the average of the domain, never the estimate (ADR 0008 rules 5 and 8). */ ?>
    <p class="g2-text-meta mt-4 max-w-3xl" data-salary-ins>
      Context INS: <?= e($ins['text']) ?>
      (TEMPO <?php foreach ($ins['matrix_links'] as $i => $l): ?><?= $i ? ', ' : '' ?><a class="text-gov underline" href="<?= e($l['url']) ?>" rel="noopener"><?= e($l['code']) ?></a><?php endforeach; ?>).
      O medie include vechimea și sporurile, deci este mai mare decât un salariu de bază la gradația 0.<?= $ins['judet'] !== '' ? ' Cifra județului este media ponderată a celor două sexe.' : '' ?>
    </p>
    <?php endif; ?>

    <div class="g2-inset mt-6 max-w-3xl" data-salary-note>
      <p><strong>Sporurile nu sunt incluse.</strong> Estimarea nu include nici indemnizația de hrană. Angajatorul stabilește salariul real.
        Estimarea nu este un calcul oficial. Salariul net este calculat fără persoane în întreținere.
        <a href="/despre/#salarii" class="text-gov underline">Cum calculăm</a>.</p>
    </div>
  </section>

<?php elseif ($fn !== '' || $rand !== ''): ?>
  <p class="mt-8" data-salary-notfound>Nu am găsit funcția. Căutați din nou.</p>

<?php elseif ($q !== ''): /* ---------------- the title shortcut: titles of postings first, then the names of the grid ---------------- */
    $titles = salary_est_title_search($q, 30);
    $found = salary_est_search($q, 30);
?>
  <?= $crumbs([['Estimator de salariu', $se_url], ['Căutare: ' . $q, null]]) ?>
  <section class="mt-4" aria-labelledby="se-results" data-salary-step="1">
    <h2 id="se-results" class="g2-heading-section">Rezultate pentru „<?= e($q) ?>”</h2>
    <?php if ($titles['total'] === 0 && $found['total'] === 0): ?>
    <p class="mt-2" data-salary-empty>Nicio funcție nu corespunde căutării „<?= e($q) ?>”. Încercați un cuvânt mai scurt, de exemplu „inspector” sau „medic”.
      Sau <a class="text-gov underline" href="<?= e($se_url) ?>">alegeți domeniul</a>.</p>
    <?php endif; ?>
    <?php if ($titles['items']): ?>
    <h3 class="g2-label mt-5" data-salary-titles>Titluri de posturi din anunțuri</h3>
    <p class="g2-text-meta mt-1"><?= $titles['total'] ?> titluri<?= count($titles['items']) < $titles['total'] ? '. Arătăm primele ' . count($titles['items']) . '. Scrieți mai multe cuvinte ca să restrângeți lista' : '' ?>.</p>
    <ul class="mt-2 grid gap-2 lg:grid-cols-2">
      <?php foreach ($titles['items'] as $t): ?>
      <?= $choice(salary_est_row_url($t['ref'], 0), (string)$t['titlu'],
                  (int)$t['nr'] . ($t['nr'] == 1 ? ' anunț' : ' anunțuri'), 'data-salary-title-result') ?>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($found['total'] > 0): ?>
    <h3 class="g2-label mt-6" data-salary-names>Funcții din grilă</h3>
    <p class="g2-text-meta mt-1"><?= $found['total'] ?> funcții<?= $found['shown'] < $found['total'] ? '. Arătăm primele ' . $found['shown'] . '. Scrieți mai multe cuvinte ca să restrângeți lista' : '' ?>.</p>
    <?php foreach ($found['groups'] as $head => $items): ?>
    <h4 class="g2-text-meta mt-4"><?= e($head) ?></h4>
    <ul class="mt-1 divide-y divide-line border-y border-line">
      <?php foreach ($items as $it): ?>
      <li><a class="block min-h-[44px] py-3" href="<?= e($se_url . '?b=' . $it['b'] . '&fn=' . rawurlencode($it['ref']) . '&q=' . rawurlencode($q)) ?>">
        <?= e(salary_est_short($it['name'])) ?>
        <?php if ($it['also'] !== ''): ?><span class="g2-text-meta"> (se potrivește: <?= e(salary_est_short($it['also'], 80)) ?>)</span><?php endif; ?>
      </a></li>
      <?php endforeach; ?>
    </ul>
    <?php endforeach; ?>
    <?php endif; ?>
  </section>

<?php elseif ($dom !== '' && ($st !== '' || !salary_est_level_counts($dom))): /* an annex with no studies in its rows skips step 2: */ /* ---------------- step 3: the functions for domain + studies ---------------- */
    $all_items = salary_est_level_items($dom, $st);
    $list = $fq !== '' ? salary_est_level_items($dom, $st, $fq) : $all_items;
    $post_heads = ['execuție' => 'Funcții de execuție', 'conducere' => 'Funcții de conducere', 'altele' => 'Alte funcții'];
?>
  <?= $crumbs($st !== '' ? [['Estimator de salariu', $se_url], [$domain_label($dom), $domain_url($dom)], [SALARY_EST_LEVELS[$st], null]]
                          : [['Estimator de salariu', $se_url], [$domain_label($dom), null]]) ?>
  <section class="mt-4" aria-labelledby="se-fns" data-salary-wizard="function">
    <h2 id="se-fns" class="g2-heading-section">Alegeți funcția</h2>
    <p class="g2-text-meta mt-1"><?= count($all_items) ?> funcții: <?= e(mb_strtolower($domain_label($dom))) ?><?= $st !== '' ? ', studii ' . e(mb_strtolower(SALARY_EST_LEVELS[$st])) : '' ?>.</p>
    <?php if (count($all_items) > 40): ?>
    <form class="mt-4 flex flex-wrap items-end gap-3" action="<?= e($se_url) ?>" method="get" role="search">
      <input type="hidden" name="d" value="<?= e($dom) ?>">
      <?php if ($st !== ''): ?><input type="hidden" name="st" value="<?= e($st) ?>"><?php endif; ?>
      <div class="g2-field w-full max-w-md">
        <label class="g2-label" for="se-fq">Filtrează lista</label>
        <input class="g2-input" id="se-fq" name="fq" type="search" value="<?= e($fq) ?>" maxlength="80" autocomplete="off">
      </div>
      <button class="g2-btn" type="submit">Filtrează</button>
    </form>
    <?php endif; ?>
    <?php if (!$list): ?>
    <p class="mt-4" data-salary-empty>Nicio funcție nu corespunde filtrului „<?= e($fq) ?>”.
      <a class="text-gov underline" href="<?= e($st !== '' ? $level_url($dom, $st) : $domain_url($dom)) ?>">Arătați toată lista</a>.</p>
    <?php endif; ?>
    <?php foreach ($post_heads as $post => $head):
        $group = array_values(array_filter($list, fn($i) => $i['post'] === $post));
        if (!$group) continue; ?>
    <h3 class="g2-label mt-6"><?= e($head) ?> <span class="g2-text-meta">(<?= count($group) ?>)</span></h3>
    <ul class="mt-2 grid gap-2 lg:grid-cols-2">
      <?php foreach ($group as $i): ?>
      <?= $choice($se_url . '?b=' . $i['b'] . '&fn=' . rawurlencode($i['ref']) . ($st !== '' ? '&st=' . rawurlencode($st) : '') . '&d=' . rawurlencode($dom),
                  salary_est_short($i['name'], 120), $i['b'] === 'proiect' ? 'doar în proiect' : '', 'data-salary-fn') ?>
      <?php endforeach; ?>
    </ul>
    <?php endforeach; ?>
  </section>

<?php elseif ($dom !== ''): /* ---------------- step 2: the studies that the domain has ---------------- */
    $levels = salary_est_level_counts($dom);
?>
  <?= $crumbs([['Estimator de salariu', $se_url], [$domain_label($dom), null]]) ?>
  <section class="mt-4" aria-labelledby="se-studies" data-salary-wizard="studies">
    <h2 id="se-studies" class="g2-heading-section">Ce studii cere funcția?</h2>
    <p class="g2-text-meta mt-1">Domeniul: <?= e(mb_strtolower($domain_label($dom))) ?>. Numărul arată câte funcții are fiecare nivel.</p>
    <ul class="mt-4 grid gap-2 lg:grid-cols-2">
      <?php foreach ($levels as $l => $n): ?>
      <?= $choice($level_url($dom, $l), SALARY_EST_LEVELS[$l], $n . ($n == 1 ? ' funcție' : ' funcții'), 'data-salary-level="' . e($l) . '"') ?>
      <?php endforeach; ?>
    </ul>
    <?php if (!$levels): ?><p class="mt-2">Nu avem funcții pentru acest domeniu.</p><?php endif; ?>
  </section>

<?php else: /* ---------------- step 1: the domain ---------------- */
    $domains = salary_est_domains();
    $in_law = array_filter($domains, fn($x) => $x['law']);
    $draft_only = array_filter($domains, fn($x) => !$x['law']);
    $count_of = static fn(string $a): int => count(salary_est_items($a));
?>
  <section class="mt-8" aria-labelledby="se-domain" data-salary-wizard="domain">
    <h2 id="se-domain" class="g2-heading-section">În ce domeniu lucrează funcția?</h2>
    <p class="g2-text-meta mt-1">Alegeți domeniul, apoi studiile, apoi funcția. Domeniul și studiile doar restrâng lista. Nu le păstrăm.</p>
    <ul class="mt-4 grid gap-2 lg:grid-cols-2">
      <?php foreach ($in_law as $x): ?>
      <?= $choice($domain_url($x['a']), $x['label'], $count_of($x['a']) . ' funcții', 'data-salary-domain="' . e($x['a']) . '"') ?>
      <?php endforeach; ?>
    </ul>
    <?php if ($draft_only): ?>
    <h3 class="g2-label mt-6">Doar în proiect</h3>
    <p class="g2-text-meta mt-1">Legea în vigoare nu are aceste anexe în grila noastră. Arătăm numai cifra din proiect.</p>
    <ul class="mt-2 grid gap-2 lg:grid-cols-2">
      <?php foreach ($draft_only as $x): ?>
      <?= $choice($domain_url($x['a']), $x['label'], $count_of($x['a']) . ' funcții', 'data-salary-domain="' . e($x['a']) . '"') ?>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p class="g2-text-meta mt-6">Ați găsit titlul postului într-un anunț? Scrieți-l în câmpul de mai sus:
      <?php foreach (['consilier', 'asistent medical', 'inspector', 'referent', 'șofer', 'medic'] as $i => $ex): ?>
      <?= $i ? '· ' : '' ?><a class="text-gov underline" href="<?= e($se_url . '?q=' . rawurlencode($ex)) ?>"><?= e($ex) ?></a>
      <?php endforeach; ?>
    </p>
  </section>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
