<?php
/**
 * SALARY-EU-DETAIL — the pay of a post hired for an EU or PNRR project.
 *
 * Fixture `project` (tests/fixtures/make.php): three posts that keep a grid figure and are flagged project_hire:
 * 1001 = 50 lei/oră net, 84 ore/lună; 1002 = 2.100 lei net/lună; 1003 = no pay stated.
 * The page, the list row, the feeds, the filter, the sort and the chart must never show or use the grid figure.
 *
 * Usage: php tests/project_pay_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');
const PP_TODAY = '2026-10-03';

$db_path = sys_get_temp_dir() . '/posturi-project-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
$pdo = new PDO('sqlite:' . $db_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
fixture_seed_law_estimates($pdo);
fixture_seed_project_posts($pdo);
// The feeds list the extracted facts, salary first, only for a post with an extraction: give the three one (empty).
$pdo->exec("UPDATE posting_text SET schema_json = '{}' WHERE posting_id IN (1001, 1002, 1003) AND (schema_json IS NULL OR schema_json = '')");
$grid = $pdo->query("SELECT id, sal_min FROM job_postings WHERE id IN (1001, 1002, 1003)")->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo = null;
putenv('POSTURI_DB=' . $db_path);

$srv = start_fixture_server($db_path, PP_TODAY);
$base = $srv['base'];
function pp_get(string $base, string $path): string { return (string)@file_get_contents($base . $path); }
/** The ids a JSON feed returns for a query string (status=all). */
function pp_ids(string $base, string $qs): array {
    $j = json_decode(pp_get($base, '/posturi.json?status=all&' . $qs), true);
    $ids = array_map(fn($r) => (int)$r['id'], $j['results'] ?? []);
    sort($ids);
    return $ids;
}
function pp_detail(string $base, int $id): string { return pp_get($base, "/job/$id/"); }

run_suite('project_pay_view() words the stated pay with its unit, basis and hours', function () {
    $v = project_pay_view(['project_hire' => 1, 'proj_pay_amount' => 50.0, 'proj_pay_unit' => 'ora', 'proj_pay_basis' => 'net',
                           'proj_hours_month' => 84.0, 'proj_pay_quote' => 'q']);
    assert_same('50 lei/oră net · 84 ore/lună ≈ 4.200 lei net/lună', $v['full'], 'hour + hours + basis');
    assert_same('≈ 4.200 lei net/lună', $v['month'], 'the month figure');
    assert_same('', $v['note'], 'basis stated: no note');
    $v = project_pay_view(['project_hire' => 1, 'proj_pay_amount' => 100.0, 'proj_pay_unit' => 'ora', 'proj_pay_basis' => '', 'proj_hours_month' => null]);
    assert_same('100 lei/oră', $v['full'], 'hour only: no month figure');
    assert_same(null, $v['month'], 'no hours, no month figure');
    assert_same('brut sau net nespecificat', $v['note'], 'unknown basis is said');
    $v = project_pay_view(['project_hire' => 1, 'proj_pay_amount' => 2100.0, 'proj_pay_unit' => 'luna', 'proj_pay_basis' => 'net']);
    assert_same('2.100 lei net/lună', $v['full'], 'month figure');
    assert_same(null, project_pay_view(['project_hire' => 1]), 'no pay');
    assert_same(null, project_pay_view(['project_hire' => 0, 'proj_pay_amount' => 50.0, 'proj_pay_unit' => 'ora']), 'not a project post');
});

run_suite('list_salary() of a project post: the stated figure, else the budget words, never the grid', function () {
    $s = list_salary(['project_hire' => 1, 'sal_min' => 5000, 'sal_max' => 5000, 'proj_pay_amount' => 50.0, 'proj_pay_unit' => 'ora',
                      'proj_pay_basis' => 'net', 'proj_hours_month' => 84.0]);
    assert_same('≈ 4.200 lei net/lună', $s['text'], 'month figure');
    assert_false($s['estimated'], 'not an estimate');
    $s = list_salary(['project_hire' => 1, 'sal_min' => 5000, 'sal_max' => 5000, 'proj_pay_amount' => 100.0, 'proj_pay_unit' => 'ora', 'proj_pay_basis' => '']);
    assert_same('100 lei/oră', $s['text'], 'rate only');
    $s = list_salary(['project_hire' => 1, 'sal_min' => 5000, 'sal_max' => 5000, 'inf_salary_min' => 70]);
    assert_same('salariu din bugetul proiectului', $s['text'], 'no pay: the budget words; the stray inf_salary_min is not shown');
    $s = list_salary(['project_hire' => 0, 'sal_min' => 5000, 'sal_max' => 5000]);
    assert_same(true, $s['estimated'], 'a post that is not project-hired keeps the grid figure');
    assert_same(null, pay_level(['project_hire' => 1, 'sal_min' => 5000]), 'no pay meter');
});

foreach ([1001 => 'hour', 1002 => 'month', 1003 => 'none'] as $id => $case) {
    run_suite("the detail page of a project post with $case pay: the box, no grid figure, no meter, no estimator link", function () use ($base, $id, $case, $grid) {
        $h = pp_detail($base, $id);
        assert_contains('data-pay-project', $h, 'the box');
        assert_contains('Post finanțat din fonduri europene: salariul depinde de bugetul proiectului.', $h, 'the sentence');
        foreach (['Salariu estimat', 'data-pay-level', 'pay-meter', 'data-salary-estimator-link', 'Calculează cu altă gradație',
                  'Cum a fost calculat', 'data-no-salary', 'Conform legii salarizării', 'data-pay-stated'] as $no) {
            assert_not_contains($no, $h, "no \"$no\"");
        }
        assert_not_contains(number_format((int)$grid[$id], 0, ',', '.') . ' lei', $h, 'the grid figure is not on the page');
        if ($case === 'hour') {
            assert_contains('50 lei/oră net · 84 ore/lună ≈ 4.200 lei net/lună', $h, 'rate, hours and month figure');
            assert_contains('data-pay-project-quote', $h, 'the source quote');
            assert_contains('84 ore/lună, 50 lei/oră net', $h, 'the quote text');
        } elseif ($case === 'month') {
            assert_contains('2.100 lei net/lună', $h, 'month figure');
            assert_contains('data-pay-project-quote', $h, 'the source quote');
        } else {
            assert_not_contains('data-pay-project-figure', $h, 'no figure');
            assert_not_contains('data-pay-project-quote', $h, 'no quote');
        }
    });
}

run_suite('a post that is not project-hired keeps its grid figure on the page', function () use ($base) {
    $h = pp_detail($base, 1004);
    assert_true($h !== '', 'the page of 1004 answers');
    assert_not_contains('data-pay-project', $h, 'no project box on 1004');
});

run_suite('the list row of a project post', function () use ($base) {
    $h = pp_get($base, '/?status=all');
    assert_contains('≈ 4.200 lei net/lună', $h, '1001: the stated month figure');
    assert_contains('2.100 lei net/lună', $h, '1002: the stated month figure');
    assert_contains('salariu din bugetul proiectului', $h, '1003: the budget words');
});

run_suite('the salary filter puts a project post by its stated month pay, never by the grid', function () use ($base) {
    $all = fn(string $k) => pp_ids($base, 'salary_bucket%5B%5D=' . $k);
    assert_true(in_array(1001, $all('net-4000-5000'), true), '1001 (4.200 net) is in 4.000–5.000');
    assert_true(in_array(1002, $all('net-sub-3000'), true), '1002 (2.100 net) is under 3.000');
    assert_true(in_array(1003, $all('fara-estimare'), true), '1003 (no pay) is in "fără salariu estimat"');
    foreach (['net-sub-3000', 'net-3000-4000', 'net-4000-5000', 'net-5000-7000', 'net-peste-7000', 'fara-estimare'] as $k) {
        $ids = $all($k);
        $in = array_values(array_intersect([1001, 1002, 1003], $ids));
        $want = ['net-4000-5000' => [1001], 'net-sub-3000' => [1002], 'fara-estimare' => [1003]][$k] ?? [];
        assert_same($want, $in, "project posts in $k");
    }
    assert_true(in_array(1003, pp_ids($base, 'has_salary=1'), true) === false, 'has_salary=1 leaves out a project post with no pay');
});

run_suite('the sort by pay and the chart use the same figure', function () use ($base) {
    $html = pp_get($base, '/?status=all&sort=salary');
    preg_match_all('#<h2 class="font-display[^>]*>\s*<a href="/job/(\d+)-#', $html, $m);
    $order = array_map('intval', $m[1]);
    $pos = fn(int $id) => array_search($id, $order, true);
    assert_true($pos(1001) !== false && $pos(1002) !== false, 'the two project posts with a pay are on page 1');
    assert_true($pos(1001) < $pos(1002), 'sort: 5.857 gross (4.200 net) before 2.100 net');
    assert_true($pos(1003) === false || $pos(1003) > $pos(1002), 'sort: a post with no pay comes after one with a pay');
    $dist = salary_distribution(['status' => 'all']);
    $by = array_column($dist['rows'], 'n', 'key');
    foreach ($by as $key => $n) {
        assert_same($n, count(pp_ids($base, 'salary_bucket%5B%5D=' . $key)), "chart count = filter count: $key");
    }
});

run_suite('the feeds say the same as the page', function () use ($base) {
    $atom = pp_get($base, '/posturi.atom?status=all');
    assert_contains('salariul depinde de bugetul proiectului', $atom, 'the note is in the feed');
    assert_contains('50 lei/oră net · 84 ore/lună ≈ 4.200 lei net/lună', $atom, 'the stated pay is in the feed');
    // The entries of 1001-1003 carry no grid line; the rest of the feed may.
    foreach ([1001, 1002, 1003] as $id) {
        if (!preg_match('#<entry>(?:(?!</entry>).)*?fixture-' . $id . '(?:(?!</entry>).)*?</entry>#s', $atom, $m)) { assert_true(false, "entry $id is in the feed"); continue; }
        assert_not_contains('Salariu estimat', $m[0], "entry $id: no grid estimate");
        assert_contains('salariul depinde de bugetul proiectului', $m[0], "entry $id: the note");
    }
});

stop_fixture_server($srv, $db_path);
finish();
