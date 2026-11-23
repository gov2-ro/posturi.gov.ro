<?php
/**
 * ANOMALY-PUBLIC — the public page /anomalii/, the page of one check, the 301 from the old URL,
 * the admin page and robots.
 *
 * Fixture: `anomaly_checks` holds checks in the five groups. R14 has one real signal and one
 * `false_positive` signal; R09a has a calendar quote; R25 has a caveat and a text quote.
 * A second database holds no anomaly tables. The admin page needs REMOTE_USER, which Apache sets;
 * the test starts one server without it (401) and one with it.
 *
 * Usage: php tests/anomalies_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/anomalies_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

function an_seed(string $path, bool $with_tables): void {
    build_fixture_db($path);
    if (!$with_tables) return;
    fixture_seed_anomalies(new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
}

function an_get(string $base, string $path): array {
    $h = @file_get_contents($base . $path, false, stream_context_create(['http' => ['ignore_errors' => true]]));
    $code = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) $code = (int)$m[1];
    }
    return [$code, (string)$h];
}

$db_a = sys_get_temp_dir() . '/posturi-anom-a-' . getmypid() . '.sqlite';
$db_b = sys_get_temp_dir() . '/posturi-anom-b-' . getmypid() . '.sqlite';
an_seed($db_a, true);
an_seed($db_b, false);
putenv('POSTURI_DB=' . $db_a);
$srv_pub = start_fixture_server($db_a, '2026-10-03');
$srv_none = start_fixture_server($db_b, '2026-10-03');
// The built-in server has no Apache login. A prepend file sets REMOTE_USER, as Apache does
// after a good password; the ini file that names it is read from PHP_INI_SCAN_DIR.
$ini_dir = sys_get_temp_dir() . '/posturi-anom-ini-' . getmypid();
@mkdir($ini_dir);
file_put_contents($ini_dir . '/login.php', "<?php \$_SERVER['REDIRECT_REMOTE_USER'] = 'tester';\n");
file_put_contents($ini_dir . '/login.ini', 'auto_prepend_file=' . $ini_dir . "/login.php\n");
putenv('PHP_INI_SCAN_DIR=' . $ini_dir);
$srv_admin = start_fixture_server($db_a, '2026-10-03');
putenv('PHP_INI_SCAN_DIR');

run_suite('the public page shows every group in order, with caveats and quotes, and hides a false positive', function () use ($srv_pub) {
    [$code, $h] = an_get($srv_pub['base'], '/anomalii/');
    assert_same(200, $code, 'status');
    assert_contains('<h1', $h, 'h1');
    assert_contains('Anomalii și abateri', $h, 'title');
    $order = ['erori_noastre', 'erori_anunt', 'neobisnuit', 'angajator', 'context'];
    $pos = [];
    foreach ($order as $g) {
        $p = strpos($h, 'id="grup-' . $g . '"');
        assert_true($p !== false, "group $g renders");
        $pos[] = $p;
    }
    $sorted = $pos;
    sort($sorted);
    assert_same($sorted, $pos, 'groups in the order of the spec');
    foreach (['GRUP-NOASTRE', 'GRUP-ANUNT', 'GRUP-NEOBISNUIT', 'GRUP-ANGAJATOR', 'GRUP-CONTEXT'] as $l) assert_contains($l, $h, "check $l");
    assert_contains('AVERTISMENT-R25 semnal din text.', $h, 'a caveat renders');
    assert_contains('Context, nu o anomalie: test.', $h, 'a context caveat renders');
    assert_contains('<blockquote class="pg-quote">', $h, 'a quote renders');
    assert_contains('candidați de <mark>sex masculin</mark> &amp; &lt;b&gt;x&lt;/b&gt;,<br>' . "\n" . 'conform legii. …', $h, 'match marked, text escaped, cut shown');
    assert_contains('din textul anunțului', $h, 'text source line');
    assert_contains('din calendarul concursului', $h, 'calendar source line');
    assert_contains('<mark>2026-01-01 10:00</mark>', $h, 'calendar line');
    assert_contains('>17<', $h, 'a real signal');
    assert_not_contains('>99<', $h, 'a false_positive signal is hidden');
    assert_contains('339395', $h, 'a signal of a check that was hidden before');
    assert_contains('Raportează o greșeală', $h, 'report link');
    assert_contains('Semnalele sunt statistici din date publice, nu acuzații.', $h, 'explainer');
    assert_contains('forms.gle/', $h, 'feedback form link');
    assert_contains('href="#check-R14"', $h, 'toc link');
    assert_contains('<summary>Cuprins</summary>', $h, 'toc block');
    assert_contains('rel="canonical"', $h, 'canonical');
    assert_not_contains('name="robots" content="noindex"', $h, 'indexed');
    foreach (['ilegal', 'fraud', 'neregul', 'cu dedica'] as $w) assert_not_contains($w, $h, "banned word $w");
});

run_suite('the page of one check lists its signals; an unknown check is a 404', function () use ($srv_pub) {
    [$code, $h] = an_get($srv_pub['base'], '/anomalii/R14/');
    assert_same(200, $code, 'status');
    assert_contains('GRUP-NEOBISNUIT', $h, 'label');
    assert_contains('>17<', $h, 'signal');
    assert_not_contains('GRUP-CONTEXT', $h, 'only this check');
    assert_not_contains('>99<', $h, 'false_positive hidden');
    assert_contains('/anomalii/R14/"', $h, 'canonical of the check');
    [$code] = an_get($srv_pub['base'], '/anomalii/ZZ99/');
    assert_same(404, $code, 'unknown check');
});

run_suite('/statistici/anomalii/ redirects with a 301', function () use ($srv_pub) {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0]]);
    foreach (['/statistici/anomalii/' => '/anomalii/', '/statistici/anomalii' => '/anomalii/', '/statistici/anomalii/R14/' => '/anomalii/R14/'] as $from => $to) {
        @file_get_contents($srv_pub['base'] . $from, false, $ctx);
        $code = 0;
        $loc = '';
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) $code = (int)$m[1];
            if (stripos($line, 'Location:') === 0) $loc = trim(substr($line, 9));
        }
        assert_same(301, $code, "status of $from");
        assert_same($to, $loc, "target of $from");
    }
});

run_suite('an export with no anomaly tables says so', function () use ($srv_none) {
    [$code, $h] = an_get($srv_none['base'], '/anomalii/');
    assert_same(200, $code, 'status');
    assert_contains('Acest export nu conține încă semnale', $h, 'no tables');
    assert_not_contains('<blockquote', $h, 'no quote');
});

run_suite('the admin page returns 401 without a login', function () use ($srv_pub) {
    [$code, $h] = an_get($srv_pub['base'], '/admin/anomalii/');
    assert_same(401, $code, 'status');
    assert_not_contains('GRUP-NEOBISNUIT', $h, 'no data');
    // A forged Authorization header must not open the page (PHP_AUTH_USER is not trusted).
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'header' => "Authorization: Basic " . base64_encode('x:y') . "\r\n"]]);
    @file_get_contents($srv_pub['base'] . '/admin/anomalii/', false, $ctx);
    $code = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) $code = (int)$m[1];
    }
    assert_same(401, $code, 'forged header');
});

run_suite('the admin page shows every check with the review numbers, behind a login', function () use ($srv_admin) {
    [$code, $h] = an_get($srv_admin['base'], '/admin/anomalii/');
    assert_same(200, $code, 'status');
    assert_contains('Eticheta GRUP-NEOBISNUIT', $h, 'a check');
    assert_contains('Eticheta GRUP-CONTEXT', $h, 'context');
    assert_contains('Citite: 12; reale: 11; fals pozitive: 1.', $h, 'review numbers');
    assert_not_contains('Stare:', $h, 'no state');
    assert_not_contains('Gata de publicare', $h, 'no publication');
    assert_contains('(fals pozitiv)', $h, 'a false_positive signal is visible to the admin');
    assert_contains('name="robots" content="noindex"', $h, 'noindex');
});

run_suite('a quote keeps its line breaks; the sitemap lists /anomalii/', function () use ($srv_pub) {
    [, $h] = an_get($srv_pub['base'], '/anomalii/');
    assert_contains('<b>x</b>,<br>' . "\n" . 'conform legii.', html_entity_decode($h), 'the line break of the quote');
    [, $sm] = an_get($srv_pub['base'], '/sitemap.xml');
    assert_contains('/anomalii/</loc>', $sm, 'sitemap entry');
});

run_suite('robots.txt disallows /admin/ and /statistici/ links to the public page', function () use ($srv_pub) {
    [, $r] = an_get($srv_pub['base'], '/robots.txt');
    assert_contains('Disallow: /admin/', $r, 'robots');
    assert_not_contains('/anomalii', $r, 'the public page is not disallowed');
    [, $s] = an_get($srv_pub['base'], '/statistici/');
    assert_contains('href="/anomalii/"', $s, 'link from stats');
    assert_not_contains('/admin/', $s, 'no link to the admin page');
});

stop_fixture_server($srv_none, $db_b);
stop_fixture_server($srv_admin, $db_a . '.admin');
stop_fixture_server($srv_pub, $db_a);
@unlink($ini_dir . '/login.php');
@unlink($ini_dir . '/login.ini');
@rmdir($ini_dir);
finish();
