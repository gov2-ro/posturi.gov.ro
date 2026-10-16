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
 * DATA-04 adds the exports that predate `posting_roles`: a current export with
 * only that table missing, and the oldest shape without it, where the stand-in
 * view has to read through the job_postings view. Their requirement filters must
 * return exactly what the posting-level predicates return.
 *
 * UX-13 adds the exports that predate `requirement_evidence`: a current export with only
 * that table missing, and the oldest shape missing it too. The detail page reads the table
 * unconditionally, so an empty temp view of the same name must answer it.
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
$unsplit = "$tmp-unsplit.sqlite";
build_fixture_db($current, true);
// Exports from before PERF-SPLIT kept the long texts in job_postings; the column-drop
// variants below are all of that era, so they start from that layout.
build_fixture_db($unsplit, true, false, false);
copy($unsplit, $legacy);

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
copy($unsplit, $nov4);
// The bundled 2026-09-09 export, reduced to what PHP queries: no status, no
// resolved deadline, none of the later extraction columns.
$oldest = "$tmp-oldest.sqlite";
copy($unsplit, $oldest);
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

// BETA-V5: the export live before the v5 columns (every other table and column present).
$nov5 = "$tmp-nov5.sqlite";
copy($current, $nov5);
$vdb = new PDO("sqlite:$nov5", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ($vdb->query("SELECT name FROM pragma_table_info('job_postings') WHERE name LIKE 'v5\_%' ESCAPE '\\'")
         ->fetchAll(PDO::FETCH_COLUMN) as $col) {
    $vdb->exec("ALTER TABLE job_postings DROP COLUMN $col");
}
$vdb = null;

// DATA-04: exports from before posting_roles existed. `noroles` has every other
// column; `oldest-noroles` is the oldest shape with the table gone as well.
$noroles = "$tmp-noroles.sqlite";
copy($current, $noroles);
$oldest_noroles = "$tmp-oldest-noroles.sqlite";
copy($oldest, $oldest_noroles);
foreach ([$noroles, $oldest_noroles] as $file) {
    $rdb = new PDO("sqlite:$file", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $rdb->exec('DROP TABLE posting_roles');
    $rdb = null;
}

// UX-13: exports from before requirement_evidence existed. `noevidence` has every other
// table; `oldest-noevidence` is the oldest shape with posting_roles and the evidence table
// gone as well (three stacked stand-ins).
$noevidence = "$tmp-noevidence.sqlite";
copy($current, $noevidence);
$oldest_noevidence = "$tmp-oldest-noevidence.sqlite";
copy($oldest_noroles, $oldest_noevidence);
foreach ([$noevidence, $oldest_noevidence] as $file) {
    $edb = new PDO("sqlite:$file", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $edb->exec('DROP TABLE requirement_evidence');
    $edb = null;
}

// FIX-10: an export from before llm_costs had step/source/postings (and the oldest, with no
// llm_costs at all). The page deployed after the data must keep rendering against both.
$preledger = "$tmp-preledger.sqlite";
copy($current, $preledger);
$pdb = new PDO("sqlite:$preledger", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdb->exec('DROP TABLE llm_costs');
$pdb->exec('CREATE TABLE llm_costs (day TEXT NOT NULL, provider TEXT NOT NULL, model TEXT NOT NULL,
    prompt_version TEXT NOT NULL, calls INTEGER NOT NULL, input_tokens INTEGER NOT NULL,
    output_tokens INTEGER NOT NULL, cost_usd REAL NOT NULL, PRIMARY KEY (day, provider, model, prompt_version))');
$pdb->exec("INSERT INTO llm_costs VALUES ('2026-10-02', 'openrouter', 'fixture-model', 'v4', 3, 12000, 900, 0.01)");
$pdb = null;
$nollm = "$tmp-nollm.sqlite";
copy($current, $nollm);
$ndb = new PDO("sqlite:$nollm", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ndb->exec('DROP TABLE llm_costs');
$ndb = null;

// FIX-09: exports from before publication_monthly. `nohistory` has every other table;
// `oldest-nohistory` is the oldest shape (no status, no roles, no evidence) without it too.
$nohistory = "$tmp-nohistory.sqlite";
copy($current, $nohistory);
$oldest_nohistory = "$tmp-oldest-nohistory.sqlite";
copy($oldest_noevidence, $oldest_nohistory);
foreach ([$nohistory, $oldest_nohistory] as $file) {
    $hdb = new PDO("sqlite:$file", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $hdb->exec('DROP TABLE publication_monthly');
    $hdb = null;
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
    assert_same(2, $views($pdo), 'v4-less export is wrapped in a view (plus the posting_text stand-in)');
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
    assert_same(2, $views($pdo), 'oldest export is wrapped in a view (plus the posting_text stand-in)');
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

run_suite('a missing posting_roles table is stood in for by one implicit role per posting', function () use ($current, $noroles, $oldest_noroles) {
    $open = fn(string $f) => new PDO("sqlite:$f", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $views = fn(PDO $p) => $p->query("SELECT name FROM sqlite_temp_master WHERE type = 'view' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

    $cur = $open($current);
    shim_legacy_export($cur);
    assert_same([], $views($cur), 'a current export, table present, is not shimmed at all');

    $pdo = $open($noroles);
    shim_legacy_export($pdo);
    assert_same(['posting_roles'], $views($pdo), 'only the missing table is stood in for');
    $n = (int)$pdo->query('SELECT COUNT(*) FROM job_postings')->fetchColumn();
    assert_same($n, (int)$pdo->query('SELECT COUNT(*) FROM posting_roles')->fetchColumn(), 'one row per posting');
    assert_same($n, (int)$pdo->query('SELECT COUNT(DISTINCT posting_id) FROM posting_roles WHERE decomposed = 0 AND ordinal = 0')->fetchColumn(), 'each is the implicit role');
    $bad = (int)$pdo->query('SELECT COUNT(*) FROM posting_roles r JOIN job_postings j ON j.id = r.posting_id
        WHERE r.study_level IS NOT j.inf_studies_required OR r.eqf_level IS NOT j.v3_eqf_level
           OR r.isced_fields IS NOT j.v3_isced_fields OR r.exp_years IS NOT j.inf_experience_years
           OR r.skills IS NOT j.v3_skills OR r.credentials IS NOT j.v3_credentials')->fetchColumn();
    assert_same(0, $bad, 'the implicit role mirrors the flat search columns exactly');

    // Reading through the job_postings view of the oldest shape.
    $old = $open($oldest_noroles);
    shim_legacy_export($old);
    assert_same(['job_postings', 'posting_roles', 'posting_text'], $views($old), 'oldest shape: every stand-in');
    $on = (int)$old->query('SELECT COUNT(*) FROM job_postings')->fetchColumn();
    assert_same($on, (int)$old->query('SELECT COUNT(*) FROM posting_roles')->fetchColumn(), 'oldest shape: one row per posting');
    $old->query("SELECT COUNT(*) FROM job_postings j WHERE EXISTS (SELECT 1 FROM posting_roles r WHERE r.posting_id = j.id AND r.eqf_level IN (4) AND r.exp_years < CAST(1 AS REAL))")->fetchAll();
    assert_true(true, 'the role predicate resolves over the stacked views');
});

run_suite('a missing requirement_evidence table is stood in for by an empty view with the table\'s columns', function () use ($current, $noevidence, $oldest_noevidence) {
    $open = fn(string $f) => new PDO("sqlite:$f", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $views = fn(PDO $p) => $p->query("SELECT name FROM sqlite_temp_master WHERE type = 'view' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $cols = fn(PDO $p) => $p->query("SELECT name FROM pragma_table_info('requirement_evidence') ORDER BY cid")->fetchAll(PDO::FETCH_COLUMN);

    $cur = $open($current);
    shim_legacy_export($cur);
    assert_same([], $views($cur), 'a current export, table present, is not shimmed at all');
    $want = $cols($cur);
    assert_true(count($want) >= 15, 'the table has its columns');

    foreach (['an export with every column but no evidence table' => [$noevidence, ['requirement_evidence']],
              'the oldest shape, no roles and no evidence'        => [$oldest_noevidence, ['job_postings', 'posting_roles', 'posting_text', 'requirement_evidence']]] as $label => [$file, $expect]) {
        $pdo = $open($file);
        shim_legacy_export($pdo);
        assert_same($expect, $views($pdo), "$label: the missing tables are stood in for");
        assert_same($want, $cols($pdo), "$label: the view has the table's columns");
        assert_same(0, (int)$pdo->query('SELECT COUNT(*) FROM requirement_evidence')->fetchColumn(), "$label: no rows");
        $pdo->query('SELECT ordinal, fact_kind, fact_index, quote, status, reason, part, section, occurrences
                     FROM requirement_evidence WHERE posting_id = 1009 ORDER BY ordinal, fact_kind, fact_index')->fetchAll();
        assert_same([], load_requirement_evidence($pdo, 1009), "$label: the page's loader returns nothing");
    }

    // The stand-in is a TEMP view, so it works on a read-only file too (a host whose document root is not writable).
    chmod($noevidence, 0444);
    $ro = $open($noevidence);
    $ro->exec('PRAGMA temp_store=MEMORY');
    shim_legacy_export($ro);
    $ro->exec('PRAGMA query_only=1');
    assert_same([], load_requirement_evidence($ro, 1009), 'read-only file: the empty view answers');
    $ro = null;
    chmod($noevidence, 0644);
});

run_suite('BETA-V5: an export without the v5 columns reads them as empty', function () use ($nov5) {
    $pdo = new PDO("sqlite:$nov5", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $have = $pdo->query("SELECT name FROM pragma_table_info('job_postings')")->fetchAll(PDO::FETCH_COLUMN);
    assert_same([], array_values(array_filter($have, fn($c) => str_starts_with($c, 'v5_'))), 'fixture has no v5 column');
    shim_legacy_export($pdo);
    $row = $pdo->query("SELECT v5_recruitment_kind, v5_internal_only, v5_vacancies_total, v5_fte, v5_post_status,
        v5_end_date, v5_channels, v5_work_localities FROM job_postings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assert_same(['v5_recruitment_kind' => '', 'v5_internal_only' => null, 'v5_vacancies_total' => null, 'v5_fte' => null,
                 'v5_post_status' => '', 'v5_end_date' => null, 'v5_channels' => '[]', 'v5_work_localities' => '[]'],
        $row, 'v5 columns read as their empty values');
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
          '/?eqf%5B%5D=4', '/?eqf%5B%5D=6&exp_level%5B%5D=fara', '/?studies_level%5B%5D=licenta',
          '/?exp_level%5B%5D=5plus', '/?skill%5B%5D=Excel', '/?credential%5B%5D=autorizatie',
          '/?isced%5B%5D=04_afaceri_administratie_drept', '/?q=expert&eqf%5B%5D=6',
          job_url(['id' => 1008, 'title' => 'Mai multe posturi scoase la concurs']),
          job_url(['id' => 1009, 'title' => 'Expert contabil și referent (două roluri)']),
          job_url(['id' => 1011, 'title' => 'Șofer autospecială']),
          '/angajatori/', '/statistici/', '/despre/', '/sitemap.xml',
          '/posturi.json', '/posturi.atom', '/posturi.ics', '/versiuni.json'];

// Each shape of old export is served for real and every route fetched.
$shapes = [
    'a legacy export'                           => $legacy,
    'an export with application_status, no v4_*' => $nov4,
    'the oldest export (no status, v3/v4 or salary columns)' => $oldest,
    'an export with every column but no posting_roles'       => $noroles,
    'the oldest export without posting_roles'                => $oldest_noroles,
    'an export with every column but no requirement_evidence' => $noevidence,
    'the oldest export without roles or requirement_evidence' => $oldest_noevidence,
    'an export from before the LLM call ledger (llm_costs without step/source)' => $preledger,
    'an export with no llm_costs table'                       => $nollm,
    'an export from before the v5 columns (BETA-V5)'          => $nov5,
    'an export with no publication_monthly (FIX-09)'          => $nohistory,
    'the oldest export without roles, evidence or publication_monthly' => $oldest_nohistory,
];
// Requirement filters on an export without posting_roles = the posting-level predicates.
$flat_cases = [
    'eqf%5B%5D=4'                                    => 'v3_eqf_level IN (4)',
    'studies_level%5B%5D=licenta'                    => "inf_studies_required IN ('licenta')",
    'exp_level%5B%5D=fara'                           => '(inf_experience_years IS NULL OR inf_experience_years < 1)',
    'eqf%5B%5D=6&exp_level%5B%5D=fara'               => 'v3_eqf_level IN (6) AND (inf_experience_years IS NULL OR inf_experience_years < 1)',
    'skill%5B%5D=Contabilitate&credential%5B%5D=autorizatie'
        => "v3_skills LIKE '%\"Contabilitate\"%' AND v3_credentials LIKE '%\"autorizatie\"%'",
];
foreach ($shapes as $label => $file) {
    $srv = start_fixture_server($file, COMPAT_TODAY);
    if (in_array($file, [$noroles, $oldest_noroles], true)) {
        run_suite("requirement filters equal the posting-level predicates against $label", function () use ($srv, $file, $flat_cases) {
            $pdo = new PDO("sqlite:$file", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach ($flat_cases as $qs => $flat) {
                $want = array_map('intval', $pdo->query("SELECT id FROM job_postings WHERE $flat ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
                $json = json_decode((string)@file_get_contents($srv['base'] . '/posturi.json?status=all&' . $qs), true);
                $got = array_map(fn($r) => (int)$r['id'], $json['results'] ?? []);
                sort($got);
                assert_same($want, $got, "$qs");
            }
        });
    }
    if ($file === $preledger) {
        run_suite("the LLM spend section keeps its old, honest copy against $label", function () use ($srv) {
            $body = (string)@file_get_contents($srv['base'] . '/statistici/');
            assert_contains('$0.010', $body, 'old export: the total still shows');
            assert_contains('02.10.2026 · 3 anunțuri (estimare)', $body, 'old export: every row is an estimate');
            assert_contains('Estimare incompletă', $body, 'old export: says the figure is incomplete');
            assert_not_contains('Pe etape', $body, 'old export: no per-step breakdown it cannot support');
        });
    }
    if (in_array($file, [$nohistory, $oldest_nohistory], true)) {
        run_suite("the history section says indisponibil, with scoped current data, against $label", function () use ($srv) {
            $body = (string)@file_get_contents($srv['base'] . '/statistici/');
            assert_contains('Istoric indisponibil', $body, 'no aggregate: history is said to be unavailable');
            assert_contains('Luna publicării, doar în exportul curent', $body, 'the monthly chart is scoped to the export');
            assert_not_contains('Anunțuri publicate, în total', $body, 'no all-history total is inferred from the slice');
            assert_not_contains('Pe luna publicării (toată baza de date)', $body, 'no history chart');
            assert_contains('Export curent', $body, 'the current export is labelled');
        });
    }
    if ($file === $nollm) {
        run_suite("the LLM spend section says unavailable against $label", function () use ($srv) {
            $body = (string)@file_get_contents($srv['base'] . '/statistici/');
            assert_contains('Date indisponibile', $body, 'no table: the section degrades, the page stays up');
        });
    }
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
@unlink($nov5);
@unlink($unsplit);
finish();
