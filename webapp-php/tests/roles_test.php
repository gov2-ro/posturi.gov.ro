<?php
/**
 * DATA-04 — role-scoped requirements and search.
 *
 * Fixture-served checks (the role-case fixture adds postings 1009-1012):
 *   - the posting_roles table has the shape the export writes: one decomposed
 *     row per positions[] entry, otherwise one implicit row;
 *   - a requirement search is satisfied by ONE role — "university + no
 *     experience" must not match a posting whose university role needs five
 *     years — and an announcement matching through two roles is returned once;
 *   - the number beside every role facet value equals the result count for the
 *     selection plus that value, and the feeds select what the list selects;
 *   - an export without posting_roles (and without the later columns) renders
 *     and returns exactly what the old posting-level predicates returned;
 *   - the detail page: a single positions entry is one post, not "several
 *     roles", experience is fractional-safe, unknown experience renders nothing.
 *
 * Usage: php tests/roles_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-roles-' . getmypid() . '.sqlite';
build_fixture_db($db_path, true);
$srv = start_fixture_server($db_path);
$base = $srv['base'];

function rget(string $base, string $path): string {
    return (string)@file_get_contents($base . $path);
}

/** Sorted, de-duplicated IDs a list URL returns across all pages, plus the raw row count. */
function role_result_ids(string $base, string $qs, ?int &$rows = null): array {
    $ids = [];
    $rows = 0;
    for ($p = 1; $p <= 4; $p++) {
        $b = rget($base, '/?' . $qs . ($qs !== '' ? '&' : '') . 'page=' . $p);
        preg_match_all('#<h2 class="font-display[^>]*>\s*<a href="/job/(\d+)-#', $b, $m);
        foreach ($m[1] as $i) { $ids[] = (int)$i; $rows++; }
        if (!str_contains($b, 'rel="next"')) break;
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

function role_xpath(string $html): DOMXPath {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($dom);
}

/** value => count shown beside each checkbox of a facet group (blank counts omitted). */
function facet_numbers(string $html, string $name): array {
    $out = [];
    $xp = role_xpath($html);
    foreach ($xp->query('//input[@type="checkbox" and @name="' . $name . '"]') as $i) {
        $n = $xp->query('following-sibling::span[2]', $i)->item(0);
        $txt = trim($n?->textContent ?? '');
        if ($txt !== '' && ctype_digit($txt)) $out[$i->getAttribute('value')] = (int)$txt;
    }
    return $out;
}

/** IDs of the posting-level (flat) predicate, straight from the table — the "before" behaviour. */
function flat_ids(string $db, string $where, array $binds = []): array {
    $pdo = new PDO('sqlite:' . $db);
    $st = $pdo->prepare("SELECT id FROM job_postings WHERE $where ORDER BY id");
    $st->execute($binds);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

const Q_ALL = 'status=all';

run_suite('posting_roles has the export shape: decomposed rows per entry, one implicit row otherwise', function () use ($db_path) {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $roles = fn(int $id) => $pdo->query("SELECT * FROM posting_roles WHERE posting_id = $id ORDER BY ordinal")->fetchAll(PDO::FETCH_ASSOC);

    $n_posting = (int)$pdo->query('SELECT COUNT(*) FROM job_postings')->fetchColumn();
    $none = (int)$pdo->query('SELECT COUNT(*) FROM job_postings j WHERE NOT EXISTS (SELECT 1 FROM posting_roles r WHERE r.posting_id = j.id)')->fetchColumn();
    assert_same(0, $none, 'every posting has at least one role row');
    assert_true($n_posting > 0, 'fixture has postings');

    $a = $roles(1009);
    assert_same(2, count($a), '1009: two positions entries, two rows');
    assert_same([1, 1], array_map('intval', array_column($a, 'decomposed')), '1009: both decomposed');
    assert_same(['licenta', 'liceala'], array_column($a, 'study_level'), '1009: study level per role');
    assert_same([6, 4], array_map('intval', array_column($a, 'eqf_level')), '1009: EQF per role');
    assert_same([5.0, 0.0], array_map('floatval', array_column($a, 'exp_years')), '1009: experience per role');
    assert_same(['["Contabilitate"]', '["Excel"]'], array_column($a, 'skills'), '1009: skills per role, not the posting union');
    assert_same('["Excel", "Word"]', fixture_dumps(['Word', 'Excel', 'Excel', '']), 'fixture arrays use the export\'s JSON spelling');

    $d = $roles(1010);
    assert_same(['Inspector de specialitate', 'Inspector de specialitate'], array_column($d, 'title'), '1010: duplicate titles are two rows');
    assert_same(2, count(array_unique(array_column($d, 'role_key'))), '1010: duplicate titles get distinct keys');
    assert_same([1, 2], array_map('intval', array_column($d, 'ordinal')), '1010: keys differ by ordinal');
    foreach ($d as $r) assert_true(str_starts_with($r['role_key'], 'src:b1010c2d3e4f:'), '1010: key scoped to the source revision');

    $one = $roles(1011);
    assert_same(1, count($one), '1011: one positions entry, one row');
    assert_same(1, (int)$one[0]['decomposed'], '1011: that row is a decomposed role');

    // The role does not inherit the posting: 1008's flat skills and credentials stay off a role that states none.
    $r1008 = $roles(1008);
    assert_same(2, count($r1008), '1008: two roles');
    assert_same(['["Excel", "Word"]', null], array_column($r1008, 'skills'), '1008: role 1 states skills; role 2 inherits none from the posting');
    assert_same([null, null], array_column($r1008, 'exp_years'), '1008: unstated experience stays NULL');
    assert_same([null, null], array_column($r1008, 'credentials'), '1008: no credentials inherited');
    assert_same(null, $r1008[1]['eqf_level'], '1008: a role with no education block has no EQF');

    $imp = $roles(1001);
    assert_same(1, count($imp), '1001: no positions, one implicit row');
    assert_same([0, 0], [(int)$imp[0]['ordinal'], (int)$imp[0]['decomposed']], '1001: ordinal 0, undecomposed');
    $flat = $pdo->query('SELECT inf_studies_required, v3_eqf_level, v3_isced_fields, inf_experience_years, v3_skills, v3_credentials FROM job_postings WHERE id = 1001')->fetch(PDO::FETCH_NUM);
    $row = $imp[0];
    assert_same($flat, [$row['study_level'], $row['eqf_level'], $row['isced_fields'], $row['exp_years'], $row['skills'], $row['credentials']], '1001: the implicit row copies the flat columns');

    $keys = $pdo->query('SELECT COUNT(*) - COUNT(DISTINCT posting_id || role_key) FROM posting_roles')->fetchColumn();
    assert_same(0, (int)$keys, '(posting, role_key) is unique');
});

run_suite('build_filters: one EXISTS for the role groups, one group dropped by $excl, binds line up', function () {
    $p = ['eqf' => ['6'], 'exp_level' => ['fara'], 'skill' => ['Contabilitate'], 'judet' => ['cluj'], 'status' => 'all'];
    $f = build_filters($p);
    $sql = implode(' AND ', $f['where']);
    assert_same(1, substr_count($sql, 'EXISTS (SELECT 1 FROM posting_roles r'), 'a single EXISTS holds every role group');
    assert_same(3, count($f['role_where']), 'eqf, experience and skill are the role conditions');
    assert_same(1, count($f['posting_where']), 'județ stays a posting-level condition');
    assert_same(substr_count($sql, '?'), count($f['binds']), 'one bind per placeholder');
    assert_same(count($f['posting_binds']) + count($f['role_binds']), count($f['binds']), 'posting binds then role binds');
    assert_same('EXISTS', substr(end($f['where']), 0, 6), 'the EXISTS clause is last, so its binds are last');

    foreach (['eqf' => 'eqf', 'exp_levels' => 'exp', 'skill' => 'skill'] as $excl => $label) {
        $x = build_filters($p, true, $excl);
        assert_same(2, count($x['role_where']), "excluding $label drops exactly one role group");
        assert_same(1, count($x['posting_where']), "excluding $label leaves the posting conditions");
    }
    $none = build_filters(['judet' => ['cluj']]);
    assert_same(0, substr_count(implode(' ', $none['where']), 'posting_roles'), 'no role selection, no role clause');
    $only = build_filters(['eqf' => ['6']], true, 'eqf');
    assert_same(0, substr_count(implode(' ', $only['where']), 'posting_roles'), 'the only role group excluded leaves no EXISTS');
});

run_suite('the correlated EXISTS probes posting_roles by posting, never by the EQF or study index', function () use ($db_path) {
    // Without sqlite_stat1 rows SQLite guessed the equality index on eqf_level was the most selective and
    // rescanned every EQF-6 role once per posting (250 ms a query on 1,848 postings; 1 ms by posting_id).
    // A fixture file has no statistics either, so the plan here is the one a fresh export would get.
    $pdo = new PDO('sqlite:' . $db_path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ([['eqf' => ['6']], ['studies_level' => ['licenta']], ['eqf' => ['6'], 'exp_level' => ['fara'], 'studies_level' => ['licenta']]] as $p) {
        $f = build_filters($p + ['status' => 'all']);
        $plan = $pdo->prepare('EXPLAIN QUERY PLAN SELECT COUNT(*) FROM job_postings j WHERE ' . implode(' AND ', $f['where']));
        $plan->execute($f['binds']);
        $text = implode(' | ', array_column($plan->fetchAll(PDO::FETCH_ASSOC), 'detail'));
        assert_contains('posting_id=?', $text, 'plan probes by posting_id: ' . json_encode($p));
        assert_not_contains('idx_pr_eqf', $text, 'plan does not scan the EQF index per posting: ' . json_encode($p));
        assert_not_contains('idx_pr_study', $text, 'plan does not scan the study index per posting: ' . json_encode($p));
    }
});

run_suite('university + zero experience does not match the posting whose university role needs five years', function () use ($base, $db_path) {
    $flat = flat_ids($db_path, "v3_eqf_level IN (6) AND (inf_experience_years IS NULL OR inf_experience_years < 1)");
    assert_true(in_array(1009, $flat, true), 'before: the posting-level predicate matched 1009 (university + zero experience)');

    assert_false(in_array(1009, role_result_ids($base, Q_ALL . '&eqf%5B%5D=6&exp_level%5B%5D=fara'), true), 'role scope: eqf 6 + no experience excludes 1009');
    assert_true(in_array(1009, role_result_ids($base, Q_ALL . '&eqf%5B%5D=6&exp_level%5B%5D=5plus'), true), 'eqf 6 + 5+ years: role A of 1009');
    assert_true(in_array(1009, role_result_ids($base, Q_ALL . '&eqf%5B%5D=4&exp_level%5B%5D=fara'), true), 'eqf 4 + no experience: role B of 1009');
    assert_false(in_array(1009, role_result_ids($base, Q_ALL . '&eqf%5B%5D=4&exp_level%5B%5D=5plus'), true), 'eqf 4 + 5+ years excludes 1009');

    // Study level (the other typed group) is held to the same role.
    $flat_s = flat_ids($db_path, "inf_studies_required IN ('licenta') AND (inf_experience_years IS NULL OR inf_experience_years < 1)");
    assert_true(in_array(1009, $flat_s, true), 'before: licență + no experience matched 1009');
    assert_false(in_array(1009, role_result_ids($base, Q_ALL . '&studies_level%5B%5D=licenta&exp_level%5B%5D=fara'), true), 'licență + no experience excludes 1009');
    assert_true(in_array(1009, role_result_ids($base, Q_ALL . '&studies_level%5B%5D=licenta&exp_level%5B%5D=5plus'), true), 'licență + 5+ years matches 1009');

    // Array-valued groups: union of the posting vs one role.
    assert_false(in_array(1009, role_result_ids($base, Q_ALL . '&skill%5B%5D=Contabilitate&credential%5B%5D=permis_conducere'), true), 'accounting skill + driving licence are on different roles');
    assert_true(in_array(1009, role_result_ids($base, Q_ALL . '&skill%5B%5D=Contabilitate&credential%5B%5D=autorizatie'), true), 'accounting skill + authorisation share a role');
    assert_false(in_array(1009, role_result_ids($base, Q_ALL . '&isced%5B%5D=03_stiinte_sociale&eqf%5B%5D=6'), true), 'field of the EQF-4 role + EQF 6 excludes 1009');
    assert_true(in_array(1009, role_result_ids($base, Q_ALL . '&isced%5B%5D=04_afaceri_administratie_drept&eqf%5B%5D=6'), true), 'field + EQF of the same role matches');
    // AND within the skill group is also per role: no single role has both skills.
    assert_false(in_array(1009, role_result_ids($base, Q_ALL . '&skill%5B%5D=Contabilitate&skill%5B%5D=Excel&skill_mode=all'), true), 'two skills of two roles do not satisfy AND');
    assert_true(in_array(1009, role_result_ids($base, Q_ALL . '&skill%5B%5D=Contabilitate&skill%5B%5D=Excel&skill_mode=any'), true), 'OR takes either role');
    // Within one group, values are OR across roles.
    assert_true(in_array(1009, role_result_ids($base, Q_ALL . '&eqf%5B%5D=4&eqf%5B%5D=6'), true), 'EQF 4 or 6: either role');

    // Announcement-level filters keep their meaning around a role selection.
    assert_true(in_array(1009, role_result_ids($base, Q_ALL . '&judet%5B%5D=cluj&eqf%5B%5D=6&exp_level%5B%5D=5plus'), true), 'județ + role selection');
    assert_false(in_array(1009, role_result_ids($base, Q_ALL . '&judet%5B%5D=bacau&eqf%5B%5D=6&exp_level%5B%5D=5plus'), true), 'wrong county still excludes');
    assert_true(in_array(1009, role_result_ids($base, 'q=contabil&' . Q_ALL . '&eqf%5B%5D=6&exp_level%5B%5D=5plus'), true), 'full-text + role selection');
    assert_false(in_array(1009, role_result_ids($base, 'q=contabil&' . Q_ALL . '&eqf%5B%5D=6&exp_level%5B%5D=fara'), true), 'full-text + cross-role selection');
});

run_suite('an announcement is returned once however many of its roles match', function () use ($base) {
    // 1010: two roles, EQF 6 and 7, studies licență and master.
    foreach ([Q_ALL . '&eqf%5B%5D=6&eqf%5B%5D=7', Q_ALL . '&studies_level%5B%5D=licenta&studies_level%5B%5D=master',
              'q=inspector&' . Q_ALL . '&eqf%5B%5D=6&eqf%5B%5D=7'] as $qs) {
        $rows = 0;
        $ids = role_result_ids($base, $qs, $rows);
        assert_true(in_array(1010, $ids, true), "1010 matches: $qs");
        assert_same(count($ids), $rows, "no announcement listed twice: $qs");
        $json = json_decode(rget($base, '/posturi.json?' . $qs), true);
        assert_same(count($ids), $json['count'] ?? -1, "total counts announcements, not roles: $qs");
    }
    $home = rget($base, '/?' . Q_ALL);
    $eqf = facet_numbers($home, 'eqf[]');
    $by_value = [];
    foreach ($eqf as $v => $n) $by_value[$v] = count(role_result_ids($base, Q_ALL . '&eqf%5B%5D=' . $v));
    assert_same($by_value, $eqf, 'each EQF count is an announcement count (1010 counted under 6 and under 7, once each)');
});

/** Selections for the facet-parity checks: query string => groups it uses (so those are skipped). */
function parity_selections(): array {
    return [
        'none'                       => [Q_ALL, []],
        'no experience'              => [Q_ALL . '&exp_level%5B%5D=fara', ['exp_level[]']],
        'eqf 6'                      => [Q_ALL . '&eqf%5B%5D=6', ['eqf[]']],
        'eqf 6 + 5+ years'           => [Q_ALL . '&eqf%5B%5D=6&exp_level%5B%5D=5plus', ['eqf[]', 'exp_level[]']],
        'licență'                    => [Q_ALL . '&studies_level%5B%5D=licenta', ['studies_level[]']],
        'skill (AND)'                => [Q_ALL . '&skill%5B%5D=Contabilitate', ['skill[]']],
        'authorisation'              => [Q_ALL . '&credential%5B%5D=autorizatie', ['credential[]']],
        'field'                      => [Q_ALL . '&isced%5B%5D=04_afaceri_administratie_drept', ['isced[]']],
        'județ + eqf'                => [Q_ALL . '&judet%5B%5D=cluj&eqf%5B%5D=6', ['eqf[]']],
        'full text + no experience'  => ['q=expert&' . Q_ALL . '&exp_level%5B%5D=fara', ['exp_level[]']],
        'default status'             => ['eqf%5B%5D=4', ['eqf[]']],
    ];
}

run_suite('facet counts equal the result count for the selection plus that value', function () use ($base) {
    $groups = ['eqf[]' => 'eqf', 'studies_level[]' => 'studies_level', 'exp_level[]' => 'exp_level',
               'isced[]' => 'isced', 'skill[]' => 'skill', 'credential[]' => 'credential'];
    $checked = 0;
    foreach (parity_selections() as $label => [$qs, $used]) {
        $html = rget($base, '/?' . $qs);
        foreach ($groups as $name => $param) {
            if (in_array($name, $used, true)) continue;
            foreach (facet_numbers($html, $name) as $value => $count) {
                $with = $qs . '&' . $param . '%5B%5D=' . rawurlencode((string)$value);
                assert_same($count, count(role_result_ids($base, $with)), "$label: $name=$value count equals the list");
                $checked++;
            }
        }
        // Announcement-level facets under a role selection use the same predicate.
        foreach (facet_numbers($html, 'judet[]') as $value => $count) {
            if (str_contains($qs, 'judet%5B%5D')) continue;
            assert_same($count, count(role_result_ids($base, $qs . '&judet%5B%5D=' . rawurlencode((string)$value))), "$label: judet=$value count equals the list");
            $checked++;
        }
    }
    assert_true($checked > 60, "many facet values were compared ($checked)");

    // A facet value can be offered by a role the other selections also hold, and only then.
    $exp_after_eqf6 = facet_numbers(rget($base, '/?' . Q_ALL . '&eqf%5B%5D=6'), 'exp_level[]');
    assert_same(count(role_result_ids($base, Q_ALL . '&eqf%5B%5D=6&exp_level%5B%5D=fara')), $exp_after_eqf6['fara'] ?? 0, 'EQF 6 then "fără experiență": the shown count is the real one');
});

run_suite('the feeds select exactly what the list selects', function () use ($base) {
    $cases = [Q_ALL . '&eqf%5B%5D=6&exp_level%5B%5D=fara', Q_ALL . '&eqf%5B%5D=4&exp_level%5B%5D=fara',
              Q_ALL . '&eqf%5B%5D=6&eqf%5B%5D=7', Q_ALL . '&skill%5B%5D=Contabilitate&credential%5B%5D=autorizatie',
              Q_ALL . '&skill%5B%5D=Contabilitate&credential%5B%5D=permis_conducere',
              'q=expert&' . Q_ALL . '&eqf%5B%5D=6&exp_level%5B%5D=5plus', 'eqf%5B%5D=4'];
    foreach ($cases as $qs) {
        $want = role_result_ids($base, $qs);
        $json = json_decode(rget($base, '/posturi.json?' . $qs), true);
        $got = array_map(fn($r) => (int)$r['id'], $json['results'] ?? []);
        sort($got);
        assert_same($want, $got, "JSON feed = list: $qs");
        foreach (['posturi.atom', 'posturi.rss'] as $feed) {
            preg_match_all('#/job/(\d+)-#', rget($base, "/$feed?$qs"), $m);
            $ids = array_values(array_unique(array_map('intval', $m[1])));
            sort($ids);
            assert_same($want, $ids, "$feed = list: $qs");
        }
    }
});

run_suite('detail page: one positions entry is one post, not several roles', function () use ($base) {
    $one = rget($base, '/job/1011/');
    assert_contains('Post scos la concurs', $one, '1011 heading');
    assert_not_contains('Posturi scoase la concurs', $one, '1011: no plural heading');
    assert_not_contains('mai multe roluri', $one, '1011: no several-roles sentence');
    assert_not_contains('scoase la concurs (1)', $one, '1011: no "(1)" count');
    $role = role_xpath($one)->query('//*[@data-role]');
    assert_same(1, $role->length, '1011: one role block');
    $txt = preg_replace('/\s+/', ' ', $role->item(0)->textContent);
    assert_contains('Șofer autospecială', $txt, '1011 role title');
    assert_contains('Experiență: min. 2 ani, în specialitate', $txt, '1011 experience with the specialty qualifier');
    assert_contains('Documente: Permis categoria C', $txt, '1011 role credentials');

    $two = rget($base, '/job/1009/');
    assert_contains('Posturi scoase la concurs (2)', $two, '1009 heading keeps the count');
    assert_contains('Anunțul cuprinde mai multe roluri, fiecare cu cerințele lui.', $two, '1009 keeps the explanation');
    $blocks = [];
    foreach (role_xpath($two)->query('//*[@data-role]') as $b) $blocks[] = preg_replace('/\s+/', ' ', $b->textContent);
    assert_same(2, count($blocks), '1009: two role blocks');
    assert_contains('Experiență: min. 5 ani, în specialitate', $blocks[0], '1009 role A experience');
    assert_contains('Documente: Autorizație CECCAR', $blocks[0], '1009 role A credential');
    assert_contains('Fără experiență', $blocks[1], '1009 role B states none required');
    assert_not_contains('în specialitate', $blocks[1], '1009 role B: not in specialty');
    assert_contains('Documente: Permis categoria B', $blocks[1], '1009 role B credential');

    $dup = rget($base, '/job/1010/');
    preg_match_all('#Inspector de specialitate#', implode(' ', array_map(fn($b) => $b->textContent, iterator_to_array(role_xpath($dup)->query('//*[@data-role]')))), $m);
    assert_same(2, count($m[0]), '1010: duplicate titles both listed');
});

run_suite('detail page: fractional years, none required, unknown renders nothing, text is escaped', function () use ($base) {
    $html = rget($base, '/job/1012/');
    $blocks = [];
    foreach (role_xpath($html)->query('//*[@data-role]') as $b) $blocks[] = preg_replace('/\s+/', ' ', $b->textContent);
    assert_same(2, count($blocks), '1012: two roles');
    assert_contains('Experiență: min. 6 luni', $blocks[0], '0.5 years reads six months');
    assert_not_contains('0 ani', $blocks[0], 'never "0 ani" for 0.5');
    assert_not_contains('în specialitate', $blocks[0], 'general experience is not "în specialitate"');
    assert_contains('Fără experiență', $blocks[1], 'none_required renders "Fără experiență"');
    // Escaped at the source: the title is data.
    assert_contains('Muncitor &lt;u&gt;necalificat&lt;/u&gt; &amp; curățenie', $html, 'role title is escaped');
    assert_not_contains('<u>necalificat</u>', $html, 'no raw markup from a role title');

    // 1008's roles state no experience: nothing, in particular not "fără experiență".
    $r1008 = [];
    foreach (role_xpath(rget($base, '/job/1008/'))->query('//*[@data-role]') as $b) $r1008[] = preg_replace('/\s+/', ' ', $b->textContent);
    assert_same(2, count($r1008), '1008: two roles');
    foreach ($r1008 as $i => $t) {
        assert_not_contains('Fără experiență', $t, "1008 role $i: unknown experience is not 'none required'");
        assert_not_contains('Experiență', $t, "1008 role $i: nothing about experience");
    }

    assert_same('6 luni', experience_years_label(0.5), 'label 0.5');
    assert_same('3 luni', experience_years_label(0.25), 'label 0.25');
    assert_same('1 an', experience_years_label(1.0), 'label 1');
    assert_same('1,5 ani', experience_years_label(1.5), 'label 1.5');
    assert_same('3 ani', experience_years_label(3.0), 'label 3');
    assert_same('20 de ani', experience_years_label(20.0), 'label 20');
    assert_same('', role_experience_label(null), 'no block');
    assert_same('', role_experience_label([]), 'empty block');
    assert_same('', role_experience_label(['years_minimum' => null, 'in_specialty' => false, 'none_required' => false]), 'unstated');
    assert_same('Fără experiență', role_experience_label(['years_minimum' => 0]), 'explicit zero');
    assert_same('Fără experiență', role_experience_label(['years_minimum' => null, 'none_required' => true]), 'none required');
    assert_same('Experiență: min. 2 ani', role_experience_label(['years_minimum' => 2, 'in_specialty' => false, 'none_required' => true]), 'a positive minimum wins');
    assert_same('Experiență: min. 6 luni, în specialitate', role_experience_label(['years_minimum' => 0.5, 'in_specialty' => true]), 'fraction with specialty');
});

run_suite('no PHP warnings or fatals in the server log', function () use ($srv) {
    $log = (string)@file_get_contents($srv['log']);
    foreach (['Fatal', 'Warning', 'Notice', 'Deprecated', 'no such'] as $bad) assert_not_contains($bad, $log, "log: $bad");
});

// ---- An export that predates posting_roles --------------------------------------------------
$old_db = sys_get_temp_dir() . '/posturi-roles-old-' . getmypid() . '.sqlite';
copy($db_path, $old_db);
$o = new PDO('sqlite:' . $old_db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$o->exec('DROP TABLE posting_roles');
// ... and the provenance columns the export gained at the same time.
$o->exec('ALTER TABLE job_postings DROP COLUMN schema_source_revision');
$o->exec('ALTER TABLE job_postings DROP COLUMN schema_prompt_version');
unset($o);
$old_srv = start_fixture_server($old_db);
$ob = $old_srv['base'];

run_suite('old export (no posting_roles): renders, and every requirement filter is the old flat predicate', function () use ($ob, $old_db) {
    $pdo = new PDO('sqlite:' . $old_db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    shim_legacy_export($pdo);
    $view = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_temp_master WHERE type = 'view' AND name = 'posting_roles'")->fetchColumn();
    assert_same(1, $view, 'a temp posting_roles view stands in for the table');
    $n = (int)$pdo->query('SELECT COUNT(*) FROM job_postings')->fetchColumn();
    assert_same($n, (int)$pdo->query('SELECT COUNT(*) FROM posting_roles')->fetchColumn(), 'one implicit role per posting');
    assert_same(0, (int)$pdo->query('SELECT COUNT(*) FROM posting_roles WHERE decomposed = 1')->fetchColumn(), 'none is decomposed');
    assert_same(0, (int)$pdo->query('SELECT COUNT(*) FROM posting_roles r JOIN job_postings j ON j.id = r.posting_id
        WHERE r.eqf_level IS NOT j.v3_eqf_level OR r.exp_years IS NOT j.inf_experience_years OR r.skills IS NOT j.v3_skills')->fetchColumn(),
        'the view mirrors the posting columns');

    foreach (['/', '/?' . Q_ALL, '/job/1009/', '/job/1011/', '/job/1008/', '/posturi.json', '/posturi.atom', '/statistici/'] as $path) {
        $body = rget($ob, $path);
        assert_true($body !== '', "$path answers");
        assert_not_contains('Fatal error', $body, "$path body");
        assert_not_contains('no such', $body, "$path body");
    }

    $cases = [
        'eqf 6'           => ['eqf%5B%5D=6',               'v3_eqf_level IN (6)'],
        'eqf 4 or 6'      => ['eqf%5B%5D=4&eqf%5B%5D=6',   'v3_eqf_level IN (4, 6)'],
        'licență'         => ['studies_level%5B%5D=licenta', "inf_studies_required IN ('licenta')"],
        'no experience'   => ['exp_level%5B%5D=fara',      '(inf_experience_years IS NULL OR inf_experience_years < 1)'],
        '5+ years'        => ['exp_level%5B%5D=5plus',     'inf_experience_years >= 5'],
        '1-2 years'       => ['exp_level%5B%5D=1-2',       '(inf_experience_years >= 1 AND inf_experience_years < 3)'],
        'eqf 6 + no exp.' => ['eqf%5B%5D=6&exp_level%5B%5D=fara', "v3_eqf_level IN (6) AND (inf_experience_years IS NULL OR inf_experience_years < 1)"],
        'skill + cred.'   => ['skill%5B%5D=Contabilitate&credential%5B%5D=autorizatie', "v3_skills LIKE '%\"Contabilitate\"%' AND v3_credentials LIKE '%\"autorizatie\"%'"],
        'field'           => ['isced%5B%5D=04_afaceri_administratie_drept', "v3_isced_fields LIKE '%\"04\\_afaceri\\_administratie\\_drept\"%' ESCAPE '\\'"],
    ];
    foreach ($cases as $label => [$qs, $flat]) {
        $want = flat_ids($old_db, $flat);
        assert_same($want, role_result_ids($ob, Q_ALL . '&' . $qs), "old export: $label = the flat predicate");
    }
    assert_true(count(facet_numbers(rget($ob, '/?' . Q_ALL), 'eqf[]')) > 0, 'old export: EQF facet has counts');
    assert_true(count(facet_numbers(rget($ob, '/?' . Q_ALL), 'exp_level[]')) > 0, 'old export: experience facet has counts');
    // The multi-role fixture posting is one posting here: the contrasting search matches it, as it always did.
    assert_true(in_array(1009, role_result_ids($ob, Q_ALL . '&eqf%5B%5D=6&exp_level%5B%5D=fara'), true), 'old export: no role decomposition, no role scope');
});

run_suite('old export: server log is clean', function () use ($old_srv) {
    $log = (string)@file_get_contents($old_srv['log']);
    foreach (['Fatal', 'Warning', 'Notice', 'Deprecated', 'no such'] as $bad) assert_not_contains($bad, $log, "log: $bad");
});

run_suite('undecomposed postings give the same results as the flat predicate', function () use ($base, $db_path) {
    // On the current export, restricted to postings with a single implicit role, the role-scoped
    // search equals the posting-level one: nothing changes for them.
    $pdo = new PDO('sqlite:' . $db_path);
    $undecomposed = array_map('intval', $pdo->query('SELECT posting_id FROM posting_roles GROUP BY posting_id HAVING MAX(decomposed) = 0 ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN));
    $cases = [
        'eqf%5B%5D=6'                                  => 'v3_eqf_level IN (6)',
        'studies_level%5B%5D=liceala'                  => "inf_studies_required IN ('liceala')",
        'exp_level%5B%5D=fara'                         => '(inf_experience_years IS NULL OR inf_experience_years < 1)',
        'eqf%5B%5D=6&exp_level%5B%5D=1-2'              => 'v3_eqf_level IN (6) AND (inf_experience_years >= 1 AND inf_experience_years < 3)',
        'skill%5B%5D=Excel&skill%5B%5D=Contabilitate&skill_mode=any' => "(v3_skills LIKE '%\"Excel\"%' OR v3_skills LIKE '%\"Contabilitate\"%')",
    ];
    foreach ($cases as $qs => $flat) {
        $want = array_values(array_intersect(flat_ids($db_path, $flat), $undecomposed));
        $got = array_values(array_intersect(role_result_ids($base, Q_ALL . '&' . $qs), $undecomposed));
        assert_same($want, $got, "undecomposed postings unchanged: $qs");
    }
});

stop_fixture_server($old_srv, $old_db);
stop_fixture_server($srv, $db_path);
finish();
