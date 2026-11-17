<?php
/**
 * UX-05 — the landing page, /posturi/ and the route rule of ADR 0004.
 *
 * Server-rendered checks against the fixture database on the built-in router:
 * which page each URL renders, the 301 redirects, the HX-Push-Url header, that
 * every count on the landing page equals the number of results of its link, that
 * an item with count 0 is not offered, that the (county, domain) JSON sums to
 * the tile counts, and the weekly chart rule. Browser behaviour (the county map,
 * the form, widths) is in tests/browser/home.spec.js.
 *
 * Usage: php tests/home_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';
require_once __DIR__ . '/fixtures/page_helpers.php';

putenv('POSTURI_TODAY=2026-10-03');
// The domains with active postings in the home fixture, by count (Altele last): set by the first run of this suite.
const DOMAINS_IN_FIXTURE = ['administratie', 'juridic', 'necalificati', 'social', 'sanatate'];

/**
 * A fixture database with the landing page's cases: sectors in several counties,
 * one posting for each ready-made search, and $weeks weeks of publications.
 */
function home_db(string $tag, int $weeks, bool $enrich = true): string {
    $path = sys_get_temp_dir() . "/posturi-home-$tag-" . getmypid() . '.sqlite';
    build_fixture_db($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($enrich) {
        $pdo->exec("
            UPDATE job_postings SET v4_employer_sector = 'sanatate' WHERE id IN (1001, 1002, 1008);
            UPDATE job_postings SET v4_employer_sector = 'educatie' WHERE id IN (1004, 1007);
            UPDATE job_postings SET v4_employer_sector = 'altele' WHERE id = 2001;
            UPDATE job_postings SET v4_employer_sector = 'sanatate' WHERE id = 1003;   -- closed: never counted
            UPDATE job_postings SET sal_min = 9200, v3_isced_fields = '[\"09_sanatate_asistenta_sociala\"]' WHERE id = 1001;
            UPDATE job_postings SET sal_min = 9200 WHERE id = 1006;
            UPDATE job_postings SET v3_languages = '[\"hu:B1\"]' WHERE id = 1007;
            UPDATE job_postings SET job_level = 'Funcții de conducere' WHERE id IN (1002, 1007);
            UPDATE job_postings SET v3_schedule = 'norma_partiala', sal_min = 5500, funding_kind = 'pnrr' WHERE id = 1004;
        ");
    }
    // $weeks distinct complete weeks before the week of 2026-10-03 (Monday 2026-09-28), one posting each,
    // on the postings 2001…; the rest are published on 2026-10-01 (this week: not complete).
    $ids = $pdo->query("SELECT id FROM job_postings WHERE id >= 2001 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $monday = new DateTimeImmutable('2026-09-28');
    $up = $pdo->prepare("UPDATE job_postings SET published_at = ? WHERE id = ?");
    foreach ($ids as $i => $id) {
        $d = $i < $weeks * 2
            ? $monday->modify('-' . (intdiv($i, 2) + 1) . ' weeks')->modify('+' . ($i % 2 + 1) . ' days')->format('Y-m-d') . ' 09:00:00'
            : '2026-10-01 09:00:00';
        $up->execute([$d, $id]);
    }
    // The archived rows and the older postings stay out of the window.
    $pdo->exec("UPDATE archived_postings SET published_at = '2026-01-15'");
    $pdo->exec("UPDATE job_postings SET published_at = '2026-10-01 09:00:00' WHERE id < 2001");
    return $path;
}

// ---- pure helpers ----

run_suite('Romanian labels: posturi, the link sentence, name order', function () {
    foreach ([0 => '0 posturi', 1 => '1 post', 2 => '2 posturi', 19 => '19 posturi', 20 => '20 de posturi',
              101 => '101 posturi', 119 => '119 posturi', 120 => '120 de posturi', 1197 => '1.197 de posturi'] as $n => $want) {
        assert_same($want, posturi_label($n), "posturi_label($n)");
    }
    assert_same('Vezi toate cele 1.197 de posturi active', home_all_link_text(1197), 'link sentence, 1197');
    assert_same('Vezi toate cele 37 de posturi active', home_all_link_text(37), 'link sentence, 37');
    assert_same('Vezi toate cele 12 posturi active', home_all_link_text(12), 'link sentence, 12');
    assert_same('Vezi singurul post activ', home_all_link_text(1), 'link sentence, 1');
    assert_same('Vezi lista posturilor', home_all_link_text(0), 'link sentence, 0');
    $names = ['Brașov', 'Brăila', 'Bacău', 'Bistrița-Năsăud', 'Argeș', 'Satu Mare', 'Sălaj', 'Sibiu', 'Iași', 'Ilfov', 'Timiș', 'Tulcea', 'Vâlcea', 'Vaslui'];
    $sorted = $names;
    usort($sorted, fn($a, $b) => strcmp(ro_sort_key($a), ro_sort_key($b)));
    assert_same(['Argeș', 'Bacău', 'Bistrița-Năsăud', 'Brașov', 'Brăila', 'Iași', 'Ilfov', 'Satu Mare', 'Sălaj', 'Sibiu', 'Timiș', 'Tulcea', 'Vaslui', 'Vâlcea'],
        $sorted, 'Romanian alphabet order (a < ă < â, s < ș, t < ț)');
});

run_suite('the county tile map: 42 counties, one cell each', function () {
    assert_same(42, count(HOME_COUNTY_TILES), '42 codes');
    assert_same(42, count(array_unique(array_column(HOME_COUNTY_TILES, 0))), '42 distinct slugs');
    $cells = [];
    foreach (HOME_COUNTY_TILES as [$slug, $col, $row]) {
        assert_true($col >= 1 && $col <= 9 && $row >= 1 && $row <= 7, "$slug inside the 9×7 grid");
        $cells["$col,$row"] = ($cells["$col,$row"] ?? 0) + 1;
    }
    assert_same(42, count($cells), 'no two counties share a cell');
    // The slugs are the ones of the real export (the dev export, when this checkout has it).
    $dev = __DIR__ . '/../../data/_dev/posturi-new.sqlite';
    if (is_file($dev)) {
        $pdo = new PDO('sqlite:' . $dev);
        $have = $pdo->query('SELECT slug FROM judete')->fetchAll(PDO::FETCH_COLUMN);
        assert_same([], array_values(array_diff(array_column(HOME_COUNTY_TILES, 0), $have)), 'all 42 slugs exist in judete');
    }
    assert_same([0, 0, 1, 1, 2, 2, 3], array_map('home_map_step', [0, 19, 20, 39, 40, 79, 80]), 'steps 0–19, 20–39, 40–79, 80+');
});

// ---- routes ----

$db = home_db('routes', 12);
$srv = start_fixture_server($db);
$base = $srv['base'];

run_suite('ADR 0004: which page each URL renders', function () use ($base) {
    [$st, $home] = http_page($base, '/');
    assert_same(200, $st, '/ answers 200');
    assert_contains('id="h-hero"', $home, '/ is the landing page');
    assert_not_contains('id="filter-form"', $home, '/ has no filter form');
    assert_contains('<link rel="canonical" href="' . $base . '/">', $home, 'landing canonical is /');

    foreach (['/?sort=deadline', '/?status=active', '/?page=2', '/?q=medic', '/?utm_source=x'] as $path) {
        [$st, $list] = http_page($base, $path);
        assert_same(200, $st, "$path answers 200");
        assert_contains('id="filter-form"', $list, "$path is the list");
        assert_not_contains('id="h-hero"', $list, "$path is not the landing page");
    }

    foreach (['/posturi/', '/posturi'] as $path) {
        [$st, $list] = http_page($base, $path);
        assert_same(200, $st, "$path answers 200");
        assert_contains('id="filter-form"', $list, "$path is the unfiltered list");
        assert_contains('<link rel="canonical" href="' . $base . '/posturi/">', $list, "$path canonical is /posturi/");
    }
    [$st, $list] = http_page($base, '/?status=all');
    assert_contains('<link rel="canonical" href="' . $base . '/posturi/">', $list, 'a filtered list folds into /posturi/, never into the landing page');

    foreach (['/posturi/?q=x' => '/?q=x', '/posturi?q=x' => '/?q=x',
              '/posturi/?judet%5B%5D=cluj&sort=deadline' => '/?judet%5B%5D=cluj&sort=deadline'] as $path => $to) {
        [$st, , $h] = http_page($base, $path);
        assert_same(301, $st, "$path is a 301");
        assert_same($to, $h['location'] ?? '', "$path goes to $to");
    }
});

run_suite('a blank form goes to /posturi/; blank values are dropped', function () use ($base) {
    foreach (['/?q=&judet%5B%5D=', '/?q=', '/?sort=', '/?q=%20&status='] as $path) {
        [$st, , $h] = http_page($base, $path);
        assert_same(301, $st, "$path is a 301");
        assert_same('/posturi/', $h['location'] ?? '', "$path goes to /posturi/");
    }
    [$st, , $h] = http_page($base, '/?q=x&judet%5B%5D=');
    assert_same(301, $st, 'q only, blank county: 301');
    assert_same('/?q=x', $h['location'] ?? '', 'the blank county is dropped');
    [$st, , $h] = http_page($base, '/?judet%5B%5D=cluj&judet%5B%5D=&sort=deadline');
    assert_same('/?judet%5B%5D=cluj&sort=deadline', $h['location'] ?? '', 'blank values inside a multi-value parameter are dropped');
    [$st] = http_page($base, '/?q=x&judet%5B%5D=cluj');
    assert_same(200, $st, 'a filled request is not redirected');
});

run_suite('an HTMX fragment with no filter pushes /posturi/', function () use ($base) {
    $cb = 'org.htmx.cache-buster=results';
    [$st, $body, $h] = http_page($base, "/?$cb");
    assert_same(200, $st, 'fragment answers 200');
    assert_not_contains('<!doctype', strtolower($body), 'a fragment, not a page');
    assert_same('/posturi/', $h['hx-push-url'] ?? '', 'empty key: HX-Push-Url is /posturi/');
    [, , $h] = http_page($base, "/?$cb&status=active");
    assert_same('/posturi/', $h['hx-push-url'] ?? '', 'the default status is not a filter');
    foreach (["/?$cb&q=medic", "/?$cb&judet%5B%5D=cluj", "/?$cb&sort=deadline", "/?$cb&status=all", "/?$cb&page=2"] as $path) {
        [, , $h] = http_page($base, $path);
        assert_false(isset($h['hx-push-url']), "$path: no HX-Push-Url");
    }
    [, $page, $h] = http_page($base, '/posturi/?' . $cb);
    assert_not_contains('<!doctype', strtolower($page), '/posturi/ with the buster is a fragment too');
    // History restore: HX-Request without the buster gets the whole page.
    [, $full] = http_page($base, '/posturi/', ['HX-Request: true', 'HX-History-Restore-Request: true']);
    assert_contains('<!doctype html>', strtolower($full), 'history restore of /posturi/ gets the full page');
});

run_suite('sitemap, navigation and footer', function () use ($base) {
    [, $sm] = http_page($base, '/sitemap.xml');
    assert_contains('<loc>' . $base . '/posturi/</loc>', $sm, 'sitemap lists /posturi/');
    [, $home] = http_page($base, '/');
    $x = xp($home);
    $nav = [];
    foreach ($x->query('//nav[@aria-label="Navigare principală"]/a') as $a) $nav[] = [trim($a->textContent), $a->getAttribute('href')];
    assert_same([['Posturi', '/posturi/'], ['Salvate', '/salvate/'], ['Statistici', '/statistici/'], ['Despre', '/despre/']],
        $nav, 'four navigation links, in order: no "Alerte" in the masthead');
    assert_same(1, $x->query('//nav[@aria-label="Navigare principală"]/a[@href="/salvate/"]/svg[@aria-hidden="true"]/use[@href="#i-bell"]')->length, 'the bell icon before "Salvate", as decoration');
    assert_same(1, $x->query('//nav//a[@href="/salvate/"]/span[@data-saved-count]')->length, 'the saved count stays');
    $groups = [];
    foreach ($x->query('//footer//h2') as $h2) {
        $links = [];
        foreach ($x->query('following-sibling::ul[1]//a', $h2) as $a) $links[] = [trim($a->textContent), $a->getAttribute('href')];
        $groups[trim($h2->textContent)] = $links;
    }
    assert_same(['Posturi', 'Pagini tematice', 'Urmărește', 'Proiect'], array_keys($groups), 'four footer groups');
    assert_same([['Toate posturile', '/posturi/'], ['Angajatori', '/angajatori/'], ['Statistici', '/statistici/'], ['Pot aplica?', '/pot-aplica/']], $groups['Posturi'], 'footer: Posturi');
    assert_same([['Sănătate', '/sanatate/']], $groups['Pagini tematice'], 'footer: Pagini tematice');
    assert_same([['Alerte pe email', '/alerte/'], ['Flux RSS', '/posturi.rss'], ['Calendar', '/posturi.ics'], ['Salvate', '/salvate/']], $groups['Urmărește'], 'footer: Urmărește');
    assert_same(['Despre și metodologie', 'Confidențialitate', 'Cod sursă', 'Pentru angajatori'], array_column($groups['Proiect'], 0), 'footer: Proiect');
    assert_contains('/despre/#confidentialitate', $home, 'footer: privacy anchor');
    assert_contains('nu este un proiect oficial', $home, 'the disclaimer stays');
    assert_not_contains('skin-picker', $home, 'the skin picker is gone (ADR 0005)');
    assert_not_contains('searchRedirect', $home, 'the UX-15 redirect script is gone');
    assert_not_contains('searchApplied', $home, 'no reader of the redirect flag');
});

// ---- counts ----

run_suite('each count on the page equals the number of results of its link', function () use ($base) {
    [, $home] = http_page($base, '/');
    $x = xp($home);
    $checked = 0;
    // Domain tiles.
    foreach ($x->query('//*[@data-home-tile]') as $a) {
        $n = paren_number($x->query('.//*[@data-tile-count]', $a)->item(0)->textContent);
        $href = $x->query('.//a', $a)->item(0)->getAttribute('href');
        assert_same(list_count($base, $href), $n, 'domain item ' . $a->getAttribute('data-domain'));
        assert_same(0, $x->query('.//a//*[@data-tile-count]', $a)->length, 'the domain count is outside the link');
        assert_same(1, preg_match('/^\(\d[\d.]*\)$/u', trim($x->query('.//*[@data-tile-count]', $a)->item(0)->textContent, " \t\n\r\0\x0B\xC2\xA0")), 'the domain count is in brackets, with no word');
        assert_same($n, (int)$a->getAttribute('data-total'), 'data-total of ' . $a->getAttribute('data-domain'));
        assert_same("\u{a0}", mb_substr($x->query('.//*[@data-tile-count]', $a)->item(0)->textContent, 0, 1), 'a no-break space before the domain count (the server markup, before script)');
        $checked++;
    }
    // Starting-point links: a text link, then its count outside the link.
    foreach ($x->query('//section[@aria-labelledby="h-start"]//li[.//*[@data-home-count]]') as $li) {
        $a = $x->query('.//a', $li)->item(0);
        $n = lead_number($x->query('.//*[@data-home-count]', $li)->item(0)->textContent);
        assert_same(list_count($base, $a->getAttribute('href')), $n, 'start link ' . trim($a->textContent));
        assert_same(0, $x->query('.//a//*[@data-home-count]', $li)->length, 'the count is outside the link');
        $checked++;
    }
    // Ready-made searches: the title link, and the count in brackets after it, outside the link.
    $cards = $x->query('//section[@aria-labelledby="h-combo"]//li[@data-home-example]');
    foreach ($cards as $li) {
        $a = $x->query('.//a', $li)->item(0);
        $n = paren_number($x->query('.//*[@data-example-count]', $li)->item(0)->textContent);
        assert_same(list_count($base, $a->getAttribute('href')), $n, 'search card ' . trim($a->textContent));
        assert_same(0, $x->query('.//a//*[@data-example-count]', $li)->length, 'the example count is outside the link');
        assert_same(0, $x->query('./p', $li)->length, 'no separate count line');
        $checked++;
    }
    // The count follows the title in the same run of text: a no-break space joins them, so it cannot start a line.
    foreach ($cards as $li) {
        assert_same("\u{a0}", mb_substr($x->query('.//*[@data-example-count]', $li)->item(0)->textContent, 0, 1), 'a no-break space before the example count');
        assert_same('', trim($x->query('.//h3', $li)->item(0)->getAttribute('class'), 'py-0.5 '), 'the title is not a flex row');
    }
    assert_same(6, $cards->length, 'the enriched fixture has all six ready-made searches');
    assert_true($checked > 15, "checked $checked links");
    // The link to /posturi/ and the first figure.
    $total = list_count($base, '/posturi/');
    $fig = $x->query('//section[@aria-labelledby="h-hero"]//h2[.="Cifre"]/following-sibling::div[1]/a[1]/span[1]');
    assert_same($total, lead_number($fig->item(0)->textContent), 'the first figure is the size of /posturi/');
    $all = $x->query('//*[@data-home-all-count]')->item(0);
    assert_same($total, paren_number($all->textContent), 'the count of "Toate anunțurile" is the size of /posturi/');
    assert_same(0, $x->query('//a//*[@data-home-all-count]')->length, 'the all-postings count is outside the link');
    $alink = $x->query('//section[@aria-labelledby="h-hero"]//a[@href="/posturi/"][normalize-space(.)="Toate anunțurile"]');
    assert_same(1, $alink->length, 'the link "Toate anunțurile" goes to /posturi/');
    assert_not_contains('Vezi toate cele', $home, 'the old sentence is gone');
});

run_suite('every ready-made search and chip link is the canonical URL', function () use ($base) {
    [, $home] = http_page($base, '/');
    $x = xp($home);
    foreach ($x->query('//li[@data-home-example]//a | //section[@aria-labelledby="h-start"]//a | //*[@data-home-tile]//a') as $a) {
        $href = $a->getAttribute('href');
        parse_str((string)parse_url($href, PHP_URL_QUERY), $params);
        assert_same('/?' . search_key($params), $href, "canonical: $href");
        assert_not_contains('&amp;amp;', $href, 'not double-escaped');
    }
    // The themed page of health comes first: its icon, its link, and the count of its base filter.
    $th = $x->query('//section[@aria-labelledby="h-combo"]//li')->item(1);
    assert_true($th->hasAttribute('data-home-theme-example'), 'the second card is the themed page');
    assert_same('/sanatate/', $x->query('.//a', $th)->item(0)->getAttribute('href'), 'the themed card links to /sanatate/');
    assert_same(list_count($base, '/?family%5B%5D=s%C4%83n%C4%83tate'), paren_number($x->query('.//*[@data-example-count]', $th)->item(0)->textContent), 'the themed card count is the count of its base filter');
    assert_same(1, $x->query('.//h3//svg', $th)->length, 'the themed card has the icon of the theme');
    $hrefs = array_map(fn($a) => $a->getAttribute('href'), iterator_to_array($x->query('//li[@data-home-example]//a')));
    assert_same([
        '/?isced_not%5B%5D=09_sanatate_asistenta_sociala&salary_bucket=net-peste-5000',
        '/?funding%5B%5D=fonduri_europene&funding%5B%5D=pnrr&salary_bucket=net-3000-4000&schedule%5B%5D=norma_partiala',
        '/?exp_level%5B%5D=fara',
        '/?level%5B%5D=Func%C8%9Bii%20de%20conducere',
        '/?duration%5B%5D=determinata&funding%5B%5D=fonduri_europene',
        '/?lang%5B%5D=hu',
    ], $hrefs, 'the six ready-made searches, in order (Hungarian last)');
    // The Hungarian card: a flag before the title, lang="hu" on the title only.
    $hu = $x->query('//section[@aria-labelledby="h-combo"]//li[@data-home-example][.//a[@href="/?lang%5B%5D=hu"]]')->item(0);
    assert_same('hu', $x->query('.//*[@lang]', $hu)->item(0)->getAttribute('lang'), 'lang="hu" on the title');
    assert_same(1, $x->query('.//*[@lang]', $hu)->length, 'lang on the title only');
    assert_same('Magyar nyelvtudást igénylő állások', trim($x->query('.//*[@lang]', $hu)->item(0)->textContent), 'the title');
    $img = $x->query('.//img', $hu)->item(0);
    assert_same('/static/flags/hu.svg', $img->getAttribute('src'), 'flag file');
    assert_true($img->hasAttribute('alt') && $img->getAttribute('alt') === '', 'flag: empty alt');
    assert_same('30', $img->getAttribute('width') . '', 'flag width');
    assert_same(1, $x->query('//img[@src="/static/flags/hu.svg"]')->length, 'one flag on the page');
    assert_true(is_file(__DIR__ . '/../static/flags/hu.svg'), 'the flag file exists');
    foreach (['CD2A3E', 'FFFFFF', '436F4D'] as $hex) assert_contains($hex, (string)file_get_contents(__DIR__ . '/../static/flags/hu.svg'), "flag colour $hex");
});

run_suite('an item with count 0 is not on the page', function () use ($base) {
    [, $home] = http_page($base, '/');
    $x = xp($home);
    foreach ($x->query('//*[@data-home-tile]') as $a) {
        assert_true((int)$a->getAttribute('data-total') > 0, 'tile ' . $a->getAttribute('data-domain') . ' > 0');
    }
    $domains = array_map(fn($a) => $a->getAttribute('data-domain'), iterator_to_array($x->query('//*[@data-home-tile]')));
    assert_same(DOMAINS_IN_FIXTURE, $domains, 'only the domains with postings; by count; Altele last');
    foreach (['family%5B%5D=IT', 'family%5B%5D=cultur%C4%83', 'family%5B%5D=financiar'] as $none) assert_not_contains($none, $home, "no tile for $none");
    // The employer-sector row: text links, no icons, in the order of the old grid; a sector with 0 is absent.
    $row = $x->query('//section[@aria-labelledby="h-start"]//h3[.="Domeniu angajator"]/following-sibling::ul[1]')->item(0);
    assert_true($row !== null, 'the group "Domeniu angajator" is on the page');
    assert_same(['/?sector%5B%5D=sanatate', '/?sector%5B%5D=educatie', '/?sector%5B%5D=justitie', '/?sector%5B%5D=altele'],
        array_map(fn($a) => $a->getAttribute('href'), iterator_to_array($x->query('.//a', $row))), 'sectors with postings, by count, Altele last');
    assert_same(0, $x->query('.//svg', $row)->length, 'no icons in the sector row');
    assert_not_contains('sector%5B%5D=transport', $home, 'no link for a sector with 0');
    foreach ($x->query('//section[@aria-labelledby="h-start"]//*[@data-home-count]') as $c) {
        assert_true(lead_number($c->textContent) > 0, 'chip count > 0');
    }
    // The base fixture has no management posting, no Hungarian posting, no salary above 5.000 net.
    $bare = home_db('bare', 12, false);
    $s2 = start_fixture_server($bare);
    [, $h2] = http_page($s2['base'], '/');
    assert_not_contains('Func%C8%9Bii%20de%20conducere', $h2, 'no card and no chip for management when it counts 0');
    assert_not_contains('lang%5B%5D=hu', $h2, 'no Hungarian card when it counts 0');
    assert_not_contains('salary_bucket=net-peste-5000', $h2, 'no high-salary card or chip when it counts 0');
    assert_not_contains('Telemuncă', $h2, 'no Telemuncă');
    assert_not_contains('Funcție publică', $h2, 'no Funcție publică');
    stop_fixture_server($s2, $bare);
});

run_suite('the starting-point groups: labels without "După", the salary and institution groups, counts of their links', function () use ($base) {
    [, $home] = http_page($base, '/');
    $x = xp($home);
    $titles = [];
    foreach ($x->query('//section[@aria-labelledby="h-start"]//h3') as $h) $titles[] = trim($h->textContent);
    assert_same(['Studii', 'Salariu', 'Ocupație', 'Domeniu angajator', 'Tip instituție', 'Alte filtre'], $titles, 'the six groups, in order');
    assert_not_contains('După studii', $home, 'no "După studii"');
    assert_not_contains('După ocupație', $home, 'no "După ocupație"');
    assert_not_contains('După angajator', $home, 'no "După angajator"');
    $items = fn(string $title) => $x->query('//section[@aria-labelledby="h-start"]//h3[.="' . $title . '"]/following-sibling::ul[1]/li');
    // Salary: the four net buckets that count above 0, each with the count of its link; never "fără salariu estimat".
    $sal = $items('Salariu');
    assert_true($sal->length >= 2, 'the salary group has items');
    foreach ($sal as $li) {
        $a = $x->query('.//a', $li)->item(0);
        assert_contains('salary_bucket=net-', $a->getAttribute('href'), 'a net bucket link');
        assert_contains(' lei net', $a->textContent, 'the label says net');
        assert_same(list_count($base, $a->getAttribute('href')), lead_number($x->query('.//*[@data-home-count]', $li)->item(0)->textContent), 'count = result count of ' . $a->getAttribute('href'));
    }
    assert_not_contains('fara-estimare', $home, 'no "fără estimare" on the landing page');
    // Institution type: each value of the filter "Instituție" with a count above 0, largest first.
    $inst = $items('Tip instituție');
    $got = [];
    foreach ($inst as $li) {
        $a = $x->query('.//a', $li)->item(0);
        parse_str((string)parse_url($a->getAttribute('href'), PHP_URL_QUERY), $q);
        assert_same(1, count($q['inst'] ?? []), 'one inst[] value');
        assert_same(EMPLOYER_KIND_LABELS[$q['inst'][0]], trim($a->textContent), 'label from EMPLOYER_KIND_LABELS');
        $n = lead_number($x->query('.//*[@data-home-count]', $li)->item(0)->textContent);
        assert_true($n > 0, 'count above 0');
        assert_same(list_count($base, $a->getAttribute('href')), $n, 'count = result count of ' . $a->getAttribute('href'));
        $got[] = $n;
    }
    assert_true(count($got) >= 2, 'the fixture has several institution types');
    $sorted = $got; rsort($sorted);
    assert_same($sorted, $got, 'largest first');
    foreach (array_keys(EMPLOYER_KIND_LABELS) as $k) {
        $present = str_contains($home, 'inst%5B%5D=' . $k);
        $n = max(0, list_count($base, home_list_url(['inst' => [$k]])));
        assert_same($n > 0, $present, "inst $k is on the page only with a count above 0 ($n)");
    }
});

run_suite('domain icons: each tile has a hidden icon that points to a symbol in the sprite', function () use ($base) {
    [, $home] = http_page($base, '/');
    $x = xp($home);
    $symbols = [];
    foreach ($x->query('//symbol[@id]') as $s) $symbols[$s->getAttribute('id')] = $s;
    $tiles = $x->query('//*[@data-home-tile]');
    assert_true($tiles->length > 0, 'the page has domain tiles');
    foreach ($tiles as $a) {
        $key = $a->getAttribute('data-domain');
        $svgs = $x->query('.//svg', $a);
        assert_same(1, $svgs->length, "tile $key has one icon");
        assert_same('true', $svgs->item(0)->getAttribute('aria-hidden'), "tile $key icon is aria-hidden");
        assert_same('false', $svgs->item(0)->getAttribute('focusable'), "tile $key icon is not focusable");
        $id = ltrim($x->query('.//use', $svgs->item(0))->item(0)->getAttribute('href'), '#');
        assert_true(isset($symbols[$id]), "tile $key icon target $id exists");
        assert_same('0 0 24 24', $symbols[$id]->getAttribute('viewbox'), "$id viewBox");
        assert_same('currentColor', $symbols[$id]->getAttribute('stroke'), "$id stroke");
    }
    // Every domain that the page can show has an icon in the sprite (checked on the definition, not on the fixture).
    foreach (HOME_DOMAINS as $d) assert_true(isset($symbols[$d['icon']]), 'the sprite has ' . $d['icon']);
    assert_true(isset($symbols['i-chart-bar']), 'the sprite has i-chart-bar');
    foreach (['administratie_locala', 'aparare', 'transport', 'agricultura'] as $gone) assert_true(!isset($symbols["i-sector-$gone"]), "the unused symbol i-sector-$gone is not in the sprite");
    assert_same('<svg class="h-8 w-8" aria-hidden="true" focusable="false"><use href="#i-x"/></svg>', icon('i-x', 'h-8 w-8'), 'icon() markup');
    assert_same('<svg aria-hidden="true" focusable="false"><use href="#i-x"/></svg>', icon('i-x'), 'icon() with no class');
});

run_suite('the (county, domain) JSON sums to the tile counts and to the filters of its link', function () use ($base) {
    [, $home] = http_page($base, '/');
    assert_true(preg_match('#<script type="application/json" id="home-data">(.*?)</script>#s', $home, $m) === 1, 'the JSON block is there');
    $data = json_decode($m[1], true);
    assert_true(is_array($data), 'the JSON parses');
    $x = xp($home);
    foreach ($x->query('//*[@data-home-tile]') as $a) {
        $key = $a->getAttribute('data-domain');
        $dom = $data['items'][$key];
        $family = array_values($dom['params']['family[]'] ?? []);
        $cor = array_values($dom['params']['cor[]'] ?? []);
        assert_same((int)$a->getAttribute('data-total'), array_sum($dom['counties']), "domain $key: the counties sum to the tile");
        $q = http_build_query(['family' => $family, 'cor' => $cor]);
        assert_same($x->query('.//a', $a)->item(0)->getAttribute('href'), '/?' . search_key(['family' => $family] + ($cor ? ['cor' => $cor] : [])), "domain $key: the link is its JSON filters");
        foreach ($dom['counties'] as $county => $n) {
            assert_same($n, list_count($base, '/?judet%5B%5D=' . $county . '&' . $q), "$county × $key equals its list");
        }
    }
    foreach ($data['counties'] as $county => $n) {
        assert_same($n, list_count($base, "/?judet%5B%5D=$county"), "county $county equals its list");
    }
    assert_same(list_count($base, '/posturi/'), $data['total'], 'the total equals /posturi/');
    assert_not_contains('</script><', substr($m[1], 0, 10), 'JSON is escaped for a script block');
});

run_suite('the county map: 42 buttons, labels from the counts, hidden without script', function () use ($base) {
    [, $home] = http_page($base, '/');
    $x = xp($home);
    $btn = $x->query('//button[@data-county]');
    assert_same(42, $btn->length, '42 county buttons');
    $data = json_decode(preg_match('#id="home-data">(.*?)</script>#s', $home, $m) ? $m[1] : '{}', true);
    foreach ($btn as $b) {
        $slug = $b->getAttribute('data-county');
        $n = $data['counties'][$slug] ?? 0;
        assert_same(posturi_label($n), explode(': ', $b->getAttribute('aria-label'), 2)[1], "$slug: aria-label count");
        assert_same($b->getAttribute('aria-label'), $b->getAttribute('title'), "$slug: title equals aria-label");
        assert_same('false', $b->getAttribute('aria-pressed'), "$slug: not pressed");
        assert_same((string)home_map_step($n), $b->getAttribute('data-step'), "$slug: step");
        assert_true(in_array(trim($b->textContent), array_keys(HOME_COUNTY_TILES), true), "$slug: code as text");
    }
    foreach ($x->query('//*[@data-home-js]') as $el) assert_true($el->hasAttribute('hidden'), 'the select and the map ship hidden');
    assert_same(2, $x->query('//*[@data-home-js]')->length, 'select block and map block');
    assert_same(0, $x->query('//*[@data-home-tile][@hidden]')->length, 'the tiles work without script');
    assert_same(1, $x->query('//*[@data-home-live][@role="status"][@aria-live="polite"]')->length, 'a polite live region');
    $opts = $x->query('//select[@id="home-judet"]/option');
    assert_same('', $opts->item(0)->getAttribute('value'), 'hero select: first option has an empty value');
    assert_same('Toate județele', $opts->item(0)->getAttribute('data-name'), 'hero select: first option');
    assert_same(['Toate județele', 'Bacău', 'Cluj', 'Ilfov'], array_map(fn($o) => $o->getAttribute('data-name'), iterator_to_array($opts)), 'hero select: counties by name');
    // Each option of both selects shows the county count of the map, in brackets.
    foreach (['home-judet', 'domenii-judet'] as $sid) {
        $os = $x->query('//select[@id="' . $sid . '"]/option');
        assert_same(4, $os->length, "$sid: the first option and the three counties of the fixture");
        assert_same('Toate județele', trim($os->item(0)->textContent), "$sid: the first option has no count");
        $seen = 0;
        foreach ($os as $o) {
            $v = $o->getAttribute('value');
            if ($v === '') continue;
            $btn = $x->query('//button[@data-county="' . $v . '"]')->item(0);
            assert_same(lead_number(explode(': ', $btn->getAttribute('aria-label'), 2)[1]), paren_number($o->textContent), "$sid $v: option count equals the map count");
            assert_same($o->getAttribute('data-name') . ' (', substr(trim($o->textContent), 0, strlen($o->getAttribute('data-name')) + 2), "$sid $v: name, then the count in brackets");
            $seen++;
        }
        assert_same(3, $seen, "$sid: every county of the list, also one with 0");
    }
});

run_suite('hero form and figures', function () use ($base) {
    [, $home] = http_page($base, '/');
    $x = xp($home);
    $f = $x->query('//form[@data-home-search]')->item(0);
    assert_same('get', strtolower($f->getAttribute('method')), 'GET');
    assert_same('/', $f->getAttribute('action'), 'action /');
    assert_same('q', $x->query('.//input[@type="search"]', $f)->item(0)->getAttribute('name'), 'search input q');
    assert_same('judet[]', $x->query('.//select', $f)->item(0)->getAttribute('name'), 'select judet[]');
    assert_same('Caută un post', trim($x->query('.//label[@for="home-q"]', $f)->item(0)->textContent), 'hidden label');
    assert_same('Caută', trim($x->query('.//button[@type="submit"]', $f)->item(0)->textContent), 'button');
    $fig = [];
    foreach ($x->query('//section[@aria-labelledby="h-hero"]//h2[.="Cifre"]/following-sibling::div[1]/a[not(@data-home-stats)]') as $a) {
        $fig[] = [trim($a->childNodes->item(1)->textContent), trim($a->childNodes->item(3)->textContent), $a->getAttribute('href')];
    }
    assert_same(['posturi active', 'angajatori', 'noi în 7 zile'], array_column($fig, 1), 'three figures');
    assert_same(['/posturi/', '/angajatori/', '/posturi/'], array_column($fig, 2), 'figure links');
    $pdo = new PDO('sqlite:' . sys_get_temp_dir() . '/posturi-home-routes-' . getmypid() . '.sqlite');
    $open = "application_status IN ('confirmed_open','unconfirmed','unknown')";
    assert_same((int)$pdo->query("SELECT COUNT(DISTINCT employer_id) FROM job_postings WHERE $open")->fetchColumn(), lead_number($fig[1][0]), 'employers with an active posting');
    assert_same((int)$pdo->query("SELECT COUNT(*) FROM job_postings WHERE $open AND substr(published_at,1,10) >= '2026-09-27'")->fetchColumn(), lead_number($fig[2][0]), 'published in the last 7 days (today 2026-10-03, 7 days with today)');
    assert_not_contains('Date actualizate:', $home, 'no freshness line in the hero (the header has it)');
    assert_not_contains('Adunăm zilnic', $home, 'no lead paragraph');
    assert_same('Anunțuri de angajare în administrația publică', trim($x->query('//h1')->item(0)->textContent), 'the h1');
    assert_contains('<title>Anunțuri de angajare în administrația publică — posturi.gov2.ro</title>', $home, 'page title');
    $off = $x->query('//strong[.="nu este afiliat"]')->item(0);
    assert_same('Date preluate de pe posturi.gov.ro. Proiectul nu este afiliat Guvernului României.', preg_replace('/\s+/', ' ', trim($off->parentNode->parentNode->textContent)), 'the last line of the hero');
    assert_same('https://posturi.gov.ro', $x->query('.//a', $off->parentNode->parentNode)->item(0)->getAttribute('href'), 'posturi.gov.ro is a link');
    assert_same(0, $x->query('//h3[.="După județ"]')->length + $x->query('//*[@aria-labelledby="h-start"]//a[.="Toate județele"]')->length, 'no group "După județ"');
    assert_not_contains('După județ', $home, 'no group "După județ"');
    $stats = $x->query('//section[@aria-labelledby="h-hero"]//a[@data-home-stats]');
    assert_same(1, $stats->length, 'one "Statistici" link in the figures row');
    assert_same('/statistici/', $stats->item(0)->getAttribute('href'), 'it goes to /statistici/');
    assert_same('Statistici →', preg_replace('/\s+/u', ' ', trim($stats->item(0)->textContent)), 'its text');
    assert_same(1, $x->query('.//svg[@aria-hidden="true"]/use[@href="#i-chart-bar"]', $stats->item(0))->length, 'a decorative chart icon before the text');
    assert_same($x->query('//h2[.="Cifre"]/following-sibling::div[1]')->item(0)->nodeName, $stats->item(0)->parentNode->nodeName, 'the link is in the row of the figures');
    $to = $x->query('//section[@aria-labelledby="h-hero"]//a[@data-home-to-domains]');
    assert_same('#domenii', $to->item(0)->getAttribute('href'), '"După domeniu" points to #domenii');
    assert_same(1, $x->query('//section[@id="domenii"][@tabindex="-1"]')->length, 'the target exists and takes focus');
    $lab = $x->query('//label[@for="domenii-judet"]');
    assert_same(1, $lab->length, 'the domains select has a label');
    assert_same('sr-only', trim($lab->item(0)->getAttribute('class')), 'the label is visually hidden, not removed');
    assert_not_contains('Mai multe statistici', $home, 'the old statistics link is gone');
    // Section order.
    preg_match_all('#<section[^>]*aria-labelledby="([^"]+)"#', substr($home, (int)strpos($home, '<main')), $s);
    assert_same(['h-hero', 'h-combo', 'h-dom', 'h-start', 'h-now', 'h-follow', 'h-tools'], $s[1], 'seven sections, in order');
    assert_contains('id="domenii"', $home, 'the section of the domains has the id domenii');
    // Wireframe annotations are not on the page.
    foreach (['1 · HERO', '[date ilustrative]', '[ilustrativ', 'CĂUTĂRI COMBINATE', 'NOTIFICĂRI'] as $note) assert_not_contains($note, $home, "no annotation: $note");
    foreach (['bg-amber', 'text-slate', 'bg-white', '#1d4f91'] as $raw) assert_not_contains($raw, $home, "no raw palette: $raw");
});

stop_fixture_server($srv, $db);

// ---- the chart ----

/** Fetch the landing page of a database; returns the HTML. */
function home_html(string $path): string {
    $srv = start_fixture_server($path);
    [, $html] = http_page($srv['base'], '/');
    stop_fixture_server($srv, $path);
    return $html;
}

run_suite('the weekly chart: shown from 4 weeks with data, counted once, 12 complete weeks', function () {
    foreach ([3 => false, 4 => true, 12 => true] as $weeks => $shown) {
        $db = home_db("chart$weeks", $weeks);
        $html = home_html($db);
        assert_same($shown, str_contains($html, 'Posturi publicate pe săptămână'), "$weeks weeks with data: chart " . ($shown ? 'shown' : 'hidden'));
        assert_same($shown, str_contains($html, 'role="img"'), "$weeks weeks: the svg");
        assert_contains('data-home-stats', $html, "$weeks weeks: the link to /statistici/ stays");
        assert_not_contains('Mai multe statistici', $html, "$weeks weeks: the old link is gone");
    }
    // Figures of the 12-week case, computed on the side.
    $db = home_db('chartfig', 12);
    $pdo = new PDO('sqlite:' . $db);
    // An archived row that shares an id with a live posting must not be counted twice.
    $pdo->exec("INSERT INTO archived_postings (id, url, title, employer_id, employer_name, judet_name, judet_slug, locality, published_at, expires_at, apply_deadline, cancelled, nr_posturi, occ_canonical, inf_profession_family, sal_min, sal_max, summary)
                VALUES (2001, 'https://x/2001', 'dup', 1, 'e', 'Cluj', 'cluj', '', '2026-07-15', NULL, NULL, 0, NULL, '', '', NULL, NULL, '')");
    // A distinct archived posting in the oldest week counts once.
    $pdo->exec("INSERT INTO archived_postings (id, url, title, employer_id, employer_name, judet_name, judet_slug, locality, published_at, expires_at, apply_deadline, cancelled, nr_posturi, occ_canonical, inf_profession_family, sal_min, sal_max, summary)
                VALUES (990001, 'https://x/990001', 'old', 1, 'e', 'Cluj', 'cluj', '', '2026-07-07', NULL, NULL, 0, NULL, '', '', NULL, NULL, '')");
    $shared = (int)$pdo->query('SELECT COUNT(*) FROM job_postings j JOIN archived_postings a ON a.id = j.id')->fetchColumn();
    assert_same(1, $shared, 'the test database has one id in both tables');
    $per_week = [];
    $monday = new DateTimeImmutable('2026-09-28');
    for ($w = 12; $w >= 1; $w--) {
        $from = $monday->modify("-$w weeks")->format('Y-m-d');
        $to = $monday->modify('-' . ($w - 1) . ' weeks')->format('Y-m-d');
        $n = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT substr(published_at,1,10) d FROM job_postings UNION ALL SELECT substr(published_at,1,10) FROM archived_postings WHERE id NOT IN (SELECT id FROM job_postings)) WHERE d >= '$from' AND d < '$to'")->fetchColumn();
        $per_week[] = $n;
    }
    $pdo = null;
    assert_same($per_week, array_column(home_weekly_postings_on($db), 'n'), 'the helper gives the independent counts, week by week');
    $html = home_html($db);
    assert_contains('min ' . min($per_week) . ' · max ' . max($per_week) . ' · ultima ' . end($per_week), $html, 'min, max and last');
    assert_contains('între ' . min($per_week) . ' și ' . max($per_week), $html, 'the svg label');
    // The polyline, drawn on the side from the counts: the shared partial (partials/weekly_chart.php) draws the landing chart as before.
    [$lo, $hi] = [min($per_week), max($per_week)];
    $pts = [];
    foreach ($per_week as $i => $v) $pts[] = round($i / 11 * 320, 1) . ',' . round($hi === $lo ? 32 : 4 + (1 - ($v - $lo) / ($hi - $lo)) * 56, 1);
    assert_contains('points="' . implode(' ', $pts) . '"', $html, 'the polyline of the landing chart');
    assert_contains('<figcaption class="g2-label">Posturi publicate pe săptămână</figcaption>', $html, 'the caption of the landing chart');
    assert_contains('aria-label="Posturi publicate pe săptămână, ultimele 12 săptămâni complete: între ', $html, 'the label of the landing chart');
    assert_same(home_weekly_postings_on($db), home_weekly_postings_on($db, true), 'a null family is the landing result');
    assert_contains('6 iul', $html, 'first week: Monday 2026-07-06');
    assert_contains('21 sep', $html, 'last week: Monday 2026-09-21');
    assert_same(12, count($per_week), 'twelve weeks');
    @unlink($db);
});

/** home_weekly_postings() against another database file. */
function home_weekly_postings_on(string $path, bool $null_family = false): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        'putenv("POSTURI_DB=' . $path . '"); putenv("POSTURI_TODAY=2026-10-03"); require "' . __DIR__ . '/../db.php"; require "' . __DIR__ . '/../helpers.php";'
        . 'echo json_encode(home_weekly_postings(12' . ($null_family ? ', null' : '') . '));');
    return json_decode((string)shell_exec($cmd), true) ?: [];
}

finish();
