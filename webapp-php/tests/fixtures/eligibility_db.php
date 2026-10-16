<?php
/**
 * UX-10 fixture: the evidence fixture (role cases + evidence rows, `build_fixture_db($p, true, true)`)
 * plus postings 1040-1044 that exercise the "Pot aplica?" panel. They live in this file, applied after
 * the shared fixture is built, so no other suite's pinned counts or ID sets can move.
 *
 *   1040  two contrasting roles: licență + 5 years in the specialty + two documents (partial licences),
 *         versus liceală + an explicit "no experience" + one document. All education/experience quotes verified.
 *   1041  two roles at the SAME level (licență, EQF 6) in different fields (drept / inginerie civilă).
 *   1042  four roles: explicit zero experience; unverified education + experience not stated at all;
 *         education offering alternatives ("sau echivalent") with specialty not stated; experience offering alternatives.
 *   1044  three roles: total (not specialty) experience; an in_specialty=false flag the sentence contradicts;
 *         education and a document framed as an advantage ("required": false).
 *   1043  hostile text: a role title and a credential label that try to close the embedded JSON <script>.
 *
 * Usage: build_eligibility_db('/tmp/x.sqlite');  CLI: php tests/fixtures/make.php <path> eligibility
 */

require_once __DIR__ . '/build_db.php';

/** @return list<array{row:array<string,mixed>,evidence:list<array<int,mixed>>}> */
function eligibility_fixture_postings(): array {
    $f = fn(string $code, string $label) => ['isced_field' => $code, 'label_ro' => $label];
    $rev = fn(string $n) => $n . str_repeat('ab12cd34', 7) . 'ef';        // 64 hex-ish chars, distinct first 12
    $posting = fn(int $id, string $title, array $schema, array $extra = []) => $extra + [
        'id' => $id, 'url' => "https://posturi.gov.ro/anunt/fixture-$id", 'title' => $title,
        'employer_id' => 1, 'employer_name' => 'Spitalul Clinic Județean Cluj',
        'judet_id' => 1, 'judet_name' => 'Cluj', 'judet_slug' => 'cluj', 'locality' => 'Cluj-Napoca',
        'published_at' => '2026-09-18 09:00:00', 'expires_at' => '2026-11-02 23:59:59',
        'job_level' => 'executie', 'job_type' => 'Permanent', 'categorie' => 'Personal contractual',
        'body_markdown' => "Concurs pentru posturile din fixture-ul $id.", 'nr_posturi' => 2,
        'schema_json' => json_encode($schema, JSON_UNESCAPED_UNICODE),
        'schema_source_revision' => $rev((string)$id), 'schema_prompt_version' => 'v4',
        'v3_positions' => count($schema['positions'] ?? []),
        'apply_deadline' => '2026-10-28', 'deadline_source' => 'anunt', 'application_status' => 'confirmed_open',
    ];

    // Evidence tuples: [ordinal, kind, index, label, quote, status, reason, part, section, occurrences]
    $v   = fn(int $o, string $k, string $q, string $label = 'fapt', int $i = 0) => [$o, $k, $i, $label, $q, 'verified', null, 'anunt', null, 1];
    $nf  = fn(int $o, string $k, string $q, string $label = 'fapt') => [$o, $k, 0, $label, $q, 'unverified', 'not_found', null, null, null];
    $cred = fn(int $o, int $i, string $label) => [$o, 'credential', $i, $label, null, 'absent', 'no_evidence_field', null, null, null];

    return [
        ['row' => $posting(1040, 'Eligibilitate: două roluri contrastante', ['positions' => [
            ['title' => 'Inspector superior', 'count' => 1,
             'education' => ['minimum_level' => 'licenta', 'eqf_level' => 6,
                 'fields_of_study' => [$f('04_afaceri_administratie_drept', 'economie')]],
             'experience' => ['years_minimum' => 5, 'in_specialty' => true, 'none_required' => false],
             'credentials' => [['label' => 'Autorizație CECCAR', 'kind' => 'autorizatie'],
                               ['label' => 'Atestat de limba engleză', 'kind' => 'certificat_profesional']]],
            ['title' => 'Referent', 'count' => 1,
             'education' => ['minimum_level' => 'liceala', 'eqf_level' => 4, 'fields_of_study' => []],
             'experience' => ['years_minimum' => 0, 'in_specialty' => false, 'none_required' => true],
             'credentials' => [['label' => 'Permis categoria B', 'kind' => 'permis_conducere']]],
        ]]), 'evidence' => [
            $v(1, 'education', 'ELIG-R1 studii superioare economice, diplomă de licență'),
            $v(1, 'experience', 'ELIG-R1 vechime de 5 ani în specialitate'),
            $cred(1, 0, 'Autorizație CECCAR'), $cred(1, 1, 'Atestat de limba engleză'),
            $v(2, 'education', 'ELIG-R2 studii liceale cu diplomă de bacalaureat'),
            $v(2, 'experience', 'ELIG-R2 nu se cere vechime'),
            $cred(2, 0, 'Permis categoria B'),
        ]],

        ['row' => $posting(1041, 'Eligibilitate: același nivel, domenii diferite', ['positions' => [
            ['title' => 'Consilier juridic', 'count' => 1,
             'education' => ['minimum_level' => 'licenta', 'eqf_level' => 6, 'fields_of_study' => [$f('04_afaceri_administratie_drept', 'drept')]],
             'experience' => ['years_minimum' => 2, 'in_specialty' => true, 'none_required' => false]],
            ['title' => 'Inginer constructor', 'count' => 1,
             'education' => ['minimum_level' => 'licenta', 'eqf_level' => 6, 'fields_of_study' => [$f('07_inginerie_constructii', 'inginerie civilă')]],
             'experience' => ['years_minimum' => 2, 'in_specialty' => true, 'none_required' => false]],
        ]]), 'evidence' => [
            $v(1, 'education', 'ELIG-J1 licență în științe juridice'), $v(1, 'experience', 'ELIG-J1 2 ani în specialitatea juridică'),
            $v(2, 'education', 'ELIG-J2 licență în inginerie civilă'), $v(2, 'experience', 'ELIG-J2 2 ani în construcții'),
        ]],

        ['row' => $posting(1042, 'Eligibilitate: zero, nespecificat, alternative', ['positions' => [
            ['title' => 'Muncitor', 'count' => 1,
             'education' => ['minimum_level' => 'generala', 'eqf_level' => 2, 'fields_of_study' => []],
             'experience' => ['years_minimum' => null, 'in_specialty' => false, 'none_required' => true]],
            ['title' => 'Paznic', 'count' => 1,
             'education' => ['minimum_level' => 'liceala', 'eqf_level' => 4, 'fields_of_study' => []],
             'experience' => null],
            ['title' => 'Specialist', 'count' => 1,
             'education' => ['minimum_level' => 'licenta', 'eqf_level' => 6, 'fields_of_study' => []],
             'experience' => ['years_minimum' => 3, 'in_specialty' => null, 'none_required' => false]],
            ['title' => 'Analist', 'count' => 1,
             'education' => ['minimum_level' => 'master', 'eqf_level' => 7, 'fields_of_study' => []],
             'experience' => ['years_minimum' => 2, 'in_specialty' => true, 'none_required' => false]],
        ]]), 'evidence' => [
            $v(1, 'education', 'ELIG-M1 studii generale'), $v(1, 'experience', 'ELIG-M1 nu se cere experiență'),
            $nf(2, 'education', 'ELIG-M2 text neregăsit în anunț', 'liceala'),
            $v(3, 'education', 'ELIG-M3 studii superioare de lungă durată sau echivalente'), $v(3, 'experience', 'ELIG-M3 vechime de 3 ani'),
            $v(4, 'education', 'ELIG-M4 studii universitare de master'),
            $v(4, 'experience', 'ELIG-M4 2 ani în specialitate ori în domeniul conex'),
        ]],

        ['row' => $posting(1044, 'Eligibilitate: vechime totală, semnal contrazis, avantaj', ['positions' => [
            ['title' => 'Magaziner', 'count' => 1,
             'education' => ['minimum_level' => 'postliceala', 'eqf_level' => 5, 'fields_of_study' => []],
             'experience' => ['years_minimum' => 2, 'in_specialty' => false, 'none_required' => false]],
            ['title' => 'Coordonator', 'count' => 1,
             'education' => ['minimum_level' => 'licenta', 'eqf_level' => 6, 'fields_of_study' => []],
             'experience' => ['years_minimum' => 4, 'in_specialty' => false, 'none_required' => false]],
            ['title' => 'Operator', 'count' => 1,
             'education' => ['minimum_level' => 'master', 'eqf_level' => 7, 'fields_of_study' => [], 'required' => false],
             'experience' => ['years_minimum' => 1, 'in_specialty' => false, 'none_required' => false],
             'credentials' => [['label' => 'Curs de prim ajutor', 'kind' => 'certificat_profesional', 'required' => false],
                               ['label' => 'Permis categoria B sau C', 'kind' => 'permis_conducere']]],
        ]]), 'evidence' => [
            $v(1, 'education', 'ELIG-T1 studii postliceale'), $v(1, 'experience', 'ELIG-T1 2 ani vechime în muncă'),
            $v(2, 'education', 'ELIG-T2 studii superioare'), $v(2, 'experience', 'ELIG-T2 4 ani vechime în specialitatea postului'),
            $v(3, 'education', 'ELIG-T3 de preferat studii de master'), $v(3, 'experience', 'ELIG-T3 un an de vechime în muncă'),
            $cred(3, 0, 'Curs de prim ajutor'), $cred(3, 1, 'Permis categoria B sau C'),
        ]],

        ['row' => $posting(1043, 'Eligibilitate: text ostil', ['positions' => [
            ['title' => '</script><script>window.__pwned=1</script> & "Rol" \'unu\'', 'count' => 1,
             'education' => ['minimum_level' => 'licenta', 'eqf_level' => 6, 'fields_of_study' => []],
             'experience' => ['years_minimum' => 1, 'in_specialty' => false, 'none_required' => false],
             'credentials' => [['label' => '</script><img src=x onerror=window.__pwned=2> "Atestat" & \'altul\'', 'kind' => 'altele']]],
            ['title' => 'Rol normal', 'count' => 1,
             'education' => ['minimum_level' => 'liceala', 'eqf_level' => 4, 'fields_of_study' => []],
             'experience' => ['years_minimum' => 0, 'in_specialty' => false, 'none_required' => true]],
        ]]), 'evidence' => [
            $v(1, 'education', 'ELIG-H1 licență'), $v(1, 'experience', 'ELIG-H1 un an de vechime'),
            $cred(1, 0, 'atestat'),
            $v(2, 'education', 'ELIG-H2 liceu'), $v(2, 'experience', 'ELIG-H2 fără vechime'),
        ]],
    ];
}

function build_eligibility_db(string $path): void {
    build_fixture_db($path, true, true, false);   // rows are added below, then split
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $ins_r = $db->prepare('INSERT INTO posting_roles (posting_id, ' . implode(', ', FIXTURE_ROLE_COLUMNS)
        . ') VALUES (?, ' . implode(', ', array_fill(0, count(FIXTURE_ROLE_COLUMNS), '?')) . ')');
    $ins_e = $db->prepare('INSERT INTO requirement_evidence (posting_id, role_key, ordinal, fact_kind, fact_index,
        fact_label, basis, quote, status, reason, part, section, occurrences, source_revision, extraction_revision,
        text_hash, normalization) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    foreach (eligibility_fixture_postings() as $case) {
        $row = $case['row'];
        $cols = array_keys($row);
        $db->prepare('INSERT INTO job_postings (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($row));
        $full = $db->query('SELECT * FROM job_postings WHERE id = ' . (int)$row['id'])->fetch(PDO::FETCH_ASSOC);
        $keys = [];
        foreach (fixture_role_rows($full) as $role) {
            $ins_r->execute(array_merge([$row['id']], array_map(fn($c) => $role[$c], FIXTURE_ROLE_COLUMNS)));
            $keys[$role['ordinal']] = $role['role_key'];
        }
        foreach ($case['evidence'] as [$ordinal, $kind, $index, $label, $quote, $status, $reason, $part, $section, $occ]) {
            $ins_e->execute([$row['id'], $keys[$ordinal], $ordinal, $kind, $index, $label, 'extracted', $quote, $status, $reason,
                $part, $section, $occ, $row['schema_source_revision'], $row['schema_source_revision'], str_repeat('c', 64), 'fold-alnum-v1']);
        }
    }
    fixture_split_posting_text($db);
}
