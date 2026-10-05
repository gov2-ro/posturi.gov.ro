<?php
/**
 * UX-09 — read-only lookup of PUBLIC announcement data for browser-local
 * saved/hidden ids.
 *
 *   POST /preferinte-posturi.json   {"ids":[1001,1002]}
 *
 * It resolves ids and nothing else: no preferences are stored, saved and hidden
 * ids are indistinguishable, nothing is logged or tracked, no cookies or
 * server session are involved and no cross-origin access is granted. Unlike
 * /posturi.json there is no default "active" predicate and no 200-row cap — a
 * saved posting that has since closed (or whose id is simply gone) is a normal
 * answer. Missing ids produce no item; the client decides what that means.
 *
 * Errors are JSON and controlled: 405 (+Allow), 415, 413, 400.
 */
declare(strict_types=1);

const PREF_LOOKUP_MAX_BODY = 16384;   // 16 KiB
const PREF_LOOKUP_MAX_IDS  = 500;

function pref_lookup_fail(int $status, string $error, array $headers = []): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $h) header($h);
    echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    pref_lookup_fail(405, 'metoda trebuie să fie POST', ['Allow: POST']);
}

$ctype = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($ctype !== 'application/json') {
    pref_lookup_fail(415, 'Content-Type trebuie să fie application/json');
}

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > PREF_LOOKUP_MAX_BODY) {
    pref_lookup_fail(413, 'corpul cererii depășește 16 KiB');
}
// Read one byte past the cap so a missing/lying Content-Length is still caught.
$raw = (string)file_get_contents('php://input', false, null, 0, PREF_LOOKUP_MAX_BODY + 1);
if (strlen($raw) > PREF_LOOKUP_MAX_BODY) {
    pref_lookup_fail(413, 'corpul cererii depășește 16 KiB');
}

try {
    // PHP counts the scalar level: depth 3 is exactly {"ids":[1]}; any nested
    // array or object inside ids fails to decode.
    $body = json_decode($raw, true, 3, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    pref_lookup_fail(400, 'JSON invalid sau prea adânc');
}
if (!is_array($body) || array_is_list($body) || array_keys($body) !== ['ids']
    || !is_array($body['ids']) || !array_is_list($body['ids'])) {
    pref_lookup_fail(400, 'corpul trebuie să fie {"ids":[...]}');
}

$ids = [];
foreach ($body['ids'] as $v) {
    // is_int rejects strings, booleans, floats (1.0, 1e3), null, nested arrays,
    // and integers too large for PHP (decoded as float).
    if (!is_int($v) || $v < 1) {
        pref_lookup_fail(400, 'ids trebuie să fie numere întregi pozitive');
    }
    $ids[$v] = true;
}
$ids = array_keys($ids);   // duplicates collapse; the cap applies to distinct ids
if (count($ids) > PREF_LOOKUP_MAX_IDS) {
    pref_lookup_fail(400, 'cel mult 500 de ids distincte');
}

$items = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT j.id, j.url, j.title, j.occ_canonical, j.employer_name, j.locality,
                j.apply_deadline, j.expires_at, j.deadline_source, j.application_status,
                COALESCE(jd.name, j.judet_name) AS judet_name
           FROM job_postings j
           LEFT JOIN judete jd ON jd.id = j.judet_id
          WHERE j.id IN ($ph)
          ORDER BY j.id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $r) {
        $dl = posting_deadline($r);
        $items[] = [
            'id'              => (int)$r['id'],
            'url'             => (string)$r['url'],
            'path'            => job_url($r),
            'title'           => display_title($r)['primary'],
            'employer'        => (string)$r['employer_name'],
            'location'        => place_label($r),
            'status'          => (string)($r['application_status'] ?? '') ?: null,
            'deadline'        => $dl['date'],
            'deadline_source' => $dl['source'],
        ];
    }
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
