<?php
/**
 * REV-01 — old-export compatibility.
 *
 * Code deploys (from the Mac) and data deploys (from the VPS) are separate, so
 * new PHP code can be served against an export written by older pipeline code.
 * FIX-05's application_status made every list/count/feed query a fatal on such
 * an export. This suite derives a "legacy" export from the fixture by dropping
 * the columns the 2c5e480 export did not have, then checks that the db() shim
 * reproduces the export's status vocabulary row for row and that every route
 * renders without a fatal or warning.
 *
 * PHP-COMPAT-01 adds two more shapes: an export that has application_status but
 * no v4_* columns ("v3-era"), and one that also lacks the other columns PHP
 * queries (occupation, salary, contract facets) — the 2026-09-09 bundle.
 *
 * Usage: php tests/compat_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

const COMPAT_TODAY = '2026-10-03';
putenv('POSTURI_TODAY=' . COMPAT_TODAY);

$tmp = sys_get_temp_dir() . '/posturi-compat-' . getmypid();
$current = "$tmp-current.sqlite";
$legacy  = "$tmp-legacy.sqlite";
build_fixture_db($current);
copy($current, $legacy);

// The live export at 2c5e480 lacked these; older ones lack more (see the shim).
$dropped = [
    'job_postings' => ['application_status', 'detail_fetched_at', 'detail_content_hash'],
    'build_meta'   => ['run_id', 'index_checked_at', 'index_scan_pages', 'index_scan_complete',
                       'detail_fetched_at_max', 'detail_fetched_rows'],
];
$ldb = new PDO("sqlite:$legacy", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ($ldb->query("SELECT name FROM sqlite_master WHERE type = 'index' AND sql LIKE '%application_status%'")
         ->fetchAll(PDO::FETCH_COLUMN) as $idx) {
    $ldb->exec("DROP INDEX \"$idx\"");
}
foreach ($dropped as $table => $cols) {
    $have = $ldb->query("SELECT name FROM pragma_table_info('$table')")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_intersect($cols, $have) as $col) {
        $ldb->exec("ALTER TABLE $table DROP COLUMN $col");
    }
}
$ldb = null;

// An export from after FIX-05 but before prompt v4: application_status is there,
// the v4_* columns are not. The first shim returned as soon as it saw the status.
$nov4 = "$tmp-nov4.sqlite";
copy($current, $nov4);
// The bundled 2026-09-09 export, reduced to what PHP queries: no status, no
// resolved deadline, none of the later extraction columns.
$oldest = "$tmp-oldest.sqlite";
copy($current, $oldest);
$strip = [
    $nov4   => ['v4_funding_source', 'v4_funding_programme', 'v4_employer_sector',
                'v4_parent_institution', 'v4_application_deadline'],
    $oldest => ['application_status', 'apply_deadline', 'deadline_source',
                'v3_contract_duration', 'v3_schedule', 'v3_shift_work', 'occ_canonical',
                'sal_min', 'sal_json', 'v4_funding_source', 'v4_funding_programme',
                'v4_employer_sector', 'v4_parent_institution', 'v4_application_deadline'],
];
foreach ($strip as $file => $cols) {
    $sdb = new PDO("sqlite:$file", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ($cols as $col) {
        foreach ($sdb->query("SELECT name FROM sqlite_master WHERE type = 'index' AND sql LIKE '%$col%'")
                 ->fetchAll(PDO::FETCH_COLUMN) as $idx) {
            $sdb->exec("DROP INDEX \"$idx\"");
        }
        $sdb->exec("ALTER TABLE job_postings DROP COLUMN $col");
    }
    $sdb = null;
}

run_suite('shim reproduces the exported status vocabulary row for row', function () use ($current, $legacy) {
    $want = (new PDO("sqlite:$current"))
        ->query("SELECT id, application_status FROM job_postings ORDER BY id")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $pdo = new PDO("sqlite:$legacy", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    shim_legacy_export($pdo);
    $got = $pdo->query("SELECT id, application_status FROM job_postings ORDER BY id")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_true(count($want) > 0, 'fixture has rows');
    assert_same($want, $got, 'computed status equals the exported one');
    assert_true(count(array_unique($got)) >= 3, 'fixture covers several statuses');

    // A current export is left alone: no temp view is created.
    $cur = new PDO("sqlite:$current", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    shim_legacy_export($cur);
    $views = $cur->query("SELECT count(*) FROM sqlite_temp_master WHERE type = 'view'")->fetchColumn();
    assert_same(0, (int)$views, 'current export is not shimmed');
});

run_suite('shim covers each missing column on its own', function () use ($current, $nov4, $oldest) {
    $open = function (string $f): PDO {
        return new PDO("sqlite:$f", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    };
    $views = fn(PDO $p) => (int)$p->query("SELECT count(*) FROM sqlite_temp_master WHERE type = 'view'")->fetchColumn();
    $v4 = ['v4_funding_source', 'v4_funding_programme', 'v4_employer_sector', 'v4_parent_institution',
           'v4_application_deadline'];

    // Has application_status, lacks v4_*: wrapped, v4_* readable, status untouched.
    $pdo = $open($nov4);
    $have = $pdo->query("SELECT name FROM pragma_table_info('job_postings')")->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('application_status', $have, true) && !array_intersect($v4, $have),
        'fixture has application_status and no v4_* columns');
    shim_legacy_export($pdo);
    assert_same(1, $views($pdo), 'v4-less export is wrapped in a view');
    $row = $pdo->query("SELECT " . implode(', ', $v4) . " FROM job_postings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assert_same(['v4_funding_source' => '', 'v4_funding_programme' => '', 'v4_employer_sector' => '',
                 'v4_parent_institution' => '', 'v4_application_deadline' => null], $row, 'v4_* defaults');
    $n = (int)$pdo->query("SELECT count(*) FROM job_postings WHERE v4_funding_source = 'fonduri_europene'")->fetchColumn();
    assert_same(0, $n, 'funding probe matches nothing');
    $want = $open($current)->query("SELECT id, application_status FROM job_postings ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $got = $pdo->query("SELECT id, application_status FROM job_postings ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_same($want, $got, 'exported application_status is passed through unchanged');

    // The oldest shape: every queried column is available through the view.
    $pdo = $open($oldest);
    shim_legacy_export($pdo);
    assert_same(1, $views($pdo), 'oldest export is wrapped in a view');
    foreach (['application_status', 'apply_deadline', 'deadline_source', 'v3_contract_duration',
              'v3_schedule', 'v3_shift_work', 'occ_canonical', 'sal_min'] as $col) {
        $pdo->query("SELECT $col FROM job_postings LIMIT 1")->fetchAll();
    }
    assert_true(true, 'every queried column resolves in the oldest export');

    // A current export is not wrapped (also asserted for the status case above).
    $cur = $open($current);
    shim_legacy_export($cur);
    assert_same(0, $views($cur), 'current export is not wrapped in a view');
});

run_suite('shim works on a read-only file', function () use ($legacy) {
    chmod($legacy, 0444);   // as on a host whose document root is not writable
    $ro = new PDO("sqlite:$legacy", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ro->exec("PRAGMA temp_store=MEMORY");
    shim_legacy_export($ro);
    $ro->exec("PRAGMA query_only=1");
    $n = (int)$ro->query("SELECT count(*) FROM job_postings WHERE application_status IN (" . OPEN_STATUS_SQL . ")")
        ->fetchColumn();
    assert_true($n > 0, 'open rows counted through the view');
    chmod($legacy, 0644);
});

$paths = ['/', '/?status=closed', '/?status=unknown', '/?status=soon', '/?q=medic',
          '/?funding=fonduri_europene', '/?funding=buget_local', '/?sector=sanatate',
          '/?occupation=Referent', '/?duration=determinata', '/?schedule=norma_intreaga',
          '/?has_salary=1', '/?shift=1',
          '/angajatori/', '/statistici/', '/despre/', '/sitemap.xml',
          '/posturi.json', '/posturi.atom', '/posturi.ics', '/versiuni.json'];

// Each shape of old export is served for real and every route fetched.
$shapes = [
    'a legacy export'                           => $legacy,
    'an export with application_status, no v4_*' => $nov4,
    'the oldest export (no status, v3/v4 or salary columns)' => $oldest,
];
foreach ($shapes as $label => $file) {
    $srv = start_fixture_server($file, COMPAT_TODAY);
    run_suite("every route renders against $label", function () use ($srv, $paths) {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0]]);
        foreach ($paths as $p) {
            $body = (string)@file_get_contents($srv['base'] . $p, false, $ctx);
            preg_match('#^HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m);
            assert_same(200, (int)($m[1] ?? 0), "$p status");
            assert_not_contains('Fatal error', $body, "$p body");
            assert_not_contains('no such column', $body, "$p body");
        }
        $log = (string)@file_get_contents($srv['log']);
        assert_not_contains('Fatal', $log, 'server log: no fatals');
        assert_not_contains('Warning', $log, 'server log: no warnings');
        assert_not_contains('no such column', $log, 'server log: no missing columns');
    });
    stop_fixture_server($srv, $file);
}
@unlink($current);
finish();
