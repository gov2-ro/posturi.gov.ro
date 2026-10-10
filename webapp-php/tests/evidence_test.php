<?php
/**
 * UX-13 — source evidence beside structured requirements (first slice).
 *
 * The detail page reads `requirement_evidence` (export-to-sqlite.py / evidence.py) and
 * puts a native <details> disclosure beside each education / experience / skill /
 * credential fact. Checked here, against the fixture served on the built-in router:
 *   - the three states (verified / unverified / absent) carry their Romanian labels, and a
 *     quotation (<blockquote>) appears ONLY for a verified row; an unverified quote is
 *     plain labelled text;
 *   - the facts stay visible outside the disclosure and it needs no JavaScript;
 *   - each role shows its own evidence and never another role's; a decomposed posting's
 *     flat block shows none;
 *   - hostile quote/section/label text is escaped, and only http(s) links are rendered;
 *   - an export without the table renders exactly as before, without errors.
 *
 * Usage: php tests/evidence_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-evidence-' . getmypid() . '.sqlite';
build_fixture_db($db_path, true, true);
$srv = start_fixture_server($db_path);
$base = $srv['base'];

function ev_get(string $base, string $path): string {
    return (string)@file_get_contents($base . $path);
}

function ev_xpath(string $html): DOMXPath {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($dom);
}

function ev_text(?DOMNode $n): string {
    return $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent)) : '';
}

/** The evidence statuses of every disclosure under the nodes matched by $query, in order. */
function ev_states(DOMXPath $xp, string $query): array {
    $out = [];
    foreach ($xp->query($query . '//details[@data-evidence]') as $d) $out[] = $d->getAttribute('data-evidence');
    return $out;
}

const EV_ROW = ['quote' => null, 'status' => 'absent', 'reason' => null, 'part' => null, 'section' => null, 'occurrences' => null];

run_suite('requirement_evidence has the export shape', function () use ($db_path) {
    $pdo = new PDO('sqlite:' . $db_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cols = $pdo->query("SELECT name FROM pragma_table_info('requirement_evidence') ORDER BY cid")->fetchAll(PDO::FETCH_COLUMN);
    assert_same(['posting_id', 'role_key', 'ordinal', 'fact_kind', 'fact_index', 'fact_label', 'basis', 'quote', 'status',
        'reason', 'part', 'section', 'occurrences', 'source_revision', 'extraction_revision', 'text_hash', 'normalization'], $cols,
        'columns, in the export\'s order');
    $pk = $pdo->query("SELECT name FROM pragma_table_info('requirement_evidence') WHERE pk > 0 ORDER BY pk")->fetchAll(PDO::FETCH_COLUMN);
    assert_same(['posting_id', 'ordinal', 'fact_kind', 'fact_index'], $pk, 'primary key');
    $by = $pdo->query('SELECT status, COUNT(*) FROM requirement_evidence GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_true(($by['verified'] ?? 0) > 0 && ($by['unverified'] ?? 0) > 0 && ($by['absent'] ?? 0) > 0, 'fixture covers all three states');
    $keys = $pdo->query("SELECT r.role_key FROM requirement_evidence e JOIN posting_roles r
        ON r.posting_id = e.posting_id AND r.ordinal = e.ordinal AND r.role_key = e.role_key WHERE e.posting_id IN (1009, 1013)")->fetchAll();
    $n = (int)$pdo->query('SELECT COUNT(*) FROM requirement_evidence WHERE posting_id IN (1009, 1013) AND ordinal <> 9')->fetchColumn();
    assert_same($n, count($keys), 'every row\'s role_key is the role_key of its role in posting_roles');
});

run_suite('evidence_safe_url admits absolute http(s) URLs and nothing else', function () {
    foreach (['https://posturi.gov.ro/joburi/x/', 'http://example.org/a?b=c&d=e#f', 'HTTPS://EXAMPLE.ORG/'] as $ok) {
        assert_same($ok, evidence_safe_url($ok), "accepted: $ok");
    }
    foreach ([null, '', '   ', 'javascript:alert(1)', 'JaVaScRiPt:alert(1)', ' javascript:alert(1)', "java\tscript:alert(1)",
              'data:text/html,<script>alert(1)</script>', 'vbscript:x', 'ftp://example.org/', 'mailto:a@b.ro', '//evil.example/x',
              '/joburi/x/', 'posturi.gov.ro/x', 'https://', 'https:///nohost', 'https://exa mple.org/', 'https://a.b/"onmouseover="x',
              "https://a.b/'", 'https://a.b/<script>', "https://a.b/\x00", 'https://a.b/`x`'] as $bad) {
        assert_same(null, evidence_safe_url($bad), 'rejected: ' . var_export($bad, true));
    }
});

run_suite('evidence_disclosure: verified in the announcement text', function () {
    $row = ['quote' => 'diplomă de licență', 'status' => 'verified', 'part' => 'anunt',
            'section' => 'Condiții de participare', 'occurrences' => 1] + EV_ROW;
    $h = evidence_disclosure($row, 'https://posturi.gov.ro/joburi/x/', null, 'Studii: Licență');
    assert_well_formed($h, 'verified markup');
    assert_contains('<details', $h, 'native disclosure');
    assert_contains('data-evidence="verified"', $h);
    assert_contains('>Sursa ', $h, 'summary label');
    assert_contains('<blockquote', $h, 'verified: a blockquote');
    assert_contains('>diplomă de licență</blockquote>', $h, 'the quote');
    assert_contains('Găsit în: textul anunțului — secțiunea «Condiții de participare»', $h, 'where');
    assert_not_contains('apare de', $h, 'one occurrence says nothing about duplicates');
    assert_contains('href="https://posturi.gov.ro/joburi/x/"', $h, 'official link');
    assert_contains('>Anunțul oficial</a>', $h, 'link text does not claim verification');
    assert_contains('aria-label="Sursa: Studii: Licență"', $h, 'the summary names its fact for assistive tech');
    assert_not_contains('Documentul anunțului', $h, 'the attachment link belongs to attachment hits');

    $dup = evidence_disclosure(['occurrences' => 2] + $row, 'https://posturi.gov.ro/joburi/x/', null);
    assert_contains('apare de 2 ori', $dup, 'duplicates are reported');
    $nosec = evidence_disclosure(['section' => null] + $row, 'https://posturi.gov.ro/joburi/x/', null);
    assert_contains('Găsit în: textul anunțului', $nosec);
    assert_not_contains('secțiunea', $nosec, 'no section, none invented');
    assert_not_contains('aria-label', $nosec, 'no fact label, no aria-label');
});

run_suite('evidence_disclosure: verified in the attachments does not name a document or a page', function () {
    $row = ['quote' => 'permis de conducere categoria B', 'status' => 'verified', 'part' => 'anexe', 'occurrences' => 1] + EV_ROW;
    $h = evidence_disclosure($row, 'https://posturi.gov.ro/joburi/x/', 'https://posturi.gov.ro/doc/anunt.pdf');
    assert_contains('Găsit în: documentele atașate (documentul exact nu este identificat)', $h);
    assert_not_contains('pagina', $h, 'no page number, ever');
    assert_not_contains('secțiunea', $h, 'no section for attachments');
    assert_contains('>Anunțul oficial</a>', $h);
    assert_contains('href="https://posturi.gov.ro/doc/anunt.pdf"', $h, 'the announcement document link, when valid');
    assert_contains('>Documentul anunțului</a>', $h);

    $bad = evidence_disclosure($row, 'https://posturi.gov.ro/joburi/x/', 'javascript:alert(1)');
    assert_not_contains('javascript:', $bad, 'a javascript: announcement_url is not rendered');
    assert_not_contains('Documentul anunțului', $bad);
    $same = evidence_disclosure($row, 'https://posturi.gov.ro/doc/anunt.pdf', 'https://posturi.gov.ro/doc/anunt.pdf');
    assert_same(1, substr_count($same, '<a '), 'the same URL twice is one link');
});

run_suite('evidence_disclosure: unverified is labelled model output, never a quotation', function () {
    $row = ['quote' => 'vechime de cinci ani', 'status' => 'unverified', 'reason' => 'not_found'] + EV_ROW;
    $h = evidence_disclosure($row, 'https://posturi.gov.ro/joburi/x/', null);
    assert_well_formed($h);
    assert_contains('data-evidence="unverified"', $h);
    assert_contains('>Sursă neverificată ', $h, 'summary label');
    assert_not_contains('<blockquote', $h, 'NOT a blockquote');
    assert_contains('Textul citat de extragerea automată nu a fost regăsit în sursa curentă.', $h);
    assert_contains('<p class="mt-1 text-xs">Text extras automat (neverificat): vechime de cinci ani</p>', $h, 'plain labelled paragraph');
    assert_contains('>Anunțul oficial</a>', $h);
    assert_not_contains('Găsit în', $h, 'no location claimed');

    $short = evidence_disclosure(['reason' => 'too_short', 'quote' => 'Excel'] + $row, 'https://posturi.gov.ro/x/', null);
    assert_contains('Citatul este prea scurt pentru a fi verificat.', $short);
    assert_not_contains('nu a fost regăsit', $short, 'too short is not "not found"');
    assert_not_contains('<blockquote', $short);
    $stale = evidence_disclosure(['reason' => 'stale_revision'] + $row, 'https://posturi.gov.ro/x/', null);
    assert_contains('nu a fost regăsit în sursa curentă', $stale);
    assert_contains('versiune mai veche a anunțului', $stale, 'stale revision is explained');
    $none = evidence_disclosure(['reason' => 'no_source_text'] + $row, 'https://posturi.gov.ro/x/', null);
    assert_contains('Textul sursă nu este disponibil pentru verificare.', $none);
    assert_not_contains('<blockquote', $none . $stale);
});

run_suite('evidence_disclosure: absent says nothing about whether the requirement exists', function () {
    $h = evidence_disclosure(['reason' => 'no_evidence_field'] + EV_ROW, 'https://posturi.gov.ro/joburi/x/', null, 'Permis categoria B');
    assert_well_formed($h);
    assert_contains('data-evidence="absent"', $h);
    assert_contains('>Fără citat ', $h);
    assert_contains('Nu avem un citat din sursă pentru această cerință. Lipsa unui citat nu înseamnă că cerința nu există — verificați anunțul oficial.', $h);
    assert_not_contains('<blockquote', $h);
    assert_contains('>Anunțul oficial</a>', $h);
    assert_not_contains('neverificat', $h, 'absent is not unverified');
});

run_suite('evidence_disclosure: no row, an unknown status or a verified row without a quote render nothing', function () {
    $u = 'https://posturi.gov.ro/joburi/x/';
    assert_same('', evidence_disclosure(null, $u, null), 'no row');
    assert_same('', evidence_disclosure(['status' => 'confirmed'] + EV_ROW, $u, null), 'unknown status');
    assert_same('', evidence_disclosure(['status' => 'verified', 'quote' => '', 'part' => 'anunt'] + EV_ROW, $u, null), 'verified, no quote');
    assert_same('', evidence_disclosure(['status' => 'verified', 'quote' => 'ceva text lung aici', 'part' => null] + EV_ROW, $u, null), 'verified, no part');
    assert_same('', evidence_disclosure(['status' => 'verified', 'quote' => 'ceva text lung aici', 'part' => 'pagina 3'] + EV_ROW, $u, null), 'verified, invented part');
    assert_same('', evidence_fact_list([['kind' => 'skill', 'label' => 'x', 'row' => null]], $u, null), 'a list of nothing is nothing');
});

run_suite('evidence_disclosure: hostile text is escaped, hostile links are dropped', function () {
    $evil = FIXTURE_HOSTILE_QUOTE;
    foreach (['verified' => ['part' => 'anunt', 'section' => 'Titlu <b>hostil</b> "x"', 'occurrences' => 1],
              'unverified' => ['reason' => 'not_found'], 'absent' => []] as $status => $extra) {
        $row = ['quote' => $status === 'absent' ? null : $evil, 'status' => $status] + $extra + EV_ROW;
        foreach (['javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=', '"><script>alert(1)</script>'] as $url) {
            $h = evidence_disclosure($row, $url, $url, '"><script>alert(3)</script>');
            assert_well_formed($h, "$status / $url");
            assert_not_contains('<script', $h, "$status: no raw script");
            assert_not_contains('<img', $h, "$status: no raw img");
            assert_not_contains('<b>', $h, "$status: no raw b from the section");
            assert_not_contains('href=', $h, "$status: no href from $url");
            assert_not_contains_ci('javascript:', $h, "$status: no javascript: URL");
        }
        $h = evidence_disclosure($row, 'https://posturi.gov.ro/x/', null);
        if ($status !== 'absent') {
            assert_contains('&lt;script&gt;alert(&quot;edu&quot;)&lt;/script&gt; &quot;&gt;&lt;img src=x onerror=alert(2)&gt;', $h, "$status: escaped quote");
        }
        $xp = ev_xpath($h);
        assert_same(0, $xp->query('//script|//img|//*[@onerror]|//*[@onclick]')->length, "$status: the parsed markup has no active element");
    }
});

run_suite('detail 1009: each role shows its own evidence, in the right state, and never another role\'s', function () use ($base) {
    $html = ev_get($base, '/job/1009/');
    assert_not_contains('Fatal error', $html);
    $xp = ev_xpath($html);
    $roles = $xp->query('//*[@data-role]');
    assert_same(2, $roles->length, 'two roles');
    $r1 = $roles->item(0);
    $r2 = $roles->item(1);
    $t1 = ev_text($r1);
    $t2 = ev_text($r2);

    // role 1: education verified, experience unverified, skill verified (attachments), credential absent
    assert_same(['verified', 'unverified', 'verified', 'absent'], ev_states($xp, '(//*[@data-role])[1]'), 'role 1 states, in fact order');
    assert_same(['verified', 'absent', 'unverified', 'absent'], ev_states($xp, '(//*[@data-role])[2]'), 'role 2 states, in fact order');
    assert_contains(FIXTURE_ROLE1_QUOTE, $t1, 'role 1 shows its education quote');
    assert_contains('ROLE1-SKILL cunoștințe de contabilitate', $t1, 'role 1 shows its skill quote');
    assert_contains('ROLE1-EXP vechime de cinci ani în specialitate', $t1, 'role 1 shows its unverified experience text');
    assert_contains(FIXTURE_ROLE2_QUOTE, $t2, 'role 2 shows its education quote');
    assert_not_contains('ROLE2-UNIQUE', $t1, 'role 2\'s quote never appears under role 1');
    assert_not_contains('ROLE1-', $t2, 'role 1\'s quotes never appear under role 2');
    assert_not_contains('ROLE9-GHOST', $html, 'a row for an ordinal that is no role is never shown');
    assert_not_contains('Fantomă', $html);

    // labels
    assert_contains('Găsit în: textul anunțului — secțiunea «Condiții de participare»', $t1);
    assert_contains('Găsit în: documentele atașate (documentul exact nu este identificat)', $t1);
    assert_contains('apare de 2 ori', $t1, 'the duplicate attachment hit says so');
    assert_contains('Textul citat de extragerea automată nu a fost regăsit în sursa curentă.', $t1);
    assert_contains('Text extras automat (neverificat): ROLE1-EXP', $t1);
    assert_contains('Nu avem un citat din sursă pentru această cerință.', $t1);
    assert_contains('Citatul este prea scurt pentru a fi verificat.', $t2);
    assert_not_contains('secțiunea', $t2, 'role 2\'s hit has no section to name');

    // a quotation only for verified rows
    assert_same(3, $xp->query('//blockquote')->length, 'three verified rows, three quotations');
    assert_same(0, $xp->query('//details[@data-evidence="unverified"]//blockquote | //details[@data-evidence="absent"]//blockquote')->length, 'no quotation outside verified');
    assert_same(3, $xp->query('//details[@data-evidence="verified"]//blockquote')->length);
    assert_same(2, $xp->query('//details[@data-evidence="unverified"]//p[contains(., "Text extras automat (neverificat):")]')->length,
        'the model text of both unverified rows (not found, too short), labelled, in plain paragraphs');

    // summaries and facts
    $sums = [];
    foreach ($xp->query('//details[@data-evidence]/summary') as $s) $sums[] = preg_replace('/\s*▾$/u', '', ev_text($s));
    assert_same(['Sursa', 'Sursă neverificată', 'Sursa', 'Fără citat', 'Sursa', 'Fără citat', 'Sursă neverificată', 'Fără citat'], $sums, 'summary labels');
    $facts1 = [];
    foreach ($xp->query('.//li[@data-evidence-kind]/span[1]', $r1) as $f) $facts1[] = ev_text($f);
    assert_same(['Studii: Licență', 'Experiență: min. 5 ani, în specialitate', 'Contabilitate', 'Autorizație CECCAR'], $facts1,
        'the fact stays visible outside its disclosure');
    foreach ($xp->query('//details[@data-evidence]') as $d) {
        assert_false($d->hasAttribute('open'), 'closed until the reader opens it');
        assert_same('summary', $d->firstChild?->nodeName, 'native disclosure: summary is the first child');
    }

    // The flat "Cerințe structurate" block describes the posting, not a role: no evidence outside the roles.
    assert_same(0, $xp->query('//details[@data-evidence][not(ancestor::*[@data-role])]')->length, 'a decomposed posting\'s flat block shows no evidence');
    // old behaviour intact
    assert_contains('Experiență: min. 5 ani, în specialitate', $t1);
    assert_contains('Fără experiență', $t2);
});

run_suite('detail 1013: flat facts, escaped hostile text, no javascript: link', function () use ($base) {
    $html = ev_get($base, '/job/1013/');
    assert_not_contains('Fatal error', $html);
    $xp = ev_xpath($html);
    $kinds = [];
    foreach ($xp->query('//li[@data-evidence-kind]') as $li) $kinds[] = $li->getAttribute('data-evidence-kind');
    assert_same(['education', 'experience', 'skill', 'skill', 'skill', 'credential'], $kinds, 'one disclosure per fact, flat block order');
    assert_same(['verified', 'verified', 'verified', 'unverified', 'unverified', 'absent'], ev_states($xp, ''), 'states');

    // escaping: the quote, the section and the label are data
    assert_not_contains('<script>alert("edu")', $html, 'no raw script from the quote');
    assert_not_contains('<img src=x', $html, 'no raw img from the quote');
    assert_contains('&lt;script&gt;alert(&quot;edu&quot;)&lt;/script&gt; &quot;&gt;&lt;img src=x onerror=alert(2)&gt;', $html, 'the quote, escaped');
    assert_contains('secțiunea «Titlu &lt;b&gt;hostil&lt;/b&gt;»', $html, 'the section, escaped');
    assert_not_contains('Titlu <b>hostil</b>', $html);
    assert_contains('Word &lt;b&gt;', $html, 'the skill label, escaped');
    assert_not_contains('<script>x</script>', $html);
    assert_same(0, $xp->query('//img[@onerror] | //*[@onerror] | //details//script')->length, 'no active element built from source text');

    // links: the posting URL only; the javascript: announcement_url is not rendered anywhere
    foreach ($xp->query('//details[@data-evidence]//a') as $a) {
        assert_true(preg_match('#^https://posturi\.gov\.ro/anunt/fixture-1013$#', $a->getAttribute('href')) === 1, 'evidence links go to the official page: ' . $a->getAttribute('href'));
        assert_same('noopener noreferrer', $a->getAttribute('rel'), 'external link rel');
    }
    assert_same(0, $xp->query('//details//a[starts-with(translate(@href, "JAVSCRIPT", "javscript"), "javascript:")]')->length,
        'no javascript: link inside any evidence disclosure');
    // The sidebar's attachment list validates its links too (attachment_list()): the hostile
    // announcement_url must not become an href anywhere on the page.
    assert_same(0, $xp->query('//a[starts-with(translate(normalize-space(@href), "JAVSCRIPT", "javscript"), "javascript:")]')->length,
        'no javascript: link anywhere on the page');
    assert_not_contains('Documentul anunțului', $html, 'the unsafe announcement_url yields no link');
    assert_contains('Găsit în: documentele atașate (documentul exact nu este identificat)', $html, 'the attachment hit is still reported');

    // the facts are visible outside the disclosure, in the flat block
    $sec = $xp->query('//section[h2[contains(normalize-space(.), "Cerințe structurate")]]')->item(0);
    assert_true($sec !== null, 'the structured block');
    $labels = [];
    foreach ($xp->query('.//li[@data-evidence-kind]/span[1]', $sec) as $s) $labels[] = ev_text($s);
    assert_same(['Excel', 'Word <b>', 'GIS', 'Permis categoria B'], $labels, 'skill and credential labels sit beside their disclosure');
    // Education and experience are the one fact of their own section: the section shows the fact, the summary names it.
    assert_same(['Sursa: Studii: Licență', 'Sursa: Experiență: min. 3 ani, în specialitate'],
        array_map(fn($n) => $n->getAttribute('aria-label'), iterator_to_array($xp->query('.//li[@data-evidence-kind="education" or @data-evidence-kind="experience"]/details/summary', $sec))),
        'single-fact sections: no repeated label, the disclosure is still named for assistive tech');
    assert_same(0, $xp->query('.//li[@data-evidence-kind="education"]/span', $sec)->length, 'no repeated education label');
    $exp = $xp->query('.//*[@data-flat-experience]', $sec)->item(0);
    assert_same('Experiență', ev_text($xp->query('./div[1]', $exp)->item(0)), 'experience heading');
    assert_same('Min. 3 ani, în specialitate', ev_text($xp->query('./div[2]', $exp)->item(0)), 'experience is now shown beside its source');
    assert_same(1, $xp->query('//*[@data-flat-experience]')->length);

    // the bare hover quote on a skill chip is replaced by the disclosure where a row exists, kept where none does
    assert_not_contains('title="FLAT-SKILL-EVIDENCE-RAW', $html, 'no unlabelled model quote as a tooltip beside the disclosure');
    assert_contains('title="doar în schemă"', $html, 'a skill with no evidence row keeps its old tooltip');
    assert_true($xp->query('//a[@title="doar în schemă"]')->length === 1);
});

run_suite('postings without evidence rows render no disclosures', function () use ($base) {
    foreach (['/job/1008/', '/job/1001/', '/job/1010/', '/job/1011/', '/job/1012/'] as $path) {
        $html = ev_get($base, $path);
        assert_not_contains('Fatal error', $html, $path);
        assert_not_contains('data-evidence', $html, "$path: no disclosure without rows");
        assert_not_contains('Sursa', $html, "$path: no label without rows");
    }
    $html = ev_get($base, '/job/1008/');
    assert_contains('Cerințe structurate', $html, '1008 still shows its structured block');
    assert_contains('Posturi scoase la concurs (2)', $html, '1008 still shows its roles');
    assert_not_contains('data-flat-experience', $html, 'a decomposed posting has no flat experience line');
    // undecomposed v3 posting without an experience block: no new line
    assert_not_contains('data-flat-experience', ev_get($base, '/job/1001/'));
});

run_suite('server log is clean', function () use ($srv) {
    $log = (string)@file_get_contents($srv['log']);
    foreach (['Fatal', 'Warning', 'Notice', 'Deprecated', 'no such'] as $bad) assert_not_contains($bad, $log, "log: $bad");
});

// ---- An export that predates the table --------------------------------------------------------
$old_db = sys_get_temp_dir() . '/posturi-evidence-old-' . getmypid() . '.sqlite';
copy($db_path, $old_db);
$o = new PDO('sqlite:' . $old_db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$o->exec('DROP TABLE requirement_evidence');
unset($o);
$old_srv = start_fixture_server($old_db);
$ob = $old_srv['base'];

run_suite('old export (no requirement_evidence): an empty temp view stands in and every page renders', function () use ($old_db, $ob) {
    $pdo = new PDO('sqlite:' . $old_db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    shim_legacy_export($pdo);
    $views = $pdo->query("SELECT name FROM sqlite_temp_master WHERE type = 'view' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    assert_same(['requirement_evidence'], $views, 'only the missing table is stood in for');
    assert_same(0, (int)$pdo->query('SELECT COUNT(*) FROM requirement_evidence')->fetchColumn(), 'no rows');
    $cols = $pdo->query("SELECT name FROM pragma_table_info('requirement_evidence') ORDER BY cid")->fetchAll(PDO::FETCH_COLUMN);
    assert_same(['posting_id', 'role_key', 'ordinal', 'fact_kind', 'fact_index', 'fact_label', 'basis', 'quote', 'status',
        'reason', 'part', 'section', 'occurrences', 'source_revision', 'extraction_revision', 'text_hash', 'normalization'], $cols,
        'the view has the table\'s columns');
    assert_same([], load_requirement_evidence($pdo, 1009), 'the loader returns nothing');

    foreach (['/job/1009/', '/job/1013/', '/job/1008/', '/job/1001/', '/', '/posturi.json'] as $path) {
        $html = ev_get($ob, $path);
        assert_true($html !== '', "$path answers");
        assert_not_contains('Fatal error', $html, $path);
        assert_not_contains('no such', $html, $path);
        assert_not_contains('data-evidence', $html, "$path: no disclosure on an old export");
    }
    // The page is what it was: facts visible, the old tooltip back on the skill chip.
    $h = ev_get($ob, '/job/1009/');
    $xp = ev_xpath($h);
    assert_same(2, $xp->query('//*[@data-role]')->length, 'roles still render');
    assert_contains('Documente: Autorizație CECCAR', ev_text($xp->query('//*[@data-role]')->item(0)));
    assert_not_contains('ROLE1-UNIQUE', $h, 'no evidence text without the table');
    $h13 = ev_get($ob, '/job/1013/');
    assert_contains('title="FLAT-SKILL-EVIDENCE-RAW cunoștințe Excel"', $h13, 'old export: the old tooltip, unchanged');
});

run_suite('old export: server log is clean', function () use ($old_srv) {
    $log = (string)@file_get_contents($old_srv['log']);
    foreach (['Fatal', 'Warning', 'Notice', 'Deprecated', 'no such'] as $bad) assert_not_contains($bad, $log, "log: $bad");
});

run_suite('a current export without evidence rows is not shimmed', function () {
    $db = sys_get_temp_dir() . '/posturi-evidence-cur-' . getmypid() . '.sqlite';
    build_fixture_db($db, true);
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    shim_legacy_export($pdo);
    assert_same(0, (int)$pdo->query("SELECT COUNT(*) FROM sqlite_temp_master WHERE type = 'view'")->fetchColumn(), 'the table exists, empty: no view');
    assert_same([], load_requirement_evidence($pdo, 1009), 'and no evidence');
    unset($pdo);
    @unlink($db);
});

stop_fixture_server($old_srv, $old_db);
stop_fixture_server($srv, $db_path);
finish();
