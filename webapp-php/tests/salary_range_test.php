<?php
/**
 * SALARY-GRAD-FOOD — the pay range of the law in force (ADR 0008 rule 11).
 *
 * The export writes pay_min/pay_max (total gross: base + food), the gradations and the food. The PHP side reads them:
 * SALARY_EXPR (filter, sort), list_salary(), pay_level(), pay_range_view() and the detail page.
 * Fixture: posting 1001 = law estimate (pay range set below); 1003 = a stated pay of 7.870 lei with an extracted
 * section "Salarizare"; the reference case is posting 21359 of the real data (low 8.808, high 10.147).
 *
 * Usage: php tests/salary_range_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-range-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
$pdo = new PDO('sqlite:' . $db_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
fixture_seed_law_estimates($pdo);
fixture_seed_pay_range($pdo);
// An export from before the columns: the same data without pay_*.
$old_path = sys_get_temp_dir() . '/posturi-range-old-' . getmypid() . '.sqlite';
copy($db_path, $old_path);
$odb = new PDO('sqlite:' . $old_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['pay_min', 'pay_max', 'pay_grad_min', 'pay_grad_max', 'pay_food_min', 'pay_food_max'] as $col) {
    $odb->exec("ALTER TABLE job_postings DROP COLUMN $col");
}
$odb = null;
$pdo = null;
putenv('POSTURI_DB=' . $db_path);

$srv = start_fixture_server($db_path, '2026-10-03');
$base = $srv['base'];
function sr_get(string $base, string $path): string { return (string)@file_get_contents($base . $path); }

run_suite('pay_range_view() words the range of posting 21359', function () {
    $v = pay_range_view(['pay_min' => 8808, 'pay_max' => 10147, 'pay_grad_min' => 1, 'pay_grad_max' => 5,
                         'pay_food_min' => 347, 'pay_food_max' => 347]);
    assert_same('8.808–10.147', $v['gross'], 'gross');
    assert_same(net_from_gross(8808), $v['net_min'], 'net of the total gross, low');
    assert_same(net_from_gross(10147), $v['net_max'], 'net of the total gross, high');
    assert_same('gradația 1–5', $v['grade'], 'grade');
    assert_same('both', $v['food'], 'food at both ends');
    assert_same('cu indemnizația de hrană de 347 lei', $v['food_text'], 'food words');
    $v = pay_range_view(['pay_min' => 8000, 'pay_max' => 11000, 'pay_grad_min' => 0, 'pay_grad_max' => 5,
                         'pay_food_min' => 347, 'pay_food_max' => 0]);
    assert_same('low', $v['food'], 'food at the low end only');
    assert_contains('doar la gradațiile mici', $v['food_text'], 'says "doar la gradațiile mici"');
    $v = pay_range_view(['pay_min' => 11000, 'pay_max' => 12000, 'pay_grad_min' => 5, 'pay_grad_max' => 5,
                         'pay_food_min' => 0, 'pay_food_max' => 0]);
    assert_same('', $v['food_text'], 'no food, no words');
    assert_same('gradația 5', $v['grade'], 'one gradation');
    $v = pay_range_view(['pay_min' => 4500, 'pay_max' => 8000, 'pay_grad_min' => 1, 'pay_grad_max' => null,
                         'pay_food_min' => 347, 'pay_food_max' => 0]);
    assert_same('de la gradația 1, până la plafonul legal', $v['grade'], 'a local range: the cap has no gradation');
    assert_same(null, pay_range_view([]), 'an old export');
    assert_same(null, pay_range_view(['pay_min' => null]), 'rule 11 does not apply');
    assert_same(null, pay_range_view(['pay_min' => 8808, 'project_hire' => 1]), 'a project post has no range');
});

run_suite('pay_range_view() of a stated span: no gradation claimed for an end that is not computed', function () {
    // 20202: 4.544-5.855 stated; low = gradation 0 + food, high = the stated maximum (no gradation number).
    $v = pay_range_view(['inf_salary_min' => 4544, 'inf_salary_max' => 5855, 'pay_min' => 4891, 'pay_max' => 6202,
                         'pay_grad_min' => 0, 'pay_grad_max' => null, 'pay_food_min' => 347, 'pay_food_max' => 347]);
    assert_same('', $v['grade'], 'no grade text');
    assert_same('cu indemnizația de hrană de 347 lei', $v['detail'], 'only the food');
    assert_same('4.544–5.855', $v['stated'], 'the stated span');
    $v = pay_range_view(['inf_salary_min' => 5126, 'inf_salary_max' => 6375, 'pay_min' => 5473, 'pay_max' => 6722,
                         'pay_grad_min' => 0, 'pay_grad_max' => 5, 'pay_food_min' => 347, 'pay_food_max' => 347]);
    assert_same('gradația 0–5, cu indemnizația de hrană de 347 lei', $v['detail'], 'a span that matches the table');
    $v = pay_range_view(['inf_salary_min' => 7870, 'inf_salary_max' => 7870, 'pay_min' => 8808, 'pay_max' => 10147,
                         'pay_grad_min' => 1, 'pay_grad_max' => 5, 'pay_food_min' => 347, 'pay_food_max' => 347]);
    assert_same('7.870', $v['stated'], 'a single stated figure');
});

run_suite('list_salary() and pay_level() read the range before the old figure', function () {
    $row = ['inf_salary_min' => 7870, 'inf_salary_max' => 7870, 'pay_min' => 8808, 'pay_max' => 10147];
    $s = list_salary($row);
    assert_same('Salariu anunțat', $s['label'], 'a stated pay keeps its label');
    assert_same('8.808–10.147 lei', $s['gross'], 'the gross is the range');
    assert_same('≈ ' . number_format(net_from_gross(8808), 0, ',', '.') . '–' . number_format(net_from_gross(10147), 0, ',', '.') . ' lei net', $s['text'], 'the net is the range');
    $s = list_salary(['sal_min' => 4000, 'sal_max' => 4000, 'sal_baza' => 'lege_in_vigoare', 'pay_min' => 4800, 'pay_max' => 5200]);
    assert_same('Salariu estimat', $s['label'], 'an estimate keeps its label');
    assert_same('4.800–5.200 lei', $s['gross'], 'the estimate range');
    $s = list_salary(['sal_min' => 4000, 'sal_max' => 4000, 'sal_baza' => 'lege_in_vigoare']);
    assert_same('4.000 lei', $s['gross'], 'no pay columns: the old figure');
});

run_suite('SALARY_EXPR: pay_min first, then the stated pay, then the estimate', function () use ($db_path) {
    $copy = $db_path . '.expr';   // the suite changes rows: it works on a copy
    copy($db_path, $copy);
    $pdo = new PDO('sqlite:' . $copy, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    register_sql_functions($pdo);
    $q = static fn(int $id) => (float)$pdo->query("SELECT " . SALARY_EXPR . " FROM job_postings j WHERE j.id = $id")->fetchColumn();
    assert_same(8808.0, $q(1003), 'stated pay with a range: the low end with food');
    assert_same(5000.0, $q(1001), 'estimate with a range');
    $pdo->exec("UPDATE job_postings SET pay_min = NULL WHERE id = 1001");
    assert_same((float)$pdo->query("SELECT sal_min FROM job_postings WHERE id = 1001")->fetchColumn(), $q(1001), 'no range: the estimate');
    $pdo->exec("UPDATE job_postings SET pay_min = NULL WHERE id = 1003");
    assert_same(7870.0, $q(1003), 'no range: the stated pay');
    $pdo->exec("UPDATE job_postings SET pay_min = 8808, project_hire = 1, proj_pay_amount = 2100, proj_pay_unit = 'luna', proj_pay_basis = 'brut' WHERE id = 1003");
    assert_same(2100.0, $q(1003), 'a project post: its own pay, never the range');
    $pdo = null;
    @unlink($copy);
});

run_suite('SALARY_EXPR on an export without the pay columns', function () use ($old_path) {
    $pdo = new PDO('sqlite:' . $old_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    register_sql_functions($pdo);
    shim_legacy_export($pdo);
    $v = (float)$pdo->query("SELECT " . SALARY_EXPR . " FROM job_postings j WHERE j.id = 1003")->fetchColumn();
    assert_same(7870.0, $v, 'the stated pay, as before');
    $n = (int)$pdo->query("SELECT COUNT(*) FROM job_postings j WHERE " . SALARY_EXPR . " > 0")->fetchColumn();
    assert_true($n > 0, 'the filter query runs');
});

run_suite('the detail page of a stated pay: the range, the note, no repeated "Salarizare"', function () use ($base) {
    $h = preg_replace('/\s+/', ' ', sr_get($base, '/job/1003/'));
    assert_contains('data-pay-stated', $h, 'the stated box');
    $net_lo = number_format(net_from_gross(8808), 0, ',', '.');
    $net_hi = number_format(net_from_gross(10147), 0, ',', '.');
    assert_contains("de la $net_lo la $net_hi lei net", $h, 'the net range');
    assert_contains('brut: 8.808–10.147 lei / lună, gradația 1–5, cu indemnizația de hrană de 347 lei', $h, 'the gross line');
    assert_contains('Gradația depinde de vechimea în muncă. Minimul vine din experiența cerută în anunț.', $h, 'the note');
    assert_contains('În anunț: 7.870 lei.', $h, 'the stated figure');
    assert_same(0, preg_match('/<h2[^>]*> *Salarizare/u', $h), 'the extracted section is gone (the JSON-LD keeps it)');
    assert_contains('Atribuțiile postului de test.', $h, 'other sections stay');
    if (str_contains($h, 'data-pay-law')) {
        assert_contains('Estimare după Legea 153/2017, pe aceeași bază: ' . net_label(5800, 7300) . ' (brut 5.800–7.300 lei) / lună.', $h, 'the estimate note on the same basis');
        assert_same(0, preg_match('/data-pay-law>[^<]*la gradația 0/u', $h), 'no gradation-0 estimate next to a range');
    } else {
        assert_true(false, 'fixture 1003 has no law estimate, so the note is not tested');
    }
});

run_suite('the detail page of an estimate: the range replaces "la gradația 0"', function () use ($base) {
    $h = preg_replace('/\s+/', ' ', sr_get($base, '/job/1001/'));
    assert_contains('data-pay-range-note', $h, 'the note');
    assert_contains('brut: 5.000–6.200 lei / lună, gradația 1–5, cu indemnizația de hrană de 347 lei', $h, 'the gross line');
    assert_not_contains('lei / lună, la gradația 0', $h, 'no "la gradația 0" on the figure');
});

run_suite('the detail page without a range keeps the old text, and keeps "Salarizare"', function () use ($base) {
    $h = sr_get($base, '/job/1002/');
    assert_contains('la gradația 0', $h, 'the old text');
    assert_not_contains('data-pay-range-note', $h, 'no range note');
});

run_suite('the list line reads the range: lowest low end, highest high end (rule 11)', function () use ($base, $db_path) {
    $pdo = new PDO('sqlite:' . $db_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lo = $hi = [];
    foreach ($pdo->query("SELECT pay_min, pay_max, inf_salary_min a, inf_salary_max b, sal_min c, sal_max d FROM job_postings WHERE project_hire = 0 OR project_hire IS NULL") as $r) {
        [$l, $h] = (float)$r['pay_min'] > 0 ? [(float)$r['pay_min'], (float)$r['pay_max']]
            : ((float)$r['a'] > 0 ? [(float)$r['a'], (float)$r['b']] : [(float)$r['c'], (float)$r['d']]);
        if ($l < 1000) continue;
        $lo[] = net_from_gross($l); $hi[] = net_from_gross(max($l, $h));
    }
    $pdo = null;
    $fmt = fn(float $v): string => number_format((int)round($v), 0, ',', '.');
    $h = preg_replace('/\s+/u', ' ', sr_get($base, '/posturi/?status=all'));
    assert_true(preg_match('/<span data-salary-range.*?<\/span><\/span>/u', $h, $m) === 1, 'the line is there');
    $title = $m[0] ?? '';
    $h = preg_replace('/\s+/u', ' ', strip_tags($title));
    assert_contains('salariu net ≈ ' . $fmt(min($lo)) . '–' . $fmt(max($hi)) . ' lei', $h, 'lowest low end to highest high end');
    assert_same(max($hi), net_from_gross(10147), 'the high end is the net of the range of 1003');
    assert_not_contains('la gradația 0.', $title, 'the title no longer says gradation 0');
    assert_contains('de la gradația minimă cerută la gradația 5', $title, 'the title words the range');
});

run_suite('the feed item of an estimate with a range: the wording of the detail page', function () use ($db_path) {
    $copy = $db_path . '.feed';
    copy($db_path, $copy);
    $pdo = new PDO('sqlite:' . $copy, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE posting_text SET schema_json = '{\"responsibilities\":\"Atributii.\"}' WHERE posting_id IN (1001, 1002)");
    $pdo->exec("UPDATE job_postings SET has_schema = 1 WHERE id IN (1001, 1002)");
    $pdo = null;
    $srv = start_fixture_server($copy, '2026-10-03');
    $x = preg_replace('/\s+/u', ' ', html_entity_decode((string)@file_get_contents($srv['base'] . '/posturi.rss?status=all'), ENT_QUOTES | ENT_HTML5));
    $net_lo = number_format(net_from_gross(5000), 0, ',', '.');
    $net_hi = number_format(net_from_gross(6200), 0, ',', '.');
    assert_contains("<b>Salariu estimat:</b> de la $net_lo la $net_hi lei net / lună", $x, 'the net range');
    assert_contains('<b>Brut estimat:</b> brut: 5.000–6.200 lei / lună, gradația 1–5, cu indemnizația de hrană de 347 lei', $x, 'the gross line');
    assert_not_contains("$net_hi lei net / lună, la gradația 0", $x, 'no "la gradația 0" on the range');
    assert_contains('lei brut / lună, la gradația 0', $x, 'a row with no range keeps the old text');
    stop_fixture_server($srv, $copy);
});

run_suite('an old export renders the detail page', function () use ($old_path) {
    $srv = start_fixture_server($old_path, '2026-10-03');
    $h = (string)@file_get_contents($srv['base'] . '/job/1003/');
    assert_contains('data-pay-stated', $h, 'the stated box');
    assert_not_contains('data-pay-range-note', $h, 'no range');
    stop_fixture_server($srv, $old_path);
});

stop_fixture_server($srv, $db_path);
finish();
