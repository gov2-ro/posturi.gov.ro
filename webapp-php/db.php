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
        // Extraction provenance (DATA-04). Only the export carries it; PHP reads it
        // off a fetched row with `??` today, but later per-role history will not.
        'schema_source_revision' => "''",
        'schema_prompt_version'  => "''",
        // BETA-V5 facts (export-to-sqlite.py::_v5_columns): empty until v5 rows exist.
        'v5_recruitment_kind'    => "''",
        'v5_internal_only'       => 'NULL',
        'v5_vacancies_total'     => 'NULL',
        'v5_fte'                 => 'NULL',
        'v5_post_status'         => "''",
        'v5_end_date'            => 'NULL',
        'v5_channels'            => "'[]'",
        'v5_work_localities'     => "'[]'",
    ];
    foreach ($blank as $name => $value) {
        if (!isset($cols[$name])) $extra[] = "$value AS $name";
    }
    // PERF-SPLIT: a current export says "structured" with has_schema and keeps the
    // long texts in posting_text; an older one has them as columns here.
    if (!isset($cols['has_schema'])) {
        $extra[] = isset($cols['schema_json'])
            ? "(schema_json IS NOT NULL) AS has_schema" : "0 AS has_schema";
    }
    if ($extra) {
        $pdo->exec("CREATE TEMP VIEW job_postings AS SELECT *, " . implode(', ', $extra)
            . " FROM main.job_postings");
    }
    shim_posting_roles($pdo, $cols);
    shim_requirement_evidence($pdo);
    shim_posting_text($pdo, $cols);
    shim_publication_monthly($pdo);
}

/**
 * FIX-09: an export that predates `publication_monthly` has no historical aggregates.
 * An EMPTY temp view of the same name (same columns, no rows) answers the stats query, and
 * publication_history() reads "no rows" as "indisponibil". History is never rebuilt from
 * job_postings: on a production export that table holds only the surviving, unexpired
 * announcements, so counting it by month would present a slice as the past.
 */
function shim_publication_monthly(PDO $pdo): void {
    $has = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'publication_monthly'")
        ->fetchColumn();
    if ($has) {
        return;
    }
    $pdo->exec("CREATE TEMP VIEW publication_monthly AS SELECT
            0 AS agg_version, '' AS month, 0 AS judet_id, 0 AS postings, 0 AS cancelled
        WHERE 0");
}

/**
 * PERF-SPLIT: an export that predates `posting_text` still has the long texts as
 * job_postings columns, so a TEMP view of that name reads them from there and the
 * detail page's join works on either layout. A column the export is too old to
 * have reads as its empty value.
 *
 * @param array<string,int> $cols  job_postings column names, as keys
 */
function shim_posting_text(PDO $pdo, array $cols): void {
    $has = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'posting_text'")
        ->fetchColumn();
    if ($has) {
        return;
    }
    $c = static fn(string $name, string $empty): string => isset($cols[$name]) ? $name : $empty;
    $pdo->exec("CREATE TEMP VIEW posting_text AS SELECT id AS posting_id,
            {$c('body_markdown', "''")} AS body_markdown,
            {$c('schema_json', 'NULL')} AS schema_json,
            {$c('sal_json', 'NULL')} AS sal_json,
            {$c('attachment_meta', "'[]'")} AS attachment_meta,
            {$c('other_links', "'[]'")} AS other_links,
            {$c('detalii_raw', "''")} AS detalii_raw,
            {$c('v3_bibliography', "'[]'")} AS v3_bibliography
        FROM main.job_postings");
}

/**
 * DATA-04: an export that predates `posting_roles` behaves exactly as it did.
 *
 * The role-scoped filters (EQF, study level, experience, field, skill,
 * credential) match through `posting_roles`. Without the table, a TEMP view with
 * that name gives every posting its one implicit role — ordinal 0, undecomposed,
 * the posting's own flat columns — which is precisely what a current export writes
 * for a posting with no positions. A role-scoped search then reduces to the old
 * posting-level predicate, row for row. It reads `job_postings` unqualified on
 * purpose: when shim_legacy_export() made a temp view of that name, this one must
 * see its blanks too, and temp resolves before main.
 *
 * Columns the export is too old to have read as NULL, so a very old file degrades
 * to "no value" instead of a "no such column" fatal.
 *
 * @param array<string,int> $cols  job_postings column names, as keys
 */
function shim_posting_roles(PDO $pdo, array $cols): void {
    $has = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'posting_roles'")
        ->fetchColumn();
    if ($has) {
        return;
    }
    $c = static fn(string $name): string => isset($cols[$name]) ? $name : 'NULL';
    $pdo->exec("CREATE TEMP VIEW posting_roles AS SELECT
            id AS posting_id, 0 AS ordinal, 'legacy:-:0' AS role_key, 0 AS decomposed,
            NULL AS title, NULL AS vacancy_count,
            {$c('inf_studies_required')} AS study_level,
            {$c('v3_eqf_level')} AS eqf_level,
            {$c('v3_isced_fields')} AS isced_fields,
            {$c('inf_experience_years')} AS exp_years,
            NULL AS exp_in_specialty, NULL AS exp_none_required,
            {$c('v3_skills')} AS skills,
            {$c('v3_credentials')} AS credentials,
            NULL AS seniority_hint, NULL AS source_revision, 0 AS role_format
        FROM job_postings");
}

/**
 * UX-13: an export that predates `requirement_evidence` shows no evidence.
 *
 * The detail page reads the table unconditionally. Without it, an EMPTY temp view of
 * the same name (same columns, no rows) answers the query, so the page renders exactly
 * as it did: no disclosures, no error. Nothing is invented for old exports - an absent
 * table is "we have not checked", which the page shows as nothing at all, never as
 * "no quote" (that is a per-fact statement only a current export can make).
 */
function shim_requirement_evidence(PDO $pdo): void {
    $has = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'requirement_evidence'")
        ->fetchColumn();
    if ($has) {
        return;
    }
    $pdo->exec("CREATE TEMP VIEW requirement_evidence AS SELECT
            0 AS posting_id, '' AS role_key, 0 AS ordinal, '' AS fact_kind, 0 AS fact_index,
            '' AS fact_label, '' AS basis, NULL AS quote, '' AS status, NULL AS reason,
            NULL AS part, NULL AS section, NULL AS occurrences, NULL AS source_revision,
            NULL AS extraction_revision, '' AS text_hash, '' AS normalization
        WHERE 0");
}
