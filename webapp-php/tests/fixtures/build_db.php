<?php
/**
 * Deterministic SQLite fixture for the PHP request/rendered tests.
 *
 * Schema mirrors export-to-sqlite.py (the columns pages really query), seeded
 * with postings that cover the deadline matrix FIX-02 needs plus the bodies
 * FIX-01/07 exercise: safe and unsafe Markdown, structured sections, diacritics,
 * multi-role entries, several employers/counties. No real personal data.
 *
 * Usage: build_fixture_db('/tmp/fixture.sqlite')  — POSTURI_DB points the app
 * at the result; POSTURI_TODAY fixes the clock (default 2026-10-03).
 *
 * `$role_cases = true` (DATA-04) adds four multi-role postings, ids 1009-1012,
 * that exercise role-scoped search: two roles with contrasting requirements,
 * duplicate role titles, a single positions entry, and fractional/"none"
 * experience. They are off by default because most suites (and the pinned ID sets
 * in old_urls.php / discovery_test.php, the 38-row counts) were written against
 * the original 38-posting fixture; roles_test.php and compat_test.php switch them on.
 *
 * `$evidence_cases = true` (UX-13, needs `$role_cases`) adds posting 1013 (no roles, flat
 * facts, a `javascript:` announcement_url, hostile quote/section text) and fills
 * `requirement_evidence` for 1009 (two roles with distinct, recognisable quotes) and 1013
 * in all three states. The table itself always exists, empty, like the export's.
 *
 * `posting_roles` is populated for every posting by fixture_role_rows(), a PHP
 * port of posting_roles.py's role_rows(): the same rules, so the table has the
 * shape the export writes.
 */

/**
 * $split = false builds the layout of exports before PERF-SPLIT (long texts as
 * job_postings columns, FTS with stored content), for the compatibility suite.
 */
function build_fixture_db(string $path, bool $role_cases = false, bool $evidence_cases = false, bool $split = true): void {
    if ($evidence_cases && !$role_cases) {
        throw new InvalidArgumentException('evidence cases are built on the role cases');
    }
    if (file_exists($path)) unlink($path);
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec(<<<'SQL'
CREATE TABLE judete (
    id      INTEGER PRIMARY KEY,
    name    TEXT NOT NULL,
    slug    TEXT NOT NULL UNIQUE
);
CREATE TABLE employers (
    id      INTEGER PRIMARY KEY,
    name    TEXT NOT NULL,
    slug    TEXT NOT NULL UNIQUE
);
CREATE TABLE job_postings (
    id                      INTEGER PRIMARY KEY,
    url                     TEXT NOT NULL UNIQUE,
    title                   TEXT NOT NULL DEFAULT '',
    employer_id             INTEGER REFERENCES employers(id),
    employer_name           TEXT NOT NULL DEFAULT '',
    judet_id                INTEGER REFERENCES judete(id),
    judet_name              TEXT NOT NULL DEFAULT '',
    judet_slug              TEXT NOT NULL DEFAULT '',
    locality                TEXT NOT NULL DEFAULT '',
    detalii_raw             TEXT NOT NULL DEFAULT '',
    published_at            TEXT,
    expires_at              TEXT,
    tip                     TEXT NOT NULL DEFAULT '',
    job_level               TEXT NOT NULL DEFAULT '',
    job_type                TEXT NOT NULL DEFAULT '',
    employer_category       TEXT NOT NULL DEFAULT '',
    categorie               TEXT NOT NULL DEFAULT '',
    announcement_url        TEXT NOT NULL DEFAULT '',
    body_markdown           TEXT NOT NULL DEFAULT '',
    nr_posturi              INTEGER,
    contact_phone           TEXT NOT NULL DEFAULT '',
    contact_email           TEXT NOT NULL DEFAULT '',
    contact_person          TEXT NOT NULL DEFAULT '',
    data_limita_depunere    TEXT,
    data_proba_scrisa       TEXT,
    data_interviu           TEXT,
    data_rezultate_finale   TEXT,
    created_at              TEXT,
    updated_at              TEXT,
    last_seen_at            TEXT,
    detail_fetched_at       TEXT,
    detail_content_hash     TEXT NOT NULL DEFAULT '',
    other_links             TEXT NOT NULL DEFAULT '[]',
    attachment_meta         TEXT NOT NULL DEFAULT '[]',
    inferred                TEXT NOT NULL DEFAULT '{}',
    schema_json             TEXT,
    schema_source_revision  TEXT NOT NULL DEFAULT '',
    schema_prompt_version   TEXT NOT NULL DEFAULT '',
    inf_profession_family   TEXT,
    inf_seniority           TEXT,
    inf_anomaly_flags       TEXT NOT NULL DEFAULT '[]',
    inf_work_type           TEXT,
    inf_remote_eligible     INTEGER NOT NULL DEFAULT 0,
    inf_requires_computer   INTEGER,
    inf_experience_years    REAL,
    inf_studies_required    TEXT,
    v3_eqf_level            INTEGER,
    v3_study_level          TEXT NOT NULL DEFAULT '',
    v3_isced_fields         TEXT NOT NULL DEFAULT '[]',
    v3_study_labels         TEXT NOT NULL DEFAULT '[]',
    v3_skills               TEXT NOT NULL DEFAULT '[]',
    v3_languages            TEXT NOT NULL DEFAULT '[]',
    v3_credentials          TEXT NOT NULL DEFAULT '[]',
    v3_policy_domains       TEXT NOT NULL DEFAULT '[]',
    v3_exam_stages          TEXT NOT NULL DEFAULT '[]',
    v3_positions            INTEGER,
    v3_contract_duration    TEXT NOT NULL DEFAULT '',
    v3_schedule             TEXT NOT NULL DEFAULT '',
    v3_hours_per_week       REAL,
    v3_shift_work           INTEGER,
    v3_remote_mode          TEXT NOT NULL DEFAULT '',
    v3_seniority_hint       TEXT NOT NULL DEFAULT '',
    v3_bibliography         TEXT NOT NULL DEFAULT '[]',
    inf_salary_min          REAL,
    inf_salary_max          REAL,
    occ_canonical           TEXT NOT NULL DEFAULT '',
    occ_cor_code            TEXT NOT NULL DEFAULT '',
    occ_isco_group          TEXT NOT NULL DEFAULT '',
    occ_confidence          TEXT NOT NULL DEFAULT '',
    sal_min                 REAL,
    sal_max                 REAL,
    sal_confidence          TEXT NOT NULL DEFAULT '',
    sal_variant             TEXT NOT NULL DEFAULT '',
    sal_json                TEXT,
    v4_funding_source       TEXT NOT NULL DEFAULT '',
    v4_funding_programme    TEXT NOT NULL DEFAULT '',
    v4_employer_sector      TEXT NOT NULL DEFAULT '',
    v4_parent_institution   TEXT NOT NULL DEFAULT '',
    v4_application_deadline TEXT,
    v5_recruitment_kind     TEXT NOT NULL DEFAULT '',
    v5_internal_only        INTEGER,
    v5_vacancies_total      INTEGER,
    v5_fte                  REAL,
    v5_post_status          TEXT NOT NULL DEFAULT '',
    v5_end_date             TEXT,
    v5_channels             TEXT NOT NULL DEFAULT '[]',
    v5_work_localities      TEXT NOT NULL DEFAULT '[]',
    apply_deadline          TEXT,
    deadline_source         TEXT NOT NULL DEFAULT '',
    application_status      TEXT NOT NULL DEFAULT ''
);
CREATE TABLE posting_roles (
    posting_id        INTEGER NOT NULL REFERENCES job_postings(id) ON DELETE CASCADE,
    ordinal           INTEGER NOT NULL,
    role_key          TEXT NOT NULL,
    decomposed        INTEGER NOT NULL,
    title             TEXT,
    vacancy_count     INTEGER,
    study_level       TEXT,
    eqf_level         INTEGER,
    isced_fields      TEXT,
    exp_years         REAL,
    exp_in_specialty  INTEGER,
    exp_none_required INTEGER,
    skills            TEXT,
    credentials       TEXT,
    seniority_hint    TEXT,
    source_revision   TEXT,
    role_format       INTEGER NOT NULL,
    PRIMARY KEY (posting_id, ordinal)
);
CREATE INDEX idx_pr_eqf     ON posting_roles(eqf_level);
CREATE INDEX idx_pr_study   ON posting_roles(study_level);
CREATE TABLE requirement_evidence (
    posting_id          INTEGER NOT NULL REFERENCES job_postings(id) ON DELETE CASCADE,
    role_key            TEXT NOT NULL,
    ordinal             INTEGER NOT NULL,
    fact_kind           TEXT NOT NULL,
    fact_index          INTEGER NOT NULL,
    fact_label          TEXT NOT NULL,
    basis               TEXT NOT NULL,
    quote               TEXT,
    status              TEXT NOT NULL,
    reason              TEXT,
    part                TEXT,
    section             TEXT,
    occurrences         INTEGER,
    source_revision     TEXT,
    extraction_revision TEXT,
    text_hash           TEXT NOT NULL,
    normalization       TEXT NOT NULL,
    PRIMARY KEY (posting_id, ordinal, fact_kind, fact_index)
);
CREATE TABLE calendar_events (
    id          INTEGER PRIMARY KEY,
    posting_id  INTEGER NOT NULL REFERENCES job_postings(id) ON DELETE CASCADE,
    eveniment   TEXT NOT NULL,
    data        TEXT NOT NULL,
    ora         TEXT
);
CREATE TABLE llm_costs (
    day             TEXT NOT NULL,
    provider        TEXT NOT NULL,
    model           TEXT NOT NULL,
    prompt_version  TEXT NOT NULL,
    calls           INTEGER NOT NULL,
    input_tokens    INTEGER NOT NULL,
    output_tokens   INTEGER NOT NULL,
    cost_usd        REAL NOT NULL,
    step            TEXT NOT NULL DEFAULT 'schema',
    source          TEXT NOT NULL DEFAULT 'ledger' CHECK (source IN ('ledger', 'legacy')),
    unpriced_calls  INTEGER NOT NULL DEFAULT 0,
    postings        INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (day, provider, model, prompt_version, step, source)
);
-- FIX-09: historical publication intake, over ALL of Postgres (the export's table).
CREATE TABLE publication_monthly (
    agg_version INTEGER NOT NULL,
    month       TEXT    NOT NULL,
    judet_id    INTEGER NOT NULL DEFAULT 0,
    postings    INTEGER NOT NULL,
    cancelled   INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (month, judet_id)
) WITHOUT ROWID;
CREATE TABLE archived_postings (
    id INTEGER PRIMARY KEY, url TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', employer_id INTEGER,
    employer_name TEXT NOT NULL DEFAULT '', judet_name TEXT NOT NULL DEFAULT '', judet_slug TEXT NOT NULL DEFAULT '',
    locality TEXT NOT NULL DEFAULT '', published_at TEXT, expires_at TEXT, apply_deadline TEXT,
    cancelled INTEGER NOT NULL DEFAULT 0, nr_posturi INTEGER, occ_canonical TEXT NOT NULL DEFAULT '',
    inf_profession_family TEXT NOT NULL DEFAULT '', sal_min REAL, sal_max REAL, summary TEXT NOT NULL DEFAULT ''
);
INSERT INTO archived_postings VALUES
    (9001, 'https://posturi.gov.ro/anunt/fixture-9001', 'Asistent medical principal', 1, 'Spitalul Clinic Județean Cluj',
     'Cluj', 'cluj', 'Cluj-Napoca', '2026-08-01 09:00:00', '2026-09-15 23:59:59', '2026-08-20', 0, 2,
     'Asistent medical', 'sănătate', 4800, 4800, 'Concurs pentru **asistent medical principal** la secția ATI.'),
    (9002, 'https://posturi.gov.ro/anunt/fixture-9002', 'Referent anulat', 1, 'Primăria Comunei Găiceana',
     'Bacău', 'bacau', 'Găiceana', '2026-09-20 09:00:00', '2026-10-30 23:59:59', '2026-10-10', 1, 1,
     'Referent', 'administrație', NULL, NULL, '<script>alert(1)</script>Anunț anulat de instituție.');
CREATE TABLE build_meta (
    id              INTEGER PRIMARY KEY CHECK (id = 1),
    built_at        TEXT NOT NULL,
    git_sha         TEXT NOT NULL DEFAULT '',
    source_host     TEXT NOT NULL DEFAULT '',
    active_only     INTEGER NOT NULL DEFAULT 0,
    job_postings    INTEGER NOT NULL DEFAULT 0,
    employers       INTEGER NOT NULL DEFAULT 0,
    calendar_events INTEGER NOT NULL DEFAULT 0,
    run_id                  TEXT NOT NULL DEFAULT '',
    index_checked_at        TEXT,
    index_scan_pages        INTEGER,
    index_scan_complete     INTEGER NOT NULL DEFAULT 0,
    detail_fetched_at_max   TEXT,
    detail_fetched_rows     INTEGER
);
CREATE VIRTUAL TABLE job_postings_fts USING fts5(
    title, employer_name, judet_name, body_text,
    tokenize='unicode61 remove_diacritics 2'
);
SQL);

    // One column list drives both the INSERT statement and the row mapping.
    $columns = ['id','url','title','employer_id','employer_name','judet_id','judet_name','judet_slug',
        'locality','published_at','expires_at','job_level','job_type','employer_category','categorie',
        'announcement_url','body_markdown','nr_posturi','contact_phone','contact_email','contact_person',
        'data_limita_depunere','data_proba_scrisa','data_interviu','data_rezultate_finale',
        'created_at','updated_at','last_seen_at','detail_fetched_at','detail_content_hash',
        'other_links','attachment_meta','inferred','schema_json',
        'schema_source_revision','schema_prompt_version',
        'inf_profession_family','inf_seniority','inf_anomaly_flags','inf_work_type','inf_remote_eligible',
        'inf_requires_computer','inf_experience_years','inf_studies_required',
        'v3_eqf_level','v3_study_level','v3_isced_fields','v3_study_labels','v3_skills','v3_languages',
        'v3_credentials','v3_policy_domains','v3_exam_stages','v3_positions',
        'v3_contract_duration','v3_schedule','v3_hours_per_week','v3_shift_work','v3_remote_mode',
        'v3_seniority_hint','v3_bibliography','inf_salary_min','inf_salary_max',
        'occ_canonical','occ_cor_code','occ_isco_group','occ_confidence',
        'sal_min','sal_max','sal_confidence','sal_variant','sal_json',
        'v4_funding_source','v4_funding_programme','v4_employer_sector','v4_parent_institution',
        'v4_application_deadline','apply_deadline','deadline_source','application_status'];
    $ph = implode(', ', array_fill(0, count($columns), '?'));
    $ins_j = $db->prepare('INSERT INTO job_postings (' . implode(', ', $columns) . ') VALUES (' . $ph . ')');

    $db->exec("INSERT INTO judete VALUES (1, 'Cluj', 'cluj'), (2, 'Bacău', 'bacau'), (3, 'Ilfov', 'ilfov')");
    $db->exec("INSERT INTO employers VALUES
        (1, 'Spitalul Clinic Județean Cluj', 'spitalul-clinic-judetean-cluj'),
        (2, 'Primăria Comunei Găiceana', 'primaria-comunei-gaiceana'),
        (3, 'Administrația de Salubrizare Tunari', 'administratia-de-salubrizare-tunari')");

    $insert = function (array $fields) use ($ins_j, $columns) {
        $vals = [];
        foreach ($columns as $key) $vals[] = $fields[$key] ?? null;
        $ins_j->execute($vals);
        return $fields['id'];
    };

    $base = [
        'judet_id' => 1, 'judet_name' => 'Cluj', 'judet_slug' => 'cluj', 'locality' => 'Cluj-Napoca',
        'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00',
        'last_seen_at' => '2026-10-03 09:00:00', 'detail_fetched_at' => '2026-10-03 09:00:00',
        'detail_content_hash' => '',
        // NOT NULL DEFAULT '' columns the INSERT lists explicitly.
        'tip' => '', 'employer_category' => '', 'announcement_url' => '', 'detalii_raw' => '',
        'contact_phone' => '', 'contact_email' => '', 'contact_person' => '',
        'occ_cor_code' => '', 'occ_isco_group' => '', 'occ_confidence' => '',
        'v3_study_level' => '', 'v3_contract_duration' => '', 'v3_schedule' => '',
        'v3_remote_mode' => '', 'v3_seniority_hint' => '',
        'other_links' => '[]', 'attachment_meta' => '[]',
        'inferred' => '{}', 'inf_anomaly_flags' => '[]', 'inf_remote_eligible' => 0,
        'v3_isced_fields' => '[]', 'v3_study_labels' => '[]', 'v3_skills' => '[]', 'v3_languages' => '[]',
        'v3_credentials' => '[]', 'v3_policy_domains' => '[]', 'v3_exam_stages' => '[]',
        'v3_bibliography' => '[]', 'sal_confidence' => '', 'sal_variant' => '',
        'v4_funding_source' => '', 'v4_funding_programme' => '', 'v4_employer_sector' => '',
        'v4_parent_institution' => '', 'v4_application_deadline' => null, 'deadline_source' => '',
        'application_status' => 'confirmed_open',
        'schema_source_revision' => '', 'schema_prompt_version' => '',
    ];

    // --- Deadline matrix (today is fixed at 2026-10-03 via POSTURI_TODAY) ---

    // 1001: scraped deadline 2026-10-16, competition ends 2026-11-10. The original
    // bug showed "13 zile" beside 10.11.2026; both must read 16.10.2026.
    $insert([
        'id' => 1001, 'url' => 'https://posturi.gov.ro/anunt/fixture-1001',
        'title' => 'ASISTENT MEDICAL GENERALIST', 'employer_id' => 1, 'employer_name' => 'Spitalul Clinic Județean Cluj',
        'published_at' => '2026-10-01 09:00:00', 'expires_at' => '2026-11-10 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Permanent', 'employer_category' => 'instituție publică',
        'categorie' => 'Funcționari publici',
        'announcement_url' => 'https://posturi.gov.ro/anunt/fixture-1001-doc.docx',
        'body_markdown' => "Concurs pentru ocuparea postului de **asistent medical generalist**.\n\n- dosar complet;\n- probă scrisă la sediul spitalului.",
        'nr_posturi' => 1, 'contact_phone' => '0264 111 222', 'contact_email' => 'resurse@spital-cluj.ro',
        'contact_person' => 'Ion Popescu',
        'data_limita_depunere' => '2026-10-16 14:30:00', 'data_proba_scrisa' => '2026-10-28',
        'data_interviu' => '2026-11-03', 'data_rezultate_finale' => '2026-11-10',
        'inf_profession_family' => 'sanatate', 'inf_seniority' => 'asistent',
        'inf_work_type' => 'norma_intreaga', 'inf_experience_years' => 0, 'inf_studies_required' => 'liceala',
        'occ_canonical' => 'Asistent medical', 'occ_cor_code' => '325901', 'occ_isco_group' => '32',
        'occ_confidence' => 'exact', 'sal_min' => 4100, 'sal_max' => 5200,
        'apply_deadline' => '2026-10-16', 'deadline_source' => 'anunt',
            'application_status' => 'confirmed_open',
    ] + $base);

    // 1002: deadline today — "Azi!".
    $insert([
        'id' => 1002, 'url' => 'https://posturi.gov.ro/anunt/fixture-1002',
        'title' => 'Referent debutant', 'employer_id' => 2, 'employer_name' => 'Primăria Comunei Găiceana',
        'judet_id' => 2, 'judet_name' => 'Bacău', 'judet_slug' => 'bacau', 'locality' => 'Găiceana',
        'published_at' => '2026-09-30 09:00:00', 'expires_at' => '2026-10-10 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Temporar', 'categorie' => 'Funcționari publici',
        'body_markdown' => 'Se organizează concurs pentru postul de referent.',
        'occ_canonical' => 'Referent', 'sal_min' => 3300, 'sal_max' => 3300,
        'apply_deadline' => '2026-10-03', 'deadline_source' => 'concurs',
            'application_status' => 'confirmed_open',
    ] + $base);

    // 1003: deadline past — closed.
    $insert([
        'id' => 1003, 'url' => 'https://posturi.gov.ro/anunt/fixture-1003',
        'title' => 'Inspector grad II', 'employer_id' => 3, 'employer_name' => 'Administrația de Salubrizare Tunari',
        'judet_id' => 3, 'judet_name' => 'Ilfov', 'judet_slug' => 'ilfov', 'locality' => 'Tunari',
        'published_at' => '2026-09-25 09:00:00', 'expires_at' => '2026-10-05 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Permanent', 'categorie' => 'Personal contractual',
        'body_markdown' => 'Concurs pentru postul de inspector.',
        'occ_canonical' => 'Inspector', 'sal_min' => 4500, 'sal_max' => 5000,
        'apply_deadline' => '2026-09-30', 'deadline_source' => 'anunt',
            'application_status' => 'closed',
    ] + $base);

    // 1004: expiry fallback — the date must be visibly an estimate.
    $insert([
        'id' => 1004, 'url' => 'https://posturi.gov.ro/anunt/fixture-1004',
        'title' => 'Îngrijitor școală', 'employer_id' => 2, 'employer_name' => 'Primăria Comunei Găiceana',
        'judet_id' => 2, 'judet_name' => 'Bacău', 'judet_slug' => 'bacau', 'locality' => 'Găiceana',
        'published_at' => '2026-09-28 09:00:00', 'expires_at' => '2026-10-20 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Permanent', 'categorie' => 'Personal contractual',
        'body_markdown' => 'Se organizează concurs pentru postul de îngrijitor.',
        'occ_canonical' => 'Îngrijitor', 'sal_min' => 2800, 'sal_max' => 2800,
        'apply_deadline' => '2026-10-20', 'deadline_source' => 'expirare',
            'application_status' => 'unconfirmed',
    ] + $base);

    // 1005: no dates anywhere — explicit unknown state, no countdown.
    $insert([
        'id' => 1005, 'url' => 'https://posturi.gov.ro/anunt/fixture-1005',
        'title' => 'Muncitor necalificat', 'employer_id' => 3, 'employer_name' => 'Administrația de Salubrizare Tunari',
        'judet_id' => 3, 'judet_name' => 'Ilfov', 'judet_slug' => 'ilfov', 'locality' => 'Tunari',
        'published_at' => '2026-09-20 09:00:00', 'expires_at' => null,
        'job_level' => 'executie', 'job_type' => 'Temporar', 'categorie' => 'Personal contractual',
        'body_markdown' => 'Concurs pentru muncitor necalificat.',
        'occ_canonical' => 'Muncitor necalificat', 'sal_min' => 2500, 'sal_max' => 2500,
        'apply_deadline' => null, 'deadline_source' => '',
            'application_status' => 'unknown',
    ] + $base);

    // 1006: confirmed deadline equals expiry — the "se încheie" line must not duplicate.
    $insert([
        'id' => 1006, 'url' => 'https://posturi.gov.ro/anunt/fixture-1006',
        'title' => 'Consilier juridic', 'employer_id' => 1, 'employer_name' => 'Spitalul Clinic Județean Cluj',
        'published_at' => '2026-09-29 09:00:00', 'expires_at' => '2026-10-16 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Permanent', 'categorie' => 'Funcționari publici',
        'body_markdown' => 'Concurs pentru consilier juridic.',
        'occ_canonical' => 'Consilier juridic', 'sal_min' => 5600, 'sal_max' => 7000,
        'apply_deadline' => '2026-10-16', 'deadline_source' => 'concurs',
            'application_status' => 'confirmed_open',
    ] + $base);

    // 1007: hostile Markdown body — the FIX-01 browser fixture renders this.
    $insert([
        'id' => 1007, 'url' => 'https://posturi.gov.ro/anunt/fixture-1007',
        'title' => 'Post cu text nesigur', 'employer_id' => 1, 'employer_name' => 'Spitalul Clinic Județean Cluj',
        'published_at' => '2026-09-27 09:00:00', 'expires_at' => '2026-11-01 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Permanent', 'categorie' => 'Personal contractual',
        'body_markdown' => "Text normal cu **bold** și diacritice: ș, ț, ă, â, î.\n\n"
            . '<a href="javascript:alert(1)" onclick="alert(2)">link suspect</a>'
            . "\n\n[alt link](JaVaScRiPt:alert(3))\n\n"
            . '<img src="x" onerror="alert(4)">'
            . "\n\nUn [link legitim](https://posturi.gov.ro/documente/anunt-1007.pdf).",
        'occ_canonical' => 'Analist', 'sal_min' => 3000, 'sal_max' => 3500,
        'apply_deadline' => '2026-10-25', 'deadline_source' => 'anunt',
            'application_status' => 'confirmed_open',
    ] + $base);

    // 1008: multi-role v3 row with structured sections and a calendar.
    $insert([
        'id' => 1008, 'url' => 'https://posturi.gov.ro/anunt/fixture-1008',
        'title' => 'Mai multe posturi scoase la concurs', 'employer_id' => 3,
        'employer_name' => 'Administrația de Salubrizare Tunari',
        'judet_id' => 3, 'judet_name' => 'Ilfov', 'judet_slug' => 'ilfov', 'locality' => 'Tunari',
        'published_at' => '2026-09-26 09:00:00', 'expires_at' => '2026-10-30 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Permanent', 'categorie' => 'Funcționari publici',
        'body_markdown' => 'Anunț cu mai multe roluri.',
        'nr_posturi' => 5,
        'schema_json' => json_encode([
            'education' => ['minimum_level' => 'liceala', 'eqf_level' => 4,
                'fields_of_study' => [['isced_field' => '04_afaceri_administratie_drept', 'label_ro' => 'administrație']]],
            'positions' => [
                // Role 1 states its own skills (a real extraction does, and the browser feed test needs an
                // Ilfov posting with a skill facet value); role 2 states nothing, so it has none of the posting's.
                ['count' => 1, 'title' => 'Referent', 'education' => ['minimum_level' => 'liceala', 'eqf_level' => 4],
                    'skill_list' => [['label' => 'Excel', 'required' => true], ['label' => 'Word', 'required' => false]]],
                ['count' => 4, 'title' => 'Muncitor necalificat', 'education' => null],
            ],
            'skill_list' => [['label' => 'Excel', 'required' => true], ['label' => 'Word', 'required' => false]],
            'language_list' => [['language' => 'engleză', 'cefr' => 'B1']],
            'credentials' => [['label' => 'Permis categoria B', 'kind' => 'permis_conducere']],
            'policy_domains' => ['administratie_publica'], 'exam_stages' => ['proba_scrisa', 'interviu'],
            'contract' => ['duration' => 'determinata', 'duration_months' => 6],
        ], JSON_UNESCAPED_UNICODE),
        'v3_eqf_level' => 4, 'v3_skills' => '["Excel","Word"]', 'v3_languages' => '["en:B1"]',
        'v3_credentials' => '["permis_conducere"]', 'v3_policy_domains' => '["administratie_publica"]',
        'v3_exam_stages' => '["proba_scrisa","interviu"]', 'v3_positions' => 2,
        'v3_contract_duration' => 'determinata', 'v3_schedule' => 'norma_intreaga',
        'schema_source_revision' => 'f1008a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f6071829',
        'schema_prompt_version' => 'v4',
        'occ_canonical' => 'Referent', 'sal_min' => 3300, 'sal_max' => 3300,
        'apply_deadline' => '2026-10-21', 'deadline_source' => 'concurs',
            'application_status' => 'confirmed_open',
    ] + $base);

    if ($role_cases) {
        fixture_role_case_postings($insert, $base);
    }
    if ($evidence_cases) {
        fixture_evidence_posting($insert, $base);
    }

    // A batch of 30 plain rows (published before everything above) so the list
    // spans two pages at PAGE_SIZE 25 — pagination and page-clamping tests.
    for ($i = 1; $i <= 30; $i++) {
        $id = 2000 + $i;
        $insert([
            'id' => $id, 'url' => "https://posturi.gov.ro/anunt/fixture-$id",
            'title' => "Post de volum $i", 'employer_id' => 3, 'employer_name' => 'Administrația de Salubrizare Tunari',
            'judet_id' => 3, 'judet_name' => 'Ilfov', 'judet_slug' => 'ilfov', 'locality' => 'Tunari',
            'published_at' => '2026-09-15 09:00:00', 'expires_at' => '2026-12-31 23:59:59',
            'job_level' => 'executie', 'job_type' => 'Temporar', 'categorie' => 'Personal contractual',
            'body_markdown' => "Concurs pentru postul de volum $i.",
            'occ_canonical' => 'Muncitor necalificat', 'sal_min' => 2500, 'sal_max' => 2500,
            'apply_deadline' => sprintf('2026-10-%02d', 5 + ($i % 6)), 'deadline_source' => 'anunt',
        ] + $base);
    }

    // UX-01A facet variety. Applied as UPDATEs so the row count, ids and
    // deadline matrix above (which other tests pin) are untouched; it gives every
    // facet group at least one value and a spread of exact/array/legacy values.
    // 'zz_nou' is deliberately an unmapped vocabulary value: it must stay visible.
    $db->exec("
        UPDATE job_postings SET inf_profession_family='sănătate', v3_eqf_level=4,
            v3_isced_fields='[\"09_sanatate_asistenta_sociala\"]', v3_skills='[\"Excel\"]',
            v3_languages='[\"en:B1\"]', v3_contract_duration='nedeterminata', v3_schedule='norma_intreaga',
            v4_funding_source='buget_local', v4_employer_sector='sanatate', inf_requires_computer=1,
            employer_category='Funcție contractuală'
          WHERE id=1001;
        UPDATE job_postings SET inf_profession_family='administrație', inf_seniority='debutant',
            inf_studies_required='licenta', inf_experience_years=2, v3_eqf_level=6,
            v3_isced_fields='[\"04_afaceri_administratie_drept\"]', v3_skills='[\"Excel\",\"Contabilitate\"]',
            v3_contract_duration='determinata', v3_schedule='norma_partiala', v4_funding_source='fonduri_europene',
            v4_employer_sector='administratie_locala', inf_remote_eligible=1, employer_category='Funcție publică'
          WHERE id=1002;
        UPDATE job_postings SET inf_profession_family='administrație', inf_seniority='inferior_necunoscut',
            inf_studies_required='liceala', v3_eqf_level=4, employer_category='Funcție contractuală'
          WHERE id=1003;
        UPDATE job_postings SET inf_profession_family='social', inf_work_type='schimburi', v3_shift_work=1,
            v3_eqf_level=2, v3_schedule='schimburi', v3_contract_duration='zz_nou', inf_requires_computer=0,
            employer_category='Funcție contractuală'
          WHERE id=1004;
        UPDATE job_postings SET inf_profession_family='tehnic', inf_anomaly_flags='[\"missing_contact\"]',
            inf_experience_years=6, inf_seniority='conducere_superioara', job_level='conducere',
            employer_category='Funcție contractuală'
          WHERE id=1005;
        UPDATE job_postings SET inf_profession_family='juridic', inf_seniority='consilier',
            inf_studies_required='master', inf_experience_years=4, v3_eqf_level=7, v3_skills='[\"Contabilitate\"]',
            v3_languages='[\"fr:B2\"]', v3_credentials='[\"autorizatie\"]', v3_policy_domains='[\"drept_justitie\"]',
            v3_exam_stages='[\"interviu\"]', inf_requires_computer=1, v4_funding_source='buget_stat',
            v4_employer_sector='justitie', employer_category='Funcție publică'
          WHERE id=1006;
        UPDATE job_postings SET inf_profession_family='tehnic' WHERE id IN (1007, 1008);
        UPDATE job_postings SET v3_isced_fields='[\"04_afaceri_administratie_drept\"]',
            v3_study_level='liceala', inf_studies_required='liceala' WHERE id=1008;
    ");

    $db->exec("INSERT INTO calendar_events VALUES
        (1, 1001, 'Depunere dosare', '2026-10-16', NULL),
        (2, 1001, 'Probă scrisă', '2026-10-28', '10:00'),
        (3, 1001, 'Interviu', '2026-11-03', '09:00'),
        (4, 1008, 'Depunere dosare', '2026-10-21', NULL)");

    // Populate the FTS mirror for the search tests.
    $rows = $db->query("SELECT id, title, employer_name, judet_name, body_markdown FROM job_postings")->fetchAll();
    $fts = $db->prepare("INSERT INTO job_postings_fts (rowid, title, employer_name, judet_name, body_text) VALUES (?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $fts->execute([$r['id'], $r['title'], $r['employer_name'], $r['judet_name'], strip_tags($r['body_markdown'])]);
    }

    // One role-rows pass over every posting, after the UPDATEs above have given the
    // flat columns their final values (an undecomposed posting's implicit role copies them).
    $ins_r = $db->prepare('INSERT INTO posting_roles (posting_id, ' . implode(', ', FIXTURE_ROLE_COLUMNS)
        . ') VALUES (?, ' . implode(', ', array_fill(0, count(FIXTURE_ROLE_COLUMNS), '?')) . ')');
    foreach ($db->query('SELECT * FROM job_postings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $posting) {
        foreach (fixture_role_rows($posting) as $role) {
            $ins_r->execute(array_merge([$posting['id']], array_map(fn($c) => $role[$c], FIXTURE_ROLE_COLUMNS)));
        }
    }
    if ($evidence_cases) {
        fixture_insert_evidence($db);
    }
    $n_postings = (int)$db->query('SELECT COUNT(*) FROM job_postings')->fetchColumn();

    $db->exec("INSERT INTO build_meta (id, built_at, git_sha, source_host, active_only, job_postings, employers,
        calendar_events, run_id, index_checked_at, index_scan_pages, index_scan_complete,
        detail_fetched_at_max, detail_fetched_rows)
        VALUES (1, '2026-10-03 09:00:00', 'fixture-data-sha', 'fixture-host', 1, $n_postings, 3, 4,
        'fixture-run-1', '2026-10-03 08:30:00', 12, 1, '2026-10-03T09:00:00+00:00', 8)");
    // FIX-10: 2 Oct is a legacy estimate (3 postings); 4 Oct is the call ledger: 5 schema
    // calls (4 postings, one call with no known cost) and 2 occupations calls.
    $llm = $db->prepare("INSERT INTO llm_costs (day, provider, model, prompt_version, calls, input_tokens,
        output_tokens, cost_usd, step, source, unpriced_calls, postings) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ([
        ['2026-10-02', 'openrouter', 'fixture-model', 'v4', 3, 12000, 900, 0.01, 'schema', 'legacy', 0, 3],
        ['2026-10-04', 'openrouter', 'fixture-model', 'v4', 5, 40000, 4000, 0.02, 'schema', 'ledger', 1, 4],
        ['2026-10-04', 'openrouter', 'fixture-model', 'occupation_v1', 2, 3000, 400, 0.002, 'occupations', 'ledger', 0, 0],
    ] as $row) {
        $llm->execute($row);
    }

    // FIX-09: what the source published, including announcements long expired and not in
    // job_postings above. 2026-09 is 70 announcements here against 37 in the slice; the
    // 3 undated rows and the county-less one are explicit buckets. Months with nothing
    // (2026-01..07) are absent, as in the export.
    $hist = $db->prepare('INSERT INTO publication_monthly (agg_version, month, judet_id, postings, cancelled) VALUES (1,?,?,?,?)');
    foreach (FIXTURE_PUBLICATION_MONTHLY as $row) {
        $hist->execute($row);
    }

    if ($split) fixture_split_posting_text($db);
}

/** [month, judet_id, postings, cancelled] — SUM(postings) = 152, cancelled 8, undated 3. */
const FIXTURE_PUBLICATION_MONTHLY = [
    ['2025-11', 1, 4, 0],
    ['2025-12', 1, 6, 1],
    ['2025-12', 3, 5, 0],
    ['2026-08', 1, 20, 2],
    ['2026-08', 2, 15, 0],
    ['2026-08', 3, 28, 1],
    ['2026-09', 1, 30, 1],
    ['2026-09', 2, 12, 0],
    ['2026-09', 3, 27, 2],
    ['2026-09', 0, 1, 0],
    ['2026-10', 3, 1, 0],
    ['unknown', 0, 3, 1],
];

/**
 * The export's split_posting_text() (PERF-SPLIT): the long texts move to posting_text,
 * has_schema stands in for `schema_json IS NOT NULL`, and the FTS index is rebuilt
 * contentless. Columns the fixture does not carry are written as their empty value.
 */
function fixture_split_posting_text(PDO $db): void {
    $have = array_flip($db->query("SELECT name FROM pragma_table_info('job_postings')")->fetchAll(PDO::FETCH_COLUMN));
    $text = ['body_markdown' => "''", 'schema_json' => 'NULL', 'sal_json' => 'NULL', 'attachment_meta' => "'[]'",
             'other_links' => "'[]'", 'detalii_raw' => "''", 'v3_bibliography' => "'[]'"];
    $db->exec("CREATE TABLE posting_text (
        posting_id INTEGER PRIMARY KEY REFERENCES job_postings(id) ON DELETE CASCADE,
        body_markdown TEXT NOT NULL DEFAULT '', schema_json TEXT, sal_json TEXT,
        attachment_meta TEXT NOT NULL DEFAULT '[]', other_links TEXT NOT NULL DEFAULT '[]',
        detalii_raw TEXT NOT NULL DEFAULT '', v3_bibliography TEXT NOT NULL DEFAULT '[]')");
    $sel = implode(', ', array_map(fn($c, $e) => isset($have[$c]) ? $c : $e, array_keys($text), $text));
    $db->exec("INSERT INTO posting_text (posting_id, " . implode(', ', array_keys($text)) . ") SELECT id, $sel FROM job_postings");
    $db->exec("ALTER TABLE job_postings ADD COLUMN has_schema INTEGER NOT NULL DEFAULT 0");
    $db->exec("UPDATE job_postings SET has_schema = (schema_json IS NOT NULL)");
    foreach (array_keys($text) as $c) {
        if (isset($have[$c])) $db->exec("ALTER TABLE job_postings DROP COLUMN $c");
    }
    $db->exec("DROP TABLE job_postings_fts");
    $db->exec("CREATE VIRTUAL TABLE job_postings_fts USING fts5(title, employer_name, judet_name, body_text,
        content='', tokenize='unicode61 remove_diacritics 2')");
    $fts = $db->prepare("INSERT INTO job_postings_fts (rowid, title, employer_name, judet_name, body_text) VALUES (?, ?, ?, ?, ?)");
    foreach ($db->query("SELECT j.id, j.title, j.employer_name, j.judet_name, t.body_markdown
                         FROM job_postings j JOIN posting_text t ON t.posting_id = j.id")->fetchAll() as $r) {
        $fts->execute([$r['id'], $r['title'], $r['employer_name'], $r['judet_name'], strip_tags($r['body_markdown'])]);
    }
}


/** posting_roles columns after posting_id, in the export's order (posting_roles.py ROLE_COLUMNS). */
const FIXTURE_ROLE_COLUMNS = ['ordinal', 'role_key', 'decomposed', 'title', 'vacancy_count', 'study_level',
    'eqf_level', 'isced_fields', 'exp_years', 'exp_in_specialty', 'exp_none_required', 'skills',
    'credentials', 'seniority_hint', 'source_revision', 'role_format'];

/** Sorted unique JSON array of the non-empty strings — the export's `_dumps`. */
function fixture_dumps(array $values): string {
    $v = array_values(array_unique(array_filter($values, fn($x) => is_string($x) && $x !== '')));
    sort($v, SORT_STRING);
    // json.dumps(sorted(...), ensure_ascii=False): ", " between items, no escaped slashes.
    return '[' . implode(', ', array_map(fn($x) => json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $v)) . ']';
}

/**
 * PHP port of posting_roles.role_rows() for a fixture row (a job_postings row as an
 * associative array). One decomposed row per positions[] entry, values from that
 * entry only and NULL when unstated; otherwise one implicit row copying the flat
 * columns. Only the `src` and `none` key bases exist here: a fixture posting that
 * has a schema must carry a schema_source_revision, so keys never depend on how
 * PHP happens to serialise JSON.
 */
function fixture_role_rows(array $row): array {
    $schema = !empty($row['schema_json']) ? json_decode($row['schema_json'], true) : null;
    if (!is_array($schema)) $schema = null;
    $rev = strtolower(trim((string)($row['schema_source_revision'] ?? '')));
    if ($schema !== null && $rev === '') {
        throw new RuntimeException('fixture ' . $row['id'] . ': a posting with a schema needs schema_source_revision');
    }
    $basis = $rev !== '' ? 'src' : 'none';
    $key = fn(int $ordinal): string => $basis . ':' . ($rev !== '' ? substr($rev, 0, 12) : '-') . ':' . $ordinal;
    $source_rev = $rev !== '' ? trim((string)$row['schema_source_revision']) : null;
    $tri = fn($v) => $v === null ? null : ($v ? 1 : 0);

    $rows = [];
    $entries = is_array($schema['positions'] ?? null) ? array_values($schema['positions']) : [];
    foreach ($entries as $i => $e) {
        if (!is_array($e)) continue;
        $edu = $e['education'] ?? null;
        $exp = is_array($e['experience'] ?? null) ? $e['experience'] : [];
        $years = isset($exp['years_minimum']) && is_numeric($exp['years_minimum']) ? (float)$exp['years_minimum'] : null;
        if ($years === null && ($exp['none_required'] ?? null) === true) $years = 0.0;
        $fields = is_array($edu) ? array_filter((array)($edu['fields_of_study'] ?? []), 'is_array') : [];
        $count = isset($e['count']) && is_int($e['count']) && $e['count'] >= 1 ? $e['count'] : null;
        $rows[] = [
            'ordinal' => $i + 1, 'role_key' => $key($i + 1), 'decomposed' => 1,
            'title' => isset($e['title']) && is_string($e['title']) && trim($e['title']) !== '' ? trim($e['title']) : null,
            'vacancy_count' => $count,
            'study_level' => is_array($edu) ? ($edu['minimum_level'] ?? null) ?: null : null,
            'eqf_level' => is_array($edu) && isset($edu['eqf_level']) && is_int($edu['eqf_level']) ? $edu['eqf_level'] : null,
            'isced_fields' => is_array($edu) ? fixture_dumps(array_map(fn($f) => $f['isced_field'] ?? null, $fields)) : null,
            'exp_years' => $years,
            'exp_in_specialty' => $exp ? $tri($exp['in_specialty'] ?? null) : null,
            'exp_none_required' => $exp ? $tri($exp['none_required'] ?? null) : null,
            'skills' => is_array($e['skill_list'] ?? null)
                ? fixture_dumps(array_map(fn($k) => is_array($k) ? ($k['label'] ?? null) : null, $e['skill_list'])) : null,
            'credentials' => is_array($e['credentials'] ?? null)
                ? fixture_dumps(array_map(fn($c) => is_array($c) ? ($c['kind'] ?? null) : null, $e['credentials'])) : null,
            'seniority_hint' => ($e['seniority_hint'] ?? null) ?: null,
            'source_revision' => $source_rev, 'role_format' => 1,
        ];
    }
    if ($rows) return $rows;

    $flat_exp = is_array($schema['experience'] ?? null) ? $schema['experience'] : [];
    return [[
        'ordinal' => 0, 'role_key' => $key(0), 'decomposed' => 0, 'title' => null, 'vacancy_count' => null,
        'study_level' => ($row['inf_studies_required'] ?? null) ?: null,
        'eqf_level' => $row['v3_eqf_level'] ?? null,
        'isced_fields' => ($row['v3_isced_fields'] ?? null) ?: '[]',
        'exp_years' => $row['inf_experience_years'] ?? null,
        'exp_in_specialty' => $flat_exp ? $tri($flat_exp['in_specialty'] ?? null) : null,
        'exp_none_required' => $flat_exp ? $tri($flat_exp['none_required'] ?? null) : null,
        'skills' => ($row['v3_skills'] ?? null) ?: '[]',
        'credentials' => ($row['v3_credentials'] ?? null) ?: '[]',
        'seniority_hint' => ($row['v3_seniority_hint'] ?? null) ?: null,
        'source_revision' => $source_rev, 'role_format' => 1,
    ]];
}

/**
 * DATA-04 role-scope postings (ids 1009-1012). Flat columns follow what a real
 * extraction does with a multi-role posting: they describe ONE role's values or a
 * union of all of them, which is exactly how a posting-level filter ends up
 * matching a combination no single role satisfies.
 */
function fixture_role_case_postings(callable $insert, array $base): void {
    $field = fn(string $iscedcode, string $label) => ['isced_field' => $iscedcode, 'label_ro' => $label];

    // 1009: two roles with contrasting requirements. Role A: university, five years in the
    // specialty. Role B: secondary school, no experience. The flat columns hold role A's
    // study level but the "no experience" figure, and the union of both roles' fields,
    // skills and credentials — so the old flat predicate matches "university + zero
    // experience" and "Contabilitate + permis de conducere", which no single role does.
    $insert([
        'id' => 1009, 'url' => 'https://posturi.gov.ro/anunt/fixture-1009',
        'title' => 'Expert contabil și referent (două roluri)', 'employer_id' => 1,
        'employer_name' => 'Spitalul Clinic Județean Cluj',
        'published_at' => '2026-09-24 09:00:00', 'expires_at' => '2026-10-28 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Permanent', 'categorie' => 'Personal contractual',
        'body_markdown' => 'Concurs pentru două posturi cu cerințe diferite: expert contabil și referent.',
        'nr_posturi' => 3,
        'schema_json' => json_encode([
            'education' => ['minimum_level' => 'licenta', 'eqf_level' => 6,
                'fields_of_study' => [$field('04_afaceri_administratie_drept', 'contabilitate')]],
            'positions' => [
                ['title' => 'Expert contabil', 'count' => 1,
                 'education' => ['minimum_level' => 'licenta', 'eqf_level' => 6,
                     'fields_of_study' => [$field('04_afaceri_administratie_drept', 'contabilitate')]],
                 'experience' => ['years_minimum' => 5, 'in_specialty' => true, 'none_required' => false],
                 'skill_list' => [['label' => 'Contabilitate']],
                 'credentials' => [['label' => 'Autorizație CECCAR', 'kind' => 'autorizatie']]],
                ['title' => 'Referent', 'count' => 2,
                 'education' => ['minimum_level' => 'liceala', 'eqf_level' => 4,
                     'fields_of_study' => [$field('03_stiinte_sociale', 'științe sociale')]],
                 'experience' => ['years_minimum' => 0, 'in_specialty' => false, 'none_required' => true],
                 'skill_list' => [['label' => 'Excel']],
                 'credentials' => [['label' => 'Permis categoria B', 'kind' => 'permis_conducere']]],
            ],
        ], JSON_UNESCAPED_UNICODE),
        'schema_source_revision' => 'a1009b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8',
        'schema_prompt_version' => 'v4',
        'inf_profession_family' => 'administrație', 'inf_studies_required' => 'licenta', 'inf_experience_years' => 0,
        'v3_eqf_level' => 6, 'v3_study_level' => 'licenta',
        'v3_isced_fields' => '["03_stiinte_sociale","04_afaceri_administratie_drept"]',
        'v3_skills' => '["Contabilitate","Excel"]', 'v3_credentials' => '["autorizatie","permis_conducere"]',
        'v3_positions' => 2, 'occ_canonical' => 'Expert contabil', 'sal_min' => 4800, 'sal_max' => 5200,
        'apply_deadline' => '2026-10-22', 'deadline_source' => 'concurs', 'application_status' => 'confirmed_open',
    ] + $base);

    // 1010: duplicate role titles with different requirements. Two rows, two keys.
    $insert([
        'id' => 1010, 'url' => 'https://posturi.gov.ro/anunt/fixture-1010',
        'title' => 'Inspector de specialitate (2 posturi)', 'employer_id' => 2,
        'employer_name' => 'Primăria Comunei Găiceana',
        'judet_id' => 2, 'judet_name' => 'Bacău', 'judet_slug' => 'bacau', 'locality' => 'Găiceana',
        'published_at' => '2026-09-23 09:00:00', 'expires_at' => '2026-10-29 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Permanent', 'categorie' => 'Funcționari publici',
        'body_markdown' => 'Două posturi cu aceeași denumire și cerințe diferite.',
        'nr_posturi' => 2,
        'schema_json' => json_encode([
            'education' => ['minimum_level' => 'licenta', 'eqf_level' => 6],
            'positions' => [
                ['title' => 'Inspector de specialitate', 'count' => 1,
                 'education' => ['minimum_level' => 'licenta', 'eqf_level' => 6, 'fields_of_study' => []],
                 'experience' => ['years_minimum' => 3, 'in_specialty' => true],
                 'skill_list' => [['label' => 'Contabilitate']], 'credentials' => []],
                ['title' => 'Inspector de specialitate', 'count' => 1,
                 'education' => ['minimum_level' => 'master', 'eqf_level' => 7, 'fields_of_study' => []],
                 'experience' => ['years_minimum' => 1, 'in_specialty' => false],
                 'skill_list' => [['label' => 'Achiziții publice']], 'credentials' => []],
            ],
        ], JSON_UNESCAPED_UNICODE),
        'schema_source_revision' => 'b1010c2d3e4f5061728394a5b6c7d8e9f0a1b2c3d4e5f6071829304a5b6c7d8e',
        'schema_prompt_version' => 'v4',
        'inf_profession_family' => 'administrație', 'inf_studies_required' => 'licenta', 'inf_experience_years' => 3,
        'v3_eqf_level' => 6, 'v3_study_level' => 'licenta', 'v3_skills' => '["Achiziții publice","Contabilitate"]',
        'v3_positions' => 2, 'occ_canonical' => 'Inspector', 'sal_min' => 4500, 'sal_max' => 5000,
        'apply_deadline' => '2026-10-23', 'deadline_source' => 'concurs', 'application_status' => 'confirmed_open',
    ] + $base);

    // 1011: ONE positions entry — a single role, never a "several roles" warning.
    $insert([
        'id' => 1011, 'url' => 'https://posturi.gov.ro/anunt/fixture-1011',
        'title' => 'Șofer autospecială', 'employer_id' => 3,
        'employer_name' => 'Administrația de Salubrizare Tunari',
        'judet_id' => 3, 'judet_name' => 'Ilfov', 'judet_slug' => 'ilfov', 'locality' => 'Tunari',
        'published_at' => '2026-09-22 09:00:00', 'expires_at' => '2026-10-27 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Permanent', 'categorie' => 'Personal contractual',
        'body_markdown' => 'Concurs pentru un post de șofer.',
        'nr_posturi' => 1,
        'schema_json' => json_encode([
            'education' => ['minimum_level' => 'liceala', 'eqf_level' => 4],
            'positions' => [
                ['title' => 'Șofer autospecială', 'count' => 1,
                 'education' => ['minimum_level' => 'liceala', 'eqf_level' => 4, 'fields_of_study' => []],
                 'experience' => ['years_minimum' => 2, 'in_specialty' => true, 'none_required' => false],
                 'credentials' => [['label' => 'Permis categoria C', 'kind' => 'permis_conducere']]],
            ],
        ], JSON_UNESCAPED_UNICODE),
        'schema_source_revision' => 'c1011d2e3f405162738495a6b7c8d9e0f1a2b3c4d5e6f708192a3b4c5d6e7f80',
        'schema_prompt_version' => 'v4',
        'inf_profession_family' => 'tehnic', 'inf_studies_required' => 'liceala', 'inf_experience_years' => 2,
        'v3_eqf_level' => 4, 'v3_study_level' => 'liceala', 'v3_credentials' => '["permis_conducere"]',
        'v3_positions' => 1, 'occ_canonical' => 'Șofer', 'sal_min' => 3400, 'sal_max' => 3600,
        'apply_deadline' => '2026-10-24', 'deadline_source' => 'anunt', 'application_status' => 'confirmed_open',
    ] + $base);

    // 1012: fractional and "none" experience. Role A asks six months; role B states that none is
    // needed (years left null — exp_years becomes 0 only because none_required says so).
    $insert([
        'id' => 1012, 'url' => 'https://posturi.gov.ro/anunt/fixture-1012',
        'title' => 'Îngrijitor și muncitor (6 luni / fără experiență)', 'employer_id' => 2,
        'employer_name' => 'Primăria Comunei Găiceana',
        'judet_id' => 2, 'judet_name' => 'Bacău', 'judet_slug' => 'bacau', 'locality' => 'Găiceana',
        'published_at' => '2026-09-21 09:00:00', 'expires_at' => '2026-10-26 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Temporar', 'categorie' => 'Personal contractual',
        'body_markdown' => 'Două posturi, unul cu șase luni de vechime, unul fără experiență.',
        'nr_posturi' => 2,
        'schema_json' => json_encode([
            'education' => null,
            'positions' => [
                ['title' => 'Îngrijitor', 'count' => 1,
                 'education' => ['minimum_level' => 'generala', 'eqf_level' => 2, 'fields_of_study' => []],
                 'experience' => ['years_minimum' => 0.5, 'in_specialty' => false, 'none_required' => false]],
                // Markup in a role title is data: the detail page must escape it.
                ['title' => 'Muncitor <u>necalificat</u> & curățenie', 'count' => 1,
                 'education' => ['minimum_level' => 'generala', 'eqf_level' => 2, 'fields_of_study' => []],
                 'experience' => ['years_minimum' => null, 'in_specialty' => false, 'none_required' => true]],
            ],
        ], JSON_UNESCAPED_UNICODE),
        'schema_source_revision' => 'd1012e2f30415263748596a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f7081',
        'schema_prompt_version' => 'v4',
        'inf_profession_family' => 'social', 'inf_studies_required' => 'generala', 'inf_experience_years' => 0,
        'v3_eqf_level' => 2, 'v3_study_level' => 'generala', 'v3_positions' => 2,
        'occ_canonical' => 'Îngrijitor', 'sal_min' => 2800, 'sal_max' => 2900,
        'apply_deadline' => '2026-10-25', 'deadline_source' => 'anunt', 'application_status' => 'confirmed_open',
    ] + $base);
}


/**
 * UX-13 posting 1013: NO positions[] (one implicit role, ordinal 0), flat v3 facts, a
 * `javascript:` announcement_url and markup in labels. Its evidence rows are written by
 * fixture_insert_evidence(); the schema carries the same facts so the page renders them.
 */
function fixture_evidence_posting(callable $insert, array $base): void {
    $insert([
        'id' => 1013, 'url' => 'https://posturi.gov.ro/anunt/fixture-1013',
        'title' => 'Referent cu surse (fără roluri)', 'employer_id' => 1,
        'employer_name' => 'Spitalul Clinic Județean Cluj',
        'announcement_url' => 'javascript:alert(1)',
        'published_at' => '2026-09-19 09:00:00', 'expires_at' => '2026-10-31 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Permanent', 'categorie' => 'Personal contractual',
        'body_markdown' => "# Titlu\n\nStudii superioare și vechime de 3 ani.",
        'nr_posturi' => 1,
        'schema_json' => json_encode([
            'education' => ['minimum_level' => 'licenta', 'eqf_level' => 6,
                'fields_of_study' => [['isced_field' => '04_afaceri_administratie_drept', 'label_ro' => 'economie']],
                'verbatim' => 'studii superioare absolvite cu diplomă de licență'],
            'experience' => ['years_minimum' => 3, 'in_specialty' => true, 'none_required' => false,
                'verbatim' => 'vechime minim 3 ani în specialitate'],
            'skill_list' => [
                ['label' => 'Excel', 'required' => true, 'evidence' => 'FLAT-SKILL-EVIDENCE-RAW cunoștințe Excel'],
                ['label' => 'Word <b>', 'required' => true, 'evidence' => 'text extras <script>x</script>'],
                ['label' => 'GIS', 'required' => true, 'evidence' => 'GIS'],
                ['label' => 'Fără rând', 'required' => false, 'evidence' => 'doar în schemă'],
            ],
            'credentials' => [['label' => 'Permis categoria B', 'kind' => 'permis_conducere']],
        ], JSON_UNESCAPED_UNICODE),
        'schema_source_revision' => 'e1013f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6',
        'schema_prompt_version' => 'v4',
        'inf_profession_family' => 'administrație', 'inf_studies_required' => 'licenta', 'inf_experience_years' => 3,
        'v3_eqf_level' => 6, 'v3_study_level' => 'licenta', 'v3_isced_fields' => '["04_afaceri_administratie_drept"]',
        'v3_skills' => fixture_dumps(['Excel', 'Word <b>', 'GIS', 'Fără rând']),
        'v3_credentials' => '["permis_conducere"]',
        'occ_canonical' => 'Referent', 'sal_min' => 3300, 'sal_max' => 3500,
        'apply_deadline' => '2026-10-26', 'deadline_source' => 'anunt', 'application_status' => 'confirmed_open',
    ] + $base);
}

/** The quote that sits ONLY under role 1 of posting 1009 — it must never render under role 2. */
const FIXTURE_ROLE1_QUOTE = 'ROLE1-UNIQUE diplomă de licență în domeniul economic';
const FIXTURE_ROLE2_QUOTE = 'ROLE2-UNIQUE studii liceale absolvite cu bacalaureat';
const FIXTURE_HOSTILE_QUOTE = '<script>alert("edu")</script> "><img src=x onerror=alert(2)>';

/**
 * `requirement_evidence` rows for 1009 (two roles) and 1013 (one implicit role), covering
 * verified / unverified (not_found, too_short) / absent, an attachment hit, duplicates,
 * hostile text, and a row for an ordinal no role has. Role keys are read back from
 * posting_roles so they match the table the page is served with.
 */
function fixture_insert_evidence(PDO $db): void {
    $key = function (int $posting, int $ordinal) use ($db): string {
        $st = $db->prepare('SELECT role_key FROM posting_roles WHERE posting_id = ? AND ordinal = ?');
        $st->execute([$posting, $ordinal]);
        return (string)$st->fetchColumn();
    };
    $rev = fn(int $posting) => (string)$db->query("SELECT schema_source_revision FROM job_postings WHERE id = $posting")->fetchColumn();
    $ins = $db->prepare('INSERT INTO requirement_evidence (posting_id, role_key, ordinal, fact_kind, fact_index,
        fact_label, basis, quote, status, reason, part, section, occurrences, source_revision, extraction_revision,
        text_hash, normalization) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $add = function (int $posting, int $ordinal, string $kind, int $index, string $label, ?string $quote, string $status,
                     ?string $reason = null, ?string $part = null, ?string $section = null, ?int $occ = null) use ($ins, $key, $rev) {
        $ins->execute([$posting, $key($posting, $ordinal), $ordinal, $kind, $index, $label, 'extracted', $quote, $status,
            $reason, $part, $section, $occ, $rev($posting), $rev($posting), str_repeat('c', 64), 'fold-alnum-v1']);
    };

    // 1009, role 1 (Expert contabil).
    $add(1009, 1, 'education', 0, 'licenta', FIXTURE_ROLE1_QUOTE, 'verified', null, 'anunt', 'Condiții de participare', 1);
    $add(1009, 1, 'experience', 0, '5 ani în specialitate', 'ROLE1-EXP vechime de cinci ani în specialitate', 'unverified', 'not_found');
    $add(1009, 1, 'skill', 0, 'Contabilitate', 'ROLE1-SKILL cunoștințe de contabilitate', 'verified', null, 'anexe', null, 2);
    $add(1009, 1, 'credential', 0, 'Autorizație CECCAR', null, 'absent', 'no_evidence_field');
    // 1009, role 2 (Referent).
    $add(1009, 2, 'education', 0, 'liceala', FIXTURE_ROLE2_QUOTE, 'verified', null, 'anunt', null, 1);
    $add(1009, 2, 'experience', 0, 'fără experiență', null, 'absent');
    $add(1009, 2, 'skill', 0, 'Excel', 'Excel', 'unverified', 'too_short');
    $add(1009, 2, 'credential', 0, 'Permis categoria B', null, 'absent', 'no_evidence_field');
    // An ordinal that is no role of 1009: never shown.
    $ins->execute([1009, 'src:a1009b2c3d4e:9', 9, 'skill', 0, 'Fantomă', 'extracted', 'ROLE9-GHOST-QUOTE text fantomă', 'verified',
        null, 'anunt', null, 1, $rev(1009), $rev(1009), str_repeat('c', 64), 'fold-alnum-v1']);

    // 1013, the implicit role (ordinal 0).
    $add(1013, 0, 'education', 0, 'licenta', FIXTURE_HOSTILE_QUOTE, 'verified', null, 'anunt', 'Titlu <b>hostil</b>', 1);
    $add(1013, 0, 'experience', 0, '3 ani în specialitate', 'vechime minim 3 ani în specialitate', 'verified', null, 'anexe', null, 1);
    $add(1013, 0, 'skill', 0, 'Excel', 'FLAT-SKILL-EVIDENCE-RAW cunoștințe Excel', 'verified', null, 'anunt', 'Titlu', 3);
    $add(1013, 0, 'skill', 1, 'Word <b>', 'text extras <script>x</script>', 'unverified', 'not_found');
    $add(1013, 0, 'skill', 2, 'GIS', 'GIS', 'unverified', 'too_short');
    $add(1013, 0, 'credential', 0, 'Permis categoria B', null, 'absent', 'no_evidence_field');
}
