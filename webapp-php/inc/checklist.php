<?php
declare(strict_types=1);

require_once __DIR__ . '/v5.php';

// ---- UX-11 checklist ----
//
// Application-preparation checklist (UX-11, first slice). Pure functions: they turn what
// the export already holds (the extracted `application_docs` list, the calendar rows, the
// resolved application deadline) into one item list, and embed no state. The page
// (partials/checklist.php) renders it as a read-only list that works without JavaScript;
// static/checklist.js adds browser-local check boxes on top (key `posturi.checklist.v1`).
//
// Item contract (every item):
//   id         `<rev12>:<kind>:<n>`  identity WITHIN one source revision; not durable across
//              revisions (n is a position, rev12 changes with the page).
//   fp         first 16 hex of sha256(kind US role_key US normalised label [US stage extras]).
//              Proof that an item is identical across revisions - never an identity within a
//              revision. It lets progress survive a revision only where the item did not change.
//   kind       'document' | 'stage'
//   role_key   null on every item in this slice: `application_docs` and the calendar are
//              posting-level facts, and posting_roles keys are revision-scoped. No role is
//              claimed for an item the source does not scope to one.
//   label      plain text, at most 300 characters (the page shows it escaped, JS via textContent)
//   stages also: date (Y-m-d), time (HH:MM, only when stated), precision ('date'|'datetime'),
//              passed (the date is before Bucharest today), estimated (true only for the
//              expiry-fallback deadline)
//   evidence   {basis: 'extracted' | 'scraped' | 'resolved', quote?: scraped calendar row label,
//              deadline_source?: concurs|anunt|expirare}

/**
 * "Elementele dvs." (reader-added items) ship OFF since 2026-10-16: too much novelty at once.
 * Off, the page renders no add box and no reader items; items stored earlier stay in the
 * browser untouched and come back when this is turned on. `POSTURI_CHECKLIST_CUSTOM=1` (or
 * `=0`) overrides it per process, which is how the browser tests keep the feature covered.
 */
const CHECKLIST_CUSTOM_ITEMS_ENABLED = false;

function checklist_custom_items_enabled(): bool {
    $env = getenv('POSTURI_CHECKLIST_CUSTOM');
    if ($env === '1') return true;
    if ($env === '0') return false;
    return CHECKLIST_CUSTOM_ITEMS_ENABLED;
}

const CHECKLIST_DOCS_MAX   = 60;
const CHECKLIST_STAGES_MAX = 40;     // with the documents, at most 100 items: the client's per-posting storage cap
const CHECKLIST_LABEL_MAX  = 300;
const CHECKLIST_MODEL_VERSION = 1;

/** Shorten to $max characters, with an ellipsis when something was cut. */
function checklist_clip(string $s, int $max = CHECKLIST_LABEL_MAX): string {
    if (mb_strlen($s, 'UTF-8') <= $max) return $s;
    return rtrim(mb_substr($s, 0, $max - 1, 'UTF-8')) . '…';
}

/**
 * One list item or event label as plain text. Markdown syntax goes (emphasis, link targets,
 * heading marks); HTML does NOT get interpreted - `<script>` stays as the literal characters
 * the extraction produced, and every output path escapes it. Never returns markup.
 */
function checklist_plain(string $s): string {
    $s = mb_scrub($s, 'UTF-8');
    $s = preg_replace('~<br\s*/?>~i', ' ', $s) ?? $s;
    $s = preg_replace('/!?\[([^\]]*)\]\((?:[^()]|\([^()]*\))*\)/u', '$1', $s) ?? $s;       // [text](target) -> text
    // Emphasis is removed only as a matched pair, so an identifier such as `a__b` or a lone `*` survives.
    $s = preg_replace('/(\*\*|__|~~)(?=\S)(.+?)(?<=\S)\1/u', '$2', $s) ?? $s;
    $s = preg_replace('/(?<![\p{L}\p{N}*])\*(?=\S)([^*]+?)(?<=\S)\*(?![\p{L}\p{N}*])/u', '$1', $s) ?? $s;
    $s = preg_replace('/(?<![\p{L}\p{N}_])_(?=\S)([^_]+?)(?<=\S)_(?![\p{L}\p{N}_])/u', '$1', $s) ?? $s;
    $s = str_replace('`', '', $s);
    $s = preg_replace('/^\s*#+\s*/u', '', $s) ?? $s;
    $s = preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return checklist_clip(trim($s, " \t\n\r;,"));
}

/**
 * Lower-case, diacritics and punctuation folded, whitespace collapsed: two labels that differ
 * only in case, punctuation or Romanian diacritics are the same document.
 */
function checklist_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    if (class_exists('Normalizer')) {
        $d = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($d)) $s = preg_replace('/\p{Mn}+/u', '', $d) ?? $s;
    }
    return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '');
}

/**
 * Fingerprint of an item: kind, role key and normalised label, plus whatever else makes a
 * stage "the same stage" (its date and time). Documents carry no extras.
 *
 * @param array<int,string> $extra
 */
function checklist_fp(string $kind, ?string $role_key, string $label, array $extra = []): string {
    $parts = array_merge([$kind, (string)$role_key, checklist_norm($label)], $extra);
    return substr(hash('sha256', implode("\x1f", $parts)), 0, 16);
}

/**
 * The page revision the checklist's ids are scoped to: the exported `detail_content_hash`,
 * else a hash of the extraction payload, else '' (unknown - the client then claims no identity).
 * A value with unexpected characters is re-hashed, never stored as it came.
 */
function checklist_revision(array $p): string {
    $h = trim((string)($p['detail_content_hash'] ?? ''));
    if ($h !== '') {
        return preg_match('/^[A-Za-z0-9:_.-]{1,100}$/', $h) ? $h : 'h:' . substr(hash('sha256', $h), 0, 16);
    }
    $schema = $p['schema_json'] ?? null;
    if (is_string($schema) && $schema !== '') {
        return 'pay:' . substr(hash('sha256', $schema), 0, 12);
    }
    return '';
}

/**
 * The documents the extraction lists, one label per list item.
 *
 * Accepts the markdown bullet list `application_docs` holds (top-level `-`, `*`, `+`, `•` or
 * `1.` / `1)`), with these rules: items indented two or more columns past the first marker
 * are folded into their parent ("acte de studii: diplomă; foaie matricolă"); an unmarked
 * continuation line joins the item above it; text before the first marker is an introduction,
 * not a document; with no marker anywhere, each non-empty line is one item. An empty,
 * missing or non-text value yields [] - which the page must show as "not extracted", never
 * as "no documents required".
 *
 * @return array<int,string>
 */
function checklist_docs(mixed $raw): array {
    if (is_array($raw)) {
        $raw = implode("\n", array_map(static fn($x) => '- ' . (is_scalar($x) ? (string)$x : ''), $raw));
    }
    if (!is_string($raw) || trim($raw) === '') return [];
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $marker = '/^([ \t]*)(?:[-*+•]|\d{1,3}[.)])[ \t]+(.*)$/u';
    $has_marker = false;
    foreach ($lines as $line) {
        if (preg_match($marker, $line)) { $has_marker = true; break; }
    }

    $items = [];
    $cur = null;
    $base = null;
    $flush = static function () use (&$items, &$cur) {
        if ($cur !== null) $items[] = $cur;
        $cur = null;
    };
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        if (preg_match($marker, $line, $m)) {
            $indent = strlen(str_replace("\t", '    ', $m[1]));
            $base ??= $indent;
            if ($cur !== null && $indent >= $base + 2) {
                $cur['subs'][] = $m[2];
            } else {
                $flush();
                $cur = ['text' => $m[2], 'subs' => []];
            }
            continue;
        }
        if (!$has_marker) {
            $flush();
            $cur = ['text' => $line, 'subs' => []];
            continue;
        }
        if ($cur === null) continue;                    // introduction before the first marker
        if ($cur['subs']) $cur['subs'][count($cur['subs']) - 1] .= ' ' . trim($line);
        else $cur['text'] .= ' ' . trim($line);
    }
    $flush();

    $out = [];
    foreach ($items as $it) {
        $text = checklist_plain($it['text']);
        if ($it['subs']) {
            $subs = array_values(array_filter(array_map('checklist_plain', $it['subs']), static fn($s) => $s !== ''));
            if ($subs) $text = rtrim($text, ':') . ($text === '' ? '' : ': ') . implode('; ', $subs);
        }
        $text = checklist_clip($text);
        if (!preg_match('/[\p{L}\p{N}]/u', $text)) continue;           // nothing but marks: not a document
        $out[] = $text;
        if (count($out) >= CHECKLIST_DOCS_MAX) break;
    }
    return $out;
}

/** The stage rows: the resolved application deadline plus the calendar rows the page lists. */
function checklist_stages(array $p, array $events, array $dl): array {
    $deadline = (string)($dl['date'] ?? '');
    $source   = (string)($dl['source'] ?? '');
    $dtime = null;
    // An exact hour counts only when it is on the deadline's own day (same rule as the JSON-LD).
    if ($deadline !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', (string)($p['data_limita_depunere'] ?? ''), $m)
        && $m[1] === $deadline && $m[2] !== '00:00') {
        $dtime = $m[2];
    }

    $rows = [];
    $merged_time = null;
    foreach ($events as $ev) {
        $label = checklist_plain((string)($ev['eveniment'] ?? ''));
        if ($label === '') continue;
        $date = substr((string)($ev['data'] ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
        // '00:00' is the date-only placeholder (the sidebar treats it so too).
        $time = preg_match('/^\d{2}:\d{2}$/', (string)($ev['ora'] ?? '')) && $ev['ora'] !== '00:00' ? (string)$ev['ora'] : null;
        // The calendar row that IS the submission deadline is the deadline item below, not a second line.
        if ($deadline !== '' && $date === $deadline && preg_match('/depun|[iî]nscri|inscri/iu', $label)) {
            $merged_time ??= $time;
            continue;
        }
        $rows[] = ['label' => $label, 'date' => $date, 'time' => $time, 'estimated' => false,
            'evidence' => ['basis' => 'scraped', 'quote' => $label]];
        if (count($rows) >= CHECKLIST_STAGES_MAX - 1) break;             // room is kept for the deadline row
    }
    if ($deadline !== '') {
        $est = $source === 'expirare';
        $rows[] = [
            'label' => $est ? 'Termen estimat pentru depunerea dosarului' : 'Termen de depunere a dosarului',
            'date' => $deadline, 'time' => $dtime ?? $merged_time, 'estimated' => $est,
            'evidence' => ['basis' => 'resolved', 'deadline_source' => in_array($source, ['concurs', 'anunt', 'expirare'], true) ? $source : null],
        ];
    }
    // Chronological, rows with no usable date last; PHP's sort is stable, so equal dates keep source order.
    usort($rows, static fn($a, $b) => ($a['date'] === '' ? 1 : 0) <=> ($b['date'] === '' ? 1 : 0)
        ?: strcmp($a['date'], $b['date']));
    return $rows;
}

/**
 * The whole checklist model for one posting: what the page renders and what is embedded for
 * the script. `$events` are the `calendar_events` rows the page already loaded, `$dl` the
 * result of posting_deadline().
 *
 * @return array{version:int, source_url:string, source_revision:string, role_scope:string, items:array<int,array<string,mixed>>}
 */
function checklist_model(array $p, array $events, array $dl): array {
    $rev = checklist_revision($p);
    $rev12 = substr(preg_replace('/[^A-Za-z0-9]/', '', $rev) ?? '', 0, 12);
    if ($rev12 === '') $rev12 = 'norev';

    $schema = is_string($p['schema_json'] ?? null) ? json_decode((string)$p['schema_json'], true) : null;
    // BETA-V5: a v5 row lists its dosar as standard codes (fixed labels) plus quoted
    // specific documents scoped to roles; a v4 row as the markdown `application_docs`.
    $v5docs = is_array($schema) ? v5_document_items($schema) : [];
    $docs = [];
    if ($v5docs) {
        foreach (array_slice($v5docs, 0, CHECKLIST_DOCS_MAX) as $d) {
            $label = checklist_clip($d['label'] . ($d['roles'] ? ' (pentru: ' . implode(', ', $d['roles']) . ')' : ''));
            $docs[] = ['label' => $label, 'evidence' => array_filter(['basis' => 'extracted', 'quote' => $d['evidence']])];
        }
    } else {
        foreach (checklist_docs(is_array($schema) ? ($schema['application_docs'] ?? null) : null) as $label) {
            $docs[] = ['label' => $label, 'evidence' => ['basis' => 'extracted']];
        }
    }

    $items = [];
    foreach ($docs as $i => $doc) {
        $items[] = [
            'id' => $rev12 . ':document:' . ($i + 1), 'fp' => checklist_fp('document', null, $doc['label']),
            'kind' => 'document', 'role_key' => null, 'label' => $doc['label'],
            'evidence' => $doc['evidence'],
        ];
    }
    foreach (checklist_stages($p, $events, $dl) as $i => $st) {
        $time = $st['time'];
        $item = [
            'id' => $rev12 . ':stage:' . ($i + 1),
            'fp' => checklist_fp('stage', null, $st['label'], [$st['date'], (string)$time, $st['estimated'] ? 'est' : '']),
            'kind' => 'stage', 'role_key' => null, 'label' => $st['label'],
        ];
        if ($st['date'] !== '') {
            $item['date'] = $st['date'];
            $days = days_until($st['date']);
            $item['passed'] = $days !== null && $days < 0;
        }
        if ($time !== null) $item['time'] = $time;
        $item['precision'] = $time !== null ? 'datetime' : ($st['date'] !== '' ? 'date' : 'none');
        if ($st['estimated']) $item['estimated'] = true;
        $item['evidence'] = array_filter($st['evidence'], static fn($v) => $v !== null);
        $items[] = $item;
    }
    return [
        'version' => CHECKLIST_MODEL_VERSION,
        'source_url' => (string)($p['url'] ?? ''),
        'source_revision' => $rev,
        'role_scope' => 'posting',
        'items' => $items,
    ];
}

/**
 * The model as JSON that cannot break out of a `<script type="application/json">` element:
 * `<`, `>`, `&`, `'` and `"` are all written as \uXXXX escapes.
 */
function checklist_json(array $model): string {
    $json = json_encode($model, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return $json === false ? '{"version":1,"source_url":"","source_revision":"","role_scope":"posting","items":[]}' : $json;
}
