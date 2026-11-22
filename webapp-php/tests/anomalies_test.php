<?php
/**
 * ANOMALY-PUBLIC phase 1 — public page, admin page, robots.
 *
 * Fixture: `anomaly_checks` holds one `published` check (with one signal and one
 * `false_positive` signal), one `listed` check and one `hidden` check. A second database holds
 * no published check. The admin page needs REMOTE_USER, which Apache sets; the test starts one
 * server without it (403) and one with it.
 *
 * Usage: php tests/anomalies_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

function an_seed(string $path, bool $with_published): void {
    build_fixture_db($path);
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec("CREATE TABLE anomaly_signals (posting_id INTEGER, check_id TEXT, value TEXT, peer_value TEXT, detail_json TEXT);
        CREATE TABLE anomaly_checks (check_id TEXT PRIMARY KEY, kind TEXT, label_ro TEXT, explain_ro TEXT, state TEXT,
            count INTEGER, denominator INTEGER, reviewed INTEGER, real INTEGER, false_positive INTEGER)");
    $chk = $db->prepare("INSERT INTO anomaly_checks VALUES (?,?,?,?,?,?,?,?,?,?)");
    $chk->execute(['R14', 'unusual', 'Experiență 16 ani PUBLICATĂ', 'Explicație publicată.', $with_published ? 'published' : 'listed', 2, 100, 12, 11, 1]);
    $chk->execute(['R01', 'error_ours', 'Număr de posturi LISTAT', 'Explicație listată.', 'listed', 1, 100, 0, 0, 0]);
    $chk->execute(['R18', 'context', 'Context ASCUNS', 'Explicație ascunsă.', 'hidden', 1, 100, 0, 0, 0]);
    $sig = $db->prepare("INSERT INTO anomaly_signals VALUES (?,?,?,?,?)");
    $sig->execute([1001, 'R14', '17', '16', '{}']);
    $sig->execute([1002, 'R14', '99', '16', '{"verdict": "false_positive"}']);
    $sig->execute([1003, 'R01', '339395', '20', '{}']);
    $sig->execute([1001, 'R18', 'a@gmail.com', '', '{}']);
    $db = null;
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

run_suite('the public page shows published checks only', function () use ($srv_pub) {
    [$code, $h] = an_get($srv_pub['base'], '/statistici/anomalii/');
    assert_same(200, $code, 'status');
    assert_contains('Experiență 16 ani PUBLICATĂ', $h, 'published label');
    assert_contains('Explicație publicată.', $h, 'published explanation');
    assert_not_contains('LISTAT', $h, 'a listed check is not public');
    assert_not_contains('ASCUNS', $h, 'a hidden check is not public');
    assert_not_contains('339395', $h, 'a listed signal is not public');
    assert_not_contains('>99<', $h, 'a false_positive signal is not public');
    assert_contains('>17<', $h, 'a real signal is public');
    assert_not_contains('name="robots" content="noindex"', $h, 'the public page is indexed');
});

run_suite('the public page with no published check says the signals are in review', function () use ($srv_none) {
    [$code, $h] = an_get($srv_none['base'], '/statistici/anomalii/');
    assert_same(200, $code, 'status');
    assert_contains('Semnalele sunt în curs de verificare', $h, 'in review');
    assert_not_contains('LISTAT', $h, 'no listed check');
    assert_not_contains('<table', $h, 'no list');
});

run_suite('the admin page returns 403 without a login', function () use ($srv_pub) {
    [$code, $h] = an_get($srv_pub['base'], '/admin/anomalii/');
    assert_same(403, $code, 'status');
    assert_not_contains('LISTAT', $h, 'no data');
    // A forged Authorization header must not open the page (PHP_AUTH_USER is not trusted).
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'header' => "Authorization: Basic " . base64_encode('x:y') . "\r\n"]]);
    @file_get_contents($srv_pub['base'] . '/admin/anomalii/', false, $ctx);
    $code = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) $code = (int)$m[1];
    }
    assert_same(403, $code, 'forged header');
});

run_suite('the admin page shows every check with its state, behind a login', function () use ($srv_admin) {
    [$code, $h] = an_get($srv_admin['base'], '/admin/anomalii/');
    assert_same(200, $code, 'status');
    assert_contains('Experiență 16 ani PUBLICATĂ', $h, 'published');
    assert_contains('Număr de posturi LISTAT', $h, 'listed');
    assert_contains('Context ASCUNS', $h, 'hidden');
    assert_contains('Posibilă eroare în datele noastre', $h, 'ours label');
    assert_contains('în verificare', $h, 'listed state word');
    assert_contains('Gata de publicare', $h, 'ready: 12 read, 1 false positive');
    assert_contains('(fals pozitiv)', $h, 'a false_positive signal is visible to the admin');
    assert_contains('name="robots" content="noindex"', $h, 'noindex');
});

run_suite('robots.txt disallows /admin/ and /statistici/ links to the public page', function () use ($srv_pub) {
    [, $r] = an_get($srv_pub['base'], '/robots.txt');
    assert_contains('Disallow: /admin/', $r, 'robots');
    [, $s] = an_get($srv_pub['base'], '/statistici/');
    assert_contains('href="/statistici/anomalii/"', $s, 'link from stats');
    assert_not_contains('/admin/', $s, 'no link to the admin page');
});

stop_fixture_server($srv_none, $db_b);
stop_fixture_server($srv_admin, $db_a . '.admin');
stop_fixture_server($srv_pub, $db_a);
@unlink($ini_dir . '/login.php'); @unlink($ini_dir . '/login.ini'); @rmdir($ini_dir);
finish();
