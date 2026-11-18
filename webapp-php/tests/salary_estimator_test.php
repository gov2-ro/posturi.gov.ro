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
