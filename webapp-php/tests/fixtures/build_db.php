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
 */

function build_fixture_db(string $path): void {
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
    other_links             TEXT NOT NULL DEFAULT '[]',
    attachment_meta         TEXT NOT NULL DEFAULT '[]',
    inferred                TEXT NOT NULL DEFAULT '{}',
    schema_json             TEXT,
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
    apply_deadline          TEXT,
    deadline_source         TEXT NOT NULL DEFAULT ''
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
    PRIMARY KEY (day, provider, model, prompt_version)
);
CREATE TABLE build_meta (
    id              INTEGER PRIMARY KEY CHECK (id = 1),
    built_at        TEXT NOT NULL,
    git_sha         TEXT NOT NULL DEFAULT '',
    source_host     TEXT NOT NULL DEFAULT '',
    active_only     INTEGER NOT NULL DEFAULT 0,
    job_postings    INTEGER NOT NULL DEFAULT 0,
    employers       INTEGER NOT NULL DEFAULT 0,
    calendar_events INTEGER NOT NULL DEFAULT 0
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
        'created_at','updated_at','last_seen_at','other_links','attachment_meta','inferred','schema_json',
        'inf_profession_family','inf_seniority','inf_anomaly_flags','inf_work_type','inf_remote_eligible',
        'inf_requires_computer','inf_experience_years','inf_studies_required',
        'v3_eqf_level','v3_study_level','v3_isced_fields','v3_study_labels','v3_skills','v3_languages',
        'v3_credentials','v3_policy_domains','v3_exam_stages','v3_positions',
        'v3_contract_duration','v3_schedule','v3_hours_per_week','v3_shift_work','v3_remote_mode',
        'v3_seniority_hint','v3_bibliography','inf_salary_min','inf_salary_max',
        'occ_canonical','occ_cor_code','occ_isco_group','occ_confidence',
        'sal_min','sal_max','sal_confidence','sal_variant','sal_json',
        'v4_funding_source','v4_funding_programme','v4_employer_sector','v4_parent_institution',
        'v4_application_deadline','apply_deadline','deadline_source'];
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
        'last_seen_at' => '2026-10-03 09:00:00',
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
                ['count' => 1, 'title' => 'Referent', 'education' => ['minimum_level' => 'liceala', 'eqf_level' => 4]],
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
        'occ_canonical' => 'Referent', 'sal_min' => 3300, 'sal_max' => 3300,
        'apply_deadline' => '2026-10-21', 'deadline_source' => 'concurs',
    ] + $base);

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

    $db->exec("INSERT INTO build_meta (id, built_at, git_sha, source_host, active_only, job_postings, employers, calendar_events)
        VALUES (1, '2026-10-03 09:00:00', 'fixture', 'fixture-host', 1, 38, 3, 4)");
    $db->exec("INSERT INTO llm_costs VALUES ('2026-10-02', 'openrouter', 'fixture-model', 'v4', 3, 12000, 900, 0.01)");
}
