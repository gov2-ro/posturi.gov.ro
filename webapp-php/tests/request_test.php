<?php
/**
 * FIX-07 — request validation and route tests.
 *
 * Every public route and feed is exercised against the fixture database on
 * the built-in router: malformed query shapes get a controlled 400 (the old
 * ?q[]=medic 500), valid shapes keep working (repeated facets, legacy scalar
 * facets, punctuation-only and Unicode searches), filtering/facet parity,
 * chips, pagination, sort, canonical redirects and old URL forms. Feed
 * formats are parsed, not just fetched. See
 * docs/specs/2026-10-03-project-audit/07-php-request-validation.md.
 *
 * Usage: php tests/request_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-request-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
$srv = start_fixture_server($db_path);
$base = $srv['base'];

/** GET a path; returns [status, body, headers]. Redirects are NOT followed. */
function fetch_page(string $base, string $path): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0]]);
    $body = (string)@file_get_contents($base . $path, false, $ctx);
    $status = 500;
    $headers = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
        if (preg_match('#^([^:]+):\s*(.*)$#', $h, $m)) $headers[strtolower($m[1])] = $m[2];
    }
    return [$status, $body, $headers];
}

run_suite('malformed query shapes return a controlled 400', function () use ($base) {
    $cases = [
        '/?q%5B%5D=medic'              => 'array for scalar q',
        '/?sort%5B%5D=x'               => 'array for scalar sort',
        '/?status%5B%5D=active'        => 'array for scalar status',
        '/?q%5Ba%5D=1'                 => 'nested array for q',
        '/?judet%5Ba%5D%5Bb%5D=cluj'   => 'nested facet array',
        '/?q=' . urlencode(str_repeat('x', 301)) => 'oversized q',
        '/?judet%5B%5D=' . urlencode(str_repeat('x', 301)) => 'oversized facet value',
    ];
    foreach ($cases as $path => $label) {
        [$status, $body] = fetch_page($base, $path);
        assert_same(400, $status, "$label — HTTP 400");
        assert_not_contains('Fatal error', $body, "$label — no fatal");
        assert_not_contains('trim()', $body, "$label — no TypeError leak");
    }
    // Feeds share the decoder and answer in JSON.
    foreach (['/posturi.json?q%5B%5D=medic', '/posturi.atom?q%5B%5D=medic', '/posturi.ics?q%5B%5D=medic'] as $path) {
        [$status, $body, $headers] = fetch_page($base, $path);
        assert_same(400, $status, "$path — HTTP 400");
        assert_true(str_starts_with($headers['content-type'] ?? '', 'application/json'), "$path — JSON error type");
        $decoded = json_decode($body, true);
        assert_true(is_array($decoded) && isset($decoded['error']), "$path — error key");
    }
    // The error body never reflects raw input.
    [, $body] = fetch_page($base, '/?q%5B%5D=medic');
    assert_not_contains('medic', $body, 'error page does not echo input');
});

run_suite('valid and legacy query shapes keep working', function () use ($base) {
    foreach ([
        '/?q=medic'                                     => 'plain search',
        '/?q=' . urlencode('!@#$%^&*()_+-=[]{};:,.<>?')  => 'punctuation-only search',
        '/?q=' . urlencode('îngrijitor școală')          => 'Unicode search',
        '/?q=' . urlencode('<script>alert(1)</script>')  => 'markup in search',
        '/?judet%5B%5D=cluj&judet%5B%5D=bacau'           => 'repeated facets',
        '/?eqf=6'                                        => 'legacy scalar facet',
        '/?judet=cluj'                                   => 'legacy scalar judet',
        '/?status=all'                                   => 'old status=all link',
        '/?status=necunoscut'                            => 'unknown status falls back',
        '/?page=2'                                       => 'page two',
        '/?page=abc'                                     => 'non-numeric page',
        '/?page=99999'                                   => 'huge page clamps',
        '/?sort=deadline'                                => 'deadline sort',
        '/?sort=necunoscut'                              => 'unknown sort falls back',
        '/?expires_before=2026-10-20'                    => 'date filter',
        '/?expires_before=not-a-date'                    => 'invalid date ignored',
        '/?skill%5B%5D=Excel&skill_mode=all'             => 'skill any/all',
        '/?employer=spitalul-clinic-judetean-cluj'       => 'employer scope',
        '/?employer_id=1'                                => 'legacy employer id',
        '/?utm_source=test'                              => 'unknown params ignored',
    ] as $path => $label) {
        [$status, $body] = fetch_page($base, $path);
        assert_same(200, $status, "$label — 200");
        assert_not_contains('Fatal error', $body, "$label — no fatal");
    }
    // Markup in search is escaped, not rendered.
    [, $body] = fetch_page($base, '/?q=' . urlencode('<script>alert(1)</script>'));
    assert_not_contains('<script>alert(1)</script>', $body, 'search markup escaped');
});

run_suite('all public routes answer', function () use ($base) {
    foreach (['/', '/angajatori', '/angajatori/', '/angajator/spitalul-clinic-judetean-cluj/', '/statistici', '/despre', '/robots.txt', '/sitemap.xml'] as $path) {
        [$status, $body] = fetch_page($base, $path);
        assert_same(200, $status, "$path — 200");
        assert_not_contains('Fatal error', $body, "$path — no fatal");
    }
    foreach (['/nope', '/job/999999/', '/job/999999-altceva/'] as $path) {
        [$status] = fetch_page($base, $path);
        assert_same(404, $status, "$path — 404");
    }
    // Canonical redirect: the id resolves, a wrong slug 301s to the canonical URL.
    [$status, $body, $headers] = fetch_page($base, '/job/1001-un-slug-gresit/');
    assert_same(301, $status, 'wrong slug — 301');
    assert_contains('/job/1001-asistent-medical-generalist/', $headers['location'] ?? '', 'canonical location');
    [$status] = fetch_page($base, '/job/1001/');
    assert_same(301, $status, 'bare id — 301 to canonical');
    [$status] = fetch_page($base, '/job/1001-asistent-medical-generalist/');
    assert_same(200, $status, 'canonical URL — 200');
});

run_suite('direct database access stays denied', function () use ($base) {
    foreach (['/posturi.sqlite', '/POSTURI.SQLITE', '/posturi.sqlite-wal'] as $path) {
        [$status] = fetch_page($base, $path);
        assert_same(403, $status, "$path — 403");
    }
});

run_suite('feeds parse as their formats and respect filters', function () use ($base) {
    // Atom is well-formed XML with entries.
    [$status, $atom, $headers] = fetch_page($base, '/posturi.atom');
    assert_same(200, $status, 'atom — 200');
    assert_true(str_starts_with($headers['content-type'] ?? '', 'application/atom+xml'), 'atom content type');
    $xml = new DOMDocument();
    assert_true(@$xml->loadXML($atom) !== false, 'atom parses as XML');
    assert_true($xml->getElementsByTagName('entry')->length > 0, 'atom has entries');

    // JSON decodes with count/results.
    [$status, $json_body] = fetch_page($base, '/posturi.json');
    assert_same(200, $status, 'json — 200');
    $json = json_decode($json_body, true);
    assert_true(is_array($json) && isset($json['count'], $json['results']), 'json has count/results');
    assert_true(count($json['results']) > 0, 'json has rows');

    // iCal parses structurally.
    [$status, $ics] = fetch_page($base, '/posturi.ics');
    assert_same(200, $status, 'ics — 200');
    assert_contains('BEGIN:VCALENDAR', $ics, 'ics calendar');
    assert_contains('END:VCALENDAR', $ics, 'ics end');
    assert_true(substr_count($ics, 'BEGIN:VEVENT') > 0, 'ics has events');

    // Filtered feeds match the browse selection: only Cluj postings.
    [$status, $cluj_json] = fetch_page($base, '/posturi.json?judet%5B%5D=cluj');
    $cluj = json_decode($cluj_json, true);
    assert_same(200, $status, 'filtered json — 200');
    $all_cluj = true;
    foreach ($cluj['results'] as $r) {
        if (($r['judet'] ?? '') !== 'Cluj') { $all_cluj = false; break; }
    }
    assert_true($all_cluj, 'filtered json contains only Cluj');
    assert_true(count($cluj['results']) > 0, 'filtered json non-empty');
    [$status, $cluj_atom] = fetch_page($base, '/posturi.atom?judet%5B%5D=cluj');
    assert_same(200, $status, 'filtered atom — 200');
    $xml = new DOMDocument();
    @$xml->loadXML($cluj_atom);
    foreach ($xml->getElementsByTagName('entry') as $entry) {
        assert_contains('Județ: Cluj', $entry->getElementsByTagName('summary')->item(0)?->textContent ?? '', 'atom entry scoped to Cluj');
    }
});

run_suite('filtering, facet parity, chips, pagination, sort', function () use ($base) {
    // Facet parity: the Cluj checkbox count equals the filtered result count.
    [, $home] = fetch_page($base, '/');
    preg_match('#name="judet\[\]" value="cluj"[^>]*>.*?<span[^>]*>(\d+)</span>#s', $home, $m);
    assert_true(isset($m[1]), 'cluj facet checkbox present');
    $facet_count = (int)$m[1];
    [, $filtered] = fetch_page($base, '/?judet%5B%5D=cluj');
    preg_match('#<span class="font-mono font-medium text-ink">(\d+)</span>#', $filtered, $m2);
    $result_count = (int)($m2[1] ?? -1);
    assert_same($facet_count, $result_count, 'facet count equals filtered count');

    // Chips render and each drops only itself.
    assert_contains('Județ:', $filtered, 'chip group label');
    assert_contains('Cluj', $filtered, 'chip label');
    [, $two] = fetch_page($base, '/?judet%5B%5D=cluj&judet%5B%5D=bacau');
    assert_true(substr_count($two, 'data-chip-param="judet"') >= 2, 'two judet chips');

    // Pagination: 38 postings, 25 per page → two pages; page 2 has no page-1
    // rows. The batch rows all share one published_at, so the id-DESC tie-break
    // puts 2030 on page one and 2001 as the last row of page two.
    [, $page1] = fetch_page($base, '/?status=all');
    assert_contains('pag. 1/2', $page1, 'page one of two');
    assert_contains('href="/job/1001-asistent-medical-generalist/"', $page1, 'page one has 1001');
    assert_contains('href="/job/2030-post-de-volum-30/"', $page1, 'page one has the first batch row');
    [, $page2] = fetch_page($base, '/?status=all&page=2');
    assert_contains('pag. 2/2', $page2, 'page two of two');
    assert_not_contains('href="/job/1001-asistent-medical-generalist/"', $page2, 'page two drops page-one rows');
    assert_contains('href="/job/2001-post-de-volum-1/"', $page2, 'page two has the tail row');
    // A page beyond the end clamps to the last page instead of erroring.
    [, $page9] = fetch_page($base, '/?status=all&page=9');
    assert_contains('pag. 2/2', $page9, 'oversized page clamps');

    // Sort by deadline: rows without any deadline lead (SQLite NULLs sort
    // first), then the earliest known deadline.
    [, $sorted] = fetch_page($base, '/?sort=deadline');
    $first = strpos($sorted, 'href="/job/');
    assert_true($first !== false, 'sorted list has rows');
    $first_anchor = substr($sorted, $first, 80);
    assert_contains('/job/1005-muncitor-necalificat/', $first_anchor, 'unknown deadline leads the sort');
    assert_true(
        strpos($sorted, 'href="/job/1005-muncitor-necalificat/"') < strpos($sorted, 'href="/job/1002-referent-debutant/"'),
        'earliest known deadline follows the unknown one'
    );

    // Search: FTS finds the posting whose body mentions "spital".
    [, $search] = fetch_page($base, '/?q=spital');
    assert_contains('href="/job/1001-asistent-medical-generalist/"', $search, 'FTS title/body hit');

    // HTMX partial: same results markup, only the results region.
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'header' => "HX-Request: true\r\n"]]);
    $htmx_body = (string)@file_get_contents($base . '/?judet%5B%5D=cluj', false, $ctx);
    assert_not_contains('<!doctype html>', $htmx_body, 'htmx partial has no full page');
    assert_contains('rezultate', $htmx_body, 'htmx partial carries the count');
});

stop_fixture_server($srv, $db_path);
finish();
