<?php
/**
 * UX-09 — browser-local save/hide: server side.
 *
 * Covers the read-only lookup endpoint /preferinte-posturi.json (valid, missing
 * and closed ids; method, content-type, malformed, oversized, nested and
 * SQL-like input; no warnings), and the server markup the browser script hangs
 * off (identity attributes, buttons, the /salvate/ shell, no-JS wording). The
 * script's own behaviour is in tests/browser/saved.spec.js.
 *
 * Usage: php tests/preferences_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-prefs-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
$srv = start_fixture_server($db_path);
$base = $srv['base'];

/** @return array{0:int,1:array<string,string>,2:string} status, lower-cased headers, body */
function http(string $base, string $path, string $method = 'GET', ?string $body = null, array $headers = []): array {
    $opts = ['method' => $method, 'ignore_errors' => true, 'header' => implode("\r\n", $headers)];
    if ($body !== null) $opts['content'] = $body;
    $out = @file_get_contents($base . $path, false, stream_context_create(['http' => $opts]));
    $status = 0; $h = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) $status = (int)$m[1];
        elseif (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))] = trim($v); }
    }
    return [$status, $h, (string)$out];
}

function lookup(string $base, string $json, string $path = '/preferinte-posturi.json'): array {
    return http($base, $path, 'POST', $json, ['Content-Type: application/json']);
}

function xpath_for(string $html): DOMXPath {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($dom);
}

run_suite('lookup: valid ids resolve to public data; missing ids are simply absent', function () use ($base) {
    [$st, $h, $body] = lookup($base, '{"ids":[1001,1003,9999,1001]}');
    assert_same(200, $st, 'status');
    assert_contains('application/json', $h['content-type'] ?? '', 'content type');
    assert_same('no-store', $h['cache-control'] ?? '', 'Cache-Control: no-store');
    assert_false(isset($h['access-control-allow-origin']), 'no cross-origin access');
    assert_false(isset($h['set-cookie']), 'no cookies');
    $j = json_decode($body, true);
    $by = [];
    foreach ($j['items'] ?? [] as $i) $by[$i['id']] = $i;
    assert_same([1001, 1003], array_keys($by), 'only existing ids, duplicates collapsed, 9999 absent');
    $a = $by[1001] ?? [];
    assert_same(['id', 'url', 'path', 'title', 'employer', 'location', 'status', 'deadline', 'deadline_source'], array_keys($a), 'only public fields, nothing else');
    assert_same('https://posturi.gov.ro/anunt/fixture-1001', $a['url'] ?? null, 'official source url');
    assert_same('/job/1001-asistent-medical-generalist/', $a['path'] ?? null, 'canonical local path');
    assert_same('Asistent medical', $a['title'] ?? null, 'display title');
    assert_same('Spitalul Clinic Județean Cluj', $a['employer'] ?? null, 'employer');
    assert_same('Cluj-Napoca, Cluj', $a['location'] ?? null, 'location');
    assert_same('confirmed_open', $a['status'] ?? null, 'status');
    assert_same('2026-10-16', $a['deadline'] ?? null, 'resolved deadline');
    assert_same('anunt', $a['deadline_source'] ?? null, 'deadline provenance');
    // A closed row is a normal answer — no default active predicate.
    assert_same('closed', $by[1003]['status'] ?? null, 'closed row is returned');
    assert_same('2026-09-30', $by[1003]['deadline'] ?? null, 'closed row deadline');
    // Fallback provenance is preserved, never promoted to a confirmed deadline.
    $j4 = json_decode(lookup($base, '{"ids":[1004,1005]}')[2], true);
    $by4 = [];
    foreach ($j4['items'] as $i) $by4[$i['id']] = $i;
    assert_same('expirare', $by4[1004]['deadline_source'] ?? null, 'expiry fallback provenance');
    assert_true(array_key_exists('deadline', $by4[1005] ?? []) && $by4[1005]['deadline'] === null, 'unknown deadline is null');
});

run_suite('lookup: no limit/active defaults of /posturi.json, empty and boundary lists', function () use ($base) {
    $j = json_decode(lookup($base, '{"ids":[]}')[2], true);
    assert_same([], $j['items'] ?? null, 'empty list: empty items');
    $ids = range(1, 500);
    [$st] = lookup($base, json_encode(['ids' => $ids]));
    assert_same(200, $st, '500 distinct ids accepted');
    $ids[] = 501;
    [$st, , $body] = lookup($base, json_encode(['ids' => $ids]));
    assert_same(400, $st, '501 distinct ids rejected');
    assert_contains('"error"', $body, 'machine-readable error');
    [$st] = lookup($base, json_encode(['ids' => array_merge(range(1, 500), range(1, 500))]));
    assert_same(200, $st, 'duplicates do not count towards the cap');
    $all = [];
    foreach (array_merge([1001, 1002, 1003, 1004, 1005, 1006, 1007, 1008], range(2001, 2030)) as $i) $all[] = $i;
    $j = json_decode(lookup($base, json_encode(['ids' => $all]))[2], true);
    assert_same(38, count($j['items'] ?? []), 'all 38 fixture rows resolve (no 200-row/active cap logic)');
});

run_suite('lookup: other methods, content types and the query string', function () use ($base) {
    foreach (['GET', 'PUT', 'DELETE', 'OPTIONS'] as $m) {
        [$st, $h, $body] = http($base, '/preferinte-posturi.json', $m, null, ['Content-Type: application/json']);
        assert_same(405, $st, "$m is 405");
        assert_same('POST', $h['allow'] ?? null, "$m carries Allow: POST");
        assert_same('no-store', $h['cache-control'] ?? '', "$m not cached");
        assert_contains('"error"', $body, "$m JSON error");
    }
    [$st] = http($base, '/preferinte-posturi.json', 'POST', '{"ids":[1]}', ['Content-Type: text/plain']);
    assert_same(415, $st, 'text/plain rejected');
    [$st] = http($base, '/preferinte-posturi.json', 'POST', '{"ids":[1]}');
    assert_same(415, $st, 'missing content type rejected');
    [$st] = http($base, '/preferinte-posturi.json', 'POST', '{"ids":[1]}', ['Content-Type: Application/JSON; charset=utf-8']);
    assert_same(200, $st, 'content-type parameters and case are accepted');
    // The shared query decoder must not turn a lookup into an HTML 400.
    [$st, $h] = lookup($base, '{"ids":[1001]}', '/preferinte-posturi.json?q[]=x&status[]=a');
    assert_same(200, $st, 'query string is ignored');
    assert_contains('application/json', $h['content-type'] ?? '', 'still JSON');
});

run_suite('lookup: malformed, nested, mistyped and SQL-like bodies are controlled 400s', function () use ($base) {
    $bad = [
        'empty body' => '',
        'truncated' => '{"ids":[1001',
        'not json' => 'ids=1001',
        'top-level array' => '[1001]',
        'scalar' => '1001',
        'ids not a list' => '{"ids":1001}',
        'ids object' => '{"ids":{"a":1}}',
        'missing ids' => '{}',
        'extra key' => '{"ids":[1],"x":1}',
        'nested' => '{"ids":[[1001]]}',
        'nested object' => '{"ids":[{"id":1}]}',
        'string id' => '{"ids":["1001"]}',
        'boolean' => '{"ids":[true]}',
        'float' => '{"ids":[1.5]}',
        'integral float' => '{"ids":[1.0]}',
        'exponent' => '{"ids":[1e3]}',
        'null' => '{"ids":[null]}',
        'zero' => '{"ids":[0]}',
        'negative' => '{"ids":[-3]}',
        'overflow' => '{"ids":[99999999999999999999]}',
        'sql string' => '{"ids":["1 OR 1=1"]}',
        'sql stacked' => '{"ids":["1); DROP TABLE job_postings;--"]}',
        'sql quote' => '{"ids":["\' OR \'1\'=\'1"]}',
        'deep' => str_repeat('[', 50) . str_repeat(']', 50),
    ];
    foreach ($bad as $name => $body) {
        [$st, $h, $out] = lookup($base, $body);
        assert_same(400, $st, "$name -> 400");
        assert_contains('application/json', $h['content-type'] ?? '', "$name -> JSON");
        assert_true(is_array(json_decode($out, true)) && isset(json_decode($out, true)['error']), "$name -> error object");
    }
    // The table survived the stacked-statement attempts.
    $j = json_decode(http($base, '/posturi.json?status=all')[2], true);
    assert_true(($j['total'] ?? $j['count'] ?? count($j['results'] ?? [])) > 0, 'job_postings intact');
});

run_suite('lookup: bodies over 16 KiB are 413', function () use ($base) {
    $big = '{"ids":[' . implode(',', array_fill(0, 4000, 1001)) . ']}';
    assert_true(strlen($big) > 16384, 'fixture body is oversized');
    [$st, $h, $out] = lookup($base, $big);
    assert_same(413, $st, 'oversized body');
    assert_contains('application/json', $h['content-type'] ?? '', '413 is JSON');
    assert_contains('"error"', $out, '413 error object');
    // Exactly at the cap is fine as long as the content is valid.
    $ok = '{"ids":[1001]' . str_repeat(' ', 16384 - 14) . '}';
    assert_same(16384, strlen($ok), 'boundary body is 16 KiB');
    assert_same(200, lookup($base, $ok)[0], '16 KiB exactly is accepted');
});

run_suite('markup: identity attributes and real buttons on list, employer and detail', function () use ($base) {
    $list = http($base, '/')[2];
    $x = xpath_for($list);
    $row = $x->query('//li[@data-posting][@data-pref-id="1001"]');
    assert_same(1, $row->length, 'list row 1001 carries identity');
    $li = $row->item(0);
    assert_same('https://posturi.gov.ro/anunt/fixture-1001', $li->getAttribute('data-pref-url'), 'source url is the identity');
    assert_same('/job/1001-asistent-medical-generalist/', $li->getAttribute('data-pref-path'), 'canonical path');
    assert_same('2026-10-16', $li->getAttribute('data-pref-date'), 'last displayed date');
    assert_same('anunt', $li->getAttribute('data-pref-date-source'), 'date provenance');
    assert_same('Cluj-Napoca, Cluj', $li->getAttribute('data-pref-place'), 'place');
    assert_same(1, $x->query('.//button[@data-pref="save"][@aria-pressed="false"]', $li)->length, 'save toggle with aria-pressed');
    assert_same(1, $x->query('.//button[@data-pref="hide"]', $li)->length, 'hide button');
    assert_same(0, $x->query('//h2//button')->length, 'no button inside a title link/heading');
    assert_same(0, $x->query('//a//button')->length, 'no button nested in a link');
    assert_same(1, $x->query('.//a[@href="/job/1001-asistent-medical-generalist/"]', $li)->length, 'normal job link preserved (no-JS)');
    assert_same(1, $x->query('.//*[@data-pref-controls][@hidden]', $li)->length, 'controls start hidden until JS runs');
    assert_same(1, $x->query('.//button[@data-pref="save"][@aria-label="Salvează anunțul: Asistent medical"]', $li)->length, 'save icon button named with the posting title');
    assert_same(1, $x->query('.//button[@data-pref="hide"][@aria-pressed="false"][@aria-label="Ascunde anunțul: Asistent medical"]', $li)->length, 'hide icon button named with the posting title');
    assert_same(0, $x->query('.//button[@data-pref]//text()[normalize-space()]', $li)->length, 'icon buttons carry no visible text');
    assert_same(1, $x->query('.//button[@data-pref="save"]//*[local-name()="use"][@href="#i-bookmark"]', $li)->length, 'bookmark glyph from the shared sprite');
    assert_same(1, substr_count($list, 'id="i-bookmark"'), 'sprite defined once per page');
    assert_same(1, $x->query('//*[@data-pref-banner]')->length, 'one banner placeholder in the results');
    assert_contains('Numărul rezultatelor include anunțurile ascunse în acest browser.', $list, 'results-count help copy');
    assert_contains('Salvate', $x->query('//nav[@aria-label="Navigare principală"]//a[@href="/salvate/"]')->item(0)->textContent ?? '', 'shared nav links to /salvate/');
    assert_same(1, $x->query('//*[@data-saved-count]')->length, 'nav count slot');
    assert_same(1, substr_count($list, '/static/saved.js'), 'script loaded once by the layout');
    assert_contains('Salvarea și ascunderea anunțurilor se păstrează în browser și necesită JavaScript', $list, 'no-JS explanation');

    // HTMX partial: rows carry the same hooks (re-applied after swaps).
    [, , $part] = http($base, '/?page=2', 'GET', null, ['HX-Request: true']);
    assert_contains('data-pref-url="https://posturi.gov.ro/anunt/fixture-2', $part, 'HTMX partial rows carry identity');
    assert_contains('data-pref="hide"', $part, 'HTMX partial rows carry buttons');

    $emp = http($base, '/angajator/spitalul-clinic-judetean-cluj/')[2];
    $xe = xpath_for($emp);
    assert_true($xe->query('//*[@data-posting][@data-pref-hideable]//button[@data-pref="hide"]')->length >= 2, 'employer rows have save and hide');
    assert_same('https://posturi.gov.ro/anunt/fixture-1001', $xe->query('//*[@data-pref-id="1001"]')->item(0)->getAttribute('data-pref-url'), 'employer row identity');
    assert_same('Spitalul Clinic Județean Cluj', $xe->query('//*[@data-pref-id="1001"]')->item(0)->getAttribute('data-pref-employer'), 'employer row employer');
    assert_same(1, $xe->query('//*[@data-pref-banner]')->length, 'employer banner');

    $det = http($base, '/job/1001-asistent-medical-generalist/')[2];
    $xd = xpath_for($det);
    assert_same(1, $xd->query('//*[@data-posting][@data-pref-id="1001"]//button[@data-pref="save"]')->length, 'detail save button');
    assert_same(1, $xd->query('//*[@data-posting][@data-pref-id="1001"]//button[@data-pref="hide"]')->length, 'detail hide icon button');
    assert_same(1, $xd->query('//*[@data-posting][@data-pref-id="1001"]/div/h1/following-sibling::*[@data-pref-controls]')->length, 'detail icons sit right after the h1');
    assert_same(0, $xd->query('//h1//button')->length, 'no button inside the heading');
    assert_same('https://posturi.gov.ro/anunt/fixture-1001', $xd->query('//*[@data-posting]')->item(0)->getAttribute('data-pref-url'), 'detail identity');
});

run_suite('markup: hostile snapshot text is escaped at the source', function () {
    $p = ['id' => 7, 'url' => 'https://posturi.gov.ro/anunt/x" onmouseover="alert(1)',
          'title' => '"><img src=x onerror=alert(1)>', 'employer_name' => "<script>alert(2)</script>",
          'judet_name' => 'Cluj', 'locality' => '', 'apply_deadline' => '2026-10-20', 'deadline_source' => 'anunt'];
    $attrs = pref_attrs($p);
    assert_not_contains('<img', $attrs, 'no raw tag in attributes');
    assert_not_contains('<script', $attrs, 'no raw script in attributes');
    assert_not_contains('" onmouseover=', $attrs, 'quote in the url cannot break out of its attribute');
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8"><div' . $attrs . '></div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $div = (new DOMXPath($dom))->query('//div[@data-posting]')->item(0);
    assert_true($div !== null, 'attribute string parses');
    assert_same('https://posturi.gov.ro/anunt/x" onmouseover="alert(1)', $div->getAttribute('data-pref-url'), 'value round-trips verbatim');
    assert_false($div->hasAttribute('onmouseover'), 'no injected handler attribute');
    $btn = pref_controls($p);
    assert_not_contains('<img', $btn, 'no raw tag in button text');
});

run_suite('/salvate/ shell: layout, states, no export data, no indexing', function () use ($base) {
    [$st, , $html] = http($base, '/salvate/');
    assert_same(200, $st, '/salvate/ renders');
    assert_same(200, http($base, '/salvate')[0], '/salvate also routes');
    $x = xpath_for($html);
    assert_same('Salvate', trim($x->query('//h1')->item(0)->textContent ?? ''), 'heading');
    assert_contains('Salvările și anunțurile ascunse rămân în acest browser. Nu se sincronizează între dispozitive și se pot pierde dacă ștergi datele browserului.', $html, 'persistence copy always visible');
    assert_contains('noindex', $html, 'noindex');
    foreach (['saved', 'hidden'] as $v) assert_same(1, $x->query('//a[@data-view-tab="' . $v . '"]')->length, "$v tab");
    foreach (['data-saved-empty', 'data-saved-list', 'data-saved-pager', 'data-clear-confirm'] as $a) {
        assert_same(1, $x->query('//*[@' . $a . ']')->length, "$a present");
    }
    assert_contains('Șterge lista de salvate', $html, 'clear saved');
    assert_contains('Restabilește toate anunțurile ascunse', $html, 'restore all hidden');
    assert_contains('<noscript>', $html, 'no-JS state');
    assert_not_contains('/job/1001-', $html, 'does not render the export into HTML');
    assert_not_contains('data-pref-id="1001"', $html, 'no posting rows rendered by the server');
    assert_contains('Disallow: /salvate/', http($base, '/robots.txt')[2], 'robots disallows /salvate/');
    assert_not_contains('/salvate', http($base, '/sitemap.xml')[2], 'not in the sitemap');
});

run_suite('rebuilt dataset: new slug and deadline, removed id, id reused by another source', function () use ($base, $db_path) {
    $w = new PDO('sqlite:' . $db_path);
    $w->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $w->exec("UPDATE job_postings SET title='ASISTENT MEDICAL PRINCIPAL', occ_canonical='', apply_deadline='2026-10-30' WHERE id=1001");
    $w->exec("UPDATE job_postings SET url='https://posturi.gov.ro/anunt/alt-anunt-1002' WHERE id=1002");
    $w->exec("PRAGMA foreign_keys=OFF; DELETE FROM calendar_events WHERE posting_id=1006; DELETE FROM job_postings_fts WHERE rowid=1006; DELETE FROM job_postings WHERE id=1006");
    $j = json_decode(lookup($base, '{"ids":[1001,1002,1006]}')[2], true);
    $by = [];
    foreach ($j['items'] as $i) $by[$i['id']] = $i;
    assert_same('/job/1001-asistent-medical-principal/', $by[1001]['path'] ?? null, 'slug follows the title; id and source url unchanged');
    assert_same('https://posturi.gov.ro/anunt/fixture-1001', $by[1001]['url'] ?? null, 'identity unchanged');
    assert_same('2026-10-30', $by[1001]['deadline'] ?? null, 'deadline refreshed');
    assert_same('https://posturi.gov.ro/anunt/alt-anunt-1002', $by[1002]['url'] ?? null, 'a reused id reports its NEW source url, so a client can refuse the match');
    assert_false(isset($by[1006]), 'a removed id is absent, not "expired"');
    assert_not_contains('expir', json_encode($j['items']), 'no expired/cancelled claim is made for anything');
});

// No PHP warnings from any of the above.
$log = (string)@file_get_contents($srv['log']);
run_suite('server log: no warnings, notices or fatals', function () use ($log) {
    foreach (['Undefined', 'Warning', 'Notice', 'Deprecated', 'Fatal', 'Uncaught'] as $needle) {
        assert_not_contains($needle, $log, "server log: $needle");
    }
});

stop_fixture_server($srv, $db_path);
finish();
