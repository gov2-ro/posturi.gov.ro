<?php
/**
 * Shared helpers for the PHP webapp.
 */

// ---- Parsedown (bundled) ----
// Inline a minimal Parsedown-compatible renderer.
// For production, drop in the full Parsedown.php from https://parsedown.org/

function markdown_to_html(string $text): string {
    if (trim($text) === '') return '';
    // Use Parsedown if available (drop Parsedown.php into this directory)
    if (class_exists('Parsedown')) {
        static $pd = null;
        if ($pd === null) { $pd = new Parsedown(); $pd->setSafeMode(false); }
        return $pd->text($text);
    }
    // Minimal fallback: convert newlines and basic markdown
    $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Paragraphs: blank lines
    $html = preg_replace('/\n{2,}/', '</p><p>', $html);
    $html = '<p>' . $html . '</p>';
    // Bold **text**
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    // Italic *text*
    $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html);
    // Line breaks within paragraph
    $html = str_replace("\n", '<br>', $html);
    return $html;
}

// Allowed HTML tags for sanitization (strip_tags allowlist)
const ALLOWED_TAGS = '<p><br><ul><ol><li><strong><b><em><i><h1><h2><h3><h4><h5><h6><table><thead><tbody><tr><th><td><blockquote><pre><code><a>';

function sanitize_html(string $html): string {
    return strip_tags($html, ALLOWED_TAGS);
}

function render_markdown(string $text): string {
    return sanitize_html(markdown_to_html($text));
}

// ---- Date helpers ----

function days_until(?string $date_str): ?int {
    if (!$date_str) return null;
    try {
        $d = new DateTime(substr($date_str, 0, 10));
        $today = new DateTime(date('Y-m-d'));
        $diff = $today->diff($d);
        return $diff->invert ? -$diff->days : $diff->days;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Romanian day count: 1 zi · 2–19 zile · 20+ de zile.
 * (The "de" is required from 20 up, and again above 100 for 120, 121, …)
 */
function days_label(int $n): string {
    if ($n === 1) return '1 zi';
    $mod = $n % 100;
    return ($mod === 0 || ($mod >= 20)) ? "$n de zile" : "$n zile";
}

/** Romanian year count: 1 an · 2–19 ani · 20+ de ani. */
function years_label(int $n): string {
    if ($n === 1) return '1 an';
    $mod = $n % 100;
    return ($mod === 0 || $mod >= 20) ? "$n de ani" : "$n ani";
}

function fmt_date(?string $iso, string $fmt = 'd.m.Y'): string {
    if (!$iso) return '';
    try {
        return (new DateTime(substr($iso, 0, 10)))->format($fmt);
    } catch (Exception $e) {
        return $iso;
    }
}

function fmt_datetime(?string $iso, string $fmt = 'd.m.Y H:i'): string {
    if (!$iso) return '';
    try {
        return (new DateTime($iso))->format($fmt);
    } catch (Exception $e) {
        return $iso;
    }
}

// ---- Salary / fee renderers ----

function render_base_salary($salary): string {
    if (!is_array($salary)) return '';
    $mn = $salary['minValue'] ?? null;
    $mx = $salary['maxValue'] ?? null;
    $currency = $salary['currency'] ?? 'RON';
    $unit = strtolower($salary['unitText'] ?? 'month');
    $unit_ro = ['hour' => '/oră', 'day' => '/zi', 'week' => '/săptămână', 'month' => '/lună', 'year' => '/an'][$unit] ?? '';
    if ($mn === null && $mx === null) return '';
    if ($mn !== null && $mx !== null && $mn != $mx) return "{$mn}–{$mx} {$currency}{$unit_ro}";
    return (($mn ?? $mx)) . " {$currency}{$unit_ro}";
}

function render_application_fee($fee): string {
    if (!is_array($fee)) return '';
    $parts = [];
    if (isset($fee['amount'])) $parts[] = $fee['amount'] . ' ' . ($fee['currency'] ?? 'RON');
    if (!empty($fee['account'])) $parts[] = 'Cont: ' . $fee['account'];
    if (!empty($fee['details'])) $parts[] = $fee['details'];
    return implode('. ', $parts);
}

function render_application_contact($contact): string {
    if (!is_array($contact)) return '';
    $rows = [];
    if (!empty($contact['name']))    $rows[] = '- ' . $contact['name'];
    if (!empty($contact['phone']))   $rows[] = '- Telefon: ' . $contact['phone'];
    if (!empty($contact['email']))   $rows[] = '- Email: ' . $contact['email'];
    if (!empty($contact['address'])) $rows[] = '- Adresă: ' . $contact['address'];
    return implode("\n", $rows);
}

// ---- Schema section rendering ----

const SCHEMA_SECTION_LABELS = [
    'responsibilities'       => 'Atribuții principale',
    'educationRequirements'  => 'Studii',
    'experienceRequirements' => 'Experiență',
    'qualifications'         => 'Condiții specifice',
    'skills'                 => 'Competențe',
    'application_docs'       => 'Dosar de candidatură',
    'baseSalary'             => 'Salarizare',
    'salary'                 => 'Salarizare',
    'application_fee'        => 'Taxă de participare',
    'application_contact'    => 'Contact pentru depunere',
    'jobBenefits'            => 'Beneficii',
    'workHours'              => 'Program de lucru',
    'jobLocation'            => 'Locație',
    'work_conditions'        => 'Condiții de muncă',
];

function render_schema_sections(?string $schema_json_str): ?array {
    if (!$schema_json_str) return null;
    $schema = json_decode($schema_json_str, true);
    if (!is_array($schema)) return null;

    $sections = [];
    foreach (SCHEMA_SECTION_LABELS as $key => $label) {
        if (!array_key_exists($key, $schema)) continue;
        $value = $schema[$key];
        if ($value === null) continue;

        if (is_array($value)) {
            $rendered = match($key) {
                'baseSalary'          => render_base_salary($value),
                'application_fee'     => render_application_fee($value),
                'application_contact' => render_application_contact($value),
                default               => implode(', ', array_filter($value)),
            };
        } else {
            $rendered = (string)$value;
        }

        if (trim($rendered) === '') continue;
        // `key` is the Schema.org JobPosting property (or one of the three
        // RO-specific custom keys) the value came from — surfaced in the UI so
        // the structure is self-documenting.
        $sections[] = ['key' => $key, 'label' => $label, 'html' => render_markdown($rendered)];
    }
    return $sections ?: null;
}

// ---- Label lookups ----

const WORK_TYPE_LABELS = [
    'norma_intreaga' => 'Normă întreagă',
    'norma_partiala' => 'Normă parțială',
    'schimburi'      => 'Schimburi',
];

function work_type_label(string $v): string {
    return WORK_TYPE_LABELS[$v] ?? $v;
}

const ANOMALY_LABELS = [
    'short_deadline'         => 'Termen scurt',
    'missing_contact'        => 'Contact lipsă',
    'contact_in_attachment'  => 'Contact în atașament',
    'gender_criteria'        => 'Criteriu de gen',
    'no_body'                => 'Fără corp',
    'frequent_repost'        => 'Re-publicare frecventă',
];

/** Seniority buckets from `_infer_seniority` — the raw values are snake_case. */
const SENIORITY_LABELS = [
    'conducere_superioara' => 'Conducere superioară',
    'director'             => 'Director / șef',
    'expert'               => 'Expert',
    'consilier'            => 'Consilier',
    'inspector'            => 'Inspector',
    'referent'             => 'Referent',
    'asistent'             => 'Asistent',
    'debutant'             => 'Debutant',
];

function seniority_label(string $v): string {
    return SENIORITY_LABELS[$v] ?? ucfirst(str_replace('_', ' ', $v));
}

/** Professional grade from the title ("gradul II", "principal", "superior"). */
function grade_label(string $v): string {
    return match (strtolower($v)) {
        'debutant'  => 'Debutant',
        'principal' => 'Grad principal',
        'superior'  => 'Grad superior',
        default     => 'Gradul ' . strtoupper($v),
    };
}

const STUDIES_LABELS = [
    'doctorat'    => 'Doctorat',
    'master'      => 'Master / Magistru',
    'licenta'     => 'Licență',
    'postliceala' => 'Postliceală',
    'liceala'     => 'Liceală',
    'generala'    => 'Generală',
];

// ---- Salary/experience buckets ----

const SALARY_BUCKETS = [
    ['key' => 'sub-3000',   'label' => 'sub 3000',    'min' => 0,    'max' => 3000],
    ['key' => '3000-4000',  'label' => '3000 – 4000', 'min' => 3000, 'max' => 4000],
    ['key' => '4000-5000',  'label' => '4000 – 5000', 'min' => 4000, 'max' => 5000],
    ['key' => '5000-7000',  'label' => '5000 – 7000', 'min' => 5000, 'max' => 7000],
    ['key' => 'peste-7000', 'label' => 'peste 7000',  'min' => 7000, 'max' => null],
];

const EXP_BUCKETS = [
    ['key' => 'fara',  'label' => 'Fără experiență', 'min' => 0, 'max' => 1],
    ['key' => '1-2',   'label' => '1–2 ani',          'min' => 1, 'max' => 3],
    ['key' => '3-5',   'label' => '3–5 ani',          'min' => 3, 'max' => 6],
    ['key' => '5plus', 'label' => '5+ ani',            'min' => 5, 'max' => null],
];

function bucket_label(array $buckets, string $key): string {
    foreach ($buckets as $b) {
        if ($b['key'] === $key) return $b['label'];
    }
    return $key;
}

// ---- Status (replaces the raw date pickers as the primary deadline control) ----

const DEFAULT_STATUS = 'active';

const STATUS_LABELS = [
    'active' => 'Active',
    'soon'   => 'Expiră în 7 zile',
    'all'    => 'Toate',
];

/** slug => name, for turning `judet=cluj` back into "Cluj" on a chip. */
function judet_names(): array {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (db()->query("SELECT slug, name FROM judete")->fetchAll() as $r) {
            $map[$r['slug']] = $r['name'];
        }
    }
    return $map;
}

// ---- Attachments ----

/**
 * Labels for the attachment kinds worth calling out. Deliberately short: the
 * source ships one file per posting and ~89% of them are the announcement
 * itself, whose bibliografie / fișă / cerere live inside it as sections rather
 * than as separate documents. See webapp/apps/jobs/attachments.py.
 */
const ATTACHMENT_KIND_LABELS = [
    'erata'     => 'Erată / modificare',
    'rezultate' => 'Rezultate',
];

const ATTACHMENT_ROLE_LABELS = [
    'announcement' => 'Anunț oficial',
    'other'        => 'Document anexat',
];

/** Byte count as a download link should show it: 12 KB, 1.4 MB. */
function human_size($bytes): string {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '';
    if ($bytes < 1024) return $bytes . ' B';
    $kb = $bytes / 1024;
    return $kb < 1024 ? round($kb) . ' KB' : round($kb / 1024, 1) . ' MB';
}

/**
 * Describe a posting's attachments for display.
 *
 * The filename is deliberately not shown — every one is opaque
 * (`j_11414_c_9189_anunt_688423.docx`, or an 8-char hex hash), so it labels
 * nothing. What a reader can use is the role, the format and the size, plus a
 * kind when the document declares itself something other than the announcement.
 */
function attachment_list(array $p): array {
    $meta = $p['attachment_meta'] ?? null;
    $meta = is_array($meta) ? $meta : (json_decode((string)$meta ?: '[]', true) ?: []);

    if (!$meta) {
        // Pre-metadata rows, and the feeds, still need something sensible.
        $meta = [];
        if (!empty($p['announcement_url'])) {
            $meta[] = ['url' => $p['announcement_url'], 'role' => 'announcement'];
        }
        foreach ((array)(json_decode($p['other_links'] ?? '[]', true) ?: []) as $link) {
            $meta[] = ['url' => $link, 'role' => 'other'];
        }
    }

    $out = [];
    foreach ($meta as $a) {
        $url = (string)($a['url'] ?? '');
        if ($url === '') continue;
        $kind = (string)($a['kind'] ?? '');
        $role = (string)($a['role'] ?? 'other');
        $ext  = strtoupper((string)($a['ext'] ?? ''));
        if ($ext === '') {
            $ext = strtoupper(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        }
        $out[] = [
            'url'       => $url,
            'label'     => ATTACHMENT_KIND_LABELS[$kind] ?? (ATTACHMENT_ROLE_LABELS[$role] ?? 'Document'),
            'ext'       => $ext !== '' ? $ext : 'FIȘIER',
            'size'      => human_size($a['bytes'] ?? 0),
            'important' => isset(ATTACHMENT_KIND_LABELS[$kind]),
        ];
    }
    return $out;
}

// ---- Inferred metadata ----

/** Decode a posting's `inferred` JSON blob once, tolerating an already-decoded array. */
function inferred_of(array $p): array {
    $raw = $p['inferred'] ?? null;
    if (is_array($raw)) return $raw;
    return json_decode((string)$raw ?: '{}', true) ?: [];
}

/**
 * Minimum confidence before a dictionary-guessed profession family is shown.
 * Below this the guess is worse than saying nothing.
 */
const FAMILY_MIN_CONFIDENCE = 0.5;

/**
 * The derived attributes of a posting, in the order a job seeker scans them:
 * what field, what grade, what qualifications, what schedule.
 *
 * Kept apart from the source-of-truth badges (nivel / tip / categorie), because
 * these are inferred and the UI says so — see `/despre/` for the methodology.
 */
function inferred_meta(array $p): array {
    $inf  = inferred_of($p);
    $meta = [];

    $family = (string)($inf['profession_family'] ?? '');
    $conf   = (float)($inf['profession_family_confidence'] ?? 0);
    // 'altele' is the dictionary's catch-all — a label that says nothing.
    if ($family !== '' && $family !== 'altele' && $conf >= FAMILY_MIN_CONFIDENCE) {
        $meta[] = ucfirst($family);
    }
    if ($seniority = (string)($inf['seniority'] ?? '')) {
        $meta[] = seniority_label($seniority);
    }
    // The grade is a different axis from seniority ("Consilier, grad principal"),
    // so show it too — unless the regex landed on the same word for both.
    $grade = (string)($inf['grade'] ?? '');
    if ($grade !== '' && strcasecmp($grade, $seniority) !== 0) {
        $meta[] = grade_label($grade);
    }
    if ($studies = (string)($inf['studies_required'] ?? '')) {
        $meta[] = STUDIES_LABELS[$studies] ?? ucfirst($studies);
    }
    if ($years = $inf['experience_years'] ?? null) {
        $meta[] = 'Min. ' . years_label((int)$years);
    } elseif (!empty($inf['no_experience_required'])) {
        // The posting says so explicitly ("nu este cazul"), which is a
        // stronger signal than simply not mentioning experience.
        $meta[] = 'Fără experiență';
    }
    if ($work_type = (string)($inf['work_type'] ?? '')) {
        $meta[] = work_type_label($work_type);
    }
    if (!empty($inf['remote_eligible'])) {
        $meta[] = 'Telemuncă';
    }
    if (!empty($inf['requires_computer'])) {
        $meta[] = ($inf['computer_level'] ?? '') === 'advanced' ? 'Calculator (avansat)' : 'Calculator';
    }
    return $meta;
}

/** Free-form inferred tag lists, for the detail page. */
function inferred_tags(array $p): array {
    $inf = inferred_of($p);
    $out = [];
    foreach ([
        'skills'         => 'Competențe',
        'languages'      => 'Limbi străine',
        'certifications' => 'Certificări',
    ] as $key => $label) {
        $values = array_values(array_filter((array)($inf[$key] ?? []), fn($v) => trim((string)$v) !== ''));
        if ($values) $out[] = ['label' => $label, 'values' => $values];
    }
    return $out;
}

// ---- Prompt-v3 structured extraction ----

/**
 * Labels for the v3 controlled vocabularies. These stay empty in the UI until
 * `llm-schema.py --prompt-version v3` has run — `facet_group()` skips a group
 * with no options, so the filters appear by themselves once data exists.
 * See docs/metadata-schema-v3.md.
 */
const ISCED_FIELD_LABELS = [
    '00_generale'                            => 'Programe generale',
    '01_educatie'                            => 'Educație',
    '02_arte_umanioare'                      => 'Arte și științe umaniste',
    '03_stiinte_sociale'                     => 'Științe sociale și jurnalism',
    '04_afaceri_administratie_drept'         => 'Afaceri, administrație și drept',
    '05_stiinte_naturale_matematica'         => 'Științe naturale și matematică',
    '06_tic'                                 => 'Tehnologia informației (TIC)',
    '07_inginerie_constructii'               => 'Inginerie și construcții',
    '08_agricultura_silvicultura_veterinara' => 'Agricultură, silvicultură, veterinară',
    '09_sanatate_asistenta_sociala'          => 'Sănătate și asistență socială',
    '10_servicii'                            => 'Servicii',
];

const POLICY_DOMAIN_LABELS = [
    'achizitii_publice' => 'Achiziții publice',       'administratie_publica' => 'Administrație publică',
    'agricultura_alimentatie' => 'Agricultură',        'aparare_securitate' => 'Apărare și securitate',
    'asistenta_sociala' => 'Asistență socială',        'constructii_urbanism' => 'Construcții și urbanism',
    'cultura_patrimoniu' => 'Cultură și patrimoniu',   'demografie_migratie' => 'Demografie și migrație',
    'drept_justitie' => 'Drept și justiție',           'economie_finante' => 'Economie și finanțe',
    'educatie_cercetare' => 'Educație și cercetare',   'energie' => 'Energie',
    'mediu' => 'Mediu',                                'fonduri_europene' => 'Fonduri europene',
    'relatii_munca' => 'Relații de muncă',             'resurse_umane' => 'Resurse umane',
    'sanatate_publica' => 'Sănătate publică',          'securitate_sanatate_munca' => 'Securitatea muncii',
    'sport_tineret' => 'Sport și tineret',             'tehnologia_informatiei' => 'Tehnologia informației',
    'comunicare_media' => 'Comunicare și media',       'transporturi' => 'Transporturi',
    'turism' => 'Turism',                              'altele' => 'Altele',
];

const CREDENTIAL_KIND_LABELS = [
    'permis_conducere'             => 'Permis de conducere',
    'certificat_profesional'       => 'Certificat profesional',
    'autorizatie'                  => 'Autorizație / aviz de practică',
    'aviz_medical'                 => 'Aviz medical / psihologic',
    'acces_informatii_clasificate' => 'Acces informații clasificate',
    'altele'                       => 'Alte documente',
];

const EXAM_STAGE_LABELS = [
    'selectie_dosare' => 'Selecție dosare', 'proba_scrisa'   => 'Probă scrisă',
    'proba_practica'  => 'Probă practică',  'proba_sportiva' => 'Probă sportivă',
    'interviu'        => 'Interviu',        'proba_orala'    => 'Probă orală',
    'test_psihologic' => 'Test psihologic',
];

const EQF_LABELS = [
    2 => 'Studii generale',   3 => 'Școală profesională', 4 => 'Liceu / bacalaureat',
    5 => 'Postliceală',       6 => 'Licență',             7 => 'Master', 8 => 'Doctorat',
];

function isced_label(string $v): string      { return ISCED_FIELD_LABELS[$v] ?? $v; }
function policy_domain_label(string $v): string { return POLICY_DOMAIN_LABELS[$v] ?? ucfirst(str_replace('_', ' ', $v)); }
function credential_kind_label(string $v): string { return CREDENTIAL_KIND_LABELS[$v] ?? $v; }
function exam_stage_label(string $v): string { return EXAM_STAGE_LABELS[$v] ?? $v; }

/** Decode one of the `v3_*` JSON-array columns. */
function v3_list(array $p, string $column): array {
    $raw = $p[$column] ?? '[]';
    if (is_array($raw)) return $raw;
    return array_values(array_filter((array)(json_decode((string)$raw ?: '[]', true) ?: [])));
}

/** True when this posting carries a v3 extraction at all. */
function has_v3(array $p): bool {
    return !empty($p['v3_eqf_level']) || v3_list($p, 'v3_skills') || v3_list($p, 'v3_isced_fields');
}

/**
 * "en:B2" → ['engleză', 'B2']. The export packs the pair into one token so a
 * single LIKE probe can filter on language and level together.
 */
const LANGUAGE_NAMES = [
    'en' => 'Engleză', 'fr' => 'Franceză', 'de' => 'Germană', 'it' => 'Italiană',
    'es' => 'Spaniolă', 'ru' => 'Rusă', 'hu' => 'Maghiară',
];

function language_token_label(string $token): string {
    [$code, $cefr] = array_pad(explode(':', $token, 2), 2, '');
    $name = LANGUAGE_NAMES[strtolower($code)] ?? ucfirst($code);
    return $cefr !== '' ? "$name ($cefr)" : $name;
}

// ---- Query string helpers ----

/**
 * Return the current query string with the given key changed/removed.
 * Pass null to remove the key. Pass an array to set multiple values.
 */
function qs_with(string $key, $value): string {
    $params = $_GET;
    unset($params['page']); // reset page on filter change by default
    if ($value === null) {
        unset($params[$key]);
    } else {
        $params[$key] = $value;
    }
    $q = http_build_query($params);
    return $q ? '?' . $q : '';
}

/**
 * Return the current query string with a single value dropped from an array
 * param — `judet=cluj&judet=iasi` minus `cluj` leaves `judet=iasi`, where
 * qs_with($key, null) would have dropped both. Pass $value = null to drop the
 * whole key (scalar params, or "clear this facet entirely").
 */
function qs_without(string $key, ?string $value = null): string {
    $params = $_GET;
    unset($params['page']);
    $current = $params[$key] ?? null;

    if ($value === null || !is_array($current)) {
        unset($params[$key]);
    } else {
        $rest = array_values(array_filter($current, fn($v) => (string)$v !== $value));
        if ($rest) { $params[$key] = $rest; } else { unset($params[$key]); }
    }
    $q = http_build_query($params);
    return $q ? '?' . $q : '';
}

function qs_page(int $page): string {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

function current_qs(): string {
    $q = http_build_query($_GET);
    return $q ? '?' . $q : '';
}

function feed_url(string $filename): string {
    $qs = http_build_query($_GET);
    return '/' . $filename . ($qs ? '?' . $qs : '');
}

// ---- Active-filter chips ----

/**
 * Every filter param, in sidebar order, with the group name shown on its chip.
 * An empty group renders the value alone (the value already reads as a label).
 */
const FILTER_CHIP_GROUPS = [
    'q'              => 'Caută',
    'status'         => 'Stare',
    'family'         => 'Domeniu',
    'judet'          => 'Județ',
    'level'          => '',
    'type'           => 'Tip',
    'categorie'      => '',
    'seniority'      => 'Grad',
    'work_type'      => 'Normă',
    'exp_level'      => 'Experiență',
    'studies_level'  => 'Studii',
    'remote'         => '',
    'computer'       => 'Calculator',
    'salary_bucket'  => 'Salariu',
    'employer_cat'   => 'Angajator',
    'schema'         => 'Descriere',
    'isced'          => 'Domeniu studii',
    'eqf'            => 'Nivel studii',
    'skill'          => 'Competență',
    'lang'           => 'Limbă',
    'credential'     => 'Document',
    'domain'         => 'Domeniu activitate',
    'stage'          => 'Etapă',
    'anomaly'        => 'Anomalie',
    'expires_after'  => 'Termen de la',
    'expires_before' => 'Termen până la',
];

/**
 * Filters that accept several values at once. Their form fields must be named
 * `judet[]`, not `judet` — a browser serialises repeated checkboxes as
 * `judet=cluj&judet=iasi`, which PHP collapses to the *last* value, so every
 * multi-select silently narrowed to one option after an HTMX round-trip.
 */
const MULTI_PARAMS = [
    'judet', 'level', 'type', 'categorie', 'employer_cat',
    'family', 'seniority', 'work_type', 'exp_level', 'studies_level', 'anomaly',
    'isced', 'skill', 'lang', 'credential', 'domain', 'stage',
];

/** Form field name for a filter param. */
function param_field(string $name): string {
    return in_array($name, MULTI_PARAMS, true) ? $name . '[]' : $name;
}

function filter_value_label(string $key, string $value): string {
    return match ($key) {
        'judet'                            => judet_names()[$value] ?? $value,
        'studies_level'                    => STUDIES_LABELS[$value] ?? $value,
        'anomaly'                          => ANOMALY_LABELS[$value] ?? $value,
        'work_type'                        => WORK_TYPE_LABELS[$value] ?? $value,
        'exp_level'                        => bucket_label(EXP_BUCKETS, $value),
        'salary_bucket'                    => bucket_label(SALARY_BUCKETS, $value),
        'status'                           => STATUS_LABELS[$value] ?? $value,
        'remote'                           => 'Telemuncă',
        'schema'                           => $value === 'yes' ? 'Structurată' : 'Text brut',
        'isced'                            => isced_label($value),
        'eqf'                              => EQF_LABELS[(int)$value] ?? $value,
        'lang'                             => language_token_label($value),
        'credential'                       => credential_kind_label($value),
        'domain'                           => policy_domain_label($value),
        'stage'                            => exam_stage_label($value),
        'computer'                         => $value === 'solicitat' ? 'Calculator solicitat' : 'Fără calculator',
        'expires_after', 'expires_before'  => fmt_date($value),
        'family', 'seniority'              => ucfirst(str_replace('_', ' ', $value)),
        default                            => $value,
    };
}

/**
 * One removable chip per active filter *value*, so a three-județ selection
 * yields three chips that each drop only themselves.
 *
 * `href` is the no-JS fallback; `param`/`value` let the click handler in
 * list.php uncheck the matching sidebar input and re-fire the HTMX form
 * instead, which keeps the form and the URL in sync.
 */
function active_filter_chips(): array {
    $chips = [];
    foreach (FILTER_CHIP_GROUPS as $key => $group) {
        $raw = $_GET[$key] ?? null;
        if ($raw === null || $raw === '' || $raw === []) continue;
        if ($key === 'status' && $raw === DEFAULT_STATUS) continue; // the default reads as "no filter"

        foreach ((array)$raw as $value) {
            $value = trim((string)$value);
            if ($value === '') continue;
            $chips[] = [
                'param' => $key,
                'value' => $value,
                'group' => $group,
                'label' => filter_value_label($key, $value),
                'href'  => qs_without($key, is_array($raw) ? $value : null),
            ];
        }
    }
    return $chips;
}

// ---- URLs ----

/** Romanian-aware slugifier: ă/â/î/ș/ț fold to their ASCII bases, not to "?". */
function ro_slug(string $s, int $max = 60): string {
    $s = strtr($s, [
        'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't',
    ]);
    $s = mb_strtolower($s, 'UTF-8');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false) $s = $t;
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    if (strlen($s) > $max) {
        $s = substr($s, 0, $max);
        $s = preg_replace('/-[^-]*$/', '', $s) ?: $s;   // never cut mid-word
    }
    return $s;
}

/**
 * Canonical detail URL: `/job/1234-inspector-primaria-cluj/`.
 * The id stays in front so lookup is still a primary-key hit and the slug can
 * change with the title without breaking the link.
 */
function job_url(array $p): string {
    $slug = ro_slug((string)($p['title'] ?? ''));
    return '/job/' . (int)$p['id'] . ($slug !== '' ? '-' . $slug : '') . '/';
}

/**
 * Human-readable place for a posting row: "Cluj-Napoca, Cluj", or just "Cluj"
 * when the source gave no locality.
 *
 * The county alone is what the facet filters on — `locality` carries the city
 * precision that used to be welded into `judet_name` as "CLUJ-NAPOCA, Cluj".
 */
function place_label(array $p): string {
    $judet    = trim((string)($p['judet_name'] ?? ''));
    $locality = trim((string)($p['locality'] ?? ''));
    if ($locality === '') return $judet;
    if ($judet === '' || mb_strtolower($locality) === mb_strtolower($judet)) return $locality;
    return $locality . ', ' . $judet;
}

function site_origin(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') $scheme = 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'posturi.gov2.ro';
    return $scheme . '://' . $host;
}

// ---- HTML helpers ----

function e(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function checked_if(bool $cond): string {
    return $cond ? ' checked' : '';
}

function selected_if(bool $cond): string {
    return $cond ? ' selected' : '';
}

// ---- Filter application ----

/**
 * Build WHERE clause fragments and bindings from GET params.
 * Returns ['where' => string[], 'binds' => array, 'fts' => bool]
 */
function build_filters(array $p, bool $exclude_key = false, string $excl = ''): array {
    $where = [];
    $binds = [];
    $fts = false;

    $q         = trim($p['q'] ?? '');
    $judets    = (array)($p['judet']        ?? []);
    $levels    = (array)($p['level']        ?? []);
    $types     = (array)($p['type']         ?? []);
    $cats      = (array)($p['categorie']    ?? []);
    $emp_cats  = (array)($p['employer_cat'] ?? []);
    $families  = (array)($p['family']       ?? []);
    $seniorities = (array)($p['seniority']  ?? []);
    $work_types = (array)($p['work_type']   ?? []);
    $exp_levels = (array)($p['exp_level']   ?? []);
    $studies    = (array)($p['studies_level'] ?? []);
    $anomalies  = (array)($p['anomaly']     ?? []);
    $remote     = $p['remote']       ?? '';
    $computer   = $p['computer']     ?? '';
    $sal_bucket = $p['salary_bucket'] ?? '';
    $exp_before = $p['expires_before'] ?? '';
    $exp_after  = $p['expires_after']  ?? '';
    $status     = $p['status'] ?? DEFAULT_STATUS;

    // Only claim FTS when the query actually yields terms — a query of pure
    // punctuation would otherwise reach SQLite as an empty MATCH expression.
    if ($q && $excl !== 'q' && fts_query($q) !== '') {
        $fts = true;
        // FTS join handled in caller; rank sort also set there
    }

    if ($excl !== 'status') {
        $today = date('Y-m-d');
        if ($status === 'active') {
            $where[] = "(j.expires_at IS NULL OR j.expires_at >= ?)";
            $binds[] = $today;
        } elseif ($status === 'soon') {
            $where[] = "(j.expires_at >= ? AND j.expires_at <= ?)";
            $binds[] = $today;
            $binds[] = date('Y-m-d', strtotime('+7 days'));
        }
        // 'all' adds no clause
    }
    if ($judets && $excl !== 'judet_slugs') {
        $ph = implode(',', array_fill(0, count($judets), '?'));
        $where[] = "j.judet_slug IN ($ph)";
        array_push($binds, ...$judets);
    }
    if ($levels && $excl !== 'levels') {
        $ph = implode(',', array_fill(0, count($levels), '?'));
        $where[] = "j.job_level IN ($ph)";
        array_push($binds, ...$levels);
    }
    if ($types && $excl !== 'types') {
        $ph = implode(',', array_fill(0, count($types), '?'));
        $where[] = "j.job_type IN ($ph)";
        array_push($binds, ...$types);
    }
    if ($cats && $excl !== 'categories') {
        $ph = implode(',', array_fill(0, count($cats), '?'));
        $where[] = "j.categorie IN ($ph)";
        array_push($binds, ...$cats);
    }
    if ($emp_cats && $excl !== 'employer_cats') {
        $ph = implode(',', array_fill(0, count($emp_cats), '?'));
        $where[] = "j.employer_category IN ($ph)";
        array_push($binds, ...$emp_cats);
    }
    if ($families && $excl !== 'families') {
        $ph = implode(',', array_fill(0, count($families), '?'));
        $where[] = "j.inf_profession_family IN ($ph)";
        array_push($binds, ...$families);
    }
    if ($seniorities && $excl !== 'seniorities') {
        $ph = implode(',', array_fill(0, count($seniorities), '?'));
        $where[] = "j.inf_seniority IN ($ph)";
        array_push($binds, ...$seniorities);
    }
    if ($work_types && $excl !== 'work_types') {
        $ph = implode(',', array_fill(0, count($work_types), '?'));
        $where[] = "j.inf_work_type IN ($ph)";
        array_push($binds, ...$work_types);
    }
    if ($exp_before) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp_before)) {
            $where[] = "j.expires_at <= ?";
            $binds[] = $exp_before;
        }
    }
    if ($exp_after) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp_after)) {
            $where[] = "j.expires_at >= ?";
            $binds[] = $exp_after;
        }
    }
    if ($remote && $excl !== 'remote') {
        $where[] = "j.inf_remote_eligible = 1";
    }
    if ($computer && $excl !== 'computer') {
        if ($computer === 'solicitat') {
            $where[] = "j.inf_requires_computer = 1";
        } elseif ($computer === 'nesolicitat') {
            $where[] = "(j.inf_requires_computer IS NULL OR j.inf_requires_computer != 1)";
        }
    }
    if ($anomalies && $excl !== 'anomaly_flags') {
        foreach ($anomalies as $flag) {
            $flag = preg_replace('/[^a-z_]/', '', $flag); // sanitize
            $where[] = "j.inf_anomaly_flags LIKE ?";
            $binds[] = '%"' . $flag . '"%';
        }
    }
    if ($exp_levels && $excl !== 'exp_levels') {
        $exp_clauses = [];
        foreach ($exp_levels as $bk) {
            foreach (EXP_BUCKETS as $bucket) {
                if ($bucket['key'] !== $bk) continue;
                if ($bucket['min'] == 0 && $bucket['max'] !== null) {
                    $exp_clauses[] = "(j.inf_experience_years IS NULL OR j.inf_experience_years < ?)";
                    $binds[] = $bucket['max'];
                } elseif ($bucket['max'] === null) {
                    $exp_clauses[] = "j.inf_experience_years >= ?";
                    $binds[] = $bucket['min'];
                } else {
                    $exp_clauses[] = "(j.inf_experience_years >= ? AND j.inf_experience_years < ?)";
                    $binds[] = $bucket['min'];
                    $binds[] = $bucket['max'];
                }
            }
        }
        if ($exp_clauses) $where[] = '(' . implode(' OR ', $exp_clauses) . ')';
    }
    if ($studies && $excl !== 'studies_levels') {
        $ph = implode(',', array_fill(0, count($studies), '?'));
        $where[] = "j.inf_studies_required IN ($ph)";
        array_push($binds, ...$studies);
    }
    // ---- prompt-v3 structured filters ----
    // Each JSON-array column is probed with LIKE '%"value"%', the same shape
    // inf_anomaly_flags uses. All no-ops until a v3 extraction has run.
    foreach ([
        'isced'      => 'v3_isced_fields',
        'skill'      => 'v3_skills',
        'lang'       => 'v3_languages',
        'credential' => 'v3_credentials',
        'domain'     => 'v3_policy_domains',
        'stage'      => 'v3_exam_stages',
    ] as $param => $column) {
        $values = (array)($p[$param] ?? []);
        if (!$values || $excl === $param) continue;
        foreach ($values as $value) {
            // ESCAPE is required: these vocabularies are full of underscores
            // ("09_sanatate_asistenta_sociala"), and `_` is a LIKE wildcard.
            // Escaping without declaring the escape character matched nothing.
            $where[] = "j.$column LIKE ? ESCAPE '\\'";
            $binds[] = '%"' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$value) . '"%';
        }
    }
    $eqf = $p['eqf'] ?? '';
    if ($eqf !== '' && $excl !== 'eqf') {
        // A candidate qualified above the minimum still qualifies.
        $where[] = "j.v3_eqf_level IS NOT NULL AND j.v3_eqf_level <= ?";
        $binds[] = (int)$eqf;
    }

    // Whether the posting has LLM-extracted Schema.org sections, or only the
    // raw scraped body. Ported from the Django "Dev" panel (commit c9be4ce);
    // it is useful to readers too, not just for measuring backfill coverage.
    $schema = $p['schema'] ?? '';
    if ($schema && $excl !== 'schema') {
        $where[] = $schema === 'yes' ? "j.schema_json IS NOT NULL" : "j.schema_json IS NULL";
    }
    if ($sal_bucket && $excl !== 'salary_bucket') {
        foreach (SALARY_BUCKETS as $b) {
            if ($b['key'] !== $sal_bucket) continue;
            $where[] = "j.inf_salary_min IS NOT NULL AND j.inf_salary_min >= ?";
            $binds[] = $b['min'];
            if ($b['max'] !== null) {
                $where[] = "j.inf_salary_min < ?";
                $binds[] = $b['max'];
            }
        }
    }

    return ['where' => $where, 'binds' => $binds, 'fts' => $fts];
}

/**
 * Run a facet GROUP BY count query, returning [{value, label, count}].
 * $col is the column in job_postings (aliased as j).
 * $base_where and $binds come from build_filters() excluding this facet's key.
 */
function facet_counts(
    string $col, array $base_where, array $binds,
    bool $fts, string $q_term, int $limit = 0
): array {
    $join = '';
    $w = $base_where;
    $b = $binds;
    if ($fts && ($match = fts_query($q_term)) !== '') {
        $join = "JOIN job_postings_fts fts ON fts.rowid = j.id";
        $w[] = "job_postings_fts MATCH ?";
        $b[] = $match;
    }
    $w[] = "j.$col != ''";
    $w[] = "j.$col IS NOT NULL";
    $where_sql = 'WHERE ' . implode(' AND ', $w);
    $lim = $limit > 0 ? "LIMIT $limit" : '';
    $sql = "SELECT j.$col AS val, COUNT(*) AS cnt
            FROM job_postings j $join $where_sql
            GROUP BY j.$col ORDER BY cnt DESC $lim";
    $stmt = db()->prepare($sql);
    $stmt->execute($b);
    return $stmt->fetchAll();
}

/**
 * Turn a user query into an FTS5 MATCH expression.
 *
 * Every term is quoted (so FTS5 operators typed by accident are literals) and
 * the terms are AND-ed, because quoting the *whole* query made it a single
 * phrase — "inspector primărie" then matched only that exact adjacent pair and
 * returned nothing. The trailing term gets a prefix wildcard so the 400ms
 * live-search doesn't show "no results" for every half-typed word.
 *
 * Returns '' when the query has no usable terms; callers must then skip the
 * MATCH clause entirely rather than pass an empty expression to SQLite.
 */
function fts_query(string $q): string {
    $terms = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$terms) return '';

    $terms = array_slice($terms, 0, 12); // cap pathological input
    $last  = count($terms) - 1;
    $parts = [];
    foreach ($terms as $i => $term) {
        $quoted = '"' . str_replace('"', '""', $term) . '"';
        // Single letters make useless prefixes ("a*" matches half the corpus).
        $parts[] = ($i === $last && mb_strlen($term) >= 2) ? $quoted . ' *' : $quoted;
    }
    return implode(' AND ', $parts);
}

/** Rank title matches above body matches. Column order matches the FTS5 DDL. */
const FTS_RANK = 'bm25(job_postings_fts, 10.0, 3.0, 1.0, 1.0)';
