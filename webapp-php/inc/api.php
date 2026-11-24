<?php
declare(strict_types=1);

/**
 * API-01: the public JSON API under /api/v1/.
 *
 * One serializer (api_list_row, api_posting) serves the list, the posting endpoint and the bulk
 * download, so the three cannot drift. The routes are strict: an unknown parameter is a 400
 * (the HTML pages and the old feeds ignore unknown parameters). Brief: docs/briefs/2026-11-24-public-api.md.
 * Loaded by index.php for /api/v1/* and by pages/api_docs.php (the parameter table).
 */

require_once __DIR__ . '/alerts.php';   // al_dir(): where posturi-private/ is

const API_VERSION   = 'v1';
const API_PAGE_SIZE = 100;
const API_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

/**
 * Parameters that build_filters() reads from a list request (the allow-list of /api/v1/posturi). Derived from the
 * three constants of query.php, so a new filter is allowed here without a second edit. `sort` and `title` are left out:
 * they change nothing in the API (the order is fixed) and would only make a new cache key. `page` is the API's own.
 *
 * @return list<string>
 */
function api_list_params(): array {
    $all = array_merge(MULTI_PARAMS, QUERY_FACETS, QUERY_SCALARS);
    $all = array_diff($all, ['sort', 'title', 'page']);
    return array_values(array_unique($all));
}

/** Whether a list parameter takes several values (`judet[]=a&judet[]=b`). */
function api_param_is_multi(string $key): bool {
    return query_is_facet($key);
}

/**
 * The Romanian description of each list parameter, for /api/. A parameter that ends in `_not` is described from its
 * base parameter (api_param_description()). tests/api_test.php fails when the allow-list has a parameter with no text.
 */
const API_PARAM_DOCS = [
    'page'           => 'Numărul paginii (1, 2, …). Fiecare pagină are 100 de rezultate. Fără parametru, API-ul dă pagina 1.',
    'q'              => 'Căutare în titlu și în textul anunțului. Un singur șir de text.',
    'status'         => 'Starea înscrierii: active (implicit), soon (se închid în 7 zile), closed, unknown sau all.',
    'employer'       => 'Doar un angajator: slug-ul din adresa paginii angajatorului.',
    'employer_id'    => 'Doar un angajator: numărul lui. Folosiți mai bine parametrul employer.',
    'judet'          => 'Județul (slug-ul județului, de exemplu cluj).',
    'loc'            => 'Localitatea, așa cum o scrie anunțul.',
    'level'          => 'Nivelul postului (execuție, conducere).',
    'type'           => 'Tipul postului, așa cum îl scrie sursa.',
    'categorie'      => 'Categoria anunțului, așa cum o scrie sursa.',
    'employer_cat'   => 'Categoria angajatorului, așa cum o scrie sursa.',
    'family'         => 'Domeniul profesional dedus din text.',
    'seniority'      => 'Funcția dedusă din text (de exemplu debutant, consilier).',
    'work_type'      => 'Norma: norma_intreaga sau norma_partiala.',
    'exp_level'      => 'Experiența cerută: fara, 1-2, 3-5, 5plus și alte intervale ale site-ului.',
    'studies_level'  => 'Nivelul de studii din text.',
    'anomaly'        => 'Anomalia detectată în anunț.',
    'isced'          => 'Domeniul de studii (ISCED-F).',
    'skill'          => 'Competența cerută. Cu skill_mode=all, anunțul trebuie să le ceară pe toate.',
    'skill_mode'     => 'Cum se combină valorile parametrului skill: any (oricare, implicit) sau all (toate).',
    'lang'           => 'Limba cerută.',
    'credential'     => 'Documentul sau atestatul cerut.',
    'domain'         => 'Domeniul de activitate.',
    'stage'          => 'Etapa concursului.',
    'eqf'            => 'Nivelul de studii în cadrul european EQF (1–8).',
    'occupation'     => 'Ocupația (titlul normalizat).',
    'funding'        => 'Sursa de finanțare.',
    'sector'         => 'Sectorul angajatorului.',
    'duration'       => 'Durata contractului: nedeterminata, determinata și altele.',
    'schedule'       => 'Programul de lucru.',
    'recruitment'    => 'Tipul concursului.',
    'regime'         => 'Tipul funcției: publică, contractuală sau militară.',
    'inst'           => 'Instituția angajatoare.',
    'cor'            => 'Grupa ocupațională (cod COR). O grupă cuprinde și subgrupele ei.',
    'medspec'        => 'Specialitatea medicală din titlu.',
    'salary_bucket'  => 'Intervalul de salariu net estimat. Valoarea fara-estimare alege anunțurile fără salariu.',
    'interes'        => 'Posturi interesante (de exemplu salariu mare cu cerințe mici).',
    'remote'         => 'Orice valoare nevidă: doar posturile care se pot ocupa la distanță.',
    'computer'       => 'Calculatorul: solicitat sau nesolicitat.',
    'schema'         => 'yes: anunțuri cu extracție structurată. no: anunțuri cu text brut.',
    'shift'          => '1: doar posturile cu lucru în ture sau gărzi.',
    'has_salary'     => '1: doar posturile cu salariu estimat sau anunțat.',
    'parttime'       => '1: doar posturile cu normă parțială.',
    'temp_vacant'    => '1: doar posturile temporar vacante.',
    'remote_apply'   => '1: doar posturile la care se poate depune dosarul fără deplasare.',
    'expires_after'  => 'Termenul de depunere este în această zi sau după ea (AAAA-LL-ZZ).',
    'expires_before' => 'Termenul de depunere este în această zi sau înainte de ea (AAAA-LL-ZZ).',
    'published'      => 'Doar anunțurile publicate în această zi (AAAA-LL-ZZ).',
];

/** The description of one list parameter (see API_PARAM_DOCS). */
function api_param_description(string $key): string {
    if (isset(API_PARAM_DOCS[$key])) return API_PARAM_DOCS[$key];
    if (str_ends_with($key, '_not') && isset(API_PARAM_DOCS[substr($key, 0, -4)])) {
        return 'Exclude anunțurile cu valorile date. Valorile sunt cele ale parametrului ' . substr($key, 0, -4) . '.';
    }
    return '';
}

/**
 * The fields of api_posting(), for /api/: [path, type, meaning in Romanian]. A path is a top-level key, or `group.key` for a
 * group of keys. tests/api_test.php compares this list with the keys of a real posting, in both directions.
 */
const API_FIELD_DOCS = [
    ['id', 'număr', 'Numărul anunțului pe acest site. Este și numărul din adresa /api/v1/posturi/{id}.'],
    ['title', 'text', 'Titlul anunțului, așa cum îl scrie sursa.'],
    ['url', 'text', 'Adresa anunțului pe posturi.gov.ro.'],
    ['employer', 'text', 'Numele angajatorului.'],
    ['judet', 'text', 'Județul.'],
    ['published_at', 'dată sau null', 'Ziua publicării (AAAA-LL-ZZ).'],
    ['expires_at', 'dată sau null', 'Ziua în care se încheie concursul. Nu este termenul de depunere.'],
    ['apply_deadline', 'dată sau null', 'Termenul de depunere, ziua după care nu se mai poate aplica.'],
    ['deadline_source', 'text sau null', 'De unde vine apply_deadline: anunț, concurs sau expirare (estimat).'],
    ['application_status', 'text sau null', 'confirmed_open, unconfirmed, closed sau unknown.'],
    ['job_level', 'text', 'Nivelul postului, așa cum îl scrie sursa.'],
    ['job_type', 'text', 'Tipul postului (permanent, temporar), așa cum îl scrie sursa.'],
    ['categorie', 'text', 'Categoria anunțului, așa cum o scrie sursa.'],
    ['employer_category', 'text', 'Categoria angajatorului, așa cum o scrie sursa.'],
    ['nr_posturi', 'număr sau null', 'Numărul de posturi scoase la concurs.'],
    ['contact_phone', 'text', 'Telefonul de contact al instituției.'],
    ['contact_email', 'text', 'E-mailul de contact al instituției.'],
    ['profession_family', 'text sau null', 'Domeniul profesional dedus din text.'],
    ['seniority', 'text sau null', 'Funcția dedusă din text.'],
    ['anomaly_flags', 'listă', 'Semnalele de anomalie ale anunțului.'],
    ['detail_url', 'text', 'Adresa API a acestui anunț.'],
    ['page_url', 'text', 'Adresa paginii acestui anunț pe site.'],
    ['locality', 'text sau null', 'Localitatea.'],
    ['judet_slug', 'text sau null', 'Slug-ul județului, pentru parametrul judet.'],
    ['employer_slug', 'text sau null', 'Slug-ul angajatorului, pentru parametrul employer.'],
    ['announcement_url', 'text sau null', 'Adresa documentului anunțului oficial.'],
    ['contact', 'obiect', 'Persoana de contact: person, phone, email. Fiecare câmp poate fi null.'],
    ['dates', 'obiect', 'Datele concursului: application_deadline_exact (cu ora), written_exam, interview, final_results.'],
    ['classification', 'obiect', 'Clasificarea automată: regime, work_mode, funding_kind, employer_kind, occupation, cor_code, isco_group, occupation_group, medical_specialty, tags.'],
    ['inferred', 'obiect', 'Valori deduse din text: grade, work_type, studies_required, experience_years, remote_eligible, requires_computer, profession_family_confidence, anomaly_score.'],
    ['salary', 'obiect', 'Salariul, în lei pe lună, brut: currency, stated (min, max, dacă anunțul îl spune), estimate (estimarea site-ului și motivul ei), range (intervalul legii în vigoare, brut și net) și project (salariul unui post din proiect). Estimările nu sunt cifre oficiale.'],
    ['v3', 'obiect', 'Cerințele extrase: eqf_level (nivelul de studii EQF), study_level, isced_fields, study_labels, skills, languages, credentials, policy_domains, exam_stages, positions, contract_duration, schedule, hours_per_week, shift_work, remote_mode, seniority_hint, bibliography.'],
    ['v4', 'obiect', 'Finanțarea și angajatorul: funding_source, funding_programme, employer_sector, parent_institution (instituția superioară), application_deadline (termenul extras).'],
    ['v5', 'obiect', 'Faptele extrase de versiunea 5: recruitment_kind, internal_only, vacancies_total, fte, post_status, end_date, channels, work_localities. Gol la anunțurile mai vechi.'],
    ['extraction', 'obiect', 'Extracția structurată: prompt_version (versiunea) și data (obiectul extras; null când anunțul nu are extracție).'],
    ['calendar', 'listă', 'Calendarul concursului: obiecte cu event, date și time.'],
    ['attachments', 'listă', 'Documentele atașate: obiecte cu url, label, ext și size.'],
    ['body', 'text', 'Textul anunțului sursă, în Markdown.'],
];

// ---- Serializer ----

/**
 * One list row: the fields of a `/posturi.json` result, with the same names and values, plus the two URLs.
 * $row is a `job_postings` row joined with `employer_name_disp` and `judet_name_disp`.
 */
function api_list_row(array $r): array {
    $inferred = json_decode($r['inferred'] ?? '{}', true) ?: [];
    $origin = site_origin();
    return [
        'id'               => $r['id'],
        'title'            => $r['title'],
        'url'              => $r['url'],
        'employer'         => $r['employer_name_disp'] ?? $r['employer_name'],
        'judet'            => $r['judet_name_disp'] ?? $r['judet_name'],
        'published_at'     => $r['published_at'] ? substr($r['published_at'], 0, 10) : null,
        'expires_at'       => $r['expires_at'] ? substr($r['expires_at'], 0, 10) : null,
        'apply_deadline'   => $r['apply_deadline'] ? substr((string)$r['apply_deadline'], 0, 10) : null,
        'deadline_source'  => $r['deadline_source'] ?: null,
        'application_status' => $r['application_status'] ?: null,
        'job_level'        => $r['job_level'],
        'job_type'         => $r['job_type'],
        'categorie'        => $r['categorie'],
        'employer_category'=> $r['employer_category'],
        'nr_posturi'       => $r['nr_posturi'],
        'contact_phone'    => $r['contact_phone'],
        'contact_email'    => $r['contact_email'],
        'profession_family'=> $inferred['profession_family'] ?? null,
        'seniority'        => $inferred['seniority'] ?? null,
        'anomaly_flags'    => $inferred['anomaly_flags'] ?? [],
        'detail_url'       => $origin . '/api/v1/posturi/' . (int)$r['id'],
        'page_url'         => $origin . job_url($r),
    ];
}

/** Decode a JSON array/object column; a bad or empty value gives $default. */
function api_json_col($v, $default = []) {
    if (is_array($v)) return $v;
    $d = json_decode((string)$v, true);
    return is_array($d) ? $d : $default;
}

/** A nullable number column: null stays null, a numeric string becomes a number. */
function api_num($v): int|float|null {
    if ($v === null || $v === '') return null;
    return is_numeric($v) ? $v + 0 : null;
}

/** A nullable text column: an empty string becomes null. */
function api_text($v): ?string {
    $s = (string)($v ?? '');
    return $s === '' ? null : $s;
}

/**
 * One posting with its structured data: everything api_list_row() has, plus the extraction, the pay, the calendar,
 * the attachments and the body. $row is the row of api_posting_sql(); $events are its `calendar_events` rows.
 *
 * Left out on purpose (internal or a duplicate): the selector and grid row IDs of `sal_json`, the `inf_*` columns
 * (they repeat the extraction and `inferred`), `schema_source_revision`, `detail_content_hash`, `detail_fetched_at`,
 * `created_at`, `updated_at`, `last_seen_at`, `quality` and `quality_json`, `source_host`, `employer_id`, `judet_id`,
 * `tip` and `detalii_raw`.
 */
function api_posting(array $r, array $events = []): array {
    $inferred = api_json_col($r['inferred'] ?? null, []);
    $schema = api_json_col($r['schema_json'] ?? null, []);
    if ($schema) $schema = schema_text_fallbacks($schema);   // BETA-V5: a v5 row's texts are rebuilt from its keys
    $sal = api_json_col($r['sal_json'] ?? null, []);

    $range = pay_range_view($r);
    $project = null;
    if (!empty($r['project_hire'])) {
        $project = [
            'amount'          => api_num($r['proj_pay_amount'] ?? null),
            'unit'            => api_text($r['proj_pay_unit'] ?? null),
            'basis'           => api_text($r['proj_pay_basis'] ?? null),
            'hours_per_month' => api_num($r['proj_hours_month'] ?? null),
            'quote'           => api_text($r['proj_pay_quote'] ?? null),
        ];
    }
    $estimate = null;
    if (api_num($r['sal_min'] ?? null) !== null) {
        $estimate = [
            'min'        => api_num($r['sal_min']),
            'max'        => api_num($r['sal_max'] ?? null),
            'confidence' => api_text($r['sal_confidence'] ?? null),
            'variant'    => api_text($r['sal_variant'] ?? null),
            'basis'      => sal_baza($r),
            'draft_min'  => api_num($r['sal_draft_min'] ?? null),
            'draft_max'  => api_num($r['sal_draft_max'] ?? null),
            'occupation' => $sal['ocupatie'] ?? null,
            'reason'     => $sal['motiv'] ?? null,
            'warnings'   => $sal['avertismente'] ?? [],
            'disclaimer' => $sal['disclaimer'] ?? null,
        ];
    }

    $attachments = [];
    foreach (attachment_list($r) as $a) {
        $attachments[] = ['url' => $a['url'], 'label' => $a['label'], 'ext' => $a['ext'], 'size' => $a['size'] ?: null];
    }

    $cal = [];
    foreach ($events as $ev) {
        $cal[] = ['event' => $ev['eveniment'], 'date' => $ev['data'], 'time' => api_text($ev['ora'] ?? null)];
    }

    $posting = api_list_row($r) + [
        'locality'         => api_text($r['locality'] ?? null),
        'judet_slug'       => api_text($r['judet_slug'] ?? null),
        'employer_slug'    => api_text($r['employer_slug'] ?? null),
        'announcement_url' => api_text($r['announcement_url'] ?? null),
        'contact' => [
            'person' => api_text($r['contact_person'] ?? null),
            'phone'  => api_text($r['contact_phone'] ?? null),
            'email'  => api_text($r['contact_email'] ?? null),
        ],
        'dates' => [
            'application_deadline_exact' => api_text($r['data_limita_depunere'] ?? null),
            'written_exam'               => api_text($r['data_proba_scrisa'] ?? null),
            'interview'                  => api_text($r['data_interviu'] ?? null),
            'final_results'              => api_text($r['data_rezultate_finale'] ?? null),
        ],
        'classification' => [
            'regime'            => api_text($r['job_regime'] ?? null),
            'work_mode'         => api_text($r['work_mode'] ?? null),
            'funding_kind'      => api_text($r['funding_kind'] ?? null),
            'employer_kind'     => api_text($r['employer_kind'] ?? null),
            'occupation'        => api_text($r['occ_canonical'] ?? null),
            'cor_code'          => api_text($r['occ_cor_code'] ?? null),
            'isco_group'        => api_text($r['occ_isco_group'] ?? null),
            'occupation_group'  => api_text($r['occ_group'] ?? null),
            'medical_specialty' => api_json_col($r['med_specialty'] ?? null, []),
            'tags'              => api_json_col($r['tags'] ?? null, []),
        ],
        'inferred' => [
            'grade'                         => $inferred['grade'] ?? null,
            'work_type'                     => $inferred['work_type'] ?? null,
            'studies_required'              => $inferred['studies_required'] ?? null,
            'experience_years'              => $inferred['experience_years'] ?? null,
            'remote_eligible'               => $inferred['remote_eligible'] ?? null,
            'requires_computer'             => $inferred['requires_computer'] ?? null,
            'profession_family_confidence'  => $inferred['profession_family_confidence'] ?? null,
            'anomaly_score'                 => $inferred['anomaly_score'] ?? null,
        ],
        'salary' => [
            'currency' => 'RON',
            'stated'   => api_num($r['inf_salary_min'] ?? null) === null ? null
                : ['min' => api_num($r['inf_salary_min']), 'max' => api_num($r['inf_salary_max'] ?? null)],
            'estimate' => $estimate,
            'range'    => $range ? [
                'gross_min' => $range['gross_min'], 'gross_max' => $range['gross_max'],
                'net_min'   => $range['net_min'],   'net_max'   => $range['net_max'],
                'grade_min' => $range['grad_min'],  'grade_max' => $range['grad_max'],
                'food_min'  => $range['food_min'],  'food_max'  => $range['food_max'],
            ] : null,
            'project'  => $project,
        ],
        'v3' => [
            'eqf_level'         => api_num($r['v3_eqf_level'] ?? null),
            'study_level'       => api_text($r['v3_study_level'] ?? null),
            'isced_fields'      => api_json_col($r['v3_isced_fields'] ?? null, []),
            'study_labels'      => api_json_col($r['v3_study_labels'] ?? null, []),
            'skills'            => api_json_col($r['v3_skills'] ?? null, []),
            'languages'         => api_json_col($r['v3_languages'] ?? null, []),
            'credentials'       => api_json_col($r['v3_credentials'] ?? null, []),
            'policy_domains'    => api_json_col($r['v3_policy_domains'] ?? null, []),
            'exam_stages'       => api_json_col($r['v3_exam_stages'] ?? null, []),
            'positions'         => api_num($r['v3_positions'] ?? null),
            'contract_duration' => api_text($r['v3_contract_duration'] ?? null),
            'schedule'          => api_text($r['v3_schedule'] ?? null),
            'hours_per_week'    => api_num($r['v3_hours_per_week'] ?? null),
            'shift_work'        => isset($r['v3_shift_work']) ? (bool)$r['v3_shift_work'] : null,
            'remote_mode'       => api_text($r['v3_remote_mode'] ?? null),
            'seniority_hint'    => api_text($r['v3_seniority_hint'] ?? null),
            'bibliography'      => api_json_col($r['v3_bibliography'] ?? null, []),
        ],
        'v4' => [
            'funding_source'       => api_text($r['v4_funding_source'] ?? null),
            'funding_programme'    => api_text($r['v4_funding_programme'] ?? null),
            'employer_sector'      => api_text($r['v4_employer_sector'] ?? null),
            'parent_institution'   => api_text($r['v4_parent_institution'] ?? null),
            'application_deadline' => api_text($r['v4_application_deadline'] ?? null),
        ],
        'v5' => [
            'recruitment_kind' => api_text($r['v5_recruitment_kind'] ?? null),
            'internal_only'    => isset($r['v5_internal_only']) ? (bool)$r['v5_internal_only'] : null,
            'vacancies_total'  => api_num($r['v5_vacancies_total'] ?? null),
            'fte'              => api_num($r['v5_fte'] ?? null),
            'post_status'      => api_text($r['v5_post_status'] ?? null),
            'end_date'         => api_text($r['v5_end_date'] ?? null),
            'channels'         => api_json_col($r['v5_channels'] ?? null, []),
            'work_localities'  => api_json_col($r['v5_work_localities'] ?? null, []),
        ],
        'extraction' => [
            'prompt_version' => api_text($r['schema_prompt_version'] ?? null),
            'data'           => $schema ?: null,
        ],
        'calendar'    => $cal,
        'attachments' => $attachments,
        'body'        => (string)($r['body_markdown'] ?? ''),
    ];
    return $posting;
}

/** The SELECT of one full posting (the detail page's columns plus the slugs); append a WHERE and an ORDER. */
function api_posting_sql(): string {
    return "SELECT j.*, t.body_markdown, t.schema_json, t.sal_json, t.attachment_meta, t.other_links, t.v3_bibliography,
                e.name AS employer_name_disp, e.slug AS employer_slug, jd.name AS judet_name_disp
            FROM job_postings j
            LEFT JOIN posting_text t ON t.posting_id = j.id
            LEFT JOIN employers e ON e.id = j.employer_id
            LEFT JOIN judete jd ON jd.id = j.judet_id";
}

/** The calendar rows of one posting, as the detail page reads them. */
function api_events(PDO $pdo, int $id): array {
    static $stmt = null;
    $stmt ??= $pdo->prepare("SELECT eveniment, data, ora FROM calendar_events WHERE posting_id = ? ORDER BY data, id");
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

// ---- Responses ----

/** Send a JSON answer and stop. An error is never cached. */
function api_respond(array $payload, int $status = 200, array $headers = []): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    if ($status !== 200) header('Cache-Control: no-store');
    foreach ($headers as $h) header($h);
    echo json_encode($payload, API_JSON_FLAGS);
    exit;
}

function api_error(int $status, string $message, array $headers = []): never {
    api_respond(['error' => $message], $status, $headers);
}

/**
 * Check a request's parameters against an allow-list. Returns the normalized parameters (validated_query()) or sends
 * the 400. `page` is read here, so it is allowed only when $with_page is true.
 *
 * @param list<string> $allowed
 */
function api_params(array $get, array $allowed, bool $with_page = false): array {
    if ($with_page) $allowed[] = 'page';
    foreach ($get as $key => $_) {
        $key = (string)$key;
        if (!in_array($key, $allowed, true)) {
            api_error(400, 'parametrul „' . mb_substr($key, 0, 60) . '” nu este acceptat de acest API');
        }
    }
    [$params, $err] = validated_query($get);
    if ($err !== null) api_error(400, $err);
    return $params;
}

/** Build the query string of an API URL: `judet[]=a`, stable order, RFC 3986. */
function api_query_string(array $params): string {
    $parts = [];
    foreach ($params as $k => $v) {
        foreach ((array)$v as $one) {
            $parts[] = rawurlencode((string)$k) . (is_array($v) ? '%5B%5D=' : '=') . rawurlencode((string)$one);
        }
    }
    return implode('&', $parts);
}

function api_data_built_at(): ?string {
    $meta = build_meta();
    return !empty($meta['built_at']) ? (string)$meta['built_at'] : null;
}

// ---- Routes ----

/** Route one /api/v1/* request. $get is the raw query array of the request. Never returns. */
function api_dispatch(string $uri, array $get): never {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        api_error(405, 'metoda nu este permisă; API-ul acceptă doar GET și HEAD', ['Allow: GET, HEAD']);
    }
    $path = rtrim(substr($uri, strlen('/api/v1')), '/');
    if ($path === '') api_index($get);
    if ($path === '/posturi') api_list($get);
    if (preg_match('#^/posturi/(\d{1,12})$#', $path, $m)) api_one((int)$m[1], $get);
    if ($path === '/descarcare') api_download($get);
    api_error(404, 'adresa nu există în API');
}

function api_index(array $get): never {
    api_params($get, []);
    $meta = build_meta();
    $origin = site_origin();
    $n = isset($meta['job_postings']) ? (int)$meta['job_postings']
        : (int)db()->query("SELECT COUNT(*) FROM job_postings")->fetchColumn();
    api_respond([
        'api_version' => API_VERSION,
        'data'        => ['built_at' => api_data_built_at(), 'postings' => $n],
        'links'       => [
            'list'     => $origin . '/api/v1/posturi',
            'posting'  => $origin . '/api/v1/posturi/{id}',
            'download' => $origin . '/api/v1/descarcare',
            'docs'     => $origin . '/api/',
        ],
    ]);
}

function api_list(array $get): never {
    $params = api_params($get, api_list_params(), true);
    $page = 1;
    if (array_key_exists('page', $params)) {
        $raw = (string)$params['page'];
        if (!ctype_digit($raw) || (int)$raw < 1) api_error(400, 'parametrul „page” trebuie să fie un număr întreg, 1 sau mai mare');
        $page = strlen($raw) > 9 ? PHP_INT_MAX : (int)$raw;
    }
    unset($params['page']);
    $_GET = $params;   // helpers that read the request (build_filters callers) see the normalized, validated parameters

    $f = build_filters($params);
    $w = $f['where'];
    $b = $f['binds'];
    $join = '';
    if ($f['fts'] && ($q = trim((string)($params['q'] ?? '')))) {
        $join = "JOIN job_postings_fts fts ON fts.rowid = j.id";
        $w[] = "job_postings_fts MATCH ?";
        $b[] = fts_query($q);
    }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

    $st = db()->prepare("SELECT COUNT(*) FROM job_postings j $join $where");
    $st->execute($b);
    $count = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($count / API_PAGE_SIZE));
    if ($page > $pages) api_error(404, "pagina $page nu există: sunt $pages pagini");

    $st = db()->prepare("SELECT j.*, e.name AS employer_name_disp, jd.name AS judet_name_disp
        FROM job_postings j $join
        LEFT JOIN employers e ON e.id = j.employer_id
        LEFT JOIN judete jd ON jd.id = j.judet_id
        $where ORDER BY j.published_at DESC, j.id DESC LIMIT " . API_PAGE_SIZE . ' OFFSET ' . (($page - 1) * API_PAGE_SIZE));
    $st->execute($b);
    $results = array_map('api_list_row', $st->fetchAll());

    $base = site_origin() . '/api/v1/posturi';
    $url = static function (int $p) use ($base, $params): string {
        $qs = api_query_string($params + ($p > 1 ? ['page' => (string)$p] : []));
        return $base . ($qs !== '' ? '?' . $qs : '');
    };
    api_respond([
        'api_version'   => API_VERSION,
        'data_built_at' => api_data_built_at(),
        'count'         => $count,
        'page'          => $page,
        'pages'         => $pages,
        'per_page'      => API_PAGE_SIZE,
        'next'          => $page < $pages ? $url($page + 1) : null,
        'prev'          => $page > 1 ? $url($page - 1) : null,
        'results'       => $results,
    ]);
}

function api_one(int $id, array $get): never {
    api_params($get, []);
    $st = db()->prepare(api_posting_sql() . ' WHERE j.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) api_error(404, 'anunțul nu este în baza de date');
    api_respond([
        'api_version'   => API_VERSION,
        'data_built_at' => api_data_built_at(),
        'posting'       => api_posting($row, api_events(db(), $id)),
    ]);
}

// ---- Bulk download ----

/** The folder of the built files: posturi-private/api/, outside the web root. */
function api_download_dir(): string {
    return al_dir() . '/api';
}

/** The stamp that keys the file: build_meta.built_at, else the modified time of the database file. */
function api_build_stamp(): string {
    $at = api_data_built_at();
    if ($at === null) {
        $path = getenv('POSTURI_DB') ?: __DIR__ . '/../posturi.sqlite';
        $at = 'mtime-' . (int)@filemtime($path);
    }
    return trim((string)preg_replace('/[^0-9A-Za-z]+/', '-', $at), '-');
}

/**
 * Build the download file when the folder has none for this build. Returns [path, built_now], or null when the folder
 * cannot be written. Concurrent requests wait on an exclusive lock, then read the finished file.
 *
 * @return array{0: string, 1: bool}|null
 */
function api_ensure_download(): ?array {
    $dir = api_download_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
    if (!is_writable($dir)) return null;
    $final = $dir . '/posturi-' . api_build_stamp() . '.json.gz';
    if (is_file($final)) return [$final, false];

    $lock = @fopen($dir . '/build.lock', 'c');
    if ($lock === false) return null;
    flock($lock, LOCK_EX);
    try {
        if (is_file($final)) return [$final, false];   // another request built it while this one waited
        @set_time_limit(300);
        $tmp = $dir . '/build-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.tmp';
        try {
            api_write_download($tmp);
            if (!rename($tmp, $final)) throw new RuntimeException('rename failed');
        } catch (Throwable $e) {
            @unlink($tmp);
            error_log('api download build failed: ' . $e->getMessage());
            return null;
        }
        foreach (glob($dir . '/posturi-*.json.gz') ?: [] as $old) {
            if ($old !== $final) @unlink($old);
        }
        return [$final, true];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Write every posting of the export as one gzip JSON document, row by row. */
function api_write_download(string $path): void {
    $pdo = db();
    $count = (int)$pdo->query("SELECT COUNT(*) FROM job_postings")->fetchColumn();
    $gz = gzopen($path, 'wb6');
    if ($gz === false) throw new RuntimeException('cannot open ' . $path);
    gzwrite($gz, '{"api_version":' . json_encode(API_VERSION) . ',"data_built_at":' . json_encode(api_data_built_at())
        . ',"count":' . $count . ',"postings":[');
    $st = $pdo->query(api_posting_sql() . ' ORDER BY j.published_at DESC, j.id DESC');
    $first = true;
    while ($row = $st->fetch()) {
        $json = json_encode(api_posting($row, api_events($pdo, (int)$row['id'])), API_JSON_FLAGS);
        if ($json === false) throw new RuntimeException('json_encode failed for posting ' . $row['id']);
        gzwrite($gz, ($first ? '' : ',') . $json);
        $first = false;
    }
    gzwrite($gz, ']}');
    gzclose($gz);
}

function api_download(array $get): never {
    api_params($get, []);
    $built = api_ensure_download();
    if ($built === null) api_error(503, 'descărcarea nu este disponibilă acum: serverul nu poate scrie fișierul');
    [$file] = $built;
    $meta_at = api_data_built_at();
    $day = $meta_at !== null ? substr($meta_at, 0, 10) : gmdate('Y-m-d', (int)@filemtime($file));

    ini_set('zlib.output_compression', '0');
    http_response_code(200);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="posturi-gov2-' . $day . '.json.gz"');
    header('Content-Length: ' . filesize($file));
    header('Access-Control-Allow-Origin: *');
    // index.php's shutdown handler cannot add the cache policy after the body is sent: set it here.
    default_cache_headers();
    // The buffer of index.php would hold the whole file in memory.
    while (ob_get_level() > 0) ob_end_clean();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
    exit;
}
