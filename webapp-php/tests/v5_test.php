<?php
/**
 * BETA-V5 — reading prompt-v5 extractions in a mixed v4/v5 corpus.
 *
 * Unit checks of inc/v5.php (facts, calendar rows, text fallbacks, the dosar), the
 * checklist's v5 branch, and the export columns as the fixture writes them. Page-level
 * checks of the detail page live here too once it renders the v5 facts.
 *
 * Usage: php tests/v5_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../inc/v5.php';
require_once __DIR__ . '/../inc/checklist.php';
require_once __DIR__ . '/fixtures/v5_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');
$db_path = sys_get_temp_dir() . '/posturi-v5-' . getmypid() . '.sqlite';
build_v5_db($db_path);

function v5_row(string $db_path, int $id): array {
    $pdo = new PDO('sqlite:' . $db_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $st = $pdo->prepare('SELECT j.*, t.schema_json, t.body_markdown FROM job_postings j LEFT JOIN posting_text t ON t.posting_id = j.id WHERE j.id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

run_suite('v5 facts: who can apply and how, in words', function () use ($db_path) {
    $f = v5_facts(schema_of(v5_row($db_path, 1201)));
    assert_same('Temporar vacant (până la revenirea titularului)', $f['Statutul postului'] ?? null, 'temporary vacancy');
    assert_same('0,75 normă', $f['Normă'] ?? null, 'fraction of a norm');
    assert_same('30.06.2027', $f['Contract până la'] ?? null, 'end date');
    assert_same('la sediu, prin e-mail', $f['Depunerea dosarului'] ?? null, 'channels');
    assert_same('Florești, Apahida', $f['Locuri de muncă'] ?? null, 'work localities');
    assert_same('Consiliul Local Cluj-Napoca', $f['În subordinea'] ?? null, 'stated subordination');
    assert_false(isset($f['Tip concurs']), 'an ordinary competition is not labelled');
    $p = v5_facts(schema_of(v5_row($db_path, 1202)));
    assert_contains('promovare', $p['Tip concurs'] ?? '', 'promotion named');
    assert_contains('angajații instituției', $p['Tip concurs'] ?? '', 'promotion says who may take part');
    assert_same('3', $p['Posturi scoase la concurs'] ?? null, 'seats');
    assert_same([], v5_facts(schema_of(v5_row($db_path, 1001))), 'a v4 row has no v5 facts');
    assert_same('', fte_label(1.0), 'a full norm is not a fraction');
    assert_same('0,5 normă', fte_label(0.5), 'half norm');
});

run_suite('the extracted calendar as one table: dated rows in order, relative rows kept', function () use ($db_path) {
    $rows = llm_calendar_rows(schema_of(v5_row($db_path, 1201)));
    assert_same(['depunere_dosare', 'proba_scrisa', 'rezultate_selectie_dosare'], array_column($rows, 'stage'), 'date order, undated last');
    assert_same('Depunerea dosarelor', $rows[0]['label'], 'stage label from the vocabulary');
    assert_same('Depunerea dosarelor la secretariat', $rows[0]['detail'], 'the posting\'s own words beside it');
    assert_same('2 zile lucrătoare după depunere', $rows[2]['detail'], 'a relative row keeps its rule');
    assert_same('', $rows[2]['date'], 'never a computed date');
    assert_same([], llm_calendar_rows(schema_of(v5_row($db_path, 1001))), 'no extracted calendar: [] (page falls back)');
    assert_same(count(CALENDAR_STAGE_LABELS), 32, 'every CalendarStage has a label');
});

run_suite('dropped free-text keys are rebuilt from the structured ones', function () use ($db_path) {
    $s = schema_text_fallbacks(schema_of(v5_row($db_path, 1201)));
    assert_same('Studii medii cu diplomă de bacalaureat.', $s['educationRequirements'], 'education text');
    assert_same('Nu se solicită vechime.', $s['experienceRequirements'], 'experience text');
    assert_contains('- Formular de înscriere', $s['application_docs'], 'dosar text from codes');
    assert_contains('- aviz psihologic', $s['application_docs'], 'and from specific items');
    $kept = schema_text_fallbacks(['educationRequirements' => 'Text v4', 'education' => ['verbatim' => 'altceva']]);
    assert_same('Text v4', $kept['educationRequirements'], 'a v4 value is never replaced');
});

run_suite('checklist: the v5 dosar as fixed labels plus quoted specific documents', function () use ($db_path) {
    $p = v5_row($db_path, 1201);
    $m = checklist_model($p, [], posting_deadline($p));
    $docs = array_values(array_filter($m['items'], fn($i) => $i['kind'] === 'document'));
    assert_same(['Formular de înscriere', 'Copia actului de identitate', 'Cazier judiciar', 'aviz psihologic'],
        array_column($docs, 'label'), 'standard codes first, then the specific item');
    assert_same('aviz psihologic pentru postul de supraveghetor', $docs[3]['evidence']['quote'] ?? null, 'the quote travels with it');
    assert_false(isset($docs[0]['evidence']['quote']), 'a standard item has no quote');
    $none = checklist_model(v5_row($db_path, 1202), [], posting_deadline(v5_row($db_path, 1202)));
    assert_same([], array_values(array_filter($none['items'], fn($i) => $i['kind'] === 'document')), 'no dosar extracted: no document items');
});

run_suite('export columns: v5 rows carry them, v4 rows read empty', function () use ($db_path) {
    $r = v5_row($db_path, 1201);
    assert_same('temporar_vacant', $r['v5_post_status'], 'post status column');
    assert_same(0.75, (float)$r['v5_fte'], 'fte column');
    $old = v5_row($db_path, 1001);
    assert_same(['', null, '[]'], [$old['v5_recruitment_kind'], $old['v5_fte'], $old['v5_channels']], 'v4 row: empty values');
});

run_suite('detail page: v5 facts, the extracted calendar table, and v4 rows unchanged', function () use ($db_path) {
    $srv = start_fixture_server($db_path);
    try {
        $get = fn(string $path) => (string)@file_get_contents($srv['base'] . $path);
        $v5 = $get('/job/1201/');
        if (str_contains($v5, 'Location:')) $v5 = $get('/job/1201-supraveghetor-de-noapte-v5/');
        $flat = preg_replace('/\s+/', ' ', strip_tags($v5));
        assert_contains('Statutul postului Temporar vacant', $flat, 'temporary vacancy shown');
        assert_contains('Normă 0,75 normă', $flat, 'norm shown');
        assert_contains('Depunerea dosarului la sediu, prin e-mail', $flat, 'channels shown');
        assert_contains('data-calendar="extras"', $v5, 'the extracted calendar is the table');
        assert_contains('2 zile lucrătoare după depunere', $flat, 'relative row shown with its rule');
        $cal = substr($v5, (int)strpos($v5, 'data-calendar='), 4000);
        $cal = substr($cal, 0, (int)strpos($cal, '</table>'));
        assert_not_contains('Calendar extras din tabel (rezervă)', $cal, 'the scraped rows are only a fallback in the table');
        assert_contains('Studii medii cu diplomă de bacalaureat.', $flat, 'requirement text rebuilt for a v5 row');
        assert_contains('"educationRequirements":"Studii medii cu diplomă de bacalaureat."', $v5, 'and in the JSON-LD');
        assert_not_contains('Loc de muncă', $flat, 'no remote/on-site line');
        $v4 = $get('/job/1001-asistent-medical-generalist/');
        assert_contains('data-calendar="tabel"', $v4, 'a v4 row with no extracted calendar keeps the scraped table');
        assert_not_contains('Statutul postului', $v4, 'no v5 facts on a v4 row');
        $list = $get('/?status=all');
        assert_contains('name="recruitment[]"', $list, 'Tip concurs offered once v5 rows exist');
        assert_contains('name="temp_vacant"', $list, 'temporary-vacancy switch offered');
        preg_match_all('#<h2 class="font-display[^>]*>\s*<a href="/job/(\d+)-#', $get('/?status=all&temp_vacant=1'), $m);
        assert_same(['1201'], $m[1], 'the switch filters to the temporarily vacant post');
        preg_match_all('#<h2 class="font-display[^>]*>\s*<a href="/job/(\d+)-#', $get('/?status=all&recruitment%5B%5D=promovare'), $m);
        assert_same(['1202'], $m[1], 'Tip concurs filters');
        $log = (string)@file_get_contents($db_path . '.server.log');
        assert_not_contains('Warning', $log, 'server log: no warnings');
        assert_not_contains('Fatal', $log, 'server log: no fatals');
    } finally {
        if (isset($srv['proc']) && is_resource($srv['proc'])) { proc_terminate($srv['proc']); proc_close($srv['proc']); }
    }
});

@unlink($db_path);
@unlink($db_path . '.server.log');
finish();
