<?php
/**
 * UX-15 — search_key() against the shared fixture (ADR 0001).
 *
 * tests/fixtures/search_keys.json lists query strings and their keys. The
 * browser suite (tests/browser/searches.spec.js) checks searchKey() in
 * static/searches.js against the same file. A last check keeps the multi-value
 * parameter list of the JS file equal to MULTI_PARAMS.
 *
 * Usage: php tests/searches_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';

/** Parse a query string the way PHP fills $_GET: name[] and MULTI_PARAMS give arrays, the last scalar wins. */
function fixture_params(string $qs): array {
    $qs = ltrim($qs, '?');
    $out = [];
    if ($qs === '') return $out;
    foreach (explode('&', $qs) as $pair) {
        if ($pair === '') continue;
        $kv = explode('=', $pair, 2);
        $name = urldecode($kv[0]);
        $value = urldecode($kv[1] ?? '');
        $bracket = str_ends_with($name, '[]');
        if ($bracket) $name = substr($name, 0, -2);
        if ($bracket || in_array($name, MULTI_PARAMS, true)) {
            $out[$name] = array_merge((array)($out[$name] ?? []), [$value]);
        } else {
            $out[$name] = $value;
        }
    }
    return $out;
}

run_suite('search_key() matches the fixture', function () {
    $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/search_keys.json'), true);
    assert_true(count($fixture['cases']) >= 12, 'the fixture has at least 12 cases');
    foreach ($fixture['cases'] as $c) {
        assert_same($c['key'], search_key(fixture_params($c['input'])), $c['name']);
    }
});

run_suite('search_key() is stable', function () {
    $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/search_keys.json'), true);
    foreach ($fixture['cases'] as $c) {
        // The key is itself a valid query string with the same key.
        assert_same($c['key'], search_key(fixture_params($c['key'])), 'idempotent: ' . $c['name']);
    }
});

run_suite('searches.js lists the same multi-value parameters as MULTI_PARAMS', function () {
    $js = file_get_contents(__DIR__ . '/../static/searches.js');
    assert_true(preg_match('/MULTI_PARAMS = \[(.*?)\];/s', $js, $m) === 1, 'searches.js declares MULTI_PARAMS');
    preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $names);
    $a = $names[1];
    $b = MULTI_PARAMS;
    sort($a);
    sort($b);
    assert_same($b, $a, 'multi-value parameter lists');
});

finish();
