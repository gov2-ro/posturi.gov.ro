<?php
/**
 * UX-SALVATE-FLUX — /flux.json: the one lookup behind the Flux tab of /salvate/.
 *
 * Covers the caps (400), the open-only rule, the dedupe across sources, an unknown slug, a closed
 * saved id, `truncated` (per-source cap of 100 and the cap of 300 in all), the row shape, the
 * headers, and the /salvate/ shell with its three tabs. The script's own behaviour is in
 * tests/browser/flux.spec.js.
 *
 * Usage: php tests/flux_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-flux-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
$srv = start_fixture_server($db_path);
$base = $srv['base'];

/** @return array{0:int,1:array<string,string>,2:string} status, lower-cased headers, body */
function http(string $base, string $path, string $method = 'GET'): array {
    $opts = ['method' => $method, 'ignore_errors' => true];
    $out = @file_get_contents($base . $path, false, stream_context_create(['http' => $opts]));
    $status = 0; $h = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) $status = (int)$m[1];
        elseif (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))] = trim($v); }
    }
    return [$status, $h, (string)$out];
}

/** Build the query string the browser builds: s[] with an encoded key, e[] and id[]. */
function flux_qs(array $s = [], array $e = [], array $id = []): string {
    $p = [];
    foreach ($s as $v) $p[] = 's[]=' . rawurlencode($v);
    foreach ($e as $v) $p[] = 'e[]=' . rawurlencode($v);
    foreach ($id as $v) $p[] = 'id[]=' . rawurlencode((string)$v);
    return '/flux.json' . ($p ? '?' . implode('&', $p) : '');
}

function flux(string $base, string $path): array {
    [$st, $h, $body] = http($base, $path);
    return [$st, json_decode($body, true), $h];
}

const E_CLUJ = 'spitalul-clinic-judetean-cluj';      // 1001, 1006, 1007 (open)
const E_BACAU = 'primaria-comunei-gaiceana';         // 1002, 1004 (open)
const E_ILFOV = 'administratia-de-salubrizare-tunari';  // 1003 closed; 1005, 1008, 2001-2030 open
const K_CLUJ = 'judet%5B%5D=cluj';

run_suite('the request: caps and shape (400), method (405), headers', function () use ($base) {
    [$st, $j, $h] = flux($base, flux_qs());
    assert_same(200, $st, 'no sources is a valid, empty answer');
    assert_same([], $j['rows'], 'no rows');
    assert_same([], $j['sources'], 'no sources');
    assert_same('2026-10-03', $j['today'], 'the server day, for the stats of "new" and "closing"');
    assert_contains('application/json', $h['content-type'] ?? '', 'content type');
    assert_same('no-store', $h['cache-control'] ?? '', 'Cache-Control: no-store');
    assert_false(isset($h['access-control-allow-origin']), 'no cross-origin access');
    assert_false(isset($h['set-cookie']), 'no cookies');

    $keys = ['q=a', 'q=b', 'q=c', 'q=d'];
    assert_same(200, flux($base, flux_qs($keys))[0], 'four searches pass');
    assert_same(400, flux($base, flux_qs([...$keys, 'q=e']))[0], 'five searches: 400');
    $slugs = array_map(fn($i) => "angajator-$i", range(1, 10));
    assert_same(200, flux($base, flux_qs([], $slugs))[0], 'ten employers pass');
    assert_same(400, flux($base, flux_qs([], [...$slugs, 'angajator-11']))[0], 'eleven employers: 400');
    assert_same(200, flux($base, flux_qs([], [], range(1001, 1020)))[0], 'twenty ids pass');
    assert_same(400, flux($base, flux_qs([], [], range(1001, 1021)))[0], 'twenty-one ids: 400');
    assert_same(200, flux($base, flux_qs([], [], [1001, 1001, 1001]))[0], 'a repeated id is one id');
    assert_same(400, flux($base, '/flux.json?s=judet')[0], 's must be a list');
    assert_same(400, flux($base, '/flux.json?e[]=')[0], 'an empty slug is 400');
    assert_same(400, flux($base, '/flux.json?id[]=abc')[0], 'an id must be a number');
    assert_same(400, flux($base, '/flux.json?id[]=0')[0], 'an id must be positive');
    assert_same(400, flux($base, '/flux.json?id[]=1.5')[0], 'an id must be whole');
    assert_same(400, flux($base, '/flux.json?id[a]=1')[0], 'a keyed list is 400');
    [$st, $h] = http($base, flux_qs(), 'POST');
    assert_same(405, $st, 'POST is 405');
    assert_contains('GET', $h['allow'] ?? '', 'Allow names GET');
});

run_suite('rows: the shape of one row, and the open-only rule', function () use ($base) {
    [, $j] = flux($base, flux_qs([], [E_ILFOV]));
    $ids = array_column($j['rows'], 'id');
    assert_false(in_array(1003, $ids, true), 'a closed posting is not returned');
    assert_true(in_array(1005, $ids, true), 'an open posting without a date is returned (the list default)');
    assert_true(in_array(1008, $ids, true), 'an open posting is returned');
    assert_same(1 + 1 + 30, count($ids), 'the open postings of the employer: 1005, 1008 and the 30 volume rows');
    assert_same(32, $j['sources'][0]['total'], 'total counts the open postings');
    assert_false($j['sources'][0]['truncated'], 'not truncated');

    [, $j] = flux($base, flux_qs([], [E_CLUJ]));
    $by = array_column($j['rows'], null, 'id');
    $r = $by[1001];
    assert_same(['id', 'title', 'url', 'source_url', 'employer', 'employer_slug', 'judet', 'locality', 'place', 'domain', 'salary',
                 'published_at', 'apply_deadline', 'deadline_source', 'src'], array_keys($r), 'the fields of a row');
    assert_same('Asistent medical', $r['title'], 'display title');
    assert_same('/job/1001-asistent-medical-generalist/', $r['url'], 'the local path');
    assert_same('https://posturi.gov.ro/anunt/fixture-1001', $r['source_url'], 'the official URL: the identity of saved.js');
    assert_same('Spitalul Clinic Județean Cluj', $r['employer'], 'employer');
    assert_same(E_CLUJ, $r['employer_slug'], 'employer slug');
    assert_same('Cluj', $r['judet'], 'county');
    assert_same('Cluj-Napoca, Cluj', $r['place'], 'place, as on the list');
    assert_same('sănătate', $r['domain'], 'professional family');
    assert_same('2026-10-01', $r['published_at'], 'published date');
    assert_same('2026-10-16', $r['apply_deadline'], 'deadline');
    assert_same('anunt', $r['deadline_source'], 'deadline provenance');
    assert_same([0], $r['src'], 'one source');
    // The salary is the figure of the list card, with its salary-filter bucket.
    $sal = list_salary(db_row($GLOBALS['db_path'], 1001));
    assert_same($sal['text'], $r['salary']['text'], 'the salary text of the list card');
    assert_same($sal['estimated'], $r['salary']['estimated'], 'estimated flag');
    assert_same('net-sub-3000', $r['salary']['bucket'], 'bucket as the salary filter reads it');
    assert_same(2485, $r['salary']['net'], 'net lower figure');
    // Newest first.
    $dates = array_column($j['rows'], 'published_at');
    $sorted = $dates; rsort($sorted);
    assert_same($sorted, $dates, 'newest first');
});

/** One fixture row, for the helpers that take a row array. */
function db_row(string $path, int $id): array {
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo->query("SELECT * FROM job_postings WHERE id = $id")->fetch();
}

run_suite('sources: a search, an employer and saved ids; a posting in several sources appears once', function () use ($base) {
    [, $j] = flux($base, flux_qs([K_CLUJ], [E_CLUJ, E_BACAU], [1001, 1002, 1008, 1003]));
    assert_same(['search', 'employer', 'employer', 'saved'], array_column($j['sources'], 'kind'), 'the order: searches, employers, saved');
    assert_same([K_CLUJ, E_CLUJ, E_BACAU, 'saved'], array_column($j['sources'], 'ref'), 'refs');
    $ids = array_column($j['rows'], 'id');
    assert_same(count($ids), count(array_unique($ids)), 'every posting once');
    $by = array_column($j['rows'], null, 'id');
    assert_same([0, 1, 3], $by[1001]['src'], '1001 is in the search, the employer and the saved ids');
    assert_same([0, 1], $by[1006]['src'], '1006: the search and the employer');
    assert_same([2, 3], $by[1002]['src'], '1002: the second employer and the saved ids');
    assert_same([3], $by[1008]['src'], '1008: saved only');
    assert_false(isset($by[1003]), 'a closed saved id is not returned');
    assert_same(3, $j['sources'][3]['total'], 'saved: 3 of the 4 ids are open');
    assert_same(3, $j['sources'][0]['total'], 'the search finds the 3 open Cluj postings');
    assert_same([1001, 1002, 1004, 1006, 1007, 1008], (function () use ($ids) { sort($ids); return $ids; })(), 'the union of the sources, once each');
});

run_suite('a search goes through the list filter and the open status is forced', function () use ($base) {
    [, $j] = flux($base, flux_qs(['status=all']));
    assert_same('search', $j['sources'][0]['kind'], 'the source is kept');
    assert_false(in_array(1003, array_column($j['rows'], 'id'), true), 'status=all in a saved key does not list closed postings');
    assert_same(37, $j['sources'][0]['total'], 'status=all means the open postings (37 in the fixture)');
    [, $j] = flux($base, flux_qs(['status=closed']));
    assert_same(0, $j['sources'][0]['total'], 'a saved search of closed postings has no open posting to show');
    assert_same([], $j['rows'], 'no rows');
    [, $j] = flux($base, flux_qs(['status=soon']));
    assert_true(in_array(1002, array_column($j['rows'], 'id'), true), 'status=soon keeps its meaning: 1002 closes today');
    assert_false(in_array(1001, array_column($j['rows'], 'id'), true), 'status=soon: 1001 closes in 13 days');
});

run_suite('unknown sources are reported, not fatal', function () use ($base) {
    [$st, $j] = flux($base, flux_qs(['not-a-key', 'judet=cluj'], ['nu-exista', E_CLUJ], [999999]));
    assert_same(200, $st, 'status 200');
    assert_same([true, true, true], [$j['sources'][0]['unknown'] ?? false, $j['sources'][1]['unknown'] ?? false, $j['sources'][2]['unknown'] ?? false],
        'a string that is not a canonical key, a non-canonical spelling and an unknown slug are flagged');
    assert_false(isset($j['sources'][3]['unknown']), 'a known employer is not flagged');
    assert_same(0, $j['sources'][2]['total'], 'an unknown slug: total 0');
    assert_same('saved', $j['sources'][4]['kind'], 'the saved source is there');
    assert_same(0, $j['sources'][4]['total'], 'an id that does not exist: total 0');
    assert_same(3, count($j['rows']), 'only the known employer contributes rows');
});

run_suite('the page: three tabs, Flux first and the default; robots', function () use ($base) {
    [$st, , $html] = http($base, '/salvate/');
    assert_same(200, $st, '/salvate/ opens');
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $x = new DOMXPath($dom);
    $tabs = [];
    foreach ($x->query('//a[@data-view-tab]') as $a) $tabs[] = [$a->getAttribute('data-view-tab'), trim($a->textContent), $a->getAttribute('href'), $a->getAttribute('aria-current')];
    assert_same([['flux', 'Flux', '/salvate/', 'page'], ['saved', 'Liste', '/salvate/?vedere=liste', ''], ['hidden', 'Ascunse', '/salvate/?vedere=ascunse', '']],
        array_map(fn($t) => $t[0] === 'hidden' ? [$t[0], preg_replace('/\s*\(.*$/', '', $t[1]), $t[2], $t[3]] : $t, $tabs), 'the tabs, in order; Flux is current');
    assert_false($x->query('//section[@data-flux-panel]')->item(0)->hasAttribute('hidden'), 'the Flux section is shown');
    assert_true($x->query('//div[@data-view-panel="saved"]')->item(0)->hasAttribute('hidden'), 'the Liste group is hidden');
    foreach (['liste' => 'saved', 'ascunse' => 'hidden', 'notite' => 'saved', 'cautari' => 'saved', 'zzz' => 'flux'] as $v => $tab) {
        [, , $h] = http($base, '/salvate/?vedere=' . $v);
        $d = new DOMDocument();
        @$d->loadHTML('<?xml encoding="UTF-8">' . $h, LIBXML_NOERROR | LIBXML_NOWARNING);
        $cur = (new DOMXPath($d))->query('//a[@data-view-tab and @aria-current="page"]')->item(0);
        assert_same($tab, $cur ? $cur->getAttribute('data-view-tab') : null, "?vedere=$v opens the tab $tab");
    }
    [, , $liste] = http($base, '/salvate/?vedere=liste');
    foreach (['data-alerts-block', 'data-searches-panel', 'data-employers-panel', 'data-saved-list', 'data-clear-employers'] as $hook) {
        assert_contains($hook, $liste, "Liste carries $hook");
    }
    $order = array_map(fn($h) => strpos($liste, $h), ['data-alerts-block', 'data-searches-panel', 'data-employers-panel', 'data-saved-heading', 'id="saved-tools"']);
    $sorted = $order; sort($sorted);
    assert_same($sorted, $order, 'Liste order: alerts, searches, employers, saved postings, Gestionare');
    assert_contains('Disallow: /flux.json', http($base, '/robots.txt')[2], 'robots disallows /flux.json');
    assert_contains('Disallow: /salvate/', http($base, '/robots.txt')[2], 'robots still disallows /salvate/');
});

stop_fixture_server($srv, $db_path);

// ---- A bigger export: the cap of 100 per source and of 300 in all ----

$big_path = sys_get_temp_dir() . '/posturi-flux-big-' . getmypid() . '.sqlite';
build_fixture_db($big_path);
$w = new PDO('sqlite:' . $big_path);
$w->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// 120 more open postings for each of the three employers, ids 3000+, published a minute apart (the old ids are the oldest).
$w->exec("CREATE TEMP TABLE proto AS SELECT * FROM job_postings WHERE id = 2001");
for ($e = 1; $e <= 3; $e++) {
    for ($i = 0; $i < 120; $i++) {
        $id = 3000 + ($e - 1) * 1000 + $i;
        $w->exec("UPDATE proto SET id = $id, url = 'https://posturi.gov.ro/anunt/big-$id', employer_id = $e,
                  published_at = datetime('2026-10-02 09:00:00', '+$i minutes'), title = 'Post mare $id'");
        $w->exec("INSERT INTO job_postings SELECT * FROM proto");
    }
}
$srv2 = start_fixture_server($big_path);
$base2 = $srv2['base'];

run_suite('truncated: 100 rows per source, 300 in all', function () use ($base2) {
    [, $j] = flux($base2, flux_qs([], [E_CLUJ]));
    assert_same(100, count($j['rows']), 'one employer: at most 100 rows');
    assert_same(123, $j['sources'][0]['total'], 'total is the count of open postings (3 + 120)');
    assert_true($j['sources'][0]['truncated'], 'truncated is set');
    $dates = array_column($j['rows'], 'published_at');
    $sorted = $dates; rsort($sorted);
    assert_same($sorted, $dates, 'the newest 100');
    assert_false(in_array(1001, array_column($j['rows'], 'id'), true), 'the old postings are the ones left out');

    [, $j] = flux($base2, flux_qs([], [E_CLUJ, E_BACAU, E_ILFOV]));
    assert_same(300, count($j['rows']), 'three full sources: exactly 300 rows');
    foreach ($j['sources'] as $s) assert_true($s['truncated'], 'each source is truncated: ' . $s['ref']);

    // Saved ids that no source shows come last, and the 300 cap cuts them.
    $old = [1001, 1006, 1007, 1002, 1004];
    [, $j] = flux($base2, flux_qs([], [E_CLUJ, E_BACAU, E_ILFOV], $old));
    assert_same(300, count($j['rows']), 'the cap of 300 holds with saved ids too');
    $saved = $j['sources'][3];
    assert_same('saved', $saved['kind'], 'the saved source');
    assert_same(5, $saved['total'], 'five open saved ids');
    assert_true($saved['truncated'], 'the saved source is truncated: its rows were cut by the cap of 300');
    $pos = array_flip(array_column($j['rows'], 'id'));
    $cut = array_filter($old, fn($id) => !isset($pos[$id]));
    assert_same(5, count($cut), 'all five old postings fall after the 300 newest');
});

stop_fixture_server($srv2, $big_path);

finish();
