<?php
/**
 * UX-SALARY-ESTIMATOR — the page /estimator-salariu/, against the fixture (tests/fixtures/build_db.php,
 * fixture_salary_estimator()) on the built-in router.
 *
 * Checked: the search (accent-insensitive); step 2 by `?rand=`; a central row gives the exported lei and
 * the net of net_from_gross(); a local row gives the range up to the chosen cap; a draft-only function gives
 * the law message; a law-only function gives the draft message; gradation 3 pays more than 0, except a
 * management row; parity with the posting's sal_min; the link on the detail page; an older export (no tables).
 *
 * UX-SALARY-WIZARD and SALARY-INS-LINE (brief 2026-11-19): the steps domain -> studies -> function -> variants, the levels
 * of a domain, `PL/M` under PL and M, the breadcrumb, the title shortcut, the old URLs, the INS line (country, county, none),
 * and an export without `salary_title` and `salary_ins_context`.
 *
 * Usage: php tests/salary_estimator_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

function se_get(string $base, string $path): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0]]);
    $body = (string)@file_get_contents($base . $path, false, $ctx);
    $status = 500;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
    return [$status, $body];
}

/** The inner HTML of one result block: 'law' or 'draft'. */
function se_block(string $html, string $which): string {
    return preg_match('#<section[^>]*data-salary-' . $which . '>(.*?)</section>#s', $html, $m) ? $m[1] : '';
}

/** "Salariu de bază brut: 5.641–7.970 lei" of a block as [lo, hi] ints, or null. */
function se_gross(string $block): ?array {
    if (!preg_match('#Salariu de bază brut: ([\d.]+)(?:–([\d.]+))? lei#u', $block, $m)) return null;
    $lo = (int)str_replace('.', '', $m[1]);
    return [$lo, isset($m[2]) && $m[2] !== '' ? (int)str_replace('.', '', $m[2]) : $lo];
}

$db_path = sys_get_temp_dir() . '/posturi-salest-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
$pdo = new PDO('sqlite:' . $db_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
putenv('POSTURI_DB=' . $db_path);   // the helpers that the test calls itself read the fixture
$seeded = fixture_seed_law_estimates($pdo);
$srv = start_fixture_server($db_path);
$base = $srv['base'];
$P = '/estimator-salariu/';

run_suite('step 1: the search finds a function, ignoring case and diacritics', function () use ($base, $P) {
    [$st, $h] = se_get($base, $P . '?q=Consilier');
    assert_same(200, $st, 'status');
    assert_contains('data-salary-step="1"', $h, 'step 1');
    assert_contains('Consilier', $h, 'Consilier listed');
    foreach (['asistent medical', 'ASISTENT médical', 'asistent  medicăl'] as $q) {
        [, $h] = se_get($base, $P . '?q=' . rawurlencode($q));
        assert_contains('Asistent medical', $h, "q=$q");
        assert_contains('fn=L153-t3%211', $h, "q=$q links to step 2");
    }
    [, $h] = se_get($base, $P . '?q=zzzz');
    assert_contains('data-salary-empty', $h, 'no match');
    [, $h] = se_get($base, $P . '?q=consilier&q2=x');
    assert_not_contains('Warning', $h, 'no PHP warning');
});

run_suite('step 2 by ?rand=: a central row gives the exported lei and the net', function () use ($base, $P) {
    [$st, $h] = se_get($base, $P . '?rand=' . rawurlencode('L153-t1!1') . '&g=0');
    assert_same(200, $st, 'status');
    assert_contains('data-salary-step="2"', $h, 'opens step 2');
    $law = se_block($h, 'law');
    assert_same([6580, 6580], se_gross($law), 'gross of the row, gradation 0');
    assert_contains(net_label(6580.0, 6580.0), $law, 'net from net_from_gross()');
    assert_contains('Legea 153/2017 (în vigoare)', $law, 'law heading');
    // The law block comes before the draft block.
    assert_true(strpos($h, 'data-salary-law') < strpos($h, 'data-salary-draft'), 'law first');
    $draft = se_block($h, 'draft');
    assert_contains('După noua lege (proiect, neadoptat)', $draft, 'draft heading');
    assert_same([(int)ceil(1.35 * 4100), (int)ceil(1.85 * 4100)], se_gross($draft), 'the draft range over the two bands');
    assert_contains('Sporurile nu sunt incluse', $h, 'the sporuri line');
    // A hospital row: the unit type gives its own value.
    [, $a1] = se_get($base, $P . '?rand=' . rawurlencode('L153-t3!1'));
    [, $a4] = se_get($base, $P . '?rand=' . rawurlencode('L153-t3!2'));
    assert_same([5474, 5474], se_gross(se_block($a1, 'law')), 'a.1');
    assert_same([5460, 5460], se_gross(se_block($a4, 'law')), 'a.4');
});

run_suite('a local post: a range up to the chosen cap', function () use ($base, $P) {
    $u = $P . '?rand=' . rawurlencode('L153-t2!1') . '&nivel=local';
    [, $h] = se_get($base, $u . '&cap=4');
    $law = se_block($h, 'law');
    assert_same([5641, 7970], se_gross($law), 'territorial row up to the cap of a small comună');
    assert_contains('data-pay-local', $law, 'local note');
    assert_contains(net_label(5641.0, 7970.0), $law, 'net range');
    [, $h] = se_get($base, $u . '&cap=1');
    assert_same([5641, 21735], se_gross(se_block($h, 'law')), 'the Capital cap');
    [, $h] = se_get($base, $u);
    $law = se_block($h, 'law');
    assert_same([5641, 13283], se_gross($law), 'no choice: the widest cap that is not the Capital');
    assert_contains('cel mai mare plafon', $law, 'it says so');
});

run_suite('a draft-only function and a law-only function', function () use ($base, $P) {
    [, $h] = se_get($base, $P . '?q=soldat');
    assert_contains('Numai în proiect', $h, 'listed as draft-only');
    preg_match('#href="([^"]*fn=D3%211[^"]*)"#', $h, $m);
    assert_true(isset($m[1]), 'link to the draft row');
    [, $h] = se_get($base, html_entity_decode($m[1]));
    assert_contains('Nu avem grila legii pentru această funcție.', se_block($h, 'law'), 'law message');
    assert_same([(int)ceil(1.1 * 4100), (int)ceil(1.1 * 4100)], se_gross(se_block($h, 'draft')), 'draft figure');
    [, $h] = se_get($base, $P . '?rand=' . rawurlencode('L153-t5!1'));
    assert_contains('Proiectul nu are o funcție corespunzătoare.', se_block($h, 'draft'), 'draft message');
    assert_same([4700, 4700], se_gross(se_block($h, 'law')), 'law figure');
});

run_suite('the gradation raises the pay, except for a management row', function () use ($base, $P) {
    $g = fn(string $ref, int $n) => se_gross(se_block(se_get($base, $P . '?rand=' . rawurlencode($ref) . '&g=' . $n)[1], 'law'));
    assert_same([(int)ceil(round(6580 * 1.1851875, 6)), (int)ceil(round(6580 * 1.1851875, 6))], $g('L153-t1!1', 3), 'gradation 3 of 6,580');
    assert_true($g('L153-t1!1', 3)[0] > $g('L153-t1!1', 0)[0], 'higher at 3 than at 0');
    assert_same($g('L153-t4!1', 0), $g('L153-t4!1', 3), 'a management row ignores the gradation');
    [, $h] = se_get($base, $P . '?rand=' . rawurlencode('L153-t4!1') . '&g=3');
    assert_contains('gradația maximă', se_block($h, 'law'), 'the management note');
    [, $h] = se_get($base, $P . '?rand=' . rawurlencode('L153-t1!1') . '&g=9');
    assert_same($g('L153-t1!1', 5), se_gross(se_block($h, 'law')), 'a gradation above 5 is read as 5');
});

run_suite('parity: the page at ?rand=<first ref>&g=0 gives the sal_min of the posting', function () use ($base, $P, $pdo, $seeded) {
    foreach ($seeded as $id => [$ref, $local]) {
        $min = (int)$pdo->query("SELECT sal_min FROM job_postings WHERE id = $id")->fetchColumn();
        [, $h] = se_get($base, $P . '?rand=' . rawurlencode($ref) . '&g=0' . ($local ? '&nivel=local' : ''));
        assert_same($min, se_gross(se_block($h, 'law'))[0], "posting $id");
        // The detail page links to exactly that URL.
        $row = $pdo->query("SELECT id, title FROM job_postings WHERE id = $id")->fetch();
        [, $d] = se_get($base, job_url($row));
        assert_contains('data-salary-estimator-link', $d, "posting $id: the link");
        assert_contains(htmlspecialchars(salary_est_row_url($ref, 0, $local)), $d, "posting $id: the URL");
    }
});

run_suite('an unknown row and bad input answer a page, not an error', function () use ($base, $P) {
    foreach (['?fn=nope', '?rand=nope', '?fn=L153-t1!1&g=x&cap=abc&nivel=zzz', '?q[]=a'] as $u) {
        [$st, $h] = se_get($base, $P . $u);
        assert_true(in_array($st, [200, 400], true), "$u answers 200 or 400");
        assert_not_contains('Warning', $h, "$u: no warning");
        assert_not_contains('Fatal', $h, "$u: no fatal");
    }
});

/** The hrefs of the links that carry an attribute, e.g. data-salary-level="PL", decoded. */
function se_links(string $html, string $attr): array {
    preg_match_all('#<a [^>]*href="([^"]*)"[^>]*' . preg_quote($attr, '#') . '[^>]*>#', $html, $m);
    return array_map('html_entity_decode', $m[1]);
}

/** The text of the links with an attribute, whitespace collapsed. */
function se_link_texts(string $html, string $attr): array {
    preg_match_all('#<a [^>]*' . preg_quote($attr, '#') . '[^>]*>(.*?)</a>#s', $html, $m);
    return array_map(fn($t) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('/<[^>]+>/', ' ', $t))))), $m[1]);
}

run_suite('wizard helpers: the studies levels of a grid value', function () {
    assert_same(['PL', 'M'], salary_est_study_levels('PL/M'), 'PL/M');
    assert_same(['M', 'G'], salary_est_study_levels('M/G'), 'M/G');
    assert_same(['M', 'G'], salary_est_study_levels('M.G'), 'M.G');
    assert_same(['M', 'PL'], salary_est_study_levels('M, PL'), 'draft: M, PL');
    assert_same(['SSD'], salary_est_study_levels('SSD'), 'SSD is not S');
    assert_same(['S'], salary_est_study_levels('S + Licență'), 'S + licence');
    assert_same([], salary_est_study_levels(''), 'blank');
    assert_same([], salary_est_study_levels('Şcoală maiştri + Licenţă'), 'words are not levels');
    assert_true(salary_est_row_has_level(['studii' => ''], 'G'), 'a blank row is under every level');
    assert_true(!salary_est_row_has_level(['studii' => 'PL/M'], 'G'), 'PL/M is not under G');
});

run_suite('wizard step 1: the domains; draft-only annexes come last', function () use ($base, $P) {
    [$st, $h] = se_get($base, $P);
    assert_same(200, $st, 'status');
    assert_contains('data-salary-wizard="domain"', $h, 'step 1');
    foreach (['Sănătate și asistență socială', 'Cultură', 'Administrație și alte funcții comune'] as $label) assert_contains($label, $h, $label);
    $links = se_links($h, 'data-salary-domain');
    assert_same(['/estimator-salariu/?d=II', '/estimator-salariu/?d=III', '/estimator-salariu/?d=VII', '/estimator-salariu/?d=VIII', '/estimator-salariu/?d=VI'], $links, 'annex order, VI last');
    assert_true(strpos($h, 'Doar în proiect') < strpos($h, 'data-salary-domain="VI"'), 'the draft-only heading comes before Anexa VI');
    assert_true(strpos($h, 'data-salary-domain="VIII"') < strpos($h, 'Doar în proiect'), 'law annexes come before it');
    assert_contains('Caută după titlul postului', $h, 'the title shortcut stays at the top');
});

run_suite('wizard step 2: only the levels that the domain has, with counts', function () use ($base, $P) {
    [, $h] = se_get($base, $P . '?d=II');
    assert_contains('data-salary-wizard="studies"', $h, 'step 2');
    $texts = se_link_texts($h, 'data-salary-level');
    // Domain II: Medic specialist S; Asistent medical PL; Registrator medical PL/M; Îngrijitor M/G; Statistician has no level (every level).
    assert_same(['Superioare 2 funcții', 'Postliceale 3 funcții', 'Medii 3 funcții', 'Generale 2 funcții'], $texts, 'levels and counts');
    assert_not_contains('data-salary-level="SSD"', $h, 'no SSD in domain II');
    [, $h] = se_get($base, $P . '?d=III');
    assert_same(['Superioare 1 funcție'], se_link_texts($h, 'data-salary-level'), 'domain III has only S');
    [, $h] = se_get($base, $P . '?d=VI');
    assert_same(['Medii 1 funcție'], se_link_texts($h, 'data-salary-level'), 'a draft-only domain');
    // An annex whose rows name no studies skips step 2: its functions are listed at once, without `st`.
    [, $h] = se_get($base, $P . '?d=VII');
    assert_contains('data-salary-wizard="function"', $h, 'Anexa VII: the list');
    $links = se_links($h, 'data-salary-fn');
    assert_same(1, count($links), 'one function');
    assert_not_contains('st=', $links[0], 'no studies in the link');
    [$st, $h] = se_get($base, $P . '?d=ZZ');
    assert_same(200, $st, 'unknown domain');
    assert_contains('data-salary-wizard="domain"', $h, 'an unknown domain shows step 1');
});

run_suite('wizard step 3: the functions of domain + studies; PL/M is under PL and M', function () use ($base, $P) {
    $names = fn(string $st, string $d = 'II') => se_link_texts(se_get($base, $P . "?d=$d&st=$st")[1], 'data-salary-fn');
    assert_same(['Asistent medical', 'Registrator medical', 'Statistician'], $names('PL'), 'PL');
    assert_same(['Registrator medical', 'Statistician', 'Îngrijitor'], $names('M'), 'M: PL/M and M/G');
    assert_same(['Statistician', 'Îngrijitor'], $names('G'), 'G: M/G and a blank row');
    assert_same(['Medic specialist', 'Statistician'], $names('S'), 'S');
    [, $h] = se_get($base, $P . '?d=II&st=PL');
    assert_contains('data-salary-wizard="function"', $h, 'step 3');
    assert_contains('Funcții de execuție', $h, 'grouped by post type');
    [, $h] = se_get($base, $P . '?d=II&st=G');
    assert_contains('Alte funcții', $h, 'a row without a post type is "Alte funcții"');
    // A step-3 link opens the variants and keeps the domain and the studies.
    $links = se_links(se_get($base, $P . '?d=II&st=PL')[1], 'data-salary-fn');
    assert_contains('fn=L153-t6%211', $links[1], 'link to the function');
    assert_contains('st=PL', $links[1], 'keeps the studies');
    assert_contains('d=II', $links[1], 'keeps the domain');
    // The text filter narrows the list.
    assert_same(['Registrator medical'], se_link_texts(se_get($base, $P . '?d=II&st=PL&fq=' . rawurlencode('REGISTRÁTOR'))[1], 'data-salary-fn'), 'filter, no case, no diacritics');
    [, $h] = se_get($base, $P . '?d=II&st=PL&fq=zzzz');
    assert_contains('data-salary-empty', $h, 'empty filter');
    // A draft-only domain lists the draft function and links to it.
    $links = se_links(se_get($base, $P . '?d=VI&st=M')[1], 'data-salary-fn');
    assert_true(isset($links[0]) && str_contains($links[0], 'b=proiect'), 'draft link');
    assert_contains('doar în proiect', se_get($base, $P . '?d=VI&st=M')[1], 'marked as draft-only');
    // The filter field shows only when the list has more than 40 items: this domain has fewer.
    assert_not_contains('id="se-fq"', se_get($base, $P . '?d=II&st=PL')[1], 'no filter field on a short list');
});

run_suite('wizard step 4 and the breadcrumb: every part links back', function () use ($base, $P) {
    [, $h] = se_get($base, $P . '?b=lege&fn=' . rawurlencode('L153-t6!1') . '&st=PL&d=II');
    assert_contains('data-salary-step="2"', $h, 'variants');
    assert_same(4500, se_gross(se_block($h, 'law'))[0], 'the figure');
    preg_match('#<nav[^>]*data-salary-crumbs>(.*?)</nav>#s', $h, $m);
    $nav = $m[1] ?? '';
    assert_contains('href="/estimator-salariu/"', $nav, 'root');
    assert_contains('href="/estimator-salariu/?d=II"', $nav, 'domain');
    assert_contains('href="/estimator-salariu/?d=II&amp;st=PL"', $nav, 'studies');
    assert_contains('<li aria-current="page">Registrator medical</li>', $nav, 'the function is the current item');
    assert_contains('Sănătate și asistență socială', $nav, 'domain label');
    assert_contains('Postliceale', $nav, 'studies label');
    // The form keeps the domain and the studies.
    assert_contains('name="d" value="II"', $h, 'hidden d');
    assert_contains('name="st" value="PL"', $h, 'hidden st');
    // The studies narrow the rows of the function; the old URL (no st) still shows all of them.
    [, $all] = se_get($base, $P . '?fn=' . rawurlencode('L153-t1!1') . '&b=lege');
    [, $one] = se_get($base, $P . '?fn=' . rawurlencode('L153-t1!1') . '&b=lege&st=M');
    assert_contains('data-salary-law', $all, 'old URL works');
    assert_same(se_gross(se_block($all, 'law')), se_gross(se_block($one, 'law')), 'a level that matches no row is ignored');
    // A direct entry has a breadcrumb from its annex, with no studies part.
    [, $d] = se_get($base, $P . '?rand=' . rawurlencode('L153-t3!1') . '&g=0');
    preg_match('#<nav[^>]*data-salary-crumbs>(.*?)</nav>#s', $d, $m);
    assert_contains('href="/estimator-salariu/?d=II"', $m[1] ?? '', 'direct entry: domain link');
    assert_not_contains('st=', $m[1] ?? '', 'direct entry: no studies');
});

run_suite('the title shortcut: titles of postings first, then the grid names', function () use ($base, $P) {
    [, $h] = se_get($base, $P . '?q=' . rawurlencode('asistent medical'));
    assert_contains('data-salary-titles', $h, 'title block');
    $links = se_links($h, 'data-salary-title-result');
    assert_same(['/estimator-salariu/?rand=L153-t3%211&g=0', '/estimator-salariu/?rand=L153-t3%211&g=0'], $links, 'two titles, both open the row');
    assert_same(['Asistent medical 12 anunțuri', 'Asistent medical generalist 5 anunțuri'], se_link_texts($h, 'data-salary-title-result'), 'by number of postings');
    assert_true(strpos($h, 'data-salary-titles') < strpos($h, 'data-salary-names'), 'titles before names');
    assert_contains('fn=L153-t3%211', $h, 'the grid names stay');
    // "inspector de specialitate" opens step 4 on its row, with the grade.
    [, $h] = se_get($base, $P . '?q=' . rawurlencode('Inspector de SPECIALITATE'));
    $links = se_links($h, 'data-salary-title-result');
    assert_same(['/estimator-salariu/?rand=L153-t8%211&g=0'], $links, 'inspector de specialitate');
    [, $r] = se_get($base, $links[0]);
    assert_contains('data-salary-step="2"', $r, 'opens the variants');
    assert_contains('<option value="gradul II" selected>', $r, 'the grade of the title');
    assert_same([6100, 6100], se_gross(se_block($r, 'law')), 'its figure, at the grade of the title (not gradul I: 6.500)');
    // A word that is in no title still finds the grid name; a nonsense word finds nothing.
    [, $h] = se_get($base, $P . '?q=soldat');
    assert_not_contains('data-salary-titles', $h, 'no title block without a title');
    assert_contains('Numai în proiect', $h, 'the grid name');
    assert_true(count(salary_est_title_search('asistent', 30)['items']) <= 30, 'at most 30 titles');
});

run_suite('the INS line: country, county, none', function () use ($base, $P, $db_path) {
    $u = $P . '?rand=' . rawurlencode('L153-t3!1') . '&g=0';
    [, $h] = se_get($base, $u);
    preg_match('#<p[^>]*data-salary-ins>(.*?)</p>#s', $h, $m);
    $line = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1] ?? ''))));
    assert_contains('Context INS: câștigul salarial mediu net lunar în sănătate și asistență socială, România, mai 2026: 5.417 lei (TEMPO FOM106G).', $line, 'national');
    assert_contains('mai mare decât un salariu de bază la gradația 0', $line, 'the seniority sentence');
    assert_contains('href="https://ins.gov2.ro/dataset-v2.html?code=FOM106G"', $h, 'link to the matrix');
    assert_true(strpos($h, 'data-salary-ins') > strpos($h, 'data-salary-draft'), 'under the two pay boxes');
    [, $hc] = se_get($base, $u . '&jud=Cluj');
    preg_match('#<p[^>]*data-salary-ins>(.*?)</p>#s', $hc, $m);
    $line = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1] ?? ''))));
    assert_contains('județul Cluj, anul 2024: 5.703 lei (TEMPO FOM106E, FOM104F).', $line, 'county');
    assert_contains('<option value="Cluj" selected>', $hc, 'the county is selected');
    assert_same(se_gross(se_block($h, 'law')), se_gross(se_block($hc, 'law')), 'the county does not change the estimate');
    assert_same(se_gross(se_block($h, 'draft')), se_gross(se_block($hc, 'draft')), 'nor the draft');
    [, $hb] = se_get($base, $u . '&jud=' . rawurlencode('București'));
    assert_contains('municipiul București, anul 2024: 6.231 lei', html_entity_decode(strip_tags($hb)), 'Bucharest');
    // A county with no row is not an option: the line is the national one.
    [, $ha] = se_get($base, $u . '&jud=Alba');
    assert_contains('România, mai 2026', html_entity_decode(strip_tags($ha)), 'an unknown county falls back to the country');
    assert_not_contains('value="Alba"', $ha, 'no Alba option');
    // The select lists only the counties of the sector.
    assert_contains('<label class="g2-label" for="se-jud">Județul (pentru context)</label>', $h, 'the county select');
    // No sector rows (Anexa III, CAEN R): no line, no select.
    [, $h3] = se_get($base, $P . '?rand=' . rawurlencode('L153-t4!1'));
    assert_not_contains('data-salary-ins', $h3, 'no INS row for the domain: no line');
    assert_not_contains('se-jud', $h3, 'and no county select');
    // A sector with a national row but no county rows (O): the line is national and there is no select.
    [, $h8] = se_get($base, $P . '?rand=' . rawurlencode('L153-t5!1') . '&jud=Cluj');
    assert_contains('administrația publică și apărare, România, mai 2026: 6.900 lei', html_entity_decode(strip_tags($h8)), 'Anexa VIII');
    assert_not_contains('se-jud', $h8, 'no county options for O');
    // The helper: a county without a row gives no line (a choice with no INS row).
    assert_same(null, salary_est_ins_line('VIII', 'Cluj'), 'no row for the choice: null');
    assert_same(null, salary_est_ins_line('VII', ''), 'Anexa VII has no sector');
    assert_same(5417, salary_est_ins_line('II', '')['value'], 'national value');
});

run_suite('an export without salary_title and salary_ins_context still works', function () use ($db_path) {
    $copy = $db_path . '.nonew';
    copy($db_path, $copy);
    $c = new PDO('sqlite:' . $copy);
    $c->exec('DROP TABLE salary_title');
    $c->exec('DROP TABLE salary_ins_context');
    $c = null;
    $s = start_fixture_server($copy);
    [$st, $h] = se_get($s['base'], '/estimator-salariu/?q=' . rawurlencode('asistent medical'));
    assert_same(200, $st, 'status');
    assert_not_contains('data-salary-titles', $h, 'no title results');
    assert_contains('data-salary-names', $h, 'the grid names remain');
    [$st, $h] = se_get($s['base'], '/estimator-salariu/?rand=' . rawurlencode('L153-t3!1') . '&jud=Cluj');
    assert_same(200, $st, 'the result');
    assert_same([5474, 5474], se_gross(se_block($h, 'law')), 'the estimate is the same');
    assert_not_contains('data-salary-ins', $h, 'no INS line');
    assert_not_contains('se-jud', $h, 'no county select');
    foreach (['', '?d=II', '?d=II&st=PL'] as $u) {
        [$st, $h] = se_get($s['base'], '/estimator-salariu/' . $u);
        assert_same(200, $st, "$u: the wizard works");
        assert_not_contains('Warning', $h, "$u: no warning");
    }
    stop_fixture_server($s, $copy);
});

run_suite('an export without the estimator tables says so, with HTTP 200', function () use ($db_path) {
    $copy = $db_path . '.old';
    copy($db_path, $copy);
    $c = new PDO('sqlite:' . $copy);
    foreach (SALARY_EST_TABLES as $t) $c->exec("DROP TABLE $t");
    $c = null;
    $s = start_fixture_server($copy);
    [$st, $h] = se_get($s['base'], '/estimator-salariu/?rand=L153-t1!1');
    assert_same(200, $st, 'status');
    assert_contains('Estimatorul nu este disponibil acum.', $h, 'message');
    [$st] = se_get($s['base'], '/job/1001-asistent-medical-generalist/');
    assert_same(200, $st, 'the detail page still works');
    stop_fixture_server($s, $copy);
});

stop_fixture_server($srv, $db_path);
finish();
