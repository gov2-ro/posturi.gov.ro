<?php
/**
 * UX-SALVATE-FLUX — one lookup for the „Flux” tab of /salvate/.
 *
 *   GET /flux.json?s[]=<search key>&e[]=<employer slug>&id[]=<posting id>
 *
 * The browser sends its saved searches (s, at most 4), followed employers (e, at most
 * 10) and saved posting ids (id, at most 20). The answer is every OPEN posting of those
 * sources, once each, newest first: at most 100 rows per source and 300 in all. Nothing
 * is stored or logged: the three lists live in the browser, the URL carries only what
 * the reader's own lists hold, and the response is `no-store`.
 *
 * Each search key and each employer goes through the list's own filter,
 * build_filters() (the same code as /posturi.json and the list page), with the status
 * forced to the default "open". The ids are returned only when the posting is open.
 *
 * Output: {today, rows: [...], sources: [{kind, ref, total, truncated, unknown?}]}. The
 * sources keep the order of the request (searches, employers, then one `saved` source
 * when ids were sent); `src` in a row holds indices into `sources`. A key that does not
 * parse, or an employer slug that does not exist, is a source with `unknown: true` and
 * no rows: the reader sees that the source exists but selects nothing.
 *
 * Errors are JSON: 405 (+Allow), 400 (a list above its cap, a malformed parameter).
 */
declare(strict_types=1);

const FLUX_MAX_SEARCHES = 4;
const FLUX_MAX_EMPLOYERS = 10;
const FLUX_MAX_IDS = 20;
const FLUX_PER_SOURCE = 100;
const FLUX_TOTAL = 300;
const FLUX_KEY_MAX = 1500;

function flux_fail(int $status, string $error, array $headers = []): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $h) header($h);
    echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

/** A request parameter that must be a list of strings (or absent). */
function flux_list(string $name, int $max, string $what): array {
    $v = $_GET[$name] ?? [];
    if (!is_array($v) || !array_is_list($v)) flux_fail(400, "{$name}[] trebuie să fie o listă");
    foreach ($v as $one) {
        if (!is_string($one) || $one === '') flux_fail(400, "{$name}[] conține o valoare nevalidă");
    }
    if (count($v) > $max) flux_fail(400, "cel mult $max $what");
    return $v;
}

/**
 * The columns of one row, and the one salary figure the list shows (SALARY_EXPR, gross).
 * `j.*` and not a list of names: an export that predates a column then still answers (db.php shim_legacy_export),
 * and the row helpers read every key with a default. The long texts are in posting_text, not in job_postings.
 */
const FLUX_COLS = "j.*, CAST((" . SALARY_EXPR . ") AS REAL) AS sal_gross,
        e.name AS employer_name_disp, e.slug AS employer_slug, jd.name AS judet_name_disp";
const FLUX_JOINS = " LEFT JOIN employers e ON e.id = j.employer_id LEFT JOIN judete jd ON jd.id = j.judet_id";

/**
 * Open postings of one filter set: [total, rows]. Rows are newest first, at most FLUX_PER_SOURCE.
 * The filter is build_filters(); the status is forced to the default so a saved `status=all` cannot list closed postings.
 */
function flux_filtered(array $params): array {
    unset($params['page'], $params['sort'], $params['title']);
    // Open postings only. `soon` and `unknown` are subsets of open and keep their meaning; `closed` selects none here;
    // `active` and `all` both mean the open postings.
    $status = (string)($params['status'] ?? DEFAULT_STATUS);
    if ($status === 'closed') return [0, []];
    if (!in_array($status, ['soon', 'unknown'], true)) $params['status'] = DEFAULT_STATUS;
    $f = build_filters($params);
    $w = $f['where'];
    $b = $f['binds'];
    $join = '';
    if ($f['fts'] && ($q = trim((string)($params['q'] ?? '')))) {
        $join = ' JOIN job_postings_fts fts ON fts.rowid = j.id';
        $w[] = 'job_postings_fts MATCH ?';
        $b[] = fts_query($q);
    }
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    $st = db()->prepare('SELECT COUNT(*) FROM job_postings j' . $join . $where);
    $st->execute($b);
    $total = (int)$st->fetchColumn();
    $st = db()->prepare('SELECT ' . FLUX_COLS . ' FROM job_postings j' . $join
        . FLUX_JOINS . $where . ' ORDER BY j.published_at DESC, j.id DESC LIMIT ' . FLUX_PER_SOURCE);
    $st->execute($b);
    return [$total, $st->fetchAll()];
}

/** The salary bucket of a gross figure, with the same limits as the list's salary filter (SALARY_NET_BUCKETS). */
function flux_bucket(float $gross): ?string {
    if ($gross <= 0) return null;
    foreach (SALARY_NET_BUCKETS as $b) {
        $ok = ($b['min'] === null || $gross >= gross_for_net($b['min']))
           && ($b['max'] === null || $gross < gross_for_net($b['max']));
        if ($ok) return $b['key'];
    }
    return null;
}

/** One row as the browser gets it. */
function flux_row(array $p): array {
    $dt = display_title($p);
    $dl = posting_deadline($p);
    $sal = list_salary($p);
    $gross = (float)($p['sal_gross'] ?? 0);
    // Under 1.000 lei gross is not a monthly salary (an hourly rate; DATA-SALARY-NOT-MONTHLY): no figure for the stats.
    $net = $gross >= 1000 ? net_from_gross($gross) : null;
    $salary = null;
    if ($sal !== null) {
        $salary = ['text' => $sal['text'], 'net' => $net, 'bucket' => $net !== null ? flux_bucket($gross) : null,
                   'estimated' => (bool)$sal['estimated']];
    }
    $judet = (string)($p['judet_name_disp'] ?? $p['judet_name'] ?? '');
    return [
        'id'              => (int)$p['id'],
        'title'           => $dt['primary'],
        'url'             => job_url($p),
        'source_url'      => (string)$p['url'],
        'employer'        => (string)($p['employer_name_disp'] ?? $p['employer_name'] ?? ''),
        'employer_slug'   => ($p['employer_slug'] ?? '') !== '' ? (string)$p['employer_slug'] : null,
        'judet'           => $judet !== '' ? $judet : null,
        'locality'        => (string)($p['locality'] ?? ''),
        'place'           => place_label(['judet_name' => $judet, 'locality' => $p['locality'] ?? '']),
        'domain'          => ($p['inf_profession_family'] ?? '') !== '' ? (string)$p['inf_profession_family'] : null,
        'salary'          => $salary,
        'published_at'    => $p['published_at'] ? substr((string)$p['published_at'], 0, 10) : null,
        'apply_deadline'  => $dl['date'],
        'deadline_source' => $dl['source'],
        'src'             => [],
    ];
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    flux_fail(405, 'metoda trebuie să fie GET', ['Allow: GET, HEAD']);
}

$keys = flux_list('s', FLUX_MAX_SEARCHES, 'căutări salvate');
$slugs = flux_list('e', FLUX_MAX_EMPLOYERS, 'angajatori urmăriți');
$id_in = flux_list('id', FLUX_MAX_IDS, 'anunțuri salvate');
$ids = [];
foreach ($id_in as $v) {
    if (!ctype_digit($v) || strlen($v) > 10 || (int)$v < 1) flux_fail(400, 'id[] trebuie să fie numere întregi pozitive');
    $ids[(int)$v] = true;
}
$ids = array_keys($ids);

$sources = [];     // [{kind, ref, total, truncated}] in request order
$by_source = [];   // source index -> row ids it contributed (newest first)
$rows = [];        // posting id -> row

$add_rows = function (int $si, array $found) use (&$rows, &$by_source): void {
    $by_source[$si] = [];
    foreach ($found as $p) {
        $id = (int)$p['id'];
        if (!isset($rows[$id])) $rows[$id] = flux_row($p);
        $by_source[$si][] = $id;
    }
};

foreach ($keys as $key) {
    $si = count($sources);
    $source = ['kind' => 'search', 'ref' => $key, 'total' => 0, 'truncated' => false];
    $params = [];
    if (strlen($key) <= FLUX_KEY_MAX) parse_str($key, $params);
    // Only a canonical key (ADR 0001) selects: a hand-made string would otherwise read as "no filter" and list everything.
    [$valid, $err] = ($params && search_key($params) === $key) ? validated_query($params) : [null, 'not a search key'];
    if ($err !== null || !$valid) {
        $source['unknown'] = true;
        $by_source[$si] = [];
    } else {
        [$total, $found] = flux_filtered($valid);
        $source['total'] = $total;
        $add_rows($si, $found);
    }
    $sources[] = $source;
}

foreach ($slugs as $slug) {
    $si = count($sources);
    $source = ['kind' => 'employer', 'ref' => $slug, 'total' => 0, 'truncated' => false];
    $st = db()->prepare('SELECT 1 FROM employers WHERE slug = ?');
    $st->execute([$slug]);
    if (!$st->fetchColumn()) {
        $source['unknown'] = true;
        $by_source[$si] = [];
    } else {
        [$total, $found] = flux_filtered(['employer' => $slug]);
        $source['total'] = $total;
        $add_rows($si, $found);
    }
    $sources[] = $source;
}

if ($ids) {
    $si = count($sources);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare('SELECT ' . FLUX_COLS . ' FROM job_postings j' . FLUX_JOINS . ' WHERE j.id IN (' . $ph . ') AND j.application_status IN (' . OPEN_STATUS_SQL . ')'
        . ' ORDER BY j.published_at DESC, j.id DESC');
    $st->execute($ids);
    $found = $st->fetchAll();
    $add_rows($si, $found);
    $sources[] = ['kind' => 'saved', 'ref' => 'saved', 'total' => count($found), 'truncated' => false];
}

// Newest first, once each, at most FLUX_TOTAL.
$list = array_values($rows);
usort($list, fn($a, $b) => [(string)$b['published_at'], $b['id']] <=> [(string)$a['published_at'], $a['id']]);
$list = array_slice($list, 0, FLUX_TOTAL);
$kept = [];
foreach ($list as $i => $r) $kept[$r['id']] = $i;
$returned = array_fill(0, count($sources), 0);
foreach ($by_source as $si => $row_ids) {
    foreach ($row_ids as $id) {
        if (!isset($kept[$id])) continue;
        $list[$kept[$id]]['src'][] = $si;
        $returned[$si]++;
    }
}
foreach ($sources as $si => &$s) {
    $s['truncated'] = $s['total'] > $returned[$si];
}
unset($s);

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode(['today' => ro_today(), 'rows' => $list, 'sources' => $sources], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
