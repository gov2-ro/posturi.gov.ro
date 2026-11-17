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
 * The occupation chips of the list (docs/briefs/2026-11-17-themes-admin-financiar-chips.md, step 5) are checked here too: they share
 * the page helpers and the way of building a fixture; the browser behaviour is in tests/browser/chips.spec.js.
 *
 * Usage: php tests/theme_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../inc/themes.php';
require_once __DIR__ . '/fixtures/theme_db.php';
require_once __DIR__ . '/fixtures/chips_db.php';
require_once __DIR__ . '/fixtures/professional_db.php';
require_once __DIR__ . '/fixtures/server.php';
require_once __DIR__ . '/fixtures/page_helpers.php';
require_once __DIR__ . '/../assets/icons/healthicons/build-sprite.php';

putenv('POSTURI_TODAY=2026-10-03');
/** The rule of posts, written on the side (not posts_sql()): 1 to 30 as it is, else 1. */
const POSTS_SIDE = 'CASE WHEN nr_posturi >= 1 AND nr_posturi <= 30 THEN nr_posturi ELSE 1 END';
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
    assert_same(4, $links->length, 'four themed pages in the footer group');
    assert_same(['/sanatate/', '/administratie/', '/financiar/', '/social/'], array_map(fn($a) => $a->getAttribute('href'), iterator_to_array($links)), 'their links, in configuration order');
    assert_same(['Sănătate', 'Administrație', 'Financiar', 'Asistență socială și consiliere'], array_map(fn($a) => trim($a->textContent), iterator_to_array($links)), 'their link texts');
    assert_same(1, $x->query('//footer//a[@href="/sanatate/"]')->length, 'one footer link');
    foreach (['/administratie/', '/financiar/', '/social/'] as $slug) assert_contains('<loc>' . $base . $slug . '</loc>', $sm, "sitemap entry $slug");
    [, $llms] = http_page($base, '/llms.txt');
    foreach (['/sanatate/', '/administratie/', '/financiar/', '/social/'] as $slug) assert_contains('](' . $base . $slug . ')', $llms, "llms.txt lists $slug");
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
    assert_same(0, $x->query('//*[@data-county-picked or @data-county-name]')->length, 'no county name before the title (the button "Toate județele" is after the h1: its own suite)');
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

run_suite('the button "Toate județele" after the title: in the HTML, hidden, outside the h1, with the text; only on a theme with a map', function () use ($base) {
    $h = http_page($base, '/sanatate/')[1];
    $x = xp($h);
    $b = $x->query('//button[@data-county-clear]');
    assert_same(1, $b->length, 'one button');
    assert_true($b->item(0)->hasAttribute('hidden'), 'hidden without script (the script shows it while a county is selected)');
    assert_same('button', $b->item(0)->getAttribute('type'), 'a button, not a submit');
    assert_same('Toate județele', trim($b->item(0)->textContent), 'its text');
    assert_contains('g2-chip', $b->item(0)->getAttribute('class'), 'the DS chip');
    assert_same(0, $x->query('//h1//button')->length, 'no button inside the heading');
    assert_same(1, $x->query('//h1/following::button[@data-county-clear]')->length, 'after the h1');
    assert_same(1, $x->query('//button[@data-county-clear]/svg[@aria-hidden="true"]/use[@href="#i-x"]')->length, 'an x icon before the text, as decoration');
    assert_same(1, $x->query('//symbol[@id="i-x"]')->length, 'the symbol is in the sprite');
    assert_same(0, xp(http_page($base, '/')[1])->query('//*[@data-county-clear]')->length, 'not on the landing page');
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
    // 9101 (Jul 8), 9107 + 9108 (Jul 14, 15), 9001 (Aug 1, two posts) and 9109 (Aug 3), 1003 + 9102 (Aug 10 and 12), 9110 (Aug 19), 9106 (Sep 2), 2001 + 2002 (Sep 15);
    // out: 9103 (another family), 9104 (the id of 2001: once), 9105 (before Jul 6), the postings of the current week.
    assert_same([1, 2, 0, 2, 1, 2, 1, 0, 1, 0, 2, 0], array_column($weekly, 'n'), 'weeks of the theme, in posts: 9001 has nr_posturi 2');
    $all = json_decode(php_on($db, 'echo json_encode(home_weekly_postings(12));'), true);
    assert_true(array_sum(array_column($all, 'n')) > array_sum(array_column($weekly, 'n')), 'without the family the chart counts more (the other families)');
    [, $h] = http_page($base, '/sanatate/');
    assert_contains('Posturi publicate pe săptămână', $h, 'the caption of the chart');
    assert_contains('min 0 · max 2 · ultima 0', $h, 'min, max, last');
    assert_not_contains('Toată țara', $h, 'no caption below the chart');
    $cap = xp($h)->query('//figure[@data-chart]/figcaption');
    assert_same('Posturi publicate pe săptămână', trim($cap->item(0)->textContent), 'the heading of the chart stays for assistive technology');
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
    $sql = fn(string $c, string $w0) => (int)$pdo->query("SELECT SUM(p) FROM (
        SELECT substr(published_at,1,10) d, " . POSTS_SIDE . " p FROM job_postings WHERE inf_profession_family='sănătate' AND ($c)
        UNION ALL SELECT substr(published_at,1,10), " . POSTS_SIDE . " FROM archived_postings WHERE inf_profession_family='sănătate' AND ($c) AND id NOT IN (SELECT id FROM job_postings))
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
    assert_same([1, 0, 0, 2, 0, 1, 0, 0, 0, 0, 1, 0], $by['Asistent'], 'Asistent: Asistent medical on 8 Jul, 1 Aug (two posts), 10 Aug (closed), 15 Sep');
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
        return array_map(fn($w) => (int)$pdo->query("SELECT SUM(p) FROM (
            SELECT substr(published_at,1,10) d, " . POSTS_SIDE . " p FROM job_postings WHERE $health AND judet_slug=" . $pdo->quote($county) . "$o
            UNION ALL SELECT substr(published_at,1,10), " . POSTS_SIDE . " FROM archived_postings WHERE $health AND judet_slug=" . $pdo->quote($county) . "$o AND id NOT IN (SELECT id FROM job_postings))
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
    $national = fn(string $group = '') => array_map(fn($w) => (int)$pdo->query("SELECT SUM(p) FROM (
            SELECT substr(published_at,1,10) d, " . POSTS_SIDE . " p FROM job_postings WHERE $health AND judet_slug<>''" . ($group === '' ? '' : ' AND (' . $cond[$group] . ')') . "
            UNION ALL SELECT substr(published_at,1,10), " . POSTS_SIDE . " FROM archived_postings WHERE $health AND judet_slug<>''" . ($group === '' ? '' : ' AND (' . $cond[$group] . ')') . " AND id NOT IN (SELECT id FROM job_postings))
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

// ---- the themes "administratie" and "financiar", `order => 'count'`, `labels` of a select ----

/** The item ids of a page that start with $prefix, in page order, with their counts. */
function items_of(array $items, string $prefix): array {
    $out = [];
    foreach ($items as $id => $it) if (str_starts_with($id, $prefix)) $out[substr($id, strlen($prefix))] = $it['count'];
    return $out;
}

$dbp = theme_db('prof', professional_sql());
$srvp = start_fixture_server($dbp);
$basep = $srvp['base'];

run_suite('/administratie/ and /financiar/ render: title, canonical, h1, icon, sections in order, one h1, same bytes for each reader', function () use ($basep) {
    $cases = [
        'administratie' => ['Posturi în administrație', 'i-domain-administratie', ['h-theme', 'h-tip-functie', 'h-ocupatii', 'h-angajatori', 'h-domenii', 'h-studii', 'h-noi', 'h-notificari'], 10],
        'financiar' => ['Posturi financiar-contabile', 'i-domain-financiar', ['h-theme', 'h-tip-functie', 'h-ocupatii', 'h-angajatori', 'h-programe', 'h-studii', 'h-noi', 'h-notificari'], 12],
    ];
    foreach ($cases as $slug => [$title, $icon, $sections, $n]) {
        [$st, $h, $hd] = http_page($basep, "/$slug/");
        assert_same(200, $st, "/$slug/ answers 200");
        assert_contains('<link rel="canonical" href="' . $basep . "/$slug/\">", $h, "$slug canonical");
        assert_contains("<title>$title — posturi.gov2.ro</title>", $h, "$slug title");
        assert_same(1, preg_match('#<meta name="description" content="Posturi (în administrație|financiar-contabile) din sectorul public: #', $h), "$slug description, in the form of the health one");
        assert_same(1, preg_match_all('#<h1[ >]#', $h), "$slug has one h1");
        assert_contains("<span>$title<span data-county-title></span><span data-title-count class=\"text-base font-normal text-ink-muted\">&nbsp;($n)</span></span></h1>", $h, "$slug h1 and count");
        assert_same(1, xp($h)->query('//h1/svg[@aria-hidden="true"]/use[@href="#' . $icon . '"]')->length, "$slug: the icon of the theme");
        assert_same(1, xp($h)->query('//symbol[@id="' . $icon . '"]')->length, "$slug: the symbol is in the sprite");
        preg_match_all('#<section[^>]*aria-labelledby="([^"]+)"#', substr($h, (int)strpos($h, '<main')), $s);
        assert_same($sections, $s[1], "$slug: sections, in order");
        assert_not_contains('health-icons.svg', $h, "$slug: no icon file");
        assert_same(0, xp($h)->query('//section[@aria-labelledby="h-ocupatii" or @aria-labelledby="h-tip-functie" or @aria-labelledby="h-angajatori" or @aria-labelledby="h-studii"]//li//svg | //section[@aria-labelledby="h-ocupatii"]//li/span[@aria-hidden="true"]')->length, "$slug: no icons and no gaps in the items");
        assert_well_formed($h, "/$slug/");
        [, $h2, $hd2] = http_page($basep, "/$slug/", ['Cookie: pg_x=1', 'Accept-Language: en']);
        assert_same($h, $h2, "$slug: the same bytes for another reader");
        assert_true(!isset($hd2['set-cookie']), "$slug: no cookie");
        foreach (['bg-amber', 'text-slate', 'bg-white', '#1d4f91'] as $raw) assert_not_contains($raw, $h, "$slug: no raw palette: $raw");
        // No line "other".
        assert_same(0, xp($h)->query('//li[@data-domain="other"]')->length, "$slug: no line other");
        // The redirect of /{slug}/?… merges the base filter.
        [$st, , $hd] = http_page($basep, "/$slug/?q=x");
        assert_same(301, $st, "$slug redirect");
        assert_same('/?family%5B%5D=' . rawurlencode($slug === 'administratie' ? 'administrație' : 'financiar') . '&q=x', $hd['location'] ?? '', "$slug redirect target");
    }
    [, $sm] = http_page($basep, '/sitemap.xml');
    [, $llms] = http_page($basep, '/llms.txt');
    foreach (['administratie', 'financiar'] as $slug) {
        assert_contains("<loc>$basep/$slug/</loc>", $sm, "sitemap: $slug");
        assert_contains("]($basep/$slug/)", $llms, "llms.txt: $slug");
    }
});

run_suite('every count equals the result count of its link: Tip funcție, Ocupații, Angajatori, Domenii, Programe, Studii; no item of count 0', function () use ($basep) {
    foreach (['administratie', 'financiar'] as $slug) {
        $x = xp(http_page($basep, "/$slug/")[1]);
        $items = tile_items($x);
        assert_true(count($items) >= 12, "$slug: " . count($items) . ' items');
        foreach ($items as $id => $it) {
            if ($it['count'] === null) continue;
            assert_same($it['count'], list_count($basep, $it['href']), "$slug item $id: the count is the result count of its link");
            assert_same($it['count'], $it['total'], "$slug item $id: data-total");
            assert_true($it['count'] > 0, "$slug item $id: not 0");
            assert_same(0, $it['in_link'], "$slug item $id: the count is outside the link");
        }
        foreach (['level:', 'occupation:', 'inst:', 'studies_level:'] as $prefix) assert_true(count(items_of($items, $prefix)) > 0, "$slug: has items $prefix");
        // Each link is the base filter plus one more filter.
        foreach ($items as $id => $it) {
            if ($id === 'all') continue;
            assert_contains($slug === 'administratie' ? 'family%5B%5D=administra%C8%9Bie' : 'family%5B%5D=financiar', $it['href'], "$slug item $id: the base filter");
        }
    }
    // A count of 0 is hidden: no posting of these themes has the level "Doctorat", the domain "turism" is only in the administrație one.
    $a = xp(http_page($basep, '/administratie/')[1]);
    assert_same(0, $a->query('//li[@data-domain="studies_level:doctorat"]')->length, 'no study level with 0');
    assert_same(0, $a->query('//li[@data-domain="skill:FOREXEBUG"]')->length, 'administratie has no programe section');
});

run_suite('order => count: Tip funcție, Angajatori and Domenii sort by count, largest first; the labels give the texts', function () use ($basep) {
    $a = tile_items(xp(http_page($basep, '/administratie/')[1]));
    // The labels come in the order conducere, execuție: by count the order is the other one.
    assert_same(['Funcții de execuție' => 7, 'Funcții de conducere' => 3], items_of($a, 'level:'), 'administratie, Tip funcție: by count');
    assert_same('Funcții de execuție', $a['level:Funcții de execuție']['label'], 'the item text is the text of the label');
    // The order of EMPLOYER_KIND_LABELS is primarie, spital, scoala: by count it is scoala, primarie, spital.
    assert_same(['scoala' => 5, 'primarie' => 3, 'spital' => 2], items_of($a, 'inst:'), 'administratie, Angajatori: by count');
    assert_same('Școli, licee, grădinițe', $a['inst:scoala']['label'], 'Angajatori: the text of EMPLOYER_KIND_LABELS');
    // Domenii: 9 values have a count, 8 are shown (limit after the sort): the smallest value in the order of the labels is left out.
    $dom = items_of($a, 'domain:');
    assert_same(['administratie_publica' => 4, 'educatie_cercetare' => 3, 'achizitii_publice' => 2, 'economie_finante' => 2,
                 'energie' => 1, 'mediu' => 1, 'sanatate_publica' => 1, 'transporturi' => 1], $dom, 'administratie, Domenii: the first 8 by count');
    assert_same(0, count(array_filter(array_keys($a), fn($k) => $k === 'domain:turism')), 'the 9th value (turism) is cut by the limit');
    assert_same('Administrație publică', $a['domain:administratie_publica']['label'], 'Domenii: the text of POLICY_DOMAIN_LABELS');
    assert_same(1, list_count($basep, $a['domain:transporturi']['href']), 'a cut value still has a list (the limit is for the page)');
    $f = tile_items(xp(http_page($basep, '/financiar/')[1]));
    assert_same(['Funcții de execuție' => 10, 'Funcții de conducere' => 2], items_of($f, 'level:'), 'financiar, Tip funcție: by count');
    assert_same(['scoala' => 5, 'spital' => 3, 'primarie' => 2, 'cultura' => 2], items_of($f, 'inst:'), 'financiar, Angajatori: by count, ties in the order of the labels');
    // Without `order`, the labels keep their order (Studii).
    assert_same(['licenta'], array_keys(items_of($f, 'studies_level:')), 'Studii: the order of the labels, 0 hidden');
});

run_suite('Programe informatice cerute: the proposed list, by count; "Forexebug" and "FOREXEBUG" are one item; the count is the result count of the link', function () use ($basep) {
    $f = tile_items(xp(http_page($basep, '/financiar/')[1]));
    // Labels: FOREXEBUG, EDUSAL, SIIIR. By count: EDUSAL 3, FOREXEBUG 2 (both spellings), SIIIR 1.
    assert_same(['EDUSAL' => 3, 'FOREXEBUG' => 2, 'SIIIR' => 1], items_of($f, 'skill:'), 'programs, by count');
    foreach (['EDUSAL', 'FOREXEBUG', 'SIIIR'] as $v) assert_same($f['skill:' . $v]['count'], list_count($basep, $f['skill:' . $v]['href']), "skill $v: the count equals its list");
    assert_contains('skill%5B%5D=FOREXEBUG', $f['skill:FOREXEBUG']['href'], 'the link carries the value of the label');
    // The filter compares with LIKE (the case of ASCII letters is ignored): the list of "FOREXEBUG" holds the posting that says "Forexebug".
    assert_same(2, list_count($basep, '/?family%5B%5D=financiar&skill%5B%5D=Forexebug'), 'the other spelling gives the same list');
    assert_same(0, count(items_of($f, 'skill:Excel')), 'no generic office program in the list (Excel: 4 postings, not offered)');
});

run_suite('labels in a select: the option shows the text and sends the value; the options are in the order of the texts', function () use ($basep) {
    foreach (['administratie', 'financiar'] as $slug) {
        $x = xp(http_page($basep, "/$slug/")[1]);
        $f = $x->query('//form[@data-home-search]')->item(0);
        $names = [];
        foreach ($x->query('.//select', $f) as $s) $names[] = $s->getAttribute('name');
        assert_same(['occupation[]', 'inst[]', 'judet[]'], $names, "$slug: occupation, institution, county");
        $inst = [];
        foreach ($x->query('.//select[@name="inst[]"]/option', $f) as $o) $inst[] = [$o->getAttribute('value'), trim($o->textContent)];
        assert_same(['', 'Toate instituțiile'], $inst[0], "$slug: the first option");
        // The values with a posting inside the theme, with the text of EMPLOYER_KIND_LABELS, sorted by that text (Romanian order: s before ș).
        $want = $slug === 'administratie'
            ? [['primarie', 'Primării și consilii locale'], ['spital', 'Spitale și unități sanitare'], ['scoala', 'Școli, licee, grădinițe']]
            : [['cultura', 'Cultură (muzee, teatre, biblioteci)'], ['primarie', 'Primării și consilii locale'], ['spital', 'Spitale și unități sanitare'], ['scoala', 'Școli, licee, grădinițe']];
        assert_same($want, array_slice($inst, 1), "$slug: option value and text");
        $occ = [];
        foreach ($x->query('.//select[@name="occupation[]"]/option[@value!=""]', $f) as $o) $occ[] = [$o->getAttribute('value'), trim($o->textContent)];
        foreach ($occ as [$v, $t]) assert_same($v, $t, "$slug: an option with no label shows its value ($v)");
        // Each option leads to a non-empty list.
        foreach ($want as [$v]) assert_true(list_count($basep, '/?' . ($slug === 'administratie' ? 'family%5B%5D=administra%C8%9Bie' : 'family%5B%5D=financiar') . '&inst%5B%5D=' . $v) > 0, "$slug: option $v is not empty");
    }
});

run_suite('the chart of each theme: "Toate" and the groups of the configuration, in order', function () use ($basep) {
    $want = ['administratie' => ['Toate', 'Conducere', 'Referent', 'Inspector', 'Secretar'], 'financiar' => ['Toate', 'Conducere', 'Administrator financiar', 'Economist', 'Contabil']];
    foreach ($want as $slug => $labels) {
        $x = xp(http_page($basep, "/$slug/")[1]);
        $btn = [];
        foreach ($x->query('//*[@data-theme-stats]//button[@data-chart-series]') as $b) $btn[] = trim($b->textContent);
        assert_same($labels, $btn, "$slug: the buttons of the chart");
    }
    // A group is the first that holds the occupation.
    $g = themes()['administratie']['history']['groups'];
    assert_same(0, theme_group_of($g, 'Secretar șef'), '"Secretar șef" is in Conducere, not in Secretar');
    assert_same(3, theme_group_of($g, 'Secretar'), '"Secretar" is in Secretar');
    $g = themes()['financiar']['history']['groups'];
    assert_same(0, theme_group_of($g, 'Contabil-șef'), '"Contabil-șef" is in Conducere');
    assert_same(0, theme_group_of($g, 'Director financiar-contabil'), '"Director financiar-contabil" is in Conducere');
    assert_same(1, theme_group_of($g, 'Administrator financiar'), '"Administrator financiar" has its own group');
    assert_same(3, theme_group_of($g, 'Contabil'), '"Contabil" is in Contabil');
});

run_suite('the county selection of the two themes: the map and the counts inside the theme', function () use ($basep) {
    foreach (['administratie' => [['cluj', 4], ['ilfov', 6]], 'financiar' => [['cluj', 2], ['ilfov', 10]]] as $slug => $cs) {
        $x = xp(http_page($basep, "/$slug/")[1]);
        foreach ($cs as [$c, $n]) {
            $o = $x->query('//select[@name="judet[]"]/option[@value="' . $c . '"]');
            assert_same($n, paren_number($o->item(0)->textContent), "$slug: the county $c in the select");
        }
        assert_same(42, $x->query('//button[@data-county]')->length, "$slug: the county map");
    }
});

stop_fixture_server($srvp, $dbp);

// ---- the theme "social": the families `social` and `educație` on one page, a chart for a list of families ----

$dbs = theme_db('social', social_sql());
$srvs = start_fixture_server($dbs);
$bases = $srvs['base'];
const SOCIAL_BASE = 'family%5B%5D=educa%C8%9Bie&family%5B%5D=social';   // the order of search_key(): sorted

run_suite('/social/ renders: title, canonical, h1 with the count, icon, sections in order, one h1, same bytes for each reader', function () use ($bases) {
    [$st, $h, $hd] = http_page($bases, '/social/');
    assert_same(200, $st, '/social/ answers 200');
    assert_contains('<link rel="canonical" href="' . $bases . '/social/">', $h, 'canonical');
    assert_contains('<title>Posturi în asistență socială și consiliere — posturi.gov2.ro</title>', $h, 'title');
    assert_same(1, preg_match('#<meta name="description" content="Posturi în asistență socială și consiliere din sectorul public: asistenți sociali, consilieri școlari, mediatori#', $h), 'the description names the main occupations');
    assert_same(1, preg_match_all('#<h1[ >]#', $h), 'one h1');
    assert_contains('<span>Posturi în asistență socială și consiliere<span data-county-title></span><span data-title-count class="text-base font-normal text-ink-muted">&nbsp;(5)</span></span></h1>', $h, 'h1 and count: 5 postings of the two families');
    assert_same(1, xp($h)->query('//h1/svg[@aria-hidden="true"]/use[@href="#i-sector-asistenta_sociala"]')->length, 'the icon of the theme');
    assert_same(1, xp($h)->query('//symbol[@id="i-sector-asistenta_sociala"]')->length, 'the symbol is in the sprite');
    preg_match_all('#<section[^>]*aria-labelledby="([^"]+)"#', substr($h, (int)strpos($h, '<main')), $s);
    assert_same(['h-theme', 'h-ocupatii', 'h-angajatori', 'h-domenii', 'h-studii', 'h-noi', 'h-notificari'], $s[1], 'sections, in order');
    assert_well_formed($h, '/social/');
    [, $h2, $hd2] = http_page($bases, '/social/', ['Cookie: pg_x=1', 'Accept-Language: en']);
    assert_same($h, $h2, 'the same bytes for another reader');
    assert_true(!isset($hd2['set-cookie']), 'no cookie');
    foreach (['bg-amber', 'text-slate', 'bg-white', '#1d4f91'] as $raw) assert_not_contains($raw, $h, "no raw palette: $raw");
    [$st, , $hd] = http_page($bases, '/social/?q=x');
    assert_same(301, $st, 'redirect');
    assert_same('/?' . SOCIAL_BASE . '&q=x', $hd['location'] ?? '', 'redirect target: both families in the base filter');
    $names = [];
    foreach (xp($h)->query('//form[@data-home-search]//select') as $sel) $names[] = $sel->getAttribute('name');
    assert_same(['occupation[]', 'inst[]', 'judet[]'], $names, 'the selects: occupation, institution, county');
    assert_same(['social', 'educatie'], themes()['social']['domains'], 'the domains of the theme');
});

run_suite('/social/: every count equals the result count of its link; the base filter holds both families; the line "other"', function () use ($bases) {
    $x = xp(http_page($bases, '/social/')[1]);
    $items = tile_items($x);
    assert_same(5, $items['all']['count'] ?? paren_number($x->query('//h1//span[@data-title-count]')->item(0)->textContent), 'the base count');
    assert_same(5, list_count($bases, '/?' . SOCIAL_BASE), 'the base list has both families (OR)');
    assert_same(3, list_count($bases, '/?family%5B%5D=social'), 'social alone: 3');
    assert_same(2, list_count($bases, '/?family%5B%5D=educa%C8%9Bie'), 'educație alone: 2');
    foreach ($items as $id => $it) {
        if ($it['count'] === null) continue;
        assert_same($it['count'], list_count($bases, $it['href']), "item $id: the count is the result count of its link");
        assert_true($it['count'] > 0, "item $id: not 0");
        if (!in_array($id, ['other'], true)) assert_contains(SOCIAL_BASE, $it['href'], "item $id: the base filter holds both families");
    }
    assert_same(['Asistent social' => 2, 'Consilier școlar' => 1, 'Mediator școlar' => 1, 'Psiholog' => 1], items_of($items, 'occupation:'), 'Ocupații, by count');
    assert_same(['primarie' => 3, 'scoala' => 1, 'asistenta-sociala' => 1], items_of($items, 'inst:'), 'Angajatori, by count, ties in the order of the labels');
    assert_same(['asistenta_sociala' => 4, 'educatie_cercetare' => 2, 'fonduri_europene' => 1], items_of($items, 'domain:'), 'Domenii, by count');
    assert_same('Asistență socială', $items['domain:asistenta_sociala']['label'], 'Domenii: the text of POLICY_DOMAIN_LABELS');
    assert_same(['master' => 2, 'licenta' => 2, 'liceala' => 1], items_of($items, 'studies_level:'), 'Studii, in the order of STUDIES_LABELS');
    // The line "other": a social-assistance employer, no family of the theme.
    assert_same(1, $items['other']['count'], 'the line other: 1 posting (2030)');
    assert_same('Alte posturi la direcțiile de asistență socială', $items['other']['label'], 'its label');
    assert_same(1, list_count($bases, $items['other']['href']), 'its count is the result count of its link');
    assert_contains('inst%5B%5D=asistenta-sociala', $items['other']['href'], 'its link carries inst[]');
    assert_contains('family_not', $items['other']['href'], 'and family_not');
    assert_contains('Infirmieri, îngrijitori, bucătari și alte posturi din centre și cămine.', http_page($bases, '/social/')[1], 'its note');
    // The postings of the "other" line are not in the theme: 2029 (a psychologist at the same kind of employer) is in the theme.
    assert_same(2, list_count($bases, '/?inst%5B%5D=asistenta-sociala'), 'the employer kind has 2 postings: 2029 in the theme, 2030 in "other"');
});

run_suite('the chart for two families: the total of a week is the sum of the posts of both families in both tables; each group, "none" and each county add up', function () use ($bases, $dbs) {
    [, $h] = http_page($bases, '/social/');
    preg_match('#<script type="application/json" id="theme-panel-data">(.*?)</script>#s', $h, $m);
    $d = json_decode($m[1], true);
    $pdo = new PDO('sqlite:' . $dbs);
    $starts = [];
    for ($i = 0, $t = new DateTimeImmutable('2026-07-06'); $i < 12; $i++) $starts[] = $t->modify("+$i weeks")->format('Y-m-d');
    $fam = "inf_profession_family IN ('social', 'educație')";
    $cond = [
        'Asistent social' => "occ_canonical LIKE 'Asistent social%'",
        'Consilier școlar' => "occ_canonical LIKE 'Consilier școlar%'",
        'Mediator' => "occ_canonical LIKE 'Mediator%'",
        'Psiholog' => "occ_canonical LIKE 'Psiholog%'",
    ];
    // The same posts expression as the chart (POSTS_SIDE), over the two families, both tables, an id once.
    $weekly = fn(string $extra = '') => array_map(fn($w) => (int)$pdo->query("SELECT COALESCE(SUM(p), 0) FROM (
            SELECT substr(published_at,1,10) d, " . POSTS_SIDE . " p FROM job_postings WHERE $fam$extra
            UNION ALL SELECT substr(published_at,1,10), " . POSTS_SIDE . " FROM archived_postings WHERE $fam$extra AND id NOT IN (SELECT id FROM job_postings))
            WHERE d >= '$w' AND d < date('$w', '+7 days')")->fetchColumn(), $starts);
    $want = $weekly();
    assert_same($want, $d['total'], 'the total of each week equals a direct sum of posts of the two families');
    assert_same(21, array_sum($want), 'the 12 weeks hold 21 posts: 14 + 4 + 1 + 1 + 1 (see social_sql())');
    // Not the first family alone, and not announcements.
    $first = array_map(fn($w) => (int)$pdo->query("SELECT COALESCE(SUM(p), 0) FROM (
            SELECT substr(published_at,1,10) d, " . POSTS_SIDE . " p FROM job_postings WHERE inf_profession_family = 'social'
            UNION ALL SELECT substr(published_at,1,10), " . POSTS_SIDE . " FROM archived_postings WHERE inf_profession_family = 'social' AND id NOT IN (SELECT id FROM job_postings))
            WHERE d >= '$w' AND d < date('$w', '+7 days')")->fetchColumn(), $starts);
    assert_true($first !== $d['total'], 'the first family alone is a different series (the second family has postings in the weeks)');
    $ann = array_map(fn($w) => (int)$pdo->query("SELECT COUNT(*) FROM (
            SELECT substr(published_at,1,10) d FROM job_postings WHERE $fam
            UNION ALL SELECT substr(published_at,1,10) FROM archived_postings WHERE $fam AND id NOT IN (SELECT id FROM job_postings))
            WHERE d >= '$w' AND d < date('$w', '+7 days')")->fetchColumn(), $starts);
    assert_true($ann !== $d['total'], 'announcements are not posts (2027 has 3 posts, 9201 has 12)');
    $names = array_column($d['series'], 'name');
    assert_same(['Asistent social', 'Consilier școlar', 'Mediator', 'Psiholog'], $names, 'the 4 groups, in order');
    assert_same([14, 4, 1, 1], array_map(fn($s) => array_sum($s['values']), $d['series']), 'the posts of each group in the 12 weeks');
    $sum = array_fill(0, 12, 0);
    foreach ($names as $i => $n) {
        assert_same($weekly(' AND (' . $cond[$n] . ')'), $d['series'][$i]['values'], "group $n: the weekly posts");
        foreach ($d['series'][$i]['values'] as $w => $v) $sum[$w] += $v;
    }
    foreach ($sum as $w => $v) assert_true($v <= $d['total'][$w], "week $w: the groups do not pass the total");
    // "Alte ocupații" = the total minus the groups: only the archive row 9203 (Profesor, 12 Aug).
    $other = array_map(fn($t, $s) => $t - $s, $d['total'], $sum);
    assert_same(1, array_sum($other), 'the rest ("Alte ocupații"): 1 post (Profesor)');
    assert_same(0, count(array_filter($other, fn($v) => $v < 0)), 'never below 0');
    // Each county: the weekly total is the sum of posts of the two families in that county.
    $by_county = fn(string $c) => array_map(fn($w) => (int)$pdo->query("SELECT COALESCE(SUM(p), 0) FROM (
            SELECT substr(published_at,1,10) d, " . POSTS_SIDE . " p FROM job_postings WHERE $fam AND judet_slug=" . $pdo->quote($c) . "
            UNION ALL SELECT substr(published_at,1,10), " . POSTS_SIDE . " FROM archived_postings WHERE $fam AND judet_slug=" . $pdo->quote($c) . " AND id NOT IN (SELECT id FROM job_postings))
            WHERE d >= '$w' AND d < date('$w', '+7 days')")->fetchColumn(), $starts);
    foreach ($d['counties'] as $slug => $c) assert_same($by_county($slug), $c['t'], "$slug: the weekly total, in posts");
    assert_same(['cluj', 'ilfov'], (function () use ($d) { $k = array_keys($d['counties']); sort($k); return $k; })(), 'the counties');
    // The list of families and the string give the same result for one family.
    $a = php_on($dbs, 'require "' . __DIR__ . '/../inc/themes.php"; $t = themes()["social"]["history"]; echo json_encode(theme_group_series(["social"], $t["groups"], 12) == theme_group_series("social", $t["groups"], 12));');
    assert_same('true', $a, 'a list of one family equals the family string');
    // An id in both tables counts once: 9205 has the id of 2025 (7 posts in the archive; the live one has 1).
    assert_same(14, array_sum($d['series'][0]['values']), 'the live row counts, not the archive row with the same id');
});

run_suite('/social/: the buttons of the chart; the sections need no other template rule', function () use ($bases) {
    $x = xp(http_page($bases, '/social/')[1]);
    $btn = [];
    foreach ($x->query('//*[@data-theme-stats]//button[@data-chart-series]') as $b) $btn[] = trim($b->textContent);
    assert_same(['Toate', 'Asistent social', 'Consilier școlar', 'Mediator', 'Psiholog'], $btn, 'the buttons of the chart');
    $g = themes()['social']['history']['groups'];
    assert_same(2, theme_group_of($g, 'Mediator școlar'), '"Mediator școlar" is in Mediator');
    assert_same(null, theme_group_of($g, 'Profesor'), '"Profesor" is in no group');
    assert_same(4, count($g), 'four groups: the most that the chart colours allow');
});

stop_fixture_server($srvs, $dbs);

// ---- the occupation chips of the list ----

const CHIP_BASE = 'family%5B%5D=tehnic&cor%5B%5D=5';

/** The chip row of a list page: ['label' => text, 'chips' => [[value, count, href]], 'all' => href of "Toate ocupațiile"], or null when the page has none. */
function chip_row(string $html): ?array {
    $x = xp($html);
    $row = $x->query('//*[@data-occ-chips]');
    if (!$row->length) return null;
    $r = $row->item(0);
    $chips = [];
    foreach ($x->query('.//a[@data-occ-chip]', $r) as $a) {
        $chips[] = [$a->getAttribute('data-occ-chip'), (int)$x->query('.//*[contains(@class,"g2-chip-count")]', $a)->item(0)->textContent, $a->getAttribute('href'), $a->getAttribute('class')];
    }
    $all = $x->query('.//a[@data-occ-all]', $r);
    return ['label_id' => $r->getAttribute('aria-labelledby'), 'role' => $r->getAttribute('role'), 'chips' => $chips,
            'all' => $all->length ? [$all->item(0)->getAttribute('href'), trim($all->item(0)->textContent)] : null,
            'label' => ($l = $x->query('//*[@id="' . $r->getAttribute('aria-labelledby') . '"]'))->length ? trim($l->item(0)->textContent) : null];
}

/** Build a chips fixture with $dist, serve it, and return [base, db, server]. */
function chips_server(string $tag, array $dist = CHIPS_DEFAULT): array {
    $path = sys_get_temp_dir() . "/posturi-chips-$tag-" . getmypid() . '.sqlite';
    build_chips_fixture_db($path, $dist);
    $srv = start_fixture_server($path);
    return [$srv['base'], $path, $srv];
}

[$basec, $dbc, $srvc] = chips_server('main');

run_suite('occupation chips: the row, its label, the chips with their counts, the last link, the place on the page', function () use ($basec) {
    [, $h] = http_page($basec, '/?' . CHIP_BASE);
    assert_same(28, list_count($basec, '/?' . CHIP_BASE), 'the fixture list has 28 results (more than one page)');
    $r = chip_row($h);
    assert_true($r !== null, 'the row is there');
    assert_same('Ocupații frecvente', $r['label'], 'the visible label');
    assert_same('group', $r['role'], 'a group');
    assert_same('occ-chips-label', $r['label_id'], 'named by its label (aria-labelledby)');
    assert_same([['Pază', 12], ['Îngrijitor', 8], ['Bucătar', 6]], array_map(fn($c) => [$c[0], $c[1]], $r['chips']), 'the occupations with 2 or more postings, largest first (Brancardier and Frizer have 1)');
    foreach ($r['chips'] as [$v, $n, $href, $cls]) {
        assert_contains('g2-chip', $cls, "chip $v: the DS chip");
        assert_same($n, list_count($basec, '/' . $href), "chip $v: the count equals the result count of its link");
        assert_not_contains('page=', $href, "chip $v: no page parameter");
        assert_contains('occupation%5B0%5D=' . rawurlencode($v), $href, "chip $v: the link adds the occupation");
        assert_contains('family%5B0%5D=tehnic', $href, "chip $v: the link keeps the filter");
    }
    assert_same(['#facet-occupation', 'Toate ocupațiile'], $r['all'], 'the last item: the link "Toate ocupațiile", to the anchor of the group');
    $x = xp($h);
    assert_same(1, $x->query('//*[@id="facet-occupation"][@data-facet="occupation"]')->length, 'the anchor is the group "Ocupație" of the filter list');
    assert_same(1, $x->query('//*[@data-occ-chips]/*[contains(@class,"g2-text-meta")][@id="occ-chips-label"]')->length, 'the label is inline text before the chips');
    // The row is inside the result region, after the active filters and before the first result.
    $res = $x->query('//*[@id="results"]//*[@data-occ-chips]');
    assert_same(1, $res->length, 'the row is inside #results (part of the result region)');
    assert_true(strpos($h, 'data-active-chips') < strpos($h, 'data-occ-chips'), 'after the row of active filters');
    assert_true(strpos($h, 'data-occ-chips') < strpos($h, 'class="pg-results'), 'before the first result');
    // A request of the filter form (htmx) brings the row too.
    [, $frag] = http_page($basec, '/?' . CHIP_BASE, ['HX-Request: true']);
    assert_true(chip_row($frag) !== null, 'the htmx fragment has the row');
    // A page after the first: the links have no page parameter.
    $r2 = chip_row(http_page($basec, '/?' . CHIP_BASE . '&page=2')[1]);
    assert_true($r2 !== null, 'page 2 has the row');
    foreach ($r2['chips'] as $c) assert_not_contains('page=', $c[2], "page 2, chip {$c[0]}: no page parameter");
    // Role tokens only.
    foreach (['bg-amber', 'text-slate', '#1d4f91'] as $raw) assert_not_contains($raw, (string)substr($h, (int)strpos($h, 'data-occ-chips'), 1500), "no raw palette: $raw");
});

run_suite('occupation chips: each condition has a positive and a negative case', function () use ($basec) {
    $has = fn(string $q) => chip_row(http_page($basec, '/?' . $q)[1]) !== null;
    // 1. More than one page (25): 26 results show the row, 25 do not.
    assert_same(26, list_count($basec, '/?' . CHIP_BASE . '&judet%5B%5D=cluj&judet%5B%5D=bacau'), 'two counties: 26 results');
    assert_true($has(CHIP_BASE . '&judet%5B%5D=cluj&judet%5B%5D=bacau'), '26 results: the row is shown');
    assert_same(25, list_count($basec, '/?' . CHIP_BASE . '&judet%5B%5D=cluj'), 'one county: 25 results');
    assert_false($has(CHIP_BASE . '&judet%5B%5D=cluj'), '25 results (one page): no row');
    // 2. No occupation filter: the same 26 results with 3 occupations selected show no row.
    $occ3 = '&occupation%5B%5D=' . rawurlencode('Pază') . '&occupation%5B%5D=' . rawurlencode('Îngrijitor') . '&occupation%5B%5D=' . rawurlencode('Bucătar');
    assert_same(26, list_count($basec, '/?' . CHIP_BASE . $occ3), 'three occupations: 26 results (more than one page)');
    assert_false($has(CHIP_BASE . $occ3), 'an occupation filter is on: no row');
    assert_same(28, list_count($basec, '/?' . CHIP_BASE . '&occupation_not%5B%5D=Nimic'), 'an exclusion of an occupation that no posting has: 28 results');
    assert_false($has(CHIP_BASE . '&occupation_not%5B%5D=Nimic'), 'occupation_not is on: no row (the counts of the filter list leave it out)');
    // 3. At least 2 occupations with 2 or more postings: Pază 25 and three occupations with one posting have one.
    [$b3, $d3, $s3] = chips_server('one', ['Pază' => 25, 'Brancardier' => 1, 'Frizer' => 1, 'Croitor' => 1]);
    assert_false(chip_row(http_page($b3, '/?' . CHIP_BASE)[1]) !== null, 'only one occupation has 2 or more postings: no row (the largest has 89 %, so the 90 % rule is not the reason)');
    stop_fixture_server($s3, $d3);
    // 4. The largest occupation has 90 % or less: Pază 26 of 28 (93 %) hides the row; Pază 24 of 28 (86 %) shows it.
    [$b4, $d4, $s4] = chips_server('big', ['Pază' => 26, 'Îngrijitor' => 2]);
    assert_false(chip_row(http_page($b4, '/?' . CHIP_BASE)[1]) !== null, 'the largest occupation has 93 %: no row (two occupations have 2 or more)');
    stop_fixture_server($s4, $d4);
    [$b5, $d5, $s5] = chips_server('ok', ['Pază' => 24, 'Îngrijitor' => 2, 'Bucătar' => 2]);
    $r5 = chip_row(http_page($b5, '/?' . CHIP_BASE)[1]);
    assert_true($r5 !== null, 'the largest has 86 %: the row is shown');
    assert_same([['Pază', 24], ['Îngrijitor', 2], ['Bucătar', 2]], array_map(fn($c) => [$c[0], $c[1]], $r5['chips']), 'its chips (equal counts keep the order of the filter list)');
    stop_fixture_server($s5, $d5);
});

run_suite('occupation_chips(): the boundaries of each rule, at most 8 chips', function () {
    $o = fn(array $counts) => array_map(fn($v, $c) => ['val' => $v, 'cnt' => $c], array_keys($counts), array_values($counts));
    // Boundary of the 90 % rule: 90 of 100 is allowed, 91 is not.
    assert_same(2, count(occupation_chips($o(['A' => 90, 'B' => 10]), 100, 25, [])), '90 % of the results: shown');
    assert_same([], occupation_chips($o(['A' => 91, 'B' => 9]), 100, 25, []), '91 % of the results: no row');
    // Boundary of the page: 25 results no, 26 yes.
    assert_same([], occupation_chips($o(['A' => 13, 'B' => 12]), 25, 25, []), '25 results: no row');
    assert_same(2, count(occupation_chips($o(['A' => 13, 'B' => 13]), 26, 25, [])), '26 results: the row');
    // 2 or more postings: a value with 1 is left out; two such values are needed.
    assert_same([['val' => 'A', 'cnt' => 30], ['val' => 'B', 'cnt' => 2]], occupation_chips($o(['A' => 30, 'B' => 2, 'C' => 1]), 40, 25, []), 'C (1 posting) is left out');
    assert_same([], occupation_chips($o(['A' => 30, 'B' => 1, 'C' => 1]), 40, 25, []), 'only one value with 2 or more: no row');
    // No filter on the occupation.
    assert_same([], occupation_chips($o(['A' => 20, 'B' => 20]), 40, 25, ['A']), 'occupation[] is set');
    assert_same([], occupation_chips($o(['A' => 20, 'B' => 20]), 40, 25, [], ['A']), 'occupation_not[] is set');
    assert_same(2, count(occupation_chips($o(['A' => 20, 'B' => 20]), 40, 25, [''], [''])), 'blank values do not count as a filter');
    // At most 8, largest first (the options may come in any order).
    $nine = ['A' => 10, 'B' => 9, 'C' => 8, 'D' => 7, 'E' => 6, 'F' => 5, 'G' => 4, 'H' => 3, 'I' => 2];
    $chips = occupation_chips($o(array_reverse($nine, true)), 60, 25, []);
    assert_same(8, count($chips), 'at most 8 chips');
    assert_same(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'], array_column($chips, 'val'), 'the 8 largest, largest first');
});

stop_fixture_server($srvc, $dbc);

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
    assert_same(0, xp($h)->query('//*[@data-county-clear]')->length, 'a theme with no counties section: no button "Toate județele"');
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
    assert_same(['/sanatate/', '/administratie/', '/financiar/', '/social/', '/justitie/', '/cifre/', '/gol/'], array_map(fn($a) => $a->getAttribute('href'), iterator_to_array($home->query('//footer//h2[.="Pagini tematice"]/following-sibling::ul[1]//a'))), 'footer links, in configuration order');
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

// ---- the weekly chart counts posts, and draws bars (brief 5) ----

run_suite('posts_sql(): 1 to 30 as it is; a missing value, 0, 31 and 339395 count as 1', function () {
    $out = php_on(theme_db('postsrule'), '$p = new PDO("sqlite::memory:"); $p->exec("CREATE TABLE t (nr_posturi INTEGER)");
        foreach ([2, null, 0, 31, 339395, 1, 30] as $v) $p->prepare("INSERT INTO t VALUES (?)")->execute([$v]);
        echo json_encode(array_map("intval", $p->query("SELECT " . posts_sql() . " FROM t")->fetchAll(PDO::FETCH_COLUMN)));');
    assert_same([2, 1, 1, 1, 1, 1, 30], json_decode($out, true), 'one case for each branch: 2, missing, 0, 31, 339395, and the edges 1 and 30');
    assert_same('CASE WHEN a.n BETWEEN 1 AND 30 THEN a.n ELSE 1 END', posts_sql('a.n'), 'the column can be qualified (the archive alias)');
});

/** A theme fixture where some announcements of the health family have a post count (see the rule of posts). */
function posts_db(): string {
    // 9102 Medic, 12 Aug (week 5): 7 posts. 9107 Kinetoterapeut and 9108 Psiholog, 14 and 15 Jul (week 1): 339395 and 0, so 1 each.
    // 9110 Medic rezident, 19 Aug (week 6): 31, so 1. 2002 Asistent, 15 Sep (week 10): 2. 9001 (week 3) has 2 in the fixture.
    $sql = '';
    foreach ([9102 => 7, 9107 => 339395, 9108 => 0, 9110 => 31, 2002 => 2] as $id => $n)
        $sql .= "UPDATE job_postings SET nr_posturi = $n WHERE id = $id; UPDATE archived_postings SET nr_posturi = $n WHERE id = $id; ";
    return theme_db('posts', $sql);
}

run_suite('the chart sums posts: the landing total, a theme total and each group', function () {
    $db = posts_db();
    $health = json_decode(php_on($db, 'echo json_encode(array_column(home_weekly_postings(12, "sănătate"), "n"));'), true);
    assert_same([1, 2, 0, 2, 1, 8, 1, 0, 1, 0, 3, 0], $health, 'the weeks of the theme in posts (COUNT(*) gives [1, 2, 0, 1, 1, 2, 1, 0, 1, 0, 2, 0])');
    $pdo = new PDO('sqlite:' . $db);
    $starts = [];
    for ($i = 0, $t = new DateTimeImmutable('2026-07-06'); $i < 12; $i++) $starts[] = $t->modify("+$i weeks")->format('Y-m-d');
    $side = fn(string $w) => (int)$pdo->query("SELECT SUM(p) FROM (
        SELECT substr(published_at,1,10) d, " . POSTS_SIDE . " p FROM job_postings
        UNION ALL SELECT substr(published_at,1,10), " . POSTS_SIDE . " FROM archived_postings WHERE id NOT IN (SELECT id FROM job_postings))
        WHERE d >= '$w' AND d < date('$w', '+7 days')")->fetchColumn();
    $landing = json_decode(php_on($db, 'echo json_encode(array_column(home_weekly_postings(12), "n"));'), true);
    assert_same(array_map($side, $starts), $landing, 'the landing total equals the posts of the SQL on the side');
    $pdo->exec("UPDATE job_postings SET nr_posturi = NULL, published_at = '2026-07-13' WHERE id = 1003");
    $groups = json_decode(php_on($db, 'echo json_encode(theme_group_series("sănătate", [["label" => "Medic", "prefixes" => ["Medic"], "except" => ["Medic rezident"]]], 12));'), true);
    assert_same([0, 0, 0, 0, 0, 7, 0, 0, 1, 0, 0, 0], $groups['groups'][0], 'the group Medic in posts: 7 on 12 Aug (9102), 1 on 2 Sep');
    assert_same($groups['total'], json_decode(php_on($db, 'echo json_encode(array_column(home_weekly_postings(12, "sănătate"), "n"));'), true), 'the group function and the total function agree');
});

/** Per week: the posts that the titles of the segments give, and the heights; from the SVG of a chart page. */
function chart_bars(string $html): array {
    $x = xp($html);
    $out = [];
    foreach ($x->query('//figure[@data-chart or not(@data-chart)]//svg[@role="img"]//g[@data-bar]') as $g) {
        $segs = [];
        foreach ($x->query('.//rect', $g) as $r) {
            preg_match('/: ([\d.]+) (?:de )?post(?:uri)?$/u', $x->query('./title', $r)->item(0)->textContent, $m);
            $segs[] = ['posts' => (int)str_replace('.', '', $m[1]), 'h' => (float)$r->getAttribute('height'), 'class' => $r->getAttribute('class'),
                       'title' => $x->query('./title', $r)->item(0)->textContent];
        }
        $out[] = $segs;
    }
    return $out;
}

run_suite('the chart of a theme: 12 stacks; the segments of each week add up to the total; the colours, the legend, the texts', function () {
    $db = posts_db();
    $srv = start_fixture_server($db);
    [, $h] = http_page($srv['base'], '/sanatate/');
    stop_fixture_server($srv, $db);
    $weeks = chart_bars($h);
    assert_same(12, count($weeks), 'twelve stacks');
    assert_same(0, preg_match('/<polyline|<path/', substr($h, strpos($h, '<figure'), strpos($h, '</figure>') - strpos($h, '<figure'))), 'no line and no area in the chart');
    preg_match('#<script type="application/json" id="theme-panel-data">(.*?)</script>#s', $h, $m);
    $d = json_decode($m[1], true);
    $hi = max($d['total']);
    foreach ($weeks as $i => $segs) {
        assert_same($d['total'][$i], array_sum(array_column($segs, 'posts')), "week $i: the segments add up to the total");
        assert_true(abs(array_sum(array_column($segs, 'h')) - $d['total'][$i] / $hi * 60) < 0.05, "week $i: the heights add up (segments touch) to the height of the total");
        foreach ($segs as $sg) assert_true($sg['posts'] > 0, "week $i: no empty segment");
    }
    // Week 1: Kinetoterapeut and Psiholog are in no group: one segment, "Alte ocupații", 2 posts (the class).
    assert_same(1, count($weeks[1]), 'week 1: one segment');
    assert_contains('pg-c-other', $weeks[1][0]['class'], 'week 1: "Alte ocupații"');
    assert_contains('Alte ocupații', $weeks[1][0]['title'], 'its title names it');
    assert_contains('săpt. 13 iul', $weeks[1][0]['title'], 'and the week');
    // Week 5: Medic 7 (class pg-c-1, chart-2: the first group), Asistent 1 (the third group, pg-c-2). Bottom up: the groups in order.
    assert_same(['pg-seg pg-c-1', 'pg-seg pg-c-2'], array_column($weeks[5], 'class'), 'week 5: the colours of the groups, in order');
    assert_same([7, 1], array_column($weeks[5], 'posts'), 'week 5: Medic 7, Asistent 1');
    assert_contains('săpt. 10 aug, Medic: 7 posturi', $weeks[5][0]['title'], 'the title: week, group and posts');
    // The Alte ocupații segment is never below 0: the total minus the groups.
    foreach (array_keys($d['total']) as $i) assert_true($d['total'][$i] - array_sum(array_column(array_column($d['series'], 'values'), $i)) >= 0, "week $i: Alte ocupații is not below 0");
    // The texts: the caption, the estimate, and the posts in the label.
    assert_not_contains('Estimare: un anunț', $h, 'no sentence about the estimate (owner, 2026-11-18)');
    assert_contains('aria-label="Posturi publicate pe săptămână, ultimele 12 săptămâni complete: între 0 și 8 posturi"', $h, 'the label speaks of posts');
    assert_same('Posturi publicate pe săptămână', $d['caption'], 'the caption of the JSON');
    assert_same(12, count($d['weeks']), 'the JSON has the 12 week labels');
    // The buttons: a square of the colour of the group before the text; "Toate" has none; the legend entry is not a button.
    $x = xp($h);
    foreach ($x->query('//button[@data-chart-series]') as $b) {
        $sq = $x->query('./span[contains(@class,"pg-chart-sq")]', $b)->length;
        assert_same($b->getAttribute('data-chart-series') === '-1' ? 0 : 1, $sq, 'the button ' . trim($b->textContent) . ': a square only on a group');
    }
    assert_same(['pg-c-1', 'pg-c-0', 'pg-c-2', 'pg-c-3'], array_map(fn($n) => preg_replace('/^.*(pg-c-\d).*$/', '$1', $n->getAttribute('class')), iterator_to_array($x->query('//button[@data-chart-series!="-1"]/span'))), 'the squares: chart-2, chart-1, chart-3, chart-4');
    assert_same(1, $x->query('//span[@data-chart-other][contains(., "Alte ocupații")][not(self::button)]')->length, 'the legend entry "Alte ocupații", not a button');
    assert_not_contains('POSTURI_CHART_BARS', file_get_contents(__DIR__ . '/../partials/weekly_chart.php') . file_get_contents(__DIR__ . '/../static/theme-panel.js') . file_get_contents(__DIR__ . '/../helpers.php'), 'no switch of the prototype');
    assert_not_contains('polyline', file_get_contents(__DIR__ . '/../partials/weekly_chart.php') . file_get_contents(__DIR__ . '/../static/theme-panel.js'), 'no polyline in the sources');
    // No theme has more than 4 groups (the colours are 4); a fifth group needs a decision.
    require_once __DIR__ . '/../inc/themes.php';
    foreach (themes() as $slug => $t) assert_true(count($t['history']['groups'] ?? []) <= 4, "theme $slug: at most 4 chart groups");
});


run_suite('server log: no warnings, notices or fatals', function () use ($srv) {
    $log = (string)@file_get_contents($srv['log']);
    assert_not_contains('Warning', $log, 'no warning');
    assert_not_contains('Fatal', $log, 'no fatal');
    assert_not_contains('Notice', $log, 'no notice');
    assert_not_contains('Deprecated', $log, 'no deprecation');
});

finish();
