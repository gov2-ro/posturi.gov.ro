<?php
/**
 * DATA-STUDIES-NOT — "Fără licență" also removes the levels above it.
 *
 * Checked against the fixture served on the built-in router (role-case fixture):
 *   - `studies_level_not[]=licenta` removes every posting with a role at licență, master or doctorat
 *     and keeps lower levels and postings with no level;
 *   - `studies_level_not[]=doctorat` removes only doctorat; `generala` removes every posting with a level;
 *   - another `_not` group (isced) is unchanged: it removes the named value only;
 *   - the chip and the control of the filter say „Fără licență și peste” / „Fără doctorat”.
 *
 * Usage: php tests/studies_not_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-studies-not-' . getmypid() . '.sqlite';
build_fixture_db($db_path, true);
// 1007 has no level in the fixture: give it a doctorat role, to have the top of the order.
$pdo = new PDO('sqlite:' . $db_path);
$pdo->exec("UPDATE posting_roles SET study_level = 'doctorat' WHERE posting_id = 1007");
$srv = start_fixture_server($db_path);
$base = $srv['base'];

function sn_get(string $base, string $path): string {
    return (string)@file_get_contents($base . $path);
}

function sn_ids(string $base, string $qs): array {
    $ids = [];
    for ($p = 1; $p <= 6; $p++) {
        $b = sn_get($base, '/?status=all&' . $qs . '&page=' . $p);
        preg_match_all('#<h2 class="font-display[^>]*>\s*<a href="/job/(\d+)-#', $b, $m);
        foreach ($m[1] as $i) $ids[] = (int)$i;
        if (!str_contains($b, 'rel="next"')) break;
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

/** Postings that have a role at one of $levels, from the database itself. */
function sn_with_levels(PDO $pdo, array $levels): array {
    $in = implode(',', array_map(fn($l) => $pdo->quote($l), $levels));
    $ids = array_map('intval', $pdo->query("SELECT DISTINCT posting_id FROM posting_roles WHERE study_level IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
    sort($ids);
    return $ids;
}

run_suite('helpers: the order and the words', function () {
    assert_same(['licenta', 'master', 'doctorat'], studies_levels_from('licenta'), 'licență and above');
    assert_same(['doctorat'], studies_levels_from('doctorat'), 'doctorat is the top');
    assert_same(STUDIES_ORDER, studies_levels_from('generala'), 'generală removes every level');
    assert_same(['x'], studies_levels_from('x'), 'an unknown value stays alone');
    assert_same('Fără licență și peste', studies_not_label('licenta'), 'label licență');
    assert_same('Fără doctorat', studies_not_label('doctorat'), 'label doctorat');
    assert_same('Fără master și peste', studies_not_label('master'), 'label master');
    assert_same('Fără licență și peste', filter_value_label('studies_level_not', 'licenta'), 'chip label');
    assert_same('Licență', filter_value_label('studies_level', 'licenta'), 'the positive chip is unchanged');
});

run_suite('list: the ordered exclusion', function () use ($base, $pdo) {
    $all = sn_ids($base, '');
    $above = sn_with_levels($pdo, ['licenta', 'master', 'doctorat']);
    assert_true(in_array(1006, $above, true) && in_array(1007, $above, true) && in_array(1002, $above, true), 'fixture has licență, master and doctorat');
    $got = sn_ids($base, 'studies_level_not%5B%5D=licenta');
    assert_same(array_values(array_diff($all, $above)), $got, 'licență removes licență, master and doctorat');
    foreach ([1002, 1006, 1007, 1009, 1010] as $id) assert_false(in_array($id, $got, true), "$id removed");
    assert_true(in_array(1001, $got, true), 'a liceală posting stays');
    assert_true(in_array(1004, $got, true), 'a posting with no level stays');
    assert_true(in_array(1005, $got, true), 'another posting with no level stays');

    $got = sn_ids($base, 'studies_level_not%5B%5D=doctorat');
    assert_same(array_values(array_diff($all, [1007])), $got, 'doctorat removes the doctorat posting only');
    assert_true(in_array(1006, $got, true), 'master stays when only doctorat is excluded');

    $got = sn_ids($base, 'studies_level_not%5B%5D=generala');
    $with_level = sn_with_levels($pdo, STUDIES_ORDER);
    assert_same(array_values(array_diff($all, $with_level)), $got, 'generală removes every posting with a level');

    $got = sn_ids($base, 'studies_level_not%5B%5D=master&studies_level_not%5B%5D=licenta');
    assert_same(sn_ids($base, 'studies_level_not%5B%5D=licenta'), $got, 'two levels: the lower one decides');
});

run_suite('list: the other exclusions do not change', function () use ($base, $pdo) {
    // `isced_not` removes the named value only; no order is applied to it.
    $with = array_map('intval', $pdo->query("SELECT id FROM job_postings WHERE v3_isced_fields LIKE '%\"0613\"%'")->fetchAll(PDO::FETCH_COLUMN));
    $all = sn_ids($base, '');
    $got = sn_ids($base, 'isced_not%5B%5D=0613');
    assert_same(array_values(array_diff($all, $with)), $got, 'isced_not removes the value only');
});

run_suite('page: the chip and the control', function () use ($base) {
    $html = sn_get($base, '/?status=all&studies_level_not%5B%5D=licenta');
    assert_contains('Fără licență și peste', $html, 'the chip');
    assert_not_contains('Fără studii', $html, 'the old chip text is gone');
    assert_contains('title="Fără licență și peste"', $html, 'the control title');
    assert_contains('title="Fără doctorat"', $html, 'the control of the top level');
    $html = sn_get($base, '/?status=all&studies_level_not%5B%5D=doctorat');
    assert_contains('data-chip-value="doctorat"', $html, 'the doctorat chip');
    assert_contains('Fără doctorat', $html, 'the doctorat chip text');
});

stop_fixture_server($srv, $db_path);
finish();
