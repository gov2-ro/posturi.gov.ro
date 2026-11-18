<?php
/**
 * /llms.txt (docs/briefs/2026-11-17-footer-llms-txt.md): the status and the type, the form of llmstxt.org (one title, one summary,
 * sections of links), that each link is on the site or on the GitHub repository and each internal link answers, that each themed page
 * is listed, that each parameter of the section "Căutare prin URL" is a key that build_filters() reads (checked in the source of the
 * function, and by a request), and that the number of active postings is the SQL count.
 *
 * Usage: php tests/llms_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../inc/themes.php';
require_once __DIR__ . '/fixtures/theme_db.php';
require_once __DIR__ . '/fixtures/server.php';
require_once __DIR__ . '/fixtures/page_helpers.php';

putenv('POSTURI_TODAY=2026-10-03');
$db = sys_get_temp_dir() . '/posturi-llms-' . getmypid() . '.sqlite';
build_theme_fixture_db($db);
$srv = start_fixture_server($db);
$base = $srv['base'];
[$status, $txt, $hd] = http_page($base, '/llms.txt');
$lines = explode("\n", $txt);

run_suite('/llms.txt: status, content type, cache header', function () use ($status, $hd, $txt) {
    assert_same(200, $status, 'status 200');
    assert_same('text/plain; charset=utf-8', $hd['content-type'] ?? '', 'plain text, UTF-8');
    assert_contains('public, max-age=', $hd['cache-control'] ?? '', 'a cache header, as robots.txt');
    assert_not_contains('<html', $txt, 'no HTML');
});

run_suite('the form of llmstxt.org: one title, one summary, sections with link lists', function () use ($lines, $txt) {
    assert_same('# posturi.gov2.ro', $lines[0], 'the first line is the title');
    assert_same(1, count(array_filter($lines, fn($l) => str_starts_with($l, '# '))), 'one title');
    $summary = array_values(array_filter($lines, fn($l) => str_starts_with($l, '> ')));
    assert_same(1, count($summary), 'one summary');
    assert_contains('Nu este un site oficial al Guvernului României', $summary[0], 'the summary says that the site is not official');
    assert_contains('posturi.gov.ro', $summary[0], 'and names the source');
    $h2 = array_values(array_filter($lines, fn($l) => str_starts_with($l, '## ')));
    assert_same(['## Pagini', '## Date și fluxuri', '## Căutare prin URL', '## Despre date', '## Cod sursă'], $h2, 'the sections, in order');
    foreach ($lines as $l) if (str_starts_with($l, '- [')) assert_same(1, preg_match('#^- \[[^\]]+\]\(https?://[^)\s]+\): .+#u', $l), "link line: $l");
});

run_suite('each link is on the base URL of the site or on the repository; each internal link answers', function () use ($lines, $base) {
    preg_match_all('#\]\((https?://[^)\s]+)\)#', implode("\n", $lines), $m);
    assert_true(count($m[1]) >= 12, count($m[1]) . ' links');
    foreach ($m[1] as $url) {
        if (str_starts_with($url, $base . '/')) {
            [$st] = http_page($base, substr($url, strlen($base)));
            assert_true(in_array($st, [200, 301, 302], true), "$url answers 200 or a redirect (got $st)");
        } else {
            assert_same('https://github.com/gov2-ro/posturi.gov.ro', $url, 'the only other link is the repository');
        }
    }
    foreach (['/', '/posturi/', '/statistici/', '/angajatori/', '/despre/', '/pot-aplica/', '/estimator-salariu/', '/posturi.rss', '/posturi.atom', '/posturi.ics', '/sitemap.xml'] as $path) {
        assert_contains('](' . $base . $path . ')', implode("\n", $lines), "$path is listed");
    }
});

run_suite('each themed page is listed', function () use ($lines) {
    foreach (themes() as $slug => $t) assert_contains('[' . $t['label'] . '](' . $GLOBALS['base'] . '/' . $slug . '/)', implode("\n", $lines), "theme $slug");
});

run_suite('"Căutare prin URL": at most 10 parameters, each a key that build_filters() reads, each example a working list', function () use ($lines, $base) {
    $src = (string)file_get_contents(__DIR__ . '/../helpers.php');
    $a = strpos($src, 'function build_filters(');
    $fn = substr($src, $a, strpos($src, "\nfunction ", $a + 10) - $a);
    $in = false;
    $params = [];
    foreach ($lines as $l) {
        if (str_starts_with($l, '## ')) $in = $l === '## Căutare prin URL';
        elseif ($in && preg_match('#^- `([a-z_]+)`: .* Exemplu: (\S+)$#u', $l, $m)) $params[$m[1]] = $m[2];
    }
    assert_true(count($params) >= 5 && count($params) <= 10, count($params) . ' parameters');
    foreach ($params as $name => $url) {
        assert_same(1, preg_match("/\\\$p\\['" . $name . "'\\]|'" . $name . "'\\s*=>/", $fn), "$name is read by build_filters()");
        assert_true(str_starts_with($url, $base . '/?'), "$name: the example is a URL of the site");
        [$st] = http_page($base, substr($url, strlen($base)));
        assert_true(in_array($st, [200, 301], true), "$name: the example answers (got $st)");
    }
    // An example with a value narrows the list (status=soon and a county are not the whole list).
    $all = list_count($base, '/posturi/');
    foreach (['family', 'occupation', 'medspec', 'judet', 'studies_level'] as $name) {
        $n = list_count($base, substr($params[$name], strlen($base)));
        assert_true($n > 0 && $n < $all, "the example of $name has postings and is not the whole list ($n of $all)");
    }
});

run_suite('"Despre date": the number of active postings is the SQL count; the date of the data', function () use ($txt, $db) {
    $pdo = new PDO('sqlite:' . $db);
    $n = (int)$pdo->query("SELECT COUNT(*) FROM job_postings WHERE application_status IN ('confirmed_open','unconfirmed','unknown')")->fetchColumn();
    assert_true($n > 0, "$n active postings on the fixture (SQL on the side)");
    assert_contains('- Anunțuri active: ' . number_format($n, 0, ',', '.') . '.', $txt, 'the count');
    $built = (string)$pdo->query("SELECT built_at FROM build_meta")->fetchColumn();
    $want = $built !== '' ? (new DateTime($built))->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('d.m.Y, H:i') : '';
    assert_true($want !== '' && str_contains($txt, 'generată la ' . $want), "the date of the data ($want)");
    assert_contains('Salariile nete sunt estimări', $txt, 'the salary figures are estimates');
});

run_suite('robots.txt is not changed by llms.txt', function () use ($base) {
    [, $r] = http_page($base, '/robots.txt');
    assert_not_contains('llms', $r, 'no line about llms.txt in robots.txt');
    assert_contains('Disallow: /*?', $r, 'the rules stay');
});

stop_fixture_server($srv, $db);
finish();
