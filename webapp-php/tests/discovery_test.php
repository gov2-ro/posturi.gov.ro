<?php
/**
 * UX-01A — simpler discovery and clear application status.
 *
 * Server-rendered checks against the fixture database: the regrouped sidebar
 * (default-open set, ancestor opening, one input per value), old filter URLs
 * returning exactly the postings they returned before the regroup, the status
 * and deadline wording, labels, orphan selections, and feed links built from
 * the active filters. Browser behaviour (HTMX swaps, history, widths) is in
 * tests/browser/discovery.spec.js.
 *
 * Usage: php tests/discovery_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';
require_once __DIR__ . '/old_urls.php';
require_once __DIR__ . '/fixtures/page_helpers.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-discovery-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
$srv = start_fixture_server($db_path);
$base = $srv['base'];

function get_html(string $base, string $path): string {
    return (string)@file_get_contents($base . $path);
}

/** IDs of every posting a list URL returns, across all pages. */
function result_ids(string $base, string $qs): array {
    $ids = [];
    for ($p = 1; $p <= 4; $p++) {
        $b = get_html($base, '/?' . $qs . ($qs !== '' ? '&' : '') . 'page=' . $p);
        preg_match_all('#<h2 class="font-display[^>]*>\s*<a href="/job/(\d+)-#', $b, $m);
        foreach ($m[1] as $i) $ids[] = (int)$i;
        if (!str_contains($b, 'rel="next"')) break;
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

function xpath_for(string $html): DOMXPath {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($dom);
}

/** data-facet keys of the <details> disclosures that are open in the server markup. */
function open_facets(string $html): array {
    $out = [];
    foreach (xpath_for($html)->query('//details[contains(@class,"facet-group")]') as $d) {
        if ($d->hasAttribute('open')) $out[] = $d->getAttribute('data-facet');
    }
    sort($out);
    return $out;
}

/** facet key -> ancestor facet keys, from the nesting in the markup. */
function facet_ancestors(string $html): array {
    $out = [];
    foreach (xpath_for($html)->query('//details[contains(@class,"facet-group")]') as $d) {
        $anc = [];
        for ($n = $d->parentNode; $n; $n = $n->parentNode) {
            if ($n instanceof DOMElement && $n->tagName === 'details' && $n->hasAttribute('data-facet')) $anc[] = $n->getAttribute('data-facet');
        }
        $out[$d->getAttribute('data-facet')] = $anc;
    }
    return $out;
}

run_suite('old filter URLs return the same postings as before the regroup', function () use ($base) {
    // ID sets captured from 6accc59 (before the regroup) against this fixture.
    $expected = [
        'default' => [1001, 1002, 1004, 1005, 1006, 1007, 1008, 2001, 2002, 2003, 2004, 2005, 2006, 2007, 2008, 2009, 2010, 2011, 2012, 2013, 2014, 2015, 2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023, 2024, 2025, 2026, 2027, 2028, 2029, 2030],
        'judet scalar legacy' => [1001, 1006, 1007],
        'judet multi' => [1001, 1002, 1004, 1006, 1007],
        'eqf exact' => [1001, 1008],
        'eqf legacy scalar' => [1002],
        'eqf multi OR' => [1002, 1004],
        'studies legacy' => [1002],
        'mixed source+struct' => [1006],
        'family+eqf+status' => [1002],
        'skills any' => [1001, 1002, 1006, 1008],
        'skills all' => [1002],
        'employer scope' => [1001, 1006, 1007],
        'date bounds' => [1001, 1004, 1006, 1008, 2005, 2011, 2017, 2023, 2029],
        'status soon' => [1002, 2001, 2002, 2003, 2004, 2005, 2006, 2007, 2008, 2009, 2010, 2011, 2012, 2013, 2014, 2015, 2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023, 2024, 2025, 2026, 2027, 2028, 2029, 2030],
        'status closed' => [1003],
        'status unknown' => [1005],
        'status all legacy' => [1001, 1002, 1003, 1004, 1005, 1006, 1007, 1008, 2001, 2002, 2003, 2004, 2005, 2006, 2007, 2008, 2009, 2010, 2011, 2012, 2013, 2014, 2015, 2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023, 2024, 2025, 2026, 2027, 2028, 2029, 2030],
        'type+duration+sched' => [1002],
        'exp+remote+computer' => [1002],
        'funding+sector' => [1001],
        'anomaly+shift+schema' => [1005],
        'shift' => [1004],
        'salary bucket' => [1002, 1007, 1008],
        // Recorded with the gross buckets as they were before the net buckets (2026-11-17), by a direct SQL query.
        'salary sub-3000' => [1004, 1005, 2001, 2002, 2003, 2004, 2005, 2006, 2007, 2008, 2009, 2010, 2011, 2012, 2013, 2014, 2015, 2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023, 2024, 2025, 2026, 2027, 2028, 2029, 2030],
        'salary 4000-5000' => [1001, 1003],
        'salary 5000-7000' => [1006],
        'salary peste-7000' => [],
        'employer_cat' => [1002, 1006],
        'free text' => [1001, 1006, 1007],
        'unknown value' => [1004],
    ];
    $cases = old_url_cases();
    assert_same(array_keys($expected), array_keys($cases), 'every old URL has a recorded result set');
    foreach ($cases as $label => $qs) {
        assert_same($expected[$label], result_ids($base, $qs), "result IDs unchanged: $label");
    }
});

run_suite('every group is collapsed on a fresh load; Județ and Domeniu are the drawer defaults', function () use ($base) {
    $home = get_html($base, '/posturi/');
    assert_same([], open_facets($home), 'no disclosure is rendered open (desktop starts collapsed)');
    $defaults = [];
    foreach (xpath_for($home)->query('//details[contains(@class,"facet-group") and @data-default-open]') as $d) $defaults[] = $d->getAttribute('data-facet');
    sort($defaults);
    assert_same(['cor', 'judet'], $defaults, 'the small-screen drawer opens these two (list.php script)');
    // BETA-FILTERS (D4, 261016): one flat list, nothing nested, nothing behind "Mai multe filtre".
    $anc = facet_ancestors($home);
    foreach ($anc as $k => $parents) assert_same([], $parents, "$k is a top-level group");
    foreach (['judet', 'family', 'occupation', 'studies_level', 'isced', 'exp_level', 'level', 'regime', 'inst',
              'employer_cat', 'duration', 'schedule', 'lang', 'skill', 'credential',
              'funding', 'sector', 'domain', 'termen'] as $k) {
        assert_true(array_key_exists($k, $anc), "group $k is offered");
    }
    // 261016: Ture and Telemuncă are switches inside "Program de lucru", not groups.
    foreach (['shift', 'remote'] as $k) {
        assert_false(array_key_exists($k, $anc), "no separate $k group");
        assert_same(1, xpath_for($home)->query('//details[@data-facet="schedule"]//input[@name="' . $k . '"]')->length,
            "$k switch sits in Program de lucru");
    }
    assert_contains('Program de lucru', $home, 'merged group heading');
    foreach (['eqf', 'type', 'seniority', 'work_type', 'anomaly', 'schema', 'stage', 'more', 'studii', 'legacy', 'diagnostics', 'categorie'] as $k) {
        assert_false(array_key_exists($k, $anc), "no $k group (merged or dropped)");
    }
    assert_false(array_key_exists('loc', $anc), 'Localitate waits for a county');
    assert_not_contains('Mai multe filtre', $home, 'no "more filters" bundle');
    foreach (['Domeniu profesional', 'Nivel de studii', 'Experiență minimă', 'Contract', 'Termen limită', 'Domeniu de activitate'] as $label) {
        assert_contains($label, $home, "heading $label");
    }
    assert_contains('nu stabilește dacă vă calificați', $home, 'education explanation');
    assert_contains('Unele intervale includ anunțuri fără experiență precizată.', $home, 'experience bucket explanation');
    assert_contains('Clasificare automată din textul anunțului', $home, 'automatic-classification help');
    assert_contains('anunțul oficial', $home, 'help points to the official announcement');
    // Search field and drawer controls stay.
    assert_contains('id="q"', $home, 'search field');
    assert_contains('id="filter-toggle"', $home, 'drawer trigger');
});

run_suite('no input appears under two headings', function () use ($base) {
    $home = get_html($base, '/?status=all');
    $seen = [];
    foreach (xpath_for($home)->query('//input[@type="checkbox" and @name]') as $i) {
        $k = $i->getAttribute('name') . '=' . $i->getAttribute('value');
        $seen[$k] = ($seen[$k] ?? 0) + 1;
    }
    $dups = array_keys(array_filter($seen, fn($n) => $n > 1));
    assert_same([], $dups, 'each checkbox name/value exists once');
    foreach (['expires_after', 'expires_before'] as $n) {
        assert_same(1, substr_count($home, 'name="' . $n . '"'), "$n field exists once");
    }
    foreach (['judet[]', 'family[]', 'occupation[]', 'isced[]', 'studies_level[]', 'exp_level[]', 'skill[]',
              'lang[]', 'credential[]', 'duration[]', 'schedule[]', 'shift', 'remote', 'sector[]',
              'funding[]', 'domain[]', 'level[]', 'regime[]', 'inst[]', 'employer_cat[]'] as $name) {
        assert_true(str_contains($home, 'name="' . $name . '"'), "control for $name");
    }
});

run_suite('a bookmarked selection opens its group and stays removable', function () use ($base) {
    $cases = [
        'isced[]=04_afaceri_administratie_drept' => ['isced', 'isced[]', '04_afaceri_administratie_drept'],
        'studies_level[]=licenta'       => ['studies_level', 'studies_level[]', 'licenta'],
        'skill[]=Excel'                 => ['skill', 'skill[]', 'Excel'],
        'exp_level[]=1-2'               => ['exp_level', 'exp_level[]', '1-2'],
        'duration[]=determinata'        => ['duration', 'duration[]', 'determinata'],
        'shift=1'                       => ['schedule', 'shift', '1'],
        'funding[]=buget_local'         => ['funding', 'funding[]', 'buget_local'],
        'level[]=executie'              => ['level', 'level[]', 'executie'],
        'employer_cat[]=' . rawurlencode('Funcție publică') => ['employer_cat', 'employer_cat[]', 'Funcție publică'],
        'salary_bucket=3000-4000'       => ['salary_bucket', 'salary_bucket[]', '3000-4000'],
    ];
    foreach ($cases as $qs => [$group, $field, $value]) {
        $html = get_html($base, '/?status=all&' . str_replace(['[', ']'], ['%5B', '%5D'], $qs));
        assert_same([$group], open_facets($html), "$qs opens its own group, nothing else");
        $x = xpath_for($html)->query('//input[@name="' . $field . '" and @value="' . $value . '" and @checked]');
        assert_same(1, $x->length, "$qs: control checked and present");
        assert_contains('data-chip-param="' . preg_replace('/\[\]$/', '', explode('=', $qs)[0]) . '"', $html, "$qs has a chip");
    }
    // Parameters without a control any more ride along hidden and keep their chip.
    foreach (['eqf[]=4' => ['eqf[]', '4'], 'type[]=Permanent' => ['type[]', 'Permanent'],
              'anomaly[]=missing_contact' => ['anomaly[]', 'missing_contact'], 'schema=yes' => ['schema', 'yes'],
              'computer=nesolicitat' => ['computer', 'nesolicitat'], 'has_salary=1' => ['has_salary', '1']] as $qs => [$field, $value]) {
        $html = get_html($base, '/?status=all&' . str_replace(['[', ']'], ['%5B', '%5D'], $qs));
        assert_same(1, xpath_for($html)->query('//input[@type="hidden" and @name="' . $field . '" and @value="' . $value . '"]')->length,
            "$qs survives the next form change as a hidden field");
        assert_contains('data-chip-param="' . preg_replace('/\[\]$/', '', explode('=', $qs)[0]) . '"', $html, "$qs has a chip");
    }
    $d = get_html($base, '/?status=all&expires_before=2026-10-21');
    assert_same(['termen'], open_facets($d), 'a date bound opens Termen limită');
    assert_contains('value="2026-10-21"', $d, 'date bound value kept');
});

run_suite('selected values outside the options survive as pinned checkboxes', function () use ($base) {
    $html = get_html($base, '/?status=all&skill%5B%5D=Nu-exista&skill_mode=all&occupation%5B%5D=Ocupatie+inexistenta');
    foreach ([['skill[]', 'Nu-exista'], ['occupation[]', 'Ocupatie inexistenta']] as [$n, $v]) {
        $x = xpath_for($html)->query('//input[@name="' . $n . '" and @value="' . $v . '" and @checked]');
        assert_same(1, $x->length, "orphan $n=$v pinned and checked");
    }
    assert_contains('name="skill_mode" value="all"', $html, 'AND switch stays');
    assert_true(preg_match('#value="Nu-exista"[^>]*checked[^>]*>.*?<span[^>]*>\s*</span>#s', $html) === 1, 'orphan count blank, not 0');
});

run_suite('facet counts, chips and result IDs agree', function () use ($base) {
    $home = get_html($base, '/posturi/');
    foreach ([['studies_level[]', 'licenta', 'studies_level%5B%5D=licenta'], ['judet[]', 'cluj', 'judet%5B%5D=cluj'], ['skill[]', 'Excel', 'skill%5B%5D=Excel']] as [$n, $v, $qs]) {
        $x = xpath_for($home)->query('//input[@name="' . $n . '" and @value="' . $v . '"]/following-sibling::span[2]');
        $facet = (int)trim($x->item(0)?->textContent ?? '-1');
        assert_same($facet, count(result_ids($base, $qs)), "facet count for $n=$v equals the result count");
    }
    $both = get_html($base, '/?status=all&eqf%5B%5D=4&judet%5B%5D=cluj');
    assert_contains('data-chip-param="eqf"', $both, 'eqf chip (legacy link)');
    assert_contains('data-chip-param="judet"', $both, 'judet chip');
    assert_contains('href="?status=all&amp;judet%5B0%5D=cluj"', $both, 'no-JS chip href drops only its value');
    assert_same([1001], result_ids($base, 'status=all&eqf%5B%5D=4&judet%5B%5D=cluj'), 'chips + facets = intersection of groups');
    assert_same([1001, 1006], result_ids($base, 'status=all&judet%5B%5D=cluj&eqf%5B%5D=4&eqf%5B%5D=7'), 'multiple EQF levels are OR within the group');
});

run_suite('UX-02: a county opens its localities, which filter and stay removable', function () use ($base) {
    $cluj = get_html($base, '/?status=all&judet%5B%5D=cluj');
    $locs = [];
    foreach (xpath_for($cluj)->query('//input[@name="loc[]"]') as $i) $locs[] = $i->getAttribute('value');
    assert_true(count($locs) >= 1, 'Localitate offered once a county is chosen');
    $loc = $locs[0];
    $ids = result_ids($base, 'status=all&judet%5B%5D=cluj&loc%5B%5D=' . rawurlencode($loc));
    assert_true(count($ids) >= 1, 'the locality filter returns postings');
    $html = get_html($base, '/?status=all&judet%5B%5D=cluj&loc%5B%5D=' . rawurlencode($loc));
    assert_contains('data-chip-param="loc"', $html, 'locality chip');
    assert_contains('Localitate:', preg_replace('/\s+/', ' ', strip_tags($html)), 'chip names the group');
});

run_suite('status labels, help copy and announcement counts', function () use ($base) {
    $home = get_html($base, '/posturi/');
    foreach (['Active', 'Termen în 7 zile', 'Înscrieri închise', 'Termen neprecizat'] as $l) assert_contains($l, $home, "status label $l");
    assert_not_contains('Expiră în 7 zile', $home, 'old 7-day label gone');
    $flat = preg_replace('/\s+/', ' ', strip_tags($home));
    assert_contains('Active include anunțuri cu înscrieri deschise și anunțuri al căror termen nu este confirmat. Verifică termenul în anunțul oficial.', $flat, 'persistent status help');
    assert_contains('Include și date estimate din expirarea anunțului.', $flat, '7-day note present (shown by CSS for that tab)');
    assert_contains('class="status-soon-note"', $home, '7-day note is the CSS-toggled element');
    // URL values unchanged.
    foreach (['active', 'soon', 'closed', 'unknown'] as $v) {
        assert_same(1, xpath_for($home)->query('//select[@name="status"]/option[@value="' . $v . '"]')->length, "status value $v");
    }
    // 261016: a select beside the sort, not a row of buttons; the help shows once a non-default is picked.
    assert_same(0, xpath_for($home)->query('//input[@name="status"]')->length, 'no status radio buttons');
    assert_contains('class="status-help', $home, 'help is the CSS-toggled element');
    // Counts are of announcements.
    assert_true(preg_match('#<span class="font-mono font-medium text-ink">\d+</span>\s*anunțuri găsite#u', $home) === 1, 'plural count wording');
    $one = get_html($base, '/?status=closed');
    assert_true(preg_match('#<span class="font-mono font-medium text-ink">1</span>\s*anunț găsit#u', $one) === 1, 'singular count wording');
    assert_not_contains('posturi găsite', $home, 'old count wording gone');
    assert_true(preg_match('#id="drawer-count">\d+</span>\s*anunțuri#u', $home) === 1, 'drawer apply wording');
    // Wrapping, not overflow: the options row (view, status, sort) may wrap.
    assert_true(preg_match('#id="result-options"[^>]*class="[^"]*flex-wrap#', $home) === 1, 'options row wraps on small screens');
});

run_suite('deadline wording: confirmed, fallback, unknown, closed', function () use ($base) {
    $note = 'Expirarea anunțului; înscriere neconfirmată';
    $row = function (string $html, string $anchor) {
        $pos = strpos($html, $anchor);
        if ($pos === false) return '';
        $end = strpos($html, '</li>', $pos);
        return substr($html, $pos, $end === false ? 3000 : $end - $pos);
    };
    $list = get_html($base, '/?status=all');
    $r1001 = $row($list, 'href="/job/1001-asistent-medical-generalist/"');
    assert_contains('13 zile', $r1001, 'confirmed: countdown kept');
    assert_not_contains($note, $r1001, 'confirmed: no fallback note');
    $r1004 = $row($list, 'href="/job/1004-ingrijitor-scoala/"');
    assert_contains($note, $r1004, 'fallback: visible note, not tooltip-only');
    assert_contains('17 zile', $r1004, 'fallback: countdown kept');
    assert_contains('datetime="2026-10-20"', $r1004, 'fallback: resolver date kept');
    assert_not_contains('>estimat<', $r1004, 'fallback: bare "estimat" gone');
    $r1005 = $row($list, 'href="/job/1005-muncitor-necalificat/"');
    assert_contains('Termen neprecizat', $r1005, 'unknown');
    assert_not_contains('zile', $r1005, 'unknown: no countdown');
    assert_not_contains($note, $r1005, 'unknown: no fallback note');
    $r1003 = $row($list, 'href="/job/1003-inspector-grad-ii/"');
    assert_contains('Înscrieri închise', $r1003, 'closed confirmed deadline');
    // The same wording on the detail page and the employer listing.
    $d1004 = get_html($base, '/job/1004/');
    assert_contains($note, $d1004, 'detail fallback note');
    $d1001 = get_html($base, '/job/1001/');
    assert_not_contains($note, $d1001, 'detail confirmed: no fallback note');
    $d1005 = get_html($base, '/job/1005/');
    assert_contains('Termen neprecizat', $d1005, 'detail unknown');
    $emp = get_html($base, '/angajator/primaria-comunei-gaiceana/');
    assert_contains($note, $emp, 'employer listing fallback note');
    assert_contains('class="pg-salary', $emp, 'employer listing: the salary line, with its own "(estimat)" (UX-SALARY-BOX)');
    $emp3 = get_html($base, '/angajator/administratia-de-salubrizare-tunari/');
    assert_contains('Termen neprecizat', $emp3, 'employer listing unknown');
});

run_suite('labels: Romanian for known values, unfamiliar values kept visible', function () use ($base) {
    $html = get_html($base, '/?status=all');
    $flat = preg_replace('/\s+/', ' ', strip_tags($html));
    foreach (['Master / Magistru', 'Licență', 'Funcții de conducere', 'Sănătate', 'Perioadă determinată'] as $l) {
        assert_contains($l, $flat, "option label $l");
    }
    assert_not_contains('conducere_superioara', $flat, 'no raw snake_case seniority');
    assert_not_contains('norma_intreaga', $flat, 'no raw snake_case work type');
    // Unmapped value: still an option, spelled as received.
    assert_true(xpath_for($html)->query('//input[@name="duration[]" and @value="zz_nou"]')->length === 1, 'unfamiliar duration stays selectable');
    assert_contains('zz_nou', $flat, 'unfamiliar value shown, not guessed');
    // Chips use the same labels.
    $c = get_html($base, '/?status=all&seniority%5B%5D=conducere_superioara&level%5B%5D=conducere&family%5B%5D=s%C4%83n%C4%83tate&duration%5B%5D=zz_nou');
    $chips = preg_replace('/\s+/', ' ', strip_tags(substr($c, strpos($c, 'data-chip-param'), 9000)));
    assert_contains('Conducere superioară', $chips, 'seniority chip label');
    assert_contains('Funcții de conducere', $chips, 'level chip label');
    assert_contains('Domeniu profesional: Sănătate', $chips, 'family chip label');
    assert_contains('zz_nou', $chips, 'unmapped chip value kept');
    assert_contains('(estimat)', get_html($base, '/?status=all&salary_bucket=net-3000-4000'), 'salary stays labelled as an estimate');
});

run_suite('result rows show pay: the stated salary first, else the labelled estimate', function () use ($base) {
    // Same precedence as SALARY_EXPR (the salary filter), so a row and a bucket never disagree.
    assert_same(['label' => 'Salariu estimat', 'text' => '≈ 2.485–3.090 lei net', 'gross' => '4.100–5.200 lei', 'estimated' => true],
        list_salary(['sal_min' => 4100.0, 'sal_max' => 5200.0, 'sal_baza' => 'lege_in_vigoare']), 'grid estimate, a range: net first, the gross range kept');
    assert_same('≈ 2.017 lei net', list_salary(['sal_min' => 3300, 'sal_max' => 3300, 'sal_baza' => 'lege_in_vigoare'])['text'], 'one figure when min = max');
    assert_same('≈ 2.017 lei net', list_salary(['sal_min' => 3300, 'sal_max' => null])['text'], 'no max: the min alone');
    assert_same(['label' => 'Salariu anunțat', 'text' => '≈ 3.523 lei net', 'gross' => '6.000 lei', 'estimated' => false],
        list_salary(['inf_salary_min' => 6000, 'inf_salary_max' => 6000, 'sal_min' => 4100, 'sal_max' => 5200]), 'a stated salary wins');
    assert_same(null, list_salary(['sal_min' => null]), 'nothing to show');
    assert_same(null, list_salary(['inf_salary_min' => 0, 'sal_min' => null]), 'a zero is not a salary');
    assert_same(null, list_salary([]), 'an old export without the columns');
    // 261016: net pay, estimated from the same gross (CAS 25 %, CASS 10 %, tax 10 % after art. 77's deduction).
    assert_same(2981, net_from_gross(5000), '5.000 brut: deduction 13 % of the minimum wage');
    assert_same(3802, net_from_gross(6500), 'above minimum wage + 2.000 there is no deduction: 58.5 %');
    assert_same(2616, net_from_gross(4325), 'at the minimum wage: 20 % deduction (facility left out on purpose)');
    assert_same('≈ 2.485–3.090 lei net', net_label(4100, 5200), 'a range stays a range');
    assert_same('', net_label(null), 'no gross, no net');

    $html = get_html($base, '/?status=all');
    $x = xpath_for($html);
    $row = $x->query('//li[@data-pref-id="1001"]//span[contains(@class,"pg-salary") and not(contains(@class,"pg-salary-"))]');
    assert_same(1, $row->length, 'posting 1001 has a pay item');
    $text = preg_replace('/\s+/', ' ', trim($row->item(0)->textContent));
    assert_contains('💰 ≈ 2.485–3.090 lei net (estimat)', $text, '261016: 💰, the net amount, "(estimat)" after it');
    assert_same('Salariu estimat', $x->query('//li[@data-pref-id="1001"]//span[contains(@class,"pg-salary-label")]')->item(0)?->getAttribute('aria-label'),
        'the icon carries the words for screen readers');
    assert_same(1, $x->query('//div[contains(@class,"pg-thead")]')->length, 'table heads ship with the rows (shown only in the table view)');
});

run_suite('employer scope and legacy params survive form serialisation and stay removable', function () use ($base) {
    $html = get_html($base, '/?employer=spitalul-clinic-judetean-cluj');
    assert_contains('<input type="hidden" name="employer" value="spitalul-clinic-judetean-cluj">', $html, 'hidden scope input');
    assert_contains('Doar angajatorul', $html, 'scope chip group');
    assert_contains('Spitalul Clinic Județean Cluj', $html, 'scope chip names the employer');
    assert_contains('data-chip-param="employer"', $html, 'scope chip removable');
    assert_not_contains('name="employer"', get_html($base, '/posturi/'), 'no scope input without scope');
});

run_suite('feed links carry every active filter and never page/sort', function () use ($base) {
    $qs = 'judet%5B%5D=cluj&judet%5B%5D=bacau&skill%5B%5D=Excel&skill_mode=any&eqf%5B%5D=4&employer=spitalul-clinic-judetean-cluj&status=all&page=1&sort=deadline';
    $html = get_html($base, '/?' . $qs);
    foreach (['posturi.atom', 'posturi.json', 'posturi.ics'] as $f) {
        preg_match('#data-feed="' . preg_quote($f, '#') . '"\s+href="([^"]+)"#', $html, $m);
        $href = html_entity_decode($m[1] ?? '');
        assert_true($href !== '', "$f link present");
        parse_str((string)parse_url($href, PHP_URL_QUERY), $q);
        assert_same(['cluj', 'bacau'], $q['judet'] ?? null, "$f keeps the county array");
        assert_same(['Excel'], $q['skill'] ?? null, "$f keeps skills");
        assert_same('any', $q['skill_mode'] ?? null, "$f keeps the OR/AND mode");
        assert_same(['4'], $q['eqf'] ?? null, "$f keeps EQF");
        assert_same('spitalul-clinic-judetean-cluj', $q['employer'] ?? null, "$f keeps employer scope");
        assert_same('all', $q['status'] ?? null, "$f keeps status");
        assert_false(array_key_exists('page', $q), "$f drops page");
        assert_false(array_key_exists('sort', $q), "$f drops sort");
    }
    // An old ?title= link: no feed link carries it on; the calendar takes its name from the filters.
    $t = get_html($base, '/?title=Calendarul+meu&judet%5B%5D=cluj');
    preg_match('#data-feed="posturi.atom"\s+href="([^"]+)"#', $t, $m);
    assert_not_contains('title=', html_entity_decode($m[1] ?? ''), 'Atom link: title is not a filter');
    preg_match('#data-feed="posturi.ics"\s+href="([^"]+)"#', $t, $m);
    assert_not_contains('title=', html_entity_decode($m[1] ?? ''), 'iCal link: title dropped');
    // Feeds themselves honour the same filters (feed set == list set for EQF).
    $json = json_decode(get_html($base, '/posturi.json?eqf%5B%5D=4&status=all'), true);
    $ids = array_map(fn($r) => (int)($r['id'] ?? 0), $json['results'] ?? []);
    sort($ids);
    assert_same(result_ids($base, 'eqf%5B%5D=4&status=all'), $ids, 'JSON feed matches the list for the same filter');
});

run_suite('no-JS: the form submits by GET with selections intact', function () use ($base) {
    $html = get_html($base, '/?judet%5B%5D=cluj&status=soon&q=spital');
    assert_contains('hx-get="/"', $html, 'form targets the list');
    assert_same(1, xpath_for($html)->query('//form[@id="filter-form"]')->length, 'one filter form');
    assert_same(0, xpath_for($html)->query('//form[@id="filter-form"][@method="post"]')->length, 'GET, not POST');
    $x = xpath_for($html)->query('//input[@type="checkbox" and @name="judet[]" and @value="cluj" and @checked]');
    assert_same(1, $x->length, 'judet[]=cluj selected');
    assert_same(1, xpath_for($html)->query('//select[@name="status"]/option[@value="soon" and @selected]')->length, 'status=soon selected');
    assert_contains('value="spital"', $html, 'search text kept');
    assert_contains('Termen în 7 zile', $html, 'status options render without JS');
});

run_suite('exclusions: "fără" drops postings with the value, chips and controls follow', function () use ($base) {
    $all = result_ids($base, 'status=all');
    $fam = result_ids($base, 'status=all&family%5B%5D=s%C4%83n%C4%83tate');
    $not = result_ids($base, 'status=all&family_not%5B%5D=s%C4%83n%C4%83tate');
    assert_true(count($fam) > 0, 'some sănătate postings');
    assert_same([], array_values(array_intersect($fam, $not)), 'excluded postings are gone');
    assert_same(array_values(array_diff($all, $fam)), $not, 'and nothing else is');
    // A role group: no role of the posting may hold the excluded skill.
    $excel = result_ids($base, 'status=all&skill%5B%5D=Excel&skill_mode=any');
    $no_excel = result_ids($base, 'status=all&skill_not%5B%5D=Excel');
    assert_true(count($excel) > 0, 'some Excel postings');
    assert_same([], array_values(array_intersect($excel, $no_excel)), 'no Excel posting survives its exclusion');
    $html = get_html($base, '/?status=all&skill_not%5B%5D=Excel');
    assert_same(1, xpath_for($html)->query('//input[@name="skill_not[]" and @value="Excel" and @checked]')->length, 'the switch is on');
    assert_contains('data-chip-param="skill_not"', $html, 'a chip removes it');
    assert_contains('Fără', $html, 'the chip says Fără');
    assert_same(['skill'], open_facets($html), 'its group opens');
    // Every value row of an excludable group has the switch; a merged yes/no switch does not.
    $home = get_html($base, '/?status=all');
    assert_true(xpath_for($home)->query('//details[@data-facet="credential"]//input[@name="credential_not[]"]')->length > 0, 'credential values are excludable');
    assert_same(0, xpath_for($home)->query('//details[@data-facet="schedule"]//input[contains(@name,"_not")]')->length, 'Program is not');
});

run_suite('occupation tree (COR) and medical specialty filter by prefix', function () use ($base) {
    assert_same([1001, 1006], result_ids($base, 'status=all&cor%5B%5D=22'), 'Sănătate: nurse and doctor');
    assert_same([1006], result_ids($base, 'status=all&cor%5B%5D=221'), 'Medici only');
    assert_same([1005], result_ids($base, 'status=all&cor%5B%5D=9'), 'a posting coded only as major group 9');
    assert_same([1006], result_ids($base, 'status=all&medspec%5B%5D=Cardiologie'), 'specialty');
    $html = get_html($base, '/?status=all&cor%5B%5D=221');
    $x = xpath_for($html);
    assert_same(1, $x->query('//details[@data-facet="cor"]//input[@name="cor[]" and @value="221" and @checked]')->length, 'the minor group is checked');
    assert_same(0, $x->query('//div[@data-tree-children="cor:22"][@hidden]')->length, 'its branch is open');
    assert_same(1, $x->query('//div[@data-tree-children="cor:2"][not(@hidden)]')->length, 'and its parent branch');
    assert_contains('data-chip-param="cor"', $html, 'chip');
    assert_contains('Medici', $html, 'labelled');
    $home = get_html($base, '/?status=all');
    assert_true(xpath_for($home)->query('//details[@data-facet="cor"]//button[contains(@class,"facet-twisty")]')->length > 0, 'twisties on the tree');
    assert_true(xpath_for($home)->query('//div[@data-tree-children][@hidden]')->length > 0, 'branches start closed');
});

run_suite('pay meter: 1–5 against the median of open postings, said in words', function () use ($base) {
    $html = get_html($base, '/?status=all');
    $x = xpath_for($html);
    $m = $x->query('//span[contains(@class,"pay-meter")]');
    assert_true($m->length > 0, 'rows with pay carry a meter');
    $levels = [];
    foreach ($m as $el) {
        $lvl = (int)$el->getAttribute('data-level');
        $levels[$lvl] = true;
        assert_true($lvl >= 1 && $lvl <= 5, 'level in 1..5');
        assert_same($lvl, $x->query('.//i[@class="on"]', $el)->length, 'as many bars on as the level');
        assert_contains('din 5 față de posturile active (mediana', $el->getAttribute('aria-label'), 'said in words');
        assert_contains(' lei net)', $el->getAttribute('aria-label'), 'the median is net');
        $pdo = new PDO('sqlite:' . $GLOBALS['db_path']);
        $vals = $pdo->query("SELECT COALESCE(inf_salary_min, sal_min) FROM job_postings WHERE application_status IN (" . OPEN_STATUS_SQL . ") AND COALESCE(inf_salary_min, sal_min) > 0 ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
        preg_match('/mediana ([\d.]+) lei net/', $el->getAttribute('aria-label'), $mm);
        assert_same(net_from_gross((float)$vals[(int)floor(0.5 * count($vals))]), (int)str_replace('.', '', $mm[1] ?? ''), 'the median is the net of the gross median');
    }
    $top = get_html($base, '/?status=all&sort=salary');
    assert_contains('data-level="5"', $top, 'the best-paid rows are in the top fifth');
    // Rows show the regime read off the text, not the source's categorie.
    assert_not_contains('Funcționari publici</span>', $html, 'no source categorie in the fact line');
});

run_suite('filter list order: Județ, Localitate, Domeniu profesional, then the rest; Specialitate medicală above Documente necesare', function () {
    $src = (string)file_get_contents(__DIR__ . '/../partials/facets.php');
    preg_match_all("/^\\s*facet_group\\('([^']+)'/m", $src, $m);
    $labels = $m[1];
    assert_same(['Județ', 'Localitate', 'Domeniu profesional', 'Instituție', 'Sector angajator', 'Nivel angajator', 'Grupă ocupațională', 'Ocupație'],
        array_slice($labels, 0, 8), 'the first eight groups');
    $i = array_search('Documente necesare', $labels, true);
    assert_same('Specialitate medicală', $labels[$i - 1] ?? '', 'Specialitate medicală is directly above Documente necesare');
    assert_same(1, count(array_keys($labels, 'Domeniu profesional', true)), 'Domeniu profesional appears once');
    assert_same(1, count(array_keys($labels, 'Specialitate medicală', true)), 'Specialitate medicală appears once');
});

// ---- UX salary-net: the net buckets, the postings with no estimate, the note ----

run_suite('net thresholds as gross thresholds: the filter and the displayed figure agree at every edge', function () {
    foreach ([3000 => 5036.0, 4000 => 6837.0, 5000 => 8548.0, 7000 => 11967.0] as $net => $gross) {
        assert_same($gross, gross_for_net($net), "gross threshold of $net lei net");
        assert_true(net_from_gross($gross) >= $net, "the threshold itself shows $net or more");
        assert_true(net_from_gross($gross - 0.01) < $net, "one cent below shows less than $net");
        // net_from_gross() dips by a few lei at some steps of the deduction; none of them crosses a threshold.
        $bad = 0;
        for ($c = (int)round($gross * 100) - 3000; $c <= (int)round($gross * 100) + 3000; $c++) {
            $above = net_from_gross($c / 100) >= $net;
            if ($above !== ($c / 100 >= $gross)) $bad++;
        }
        assert_same(0, $bad, "no cent within 30 lei of $gross disagrees with the threshold");
    }
    assert_same(salary_bucket_label('net-peste-7000'), 'peste 7.000 lei net', 'net label');
    assert_same(salary_bucket_label('net-peste-5000'), 'peste 5.000 lei net', 'a legacy net value keeps its label');
    assert_same('peste 7.000 lei brut', salary_bucket_label('peste-7000'), 'an old value says "brut"');
    assert_same('fără salariu estimat', salary_bucket_label('fara-estimare'), 'none label');
});

/** A fixture database whose figures sit on the edges of the net buckets. Returns [path, rows id => gross|null]. */
function salary_db(): array {
    $path = sys_get_temp_dir() . '/posturi-salary-' . getmypid() . '.sqlite';
    build_fixture_db($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("UPDATE job_postings SET sal_min = NULL, sal_max = NULL, inf_salary_min = NULL, inf_salary_max = NULL");
    // id => [inf_salary_min, sal_min]. The employer of 1001, 1006 and 1007 has a figure on every posting.
    $rows = [
        1001 => [null, 5035.0],   // net 2.999: under 3.000
        1006 => [null, 5036.0],   // net 3.000: from 3.000
        1007 => [null, 6836.0],   // net 3.999
        1002 => [null, 6837.0],   // net 4.000
        1004 => [null, 8547.0],   // net 4.999
        1005 => [null, 8548.0],   // net 5.000
        1008 => [9000.0, 3000.0], // the stated figure wins: net 5.000–7.000
        2001 => [0.0, null],      // a zero is no figure
        2002 => [null, 2500.0],   // net under 3.000
        2003 => [null, 20000.0],  // net 7.000+
        2004 => [null, 5035.99],  // a fraction under the threshold
        2005 => [null, 11966.99], // net 6.999: under 7.000
        2006 => [null, 11967.0],  // net 7.000
    ];
    $up = $pdo->prepare("UPDATE job_postings SET inf_salary_min = ?, inf_salary_max = ?, sal_min = ?, sal_max = ? WHERE id = ?");
    foreach ($rows as $id => [$inf, $sal]) $up->execute([$inf, $inf, $sal, $sal, $id]);
    return [$path, $rows];
}

[$sal_path, $sal_rows] = salary_db();
$sal_srv = start_fixture_server($sal_path);
$sb = $sal_srv['base'];

run_suite('each net bucket and "fara-estimare" select the postings whose displayed net is in the bucket', function () use ($sb, $sal_rows) {
    $active = result_ids($sb, '');
    // The bucket of each active posting from its displayed net figure, not from gross_for_net().
    $want = ['net-sub-3000' => [], 'net-3000-4000' => [], 'net-4000-5000' => [], 'net-5000-7000' => [], 'net-peste-7000' => [], 'fara-estimare' => []];
    foreach ($active as $id) {
        [$inf, $sal] = $sal_rows[$id] ?? [null, null];
        $g = ($inf !== null) ? $inf : $sal;
        if ($g === null || $g <= 0) { $want['fara-estimare'][] = $id; continue; }
        $n = net_from_gross($g);
        $k = $n < 3000 ? 'net-sub-3000' : ($n < 4000 ? 'net-3000-4000' : ($n < 5000 ? 'net-4000-5000' : ($n < 7000 ? 'net-5000-7000' : 'net-peste-7000')));
        $want[$k][] = $id;
    }
    assert_true(count($want['net-3000-4000']) > 0 && count($want['net-4000-5000']) > 0 && count($want['net-5000-7000']) > 0 && count($want['net-peste-7000']) > 0, 'the fixture fills every net bucket');
    $total = 0;
    foreach ($want as $key => $ids) {
        assert_same($ids, result_ids($sb, 'salary_bucket=' . $key), "ids of $key");
        $total += count($ids);
    }
    assert_same(count($active), $total, 'the six buckets add up to the active total');
    // The legacy value "peste 5.000" (SALARY_NET_LEGACY) selects the two buckets above 5.000.
    $over5 = array_merge($want['net-5000-7000'], $want['net-peste-7000']); sort($over5);
    $legacy = result_ids($sb, 'salary_bucket=net-peste-5000'); sort($legacy);
    assert_same($over5, $legacy, 'net-peste-5000 = 5.000–7.000 + peste 7.000');
    // Literal edges: 5035 and 5035.99 are under 3.000 net, 5036 is in, 6836 / 6837 and 8547 / 8548 likewise.
    assert_true(in_array(1001, $want['net-sub-3000'], true) && in_array(2004, $want['net-sub-3000'], true), '5.035 and 5.035,99 gross: under 3.000 net');
    assert_true(in_array(1006, $want['net-3000-4000'], true) && in_array(1007, $want['net-3000-4000'], true), '5.036 and 6.836 gross: 3.000–4.000 net');
    assert_true(in_array(1002, $want['net-4000-5000'], true) && in_array(1004, $want['net-4000-5000'], true), '6.837 and 8.547 gross: 4.000–5.000 net');
    assert_true(in_array(1005, $want['net-5000-7000'], true) && in_array(1008, $want['net-5000-7000'], true), '8.548 gross and a stated figure: 5.000–7.000 net');
    assert_true(in_array(2001, $want['fara-estimare'], true), 'a zero figure has no estimate');
});

run_suite('the displayed net and the bucket agree row by row', function () use ($sb) {
    $x = xpath_for(get_html($sb, '/?salary_bucket=net-3000-4000'));
    $rows = $x->query('//li[@data-pref-id]//span[contains(@class,"pg-salary-value")]');
    assert_true($rows->length >= 2, 'rows with a figure');
    foreach ($rows as $r) {
        assert_true(preg_match('/^≈ ([\d.]+)(?:–([\d.]+))? lei net$/u', trim($r->textContent), $m) === 1, 'a net figure: ' . trim($r->textContent));
        $lo = (int)str_replace('.', '', $m[1]);
        assert_true($lo >= 3000 && $lo < 4000, "the lower net figure $lo is in 3.000–4.000");
    }
});

run_suite('the salary filter lists the six options with the counts of their links', function () use ($sb) {
    $html = get_html($sb, '/posturi/');
    $x = xpath_for($html);
    $vals = [];
    foreach ($x->query('//input[@name="salary_bucket[]"]') as $in) $vals[] = $in->getAttribute('value');
    assert_same(['net-sub-3000', 'net-3000-4000', 'net-4000-5000', 'net-5000-7000', 'net-peste-7000', 'fara-estimare'], $vals, 'options in order; no old gross or legacy net value');
    foreach ($vals as $v) {
        $in = $x->query('//input[@name="salary_bucket[]" and @value="' . $v . '"]')->item(0);
        $label = trim(preg_replace('/\s+/', ' ', $in->parentNode->textContent));
        assert_same(count(result_ids($sb, 'salary_bucket=' . $v)), paren_or_trailing($label), "count of $v");
    }
    assert_contains('Net lunar estimat, de la gradația minimă cerută, cu indemnizația de hrană unde legea o dă.', $html, 'help text');
});

run_suite('salary_bucket is a multi-value parameter: values combine with OR', function () use ($sb) {
    $a = result_ids($sb, 'salary_bucket%5B%5D=net-5000-7000');
    $b = result_ids($sb, 'salary_bucket%5B%5D=net-peste-7000');
    $none = result_ids($sb, 'salary_bucket%5B%5D=fara-estimare');
    $low = result_ids($sb, 'salary_bucket%5B%5D=net-sub-3000');
    $both = result_ids($sb, 'salary_bucket%5B%5D=net-5000-7000&salary_bucket%5B%5D=net-peste-7000');
    $want = array_merge($a, $b); sort($want); sort($both);
    assert_true(count($a) > 0 && count($b) > 0, 'the fixture fills both ranges');
    assert_same($want, $both, 'two ranges: the union of the two lists');
    assert_same(count($a) + count($b), count($both), 'the count is the sum of the two counts');
    $mix = result_ids($sb, 'salary_bucket%5B%5D=net-sub-3000&salary_bucket%5B%5D=fara-estimare'); sort($mix);
    $want = array_merge($low, $none); sort($want);
    assert_same($want, $mix, 'a range and "fără salariu estimat": both sets');
    // An unknown value among known ones is dropped; unknown only: no filter.
    assert_same($a, result_ids($sb, 'salary_bucket%5B%5D=net-5000-7000&salary_bucket%5B%5D=nope'), 'an unknown value is dropped');
    assert_same(count(result_ids($sb, '')), count(result_ids($sb, 'salary_bucket%5B%5D=nope')), 'only an unknown value: no filter');
    // The legacy scalar URL is the same search.
    assert_same($a, result_ids($sb, 'salary_bucket=net-5000-7000'), 'scalar and array give the same list');
    assert_same(search_key(['salary_bucket' => 'net-3000-4000']), search_key(['salary_bucket' => ['net-3000-4000']]), 'scalar and array give the same search key');
    assert_same('salary_bucket%5B%5D=net-3000-4000', search_key(['salary_bucket' => 'net-3000-4000']), 'the key is the multi-value form');
    // Both options stay checked, and each option count is still the count of its own link.
    $x = xpath_for(get_html($sb, '/?salary_bucket%5B%5D=net-5000-7000&salary_bucket%5B%5D=net-peste-7000'));
    assert_same(2, $x->query('//input[@name="salary_bucket[]" and @checked]')->length, 'both boxes are checked');
    // The legacy value: the chip.
    assert_contains('peste 5.000 lei net', get_html($sb, '/?salary_bucket%5B%5D=net-peste-5000'), 'the legacy value keeps its chip');
    // The note: a range -> note with link to fara-estimare[] only; none only -> the "none" form; both -> no note.
    $has = fn(string $qs) => str_contains(get_html($sb, '/?' . $qs), 'data-salary-note');
    assert_true($has('salary_bucket%5B%5D=net-5000-7000&salary_bucket%5B%5D=net-peste-7000'), 'two ranges: the note');
    assert_true($has('salary_bucket%5B%5D=fara-estimare'), 'none only: the note, in its own form');
    assert_true(!$has('salary_bucket%5B%5D=net-sub-3000&salary_bucket%5B%5D=fara-estimare'), 'a range and none: no note');
});

/** The trailing number of "peste 5.000 lei net 4". */
function paren_or_trailing(string $label): int {
    return preg_match('/(\d[\d.]*)\s*$/u', $label, $m) ? (int)str_replace('.', '', $m[1]) : -1;
}

run_suite('the salary range after the result count: lowest–highest net, the median from 5 figures, "K din N" (owner, 2026-11-18)', function () use ($sb, $sal_rows) {
    $range = function (string $qs) use ($sb): string {
        $x = xpath_for(get_html($sb, $qs === '' ? '/posturi/' : '/?' . $qs));   // bare `/` is the landing page (ADR 0004)
        $n = $x->query('//*[@data-salary-range]');
        return $n->length ? trim(preg_replace('/\s+/u', ' ', $n->item(0)->textContent)) : '';
    };
    $fmt = fn(float $v): string => number_format((int)round($v), 0, ',', '.');
    foreach (['', 'salary_bucket%5B%5D=net-3000-4000&salary_bucket%5B%5D=net-4000-5000'] as $qs) {
        $ids = result_ids($sb, $qs);
        $lo = [];
        foreach ($ids as $id) {
            [$inf, $sal] = $sal_rows[$id] ?? [null, null];
            $g = ($inf !== null && $inf > 0) ? $inf : $sal;   // the fixture has min = max on every row
            if ($g !== null && $g >= 1000) $lo[] = net_from_gross($g);
        }
        sort($lo);
        $k = count($lo);
        $want = ' · salariu net ≈ ' . ($fmt(min($lo)) === $fmt(max($lo)) ? $fmt(min($lo)) : $fmt(min($lo)) . '–' . $fmt(max($lo))) . ' lei';
        if ($k >= 5) $want .= ', mediana ' . $fmt($k % 2 ? $lo[intdiv($k, 2)] : ($lo[$k / 2 - 1] + $lo[$k / 2]) / 2) . ' lei';
        if ($k < count($ids)) $want .= ' (' . $k . ' din ' . count($ids) . ')';
        assert_same(trim($want, ' ·'), trim($range($qs), ' ·'), "the range of " . ($qs ?: 'the whole list'));
    }
    assert_same('', $range('salary_bucket%5B%5D=fara-estimare'), 'no range when no result has a figure');
});

run_suite('the note above a salary-filtered list: text, count and link', function () use ($sb) {
    $note = function (string $qs) use ($sb): array {
        $x = xpath_for(get_html($sb, '/?' . $qs));
        $n = $x->query('//*[@data-salary-note]');
        if ($n->length === 0) return ['', null, null];
        $c = $x->query('.//*[@data-salary-note-count]', $n->item(0))->item(0);
        $l = $x->query('.//a[@data-salary-note-link]', $n->item(0))->item(0);
        return [trim(preg_replace('/\s+/', ' ', $n->item(0)->textContent)), $c ? (int)$c->textContent : null, $l?->getAttribute('href')];
    };
    $first = 'Filtrul de salariu arată doar anunțurile cu salariu anunțat sau estimat.';
    // A net bucket: N = the postings of the other filters with no figure; the link opens exactly those.
    [$text, $n, $href] = $note('salary_bucket=net-peste-5000');
    $none = count(result_ids($sb, 'salary_bucket=fara-estimare'));
    assert_true($none > 0, 'the fixture has postings with no figure');
    assert_same($none, $n, 'N = the postings with no estimate');
    assert_contains("$first $none anunțuri care se potrivesc cu celelalte filtre nu au o estimare. Vezi-le", $text, 'net bucket: the note');
    assert_same('?salary_bucket%5B%5D=fara-estimare', $href, 'the link: the same search with fara-estimare');
    assert_same($n, list_count($sb, '/' . $href), 'the count of the link equals N');
    // An old gross value gets the same note.
    [$text, $n] = $note('salary_bucket=peste-7000');
    assert_same($none, $n, 'old bucket: the same N');
    assert_contains($first, $text, 'old bucket: the note');
    // The other filters count in N: with an employer scope the count follows.
    [, $n, $href] = $note('salary_bucket=net-sub-3000&employer=spitalul-clinic-judetean-cluj');
    assert_same(null, $n, 'employer scope: its postings all have a figure, so N = 0 and no count is shown');
    [$text, , $href] = $note('salary_bucket=net-sub-3000&employer=spitalul-clinic-judetean-cluj');
    assert_same($first, $text, 'N = 0: the first sentence only');
    assert_same(null, $href, 'N = 0: no link');
    // The list with no estimate.
    [$text, $n] = $note('salary_bucket=fara-estimare');
    assert_same('Aceste anunțuri nu au un salariu anunțat și nu au putut fi estimate din grilă. De obicei titlul postului nu corespunde unei funcții din grila de salarizare.', $text, 'fara-estimare: its own note');
    assert_same(null, $n, 'fara-estimare: no count, no link');
    // No salary filter, no note; the fragment carries the note too.
    assert_not_contains('data-salary-note', get_html($sb, '/posturi/'), 'no filter, no note');
    $frag = (string)@file_get_contents($sb . '/?salary_bucket=net-peste-5000&_=1', false, stream_context_create(['http' => ['header' => "HX-Request: true\r\nHX-Target: results\r\n"]]));
    assert_contains('data-salary-note', $frag, 'the HTMX fragment carries the note');
    // The feeds carry no note.
    assert_not_contains('Filtrul de salariu', (string)@file_get_contents($sb . '/feeduri/posturi.rss?salary_bucket=net-peste-5000'), 'not in the feeds');
});

run_suite('the detail page: net first with a gross line; with no figure one line and the reason', function () use ($sb, $sal_path) {
    $pdo = new PDO('sqlite:' . $sal_path);
    $with = 1006;
    $pdo->prepare("UPDATE posting_text SET sal_json = ? WHERE posting_id = ?")->execute([json_encode(
        ['lei_min' => 5036, 'lei_max' => 6000, 'coef_min' => 1.2, 'coef_max' => 1.4, 'valoare_referinta' => 4000, 'gradatie' => 0,
         'varianta' => 'x', 'disclaimer' => 'Estimare orientativă.']), $with]);
    $none = (int)$pdo->query("SELECT id FROM job_postings WHERE id = 2001")->fetchColumn();
    assert_true($none === 2001, 'a posting with no figure');
    $h = get_html($sb, '/job/2001-x/');
    assert_contains('data-no-salary', $h, 'no figure: the line is there');
    assert_contains('Fără estimare de salariu. Titlul postului nu corespunde unei funcții din grila de salarizare.', preg_replace('/\s+/', ' ', $h), 'the reason');
    assert_not_contains('fonduri europene sunt plătite', $h, 'no EU funding: no second sentence');
    $pdo->exec("UPDATE job_postings SET funding_kind = 'pnrr' WHERE id = 2001");
    assert_contains('Posturile pe proiecte cu fonduri europene sunt plătite de obicei în afara grilei.', preg_replace('/\s+/', ' ', get_html($sb, '/job/2001-x/')), 'PNRR funding: the second sentence');
    $pdo->exec("UPDATE job_postings SET funding_kind = 'fonduri_europene' WHERE id = 2001");
    assert_contains('în afara grilei', get_html($sb, '/job/2001-x/'), 'EU funding: the second sentence');
    assert_true($with > 0, 'a posting with the estimate block');
    $h = preg_replace('/\s+/', ' ', get_html($sb, '/job/' . $with . '-x/'));
    assert_true(preg_match('/data-pay-net>\s*(≈ [\d.–]+ lei net)/u', $h, $m) === 1, 'the net range is the main figure');
    assert_true(preg_match('/data-pay-gross>brut: ([\d.–]+) lei \/ lună, la gradația 0</u', $h, $m2) === 1, 'the gross range is one smaller line');
    assert_not_contains('data-no-salary', $h, 'a figure: no "no estimate" line');
    assert_not_contains('data-pay-stated', $h, 'no stated salary: no stated box');
    // A stated salary wins: one box "Salariu anunțat", the estimate as one note in it, the box first in the strip.
    $pdo->exec("UPDATE job_postings SET inf_salary_min = 4700, inf_salary_max = 4700 WHERE id = $with");
    $h = preg_replace('/\s+/', ' ', get_html($sb, '/job/' . $with . '-x/'));
    assert_contains('data-pay-stated', $h, 'the stated box');
    assert_contains('Salariu anunțat', $h, 'its heading');
    assert_true(preg_match('/data-pay-gross>brut: 4\.700 lei \/ lună, din anunț</u', $h) === 1, 'the stated gross line');
    assert_contains('Estimare după Legea 153/2017: ' . net_label(5036, 6000) . ' (brut 5.036–6.000 lei) / lună, la gradația 0.', $h, 'the estimate as a note');
    assert_not_contains('Salariu estimat', $h, 'no estimate box');
    assert_not_contains('Cum a fost calculat', $h, 'no estimate details');
    $sal_at = strpos($h, 'data-pay-stated');
    foreach (['Condiții deduse automat', 'Condiții contractuale', 'Tematica examenului'] as $later) {
        $at = strpos($h, $later);
        if ($at !== false) assert_true($sal_at < $at, "the salary is before \"$later\"");
    }
    // Stated, no estimate: the box with no note.
    $pdo->prepare("UPDATE posting_text SET sal_json = NULL WHERE posting_id = ?")->execute([$with]);
    $h = get_html($sb, '/job/' . $with . '-x/');
    assert_contains('data-pay-stated', $h, 'stated, no estimate: the box');
    assert_not_contains('data-pay-law', $h, 'stated, no estimate: no note');
    $pdo->exec("UPDATE job_postings SET inf_salary_min = NULL, inf_salary_max = NULL WHERE id = $with");
});

stop_fixture_server($sal_srv, $sal_path);

stop_fixture_server($srv, $db_path);
finish();
