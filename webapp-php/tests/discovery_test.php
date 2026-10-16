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
    $home = get_html($base, '/');
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
        'salary_bucket=3000-4000'       => ['salary_bucket', 'salary_bucket', '3000-4000'],
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
    $home = get_html($base, '/');
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
    $home = get_html($base, '/');
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
    assert_not_contains('(estimat)', $emp, 'employer listing: old bare qualification gone');
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
    assert_contains('(estimat)', get_html($base, '/?status=all&salary_bucket=3000-4000'), 'salary stays labelled as an estimate');
});

run_suite('result rows show pay: the stated salary first, else the labelled estimate', function () use ($base) {
    // Same precedence as SALARY_EXPR (the salary filter), so a row and a bucket never disagree.
    assert_same(['label' => 'Salariu estimat', 'text' => '4.100–5.200 lei', 'estimated' => true],
        list_salary(['sal_min' => 4100.0, 'sal_max' => 5200.0]), 'grid estimate, a range');
    assert_same('3.300 lei', list_salary(['sal_min' => 3300, 'sal_max' => 3300])['text'], 'one figure when min = max');
    assert_same('3.300 lei', list_salary(['sal_min' => 3300, 'sal_max' => null])['text'], 'no max: the min alone');
    assert_same(['label' => 'Salariu anunțat', 'text' => '6.000 lei', 'estimated' => false],
        list_salary(['inf_salary_min' => 6000, 'inf_salary_max' => 6000, 'sal_min' => 4100, 'sal_max' => 5200]), 'a stated salary wins');
    assert_same(null, list_salary(['sal_min' => null]), 'nothing to show');
    assert_same(null, list_salary(['inf_salary_min' => 0, 'sal_min' => null]), 'a zero is not a salary');
    assert_same(null, list_salary([]), 'an old export without the columns');
    // 261016: net pay, estimated from the same gross (CAS 25 %, CASS 10 %, tax 10 % after art. 77's deduction).
    assert_same(2981, net_from_gross(5000), '5.000 brut: deduction 13 % of the minimum wage');
    assert_same(3802, net_from_gross(6500), 'above minimum wage + 2.000 there is no deduction: 58.5 %');
    assert_same(2616, net_from_gross(4325), 'at the minimum wage: 20 % deduction (facility left out on purpose)');
    assert_same('≈ 2.485–3.090 lei net', net_label(4100, 5200), 'a range stays a range');
    assert_same('', list_salary_net(['sal_min' => null]), 'no gross, no net');
    assert_same(net_label(6000, 6000), list_salary_net(['inf_salary_min' => 6000, 'inf_salary_max' => 6000, 'sal_min' => 4100]), 'net follows the stated salary first');

    $html = get_html($base, '/?status=all');
    $x = xpath_for($html);
    $row = $x->query('//li[@data-pref-id="1001"]//span[contains(@class,"pg-salary") and not(contains(@class,"pg-salary-"))]');
    assert_same(1, $row->length, 'posting 1001 has a pay item');
    $text = preg_replace('/\s+/', ' ', trim($row->item(0)->textContent));
    assert_contains('💰 4.100–5.200 lei (estimat)', $text, '261016: 💰, the amount, "(estimat)" after it');
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
    assert_not_contains('name="employer"', get_html($base, '/'), 'no scope input without scope');
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
    // The calendar title is a calendar label only.
    $t = get_html($base, '/?title=Calendarul+meu&judet%5B%5D=cluj');
    preg_match('#data-feed="posturi.atom"\s+href="([^"]+)"#', $t, $m);
    assert_not_contains('title=', html_entity_decode($m[1] ?? ''), 'Atom link: title is not a filter');
    preg_match('#data-feed="posturi.ics"\s+href="([^"]+)"#', $t, $m);
    assert_contains('title=Calendarul', html_entity_decode($m[1] ?? ''), 'iCal link still carries the calendar title');
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
    }
    $top = get_html($base, '/?status=all&sort=salary');
    assert_contains('data-level="5"', $top, 'the best-paid rows are in the top fifth');
    // Rows show the regime read off the text, not the source's categorie.
    assert_not_contains('Funcționari publici</span>', $html, 'no source categorie in the fact line');
});

stop_fixture_server($srv, $db_path);
finish();
