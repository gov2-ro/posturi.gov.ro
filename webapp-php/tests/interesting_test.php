<?php
/**
 * UX-INTERESTING — the filter `interes[]` ("Posturi interesante"): the predicate of each value, the facet
 * count against the list count, the chip, the detail-page line, the home cards and the link to /anomalii/.
 *
 * Fixture: tests/fixtures/interesting_db.php. INTERESTING_CASES lists, for each posting of the cases,
 * the values it is in. A second database has no anomaly tables (phase B values must not show).
 *
 * Usage: php tests/interesting_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/interesting_db.php';
require_once __DIR__ . '/fixtures/server.php';
require_once __DIR__ . '/fixtures/page_helpers.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_a = sys_get_temp_dir() . '/posturi-interes-a-' . getmypid() . '.sqlite';
$db_b = sys_get_temp_dir() . '/posturi-interes-b-' . getmypid() . '.sqlite';
foreach ([[$db_a, true], [$db_b, false]] as [$path, $anomalies]) {
    build_fixture_db($path);
    fixture_seed_interesting(new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), $anomalies);
}
putenv('POSTURI_DB=' . $db_a);   // this process reads db_a through db()
$srv = start_fixture_server($db_a, '2026-10-03');
$srv_old = start_fixture_server($db_b, '2026-10-03');
$base = $srv['base'];

/** Ids (all postings) in one `interes` value, through the filter itself. */
function in_value(string $value): array {
    $f = build_filters(['status' => 'all', 'interes' => [$value]]);
    $st = db()->prepare('SELECT j.id FROM job_postings j WHERE ' . implode(' AND ', $f['where'] ?: ['1']) . ' ORDER BY j.id');
    $st->execute($f['binds']);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
/** The count next to a value in the filter group of a page. */
function facet_count(string $html, string $value): ?int {
    return preg_match('#name="interes\[\]" value="' . preg_quote($value, '#') . '".{0,700}?font-mono text-xs text-ink-muted">(\d+)</span>#s', $html, $m) ? (int)$m[1] : null;
}
$url = fn(string $value) => '/?interes%5B%5D=' . $value;

run_suite('the predicate of each value, on the cases', function () {
    $got = [];
    foreach (interes_values() as $v) {
        foreach (in_value($v) as $id) if ($id >= 3000 && $id < 4000) $got[$id][] = $v;
    }
    foreach (INTERESTING_CASES as $id => $want) {
        assert_same($want, $got[$id] ?? [], "posting $id");
    }
    foreach ($got as $id => $vals) {
        assert_true(isset(INTERESTING_CASES[$id]), "posting $id is in " . implode(',', $vals) . ' and has no case');
    }
    // The filler postings (4000..) are in no value.
    foreach (interes_values() as $v) {
        assert_same([], array_values(array_filter(in_value($v), fn($i) => $i >= 4000)), "no filler in $v");
    }
    // The pay level: 20.000 is 5, 6.400 is 4, 100 is 1.
    foreach ([3020 => 5, 3025 => 4, 3023 => 1] as $id => $level) {
        $row = db()->query("SELECT * FROM job_postings WHERE id = $id")->fetch();
        assert_same($level, pay_level($row)['level'] ?? null, "pay level of $id");
    }
});

run_suite('an unknown value adds no clause; the values of one group combine with OR', function () {
    $all = build_filters(['status' => 'all']);
    $unk = build_filters(['status' => 'all', 'interes' => ['nu-exista', ['x']]]);
    assert_same($all['where'], $unk['where'], 'unknown values');
    $a = in_value('multe-posturi');
    $b = in_value('debutant-platit');
    $f = build_filters(['status' => 'all', 'interes' => ['multe-posturi', 'debutant-platit']]);
    $st = db()->prepare('SELECT j.id FROM job_postings j WHERE ' . implode(' AND ', $f['where']) . ' ORDER BY j.id');
    $st->execute($f['binds']);
    $got = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $want = array_values(array_unique(array_merge($a, $b)));
    sort($want);
    assert_same($want, $got, 'OR of two values');
});

run_suite('the facet count of each value is the result count of its list', function () use ($base, $url) {
    [, $list] = http_page($base, '/posturi/');
    assert_contains('Posturi interesante', $list, 'the group');
    foreach (interes_values() as $v) {
        $in_list = list_count($base, $url($v));
        $n = facet_count($list, $v);
        if ($in_list <= 0) { assert_same(null, $n, "no count for $v when its list is empty"); continue; }
        assert_same($in_list, $n, "facet count of $v");
    }
    assert_same(5, count(interes_values()), 'five values, phase B included');
});

run_suite('the chip carries the sentence as its title', function () use ($base, $url) {
    [, $html] = http_page($base, $url('multe-posturi'));
    assert_contains('data-chip-param="interes"', $html, 'chip');
    assert_contains('data-chip-value="multe-posturi"', $html, 'chip value');
    assert_contains('title="' . e(interes_rule('multe-posturi')) . '"', $html, 'chip title');
    assert_contains('Multe posturi la același anunț', $html, 'chip label');
});

run_suite('the detail page has one line for each value that holds', function () use ($base) {
    $h = (string)@file_get_contents($base . '/job/3010/');
    assert_contains('data-interesting="multe-posturi"', $h, 'line');
    assert_contains('5 posturi la același anunț.', $h, 'sentence');
    assert_contains('Interesant:', $h, 'prefix');
    assert_contains('href="/?interes%5B%5D=multe-posturi"', $h, 'link to the filter');
    $h = (string)@file_get_contents($base . '/job/3013/');
    assert_contains('6 posturi la același anunț.', $h, 'a multi-role posting: the vacancies of one role');
    $h = (string)@file_get_contents($base . '/job/3001/');
    assert_contains('data-interesting="salariu-cerinte-mici"', $h, 'value 1');
    assert_contains('fără experiență cerută.', $h, 'value 1, no experience');
    $h = (string)@file_get_contents($base . '/job/3002/');
    assert_contains('cu studii medii.', $h, 'value 1, medium studies');
    $h = (string)@file_get_contents($base . '/job/3025/');
    assert_contains('Post de debutant cu salariu în treapta 4 din 5.', $h, 'value 4');
    $h = (string)@file_get_contents($base . '/job/3030/');
    assert_contains('data-interesting="greu-de-ocupat"', $h, 'value 2');
    assert_contains('Același angajator a publicat același titlu și pe 15.08.2026.', $h, 'value 2 sentence');
    $h = (string)@file_get_contents($base . '/job/3033/');
    assert_contains('Semnal nerevizuit de o persoană.', $h, 'value 5 caveat');
    $h = (string)@file_get_contents($base . '/job/3032/');
    assert_not_contains('data-interesting', $h, 'a false positive is not shown');
    $h = (string)@file_get_contents($base . '/job/3004/');
    assert_not_contains('data-interesting', $h, 'a posting in no value');
});

run_suite('the home page: a card for each value with 5 or more postings, the count of its list, the link to /anomalii/', function () use ($base, $url) {
    [, $h] = http_page($base, '/');
    $x = xp($h);
    foreach (interes_values() as $v) {
        $links = $x->query('//li[@data-home-example]//a[contains(@href, "interes%5B%5D=' . $v . '")]');
        $n = list_count($base, $url($v));
        if ($n >= 5) {
            assert_same(1, $links->length, "card of $v");
            $count = $x->query('//li[@data-home-example][.//a[contains(@href, "interes%5B%5D=' . $v . '")]]//*[@data-example-count]')->item(0);
            assert_same($n, paren_number((string)$count->textContent), "count of the card of $v");
            assert_contains('text-base', (string)$links->item(0)->getAttribute('class'), "base font size of $v");
        } else {
            assert_same(0, $links->length, "no card of $v with $n postings");
        }
    }
    assert_true(list_count($base, $url('multe-posturi')) >= 5, 'the fixture gives multe-posturi 5 postings');
    $a = $x->query('//p[@data-home-anomalies-link]//a[@href="/anomalii/"]');
    assert_same(1, $a->length, 'the link to /anomalii/');
    assert_contains('Vezi și:', (string)$x->query('//p[@data-home-anomalies-link]')->item(0)->textContent, 'its words');
    assert_contains('anomalii și abateri statistice', (string)$a->item(0)->textContent, 'its link text');
    // The card comes after "Primul job".
    $titles = array_map(fn($n) => trim($n->textContent), iterator_to_array($x->query('//li[@data-home-example]//h3/a')));
    $i = array_search('Primul job: fără experiență cerută', $titles, true);
    assert_true($i !== false && ($titles[$i + 1] ?? '') === INTERES_LABELS['salariu-cerinte-mici'] || ($titles[$i + 1] ?? '') === INTERES_LABELS['multe-posturi'],
        'the interesting cards follow "Primul job"');
});

run_suite('an export without the anomaly tables: no phase B value', function () use ($srv_old) {
    $b = $srv_old['base'];
    [, $list] = http_page($b, '/posturi/');
    assert_contains('Multe posturi la același anunț', $list, 'phase A shows');
    assert_not_contains('value="greu-de-ocupat"', $list, 'no value 2');
    assert_not_contains('value="competente-rare"', $list, 'no value 5');
    assert_same(list_count($b, '/posturi/'), list_count($b, '/?interes%5B%5D=greu-de-ocupat'), 'a phase B value is ignored');
    $h = (string)@file_get_contents($b . '/job/3030/');
    assert_not_contains('data-interesting', $h, 'no line');
});

run_suite('a saved search keeps the parameter as a multi-value key', function () {
    assert_contains('interes%5B%5D=multe-posturi', search_key(['interes' => ['multe-posturi']]), 'search key');
});

stop_fixture_server($srv_old, $db_b);
stop_fixture_server($srv, $db_a);
finish();
