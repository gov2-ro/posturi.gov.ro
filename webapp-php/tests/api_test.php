<?php
/**
 * API-01: the public JSON API under /api/v1/ and its docs page /api/.
 *
 * Fixture-served checks: the list (count, pages, next and prev, a filter, q, employer, the last page and the page after
 * it), the strict parameters (400 for an unknown one, for `page` that is not a whole number from 1, for `q[]`, for the
 * HTMX cache buster), the posting endpoint (known, unknown and non-numeric IDs), the error shape (JSON, no-store, 405 with
 * Allow), the bulk download (one build, a reused file, a new build that deletes the old file, valid gzip JSON with the right
 * count, no Content-Encoding, 503 when the folder cannot be written), the agreement of the list row with the /posturi.json
 * row, and the docs page (every parameter and every field is documented, and the links to it).
 *
 * Usage: php tests/api_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';
require_once __DIR__ . '/fixtures/page_helpers.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../query.php';
require_once __DIR__ . '/../inc/api.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-api-' . getmypid() . '.sqlite';
build_fixture_db($db_path);

// 130 more open postings of one employer, so that the list has a second page and an employer scope.
$pdo = new PDO('sqlite:' . $db_path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ins = $pdo->prepare("INSERT INTO job_postings
    (id, url, title, employer_id, employer_name, judet_id, judet_name, judet_slug, locality,
     published_at, expires_at, apply_deadline, deadline_source, application_status, updated_at, job_type, categorie)
    VALUES (?, ?, ?, 3, 'Administrația de Salubrizare Tunari', 3, 'Ilfov', 'ilfov', 'Tunari', ?, '2027-03-01 23:59:59',
            '2027-02-01', 'anunt', 'confirmed_open', '2026-10-02 10:00:00', 'Permanent', 'Test')");
for ($i = 1; $i <= 130; $i++) {
    $ins->execute([5000 + $i, "https://posturi.gov.ro/anunt/api-$i", "Muncitor necalificat $i",
        sprintf('2026-10-02 %02d:%02d:00', intdiv($i, 60), $i % 60)]);
}
$total_rows = (int)$pdo->query("SELECT COUNT(*) FROM job_postings")->fetchColumn();
$ilfov_all = (int)$pdo->query("SELECT COUNT(*) FROM job_postings WHERE judet_slug = 'ilfov'")->fetchColumn();
$employer_slug = (string)$pdo->query("SELECT slug FROM employers WHERE id = 3")->fetchColumn();
$with_schema = (int)$pdo->query("SELECT posting_id FROM posting_text WHERE schema_json IS NOT NULL AND schema_json != '' LIMIT 1")->fetchColumn();
$with_events = (int)$pdo->query("SELECT posting_id FROM calendar_events LIMIT 1")->fetchColumn();
unset($pdo, $ins);

// The private folder of the download: a temporary one, never the repository's.
$priv = sys_get_temp_dir() . '/posturi-api-private-' . getmypid();
putenv('ALERTS_DIR=' . $priv);
$srv = start_fixture_server($db_path);
$base = $srv['base'];

/** GET and decode: [status, array|null, headers, raw body]. */
function api_get(string $base, string $path, string $method = 'GET'): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'method' => $method]]);
    $body = (string)@file_get_contents($base . $path, false, $ctx);
    $status = 500;
    $headers = [];
    $lines = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : (@$http_response_header ?? []);
    foreach ($lines as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
        if (preg_match('#^([^:]+):\s*(.*)$#', $h, $m)) $headers[strtolower($m[1])] = $m[2];
    }
    return [$status, json_decode($body, true), $headers, $body];
}

/** Every error answer of the suite, checked once at the end. */
$errors_seen = [];
function api_expect_error(string $base, string $path, int $want, string $what, string $method = 'GET'): array {
    global $errors_seen;
    [$st, $j, $h, $raw] = api_get($base, $path, $method);
    assert_same($want, $st, "$what: status");
    assert_true(is_array($j) && isset($j['error']) && is_string($j['error']) && $j['error'] !== '', "$what: JSON body with an error text");
    $errors_seen[$what] = [$h, $raw];
    return $h;
}

run_suite('the index: version, data date, links', function () use ($base, $total_rows) {
    [$st, $j, $h] = api_get($base, '/api/v1/');
    assert_same(200, $st, 'status');
    assert_same('application/json; charset=utf-8', $h['content-type'] ?? '', 'content type');
    assert_same('*', $h['access-control-allow-origin'] ?? '', 'CORS');
    assert_contains('s-maxage=3600', $h['cache-control'] ?? '', 'the default cache policy');
    assert_same('v1', $j['api_version'] ?? null, 'api_version');
    assert_same('2026-10-03 09:00:00', $j['data']['built_at'] ?? null, 'built_at');
    assert_same(38, $j['data']['postings'] ?? null, 'postings (from build_meta)');
    foreach (['list', 'posting', 'download', 'docs'] as $k) assert_true(isset($j['links'][$k]), "link $k");
    assert_same($j, api_get($base, '/api/v1')[1], 'with and without the trailing slash');
});

run_suite('the list: count, pages, next and prev, the last page, the page after it', function () use ($base, $total_rows) {
    $all = api_get($base, '/api/v1/posturi?status=all')[1];
    assert_same($total_rows, $all['count'], 'count of status=all');
    assert_same(2, $all['pages'], 'pages');
    assert_same(100, $all['per_page'], 'per_page');
    assert_same(100, count($all['results']), 'a full first page');
    assert_same(1, $all['page'], 'page 1');
    assert_same(null, $all['prev'], 'no prev on page 1');
    assert_same($base . '/api/v1/posturi?status=all&page=2', $all['next'], 'next');
    assert_same('2026-10-03 09:00:00', $all['data_built_at'], 'data_built_at');

    [$st, $p2, $h] = api_get($base, '/api/v1/posturi?status=all&page=2');
    assert_same(200, $st, 'page 2 status');
    assert_same($total_rows - 100, count($p2['results']), 'the rest on page 2');
    assert_same(null, $p2['next'], 'no next on the last page');
    assert_same($base . '/api/v1/posturi?status=all', $p2['prev'], 'prev of page 2 is the URL without page');
    $ids = array_merge(array_column($all['results'], 'id'), array_column($p2['results'], 'id'));
    assert_same(count($ids), count(array_unique($ids)), 'no row twice across the pages');
    assert_same($total_rows, count($ids), 'every row once');

    // The order: published_at DESC, id DESC.
    $rows = array_merge($all['results'], $p2['results']);
    for ($i = 1; $i < count($rows); $i++) {
        $a = [$rows[$i - 1]['published_at'] ?? '', $rows[$i - 1]['id']];
        $b = [$rows[$i]['published_at'] ?? '', $rows[$i]['id']];
        assert_true($a >= $b, 'order at ' . $i);
    }
    // 100 is exactly a full page when count is 100: tested by a filter below. Beyond the last page: 404.
    api_expect_error($base, '/api/v1/posturi?status=all&page=3', 404, 'page beyond pages');
    api_expect_error($base, '/api/v1/posturi?status=all&page=99999999999999999999', 404, 'a huge page');
});

run_suite('the list: filters, q, employer, an empty result', function () use ($base, $ilfov_all, $employer_slug, $total_rows) {
    $total_all = $total_rows;
    $a = api_get($base, '/api/v1/posturi?status=all&judet[]=ilfov')[1];
    assert_same($ilfov_all, $a['count'], 'judet[]=ilfov');
    assert_same($base . '/api/v1/posturi?status=all&judet%5B%5D=ilfov&page=2', $a['next'], 'next keeps a facet as judet[]');
    foreach ($a['results'] as $r) assert_same('Ilfov', $r['judet'], 'every row is in Ilfov');

    // q: the same rows as the feed (the 130 added rows are not in the FTS index of the fixture).
    $q = api_get($base, '/api/v1/posturi?status=all&q=spital')[1];
    $qf = api_get($base, '/posturi.json?status=all&q=spital')[1];
    assert_true($q['count'] > 0 && $q['count'] < $total_all, 'q narrows the list (' . $q['count'] . ')');
    assert_same($qf['count'], $q['count'], 'q: the same count as the feed');
    assert_same(array_column($qf['results'], 'id'), array_column($q['results'], 'id'), 'q: the same rows as the feed');
    $none = api_get($base, '/api/v1/posturi?status=all&q=zzzzqqqqxxxx');
    assert_same(200, $none[0], 'no result: still 200');
    assert_same(0, $none[1]['count'], 'count 0');
    assert_same(1, $none[1]['pages'], 'one page');
    assert_same([], $none[1]['results'], 'no rows');

    $e = api_get($base, '/api/v1/posturi?status=all&employer=' . rawurlencode($employer_slug))[1];
    assert_true($e['count'] >= 130, 'employer scope (' . $e['count'] . ')');
    $st = api_get($base, '/api/v1/posturi?status=all&employer=' . rawurlencode($employer_slug) . '&judet[]=ilfov&page=2');
    assert_same(200, $st[0], 'several filters and page 2');
    assert_same($base . '/api/v1/posturi?status=all&employer=' . rawurlencode($employer_slug) . '&judet%5B%5D=ilfov', $st[1]['prev'], 'prev keeps every filter');

    $default = api_get($base, '/api/v1/posturi')[1];
    $active = api_get($base, '/api/v1/posturi?status=active')[1];
    assert_same($active['count'], $default['count'], 'status is active by default, as in the list page');
});

run_suite('strict parameters: 400 for an unknown or malformed one', function () use ($base) {
    api_expect_error($base, '/api/v1/posturi?foo=1', 400, 'unknown parameter');
    api_expect_error($base, '/api/v1/posturi?foo[]=1', 400, 'unknown array parameter');
    api_expect_error($base, '/api/v1/posturi?page=0', 400, 'page=0');
    api_expect_error($base, '/api/v1/posturi?page=-1', 400, 'page=-1');
    api_expect_error($base, '/api/v1/posturi?page=x', 400, 'page=x');
    api_expect_error($base, '/api/v1/posturi?page=1.5', 400, 'page=1.5');
    api_expect_error($base, '/api/v1/posturi?page[]=1', 400, 'page[]');
    api_expect_error($base, '/api/v1/posturi?q[]=x', 400, 'q[]=x');
    api_expect_error($base, '/api/v1/posturi?org.htmx.cache-buster=1', 400, 'the HTMX cache buster');
    api_expect_error($base, '/api/v1/posturi?sort=oldest', 400, 'sort (the order is fixed)');
    api_expect_error($base, '/api/v1/posturi?q=' . str_repeat('a', 400), 400, 'a value that is too long');
    api_expect_error($base, '/api/v1/?page=1', 400, 'a parameter on the index');
    api_expect_error($base, '/api/v1/descarcare?x=1', 400, 'a parameter on the download');
    api_expect_error($base, '/api/v1/posturi/1001?x=1', 400, 'a parameter on a posting');
    // The HTML pages and the old feeds keep their rule: an unknown parameter is ignored.
    assert_same(200, api_get($base, '/posturi.json?foo=1')[0], 'the feed ignores an unknown parameter');
    assert_same(200, api_get($base, '/api/?foo=1')[0], 'the docs page ignores an unknown parameter');
    // Every list parameter is accepted (no 400), in the shape its kind needs.
    foreach (api_list_params() as $p) {
        $path = '/api/v1/posturi?status=all&' . rawurlencode($p) . (api_param_is_multi($p) ? '%5B%5D=x' : '=1');
        if ($p === 'status') $path = '/api/v1/posturi?status=all';
        $st = api_get($base, $path)[0];
        assert_true($st === 200, "$p is accepted (got $st)");
    }
});

run_suite('the posting: a known ID, an unknown ID, a non-numeric ID', function () use ($base, $with_schema, $with_events) {
    [$st, $j, $h] = api_get($base, '/api/v1/posturi/1001');
    assert_same(200, $st, 'known ID');
    assert_same('v1', $j['api_version'], 'api_version');
    assert_same(1001, $j['posting']['id'], 'id');
    assert_contains('s-maxage=3600', $h['cache-control'] ?? '', 'cache policy');
    api_expect_error($base, '/api/v1/posturi/999999', 404, 'unknown ID');
    api_expect_error($base, '/api/v1/posturi/abc', 404, 'non-numeric ID');
    api_expect_error($base, '/api/v1/posturi/12abc', 404, 'ID with trailing text');
    api_expect_error($base, '/api/v1/posturi/1001/extra', 404, 'a path below the posting');
    api_expect_error($base, '/api/v1/nimic', 404, 'an unknown route');

    $s = api_get($base, "/api/v1/posturi/$with_schema")[1]['posting'];
    assert_true(is_array($s['extraction']['data']), 'a posting with an extraction has it parsed');
    assert_true(is_string($s['extraction']['prompt_version']) && $s['extraction']['prompt_version'] !== '', 'and its prompt version');
    assert_true(is_string($s['body']), 'body');
    if ($with_events) {
        $ev = api_get($base, "/api/v1/posturi/$with_events")[1]['posting']['calendar'];
        assert_true(count($ev) >= 1 && isset($ev[0]['event'], $ev[0]['date']) && array_key_exists('time', $ev[0]), 'calendar rows');
    }
    // Nothing internal leaves through the posting (the left-out fields of api_posting()).
    $raw = api_get($base, "/api/v1/posturi/$with_schema")[3];
    foreach (['detail_content_hash', 'schema_source_revision', 'source_host', 'quality_json', '"selector"', 'inf_', 'last_seen_at', 'employer_id'] as $needle) {
        assert_not_contains($needle, $raw, "internal field $needle");
    }
});

run_suite('every error: JSON body, no-store, CORS; 405 has Allow', function () use ($base) {
    $h405 = api_expect_error($base, '/api/v1/posturi', 405, 'POST on the list', 'POST');
    assert_same('GET, HEAD', $h405['allow'] ?? '', 'Allow header');
    api_expect_error($base, '/api/v1/descarcare', 405, 'POST on the download', 'POST');
    global $errors_seen;
    assert_true(count($errors_seen) >= 20, count($errors_seen) . ' error answers checked');
    foreach ($errors_seen as $what => [$h, $raw]) {
        assert_same('no-store', $h['cache-control'] ?? '', "$what: Cache-Control");
        assert_same('application/json; charset=utf-8', $h['content-type'] ?? '', "$what: content type");
        assert_same('*', $h['access-control-allow-origin'] ?? '', "$what: CORS");
        assert_same(['error'], array_keys(json_decode($raw, true)), "$what: only the error key");
    }
});

run_suite('the list row has every field of the /posturi.json row, with the same values', function () use ($base) {
    $feed = api_get($base, '/posturi.json?status=all')[1];
    assert_true(count($feed['results']) > 100, 'the feed holds ' . count($feed['results']) . ' rows');
    $api = [];
    foreach ([1, 2] as $page) {
        foreach (api_get($base, '/api/v1/posturi?status=all&page=' . $page)[1]['results'] as $r) $api[$r['id']] = $r;
    }
    foreach ($feed['results'] as $row) {
        $a = $api[$row['id']] ?? null;
        assert_true($a !== null, 'row ' . $row['id'] . ' is in the API');
        if ($a === null) continue;
        foreach ($row as $k => $v) {
            if ($a[$k] !== $v) assert_same($v, $a[$k] ?? null, "row {$row['id']} field $k");
        }
        assert_same($base . '/api/v1/posturi/' . $row['id'], $a['detail_url'], 'detail_url');
        assert_same(1, preg_match('#^' . preg_quote($base, '#') . '/job/' . $row['id'] . '(-[^/]*)?/$#', $a['page_url']), 'page_url');
    }
    // Same fields, same order, then the two new ones.
    $keys = array_keys(reset($api));
    assert_same(array_merge(array_keys($feed['results'][0]), ['detail_url', 'page_url']), $keys, 'the keys, in order');
    assert_same(array_keys($feed['results'][0]), array_slice($keys, 0, -2), 'the old keys come first');
});

run_suite('the parameters and the fields are documented, and the docs page lists them', function () use ($base, $with_schema) {
    $missing = array_filter(api_list_params(), fn($p) => api_param_description($p) === '');
    assert_same([], array_values($missing), 'every list parameter has a description');
    foreach (array_keys(API_PARAM_DOCS) as $p) {
        assert_true($p === 'page' || in_array($p, api_list_params(), true), "API_PARAM_DOCS has no parameter that the API refuses: $p");
    }
    foreach (['sort', 'title'] as $left_out) assert_false(in_array($left_out, api_list_params(), true), "$left_out is not allowed");

    $posting = api_get($base, "/api/v1/posturi/$with_schema")[1]['posting'];
    $documented = array_column(API_FIELD_DOCS, 0);
    assert_same(array_keys($posting), $documented, 'API_FIELD_DOCS lists the keys of a posting, in order');
    // The keys of each group object are named in its meaning.
    foreach (API_FIELD_DOCS as [$path, $type, $meaning]) {
        if ($type !== 'obiect' || !is_array($posting[$path])) continue;
        foreach (array_keys($posting[$path]) as $sub) assert_contains($sub, $meaning, "the meaning of $path names $sub");
    }

    [$st, , $h, $body] = api_get($base, '/api/');
    assert_same(200, $st, 'the docs page answers');
    assert_contains('text/html', $h['content-type'] ?? '', 'HTML');
    $x = xp($body);
    foreach (array_merge(['page'], api_list_params()) as $p) {
        assert_same(1, $x->query('//tr[@data-param="' . $p . '"]')->length, "the docs table has the parameter $p");
    }
    foreach ($documented as $f) assert_same(1, $x->query('//tr[@data-field="' . $f . '"]')->length, "the docs table has the field $f");
    assert_contains('posturi.gov.ro', $body, 'the source notice');
    assert_contains('/api/v1/descarcare', $body, 'the download');
    assert_contains('href="/posturi.json"', $body, 'the feeds paragraph');
    // Where the page is linked: the footer, /despre/, the sitemap.
    assert_contains('href="/api/"', api_get($base, '/despre/')[3], '/despre/ links the docs page');
    assert_contains('href="/api/v1/descarcare"', api_get($base, '/despre/')[3], '/despre/ links the download');
    assert_contains('href="/api/"', $body, 'the footer links the docs page');
    assert_contains('/api/</loc>', api_get($base, '/sitemap.xml')[3], 'the sitemap lists /api/');
    assert_contains('rel="alternate" type="application/json" title="posturi.gov2.ro — JSON" href="/posturi.json"', $body, 'the head keeps the link to /posturi.json');
});

run_suite('the download: one build, a reused file, a new build, gzip JSON, headers', function () use ($base, $db_path, $priv, $total_rows) {
    $dir = $priv . '/api';
    $fetch = function () use ($base) {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'header' => "Accept-Encoding: gzip\r\n"]]);
        $body = (string)file_get_contents($base . '/api/v1/descarcare', false, $ctx);
        $h = [];
        $lines = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : @$http_response_header;
        foreach ($lines as $line) if (preg_match('#^([^:]+):\s*(.*)$#', $line, $m)) $h[strtolower($m[1])] = $m[2];
        return [$body, $h];
    };
    assert_same([], glob($dir . '/posturi-*.json.gz') ?: [], 'no file before the first request');
    [$body, $h] = $fetch();
    $files = glob($dir . '/posturi-*.json.gz') ?: [];
    assert_same(1, count($files), 'the first request builds one file');
    assert_same('application/gzip', $h['content-type'] ?? '', 'content type');
    assert_false(isset($h['content-encoding']), 'no Content-Encoding');
    assert_same((string)filesize($files[0]), $h['content-length'] ?? '', 'Content-Length is the size of the file');
    assert_same('attachment; filename="posturi-gov2-2026-10-03.json.gz"', $h['content-disposition'] ?? '', 'Content-Disposition');
    assert_contains('s-maxage=3600', $h['cache-control'] ?? '', 'the default cache policy');
    assert_same(file_get_contents($files[0]), $body, 'the body is the file, byte for byte');
    assert_same("\x1f\x8b", substr($body, 0, 2), 'a gzip stream');
    $doc = json_decode((string)gzdecode($body), true);
    assert_true(is_array($doc), 'the file decodes to JSON');
    assert_same('v1', $doc['api_version'] ?? null, 'api_version');
    assert_same($total_rows, $doc['count'] ?? null, 'count is the rows of the export');
    assert_same($total_rows, count($doc['postings'] ?? []), 'as many postings as the count');
    assert_same(array_column(API_FIELD_DOCS, 0), array_keys($doc['postings'][0] ?? []), 'each posting has the fields of api_posting()');
    $one = api_get($base, '/api/v1/posturi/' . $doc['postings'][0]['id'])[1]['posting'];
    assert_same($one, $doc['postings'][0], 'a posting of the file equals the posting endpoint');

    // A second request reuses the file.
    $mtime = filemtime($files[0]);
    clearstatcache();
    sleep(1);
    [$body2] = $fetch();
    clearstatcache();
    assert_same($mtime, filemtime($files[0]), 'the second request does not rebuild');
    assert_same($body, $body2, 'and sends the same bytes');
    assert_same(1, count(glob($dir . '/posturi-*.json.gz') ?: []), 'still one file');

    // A new built_at builds a new file and deletes the old one. A stray lock or temp file does not break it.
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->exec("UPDATE build_meta SET built_at = '2026-10-04 09:00:00' WHERE id = 1");
    unset($pdo);
    [$body3, $h3] = $fetch();
    $files3 = glob($dir . '/posturi-*.json.gz') ?: [];
    assert_same(1, count($files3), 'one file after the new build');
    assert_true($files3 && $files3[0] !== $files[0], 'a new file name');
    assert_false(file_exists($files[0]), 'the old file is deleted');
    assert_same('2026-10-04 09:00:00', json_decode((string)gzdecode($body3), true)['data_built_at'] ?? null, 'the new file has the new date');
    assert_same('attachment; filename="posturi-gov2-2026-10-04.json.gz"', $h3['content-disposition'] ?? '', 'the file name has the new date');
    assert_same([], glob($dir . '/build-*.tmp') ?: [], 'no temp file is left');

    // HEAD sends the headers and no body.
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'method' => 'HEAD']]);
    $hb = (string)file_get_contents($base . '/api/v1/descarcare', false, $ctx);
    assert_same('', $hb, 'HEAD: no body');
});

run_suite('the download: 503 with a JSON error when the folder cannot be written', function () use ($db_path) {
    $blocker = sys_get_temp_dir() . '/posturi-api-blocker-' . getmypid();
    file_put_contents($blocker, 'a file where a folder should be');
    putenv('ALERTS_DIR=' . $blocker);   // the folder api/ would be below a regular file
    $copy = $db_path . '.b';
    copy($db_path, $copy);
    $srv2 = start_fixture_server($copy);
    try {
        $h = api_expect_error($srv2['base'], '/api/v1/descarcare', 503, 'download without a writable folder');
        assert_same('no-store', $h['cache-control'] ?? '', 'no-store');
        assert_same(200, api_get($srv2['base'], '/api/v1/posturi?status=all')[0], 'the list still works');
        assert_false(file_exists($blocker . '/api'), 'nothing is built in the web root or elsewhere');
    } finally {
        stop_fixture_server($srv2, $copy);
        @unlink($blocker);
    }
});

stop_fixture_server($srv, $db_path);
foreach (glob($priv . '/api/*') ?: [] as $f) @unlink($f);
@rmdir($priv . '/api');
@rmdir($priv);
finish();
