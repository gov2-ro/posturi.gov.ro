<?php
/**
 * BETA-V5 fixture: the plain fixture (v4-era rows) plus two postings extracted with prompt
 * v5, so every v5 surface is checked against a MIXED corpus, as production will be
 * (docs/specs/2026-10-16-v5-extraction/README.md §6).
 *
 *   1201  v5, a 0,75 temporarily vacant night post with an end date, apply in person or by
 *         e-mail, a standard + specific dosar, a calendar with an undated (relative) row,
 *         a quoted driving licence, two work localities
 *   1202  v5, a promotion exam (internal only), 3 seats, no dosar
 */

require_once __DIR__ . '/build_db.php';

function v5_fixture_postings(): array {
    $posting = fn(int $id, string $title, array $schema, array $extra = []) => $extra + [
        'id' => $id, 'url' => "https://posturi.gov.ro/anunt/fixture-$id", 'title' => $title,
        'employer_id' => 1, 'employer_name' => 'Spitalul Clinic Județean Cluj',
        'judet_id' => 1, 'judet_name' => 'Cluj', 'judet_slug' => 'cluj', 'locality' => 'Cluj-Napoca',
        'published_at' => '2026-09-30 09:00:00', 'expires_at' => '2026-11-02 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Temporar', 'categorie' => 'Personal contractual',
        'body_markdown' => "Concurs v5 pentru fixture-ul $id. Candidații trebuie să dețină permis de conducere categoria B.",
        'nr_posturi' => 1,
        'schema_json' => json_encode($schema, JSON_UNESCAPED_UNICODE),
        'schema_source_revision' => "v5rev$id", 'detail_content_hash' => "v5rev$id", 'schema_prompt_version' => 'v5',
        'apply_deadline' => '2026-10-20', 'deadline_source' => 'concurs', 'application_status' => 'confirmed_open',
    ];
    $base = ['educationRequirements' => null, 'experienceRequirements' => null, 'skills' => null,
             'application_docs' => null, 'skill_list' => [], 'credentials' => [], 'positions' => []];
    return [
        $posting(1201, 'Supraveghetor de noapte (v5)', $base + [
            'education' => ['minimum_level' => 'liceala', 'eqf_level' => 4, 'fields_of_study' => [],
                            'required' => true, 'verbatim' => 'Studii medii cu diplomă de bacalaureat.'],
            'experience' => ['years_minimum' => null, 'in_specialty' => false, 'none_required' => true,
                             'verbatim' => 'Nu se solicită vechime.'],
            'credentials' => [['label' => 'permis categoria B', 'kind' => 'permis_conducere', 'issuer' => null, 'required' => true,
                               'evidence' => 'Candidații trebuie să dețină permis de conducere categoria B.', 'licence_category' => 'B']],
            'contract' => ['duration' => 'determinata', 'duration_months' => null, 'schedule' => 'tura_noapte',
                           'hours_per_week' => 30, 'remote_mode' => null, 'probation_months' => null, 'shift_work' => true,
                           'fte' => 0.75, 'end_date' => '2027-06-30', 'post_status' => 'temporar_vacant'],
            'competition_calendar' => ['application_deadline' => '2026-10-20', 'application_deadline_time' => '14:00', 'events' => [
                ['stage' => 'proba_scrisa', 'date' => '2026-10-27', 'time' => '10:00', 'relative' => null, 'verbatim' => 'Proba scrisă'],
                ['stage' => 'depunere_dosare', 'date' => '2026-10-20', 'time' => '14:00', 'relative' => null,
                 'verbatim' => 'Depunerea dosarelor la secretariat'],
                ['stage' => 'rezultate_selectie_dosare', 'date' => null, 'time' => null,
                 'relative' => '2 zile lucrătoare după depunere', 'verbatim' => 'Rezultatul selecției dosarelor'],
            ]],
            'recruitment' => ['kind' => 'recrutare', 'internal_only' => false], 'vacancies_total' => 1,
            'application' => ['channels' => ['sediu', 'email'], 'window_start' => '2026-10-01'],
            'age_limit' => null, 'work_localities' => ['Florești', 'Apahida'],
            'documents' => ['standard' => ['formular_inscriere', 'act_identitate', 'cazier_judiciar'],
                            'specific' => [['label' => 'aviz psihologic', 'evidence' => 'aviz psihologic pentru postul de supraveghetor', 'roles' => []]]],
            'employer_context' => ['sector' => 'educatie', 'parent_institution' => null, 'subordinated_to' => 'Consiliul Local Cluj-Napoca',
                                   'uat_type' => null, 'uat_name' => null],
        ], ['v5_recruitment_kind' => 'recrutare', 'v5_internal_only' => 0, 'v5_vacancies_total' => 1, 'v5_fte' => 0.75,
            'v5_post_status' => 'temporar_vacant', 'v5_end_date' => '2027-06-30', 'v5_channels' => '["email", "sediu"]',
            'v5_work_localities' => '["Florești", "Apahida"]', 'v3_contract_duration' => 'determinata', 'v3_schedule' => 'tura_noapte']),
        $posting(1202, 'Promovare cercetător (v5)', $base + [
            'recruitment' => ['kind' => 'promovare', 'internal_only' => true], 'vacancies_total' => 3,
            'application' => ['channels' => ['sediu'], 'window_start' => null],
            'contract' => null, 'documents' => null, 'work_localities' => [], 'age_limit' => null,
        ], ['v5_recruitment_kind' => 'promovare', 'v5_internal_only' => 1, 'v5_vacancies_total' => 3,
            'v5_channels' => '["sediu"]']),
    ];
}

function build_v5_db(string $path): void {
    build_fixture_db($path, false, false, false);   // rows are added below, then split
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ins_r = $db->prepare('INSERT INTO posting_roles (posting_id, ' . implode(', ', FIXTURE_ROLE_COLUMNS)
        . ') VALUES (?, ' . implode(', ', array_fill(0, count(FIXTURE_ROLE_COLUMNS), '?')) . ')');
    foreach (v5_fixture_postings() as $row) {
        $cols = array_keys($row);
        $db->prepare('INSERT INTO job_postings (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        $full = $db->query('SELECT * FROM job_postings WHERE id = ' . (int)$row['id'])->fetch(PDO::FETCH_ASSOC);
        foreach (fixture_role_rows($full) as $role) {
            $ins_r->execute(array_merge([$row['id']], array_map(fn($c) => $role[$c], FIXTURE_ROLE_COLUMNS)));
        }
    }
    $db->exec("INSERT INTO calendar_events (posting_id, eveniment, data, ora) VALUES
        (1201, 'Calendar extras din tabel (rezervă)', '2026-10-27', '10:00')");
    fixture_split_posting_text($db);
}
