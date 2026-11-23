<?php
/**
 * SALARY-LABELS-LIMITS — the draft figure as a last resort, and the limits on /despre/.
 *
 * Fixture `fixture_seed_limits()`: posting 1001 has a law estimate and a draft figure (+14 %: the side note);
 * posting 1003 has only a draft figure (`sal_baza` proiect: "estimare aproximativă"); `salary_limits` has fixed numbers.
 *
 * Usage: php tests/salary_limits_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-limits-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
$pdo = new PDO('sqlite:' . $db_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
fixture_seed_law_estimates($pdo);
fixture_seed_limits($pdo);
$pdo = null;
putenv('POSTURI_DB=' . $db_path);
$srv = start_fixture_server($db_path, '2026-10-03');
$base = $srv['base'];
function sl_get(string $base, string $path): string { return (string)@file_get_contents($base . $path); }

run_suite('sal_baza() reads the column, else the variant, else the draft', function () {
    assert_same('lege_in_vigoare', sal_baza(['sal_baza' => 'lege_in_vigoare']), 'column');
    assert_same('proiect', sal_baza(['sal_baza' => 'proiect', 'sal_variant' => 'lege153-2026']), 'the column wins');
    assert_same('declaratii', sal_baza(['sal_variant' => 'declaratii-2023']), 'variant, declared');
    assert_same('lege_in_vigoare', sal_baza(['sal_variant' => 'lege153-2026']), 'variant, law');
    assert_same('proiect', sal_baza(['sal_variant' => '2026-07-17']), 'an old row is the draft');
    assert_same('proiect', sal_baza([]), 'no column, no variant');
});

run_suite('list_salary() labels a draft figure as approximate, with the tooltip', function () {
    $s = list_salary(['sal_min' => 4100, 'sal_max' => 4100, 'sal_baza' => 'proiect']);
    assert_same('estimare aproximativă', $s['kind'], 'kind');
    assert_contains('Legea în vigoare nu are un rând pentru această funcție. Cifra vine din proiectul noii legi a salarizării, care nu este adoptat.', $s['title'], 'tooltip');
    $s = list_salary(['sal_min' => 4100, 'sal_max' => 4100, 'sal_baza' => 'lege_in_vigoare']);
    assert_false(isset($s['kind']), 'a law figure is only "estimat"');
});

run_suite('sal_draft_note() words the side note for a law figure only', function () {
    $sal = ['baza' => 'lege_in_vigoare', 'proiect' => ['lei_min' => 5000, 'lei_max' => 5000]];
    $n = sal_draft_note($sal);
    assert_contains('Dacă proiectul noii legi ar fi adoptat: ~', $n, 'prefix');
    assert_contains('lei net (brut 5.000 lei), la gradația 0.', $n, 'suffix');
    assert_same('', sal_draft_note(['baza' => 'proiect', 'proiect' => ['lei_min' => 5000]]), 'not for a draft figure');
    assert_same('', sal_draft_note(['baza' => 'lege_in_vigoare']), 'no draft figure');
});

run_suite('the list row says "estimare aproximativă" for the draft figure only', function () use ($base) {
    $h = sl_get($base, '/?status=all');
    assert_contains('(estimare aproximativă)', $h, 'the label');
    assert_contains('Legea în vigoare nu are un rând pentru această funcție.', $h, 'the tooltip');
    assert_contains('(estimat)', $h, 'law rows keep (estimat)');
});

run_suite('the detail page: a draft figure is labelled, a law figure has the side note', function () use ($base) {
    $h = sl_get($base, '/job/1003/');
    assert_contains('Estimare aproximativă', $h, 'heading');
    assert_contains('data-pay-project-figure', $h, 'note');
    assert_contains('Cifra vine din proiectul noii legi a salarizării, care nu este adoptat.', $h, 'note text');
    assert_not_contains('data-pay-draft-note', $h, 'no side note on a draft figure');
    assert_not_contains('Conform legii salarizării (propunere)', $h, 'the old text is gone');
    $h = sl_get($base, '/job/1001/');
    assert_contains('data-pay-draft-note', $h, 'side note');
    assert_contains('Dacă proiectul noii legi ar fi adoptat: ~', $h, 'side note text');
    assert_contains('href="/estimator-salariu/"', $h, 'link');
    assert_not_contains('data-pay-project-figure', $h, 'a law figure is not labelled approximate');
    assert_not_contains('coeficient 0', $h, 'no "coeficient 0" for a law figure');
    assert_contains('Salariul de bază: Legea 153/2017, Anexa VIII.', $h, 'law basis step');
    assert_not_contains('Selectorul corespunde', $h, 'the grid warning is not shown');
});

run_suite('/despre/ has the owner text, the anchor and the numbers of the export', function () use ($base) {
    $h = sl_get($base, '/despre/');
    assert_contains('id="ce-nu-stim"', $h, 'anchor');
    assert_contains('Proiectul nu', $h, 'draft paragraph');
    assert_contains('cifra din proiect este în medie cu <span data-limits-gap>14</span>% mai mare', $h, 'the gap');
    assert_contains('Legea limitează totalul sporurilor la 30% pentru toată instituția', $h, 'a limit');
    assert_contains('Din 1.822 anunțuri, 44 au salariul anunțat,', preg_replace('/\s+/', ' ', $h), 'coverage N, A');
    assert_contains('120 au salariul din declarațiile de avere ale colegilor, 1.200 au o estimare', preg_replace('/\s+/', ' ', $h), 'coverage Dc, L');
    assert_contains('112 au o estimare aproximativă după proiect, iar 346', preg_replace('/\s+/', ' ', $h), 'coverage D, F');
    assert_not_contains('nu o afișează încă', $h, 'the old text is gone');
});

stop_fixture_server($srv, $db_path);
finish();
