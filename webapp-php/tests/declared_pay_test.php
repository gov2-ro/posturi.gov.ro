<?php
/**
 * SALARY-DECLARATII — the pay from the asset declarations (ADR 0008 rule 9).
 *
 * Fixture `fixture_seed_declared_estimate()`: posting 1004 has `baza` declaratii (37 declarants, "Consilier superior",
 * median 5.950 net in 2023, factor 1,150, p25-p75 6.000-7.600 net after the factor, error 5,2 %).
 * Checked: the Romanian "de" after a count, declared_view(), the list label, the detail page (the figure, the band,
 * the gross line, no "la gradația 0", the four steps of the explainer, the link to the method), the method section on
 * /estimator-salariu/, and that a law estimate is unchanged.
 *
 * Usage: php tests/declared_pay_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-declared-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
$pdo = new PDO('sqlite:' . $db_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
fixture_seed_law_estimates($pdo);
fixture_seed_declared_estimate($pdo);
$sal_json = (string)$pdo->query("SELECT sal_json FROM posting_text WHERE posting_id = 1004")->fetchColumn();
$pdo = null;
putenv('POSTURI_DB=' . $db_path);
$srv = start_fixture_server($db_path, '2026-10-03');
$base = $srv['base'];
function dp_get(string $base, string $path): string { return (string)@file_get_contents($base . $path); }

run_suite('ro_count_de() puts "de" after 00 and 20-99', function () {
    foreach ([1 => '1 angajați', 5 => '5 angajați', 19 => '19 angajați', 20 => '20 de angajați', 37 => '37 de angajați',
              100 => '100 de angajați', 105 => '105 angajați', 119 => '119 angajați', 120 => '120 de angajați'] as $n => $want) {
        assert_same($want, ro_count_de($n, 'angajați'), "count $n");
    }
});

run_suite('declared_view() words the figures of the group', function () use ($sal_json) {
    $v = declared_view(json_decode($sal_json, true), 'Primăria Comunei Găiceana');
    assert_same('6.850', $v['estimare'], 'median x factor');
    assert_same('11.700', $v['brut'], 'the gross of 6.850 net, to 50 lei');
    assert_same('6.000', $v['p25'], 'p25');
    assert_same('7.600', $v['p75'], 'p75');
    assert_same('5.950', $v['mediana'], 'the 2023 median');
    assert_same('37 de angajați', $v['n'], 'count with "de"');
    assert_same(15, $v['crestere'], 'growth, whole percent');
    assert_same(5, $v['eroare'], 'error, whole percent');
    assert_same(null, declared_view(['baza' => 'lege_in_vigoare']), 'a law estimate is not a declared one');
    assert_same(null, declared_view(null), 'no estimate');
});

run_suite('list_salary() names the source of a declared estimate', function () {
    $s = list_salary(['sal_min' => 10221, 'sal_max' => 12973, 'sal_variant' => 'declaratii-2023']);
    assert_same('după declarații de avere', $s['kind'], 'kind');
    assert_same(true, $s['estimated'], 'still an estimate');
    $s = list_salary(['sal_min' => 5641, 'sal_max' => 7970, 'sal_variant' => 'lege153-2026']);
    assert_false(isset($s['kind']), 'a law estimate has no kind: the row says "estimat"');
});

run_suite('the list row says "după declarații de avere"', function () use ($base) {
    $h = dp_get($base, '/?status=all');
    assert_contains('(după declarații de avere)', $h, 'the label');
    assert_contains('(estimat)', $h, 'other rows keep (estimat)');
});

run_suite('the detail page: figure, band, gross, explainer', function () use ($base) {
    $h = dp_get($base, '/job/1004/');
    assert_contains('≈ 6.850 lei net', $h, 'the median is the main figure');
    assert_contains('circa 11.700 lei brut', $h, 'the gross line');
    assert_contains('Cei mai mulți primesc între 6.000 și 7.600 lei net.', $h, 'the band');
    assert_contains('Cum a fost calculat', $h, 'the disclosure');
    assert_not_contains('la gradația 0', $h, 'no gradation on a declared figure');
    assert_contains('În 2023, 37 de angajați cu funcția Consilier superior la Primăria Comunei Găiceana au trecut venitul din salariu în declarațiile de avere. Valoarea din mijloc a fost 5.950 lei net pe lună.', $h, 'step 1');
    assert_contains('a crescut cu 15% (INS)', $h, 'step 2');
    assert_contains('Aproximativ 6.850 lei net pe lună.', $h, 'step 3');
    assert_contains('Eroarea tipică a fost de 5%.', $h, 'step 4');
    assert_contains('Ce nu știm', $h, 'the limits');
    assert_contains('href="/estimator-salariu/#metoda-declaratii"', $h, 'the link to the method');
    assert_not_contains('Calculează cu altă gradație', $h, 'no estimator link for a declared figure');
});

run_suite('the method section is on /estimator-salariu/ with its anchor', function () use ($base) {
    $h = dp_get($base, '/estimator-salariu/');
    assert_contains('id="metoda-declaratii"', $h, 'the anchor');
    assert_contains('Metoda pe larg', $h, 'the heading');
    assert_contains('cel puțin 5 declaranți', $h, 'the minimum of 5');
});

run_suite('a law estimate keeps its page', function () use ($base) {
    $h = dp_get($base, '/job/1002/');
    assert_contains('la gradația 0', $h, 'the law figure keeps "la gradația 0"');
    assert_not_contains('data-pay-explainer', $h, 'no declared explainer');
});

stop_fixture_server($srv, $db_path);
finish();
