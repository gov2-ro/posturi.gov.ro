<?php
/**
 * DATA-ORG-06 — the CUI and the org.gov2.ro link on the employer page (brief 2026-11-23).
 *
 * Checked: CUI and link; link only; nothing (no row); nothing for a `P` record or a foreign host; no table
 * (an older export); and ops/build-employer-org.py (no `unmatched` row, no `P` record, no candidate CUI,
 * bad CIF and bad URL rejected).
 *
 * Usage: php tests/employer_org_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-eorg-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
$pdo = new PDO('sqlite:' . $db_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("INSERT INTO employers VALUES (4, 'Angajator Propus', 'angajator-propus'), (5, 'Angajator Strain', 'angajator-strain')");
$pdo->exec("CREATE TABLE employer_org (employer_id INTEGER PRIMARY KEY REFERENCES employers(id), org_id TEXT NOT NULL, cif TEXT, org_url TEXT NOT NULL)");
$pdo->exec("INSERT INTO employer_org VALUES
    (1, 'A4305857', '4305857', 'https://org.gov2.ro/#id=A4305857'),
    (2, 'A99', NULL, 'https://org.gov2.ro/#id=A99'),
    (4, 'Pangajator-propus', '12345678', 'https://org.gov2.ro/#id=Pangajator-propus'),
    (5, 'A55', '555', 'https://evil.example/#id=A55')");
$old_path = $db_path . '.old';
copy($db_path, $old_path);
$old = new PDO('sqlite:' . $old_path);
$old->exec("DROP TABLE employer_org");
$old = null;
$pdo = null;
putenv('POSTURI_DB=' . $db_path);
$srv = start_fixture_server($db_path, '2026-10-03');
$srv_old = start_fixture_server($old_path, '2026-10-03');

function eo_get(string $base, string $path): array {
    $h = @file_get_contents($base . $path, false, stream_context_create(['http' => ['ignore_errors' => true]]));
    $code = 0;
    foreach ($http_response_header ?? [] as $line) if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) $code = (int)$m[1];
    return [$code, (string)$h];
}

run_suite('CUI and link', function () use ($srv) {
    [$code, $h] = eo_get($srv['base'], '/angajator/spitalul-clinic-judetean-cluj/');
    assert_same(200, $code, 'status');
    assert_contains('data-employer-org', $h, 'the line');
    assert_contains('CUI 4305857 · ', $h, 'the CUI');
    assert_contains('href="https://org.gov2.ro/#id=A4305857"', $h, 'the link');
    assert_contains('Fișa instituției pe org.gov2.ro →', $h, 'the label');
    assert_true(strpos($h, 'CUI 4305857') > strpos($h, '<h1'), 'the line is under the name');
    assert_not_contains('target="_blank"', substr($h, strpos($h, 'data-employer-org'), 300), 'same tab');
});

run_suite('link only, when there is no CUI', function () use ($srv) {
    [, $h] = eo_get($srv['base'], '/angajator/primaria-comunei-gaiceana/');
    assert_contains('href="https://org.gov2.ro/#id=A99"', $h, 'the link');
    assert_not_contains('CUI', $h, 'no CUI');
});

run_suite('nothing, when there is no row, a P record or a foreign host', function () use ($srv) {
    foreach (['administratia-de-salubrizare-tunari', 'angajator-propus', 'angajator-strain'] as $slug) {
        [$code, $h] = eo_get($srv['base'], "/angajator/$slug/");
        assert_same(200, $code, "$slug status");
        assert_not_contains('data-employer-org', $h, "$slug: no line");
        assert_not_contains('Fișa instituției pe org.gov2.ro', $h, "$slug: no label");
        assert_not_contains('12345678', $h, "$slug: no CUI");
        assert_not_contains('evil.example', $h, "$slug: no foreign link");
    }
});

run_suite('an export without the table shows nothing', function () use ($srv_old) {
    [$code, $h] = eo_get($srv_old['base'], '/angajator/spitalul-clinic-judetean-cluj/');
    assert_same(200, $code, 'status');
    assert_not_contains('data-employer-org', $h, 'no line');
});

run_suite('ops/build-employer-org.py keeps only verified rows', function () {
    $dir = sys_get_temp_dir() . '/posturi-eorg-ops-' . getmypid();
    mkdir($dir);
    $cols = ['slug', 'employer_name', 'county', 'postings', 'last_seen', 'status', 'org_id', 'org_name', 'cif', 'link_tier', 'candidate_cifs', 'org_url'];
    $rows = [
        ['b-ok', 'B', '', 1, '', 'linked', 'A1', 'B', '111', 'exact', '', 'https://org.gov2.ro/#id=A1'],
        ['a-nocif', 'A', '', 1, '', 'linked_no_cif', 'A2', 'A', '', 'exact', '', 'https://org.gov2.ro/#id=A2'],
        ['c-unmatched', 'C', '', 1, '', 'unmatched', 'Pc', 'C', '', '', '999', 'https://org.gov2.ro/#id=Pc'],
        ['d-proposed', 'D', '', 1, '', 'linked', 'Pd', 'D', '222', 'exact', '', 'https://org.gov2.ro/#id=Pd'],
        ['e-badcif', 'E', '', 1, '', 'linked', 'A5', 'E', '12ab', 'exact', '', 'https://org.gov2.ro/#id=A5'],
        ['f-badurl', 'F', '', 1, '', 'linked', 'A6', 'F', '333', 'exact', '', 'http://org.gov2.ro/#id=A6'],
        ['g-cand', 'G', '', 1, '', 'linked_no_cif', 'A7', 'G', '', 'exact', '777', 'https://org.gov2.ro/#id=A7'],
    ];
    $fh = fopen("$dir/crosswalk.csv", 'w');
    fputcsv($fh, $cols, ',', '"', '');
    foreach ($rows as $r) fputcsv($fh, $r, ',', '"', '');
    fclose($fh);
    $script = escapeshellarg(__DIR__ . '/../../ops/build-employer-org.py');
    $out = shell_exec("python3 $script --from " . escapeshellarg("$dir/crosswalk.csv") . " --out " . escapeshellarg("$dir/out.csv") . " 2>&1");
    assert_contains('rejected: 3', (string)$out, 'three rows rejected (P record, bad CIF, bad URL)');
    $lines = array_map('trim', file("$dir/out.csv"));
    assert_same('slug,status,org_id,cif,org_url', $lines[0], 'header');
    assert_same(['a-nocif', 'b-ok', 'g-cand'], array_map(fn($l) => explode(',', $l)[0], array_slice($lines, 1)), 'sorted by slug; verified rows only');
    $all = implode("\n", $lines);
    assert_not_contains('Pc', $all, 'no unmatched row');
    assert_not_contains('Pd', $all, 'no P record');
    assert_not_contains('999', $all, 'no candidate CUI');
    assert_not_contains('777', $all, 'no candidate CUI (linked_no_cif)');
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);
});

stop_fixture_server($srv_old, $old_path);
stop_fixture_server($srv, $db_path);
finish();
