<?php
/**
 * UX-10 — the "Pot aplica?" panel: what the SERVER prepares (the comparison runs in the browser,
 * static/eligibility.js, and is covered by tests/browser/eligibility.spec.js).
 *
 * Checked here, against the fixture served on the built-in router (evidence fixture + postings
 * 1040-1044, tests/fixtures/eligibility_db.php):
 *   - the panel markup: collapsed <details>, the disclaimer and official link, the no-JavaScript
 *     note, the form hidden until JavaScript shows it, placed after the structured requirements;
 *   - the embedded JSON carries each role's own values and nothing of another role's, never the
 *     model's quote, and survives hostile text (a title cannot close its <script>);
 *   - there is no <form>, no control with a form owner, and no `name` but the radio groups';
 *   - evidence anchors: every id the JSON points at exists once on the page, and the optional id
 *     of evidence_disclosure() adds an attribute and changes nothing else;
 *   - the helpers: alternatives, specialty mentions, evidence state, the study-level order (against
 *     schema_models.StudyLevel);
 *   - a posting with nothing comparable, an export without posting_roles / requirement_evidence:
 *     the panel is omitted or degrades to "no evidence", with no error;
 *   - static guards on eligibility.js (no storage, network or HTML injection) and token-only styles.
 *
 * Usage: php tests/eligibility_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../inc/eligibility.php';
require_once __DIR__ . '/fixtures/eligibility_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');
putenv('POSTURI_ELIGIBILITY_PANEL=1');   // the panel ships off (release gate); test it on

$db_path = sys_get_temp_dir() . '/posturi-eligibility-' . getmypid() . '.sqlite';
build_eligibility_db($db_path);
$srv = start_fixture_server($db_path);
$base = $srv['base'];

function el_get(string $base, string $path): string {
    return (string)@file_get_contents($base . $path);
}

function el_xpath(string $html): DOMXPath {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($dom);
}

/** The embedded data of a page, decoded, or null when the page has no panel. */
function el_data(string $html): ?array {
    if (!preg_match('#<script type="application/json" id="eligibility-data">(.*?)</script>#s', $html, $m)) return null;
    $d = json_decode($m[1], true);
    return is_array($d) ? $d : null;
}

/** A copy of the fixture with tables dropped, served on its own port; the callback gets the base URL. */
function el_with_dropped(string $src, array $tables, callable $fn): void {
    $copy = sys_get_temp_dir() . '/posturi-eligibility-old-' . getmypid() . '-' . count($tables) . '.sqlite';
    copy($src, $copy);
    $pdo = new PDO('sqlite:' . $copy, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ($tables as $t) $pdo->exec("DROP TABLE $t");
    $pdo = null;
    $s = start_fixture_server($copy);
    try {
        $fn($s['base'], $s);
    } finally {
        stop_fixture_server($s, $copy);
    }
}

run_suite('study levels: one ordered list, the same as the extraction schema', function () {
    assert_same(['generala', 'profesionala', 'liceala', 'postliceala', 'licenta', 'master', 'doctorat'],
        array_keys(ELIGIBILITY_STUDY_LEVELS), 'rank order');
    $py = (string)@file_get_contents(__DIR__ . '/../../schema_models.py');
    assert_true(preg_match('/StudyLevel = Literal\[(.*?)\]/s', $py, $m) === 1, 'schema_models.StudyLevel found');
    preg_match_all('/"([a-z]+)"/', $m[1] ?? '', $names);
    assert_same(array_keys(ELIGIBILITY_STUDY_LEVELS), $names[1], 'the panel orders levels exactly as StudyLevel lists them');
    assert_same(['Generală', 'Profesională', 'Liceală', 'Postliceală', 'Licență', 'Master', 'Doctorat'],
        array_values(ELIGIBILITY_STUDY_LEVELS), 'labels');
});

run_suite('eligibility_has_alternatives: a standalone sau / ori, nothing else', function () {
    foreach (['licență sau echivalent', 'studii superioare SAU medii', 'vechime ori în specialitate', 'licență și/sau master',
              'sau', 'în specialitate, ori în domeniu', "2 ani\nsau 3 ani", 'a (sau b)'] as $t) {
        assert_true(eligibility_has_alternatives($t), "alternatives: $t");
    }
    foreach (['licență', 'ordine', 'orice domeniu', 'sauna', 'priori', 'asaurora', '', 'vechime de 3 ani în specialitate'] as $t) {
        assert_false(eligibility_has_alternatives($t), "not alternatives: '$t'");
    }
    assert_false(eligibility_has_alternatives(null), 'null');
    assert_true(eligibility_has_alternatives('licență', 'sau echivalent'), 'any of several texts');
    assert_false(eligibility_has_alternatives('licență', ''), 'none of several texts');
    assert_true(eligibility_mentions_specialty('3 ani în specialitatea postului'), 'specialty');
    assert_true(eligibility_mentions_specialty('vechime în domeniul economic'), 'domain');
    assert_false(eligibility_mentions_specialty('3 ani vechime în muncă'), 'general work');
});

run_suite('eligibility_evidence_state: only a located quote counts as verified', function () {
    $row = ['ordinal' => 1, 'fact_kind' => 'education', 'fact_index' => 0, 'status' => 'verified', 'reason' => null,
            'quote' => 'diplomă de licență', 'part' => 'anunt'];
    assert_same(['status' => 'verified', 'reason' => null, 'anchor' => 'ev-1-education-0'], eligibility_evidence_state($row), 'verified');
    assert_same('none', eligibility_evidence_state(null)['status'], 'no row');
    assert_same('none', eligibility_evidence_state(['quote' => ''] + $row)['status'], 'verified without a quote is malformed');
    assert_same('none', eligibility_evidence_state(['part' => null] + $row)['status'], 'verified without a part is malformed');
    assert_same('none', eligibility_evidence_state(['part' => 'pagina'] + $row)['status'], 'unknown part');
    assert_same('none', eligibility_evidence_state(['status' => 'altceva'] + $row)['status'], 'unknown status');
    $un = eligibility_evidence_state(['status' => 'unverified', 'reason' => 'not_found', 'quote' => 'x', 'part' => null] + $row);
    assert_same(['status' => 'unverified', 'reason' => 'not_found', 'anchor' => 'ev-1-education-0'], $un, 'unverified keeps its reason');
    $abs = eligibility_evidence_state(['status' => 'absent', 'reason' => '', 'quote' => null, 'part' => null] + $row);
    assert_same('absent', $abs['status']);
    assert_same(null, $abs['reason'], 'an empty reason is no reason');
    $nokey = eligibility_evidence_state(['status' => 'absent', 'quote' => null]);
    assert_same(null, $nokey['anchor'], 'a row that carries no key has no anchor');
});

run_suite('evidence anchors: the optional id is the only change to a disclosure', function () {
    $row = ['quote' => 'diplomă de licență', 'status' => 'verified', 'part' => 'anunt', 'section' => null, 'occurrences' => 1, 'reason' => null,
            'ordinal' => 2, 'fact_kind' => 'credential', 'fact_index' => 3];
    $plain = evidence_disclosure($row, 'https://posturi.gov.ro/x/', null, 'Studii');
    $with  = evidence_disclosure($row, 'https://posturi.gov.ro/x/', null, 'Studii', 'ev-2-credential-3');
    assert_not_contains(' id=', $plain, 'no id unless asked');
    assert_contains(' id="ev-2-credential-3"', $with);
    assert_same($plain, str_replace(' id="ev-2-credential-3"', '', $with), 'identical apart from the id');
    assert_same('ev-2-credential-3', evidence_anchor_id($row));
    assert_same('', evidence_anchor_id(null));
    assert_same('', evidence_anchor_id(['ordinal' => 1]), 'incomplete key');
    assert_same('ev-0-skill-12', evidence_anchor_id(['ordinal' => '0', 'fact_kind' => 'SKILL', 'fact_index' => '12']));
    assert_same('', evidence_anchor_id(['ordinal' => 1, 'fact_kind' => '123', 'fact_index' => 0]), 'a kind with no letters yields nothing');
    $list = evidence_fact_list([['kind' => 'credential', 'label' => 'Permis', 'row' => $row]], 'https://posturi.gov.ro/x/', null);
    assert_contains('id="ev-2-credential-3"', $list, 'the list passes the row\'s id to its disclosure');
    $list2 = evidence_fact_list([['kind' => 'credential', 'label' => 'Permis', 'row' => array_diff_key($row, ['ordinal' => 1])]], 'https://posturi.gov.ro/x/', null);
    assert_not_contains(' id=', $list2, 'a synthetic row without a key gets no id');
});

run_suite('panel markup on a decomposed posting', function () use ($base) {
    $h = el_get($base, '/job/1040/');
    assert_contains('<details data-eligibility', $h, 'native disclosure');
    assert_not_contains('<details data-eligibility open', $h, 'collapsed by default');
    assert_contains('Pot aplica? — verificare orientativă', $h, 'summary');
    assert_contains('Un ajutor de pregătire, nu o decizie. Doar angajatorul, pe baza anunțului oficial, stabilește cine este admis.', $h);
    assert_contains('href="https://posturi.gov.ro/anunt/fixture-1040"', $h, 'official link');
    assert_contains('Comparația funcționează în browser, cu JavaScript activat. Cerințele din sursă sunt listate mai sus.', $h, 'no-JS note');
    assert_contains('data-eligibility-nojs', $h);
    assert_true(preg_match('/<div data-eligibility-app hidden[ >]/', $h) === 1, 'the form is hidden until JavaScript shows it');
    assert_same(1, substr_count($h, 'id="eligibility-data"'), 'one data block');
    assert_same(1, substr_count($h, '<details data-eligibility'), 'one panel');
    assert_true(preg_match('#<script src="/static/eligibility\.js\?v=\d+" defer></script>#', $h) === 1, 'the script is deferred');
    foreach (['Postul comparat', 'Nivelul dvs. de studii', 'Ani de experiență, în total', 'Ani în specialitatea postului (opțional)',
              'Resetează răspunsurile', 'Comparație pe cerințe', 'nu sunt trimise, salvate sau puse în adresă'] as $t) {
        assert_contains($t, $h, $t);
    }
    // After the structured requirements, before the rest of the page.
    $req = strpos($h, 'Cerințe structurate');
    $panel = strpos($h, 'data-eligibility-section');
    // The closing disclaimer (the last of the two robots_disclaimer() lines) ends the page body.
    $cal = strrpos($h, 'restructurate cu');
    assert_true($req !== false && $panel !== false && $cal !== false && $req < $panel && $panel < $cal, 'placed after the requirements');
    // The "requirements remain visible" half: the same page lists the roles and their facts outside the panel.
    assert_contains('Inspector superior', substr($h, $req, $panel - $req), 'role listed above the panel');
    assert_contains('Experiență: min. 5 ani, în specialitate', substr($h, $req, $panel - $req), 'role experience listed above the panel');
    // One template per state, with semantic token classes (text carries the meaning, the colour reinforces it).
    foreach (['match' => ['border-ok-line bg-ok text-ok-ink', 'Îndeplinit'], 'missing' => ['border-alert-line bg-alert text-alert-ink', 'Lipsește'],
              'unknown' => ['border-note-line bg-note text-note-ink', 'De verificat']] as $state => [$classes, $text]) {
        assert_true(preg_match('#<template data-row-template="' . $state . '">\s*<li data-row class="[^"]*' . preg_quote($classes, '#') . '#', $h) === 1, "$state template classes");
        assert_true(preg_match('#data-row-template="' . $state . '">.*?' . preg_quote($text, '#') . '</span>#s', $h) === 1, "$state template text");
    }
    assert_contains('aria-live="polite"', $h, 'live region');
    assert_well_formed(substr($h, (int)strpos($h, '<section class="mb-6" data-eligibility-section>'), 200), 'panel start');
});

run_suite('embedded data: each role carries its own values and nothing of another role\'s', function () use ($base) {
    $h = el_get($base, '/job/1040/');
    $d = el_data($h);
    assert_true($d !== null, 'JSON decodes');
    assert_same(1, $d['v']);
    assert_same(array_keys(ELIGIBILITY_STUDY_LEVELS), array_column($d['levels'], 'value'), 'levels in rank order');
    assert_same(2, count($d['roles']), 'two roles');
    [$a, $b] = $d['roles'];

    assert_same(1, $a['ordinal']);
    assert_same(false, $a['implicit']);
    assert_same('Inspector superior', $a['title']);
    assert_same('licenta', $a['study_level']);
    assert_same(['Economie'], $a['isced']);
    assert_same(5.0, (float)$a['exp_years']);
    assert_same(1, $a['exp_in_specialty']);
    assert_same(0, $a['exp_none_required']);
    assert_same(['Autorizație CECCAR', 'Atestat de limba engleză'], array_column($a['credentials'], 'label'), 'exact source labels, in order');
    assert_same(['autorizatie', 'certificat_profesional'], array_column($a['credentials'], 'kind'));
    assert_same(['verified', 'ev-1-education-0'], [$a['evidence']['education']['status'], $a['evidence']['education']['anchor']]);
    assert_same(['verified', 'ev-1-experience-0'], [$a['evidence']['experience']['status'], $a['evidence']['experience']['anchor']]);
    assert_same(['absent', 'no_evidence_field'], [$a['credentials'][0]['evidence']['status'], $a['credentials'][0]['evidence']['reason']], 'credentials have no evidence field');
    assert_same(['ev-1-credential-0', 'ev-1-credential-1'], array_column(array_column($a['credentials'], 'evidence'), 'anchor'));
    assert_same(['education' => false, 'experience' => false], $a['alternatives']);

    assert_same(2, $b['ordinal']);
    assert_same('Referent', $b['title']);
    assert_same('liceala', $b['study_level']);
    assert_same([], $b['isced'], 'role 2 has no field; role 1\'s is not inherited');
    assert_same(0.0, (float)$b['exp_years']);
    assert_same(1, $b['exp_none_required']);
    assert_same(0, $b['exp_in_specialty']);
    assert_same(['Permis categoria B'], array_column($b['credentials'], 'label'));
    assert_same('ev-2-education-0', $b['evidence']['education']['anchor']);
    assert_same('ev-2-credential-0', $b['credentials'][0]['evidence']['anchor']);

    // Never mixed: a role's JSON mentions none of the other's own text.
    $ja = json_encode($a, JSON_UNESCAPED_UNICODE);
    $jb = json_encode($b, JSON_UNESCAPED_UNICODE);
    foreach (['Referent', 'Permis categoria B', 'ev-2-'] as $x) assert_not_contains($x, $ja, "role 1 data has no '$x'");
    foreach (['Inspector', 'CECCAR', 'Economie', 'engleză', 'ev-1-'] as $x) assert_not_contains($x, $jb, "role 2 data has no '$x'");
    // The model's own sentence is not duplicated into the page's data: the disclosure it links to shows it.
    assert_not_contains('ELIG-R1', $h === '' ? '' : (string)substr($h, (int)strpos($h, 'id="eligibility-data"'), 6000), 'quotes are not embedded');
    // The 1009 evidence row for an ordinal that is no role of the posting (9) never reaches the data.
    $d9 = el_data(el_get($base, '/job/1009/'));
    assert_same([1, 2], array_column($d9['roles'], 'ordinal'), '1009: roles from posting_roles only');
    assert_not_contains('GHOST', json_encode($d9), 'no row of a phantom ordinal');
});

run_suite('every anchor the data points at exists once on the page, on a disclosure of that role', function () use ($base) {
    foreach ([1040, 1041, 1042, 1044, 1013, 1009] as $id) {
        $h = el_get($base, "/job/$id/");
        $d = el_data($h);
        assert_true($d !== null, "$id has a panel");
        $xp = el_xpath($h);
        $ids = [];
        foreach ($xp->query('//*[@id]') as $n) $ids[] = $n->getAttribute('id');
        assert_same(array_values(array_unique($ids)), $ids, "$id: ids are unique");
        $anchors = [];
        foreach ($d['roles'] as $r) {
            foreach (['education', 'experience'] as $k) if ($r['evidence'][$k]['anchor']) $anchors[$r['ordinal']][] = $r['evidence'][$k]['anchor'];
            foreach ($r['credentials'] as $c) if ($c['evidence']['anchor']) $anchors[$r['ordinal']][] = $c['evidence']['anchor'];
        }
        assert_true($anchors !== [], "$id has anchors to check");
        foreach ($anchors as $ordinal => $list) {
            foreach ($list as $a) {
                assert_true(in_array($a, $ids, true), "$id: #$a exists on the page");
                assert_true(str_starts_with($a, "ev-$ordinal-"), "$id: #$a belongs to role $ordinal");
                $n = $xp->query('//details[@id="' . $a . '"][@data-evidence]');
                assert_same(1, $n->length, "$id: #$a is an evidence disclosure");
            }
        }
    }
});

run_suite('the controls: no form, nothing submittable, named only where a radio group needs it', function () use ($base) {
    $h = el_get($base, '/job/1040/');
    $xp = el_xpath($h);
    $panel = '//details[@data-eligibility]';
    assert_same(0, $xp->query("$panel//form")->length, 'no <form> in the panel');
    assert_same(0, $xp->query("$panel//*[@type='submit']")->length, 'no submit control');
    assert_same(0, $xp->query("$panel//button[not(@type='button')]")->length, 'every button is type=button');
    assert_same(0, $xp->query("//form[.//*[@data-eligibility-app]]")->length, 'the panel is not inside a form');
    foreach ($xp->query("$panel//input | $panel//select | $panel//textarea") as $c) {
        assert_same('off', $c->getAttribute('autocomplete'), 'autocomplete=off on ' . $c->getAttribute('id') . $c->getAttribute('value'));
        if ($c->getAttribute('type') !== 'radio') assert_false($c->hasAttribute('name'), 'no name on ' . $c->nodeName . '#' . $c->getAttribute('id'));
    }
    $names = [];
    foreach ($xp->query("$panel//*[@name]") as $n) {
        assert_same('radio', $n->getAttribute('type'), 'a named control is a radio');
        $names[$n->getAttribute('name')][] = $n->getAttribute('value');
    }
    assert_same(['elig-0-0', 'elig-0-1', 'elig-1-0'], array_keys($names), 'one name per credential group');
    foreach ($names as $values) assert_same(['da', 'nu', 'nu_stiu'], $values, 'Da / Nu / Nu știu');
    assert_same(3, $xp->query("$panel//input[@type='radio'][@value='nu_stiu'][@checked]")->length, '"Nu știu" is the default of every group');

    // The selector and the education options.
    assert_same(2, $xp->query("$panel//select[@id='elig-role']/option")->length);
    assert_same('1. Inspector superior', trim($xp->query("$panel//select[@id='elig-role']/option")->item(0)->textContent));
    assert_same('2. Referent', trim($xp->query("$panel//select[@id='elig-role']/option")->item(1)->textContent));
    $opts = [];
    foreach ($xp->query("$panel//select[@id='elig-edu']/option") as $o) $opts[] = trim($o->textContent);
    assert_same(['Nu știu', 'Generală', 'Profesională', 'Liceală', 'Postliceală', 'Licență', 'Master', 'Doctorat'], $opts, 'education options');
    foreach (['elig-total', 'elig-spec'] as $id) {
        $n = $xp->query("$panel//input[@id='$id']")->item(0);
        assert_same(['number', '0', '0.5'], [$n->getAttribute('type'), $n->getAttribute('min'), $n->getAttribute('step')], "$id is a number >= 0 in halves");
    }
    // Credential groups: role 1's visible, role 2's hidden; the legend is "Dețin: <exact label>".
    assert_same(0, $xp->query("$panel//div[@data-credentials-for='0'][@hidden]")->length);
    assert_same(1, $xp->query("$panel//div[@data-credentials-for='1'][@hidden]")->length);
    $legends = [];
    foreach ($xp->query("$panel//fieldset/legend") as $l) $legends[] = trim($l->textContent);
    assert_same(['Dețin: Autorizație CECCAR', 'Dețin: Atestat de limba engleză', 'Dețin: Permis categoria B'], $legends);
    // A posting with a single (implicit) role has no selector.
    $one = el_get($base, '/job/1013/');
    assert_same(0, el_xpath($one)->query("//select[@id='elig-role']")->length, 'no role selector for one role');
});

run_suite('an undecomposed posting is one implicit role, from its own flat facts', function () use ($base) {
    $d = el_data(el_get($base, '/job/1013/'));
    assert_same(1, count($d['roles']));
    $r = $d['roles'][0];
    assert_same([0, true, 'Postul'], [$r['ordinal'], $r['implicit'], $r['title']]);
    assert_same('licenta', $r['study_level']);
    assert_same(['Economie'], $r['isced'], 'the schema\'s own label_ro');
    assert_same([3.0, 1], [(float)$r['exp_years'], $r['exp_in_specialty']]);
    assert_same(['Permis categoria B'], array_column($r['credentials'], 'label'));
    assert_same('ev-0-education-0', $r['evidence']['education']['anchor']);
    assert_same('verified', $r['evidence']['experience']['status'], 'attachment hit counts as verified');
});

run_suite('evidence states, alternatives, advantages and the specialty flag reach the data per role', function () use ($base) {
    $d = el_data(el_get($base, '/job/1042/'));
    [$r1, $r2, $r3, $r4] = $d['roles'];
    // Explicit zero: stated, verified, distinct from absent.
    assert_same([0.0, 1, 'verified'], [(float)$r1['exp_years'], $r1['exp_none_required'], $r1['evidence']['experience']['status']], 'role 1: stated zero');
    // Not stated at all: null, no evidence row.
    assert_same([null, null, 'none'], [$r2['exp_years'], $r2['exp_none_required'], $r2['evidence']['experience']['status']], 'role 2: experience not stated');
    assert_same(['unverified', 'not_found'], [$r2['evidence']['education']['status'], $r2['evidence']['education']['reason']], 'role 2: education not found');
    assert_same(null, $r2['evidence']['experience']['anchor'], 'no row, no anchor');
    // Alternatives come from the quote, per fact.
    assert_same(['education' => true, 'experience' => false], $r3['alternatives'], 'role 3: "sau echivalente"');
    assert_same(null, $r3['exp_in_specialty'], 'role 3: specialty not stated');
    assert_same(['education' => false, 'experience' => true], $r4['alternatives'], 'role 4: "ori în domeniul conex"');
    assert_same(['education' => false, 'experience' => false], $r1['alternatives']);

    $t = el_data(el_get($base, '/job/1044/'));
    [$t1, $t2, $t3] = $t['roles'];
    assert_same([0, false], [$t1['exp_in_specialty'], $t1['exp_specialty_conflict']], 'role 1: general experience, sentence agrees');
    assert_same([0, true], [$t2['exp_in_specialty'], $t2['exp_specialty_conflict']], 'role 2: flag says no, the sentence says "în specialitatea postului"');
    assert_same(true, $t3['advantage']['education'], 'role 3: "required": false is an advantage');
    assert_same([true, false], [$t3['credentials'][0]['advantage'], $t3['credentials'][0]['alternatives']], 'advantage document');
    assert_same([false, true], [$t3['credentials'][1]['advantage'], $t3['credentials'][1]['alternatives']], '"sau" inside a document label');
    assert_same(false, $t1['advantage']['education']);

    $e = el_data(el_get($base, '/job/1041/'));
    assert_same([['Drept'], ['Inginerie civilă']], array_column($e['roles'], 'isced'), '1041: same level, different fields, each its own');
    assert_same(['licenta', 'licenta'], array_column($e['roles'], 'study_level'));
});

run_suite('hostile text cannot break out of the embedded JSON or the page', function () use ($base) {
    $h = el_get($base, '/job/1043/');
    assert_not_contains('</script><script>window.__pwned', $h, 'a title cannot close the data script');
    assert_not_contains('<script>window.__pwned', $h);
    assert_not_contains('<img src=x onerror', $h, 'no injected element anywhere on the page');
    // json_encode(JSON_HEX_TAG) writes < and > as backslash-u003C / backslash-u003E (built here so the file holds no escape).
    assert_contains(str_replace('@', chr(92), '@u003C@/script@u003E@u003Cscript@u003Ewindow.__pwned=1'), $h, 'the title is escaped inside the JSON');
    $d = el_data($h);
    assert_true($d !== null, 'the JSON still parses');
    assert_same('</script><script>window.__pwned=1</script> & "Rol" \'unu\'', $d['roles'][0]['title'], 'and round-trips exactly');
    assert_same('</script><img src=x onerror=window.__pwned=2> "Atestat" & \'altul\'', $d['roles'][0]['credentials'][0]['label']);
    $xp = el_xpath($h);
    assert_same(1, $xp->query("//section[@data-eligibility-section]//script")->length, 'one script element in the panel: the data');
    assert_same(0, $xp->query("//details[@data-eligibility]//img")->length, 'no injected image');
    // The option and the legend carry the text, escaped.
    assert_contains('1. &lt;/script&gt;&lt;script&gt;window.__pwned=1&lt;/script&gt; &amp; &quot;Rol&quot; ', $h, 'option text is escaped');
    assert_contains('Dețin: &lt;/script&gt;&lt;img src=x onerror=window.__pwned=2&gt;', $h, 'legend text is escaped');
});

run_suite('a posting that states nothing comparable gets no panel, and renders normally', function () use ($base) {
    $h = el_get($base, '/job/1004/');
    assert_contains('<h1', $h, 'the page renders');
    assert_not_contains('data-eligibility', $h, 'no panel');
    assert_not_contains('eligibility.js', $h, 'and no script for it');
    assert_not_contains('eligibility-data', $h);
    assert_not_contains('Warning:', $h);
    assert_not_contains('Fatal error', $h);
});

run_suite('an export without requirement_evidence: the panel works, with no evidence to rely on', function () use ($db_path) {
    el_with_dropped($db_path, ['requirement_evidence'], function (string $base) {
        $h = el_get($base, '/job/1040/');
        assert_not_contains('Warning:', $h);
        assert_not_contains('Fatal error', $h);
        $d = el_data($h);
        assert_true($d !== null, 'the panel is still offered');
        foreach ($d['roles'] as $r) {
            assert_same('none', $r['evidence']['education']['status'], 'no row is "none", never "absent" or "verified"');
            assert_same(null, $r['evidence']['education']['anchor']);
            foreach ($r['credentials'] as $c) assert_same('none', $c['evidence']['status']);
        }
        assert_same(['licenta', 'liceala'], array_column($d['roles'], 'study_level'), 'role values are unaffected');
        assert_not_contains('data-evidence="', $h, 'and the page shows no disclosure');
    });
});

run_suite('an export without posting_roles (and without requirement_evidence): no panel, no error', function () use ($db_path) {
    el_with_dropped($db_path, ['posting_roles', 'requirement_evidence'], function (string $base) {
        foreach ([1040, 1013, 1009, 1004] as $id) {
            $h = el_get($base, "/job/$id/");
            assert_contains('<h1', $h, "$id renders");
            assert_not_contains('Warning:', $h, "$id");
            assert_not_contains('Fatal error', $h, "$id");
            assert_not_contains('data-eligibility', $h, "$id: the stand-in role rows (flat columns) are not a role's facts, so no panel");
            assert_not_contains('eligibility-data', $h, "$id");
        }
        // The rest of the page is as before: the role blocks and facts are listed.
        assert_contains('Inspector superior', el_get($base, '/job/1040/'));
    });
});

run_suite('static guards on eligibility.js: no storage, no network, no HTML injection, one policy switch', function () {
    $js = (string)file_get_contents(__DIR__ . '/../static/eligibility.js');
    // Strip block and line comments (they explain what the file does NOT do).
    $code = preg_replace('#/\*.*?\*/#s', '', $js) ?? '';
    $code = preg_replace('#(^|[^:\'"])//[^\n]*#', '$1', $code) ?? '';
    foreach (['localStorage', 'sessionStorage', 'indexedDB', 'document.cookie', 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'WebSocket',
              'EventSource', 'history.', 'location.', 'window.open', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write',
              'eval(', 'new Function', 'import(', 'importScripts', 'postMessage'] as $bad) {
        assert_not_contains($bad, $code, "eligibility.js must not use $bad");
    }
    assert_same(1, substr_count($js, 'var REQUIRE_VERIFIED_EVIDENCE = true;'), 'the policy switch is one constant, shipped strict');
    assert_same(1, preg_match_all('/REQUIRE_VERIFIED_EVIDENCE\s*=[^=]/', $code), 'assigned once: nothing else sets the policy');
});

run_suite('styles are tokens: no raw palette class and no colour literal in the panel or its script', function () {
    foreach (['partials/eligibility_panel.php', 'static/eligibility.js', 'inc/eligibility.php'] as $f) {
        $src = (string)file_get_contents(__DIR__ . '/../' . $f);
        assert_same(0, preg_match('/\b(?:bg|text|border|ring|fill|stroke|divide|outline|accent|from|to|via)-(?:slate|gray|grey|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/', $src), "$f: no raw palette class");
        assert_same(0, preg_match('/#[0-9a-fA-F]{3,8}\b/', preg_replace('/&#\d+;|href="#"/', '', $src) ?? ''), "$f: no hex colour literal");
        assert_same(0, preg_match('/\brgb\(|\bhsl\(/i', $src), "$f: no rgb()/hsl() literal");
    }
});

run_suite('release gate: the panel ships on (D18) and POSTURI_ELIGIBILITY_PANEL=0 hides it', function () use ($db_path) {
    assert_same(true, ELIGIBILITY_PANEL_ENABLED, 'released for the beta on 2026-10-16');
    putenv('POSTURI_ELIGIBILITY_PANEL=0');
    $s = start_fixture_server($db_path);
    putenv('POSTURI_ELIGIBILITY_PANEL=1');
    try {
        $html = el_get($s['base'], '/job/1009/');
        assert_contains('</html>', $html, 'the detail page still renders');
        assert_same(null, el_data($html), 'no embedded panel data when gated off');
        assert_not_contains('eligibility.js', $html, 'no panel script when gated off');
    } finally {
        proc_terminate($s['proc']);   // not stop_fixture_server(): that deletes the shared fixture
        proc_close($s['proc']);
    }
});

stop_fixture_server($srv, $db_path);
finish();
