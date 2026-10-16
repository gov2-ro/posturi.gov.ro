<?php
/**
 * UX-03 (D14): postings that expired or were cancelled in the last 90 days keep a
 * slim, unindexed page with similar open jobs; nothing else sees them.
 *
 * Usage: php tests/archive_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');
$db_path = sys_get_temp_dir() . '/posturi-archive-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
$srv = start_fixture_server($db_path);
$base = $srv['base'];

function arch_get(string $base, string $path): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0]]);
    $body = (string)@file_get_contents($base . $path, false, $ctx);
    $status = 0; $h = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) $status = (int)$m[1];
        elseif (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))] = trim($v); }
    }
    return [$status, $body, $h];
}

run_suite('an expired posting keeps a page: what it was, that it closed, similar open jobs', function () use ($base) {
    [$st, $html] = arch_get($base, '/job/9001-asistent-medical-principal/');
    assert_same(200, $st, 'archived page answers 200');
    assert_contains('Anunț expirat', $html, 'says it expired');
    assert_contains('<meta name="robots" content="noindex">', $html, 'never indexed');
    assert_contains('Spitalul Clinic Județean Cluj', $html, 'employer kept');
    assert_contains('4.800 lei (estimat)', $html, 'pay kept');
    assert_contains('<strong>asistent medical principal</strong>', $html, 'summary rendered as Markdown');
    assert_contains('Posturi asemănătoare, deschise acum', $html, 'similar jobs section');
    assert_contains('href="/job/1001-asistent-medical-generalist/"', $html, 'an open posting of the same occupation');
    assert_not_contains('href="/job/1003-', $html, 'a closed one is not offered');
});

run_suite('a cancelled posting says so; hostile summary text is sanitised', function () use ($base) {
    [$st, $html] = arch_get($base, '/job/9002-referent-anulat/');
    assert_same(200, $st, 'answers 200');
    assert_contains('Anunț anulat', $html, 'says it was cancelled');
    assert_contains('a anulat acest concurs', $html, 'explains the cancellation');
    assert_not_contains('<script>alert(1)</script>', $html, 'no script survives');
    assert_contains('href="/job/1002-referent-debutant/"', $html, 'similar open referent');
});

run_suite('a stale slug redirects; an unknown id is a real 404 with the layout', function () use ($base) {
    [$st, , $h] = arch_get($base, '/job/9001-titlu-vechi/');
    assert_same(301, $st, 'stale slug redirects');
    assert_same('/job/9001-asistent-medical-principal/', $h['location'] ?? '', 'to the canonical archived URL');
    [$st, $html, $h] = arch_get($base, '/job/987654/');
    assert_same(404, $st, 'unknown id 404s');
    assert_contains('Anunțul nu mai este în baza noastră', $html, 'with an explanation');
    assert_contains('<meta name="robots" content="noindex">', $html, 'not indexed');
    assert_same('no-store', $h['cache-control'] ?? '', 'and never cached');
});

run_suite('archived postings stay out of lists, feeds and the sitemap', function () use ($base) {
    foreach (['/?status=all', '/?status=closed', '/posturi.json?status=all', '/posturi.atom', '/sitemap.xml'] as $path) {
        [, $body] = arch_get($base, $path);
        assert_not_contains('/job/9001', $body, "$path: no 9001");
        assert_not_contains('fixture-9001', $body, "$path: no source URL of 9001");
    }
});

run_suite('a saved posting that was archived answers with its state, not "unavailable"', function () use ($base) {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true,
        'header' => 'Content-Type: application/json', 'content' => '{"ids":[1001,9001,9002,987654]}']]);
    $j = json_decode((string)@file_get_contents($base . '/preferinte-posturi.json', false, $ctx), true);
    $by = [];
    foreach ($j['items'] ?? [] as $i) $by[$i['id']] = $i;
    assert_same(['1001', '9001', '9002'], array_map('strval', array_keys($by)), 'live and archived answer; unknown stays absent');
    assert_same('expired', $by[9001]['status'] ?? null, 'expired state');
    assert_same('cancelled', $by[9002]['status'] ?? null, 'cancelled state');
    assert_same('/job/9001-asistent-medical-principal/', $by[9001]['path'] ?? null, 'links the archived page');
    assert_same('https://posturi.gov.ro/anunt/fixture-9001', $by[9001]['url'] ?? null, 'identity is the source url');
});

run_suite('an export without the archive table: every missing id is a plain 404', function () use ($db_path) {
    $old = sys_get_temp_dir() . '/posturi-archive-old-' . getmypid() . '.sqlite';
    copy($db_path, $old);
    (new PDO('sqlite:' . $old))->exec('DROP TABLE archived_postings');
    $s = start_fixture_server($old);
    [$st] = arch_get($s['base'], '/job/9001-asistent-medical-principal/');
    assert_same(404, $st, 'no archive, no archived page');
    [$st] = arch_get($s['base'], '/job/1001-asistent-medical-generalist/');
    assert_same(200, $st, 'live postings unaffected');
    stop_fixture_server($s, $old);
});

stop_fixture_server($srv, $db_path);
finish();
