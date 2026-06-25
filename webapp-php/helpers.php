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
        $sections[] = ['label' => $label, 'html' => render_markdown($rendered)];
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

    if ($q && ($excl !== 'q')) {
        $fts = true;
        // FTS join handled in caller; rank sort also set there
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
    if ($fts && $q_term !== '') {
        $join = "JOIN job_postings_fts fts ON fts.rowid = j.id";
        $w[] = "job_postings_fts MATCH ?";
        $b[] = fts_escape($q_term);
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

function fts_escape(string $q): string {
    // Escape FTS5 special chars, wrap in quotes for phrase match
    $q = str_replace('"', '""', $q);
    return '"' . $q . '"';
}
