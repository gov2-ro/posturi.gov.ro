<?php
/**
 * UX-11 — browser-local application preparation checklist: server side (first slice).
 *
 * The detail page renders the checklist from what the export already holds: the extracted
 * `application_docs` list, the calendar rows and the resolved deadline. Checked here, against
 * the checklist fixture (tests/fixtures/checklist.php) served on the built-in router:
 *   - documents are parsed from markdown bullets (ordered, nested, hostile, with an introduction
 *     line, continuation lines, no markers) and are always plain, escaped text;
 *   - an empty or missing extraction says "not extracted", never "no documents required";
 *   - stages carry their dates, hour only when stated, the passed state, and the expiry-fallback
 *     qualification the page already uses; the application deadline stays visible once passed;
 *   - ids are scoped to one source revision; the fingerprint is stable across a revision for an
 *     identical item and changes with the label, the date or the hour; role keys are null;
 *   - the embedded JSON cannot break out of its <script>; hostile labels add no element;
 *   - the page is a complete read-only list without JavaScript, with the official link;
 *   - the script talks to no server and never touches the save/hide key;
 *   - an export that predates the deadline and revision columns renders without errors.
 * The script's own behaviour is in tests/browser/checklist.spec.js.
 *
 * Usage: php tests/checklist_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../inc/checklist.php';
require_once __DIR__ . '/fixtures/checklist.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_a = sys_get_temp_dir() . '/posturi-checklist-a-' . getmypid() . '.sqlite';
$db_b = sys_get_temp_dir() . '/posturi-checklist-b-' . getmypid() . '.sqlite';
build_checklist_fixture($db_a, 'a');
build_checklist_fixture($db_b, 'b');
$srv_a = start_fixture_server($db_a);
$srv_b = start_fixture_server($db_b);
$A = $srv_a['base'];
$B = $srv_b['base'];

function cl_get(string $base, string $path): string {
    return (string)@file_get_contents($base . $path);
}
function cl_xp(string $html): DOMXPath {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($dom);
}
function cl_text(?DOMNode $n): string {
    return $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent)) : '';
}
/** The embedded model of a rendered page, decoded. */
function cl_model(string $html): array {
    $xp = cl_xp($html);
    $n = $xp->query('//script[@id="checklist-data"]')->item(0);
    $m = $n ? json_decode($n->textContent, true) : null;
    return is_array($m) ? $m : [];
}
/** The checklist section, as markup. */
function cl_section(string $html): string {
    $i = strpos($html, '<section id="pregatire-dosar"');
    $j = $i === false ? false : strpos($html, '</section>', $i);
    return $i === false || $j === false ? '' : substr($html, $i, $j + 10 - $i);
}
function cl_by_label(array $model, string $label): ?array {
    foreach ($model['items'] ?? [] as $it) if ($it['label'] === $label) return $it;
    return null;
}

// ---- parsing -------------------------------------------------------------------------------

run_suite('documents are parsed from markdown bullets', function () {
    assert_same(['a', 'b', 'c.'], checklist_docs("- a;\n- b;\n- c."), 'dash list; a trailing ; is dropped, the source\'s own full stop is kept');
    assert_same(['a', 'b'], checklist_docs("* a\n+ b"), 'star and plus markers');
    assert_same(['a', 'b'], checklist_docs("1. a\n2) b"), 'ordered markers');
    assert_same(['Cerere', 'Copie CI'], checklist_docs("Documente necesare:\n- Cerere\n- Copie CI"), 'an introduction line is not a document');
    assert_same(['acte de studii: diplomă; foaie matricolă', 'CV'], checklist_docs("- acte de studii:\n  - diplomă\n  - foaie matricolă\n- CV"), 'nested items fold into their parent');
    assert_same(['a', 'b: b1', 'c continued'], checklist_docs("  - a\n  - b\n    - b1\n  - c\ncontinued"), 'consistent indent is top level; a continuation joins the item above');
    assert_same(['o linie', 'altă linie'], checklist_docs("o linie\naltă linie"), 'no markers at all: one item per line');
    assert_same([], checklist_docs(null), 'null');
    assert_same([], checklist_docs(''), 'empty string');
    assert_same([], checklist_docs("  \n \n"), 'blank lines');
    assert_same([], checklist_docs(42), 'a non-text value');
    assert_same([], checklist_docs("- \n- ;\n- **"), 'items with no text');
    assert_same(['x', 'y'], checklist_docs(['x', 'y']), 'a list value');
    $many = checklist_docs(implode("\n", array_map(fn($i) => "- doc $i", range(1, 200))));
    assert_same(CHECKLIST_DOCS_MAX, count($many), 'capped');
    $long = checklist_docs('- ' . str_repeat('lung ', 100));
    assert_true(mb_strlen($long[0]) <= CHECKLIST_LABEL_MAX && str_ends_with($long[0], '…'), 'a long label is clipped with an ellipsis');
});

run_suite('labels are plain text: markdown goes, markup stays literal', function () {
    assert_same('cerere tip', checklist_plain('cerere **tip**'));
    assert_same('italic bold x code', checklist_plain('*italic* __bold__ ~~x~~ `code`'));
    assert_same('Copie CI & anexe', checklist_plain('Copie [CI](javascript:alert(1)) & _anexe_'), 'a link keeps its text, never its target');
    assert_same('a__b and c*d', checklist_plain('a__b and c*d'), 'identifiers and lone marks survive');
    assert_same('<script>alert(1)</script> x', checklist_plain('<script>alert(1)</script> x'), 'markup is not interpreted, only escaped later');
    assert_same('a b', checklist_plain("a<br>b"), '<br> is a space');
    assert_same('a b', checklist_plain("a\t\n  b"), 'whitespace collapses');
    assert_same('Titlu', checklist_plain('## Titlu'), 'heading mark');
    assert_same('o k', checklist_plain("o\x07k"), 'control characters become spaces');
});

run_suite('the fingerprint: stable for the same content, sensitive to what matters, never the identity', function () {
    $fp = fn(string $l) => checklist_fp('document', null, $l);
    assert_same($fp('Copie după cartea de identitate'), $fp('copie dupa cartea de identitate.'), 'case, diacritics and punctuation do not change it');
    assert_same($fp('Copie  CI'), $fp('copie ci'), 'whitespace');
    assert_true($fp('Copie CI') !== $fp('Copie diplomă'), 'a different document');
    assert_true(checklist_fp('document', null, 'x') !== checklist_fp('stage', null, 'x'), 'kind is part of it');
    assert_true(checklist_fp('document', null, 'x') !== checklist_fp('document', 'src:abc:1', 'x'), 'role key is part of it');
    assert_true(checklist_fp('stage', null, 'Interviu', ['2026-11-03', '09:00', '']) !== checklist_fp('stage', null, 'Interviu', ['2026-11-05', '09:00', '']), 'a moved date');
    assert_true(checklist_fp('stage', null, 'Interviu', ['2026-11-03', '09:00', '']) !== checklist_fp('stage', null, 'Interviu', ['2026-11-03', '10:00', '']), 'a moved hour');
    assert_same(16, strlen($fp('x')), '16 hex characters');
    assert_true(preg_match('/^[0-9a-f]{16}$/', $fp('x')) === 1);
});

run_suite('the revision: content hash, else the payload, else unknown', function () {
    assert_same('abc123', checklist_revision(['detail_content_hash' => 'abc123', 'schema_json' => '{}']), 'the exported hash wins');
    $pay = checklist_revision(['detail_content_hash' => '', 'schema_json' => '{"a":1}']);
    assert_true(preg_match('/^pay:[0-9a-f]{12}$/', $pay) === 1, 'payload fallback: ' . $pay);
    assert_same($pay, checklist_revision(['schema_json' => '{"a":1}']), 'a missing column behaves like an empty one');
    assert_true($pay !== checklist_revision(['schema_json' => '{"a":2}']), 'a changed payload is a new revision');
    assert_same('', checklist_revision(['detail_content_hash' => '', 'schema_json' => null]), 'nothing: unknown');
    assert_same('', checklist_revision([]), 'an old export with neither column');
    $odd = checklist_revision(['detail_content_hash' => "bad\"<script>"]);
    assert_true(preg_match('/^h:[0-9a-f]{16}$/', $odd) === 1, 'unexpected characters are re-hashed, never stored as they came');
});

// ---- the model -----------------------------------------------------------------------------

run_suite('ids are revision-scoped, role keys are null, the fingerprint survives an identical item', function () use ($A, $B) {
    $a = cl_model(cl_get($A, '/job/1101/'));
    $b = cl_model(cl_get($B, '/job/1101/'));
    assert_same(1, $a['version'], 'model version');
    assert_same('https://posturi.gov.ro/anunt/fixture-1101', $a['source_url'], 'identity is the official source URL');
    assert_same('a11010000000000000000000', $a['source_revision']);
    assert_same('b11010000000000000000000', $b['source_revision']);
    assert_same('posting', $a['role_scope'], 'posting-level items only');
    assert_same(8, count($a['items']), '5 documents + 3 stages');
    foreach ($a['items'] as $i => $it) {
        assert_true(preg_match('/^a11010000000:(document|stage):\d+$/', $it['id']) === 1, "id {$it['id']} carries the revision");
        assert_same(null, $it['role_key'], 'role_key is null: the source does not scope the item to a role');
        assert_true(preg_match('/^[0-9a-f]{16}$/', $it['fp']) === 1, 'fp');
    }
    foreach ($b['items'] as $it) assert_true(str_starts_with($it['id'], 'b11010000000:'), 'revision B ids are B-scoped: ' . $it['id']);
    assert_same(count($a['items']), count(array_unique(array_column($a['items'], 'id'))), 'ids are unique within a revision');
    $ids_a = array_column($a['items'], 'id');
    assert_same([], array_values(array_intersect($ids_a, array_column($b['items'], 'id'))), 'no id is shared across revisions');
    // identical across the revision: same fp under a different id
    foreach (['Cerere de înscriere', 'Copie după cartea de identitate', 'Curriculum vitae', 'Termen de depunere a dosarului', 'Probă scrisă'] as $label) {
        $x = cl_by_label($a, $label); $y = cl_by_label($b, $label);
        assert_same($x['fp'], $y['fp'], "$label: provably identical");
        assert_true($x['id'] !== $y['id'], "$label: but not the same id");
    }
    // changed, removed and moved: no matching fingerprint
    assert_same(null, cl_by_label($b, 'Copie după diploma de studii'), 'the reworded document is gone from B');
    assert_true(cl_by_label($a, 'Copie după diploma de studii')['fp'] !== cl_by_label($b, 'Copie legalizată după diploma de studii')['fp'], 'reworded: other fp');
    assert_same(null, cl_by_label($b, 'Adeverință medicală'), 'removed');
    assert_true(cl_by_label($a, 'Interviu')['fp'] !== cl_by_label($b, 'Interviu')['fp'], 'moved stage: other fp');
    assert_same('2026-11-03', cl_by_label($a, 'Interviu')['date']);
    assert_same('2026-11-05', cl_by_label($b, 'Interviu')['date']);
});

run_suite('stages: dates, hour only when stated, precision, passed, estimation', function () use ($A) {
    $m = cl_model(cl_get($A, '/job/1101/'));
    $d = cl_by_label($m, 'Termen de depunere a dosarului');
    assert_same(['2026-10-16', '14:30', 'datetime', false], [$d['date'], $d['time'], $d['precision'], $d['passed']], 'deadline with an exact hour');
    assert_same('resolved', $d['evidence']['basis']);
    assert_same('anunt', $d['evidence']['deadline_source']);
    assert_false(isset($d['estimated']), 'a stated deadline is not an estimate');
    assert_same(1, count(array_filter($m['items'], fn($i) => $i['kind'] === 'stage' && str_contains($i['label'], 'epunere'))), 'the calendar row that IS the deadline is not listed twice');
    $order = array_column(array_filter($m['items'], fn($i) => $i['kind'] === 'stage'), 'date');
    $sorted = $order; sort($sorted);
    assert_same($sorted, array_values($order), 'chronological');

    $e = cl_by_label(cl_model(cl_get($A, '/job/1102/')), 'Termen estimat pentru depunerea dosarului');
    assert_same(true, $e['estimated'], 'the expiry fallback is marked estimated');
    assert_same('expirare', $e['evidence']['deadline_source']);
    assert_same(['date', false], [$e['precision'], isset($e['time'])], 'no hour is invented');

    $p = cl_model(cl_get($A, '/job/1103/'));
    $dl = cl_by_label($p, 'Termen de depunere a dosarului');
    assert_same([true, '12:00'], [$dl['passed'], $dl['time']], 'a passed deadline is flagged and keeps its hour');
    assert_same(false, cl_by_label($p, 'Interviu')['passed'], 'the later stage is not passed');
    $sc = cl_by_label(cl_model(cl_get($A, '/job/1101/')), 'Probă scrisă');
    assert_same('scraped', $sc['evidence']['basis']);
    assert_same('Probă scrisă', $sc['evidence']['quote'], 'the calendar row label as scraped');

    $none = cl_model(cl_get($A, '/job/1106/'));
    assert_same([], $none['items'], 'nothing extracted, no deadline: no items at all');
    assert_same('e11060000000000000000000', $none['source_revision']);
    assert_same('', cl_model(cl_get($A, '/job/1104/'))['source_revision'], 'no hash and no schema: unknown revision');
});

run_suite('the item count never exceeds what the client may store', function () {
    $events = [];
    for ($i = 1; $i <= 80; $i++) $events[] = ['eveniment' => "Etapa $i", 'data' => sprintf('2026-11-%02d', 1 + ($i % 28)), 'ora' => null];
    $docs = implode("\n", array_map(fn($i) => "- Document $i", range(1, 200)));
    $m = checklist_model(['url' => 'https://posturi.gov.ro/anunt/x', 'detail_content_hash' => 'h1', 'schema_json' => json_encode(['application_docs' => $docs])],
        $events, ['date' => '2026-10-20', 'source' => 'anunt']);
    assert_same(CHECKLIST_DOCS_MAX + CHECKLIST_STAGES_MAX, count($m['items']), '60 documents + 40 stages');
    assert_true(count($m['items']) <= 100, 'at most 100 items');
    assert_same(1, count(array_filter($m['items'], fn($i) => ($i['evidence']['basis'] ?? '') === 'resolved')), 'the deadline row survives the cap');
    assert_same(count($m['items']), count(array_unique(array_column($m['items'], 'id'))), 'ids stay unique');
});

run_suite('duplicate labels get distinct ids and the same fingerprint', function () use ($A, $B) {
    $docs = fn(array $m) => array_values(array_filter($m['items'], fn($i) => $i['kind'] === 'document'));
    $a = $docs(cl_model(cl_get($A, '/job/1107/')));
    assert_same(3, count($a));
    assert_same(3, count(array_unique(array_column($a, 'id'))), 'distinct ids');
    assert_same(1, count(array_unique(array_column($a, 'fp'))), 'one fingerprint');
    assert_same(2, count($docs(cl_model(cl_get($B, '/job/1107/')))), 'B drops one');
});

// ---- rendered pages -------------------------------------------------------------------------

run_suite('detail 1101: a complete read-only list, official link, no controls without JavaScript', function () use ($A) {
    $html = cl_get($A, '/job/1101/');
    assert_not_contains('Fatal error', $html);
    $sec = cl_section($html);
    assert_true($sec !== '', 'the section renders');
    assert_well_formed($sec, 'checklist section');
    $xp = cl_xp($sec);
    assert_same('Pregătirea dosarului', cl_text($xp->query('//h2')->item(0)), 'heading');
    assert_contains('Lista provine din extragerea automată a anunțului și poate fi incompletă.', cl_text($xp->query('//section')->item(0)), 'incompleteness is always stated');
    $a = $xp->query('//a[@data-cl-official]')->item(0);
    assert_same('https://posturi.gov.ro/anunt/fixture-1101', $a->getAttribute('href'), 'official link');
    assert_same('noopener noreferrer', $a->getAttribute('rel'));
    assert_same(5, $xp->query('//ul[@data-cl-list="document"]/li')->length, 'documents');
    assert_same(3, $xp->query('//ul[@data-cl-list="stage"]/li')->length, 'stages');
    assert_same(['Cerere de înscriere', 'Copie după cartea de identitate', 'Copie după diploma de studii', 'Curriculum vitae', 'Adeverință medicală'],
        array_map('cl_text', iterator_to_array($xp->query('//ul[@data-cl-list="document"]/li/span[@data-cl-label]'))), 'document labels, in source order');
    $dl = $xp->query('//li[@data-cl-deadline]')->item(0);
    assert_same('16.10.2026, ora 14:30', cl_text($xp->query('.//time', $dl)->item(0)), 'date and the stated hour');
    assert_same('2026-10-16T14:30', $xp->query('.//time', $dl)->item(0)->getAttribute('datetime'), 'machine date carries the hour');
    assert_same(0, $xp->query('//*[@data-cl-passed-tag]')->length, 'nothing is past on 1101');
    assert_same(0, $xp->query('//*[@data-cl-estimated]')->length, 'a stated deadline carries no estimate note');
    // the server markup carries no progress and no controls: it is the no-JS list
    assert_same(0, $xp->query('//form | //textarea | //select')->length, 'no form, ever');
    assert_same(1, $xp->query('//input')->length, 'only the add field exists in the markup...');
    assert_same('text', $xp->query('//input')->item(0)->getAttribute('type'), '...a text field');
    assert_false($xp->query('//input')->item(0)->hasAttribute('name'), '...with no name: it can never be submitted');
    assert_same(0, $xp->query('//input[@type="checkbox"]')->length, 'no check box until the script adds one');
    assert_same(0, $xp->query('//*[@checked]')->length, 'the server marks nothing as done');
    assert_true($xp->query('//*[@data-cl-js and @hidden]')->length === 1, 'the JS-only region is hidden by default');
    assert_same(1, $xp->query('//noscript')->length, 'the no-JS explanation');
    assert_contains('Progresul se păstrează doar în acest browser.', cl_text($xp->query('//*[@data-cl-storage]')->item(0)), 'storage disclosure');
    // "prepared", never "submitted": outside the sentence that explains the difference, no such claim
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $sec, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xp2 = new DOMXPath($dom);
    $explain = $xp2->query('//div[@data-cl-js]/p[1]')->item(0);
    assert_contains('nu că dosarul a fost depus', cl_text($explain), 'the explanation exists');
    $explain->parentNode->removeChild($explain);
    $text = cl_text($dom->documentElement);
    foreach (['depus', 'trimis', 'încheiat', 'finalizat', 'expirat', 'completat'] as $bad) assert_not_contains($bad, $text, "no \"$bad\" claim");
    // the whole page also loads the script once, deferred, with a cache-busting version
    assert_same(1, preg_match_all('#<script src="/static/checklist\.js\?v=\d+" defer></script>#', $html), 'script tag');
});

run_suite('detail 1102: hostile, ordered, nested and introduction-line documents; estimated deadline', function () use ($A) {
    $html = cl_get($A, '/job/1102/');
    $sec = cl_section($html);
    $xp = cl_xp($sec);
    $labels = array_map('cl_text', iterator_to_array($xp->query('//ul[@data-cl-list="document"]/li/span[@data-cl-label]')));
    assert_same(["<script>alert('x')</script> cerere tip", 'Copie CI & anexe: fața; verso', 'Dosar cu șină'], $labels, 'parsed and shown as text');
    assert_contains('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt; cerere tip', $sec, 'the markup is escaped');
    assert_not_contains("<script>alert('x')", $sec, 'no raw script from a label');
    assert_not_contains('javascript:', strtolower($sec), 'a link target in a label never survives');
    assert_same(0, $xp->query('//*[@onerror]//script | //section//script | //section//img')->length);
    // estimated deadline: qualified the way the page does it
    $dl = $xp->query('//li[@data-cl-deadline]')->item(0);
    assert_contains('Termen estimat pentru depunerea dosarului', cl_text($dl));
    assert_same(DEADLINE_FALLBACK_NOTE . '.', cl_text($xp->query('.//*[@data-cl-estimated]', $dl)->item(0)), 'fallback note');
    assert_contains(DEADLINE_SOURCE_LABELS['expirare'], cl_text($dl), 'provenance');
    assert_contains('doar data; ora nu este precizată în date', cl_text($dl), 'date precision disclosed');
    assert_same('20.10.2026', cl_text($xp->query('.//time', $dl)->item(0)));
});

run_suite('detail 1103 and 1106: an empty extraction is unknown, never "no documents"; a passed deadline stays', function () use ($A) {
    $wording = 'Lista documentelor nu a fost extrasă automat. Consultați anunțul oficial pentru documentele cerute.';
    foreach ([1103, 1106, 1104] as $id) {
        $sec = cl_section(cl_get($A, "/job/$id/"));
        $xp = cl_xp($sec);
        assert_same($wording, cl_text($xp->query('//*[@data-cl-empty="document"]')->item(0)), "$id: exact wording");
        assert_same(0, $xp->query('//ul[@data-cl-list="document"]')->length, "$id: no empty list that could read as 'none required'");
        foreach (['nu sunt necesare', 'fără documente', 'nu se cer', 'Nu sunt necesare'] as $bad) assert_not_contains($bad, $sec, "$id: $bad");
        assert_not_contains('data-cl-summary', $sec, "$id: no count of zero documents");
    }
    $sec = cl_section(cl_get($A, '/job/1103/'));
    $xp = cl_xp($sec);
    $dl = $xp->query('//li[@data-cl-deadline]')->item(0);
    assert_same('1', $dl->getAttribute('data-cl-passed'), 'the passed deadline is marked');
    assert_same('Termen trecut', cl_text($xp->query('.//*[@data-cl-passed-tag]', $dl)->item(0)), 'and labelled');
    assert_same('30.09.2026, ora 12:00', cl_text($xp->query('.//time', $dl)->item(0)), 'a passed deadline stays visible with its date');
    assert_same(1, $xp->query('//*[@data-cl-passed-tag]')->length, 'only the passed one');
    $none = cl_section(cl_get($A, '/job/1106/'));
    assert_contains('Etapele concursului nu au fost extrase automat.', $none, 'no stages: said so');
    assert_contains('Termenul de depunere nu este precizat în datele extrase', $none, 'no deadline: said so');
    $est = cl_section(cl_get($A, '/job/1102/'));
    assert_not_contains('Termenul de depunere nu este precizat', $est, 'a posting with a deadline does not say it has none');
});

run_suite('hostile source text cannot break the embedded JSON or add an element (1105)', function () use ($A) {
    $html = cl_get($A, '/job/1105/');
    $plain = cl_get($A, '/job/1106/');
    assert_not_contains('Fatal error', $html);
    $n = fn(string $h) => preg_match_all('/<script\b/i', $h);
    assert_same($n($plain), $n($html), 'the hostile labels add no <script> element');
    assert_not_contains('<img src=x onerror', $html, 'no raw image from a stage label');
    assert_not_contains('<script>window.__pwned', $html, 'no raw script from a document label');
    $i = strpos($html, '<script type="application/json" id="checklist-data">');
    $j = strpos($html, '</script>', $i);
    $json_raw = substr($html, $i + strlen('<script type="application/json" id="checklist-data">'), $j - $i - strlen('<script type="application/json" id="checklist-data">'));
    foreach (['<', '>', '&', "'", '</script'] as $c) assert_not_contains($c, $json_raw, "embedded JSON never carries a literal $c");
    $m = json_decode($json_raw, true);
    assert_same('</script><script>window.__pwned=1</script>', $m['items'][0]['label'], 'and decodes back to the exact text');
    assert_same('Cod "citat" & \'apostrof\' <b>bold</b>', $m['items'][1]['label'], 'quotes, ampersand, apostrophe, tags');
    assert_true(mb_strlen($m['items'][2]['label']) <= CHECKLIST_LABEL_MAX, 'long label clipped');
    $xp = cl_xp($html);
    assert_same(0, $xp->query('//section[@id="pregatire-dosar"]//*[self::img or self::b or self::script]')->length, 'no element built from a label');
    assert_same(0, $xp->query('//*[@onerror]')->length, 'no inline handler anywhere');
    assert_same('</script><img src=x onerror=alert(2)>', cl_text($xp->query('//ul[@data-cl-list="stage"]/li/span[@data-cl-label]')->item(1)), 'stage label shown as text (the deadline comes first by date)');
    // the model builder on its own, with the worst input
    $model = checklist_model(['url' => 'https://posturi.gov.ro/anunt/x', 'detail_content_hash' => 'h',
        'schema_json' => json_encode(['application_docs' => "- </script><!-- \u{2028} \"'&"])], [], ['date' => null, 'source' => null]);
    $js = checklist_json($model);
    foreach (['<', '>', '&', "'"] as $c) assert_not_contains($c, $js, "checklist_json: no literal $c");
    assert_same($model, json_decode($js, true), 'and it round-trips');
});

run_suite('the page markup uses design tokens and the script stays inside its lane', function () {
    $partial = (string)file_get_contents(__DIR__ . '/../partials/checklist.php') . (string)file_get_contents(__DIR__ . '/../inc/checklist.php');
    assert_same(0, preg_match('/\b(?:bg|text|border|ring|accent|fill|stroke|divide|from|to|via)-(?:slate|gray|zinc|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|neutral|white|black)-\d{2,3}\b/', $partial), 'no raw Tailwind palette class');
    assert_same(0, preg_match('/\b(?:bg|text|border)-(?:white|black)\b/', $partial), 'no bg-white / text-black');
    assert_same(0, preg_match('/#[0-9a-fA-F]{3,8}\b(?!;)/', preg_replace('/&#\d+;|href="#[^"]*"|id="[^"]*"/', '', $partial)), 'no hex literal');
    $js = (string)file_get_contents(__DIR__ . '/../static/checklist.js');
    $code = preg_replace('~/\*.*?\*/~s', '', $js);
    $code = preg_replace('~^\s*//.*$~m', '', $code);
    foreach (['fetch(', 'XMLHttpRequest', 'sendBeacon', 'WebSocket', 'EventSource', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function',
              'location.', 'history.', 'preferences', 'window.open', 'FormData', '.submit('] as $bad) {
        assert_not_contains($bad, $code, "checklist.js must not contain $bad");
    }
    assert_contains("'posturi.checklist.v1'", $code, 'its own key');
    assert_contains('textContent', $code, 'text goes in through textContent');
    // the save/hide script keeps its own key, and this feature has not changed it
    $saved = (string)file_get_contents(__DIR__ . '/../static/saved.js');
    assert_contains("var KEY = 'posturi.preferences.v1';", $saved, 'saved.js key unchanged');
});

run_suite('server log is clean', function () use ($srv_a, $srv_b) {
    foreach ([$srv_a, $srv_b] as $s) {
        $log = (string)@file_get_contents($s['log']);
        foreach (['Fatal', 'Warning', 'Notice', 'Deprecated', 'no such'] as $bad) assert_not_contains($bad, $log, "log: $bad");
    }
});

// ---- an export that predates the deadline and revision columns ---------------------------------

$old_db = sys_get_temp_dir() . '/posturi-checklist-old-' . getmypid() . '.sqlite';
copy($db_a, $old_db);
$o = new PDO('sqlite:' . $old_db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['application_status', 'apply_deadline', 'deadline_source', 'detail_content_hash', 'schema_source_revision', 'schema_prompt_version'] as $col) {
    foreach ($o->query("SELECT name FROM sqlite_master WHERE type = 'index' AND sql LIKE '%$col%'")->fetchAll(PDO::FETCH_COLUMN) as $idx) {
        $o->exec("DROP INDEX \"$idx\"");
    }
    $o->exec("ALTER TABLE job_postings DROP COLUMN $col");
}
unset($o);
$old_srv = start_fixture_server($old_db);
$OB = $old_srv['base'];

run_suite('old export (no deadline or revision columns): renders, falls back, claims nothing it cannot', function () use ($OB) {
    foreach ([1101, 1102, 1103, 1104, 1105, 1106, 1107] as $id) {
        $html = cl_get($OB, "/job/$id/");
        assert_not_contains('Fatal error', $html, "$id");
        assert_not_contains('no such', $html, "$id");
        assert_true(cl_section($html) !== '', "$id: the checklist still renders");
    }
    $m = cl_model(cl_get($OB, '/job/1101/'));
    assert_true(preg_match('/^pay:[0-9a-f]{12}$/', $m['source_revision']) === 1, 'no content hash column: the payload hash stands in: ' . $m['source_revision']);
    assert_same(5, count(array_filter($m['items'], fn($i) => $i['kind'] === 'document')), 'documents come from schema_json, which every export has');
    // no resolved deadline column: the page's own fallback applies, so the deadline is an estimate
    $dl = cl_by_label($m, 'Termen estimat pentru depunerea dosarului');
    assert_true($dl !== null && $dl['estimated'] === true, 'the expiry stands in and is qualified as an estimate');
    assert_same('', cl_model(cl_get($OB, '/job/1104/'))['source_revision'], 'no hash and no schema: unknown');
    $xp = cl_xp(cl_section(cl_get($OB, '/job/1101/')));
    assert_same(1, $xp->query('//*[@data-cl-estimated]')->length, 'qualified on the page too');
    $log = (string)@file_get_contents($GLOBALS['old_srv']['log']);
    foreach (['Fatal', 'Warning', 'Notice', 'Deprecated', 'no such'] as $bad) assert_not_contains($bad, $log, "log: $bad");
});

stop_fixture_server($old_srv, $old_db);
stop_fixture_server($srv_b, $db_b);
stop_fixture_server($srv_a, $db_a);
finish();
