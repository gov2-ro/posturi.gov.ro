<?php
/**
 * Themed landing pages (docs/briefs/2026-11-17-themed-landing-sanatate.md): `/sanatate/`.
 *
 * Server-rendered checks against the theme fixture (tests/fixtures/theme_db.php) on the built-in router: the
 * route and its redirect, the canonical URL, the sitemap, that every count equals the result count of its link,
 * that an item with count 0 is not on the page, the limit of the specialties, the other-employers line, and that a
 * second theme, added through POSTURI_THEMES_EXTRA, renders with the same template. Browser behaviour (the county
 * selection, widths) is in tests/browser/theme.spec.js.
 *
 * Usage: php tests/theme_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../inc/themes.php';
require_once __DIR__ . '/fixtures/theme_db.php';
require_once __DIR__ . '/fixtures/server.php';
require_once __DIR__ . '/fixtures/page_helpers.php';
require_once __DIR__ . '/../assets/icons/healthicons/build-sprite.php';

putenv('POSTURI_TODAY=2026-10-03');
const HEALTH = 'family%5B%5D=s%C4%83n%C4%83tate';
// The county map is in the first screen (section h-theme), so the "counties" section has no section of its own.
const SECTION_IDS = ['h-theme', 'h-specialitati', 'h-ocupatii', 'h-studii', 'h-noi', 'h-notificari'];

function theme_db(string $tag, string $extra_sql = ''): string {
    $path = sys_get_temp_dir() . "/posturi-theme-$tag-" . getmypid() . '.sqlite';
    build_theme_fixture_db($path);
    if ($extra_sql !== '') {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec($extra_sql);
    }
    return $path;
}

/** Run PHP code against a database file (the clock is 2026-10-03); returns its output. */
function php_on(string $db, string $code): string {
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        'putenv("POSTURI_DB=' . $db . '"); putenv("POSTURI_TODAY=2026-10-03"); require "' . __DIR__ . '/../db.php"; require "' . __DIR__ . '/../helpers.php"; ' . $code));
}

/** The `[data-home-tile]` items of a page: id => ['href', 'count' (in brackets), 'total', 'label']. */
function tile_items(DOMXPath $x): array {
    $out = [];
    foreach ($x->query('//*[@data-home-tile]') as $li) {
        $id = $li->getAttribute('data-domain');
        $c = $x->query('.//*[@data-tile-count]', $li)->item(0);
        $out[$id] = [
            'href' => $x->query('.//a', $li)->item(0)->getAttribute('href'),
            'label' => trim($x->query('.//a', $li)->item(0)->textContent),
            'count' => $c ? paren_number($c->textContent) : null,
            'total' => $li->hasAttribute('data-total') ? (int)$li->getAttribute('data-total') : null,
            'in_link' => $c ? $x->query('.//a//*[@data-tile-count]', $li)->length : 0,
        ];
    }
    return $out;
}

// ---- the route, the page and its counts ----

$db = theme_db('main', "
    UPDATE job_postings SET med_specialty='[\"Oncologie\"]' WHERE id=1007;   -- not health: no row for Oncologie
");
$srv = start_fixture_server($db);
$base = $srv['base'];

run_suite('the route: /sanatate/ renders the theme, the canonical URL is /sanatate/', function () use ($base) {
    foreach (['/sanatate/', '/sanatate'] as $path) {
        [$st, $h] = http_page($base, $path);
        assert_same(200, $st, "$path answers 200");
        assert_contains('<link rel="canonical" href="' . $base . '/sanatate/">', $h, "$path canonical");
        assert_contains('<title>Posturi în sănătate — posturi.gov2.ro</title>', $h, "$path title");
        assert_contains('<meta name="description" content="Posturi în sănătate din sectorul public', $h, "$path description");
        assert_same(1, preg_match_all('#<h1[ >]#', $h), "$path has one h1");
        assert_contains('<span>Posturi în sănătate<span data-county-title></span><span data-title-count class="text-base font-normal text-ink-muted">&nbsp;(6)</span></span></h1>', $h, "$path h1");
        $hx = xp($h);
        assert_same(1, $hx->query('//h1/svg[@aria-hidden="true"]/use[@href="#i-sector-sanatate"]')->length, "$path: the title has the icon of the theme, as decoration");
        assert_not_contains('Pe județe', $h, "$path: no section 'Pe județe'");
        assert_well_formed($h, $path);
    }
    [, $h] = http_page($base, '/sanatate/');
    preg_match_all('#<section[^>]*aria-labelledby="([^"]+)"#', substr($h, (int)strpos($h, '<main')), $s);
    assert_same(SECTION_IDS, $s[1], 'sections, in order');
    // Same page for each reader: no cookie, no per-reader state, the same bytes twice.
    [, $h2, $hd] = http_page($base, '/sanatate/', ['Cookie: pg_x=1', 'Accept-Language: en']);
    assert_same($h, $h2, 'the same bytes for another reader');
    assert_true(!isset($hd['set-cookie']), 'no cookie is set');
    assert_contains('public, max-age=300, s-maxage=3600', $hd['cache-control'] ?? '', 'cached like the other pages');
    foreach (['bg-amber', 'text-slate', 'bg-white', '#1d4f91'] as $raw) assert_not_contains($raw, $h, "no raw palette: $raw");
});

run_suite('unknown slugs are 404; the other routes are not taken', function () use ($base) {
    foreach (['/nu-exista/', '/sanatate/x/', '/Sanatate/', '/sanatate.html'] as $path) {
        [$st, $h] = http_page($base, $path);
        assert_same(404, $st, "$path is 404");
        assert_not_contains('id="h-theme"', $h, "$path is not a themed page");
    }
    foreach (['/despre/' => 'despre', '/statistici/' => 'statistici', '/angajatori/' => 'angajatori'] as $path => $_) {
        assert_same(200, http_page($base, $path)[0], "$path still answers 200");
    }
});

run_suite('/sanatate/?… is a 301 to /?… with the base filter and the given parameters together', function () use ($base) {
    $cases = [
        '/sanatate/?q=asistent' => '/?' . HEALTH . '&q=asistent',
        '/sanatate/?judet%5B%5D=cluj&sort=deadline' => '/?' . HEALTH . '&judet%5B%5D=cluj&sort=deadline',
        '/sanatate/?medspec%5B%5D=Cardiologie&page=2' => '/?' . HEALTH . '&medspec%5B%5D=Cardiologie&page=2',
        '/sanatate/?family%5B%5D=juridic' => '/?family%5B%5D=juridic&family%5B%5D=s%C4%83n%C4%83tate',
        '/sanatate/?q=&judet%5B%5D=' => '/?' . HEALTH,
        '/sanatate?q=x' => '/?' . HEALTH . '&q=x',
    ];
    foreach ($cases as $path => $to) {
        [$st, , $h] = http_page($base, $path);
        assert_same(301, $st, "$path is a 301");
        assert_same($to, $h['location'] ?? '', "$path goes to $to");
    }
    // The target is the list of the theme: the same count as the page.
    [, $page] = http_page($base, '/sanatate/');
    $all = ['count' => paren_number(xp($page)->query('//h1//span[@data-title-count]')->item(0)->textContent)];
    assert_same($all['count'], list_count($base, $cases['/sanatate/?q=&judet%5B%5D=']), 'a blank q and a blank county do not change the list');
    assert_same($all['count'], list_count($base, $cases['/sanatate/?judet%5B%5D=cluj&sort=deadline']) + list_count($base, '/?' . HEALTH . '&judet%5B%5D=bacau') + list_count($base, '/?' . HEALTH . '&judet%5B%5D=ilfov'), 'the county lists add up to the theme');
});

run_suite('the sitemap lists the theme; the footer links to it, in the group "Pagini tematice"', function () use ($base) {
    [, $sm] = http_page($base, '/sitemap.xml');
    assert_contains('<loc>' . $base . '/sanatate/</loc>', $sm, 'sitemap entry');
    [, $home] = http_page($base, '/');
    $x = xp($home);
    assert_same(0, $x->query('//*[@data-home-themes]')->length, 'no line "Pagini tematice" on the landing page');
    $links = $x->query('//footer//h2[.="Pagini tematice"]/following-sibling::ul[1]//a');
    assert_same(1, $links->length, 'one themed page in the footer group');
    assert_same('/sanatate/', $links->item(0)->getAttribute('href'), 'its link');
    assert_same('Sănătate', trim($links->item(0)->textContent), 'its link text');
    assert_same(1, $x->query('//footer//a[@href="/sanatate/"]')->length, 'one footer link');
});

run_suite('every count equals the result count of its link; an item with count 0 is not on the page', function () use ($base) {
    [, $h] = http_page($base, '/sanatate/');
    $x = xp($h);
    $items = tile_items($x);
    assert_true(count($items) >= 12, 'the page has its items (' . count($items) . ')');
    foreach ($items as $id => $it) {
        if ($it['count'] === null) continue;
        assert_same($it['count'], list_count($base, $it['href']), "item $id: the count is the result count of its link");
        assert_same($it['count'], $it['total'], "item $id: data-total");
        assert_true($it['count'] > 0, "item $id: not 0");
        assert_same(0, $it['in_link'], "item $id: the count is outside the link");
        assert_same(1, preg_match('#^/\?#', $it['href']), "item $id: a list URL");
    }
    // Specific values on the fixture (see tests/fixtures/theme_db.php).
    assert_same(6, paren_number(xp($h)->query('//h1//span[@data-title-count]')->item(0)->textContent), 'the theme: six postings (the count of the title)');
    assert_same(['medspec:Cardiologie' => 3, 'medspec:Neurologie' => 2, 'medspec:Radiologie' => 1],
        array_map(fn($i) => $i['count'], array_filter($items, fn($i, $k) => str_starts_with($k, 'medspec:'), ARRAY_FILTER_USE_BOTH)), 'specialties and counts');
    assert_same(['medspec:Cardiologie', 'medspec:Neurologie', 'medspec:Radiologie'],
        array_values(array_filter(array_keys($items), fn($k) => str_starts_with($k, 'medspec:'))), 'specialties, largest first');
    assert_same(['occupation:Asistent medical' => 3, 'occupation:Medic specialist' => 2, 'occupation:Farmacist' => 1],
        array_map(fn($i) => $i['count'], array_filter($items, fn($i, $k) => str_starts_with($k, 'occupation:'), ARRAY_FILTER_USE_BOTH)), 'occupations, largest first');
    // Count 0: a specialty of a posting outside the theme, a study level nobody has, a value of no posting.
    assert_not_contains('Oncologie', $h, 'a specialty that no health posting has');
    assert_same(0, list_count($base, '/?' . HEALTH . '&medspec%5B%5D=Oncologie'), '… and its list is empty (so the page is right to hide it)');
    assert_not_contains('Doctorat', $h, 'a study level that no health posting has');
    assert_not_contains('Referent', $h, 'an occupation of a posting outside the theme');
    assert_not_contains('Muncitor necalificat', $h, 'an occupation of a posting outside the theme (2)');
    assert_same(['studies_level:master', 'studies_level:licenta', 'studies_level:postliceala', 'studies_level:liceala'],
        array_values(array_filter(array_keys($items), fn($k) => str_starts_with($k, 'studies_level:'))), 'study levels, in the order of the list of labels');
    // The brackets and the no-break space of the item.
    assert_contains('&nbsp;(6)', preg_replace('#\x{a0}#u', '&nbsp;', $h), 'the count of the first item has a no-break space before the bracket');
});

run_suite('the theme is the medical professions wherever they work', function () use ($base) {
    // 1002 is a school-sector posting (a school doctor) and is in; 1007/1008 are sector sanatate, not medical: out.
    $in = list_count($base, '/?' . HEALTH . '&sector%5B%5D=educatie');
    assert_same(1, $in, 'a posting of the family in another sector is in the theme');
    $x = xp(http_page($base, '/sanatate/')[1]);
    $other = tile_items($x)['other'];
    assert_same('Posturi nemedicale la angajatori din sănătate', $other['label'], 'the other-employers line');
    assert_same(2, $other['count'], 'sector sanatate, not the family: 1007 and 1008');
    assert_same($other['count'], list_count($base, $other['href']), 'its count is the result count of its link');
    assert_contains('family_not%5B%5D=s%C4%83n%C4%83tate', $other['href'], 'its link carries family_not[]');
    assert_contains('sector%5B%5D=sanatate', $other['href'], 'its link carries sector[]');
    // It comes directly before "Noi și pe terminate" (and after the studies), with one muted sentence after it.
    $h = http_page($base, '/sanatate/')[1];
    assert_true(strpos($h, 'data-domain="other"') < strpos($h, 'id="h-noi"'), 'the line is before "Noi și pe terminate"');
    assert_true(strpos($h, 'data-domain="other"') > strpos($h, 'id="h-studii"'), 'and after the studies');
    $note = xp($h)->query('//li[@data-domain="other"]/ancestor::div[1]/p');
    assert_same(1, $note->length, 'one note after the line');
    assert_same('Economiști, muncitori, șoferi, registratori și alte posturi din spitale și unități sanitare.', trim($note->item(0)->textContent), 'the note');
    assert_contains('g2-text-meta', $note->item(0)->getAttribute('class'), 'muted');
    assert_same(1, xp($h)->query('//li[@data-domain="other"]//*[@data-tile-count]')->length, 'the count in brackets');
});

run_suite('the search form, the county select and the county JSON', function () use ($base) {
    $h = http_page($base, '/sanatate/')[1];
    $x = xp($h);
    $f = $x->query('//form[@data-home-search]')->item(0);
    assert_same('get', strtolower($f->getAttribute('method')), 'the form is a GET');
    assert_same('/', $f->getAttribute('action'), 'it opens the list');
    $hidden = [];
    foreach ($x->query('.//input[@type="hidden"]', $f) as $i) $hidden[$i->getAttribute('name')][] = $i->getAttribute('value');
    assert_same(['family[]' => ['sănătate']], $hidden, 'the base filter is in hidden fields');
    assert_same(0, $x->query('.//input[@name="q"]', $f)->length, 'no text field: the theme has selects (the text field is in the second theme)');
    foreach ($x->query('.//select[@name="judet[]"]/option[@value!=""]', $f) as $o) {
        $slug = $o->getAttribute('value');
        $want = list_count($base, '/?' . HEALTH . '&judet%5B%5D=' . $slug);
        assert_same($want, paren_number($o->textContent), "search option $slug: the count inside the theme");
    }
    assert_same(0, $x->query('//*[@id="domenii-judet"]')->length, 'no county select of the section: the map is the control');
    assert_same(0, $x->query('//select[not(ancestor::form[@data-home-search])]')->length, 'no select outside the search form');
    assert_same(1, $x->query('//section[@aria-labelledby="h-theme"]//*[@data-county]/ancestor::*[@role="group"]')->length, 'the map is inside the first section');
    assert_not_contains('Alege un județ pe hartă', $h, 'no line under the map');
    assert_not_contains('data-county-hint', $h, 'no hint element');
    assert_same(1, $x->query('//h1/span/span[@data-county-title]')->length, 'the title has the place for the county (the script fills it)');
    assert_same(0, $x->query('//*[@data-county-clear or @data-county-picked or @data-county-name]')->length, 'no button "Toate județele" and no county name before it');
    // No checkbox "Arată toate județele" (owner request 2026-11-17): the select and the map clear the county.
    assert_same(0, $x->query('//input[@type="checkbox"]')->length, 'no checkbox under the map');
    assert_not_contains('Arată toate județele', $h, 'no checkbox label');
    // The label above the map is not visible, and the map has an accessible name.
    $lab = $x->query('//span[@id="map-label"]');
    assert_same('Posturi active pe județe', trim($lab->item(0)->textContent), 'the label stays for assistive technology');
    assert_same('sr-only', trim($lab->item(0)->getAttribute('class')), '… and is visually hidden');
    assert_same('map-label', $x->query('//*[@role="group"][contains(@class, "pg-map")]')->item(0)->getAttribute('aria-labelledby'), 'the map is named by it');
    assert_contains('<span class="g2-label" id="map-label">Posturi active pe județe</span>', http_page($base, '/')[1], 'the landing page keeps its visible label');
    // The JSON: counties sum to each item; the link of an item with a county is its list.
    preg_match('#<script type="application/json" id="home-data">(.*?)</script>#s', $h, $m);
    $data = json_decode($m[1], true);
    assert_same(6, $data['total'], 'the total of the theme');
    assert_same(['bacau' => 2, 'cluj' => 2, 'ilfov' => 2], $data['counties'], 'the counties inside the theme');
    $items = tile_items($x);
    foreach ($data['items'] as $id => $it) {
        if (!isset($items[$id]) || $items[$id]['count'] === null) continue;
        assert_same($items[$id]['count'], array_sum($it['counties']), "item $id: the counties add up");
        foreach ($it['counties'] as $county => $n) {
            $params = ['judet' => [$county]];
            foreach ($it['params'] as $field => $vals) $params[preg_replace('/\[\]$/', '', $field)] = array_values($vals);
            assert_same($n, list_count($base, '/?' . search_key($params)), "item $id × $county equals its list");
        }
    }
    assert_not_contains('</script><', substr($m[1], 0, 10), 'JSON is escaped for a script block');
    assert_same(1, $x->query('//*[@data-home-js]')->length, 'the map ships hidden (no line under it)');
    foreach ($x->query('//*[@data-home-js]') as $el) assert_true($el->hasAttribute('hidden'), 'hidden without script');
    assert_same(42, $x->query('//button[@data-county]')->length, '42 county cells');
});

run_suite('the notification cards use the feeds of the base filter; the email card links to /alerte/', function () use ($base) {
    $h = http_page($base, '/sanatate/')[1];
    $x = xp($h);
    $sec = $x->query('//section[@aria-labelledby="h-notificari"]')->item(0);
    $links = [];
    foreach ($x->query('.//a', $sec) as $a) $links[trim($a->textContent)] = $a->getAttribute('href');
    assert_same('/alerte/', $links['Cum funcționează alertele'], 'email card');
    assert_same('/posturi.ics?family%5B0%5D=s%C4%83n%C4%83tate', $links['Calendarul acestor posturi'], 'calendar feed');
    assert_same('/posturi.rss?family%5B0%5D=s%C4%83n%C4%83tate', $links['Fluxul acestor posturi'], 'RSS feed');
    [$st, $rss] = http_page($base, $links['Fluxul acestor posturi']);
    assert_same(200, $st, 'the RSS feed answers');
    assert_same(6, substr_count($rss, '<item>'), 'the feed holds the six postings of the theme');
});

run_suite('the section titles come from the theme configuration: "Studii", not "După studii"', function () use ($base) {
    [, $page] = http_page($base, '/sanatate/');
    $h = xp($page)->query('//h2[@id="h-studii"]');
    assert_same(1, $h->length, 'the studies section');
    assert_same('Studii', trim($h->item(0)->textContent), 'its title');
    assert_not_contains('După studii', $page, 'no "După studii"');
    assert_same('Studii', themes()['sanatate']['sections'][array_search('studii', array_column(themes()['sanatate']['sections'], 'id'), true)]['title'], 'the title is in the configuration');
});

run_suite('the short lists are inside the theme', function () use ($base) {
    $x = xp(http_page($base, '/sanatate/')[1]);
    $sec = $x->query('//section[@aria-labelledby="h-noi"]')->item(0);
    $heads = [];
    foreach ($x->query('.//h3/a', $sec) as $a) $heads[trim(preg_replace('/\s*→$/u', '', $a->textContent))] = $a->getAttribute('href');
    assert_same(['Noi 01.10.2026', 'Se închid în 3 zile'], array_keys($heads), 'the two headings');
    foreach ($heads as $title => $href) {
        assert_contains(HEALTH, $href, "$title: the link carries the base filter");
        $rows = $x->query('.//h3/a[@href="' . $href . '"]/ancestor::div[1]//li', $sec)->length;
        assert_true($rows > 0 && $rows <= list_count($base, $href) + 0, "$title: $rows rows, the list has " . list_count($base, $href));
    }
    // Every posting listed belongs to the theme: its title is a health one (1001 Asistent medical, 1004 …, 2001/2002).
    $ids = [];
    foreach ($x->query('.//li//a[contains(@href,"/job/")]', $sec) as $a) preg_match('#/job/(\d+)#', $a->getAttribute('href'), $mm) && $ids[] = (int)$mm[1];
    assert_same([], array_values(array_diff($ids, [1001, 1002, 1004, 1006, 2001, 2002])), 'each posting listed is a health posting');
    assert_true(count($ids) >= 2, 'the lists have rows');
});

// ---- the statistics panel, the compact lists and the icons ----

run_suite('the title has the count of the theme; the link to all follows the line of the other employers; there is no figures row', function () use ($base, $db) {
    [, $h] = http_page($base, '/sanatate/');
    $x = xp($h);
    $pdo = new PDO('sqlite:' . $db);
    $n = (int)$pdo->query("SELECT COUNT(*) FROM job_postings WHERE inf_profession_family = 'sănătate' AND application_status IN ('confirmed_open','unconfirmed','unknown')")->fetchColumn();
    assert_same(6, $n, 'the active postings of the theme on the fixture (SQL on the side)');
    $h1 = $x->query('//h1')->item(0);
    assert_same('Posturi în sănătate (6)', preg_replace('/\s+/u', ' ', trim(str_replace("\xc2\xa0", ' ', $h1->textContent))), 'the title with the count in brackets');
    $c = $x->query('.//span[@data-title-count]', $h1);
    assert_same(1, $c->length, 'one count in the title');
    $cls = $c->item(0)->getAttribute('class');
    foreach (['text-ink-muted', 'font-normal', 'text-base'] as $k) assert_contains($k, $cls, "the count: $k (muted, normal weight, smaller than the title)");
    assert_same(0, $x->query('.//svg//span[@data-title-count] | .//span[@data-title-count]/ancestor::svg', $h1)->length, 'the count is not part of the icon');
    // The link "Toate anunțurile din sănătate" is not in the first screen: it follows the line "other", with its count in brackets
    // outside the link; its list has the count of the title.
    $a = $x->query('//li[@data-domain="all"]');
    assert_same(1, $a->length, 'the link to all');
    assert_same('Toate anunțurile din sănătate (6)', preg_replace('/\s+/u', ' ', trim(str_replace("\xc2\xa0", ' ', $a->item(0)->textContent))), 'its text, with the count in brackets');
    assert_same(1, $x->query('.//*[@data-tile-count]', $a->item(0))->length, 'the count element');
    assert_same(0, $x->query('.//a//*[@data-tile-count]', $a->item(0))->length, 'the count is outside the link');
    $href = $x->query('.//a', $a->item(0))->item(0)->getAttribute('href');
    assert_same('/?' . HEALTH, $href, 'it opens the list of the base filter');
    assert_same($n, list_count($base, $href), 'the count of the title is the result count of the link');
    assert_same(paren_number($a->item(0)->textContent), $n, 'its count is the count of the title');
    assert_same(0, $x->query('//section[@aria-labelledby="h-theme"]//li[@data-domain="all"]')->length, 'not in the first screen');
    assert_true(strpos($h, 'data-domain="all"') > strpos($h, 'data-domain="other"'), 'after the line of the other employers');
    assert_true(strpos($h, 'data-domain="all"') < strpos($h, 'id="h-noi"'), 'before "Noi și pe terminate"');
    assert_same(1, $x->query('//li[@data-domain="other"]/ancestor::div[contains(@class, "lg:grid-cols-2")]//li[@data-domain="all"]')->length, 'the two are one row of two columns from lg');
    // No figures row.
    assert_same(0, $x->query('//*[@data-fig or @data-fig-n]')->length, 'no figure');
    foreach (['posturi active', 'se închid în 7 zile', '>angajatori<'] as $t) assert_not_contains($t, $h, "no figures label: $t");
    assert_same(0, $x->query('//*[@data-theme-stats]//a[contains(@href, "status=soon")]')->length, 'no 7-day link in the panel');
    // The panel (the chart) is below the link to all postings and before the map (left column, then right column).
    $pos = fn(string $needle) => strpos($h, $needle);
    assert_true($pos('data-home-search') < $pos('data-theme-stats') && $pos('data-theme-stats') < $pos('data-county='), 'order: the search form, the chart, the map');
    assert_same(1, $x->query('//*[@data-theme-stats]//figure[@data-chart]')->length, 'the chart is in the panel');
    assert_same(0, $x->query('//*[@data-theme-stats]//*[@data-home-tile or @data-home-link or @data-tile-count or @data-county]')->length, 'no hook of the county tiles inside the panel');
});

run_suite('the weekly chart of the theme counts only the theme, each posting once', function () use ($base, $db) {
    $weekly = json_decode(php_on($db, 'echo json_encode(home_weekly_postings(12, "sănătate"));'), true);
    assert_same(['2026-07-06', '2026-09-21'], [$weekly[0]['start'], end($weekly)['start']], 'twelve complete weeks');
    // 9101 (Jul 8), 9107 + 9108 (Jul 14, 15), 9001 (Aug 1) and 9109 (Aug 3), 1003 + 9102 (Aug 10 and 12), 9110 (Aug 19), 9106 (Sep 2), 2001 + 2002 (Sep 15);
    // out: 9103 (another family), 9104 (the id of 2001: once), 9105 (before Jul 6), the postings of the current week.
    assert_same([1, 2, 0, 1, 1, 2, 1, 0, 1, 0, 2, 0], array_column($weekly, 'n'), 'weeks of the theme');
    $all = json_decode(php_on($db, 'echo json_encode(home_weekly_postings(12));'), true);
    assert_true(array_sum(array_column($all, 'n')) > array_sum(array_column($weekly, 'n')), 'without the family the chart counts more (the other families)');
    [, $h] = http_page($base, '/sanatate/');
    assert_contains('Anunțuri noi pe săptămână', $h, 'the caption of the chart');
    assert_contains('min 0 · max 2 · ultima 0', $h, 'min, max, last');
    assert_not_contains('Toată țara', $h, 'no caption below the chart');
    $cap = xp($h)->query('//figure[@data-chart]/figcaption');
    assert_same('Anunțuri noi pe săptămână', trim($cap->item(0)->textContent), 'the heading of the chart stays for assistive technology');
    assert_same('sr-only', trim($cap->item(0)->getAttribute('class')), '… and is visually hidden');
    assert_not_contains('Arhiva începe', $h, 'no note about the archive');
    assert_not_contains('Ocupații, pe luni', $h, 'no month table');
    assert_same(0, xp($h)->query('//table')->length, 'no table on the page');
    assert_contains('<span>6 iul</span>', $h, 'first week: Monday 2026-07-06');
    assert_contains('<span>21 sep</span>', $h, 'last week: Monday 2026-09-21');
});

run_suite('the buttons of the chart: "Toate" and the four occupation groups, in order; the group series equal the SQL counts, none is above the total', function () use ($base, $db) {
    [, $h] = http_page($base, '/sanatate/');
    $x = xp($h);
    $fig = $x->query('//*[@data-theme-stats]//figure[@data-chart]');
    assert_same(1, $fig->length, 'one chart');
    $btn = [];
    foreach ($x->query('.//button[@data-chart-series]', $fig->item(0)) as $b) $btn[] = [trim($b->textContent), $b->getAttribute('aria-pressed'), $b->getAttribute('class')];
    assert_same(['Toate', 'Medic', 'Rezident', 'Asistent', 'Îngrijitor'], array_column($btn, 0), '"Toate" and the groups of the theme, in the order of the configuration');
    assert_same(['true', 'false', 'false', 'false', 'false'], array_column($btn, 1), '"Toate" is pressed; the others are not');
    foreach ($btn as $b) assert_contains('pg-chart-btn', $b[2], 'a text button (local class: no DS toggle yet)');
    foreach ($btn as $b) assert_not_contains('g2-chip', $b[2], 'not a chip: no border, no background');
    $grp = $x->query('.//*[@data-chart-js]', $fig->item(0))->item(0);
    assert_true($grp->hasAttribute('hidden'), 'the buttons ship hidden: without script the chart shows the total');
    assert_same('group', $grp->getAttribute('role'), 'a group');
    assert_same(1, $x->query('.//*[@data-chart-live][@role="status"][@aria-live="polite"]', $fig->item(0))->length, 'a polite live region');
    // The JSON, against SQL on the side (job_postings and the archive, each posting once, the weeks of the total).
    assert_same(1, preg_match('#<script type="application/json" id="theme-panel-data">(.*?)</script>#s', $h, $m), 'the JSON block');
    $d = json_decode($m[1], true);
    $pdo = new PDO('sqlite:' . $db);
    // The condition of each group in SQL, written on the side (not the PHP rule).
    $cond = [
        'Medic' => "occ_canonical LIKE 'Medic%' AND occ_canonical <> 'Medic rezident'",
        'Rezident' => "occ_canonical = 'Medic rezident'",
        'Asistent' => "occ_canonical LIKE 'Asistent medical%'",
        'Îngrijitor' => "occ_canonical IN ('Îngrijitor', 'Infirmier', 'Infirmieră', 'Brancardier')",
    ];
    $sql = fn(string $c, string $w0) => (int)$pdo->query("SELECT COUNT(*) FROM (
        SELECT substr(published_at,1,10) d FROM job_postings WHERE inf_profession_family='sănătate' AND ($c)
        UNION ALL SELECT substr(published_at,1,10) FROM archived_postings WHERE inf_profession_family='sănătate' AND ($c) AND id NOT IN (SELECT id FROM job_postings))
        WHERE d >= '$w0' AND d < date('$w0', '+7 days')")->fetchColumn();
    $starts = [];
    for ($i = 0, $t = new DateTimeImmutable('2026-07-06'); $i < 12; $i++) $starts[] = $t->modify("+$i weeks")->format('Y-m-d');
    assert_same(['Medic', 'Rezident', 'Asistent', 'Îngrijitor'], array_column($d['series'], 'name'), 'four series, in the order of the buttons');
    foreach ($d['series'] as $s) {
        assert_same(array_map(fn($w) => $sql($cond[$s['name']], $w), $starts), $s['values'], "group {$s['name']} equals the SQL counts, week by week");
        foreach ($s['values'] as $i => $v) assert_true($v <= $d['total'][$i], "group {$s['name']}, week $i: not above the total");
    }
    $by = array_column($d['series'], 'values', 'name');
    assert_same([0, 0, 0, 0, 0, 1, 0, 0, 1, 0, 0, 0], $by['Medic'], 'Medic: Medic specialist on 12 Aug and 2 Sep, and not the resident');
    assert_same([0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0], $by['Rezident'], 'Rezident: "Medic rezident" on 19 Aug, in this group and not in Medic');
    assert_same([1, 0, 0, 1, 0, 1, 0, 0, 0, 0, 1, 0], $by['Asistent'], 'Asistent: Asistent medical on 8 Jul, 1 Aug, 10 Aug (closed), 15 Sep');
    assert_same([0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0], $by['Îngrijitor'], 'Îngrijitor: "Infirmier" on 3 Aug');
    // A posting is in one group at most; the rest (Farmacist, Kinetoterapeut, Psiholog) is in no group.
    $grouped = array_map(fn($i) => array_sum(array_column(array_column($d['series'], 'values'), $i)), range(0, 11));
    assert_same(3, array_sum($d['total']) - array_sum($grouped), 'the postings in no group: 3');
    foreach ($d['total'] as $i => $v) assert_true($grouped[$i] <= $v, "week $i: the groups add up to at most the total");
    $total = json_decode(php_on($db, 'echo json_encode(array_column(home_weekly_postings(12, "sănătate"), "n"));'), true);
    assert_same($total, $d['total'], 'the total of the JSON is the total of the chart');
    assert_not_contains('<table', $h, 'no table');
    assert_contains('<script src="/static/theme-panel.js?v=', $h, 'the script of the buttons');
});

run_suite('the search form of /sanatate/: three selects and the button, no text field; the options are the values with a count above 0', function () use ($base) {
    [, $h] = http_page($base, '/sanatate/');
    $x = xp($h);
    $f = $x->query('//form[@data-home-search]')->item(0);
    assert_same(0, $x->query('.//input[@type="search" or @name="q"]', $f)->length, 'no text field');
    $names = [];
    foreach ($x->query('.//select', $f) as $s) $names[] = $s->getAttribute('name');
    assert_same(['medspec[]', 'occupation[]', 'judet[]'], $names, 'three selects: specialty, occupation, county');
    assert_same(1, $x->query('.//button[@type="submit" and normalize-space(.)="Caută"]', $f)->length, 'the button: its name is "Caută"');
    assert_same(1, $x->query('.//button[@type="submit"]/svg[@aria-hidden="true"]/use[@href="#i-search"]', $f)->length, 'the search icon, as decoration');
    assert_contains('lg:sr-only', $x->query('.//button[@type="submit"]/span', $f)->item(0)->getAttribute('class'), 'the text is hidden from lg');
    assert_contains('lg:flex-nowrap', $f->getAttribute('class'), 'one row from lg');
    assert_same(1, $x->query('//symbol[@id="i-search"]')->length, 'the symbol i-search is in the sprite of the header');
    foreach ($x->query('.//select', $f) as $s) {
        $id = $s->getAttribute('id');
        assert_same(1, $x->query('.//label[@for="' . $id . '"]', $f)->length, "$id has a label");
    }
    $opts = fn(string $name) => array_map(fn($o) => [$o->getAttribute('value'), trim($o->textContent)], iterator_to_array($x->query('.//select[@name="' . $name . '"]/option', $f)));
    $spec = $opts('medspec[]');
    assert_same(['', 'Toate specialitățile'], $spec[0], 'the first option names the subject');
    assert_same([['Cardiologie', 'Cardiologie'], ['Neurologie', 'Neurologie'], ['Radiologie', 'Radiologie']], array_slice($spec, 1), 'specialties with a posting in the theme, alphabetical, no count (Oncologie is outside the theme)');
    $occ = $opts('occupation[]');
    assert_same(['', 'Toate ocupațiile'], $occ[0], 'the first option of the occupations');
    assert_same(['Asistent medical', 'Farmacist', 'Medic specialist'], array_column(array_slice($occ, 1), 0), 'occupations, alphabetical');
    $cty = $opts('judet[]');
    assert_same('Toate județele', $cty[0][1], 'the county select keeps its first option');
    assert_same(1, preg_match('/\(\d+\)$/', $cty[1][1]), 'the county options keep their counts');
    // Each option value of the specialty and occupation selects has a count above 0: its list is not empty and equals the page.
    $items = tile_items($x);
    foreach (array_slice($spec, 1) as [$v]) assert_same($items['medspec:' . $v]['count'], list_count($base, '/?' . HEALTH . '&medspec%5B%5D=' . rawurlencode($v)), "specialty $v: the list of the option equals the count on the page");
    foreach (array_slice($occ, 1) as [$v]) assert_true(list_count($base, '/?' . HEALTH . '&occupation%5B%5D=' . rawurlencode($v)) > 0, "occupation $v: not empty");
    // The form sends the base filter with the chosen values; an empty select adds nothing to the final URL.
    $hidden = [];
    foreach ($x->query('.//input[@type="hidden"]', $f) as $i) $hidden[$i->getAttribute('name')][] = $i->getAttribute('value');
    assert_same(['family[]' => ['sănătate']], $hidden, 'the base filter is in hidden fields');
    $get = '/?family%5B%5D=s%C4%83n%C4%83tate&medspec%5B%5D=Cardiologie&occupation%5B%5D=&judet%5B%5D=';
    [$st, , $hd] = http_page($base, $get);
    assert_same(301, $st, 'a request with empty selects is redirected');
    assert_same('/?' . HEALTH . '&medspec%5B%5D=Cardiologie', $hd['location'] ?? '', 'to the clean URL: empty selects add no parameter');
    assert_same($items['medspec:Cardiologie']['count'], list_count($base, $hd['location']), 'the list of one specialty has the count of that specialty on the page');
    [$st, , $hd] = http_page($base, '/?family%5B%5D=s%C4%83n%C4%83tate&medspec%5B%5D=&occupation%5B%5D=&judet%5B%5D=');
    assert_same(301, $st, 'an empty submit is redirected');
    assert_same('/?' . HEALTH, $hd['location'] ?? '', 'to the base list');
    assert_same(paren_number($x->query('//h1//span[@data-title-count]')->item(0)->textContent), list_count($base, $hd['location']), 'the base list has the count of the title');
});

run_suite('the county data of the panel: the weekly values of each county equal SQL on the side; the title count of a county is the count of its list; the sums over the counties equal the national values', function () use ($base, $db) {
    [, $h] = http_page($base, '/sanatate/');
    preg_match('#<script type="application/json" id="theme-panel-data">(.*?)</script>#s', $h, $m);
    $d = json_decode($m[1], true);
    preg_match('#<script type="application/json" id="home-data">(.*?)</script>#s', $h, $m2);
    $home = json_decode($m2[1], true);
    assert_true(!isset($d['figs']) && !array_filter($d['counties'], fn($c) => isset($c['f'])), 'no data of figures in the JSON');
    $pdo = new PDO('sqlite:' . $db);
    $open = "application_status IN ('confirmed_open','unconfirmed','unknown')";
    $health = "inf_profession_family = 'sănătate'";
    $starts = [];
    for ($i = 0, $t = new DateTimeImmutable('2026-07-06'); $i < 12; $i++) $starts[] = $t->modify("+$i weeks")->format('Y-m-d');
    $cond = [
        'Medic' => "occ_canonical LIKE 'Medic%' AND occ_canonical <> 'Medic rezident'",
        'Rezident' => "occ_canonical = 'Medic rezident'",
        'Asistent' => "occ_canonical LIKE 'Asistent medical%'",
        'Îngrijitor' => "occ_canonical IN ('Îngrijitor', 'Infirmier', 'Infirmieră', 'Brancardier')",
    ];
    $weekly = function (string $county, string $group = '') use ($pdo, $health, $starts, $cond) {
        $o = $group === '' ? '' : ' AND (' . $cond[$group] . ')';
        return array_map(fn($w) => (int)$pdo->query("SELECT COUNT(*) FROM (
            SELECT substr(published_at,1,10) d FROM job_postings WHERE $health AND judet_slug=" . $pdo->quote($county) . "$o
            UNION ALL SELECT substr(published_at,1,10) FROM archived_postings WHERE $health AND judet_slug=" . $pdo->quote($county) . "$o AND id NOT IN (SELECT id FROM job_postings))
            WHERE d >= '$w' AND d < date('$w', '+7 days')")->fetchColumn(), $starts);
    };
    $names = array_column($d['series'], 'name');
    assert_same(['bacau', 'cluj', 'ilfov'], (function () use ($d) { $k = array_keys($d['counties']); sort($k); return $k; })(), 'the counties of the theme');
    $sum = ['t' => array_fill(0, 12, 0), 's' => array_fill(0, count($names), array_fill(0, 12, 0))];
    foreach ($d['counties'] as $slug => $c) {
        assert_same($weekly($slug), $c['t'] ?? array_fill(0, 12, 0), "$slug: the weekly total");
        foreach ($names as $i => $n) assert_same($weekly($slug, $n), $c['s'][$i] ?? array_fill(0, 12, 0), "$slug: the weekly series of $n");
        // The count of the title with this county (static/home.js reads it from #home-data) is the result count of the link to all with the county.
        assert_same($home['items']['all']['counties'][$slug], list_count($base, '/?' . search_key(['family' => ['sănătate'], 'judet' => [$slug]])), "$slug: the title count equals the result count of the link");
        foreach (($c['t'] ?? []) as $w => $v) $sum['t'][$w] += $v;
        foreach ($names as $i => $n) foreach (($c['s'][$i] ?? []) as $w => $v) $sum['s'][$i][$w] += $v;
    }
    // The sum rule: each week and each series, the sum over the counties is the national value of the postings that have a county;
    // the figures "active" and "7 days" too (the fixture has no posting with no county). Not for the employers.
    $national = fn(string $group = '') => array_map(fn($w) => (int)$pdo->query("SELECT COUNT(*) FROM (
            SELECT substr(published_at,1,10) d FROM job_postings WHERE $health AND judet_slug<>''" . ($group === '' ? '' : ' AND (' . $cond[$group] . ')') . "
            UNION ALL SELECT substr(published_at,1,10) FROM archived_postings WHERE $health AND judet_slug<>''" . ($group === '' ? '' : ' AND (' . $cond[$group] . ')') . " AND id NOT IN (SELECT id FROM job_postings))
            WHERE d >= '$w' AND d < date('$w', '+7 days')")->fetchColumn(), $starts);
    assert_same($national(), $sum['t'], 'the weekly total: the counties add up to the national value');
    assert_same($d['total'], $sum['t'], 'and to the total that the server draws (every posting has a county on the fixture)');
    foreach ($names as $i => $n) assert_same($national($n), $sum['s'][$i], "series $n: the counties add up to the national value");
    // The page itself is the whole country, for every reader.
    $x = xp($h);
    assert_same(6, paren_number($x->query('//h1//span[@data-title-count]')->item(0)->textContent), 'the server writes the national count');
    assert_same(6, $home['total'], 'and the national total of the JSON');
    assert_true(strlen(gzcompress($m[1])) < 15000, 'the data are small');
});

run_suite('the lists are compact: a 28 px row and a link of at least 24 px', function () use ($base) {
    [, $h] = http_page($base, '/sanatate/');
    $x = xp($h);
    $li = $x->query('//section[@aria-labelledby="h-specialitati"]//li[@data-home-tile]')->item(0);
    assert_contains('leading-7', $li->ownerDocument->saveHTML($li), 'the row has a 28 px line');
    assert_not_contains('min-h-[44px]', $li->ownerDocument->saveHTML($li), 'no 44 px row');
    assert_contains('py-[3px]', $li->ownerDocument->saveHTML($li), 'the link has 3 px of padding: a 25 px target, 3 px between two targets');
    // The landing page keeps its 44 px rows.
    assert_contains('min-h-[44px]', http_page($base, '/')[1], 'the landing page is not compact');
});

run_suite('the icons: one symbol file, each <use> points to a symbol that exists, each icon is decoration', function () use ($base) {
    $file = __DIR__ . '/../static/health-icons.svg';
    assert_same(health_sprite_source(), (string)file_get_contents($file), 'the file equals the output of build-sprite.php');
    preg_match_all('#<symbol id="([^"]+)" viewBox="0 0 48 48">#', (string)file_get_contents($file), $ids);
    assert_true(count($ids[1]) >= 30, count($ids[1]) . ' symbols');
    assert_same(count($ids[1]), count(array_unique($ids[1])), 'each id once');
    [, $h] = http_page($base, '/sanatate/');
    $x = xp($h);
    $uses = $x->query('//use[contains(@href, "health-icons.svg")]');
    assert_true($uses->length >= 3, $uses->length . ' icons on the page');
    $v = filemtime($file);
    foreach ($uses as $u) {
        $href = $u->getAttribute('href');
        assert_same(1, preg_match('#^/static/health-icons\.svg\?v=' . $v . '\#(i-health-[a-z0-9_-]+)$#', $href, $m), "href $href: file, version of the file, id");
        assert_true(in_array($m[1] ?? '', $ids[1], true), ($m[1] ?? $href) . ' is a symbol of the file');
        $svg = $u->parentNode;
        assert_same('true', $svg->getAttribute('aria-hidden'), 'aria-hidden');
        assert_same('false', $svg->getAttribute('focusable'), 'focusable=false');
        assert_contains('h-10 w-10', $svg->getAttribute('class'), '40 px');
    }
    // Specialties and occupations of the fixture.
    $icon_of = fn(string $id) => ($e = $x->query('//li[@data-domain="' . $id . '"]//use')->item(0)) ? preg_replace('/^.*#/', '', $e->getAttribute('href')) : null;
    assert_same('i-health-cardiology', $icon_of('medspec:Cardiologie'), 'Cardiologie: its own icon');
    foreach (['Medic specialist', 'Asistent medical', 'Farmacist'] as $o) {
        assert_same(1, $x->query('//li[@data-domain="occupation:' . $o . '"]')->length, "occupation $o is on the page");
        assert_same(null, $icon_of('occupation:' . $o), "$o: no icon in the section Ocupații");
    }
    assert_same(0, $x->query('//section[@aria-labelledby="h-ocupatii"]//svg')->length, 'no icon and no gap in the section Ocupații');
    assert_same(0, $x->query('//section[@aria-labelledby="h-ocupatii"]//span[@aria-hidden="true"]')->length, 'no gap in the section Ocupații');
    assert_same(0, preg_match_all('#h-(8|10) w-(8|10)#', substr($h, (int)strpos($h, 'id="h-ocupatii"'), 6000)) , 'no icon in Ocupații');
    $spec_svg = $x->query('//section[@aria-labelledby="h-specialitati"]//li[@data-home-tile]//svg')->item(0);
    assert_contains('h-10 w-10', $spec_svg->getAttribute('class'), 'the specialty icon is 40 px');
    assert_contains('lg:grid-cols-3', $x->query('//section[@aria-labelledby="h-specialitati"]/ul')->item(0)->getAttribute('class'), 'specialties: three columns from lg');
    assert_not_contains('lg:grid-cols-3', $x->query('//section[@aria-labelledby="h-ocupatii"]/ul')->item(0)->getAttribute('class'), 'occupations: the default columns');
    assert_same(null, $icon_of('studies_level:master'), 'the studies section has no icons');
    // The mapping: every symbol id of the configuration is in the file; the rules.
    $th = themes()['sanatate'];
    foreach ($th['sections'] as $sec) {
        if (($sec['type'] ?? '') !== 'values') continue;
        foreach (array_merge(array_values($sec['icons'] ?? []), isset($sec['icon_default']) ? [$sec['icon_default']] : []) as $id) {
            assert_true(in_array($id, $ids[1], true), "{$sec['id']}: $id is in the file");
        }
    }
    $occ = $th['sections'][array_search('ocupatii', array_column($th['sections'], 'id'), true)];
    assert_true(!isset($occ['icons']) && !isset($occ['icon_default']) && !isset($occ['icon_prefixes']), 'the section Ocupații has no icon setting');
    // The file holds the symbols that a section uses, and no other (the unused ones are removed).
    $used = [];
    foreach ($th['sections'] as $sec) foreach (array_merge(array_values($sec['icons'] ?? []), isset($sec['icon_default']) ? [$sec['icon_default']] : []) as $id) $used[$id] = true;
    assert_same([], array_values(array_diff($ids[1], array_keys($used))), 'no symbol in the file that no section uses');
    assert_same(count($ids[1]), count($used), 'every used id is a symbol of the file');
    $spec = $th['sections'][array_search('specialitati', array_column($th['sections'], 'id'), true)];
    assert_same('i-health-health', theme_icon_for($spec, 'Spec 01'), 'a specialty with no mapping: the default icon');
});

run_suite('the symbol file is served as SVG by the router', function () use ($base) {
    [$st, $body, $hd] = http_page($base, '/static/health-icons.svg');
    assert_same(200, $st, 'the file answers');
    assert_contains('image/svg+xml', $hd['content-type'] ?? '', 'the content type is SVG');
    assert_contains('<symbol id="i-health-radiology"', $body, 'the body has the symbols');
});


stop_fixture_server($srv, $db);

// ---- the limit of the specialties ----

/** SQL: $n more health postings (2003…) with one distinct specialty each, "Spec NN". */
function many_specialties(int $n): string {
    $sql = '';
    for ($i = 0; $i < $n; $i++) {
        $id = 2003 + $i;
        $sql .= sprintf("UPDATE job_postings SET inf_profession_family='sănătate', med_specialty='[\"Spec %02d\"]' WHERE id=%d;\n", $i + 1, $id);
    }
    return $sql;
}

run_suite('more than 24 specialties: the first 24, then one link to the list; 24 or fewer: no link', function () {
    // 3 values come from the fixture; $n more make $distinct.
    foreach ([[21, 24, false], [22, 25, true]] as [$n, $distinct, $more]) {
        $db = theme_db("lim$n", many_specialties($n));
        $srv = start_fixture_server($db);
        $x = xp(http_page($srv['base'], '/sanatate/')[1]);
        $items = array_filter(tile_items($x), fn($i, $k) => str_starts_with($k, 'medspec:'), ARRAY_FILTER_USE_BOTH);
        assert_same(min(24, $distinct), count($items), "$distinct values: $distinct or 24 items");
        $sec = $x->query('//section[@aria-labelledby="h-specialitati"]')->item(0);
        $link = $x->query('.//li[@data-domain="more:medspec"]', $sec);
        assert_same($more ? 1 : 0, $link->length, "$distinct values: " . ($more ? 'the link to the list' : 'no link'));
        if ($more) {
            assert_same('Toate specialitățile în listă', trim($link->item(0)->textContent), 'the text of the link');
            assert_same('/?' . HEALTH, $x->query('.//a', $link->item(0))->item(0)->getAttribute('href'), 'it opens the list of the base filter');
            assert_same(0, $x->query('.//*[@data-tile-count]', $link->item(0))->length, 'it has no count');
            assert_same(['Cardiologie', 'Neurologie', 'Radiologie'], array_slice(array_map(fn($i) => $i['label'], array_values($items)), 0, 3), 'the largest three come first');
        }
        stop_fixture_server($srv, $db);
    }
});

// ---- a second theme, with no change of the template ----

$extra = sys_get_temp_dir() . '/posturi-theme-extra-' . getmypid() . '.php';
file_put_contents($extra, <<<'PHP'
<?php
return [
    'justitie' => [
        'label' => 'Justiție', 'base' => ['family' => ['tehnic']],
        'title' => 'Posturi în justiție', 'h1' => 'Posturi juridice', 'description' => 'Descriere de test.',
        'all_label' => 'Toate posturile juridice', 'placeholder' => 'Caută: jurist…',
        'sections' => [
            // `icons` with no `icon_file` on the theme: no icon and no reference to a file.
            ['type' => 'values', 'id' => 'ocupatii', 'title' => 'Funcții', 'param' => 'occupation', 'source' => 'column', 'column' => 'occ_canonical', 'limit' => 5,
             'icons' => ['Analist' => 'i-health-doctor'], 'icon_default' => 'i-health-health'],
            ['type' => 'follow', 'id' => 'abonare'],
        ],
        'other' => ['label' => 'Alte posturi din justiție', 'params' => ['sector' => ['sanatate'], 'family_not' => ['tehnic']]],
    ],
    // The statistics panel with no `history`: the figures only. An icon file, and an icon for one value only.
    'cifre' => [
        'label' => 'Cifre', 'base' => ['family' => ['tehnic']], 'icon_file' => 'health-icons.svg',
        'title' => 'Cifre', 'h1' => 'Cifre', 'description' => 'Cifre.', 'all_label' => 'Toate', 'placeholder' => 'Caută',
        'sections' => [
            ['type' => 'stats', 'id' => 'statistici'],
            ['type' => 'values', 'id' => 'ocupatii', 'title' => 'Funcții', 'param' => 'occupation', 'source' => 'column', 'column' => 'occ_canonical', 'limit' => 5,
             'icons' => ['Analist' => 'i-health-doctor']],
        ],
    ],
    // Not in the sitemap or the links: no sections at all, no `other` line.
    'gol' => [
        'label' => 'Gol', 'base' => ['family' => ['altele']], 'title' => 'Gol', 'h1' => 'Gol', 'description' => 'Gol.',
        'all_label' => 'Toate', 'placeholder' => 'Caută', 'sections' => [],
    ],
    // A theme that tries to replace a configured one is ignored.
    'sanatate' => ['label' => 'X', 'base' => ['family' => ['it']], 'title' => 'X', 'h1' => 'X', 'description' => 'X', 'all_label' => 'X', 'placeholder' => 'X', 'sections' => []],
];
PHP);
putenv('POSTURI_THEMES_EXTRA=' . $extra);
$db2 = theme_db('second');
$srv2 = start_fixture_server($db2);
putenv('POSTURI_THEMES_EXTRA');
$base2 = $srv2['base'];

run_suite('a second theme from the configuration: the same template, no change', function () use ($base2) {
    [$st, $h] = http_page($base2, '/justitie/');
    assert_same(200, $st, '/justitie/ answers 200');
    assert_contains('<link rel="canonical" href="' . $base2 . '/justitie/">', $h, 'canonical');
    assert_contains('<span>Posturi juridice</span></h1>', $h, 'its own h1');
    assert_same(0, xp($h)->query('//h1/svg')->length, 'a theme with no icon: no icon in the title');
    assert_same(0, xp($h)->query('//*[@data-county]')->length, 'a theme with no counties section: no map');
    assert_same(0, xp($h)->query('//*[@data-county-title]')->length, 'no place for the county in its title');
    assert_same(0, xp($h)->query('//form[@data-home-search]//svg')->length, 'the text field form: the button is the text');
    assert_same(1, xp($h)->query('//form[@data-home-search]//input[@type="search"][@name="q"]')->length, 'a theme with no search_selects keeps the text field');
    assert_same(0, xp($h)->query('//form[@data-home-search]//select[@name!="judet[]"]')->length, '… and has no other select');
    assert_contains('<title>Posturi în justiție — posturi.gov2.ro</title>', $h, 'its own title');
        preg_match_all('#<section[^>]*aria-labelledby="([^"]+)"#', substr($h, (int)strpos($h, '<main')), $s);
    assert_same(['h-theme', 'h-ocupatii', 'h-abonare'], $s[1], 'its sections, in its order');
    $x = xp($h);
    $items = tile_items($x);
    assert_same(['all', 'occupation:Analist', 'occupation:Muncitor necalificat', 'occupation:Referent', 'other'], array_keys($items), 'its items');
    foreach ($items as $id => $it) {
        assert_same($it['count'], list_count($base2, $it['href']), "item $id: the count is the result count of its link");
        assert_contains($id === 'other' ? 'family_not%5B%5D=tehnic' : 'family%5B%5D=tehnic', $it['href'], "item $id: the filter of the second theme");
    }
    assert_same(3, $items['all']['count'], 'three technical postings (no map: the count stays after the link, not in the title)');
    assert_same(0, $x->query('//h1//*[@data-title-count]')->length, 'a theme with no counties section: no count in the title');
    assert_same(2, $items['other']['count'], 'its other-employers line: sector sanatate, family not tehnic');
    assert_not_contains('Asistent medical', $h, 'nothing of the health theme: no health occupation');
    assert_same(0, $x->query('//form[@data-home-search]//input[@value="sănătate"]')->length, 'no health field in its form');
    // The redirect, the sitemap, the landing page and the footer follow the configuration.
    [$st, , $hd] = http_page($base2, '/justitie/?q=x');
    assert_same(301, $st, 'its redirect');
    assert_same('/?family%5B%5D=tehnic&q=x', $hd['location'] ?? '', 'merges its own base filter');
    assert_contains('<loc>' . $base2 . '/justitie/</loc>', http_page($base2, '/sitemap.xml')[1], 'sitemap');
    $home = xp(http_page($base2, '/')[1]);
    assert_same(['/sanatate/', '/justitie/', '/cifre/', '/gol/'], array_map(fn($a) => $a->getAttribute('href'), iterator_to_array($home->query('//footer//h2[.="Pagini tematice"]/following-sibling::ul[1]//a'))), 'footer links, in configuration order');
    assert_same(1, $home->query('//footer//a[@href="/justitie/"]')->length, 'footer link');
    // A theme with no `icon_file`: no icon, no reference to the file (also with `icons` in a section).
    assert_not_contains('health-icons.svg', $h, 'no icon file in a theme with no icon_file');
    assert_same(0, $x->query('//main//li//svg')->length, 'no icon in the items');
    // No `stats` section: the page is as before (no panel).
    assert_same(0, $x->query('//*[@data-theme-stats]')->length, 'no statistics panel without a stats section');
    // A stats section with no `history`: no panel (the chart needs the family of the archive).
    [$st, $c] = http_page($base2, '/cifre/');
    $cx = xp($c);
    assert_same(200, $st, '/cifre/ answers 200');
    assert_same(0, $cx->query('//*[@data-theme-stats] | //figure[@data-chart] | //*[@id="theme-panel-data"]')->length, 'no panel, no chart and no data without history');
    assert_not_contains('theme-panel.js', $c, 'no script of the panel');
    // Icons of one value: the others have no icon, but their text lines up (a gap of the same width).
    $with = $cx->query('//li[@data-domain="occupation:Analist"]//use');
    assert_same(1, $with->length, 'Analist has an icon');
    assert_same('/static/health-icons.svg?v=' . filemtime(__DIR__ . '/../static/health-icons.svg') . '#i-health-doctor', $with->item(0)->getAttribute('href'), 'its reference');
    foreach (['Referent', 'Muncitor necalificat'] as $o) {
        assert_same(0, $cx->query('//li[@data-domain="occupation:' . $o . '"]//svg')->length, "$o: no icon");
        assert_same(1, $cx->query('//li[@data-domain="occupation:' . $o . '"]/span[@aria-hidden="true"][contains(@class,"h-6 w-6")]')->length, "$o: a gap, so the text lines up");
    }
    // The built-in theme is not replaced; a theme with no sections renders.
    assert_contains('<span>Posturi în sănătate<span data-county-title></span><span data-title-count', http_page($base2, '/sanatate/')[1], 'sanatate stays what the configuration says');
    [$st, $g] = http_page($base2, '/gol/');
    assert_same(200, $st, 'a theme with no sections renders');
    assert_not_contains('id="home-data"', $g, 'no county section, no JSON block');
    assert_well_formed($g, '/gol/');
});

stop_fixture_server($srv2, $db2);
@unlink($extra);

run_suite('server log: no warnings, notices or fatals', function () use ($srv) {
    $log = (string)@file_get_contents($srv['log']);
    assert_not_contains('Warning', $log, 'no warning');
    assert_not_contains('Fatal', $log, 'no fatal');
    assert_not_contains('Notice', $log, 'no notice');
    assert_not_contains('Deprecated', $log, 'no deprecation');
});

finish();
