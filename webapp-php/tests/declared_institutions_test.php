<?php
/**
 * SALARY-DECLARATII-PAGE — the declared pay per institution (ADR 0008 rule 9, brief 2026-11-20).
 *
 * Fixture `fixture_seed_declared_institutions()`: 62 institutions (`comuna-gaiceana-bacau` with employer 2 and three
 * groups; one with no employer; 60 fillers in Bacău and Cluj). Checked: the tables (counts, unique slugs, no group
 * under 5 declarants), the partial, the page of an institution, the section on the employer page, the index (search,
 * county filter, paging, empty search), the 404, the sitemap, the links to the index, and an export without the tables.
 *
 * Usage: php tests/declared_institutions_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-declinst-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
$pdo = new PDO('sqlite:' . $db_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
fixture_seed_law_estimates($pdo);
fixture_seed_declared_estimate($pdo);
$tables = [
    'institutions' => (int)$pdo->query("SELECT COUNT(*) FROM declared_institutions")->fetchColumn(),
    'distinct_slugs' => (int)$pdo->query("SELECT COUNT(DISTINCT slug) FROM declared_institutions")->fetchColumn(),
    'groups' => (int)$pdo->query("SELECT COUNT(*) FROM declared_groups")->fetchColumn(),
    'min_n' => (int)$pdo->query("SELECT MIN(n) FROM declared_groups")->fetchColumn(),
    'orphans' => (int)$pdo->query("SELECT COUNT(*) FROM declared_groups WHERE institution_slug NOT IN (SELECT slug FROM declared_institutions)")->fetchColumn(),
    'matched' => (int)$pdo->query("SELECT COUNT(*) FROM declared_institutions WHERE employer_id IS NOT NULL")->fetchColumn(),
];
// A copy without the tables: an export that predates this feature.
$old_path = $db_path . '.old';
copy($db_path, $old_path);
$old = new PDO('sqlite:' . $old_path);
$old->exec("DROP TABLE declared_groups; DROP TABLE declared_institutions");
$old = null;
$pdo = null;
putenv('POSTURI_DB=' . $db_path);
$srv = start_fixture_server($db_path, '2026-10-03');
$srv_old = start_fixture_server($old_path, '2026-10-03');
$base = $srv['base'];
function di_get(string $base, string $path): array {
    $h = @file_get_contents($base . $path, false, stream_context_create(['http' => ['ignore_errors' => true]]));
    $code = 0;
    foreach ($http_response_header ?? [] as $line) if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) $code = (int)$m[1];
    return [$code, (string)$h];
}

run_suite('the tables: counts, unique slugs, no group under 5 declarants', function () use ($tables) {
    assert_same(62, $tables['institutions'], 'institutions');
    assert_same(62, $tables['distinct_slugs'], 'the slugs are unique');
    assert_same(3 + 1 + 60, $tables['groups'], 'groups');
    assert_true($tables['min_n'] >= 5, 'no group has fewer than 5 declarants');
    assert_same(0, $tables['orphans'], 'every group has an institution');
    assert_same(1, $tables['matched'], 'one institution has an employer');
});

run_suite('the institution page: heading, scope line, table, note, four steps, link to the employer', function () use ($base) {
    [$code, $h] = di_get($base, '/salarii-declarate/comuna-gaiceana-bacau/');
    assert_same(200, $code, 'status');
    assert_contains('<h1 class="g2-heading-page mt-4">Comuna Găiceana</h1>', $h, 'the name');
    assert_contains('Cât câștigă angajații care depun declarații de avere', $h, 'the heading of the partial');
    assert_true(strpos($h, 'Doar funcționarii și conducerea') < strpos($h, '<table'), 'the scope line comes before the table');
    assert_contains('Personalul contractual (de exemplu șoferi, muncitori, asistenți) nu apare aici.', $h, 'the scope line');
    assert_contains('g2-table g2-table-stacked', $h, 'the stacked DS table');
    foreach (['Funcția', 'Declaranți', 'Net pe lună, tipic', 'Cei mai mulți între'] as $col) assert_contains('>' . $col . '</th>', $h, "column $col");
    assert_true(strpos($h, 'Consilier superior') < strpos($h, 'Referent') && strpos($h, 'Referent') < strpos($h, 'Inspector asistent'), 'largest group first');
    assert_contains('6.850 lei', $h, 'the median');
    assert_contains('6.000–7.600 lei', $h, 'the band');
    assert_contains('Venitul din 2023, adus la nivelul din 2026 cu creșterea salariilor din administrația publică (INS, +15 %). Eroare tipică: 7 %.', $h, 'the note: the largest error, 6,7 %');
    assert_contains('Cum am calculat ▾', $h, 'the disclosure');
    assert_same(4, substr_count($h, 'data-explainer-step='), 'four steps');
    assert_contains('În 2023, 54 de angajați la Comuna Găiceana', $h, 'step 1 names the institution');
    assert_contains('la această instituție', $h, 'step 1 says "la această instituție"');
    assert_contains('href="/estimator-salariu/#metoda-declaratii"', $h, 'the link to the method');
    assert_contains('Salarii declarate</a>', $h, 'breadcrumb');
    assert_contains('aria-current="page">Comuna Găiceana', $h, 'the current crumb');
    assert_contains('href="/angajator/primaria-comunei-gaiceana/"', $h, 'the link to the employer');
    assert_contains('Posturi la acest angajator →', $h, 'the label of the link');
    assert_not_contains('la gradația 0', $h, 'no grid wording');
    foreach (['mai mare decât', 'peste mediana', 'față de alți'] as $cmp) assert_not_contains($cmp, $h, "no comparison: $cmp");
    assert_well_formed($h, 'institution page');
});

run_suite('an institution with no employer has no link to postings', function () use ($base) {
    [$code, $h] = di_get($base, '/salarii-declarate/primaria-test-sans-angajator-cluj/');
    assert_same(200, $code, 'status');
    assert_not_contains('Posturi la acest angajator', $h, 'no link');
    assert_contains('Consilier', $h, 'the group');
});

run_suite('an unknown slug is a 404', function () use ($base) {
    [$code] = di_get($base, '/salarii-declarate/nu-exista/');
    assert_same(404, $code, 'status');
});

run_suite('the employer page: the section between the counts and the postings, and a link', function () use ($base) {
    [$code, $h] = di_get($base, '/angajator/primaria-comunei-gaiceana/');
    assert_same(200, $code, 'status');
    assert_contains('data-employer-declared', $h, 'the section');
    assert_contains('Consilier superior', $h, 'the table');
    assert_true(strpos($h, 'Total anunțuri') < strpos($h, 'data-employer-declared'), 'after the counts');
    assert_true(strpos($h, 'data-employer-declared') < strpos($h, 'Anunțuri active'), 'before the postings');
    assert_contains('href="/salarii-declarate/comuna-gaiceana-bacau/"', $h, 'the link to the page of the institution');
    [, $other] = di_get($base, '/angajator/spitalul-clinic-judetean-cluj/');
    assert_not_contains('data-employer-declared', $other, 'no section without a match');
});

run_suite('the index: intro, form, 50 per page, paging', function () use ($base) {
    [$code, $h] = di_get($base, '/salarii-declarate/');
    assert_same(200, $code, 'status');
    assert_contains('declarațiile de avere din 2023', $h, 'the intro: the source');
    assert_contains('Personalul contractual nu apare aici.', $h, 'the intro: contract staff');
    assert_contains('href="/estimator-salariu/#metoda-declaratii"', $h, 'the link to the method');
    assert_contains('name="q"', $h, 'the search field');
    assert_contains('name="judet"', $h, 'the county filter');
    assert_contains('62 de instituții', $h, 'the count');
    assert_same(50, substr_count($h, 'data-declared-item'), '50 rows on page 1');
    assert_contains('class="g2-pagination', $h, 'the DS pagination');
    assert_contains('Pagina <span class="g2-mono">1</span> din <span class="g2-mono">2</span>', $h, 'the position line');
    [, $p2] = di_get($base, '/salarii-declarate/?pagina=2');
    assert_same(12, substr_count($p2, 'data-declared-item'), '12 rows on page 2');
    assert_contains('rel="prev"', $p2, 'previous link');
    [, $p9] = di_get($base, '/salarii-declarate/?pagina=9');
    assert_same(12, substr_count($p9, 'data-declared-item'), 'a page past the end shows the last page');
    assert_true(strpos($h, 'Comuna Găiceana') < strpos($h, 'Comuna Umplutură 01'), 'sorted by name');
});

run_suite('the index: search (no diacritics needed) and county filter, in the address', function () use ($base) {
    [, $h] = di_get($base, '/salarii-declarate/?q=gaiceana');
    assert_same(1, substr_count($h, 'data-declared-item'), 'one hit for "gaiceana"');
    [, $h] = di_get($base, '/salarii-declarate/?q=' . rawurlencode('Găiceana'));
    assert_same(1, substr_count($h, 'data-declared-item'), 'one hit for "Găiceana"');
    [, $h] = di_get($base, '/salarii-declarate/?judet=cluj');
    assert_same(31, substr_count($h, 'data-declared-item'), 'Cluj: 30 fillers and the institution with no employer');
    assert_contains('31 de instituții', $h, 'count with "de"');
    [, $h] = di_get($base, '/salarii-declarate/?judet=bacau&q=umplutura');
    assert_same(30, substr_count($h, 'data-declared-item'), 'both filters');
    assert_contains('<option value="bacau" selected>Bacău</option>', $h, 'the county stays selected');
    [, $h] = di_get($base, '/salarii-declarate/?judet=nu-exista');
    assert_same(50, substr_count($h, 'data-declared-item'), 'an unknown county is ignored (page 1 of 2: 50 rows)');
});

run_suite('the index: an empty search says so in one sentence and links to clear the filters', function () use ($base) {
    [$code, $h] = di_get($base, '/salarii-declarate/?q=zzzzzz');
    assert_same(200, $code, 'status');
    assert_contains('Nicio instituție nu corespunde căutării.', $h, 'the sentence');
    assert_contains('<a href="/salarii-declarate/" class="text-gov underline">Șterge filtrele</a>', $h, 'the link');
    assert_same(0, substr_count($h, 'data-declared-item'), 'no rows');
    assert_not_contains('g2-pagination', $h, 'no paging');
});

run_suite('the index and the page of an institution are in the sitemap; the method section links to the index', function () use ($base) {
    [, $x] = di_get($base, '/sitemap.xml');
    assert_contains('/salarii-declarate/</loc>', $x, 'the index');
    assert_contains('/salarii-declarate/comuna-gaiceana-bacau/</loc>', $x, 'an institution');
    assert_same(62, substr_count($x, '/salarii-declarate/') - 1, '62 institution pages');
    [, $m] = di_get($base, '/estimator-salariu/');
    assert_contains('data-declared-index-link', $m, 'the link in the method section');
    foreach (['/', '/despre/'] as $path) {
        [, $page] = di_get($base, $path);
        assert_not_contains('/salarii-declarate/', $page, "the navigation and the footer do not change ($path)");
    }
});

run_suite('an export without the tables: no section, no link, a 404 for the page, a clear index', function () use ($srv_old) {
    $b = $srv_old['base'];
    [$code] = di_get($b, '/salarii-declarate/comuna-gaiceana-bacau/');
    assert_same(404, $code, 'the institution page');
    [$code, $h] = di_get($b, '/salarii-declarate/');
    assert_same(200, $code, 'the index');
    assert_contains('data-declared-unavailable', $h, 'the index says the data is not available');
    [$code, $h] = di_get($b, '/angajator/primaria-comunei-gaiceana/');
    assert_same(200, $code, 'the employer page');
    assert_not_contains('data-employer-declared', $h, 'no section');
    [, $x] = di_get($b, '/sitemap.xml');
    assert_not_contains('/salarii-declarate/', $x, 'sitemap');
    [, $m] = di_get($b, '/estimator-salariu/');
    assert_not_contains('data-declared-index-link', $m, 'no link');
});

stop_fixture_server($srv_old, $old_path);
stop_fixture_server($srv, $db_path);
finish();
