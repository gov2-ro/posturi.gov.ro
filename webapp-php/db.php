<?php
/**
 * PDO singleton for posturi.sqlite.
 * The export script generates posturi.sqlite directly in this directory.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // POSTURI_DB lets a test or a preview point at another export without
        // touching the deployed file. Unset in production.
        $path = getenv('POSTURI_DB') ?: __DIR__ . '/posturi.sqlite';
        if (!file_exists($path)) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            die("Database not found. Run: python export-to-sqlite.py");
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // No journal_mode=WAL. This copy is a read-only replica: WAL would make PHP
        // create -shm/-wal beside it (the document root may not be writable), and a
        // -wal surviving an rsync swap describes a database that is gone, which
        // SQLite reports as "database disk image is malformed". query_only makes the
        // read-only intent something SQLite enforces rather than something we assume.
        $pdo->exec("PRAGMA cache_size=-8000; PRAGMA temp_store=MEMORY;");
        shim_legacy_export($pdo);
        $pdo->exec("PRAGMA query_only=1;");
    }
    return $pdo;
}

/**
 * REV-01: let new code read an export that predates the columns it filters on.
 *
 * Code and data deploy separately (code from the Mac, data from the VPS), so the
 * code can land on the host before an export that carries its columns — FIX-05's
 * application_status, read by every count and filter, was a fatal on the live
 * export. When columns are missing, a TEMP view named job_postings shadows the
 * table (unqualified names resolve temp before main) and computes them exactly as
 * export-to-sqlite.py does. Only the in-memory temp schema is written, so this
 * works on a read-only file, before query_only; indexes on the base table still
 * apply. Current exports skip it after one PRAGMA.
 *
 * PHP-COMPAT-01: each column is judged on its own, so an export that has
 * application_status but predates the v4_* columns is covered too. Besides the
 * deadline/status trio, the columns shimmed are the ones PHP queries — the list
 * filters, facets and the salary/occupation expressions. Anything PHP only reads
 * off a fetched row with `??` (sal_json, the build_meta provenance) needs no shim.
 */
function shim_legacy_export(PDO $pdo): void {
    $cols = array_flip($pdo->query("SELECT name FROM pragma_table_info('job_postings')")
        ->fetchAll(PDO::FETCH_COLUMN));
    if (!$cols) {
        return;
    }
    $extra = [];
    // Exports older than FIX-02 lack the resolved deadline too: fall back to the
    // expiry, which is what _apply_deadline() resolves to without a source date.
    $deadline = isset($cols['apply_deadline']) ? 'apply_deadline' : 'substr(expires_at, 1, 10)';
    $source   = isset($cols['deadline_source']) ? 'deadline_source'
              : "CASE WHEN expires_at IS NULL OR expires_at = '' THEN '' ELSE 'expirare' END";
    if (!isset($cols['apply_deadline']))  $extra[] = "$deadline AS apply_deadline";
    if (!isset($cols['deadline_source'])) $extra[] = "$source AS deadline_source";
    if (!isset($cols['application_status'])) {
        // Mirrors _application_status(); the export stamps it with its own day, the
        // shim with today's — the same Bucharest boundary every other filter uses.
        $today = function_exists('ro_today')   // Y-m-d, validated by ro_today()
            ? ro_today()
            : (new DateTimeImmutable('today', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
        $extra[] = "CASE WHEN $deadline IS NULL OR $deadline = '' THEN 'unknown'
                         WHEN $deadline < '$today' THEN 'closed'
                         WHEN $source IN ('concurs', 'anunt') THEN 'confirmed_open'
                         WHEN $source = 'expirare' THEN 'unconfirmed'
                         ELSE 'unknown' END AS application_status";
    }
    // Columns added after the first exports, with the value the export writes
    // before the extraction that fills them has run: '' for the NOT NULL DEFAULT ''
    // ones, NULL for the nullable ones. Facets and filters then see "no value".
    $blank = [
        'v3_contract_duration' => "''",   // v3 contract terms
        'v3_schedule'          => "''",
        'v3_shift_work'        => 'NULL',
        'occ_canonical'        => "''",   // normalize-titles.py
        'sal_min'              => 'NULL', // estimate-salaries.py
        'v4_funding_source'    => "''",   // prompt v4 scalars
        'v4_funding_programme' => "''",
        'v4_employer_sector'   => "''",
        'v4_parent_institution' => "''",
        'v4_application_deadline' => 'NULL',
    ];
    foreach ($blank as $name => $value) {
        if (!isset($cols[$name])) $extra[] = "$value AS $name";
    }
    if (!$extra) {
        return;
    }
    $pdo->exec("CREATE TEMP VIEW job_postings AS SELECT *, " . implode(', ', $extra)
        . " FROM main.job_postings");
}
