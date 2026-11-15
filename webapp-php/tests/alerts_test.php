<?php
/**
 * UX-14 — email alerts on the shared host (ADR 0003, docs/briefs/2026-11-15-email-alerts.md).
 *
 * Lib functions run against a private test directory (a test key pair and
 * pepper, tests/fixtures/make_alerts.php). The pages run on the built-in
 * router, and bin/alerts.php runs as a command, both against that directory
 * and the fixture posturi.sqlite. No network, no email.
 *
 * Usage: php tests/alerts_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';
require_once __DIR__ . '/fixtures/make_alerts.php';

putenv('POSTURI_TODAY=2026-10-03');

$tmp = sys_get_temp_dir() . '/posturi-alerts-' . getmypid();
$dir = $tmp . '/main';
$made = make_alerts_dir($dir);
$pair = $made['pair'];
putenv('ALERTS_DIR=' . $dir);

require_once __DIR__ . '/../query.php';
require_once __DIR__ . '/../inc/alerts.php';

const KEY_CLUJ  = 'judet%5B%5D=cluj';
const KEY_ILFOV = 'judet%5B%5D=ilfov';
const MAIL_A = 'Ana.Pop@example.com';

function open_sealed(string $b64, $pair): string {
    return (string)sodium_crypto_box_seal_open(base64_decode($b64), $pair);
}

/** @return array<int,array> decrypted outbox rows of the lib database */
function outbox_plain(PDO $pdo, $pair): array {
    $out = [];
    foreach ($pdo->query("SELECT id, kind, payload_sealed FROM outbox ORDER BY id")->fetchAll() as $r) {
        $out[] = ['id' => (int)$r['id'], 'kind' => $r['kind']] + json_decode(open_sealed($r['payload_sealed'], $pair), true);
    }
    return $out;
}

function sub_in(string $key, string $email, array $more = []): array {
    return $more + ['cheie' => $key, 'email' => $email, 'nume' => 'Cluj', 'sort' => '', 'website' => ''];
}

run_suite('seal, pepper and tokens', function () use ($pair) {
    $plain = 'ana@example.com';
    $sealed = al_seal($plain);
    assert_same($plain, open_sealed($sealed, $pair), 'the VPS key opens the sealed text');
    assert_true($sealed !== al_seal($plain), 'each seal differs (random nonce)');
    assert_not_contains($plain, $sealed, 'the sealed text has no plain address');
    assert_same(al_email_index('ana@example.com'), al_email_index(al_normalize_email('  ANA@Example.com ')), 'the index ignores case and spaces');
    assert_true(al_email_index('ana@example.com') !== al_email_index('ion@example.com'), 'different addresses, different index');
    assert_same(64, strlen(al_email_index('ana@example.com')), 'index is a SHA-256 HMAC');
    assert_same(null, al_normalize_email('not-an-address'), 'a bad address is refused');
    assert_same(null, al_normalize_email('a@b.c, d@e.f'), 'two addresses are refused');
    $t = al_new_token();
    assert_true(al_token_ok($t), 'a new token has the link shape');
    assert_same(64, strlen(al_token_hash($t)), 'token hash is SHA-256');
    $src = file_get_contents(__DIR__ . '/../inc/alerts.php');
    assert_true(substr_count($src, 'hash_equals(') >= 2, 'token checks use hash_equals');
});

run_suite('search keys: the empty and the non-canonical key are refused', function () {
    assert_same(null, al_parse_key(''), 'empty key');
    assert_same(null, al_parse_key('page=2'), 'a key with only page is the empty search');
    assert_same(null, al_parse_key('judet[]=cluj'), 'a hand-written spelling is not canonical');
    assert_same(null, al_parse_key('a=1&' . str_repeat('x', ALERTS_KEY_MAX)), 'too long');
    assert_true(al_parse_key(KEY_CLUJ) !== null, 'a canonical key passes');
    assert_same('Căutare salvată', al_clean_label("  \n "), 'an empty label gets the default');
    assert_same(ALERTS_LABEL_MAX, mb_strlen(al_clean_label(str_repeat('ș', 500))), 'a long label is cut');
});

run_suite('subscribe stores a sealed pending row and one outbox row', function () use ($pair, $dir) {
    $pdo = al_db();
    assert_same('invalid-key', al_subscribe($pdo, sub_in('', MAIL_A), '1.1.1.1'), 'the empty key is refused');
    assert_same('invalid-email', al_subscribe($pdo, sub_in(KEY_CLUJ, 'nope'), '1.1.1.1'), 'a bad address is refused');
    assert_same(0, (int)$pdo->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn(), 'refusals store nothing');

    assert_same('sent', al_subscribe($pdo, sub_in(KEY_CLUJ, MAIL_A, ['sort' => 'deadline', 'nume' => 'Cluj, medici']), '1.1.1.1'), 'valid request');
    $row = $pdo->query("SELECT * FROM subscriptions")->fetch();
    assert_same('pending', $row['status'], 'the row is pending');
    assert_same('deadline', $row['sort'], 'sort is kept');
    assert_same('Cluj, medici', $row['label'], 'label is kept');
    assert_same(al_email_index('ana.pop@example.com'), $row['email_index'], 'index of the normalised address');
    assert_same('ana.pop@example.com', open_sealed($row['email_sealed'], $pair), 'the sealed address opens with the VPS key');
    $ob = outbox_plain($pdo, $pair);
    assert_same(1, count($ob), 'one outbox row');
    assert_same('confirm', $ob[0]['kind'], 'a confirm email');
    assert_same((int)$row['id'], $ob[0]['subscription_id'], 'the payload names the subscription');
    assert_same('ana.pop@example.com', $ob[0]['email'], 'the payload carries the address, sealed');
    assert_same(open_sealed($row['token_sealed'], $pair), $ob[0]['token'], 'token_sealed holds the link token');
    assert_same(al_token_hash($ob[0]['token']), $row['token_hash'], 'the host keeps only the hash');

    // No plain address on disk, in any file of the private directory.
    $disk = '';
    foreach (glob($dir . '/*') as $f) if (!str_ends_with($f, 'test-private.key')) $disk .= file_get_contents($f);
    assert_not_contains('ana.pop@example.com', $disk, 'no plain address in the private directory');
    assert_not_contains('Ana.Pop', $disk, 'no mixed-case address either');
});

run_suite('a honeypot hit looks the same and stores nothing', function () {
    $pdo = al_db();
    $before = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn();
    $ob = (int)$pdo->query("SELECT COUNT(*) FROM outbox")->fetchColumn();
    assert_same('sent', al_subscribe($pdo, sub_in(KEY_ILFOV, 'bot@example.com', ['website' => 'http://spam']), '2.2.2.2'), 'same answer');
    assert_same($before, (int)$pdo->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn(), 'no row');
    assert_same($ob, (int)$pdo->query("SELECT COUNT(*) FROM outbox")->fetchColumn(), 'no outbox row');
});

run_suite('a known address gets the same answer and no second row', function () use ($pair) {
    $pdo = al_db();
    $id = (int)$pdo->query("SELECT id FROM subscriptions")->fetchColumn();
    $old = $pdo->query("SELECT token_hash FROM subscriptions WHERE id = $id")->fetchColumn();
    // Pending, subscribed again: a new token and a new confirm email.
    assert_same('sent', al_subscribe($pdo, sub_in(KEY_CLUJ, MAIL_A), '1.1.1.1'), 'known pending address');
    assert_same(1, (int)$pdo->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn(), 'still one row');
    assert_true($pdo->query("SELECT token_hash FROM subscriptions WHERE id = $id")->fetchColumn() !== $old, 'the token is replaced');
    $ob = outbox_plain($pdo, $pair);
    assert_same('confirm', end($ob)['kind'], 'a confirm email again');
    assert_same(al_token_hash(end($ob)['token']), $pdo->query("SELECT token_hash FROM subscriptions WHERE id = $id")->fetchColumn(), 'the new token matches');
    // Confirmed, subscribed again: no new row, a manage email.
    al_confirm($pdo, al_auth($pdo, $id, end($ob)['token']));
    assert_same('sent', al_subscribe($pdo, sub_in(KEY_CLUJ, MAIL_A), '1.1.1.1'), 'known confirmed address');
    assert_same(1, (int)$pdo->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn(), 'still one row');
    $ob = outbox_plain($pdo, $pair);
    assert_same('manage', end($ob)['kind'], 'a manage email');
    assert_same('sent', al_subscribe($pdo, sub_in(KEY_ILFOV, 'new.person@example.com'), '1.1.1.1'), 'an unknown address');
});

run_suite('the 21st subscription of an address is refused', function () {
    $pdo = al_db();
    $mail = 'many@example.com';
    for ($i = 1; $i <= 20; $i++) {
        al_subscribe($pdo, sub_in('q=' . rawurlencode("medic$i"), $mail), "3.3.3.$i");
    }
    $idx = al_email_index($mail);
    $n = fn() => (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE email_index = '$idx'")->fetchColumn();
    assert_same(20, $n(), 'twenty rows stored');
    assert_same('sent', al_subscribe($pdo, sub_in('q=medic21', $mail), '3.3.3.99'), 'same answer for the 21st');
    assert_same(20, $n(), 'the 21st stores no row');
});

run_suite('the rate limit counts per client', function () use ($tmp) {
    $dir2 = $tmp . '/rate';
    make_alerts_dir($dir2, ['rate_per_hour' => 3]);
    putenv('ALERTS_DIR=' . $dir2);
    $pdo = al_db();
    $res = [];
    for ($i = 0; $i < 5; $i++) $res[] = al_subscribe($pdo, sub_in(KEY_CLUJ, "r$i@example.com"), '9.9.9.9');
    assert_same(['sent', 'sent', 'sent', 'rate', 'rate'], $res, 'three pass, the rest are limited');
    assert_same('sent', al_subscribe($pdo, sub_in(KEY_CLUJ, 'other@example.com'), '8.8.8.8'), 'another client is not limited');
    $stored = $pdo->query("SELECT ip_hash FROM rate")->fetchAll(PDO::FETCH_COLUMN);
    assert_not_contains('9.9.9.9', implode(',', $stored), 'the rate table holds a hash, not the address');
    // A new hour starts a new window; old rows go away.
    $GLOBALS['al_now'] = time() + 3 * 3600;
    assert_same('sent', al_subscribe($pdo, sub_in(KEY_CLUJ, 'r9@example.com'), '9.9.9.9'), 'the window moved on');
    assert_same(0, (int)$pdo->query("SELECT COUNT(*) FROM rate WHERE window_start < " . (time() + 3 * 3600 - ALERTS_RATE_KEEP))->fetchColumn(), 'old rate rows are deleted');
    unset($GLOBALS['al_now']);
    putenv('ALERTS_DIR=' . $GLOBALS['dir']);
});

run_suite('the outbox has a global ceiling per hour', function () use ($tmp) {
    $dir3 = $tmp . '/ceiling';
    make_alerts_dir($dir3, ['outbox_per_hour' => 2]);
    putenv('ALERTS_DIR=' . $dir3);
    $pdo = al_db();
    $res = [];
    for ($i = 0; $i < 4; $i++) $res[] = al_subscribe($pdo, sub_in(KEY_CLUJ, "c$i@example.com"), "7.7.7.$i");
    assert_same(['sent', 'sent', 'sent', 'sent'], $res, 'the answer does not change at the ceiling');
    assert_same(2, (int)$pdo->query("SELECT COUNT(*) FROM outbox")->fetchColumn(), 'two emails queued, no more');
    putenv('ALERTS_DIR=' . $GLOBALS['dir']);
});

run_suite('pending rows and login tokens expire', function () use ($pair) {
    $pdo = al_db();
    assert_same('sent', al_subscribe($pdo, sub_in(KEY_CLUJ, 'late@example.com'), '4.4.4.4'), 'subscribe');
    $idx = al_email_index('late@example.com');
    $row = $pdo->query("SELECT * FROM subscriptions WHERE email_index = '$idx'")->fetch();
    assert_true($row !== false, 'the pending row exists');

    // A login link: queued for an address with subscriptions, none for an unknown one.
    $ob0 = count(outbox_plain($pdo, $pair));
    assert_same('ok', al_login_request($pdo, 'nobody@example.com', '4.4.4.4'), 'unknown address: same answer');
    assert_same($ob0, count(outbox_plain($pdo, $pair)), 'unknown address: nothing queued');
    assert_same('ok', al_login_request($pdo, 'late@example.com', '4.4.4.4'), 'known address');
    $ob = outbox_plain($pdo, $pair);
    $m = end($ob);
    assert_same('manage', $m['kind'], 'a manage email');
    assert_same($idx, al_login_auth($pdo, $m['token']), 'the login token proves the address');
    assert_same(null, al_login_auth($pdo, str_repeat('a', 43)), 'a wrong token proves nothing');

    $GLOBALS['al_now'] = time() + 3600 + 60;
    assert_same(null, al_login_auth($pdo, $m['token']), 'the login token expires after 1 hour');
    $GLOBALS['al_now'] = time() + ALERTS_PENDING_TTL + 60;
    al_housekeeping($pdo);
    $left = $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE email_index = '$idx'")->fetchColumn();
    assert_same(0, (int)$left, 'a pending row older than 48 h is deleted');
    assert_same(0, (int)$pdo->query("SELECT COUNT(*) FROM login_tokens WHERE email_index = '$idx'")->fetchColumn(), 'the expired login token is deleted');
    unset($GLOBALS['al_now']);
});

// ---- Pages ----

/** @return array [status, body, headers] */
function http(string $base, string $method, string $path, array $form = []): array {
    $opts = ['ignore_errors' => true, 'follow_location' => 0, 'method' => $method];
    if ($method === 'POST') {
        $opts['header'] = "Content-Type: application/x-www-form-urlencoded\r\n";
        $opts['content'] = http_build_query($form);
    }
    $body = (string)@file_get_contents($base . $path, false, stream_context_create(['http' => $opts]));
    $status = 500;
    $headers = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
        if (preg_match('#^([^:]+):\s*(.*)$#', $h, $m)) $headers[strtolower($m[1])] = $m[2];
    }
    return [$status, $body, $headers];
}

$db_path = $tmp . '/posturi.sqlite';
build_fixture_db($db_path);
putenv('ALERTS_DIR=' . $dir);
$srv = start_fixture_server($db_path);
$base = $srv['base'];

run_suite('the pages: GET never changes a row, POST does, every answer is no-store', function () use ($base, $pair, $dir) {
    $mail = 'page.user@example.com';
    [$st, $body, $h] = http($base, 'GET', '/alerte/abonare/?cheie=' . rawurlencode(KEY_CLUJ) . '&nume=' . rawurlencode('Cluj <b>') . '&sort=deadline');
    assert_same(200, $st, 'the form opens');
    assert_contains('name="email"', $body, 'the form has the address field');
    assert_contains('name="website"', $body, 'the form has the honeypot');
    assert_contains('confidențialitate', $body, 'the consent text links to the privacy text');
    assert_not_contains('Cluj <b>', $body, 'the label is escaped');
    assert_contains('no-store', $h['cache-control'] ?? '', 'the form is no-store');
    [$st] = http($base, 'GET', '/alerte/abonare/?cheie=');
    assert_same(400, $st, 'the empty key is refused on the form too');

    [$st, $body, $h] = http($base, 'POST', '/alerte/abonare/', ['cheie' => KEY_CLUJ, 'email' => $mail, 'nume' => 'Cluj', 'sort' => '', 'website' => '']);
    assert_same(200, $st, 'subscribe answers');
    assert_contains('Verifică-ți emailul. Linkul ajunge în cel mult 5 minute.', $body, 'the answer text');
    assert_contains('no-store', $h['cache-control'] ?? '', 'the answer is no-store');
    assert_not_contains($mail, $body, 'the answer never shows the address');

    $pdo = new PDO('sqlite:' . $dir . '/subscriptions.sqlite');
    $ob = json_decode((string)shell_exec('ALERTS_DIR=' . escapeshellarg($dir) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/alerts_outbox.php')), true);
    $mine = array_values(array_filter($ob, fn($r) => $r['email'] === $mail));
    assert_same(1, count($mine), 'one outbox row for the address');
    $id = $mine[0]['subscription_id'];
    $tok = $mine[0]['token'];
    $status = fn() => $pdo->query("SELECT status FROM subscriptions WHERE id = $id")->fetchColumn();

    [$st, $body, $h] = http($base, 'GET', "/alerte/confirma/$id/$tok");
    assert_same(200, $st, 'the confirm link opens');
    assert_contains('Confirmă abonarea', $body, 'a button is shown');
    assert_contains('no-store', $h['cache-control'] ?? '', 'confirm page is no-store');
    assert_same('pending', $status(), 'GET does not confirm');
    [$st] = http($base, 'GET', "/alerte/confirma/$id/" . str_repeat('x', 43));
    assert_same(404, $st, 'a wrong token gets 404');
    [$st] = http($base, 'POST', "/alerte/confirma/$id/" . str_repeat('x', 43));
    assert_same(404, $st, 'a wrong token cannot confirm');
    assert_same('pending', $status(), 'still pending');
    [$st, $body] = http($base, 'POST', "/alerte/confirma/$id/$tok");
    assert_same(200, $st, 'POST confirms');
    assert_contains('Abonarea este confirmată', $body, 'confirmed text');
    assert_same('confirmed', $status(), 'the row is confirmed');

    // A second subscription of the same address, then the manage page lists both.
    http($base, 'POST', '/alerte/abonare/', ['cheie' => KEY_ILFOV, 'email' => $mail, 'nume' => 'Ilfov', 'sort' => '', 'website' => '']);
    $ob = json_decode((string)shell_exec('ALERTS_DIR=' . escapeshellarg($dir) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/alerts_outbox.php')), true);
    $mine2 = array_values(array_filter($ob, fn($r) => $r['email'] === $mail && $r['subscription_id'] !== $id && $r['kind'] === 'confirm'));
    $id2 = $mine2[0]['subscription_id'];

    [$st, $body, $h] = http($base, 'GET', "/alerte/abonari/$id/$tok");
    assert_same(200, $st, 'the manage page opens');
    assert_contains('data-subscription="' . $id2 . '"', $body, 'it lists the other subscription of the address');
    assert_contains('Șterge toate', $body, 'it has „Șterge toate”');
    assert_contains('no-store', $h['cache-control'] ?? '', 'manage is no-store');

    [$st, $body] = http($base, 'GET', "/alerte/sterge/$id/$tok");
    assert_same(200, $st, 'the delete link opens');
    assert_contains('Șterge abonarea', $body, 'it shows a button');
    assert_same(2, (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE email_index = (SELECT email_index FROM subscriptions WHERE id = $id)")->fetchColumn(), 'GET deletes nothing');
    [, $body] = http($base, 'POST', "/alerte/sterge/$id/$tok");
    assert_contains('Abonarea este ștearsă', $body, 'POST deletes');
    assert_same(false, $pdo->query("SELECT id FROM subscriptions WHERE id = $id")->fetchColumn(), 'the row is gone');
    assert_contains('/alerte/intra/', $body, 'the page keeps a link to the other subscriptions');
    [$st] = http($base, 'GET', "/alerte/abonari/$id/$tok");
    assert_same(404, $st, 'the deleted subscription link no longer works');

    // The one-hour link of „Abonamentele mele”: the same answer for known and unknown, then delete all.
    [$st1, $b1] = http($base, 'POST', '/alerte/', ['email' => $mail, 'website' => '']);
    [$st2, $b2] = http($base, 'POST', '/alerte/', ['email' => 'unknown.person@example.com', 'website' => '']);
    assert_same(200, $st1, 'known address: 200');
    assert_same($st1, $st2, 'unknown address: the same status');
    assert_contains('Dacă ai abonări, îți trimitem un link în cel mult 5 minute.', $b1, 'the answer text');
    assert_same($b1, $b2, 'the page body is identical for a known and an unknown address');
    $ob = json_decode((string)shell_exec('ALERTS_DIR=' . escapeshellarg($dir) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/alerts_outbox.php')), true);
    $login = array_values(array_filter($ob, fn($r) => $r['kind'] === 'manage' && $r['email'] === $mail));
    assert_same(1, count($login), 'one manage email, for the known address only');
    assert_same([], array_values(array_filter($ob, fn($r) => $r['email'] === 'unknown.person@example.com')), 'nothing queued for the unknown address');
    $lt = end($login)['token'];
    [$st, $body] = http($base, 'GET', "/alerte/intra/$lt");
    assert_same(200, $st, 'the login link opens');
    assert_contains('data-subscription="' . $id2 . '"', $body, 'it lists the subscription');
    assert_same(1, (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE id = $id2")->fetchColumn(), 'GET deletes nothing');
    [, $body] = http($base, 'POST', "/alerte/intra/$lt", ['actiune' => 'sterge-toate']);
    assert_contains('Toate abonările sunt șterse', $body, 'delete all');
    assert_same(0, (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE id = $id2")->fetchColumn(), 'the row is gone');
    assert_true($pdo->query("SELECT COUNT(*) FROM login_tokens WHERE used_at IS NOT NULL")->fetchColumn() >= 1, 'used_at is set by the first change');
});

run_suite('the alerts pages are noindex and carry no cached copy', function () use ($base) {
    [$st, $body, $h] = http($base, 'GET', '/alerte/');
    assert_same(200, $st, 'the page opens');
    assert_contains('noindex', $h['x-robots-tag'] ?? '', 'X-Robots-Tag');
    assert_contains('no-referrer', $h['referrer-policy'] ?? '', 'Referrer-Policy');
    assert_contains('no-store', $h['cache-control'] ?? '', 'no-store');
    assert_not_contains('s-maxage', $h['cache-control'] ?? '', 'no edge copy');
    [$st] = http($base, 'GET', '/alerte/necunoscut');
    assert_same(404, $st, 'an unknown alerts path is a 404');
    [$st, , $h] = http($base, 'PUT', '/alerte/');
    assert_same(405, $st, 'PUT is refused');
    [$st, $body, $h] = http($base, 'GET', '/');
    assert_contains('/alerte/', $body, 'the footer links to /alerte/');
});

run_suite('bin/ is not web-requestable', function () use ($base) {
    foreach (['/bin/alerts.php', '/bin/', '/bin/alerts.php?x=1'] as $p) {
        [$st, $body] = http($base, 'GET', $p);
        assert_true(in_array($st, [403, 404], true), "$p answers 403 or 404, got $st");
        assert_not_contains('"error"', $body, "$p does not run");
    }
    $ht = file_get_contents(__DIR__ . '/../.htaccess');
    assert_true(preg_match('/RedirectMatch 404 \^\/bin/', $ht) === 1, '.htaccess denies /bin/');
    assert_true(str_contains(file_get_contents(__DIR__ . '/../bin/alerts.php'), "PHP_SAPI !== 'cli'"), 'the script refuses a non-CLI SAPI');
});

stop_fixture_server($srv, $db_path);

// ---- Command line ----

/** Run bin/alerts.php with the given directory and database; returns [json, exit code]. */
function cli(string $dir, string $db, array $args, ?string $stdin = null): array {
    $cmd = 'ALERTS_DIR=' . escapeshellarg($dir) . ' POSTURI_DB=' . escapeshellarg($db) . ' POSTURI_TODAY=2026-10-03 '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/alerts.php') . ' '
        . implode(' ', array_map('escapeshellarg', $args));
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin ?? '');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return [json_decode($out, true), $code, $out];
}

run_suite('bin/alerts.php: pull, outbox-done, delete, digest-done', function () use ($tmp, $db_path) {
    $cdir = $tmp . '/cli';
    $c = make_alerts_dir($cdir);
    putenv('ALERTS_DIR=' . $cdir);
    $pdo = al_db();
    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $i => $m) {
        al_subscribe($pdo, sub_in(KEY_CLUJ, $m, ['nume' => "S$i"]), '5.5.5.5');
    }
    $ob = outbox_plain($pdo, $c['pair']);
    // Confirm two of the three.
    foreach (array_slice($ob, 0, 2) as $o) al_confirm($pdo, al_auth($pdo, $o['subscription_id'], $o['token']));

    [$j, $code, $raw] = cli($cdir, $db_path, ['pull']);
    assert_same(0, $code, 'pull exits 0');
    assert_same(2, count($j['subscriptions']), 'pull lists the confirmed subscriptions only');
    assert_same(3, count($j['outbox']), 'pull lists the outbox');
    assert_not_contains('@example.com', $raw, 'pull prints no plain address');
    $s = $j['subscriptions'][0];
    foreach (['id', 'email_sealed', 'token_sealed', 'search_key', 'sort', 'label', 'confirmed_at', 'last_digest_at'] as $f) {
        assert_true(array_key_exists($f, $s), "pull row has $f");
    }
    assert_not_contains('email_index', $raw, 'pull does not leak the index');
    assert_not_contains('token_hash', $raw, 'pull does not leak the token hash');
    assert_same('a@example.com', open_sealed($s['email_sealed'], $c['pair']), 'the VPS key opens the pulled address');

    [$l, $code, $lraw] = cli($cdir, $db_path, ['list']);
    assert_same(0, $code, 'list exits 0');
    assert_same(3, count($l['subscriptions']), 'list shows pending rows too');
    assert_same(['confirmed', 'confirmed', 'pending'], array_column($l['subscriptions'], 'status'), 'list carries the status');
    assert_same([1, 2, 3], array_column($l['subscriptions'], 'person'), 'three addresses, three people');
    assert_not_contains('@example.com', $lraw, 'list prints no plain address');
    foreach (['email_index', 'token_hash', 'token_sealed'] as $f) assert_not_contains($f, $lraw, "list does not leak $f");

    [$j, $code] = cli($cdir, $db_path, ['outbox-done', $j['outbox'][0]['id'] . ',' . $j['outbox'][1]['id']]);
    assert_same(2, $j['deleted'], 'outbox-done deletes the rows it names');
    assert_same(1, (int)$pdo->query("SELECT COUNT(*) FROM outbox")->fetchColumn(), 'one outbox row is left');

    [$j] = cli($cdir, $db_path, ['digest-done', (string)$s['id'], '2026-10-02T12:00:00+03:00']);
    assert_same(1, $j['updated'], 'digest-done updates one row');
    assert_same('2026-10-02T09:00:00Z', $pdo->query("SELECT last_digest_at FROM subscriptions WHERE id = {$s['id']}")->fetchColumn(), 'the time is stored in UTC');
    [$j, $code] = cli($cdir, $db_path, ['digest-done', (string)$s['id'], 'yesterday-ish']);
    assert_same(1, $code, 'a bad time fails');

    [$j] = cli($cdir, $db_path, ['delete', (string)$s['id']]);
    assert_same(1, $j['deleted'], 'delete removes the subscription');
    [$j, $code] = cli($cdir, $db_path, ['delete', '1;DROP']);
    assert_same(1, $code, 'a bad id fails');
    [$j, $code] = cli($cdir, $db_path, ['nope']);
    assert_same(1, $code, 'an unknown command fails');
    putenv('ALERTS_DIR=' . $GLOBALS['dir']);
});

run_suite('bin/alerts.php candidates: postings created after "since"', function () use ($tmp) {
    $cdir = $tmp . '/cand';
    $c = make_alerts_dir($cdir);
    putenv('ALERTS_DIR=' . $cdir);
    $db = $tmp . '/cand.sqlite';
    build_fixture_db($db);
    $w = new PDO('sqlite:' . $db);
    $w->exec("UPDATE job_postings SET created_at = '2026-10-02T09:00:00+03:00' WHERE id = 1001");   // Cluj, after since (06:00Z)
    $w->exec("UPDATE job_postings SET created_at = '2026-10-02 20:00:00' WHERE id = 1006");         // Cluj, after since (naive = UTC)
    $w->exec("UPDATE job_postings SET created_at = '2026-10-01T10:00:00+00:00' WHERE id = 1007");   // Cluj, before since
    $w->exec("UPDATE job_postings SET created_at = '2026-10-02 20:00:00' WHERE id = 1008");         // Ilfov, after since
    $pdo = al_db();
    al_subscribe($pdo, sub_in(KEY_CLUJ, 'cand@example.com'), '6.6.6.6');
    al_subscribe($pdo, sub_in(KEY_ILFOV, 'cand@example.com'), '6.6.6.6');
    foreach (outbox_plain($pdo, $c['pair']) as $o) al_confirm($pdo, al_auth($pdo, $o['subscription_id'], $o['token']));
    [$id1, $id2] = array_map('intval', $pdo->query("SELECT id FROM subscriptions ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

    $ask = json_encode([['id' => $id1, 'since' => '2026-10-02T05:30:00Z'], ['id' => $id2, 'since' => '2026-10-02T05:30:00Z'], ['id' => 9999, 'since' => '2026-10-02T05:30:00Z']]);
    [$j, $code, $raw] = cli($cdir, $db, ['candidates'], $ask);
    assert_same(0, $code, 'candidates exits 0');
    $by = array_column($j['candidates'], null, 'id');
    $ids = array_column($by[$id1]['items'], 'id');
    sort($ids);
    assert_same([1001, 1006], $ids, 'Cluj: the two postings created after since');
    assert_same(2, $by[$id1]['total'], 'the total counts them');
    assert_same([1008], array_column($by[$id2]['items'], 'id'), 'Ilfov: one posting, the filter is the list filter');
    assert_same('gone', $by[9999]['error'], 'an unknown id is reported, not an error exit');
    $it = $by[$id1]['items'][0];
    foreach (['id', 'title', 'employer', 'place', 'deadline', 'url'] as $f) assert_true(array_key_exists($f, $it), "item has $f");
    assert_true(str_starts_with($it['url'], 'https://posturi.example/job/'), 'the URL uses the configured site_url');
    assert_not_contains('@example.com', $raw, 'no address in the output');
    [$j] = cli($cdir, $db, ['candidates'], json_encode([['id' => $id1, 'since' => '2026-10-03T00:00:00Z']]));
    assert_same(0, $j['candidates'][0]['total'], 'nothing after a later since');
    [$j, $code] = cli($cdir, $db, ['candidates'], 'not json');
    assert_same(1, $code, 'bad stdin fails');
    @unlink($db);
    putenv('ALERTS_DIR=' . $GLOBALS['dir']);
});

run_suite('bin/alerts.php refuses to run without a config', function () use ($tmp) {
    $empty = $tmp . '/empty';
    mkdir($empty);
    [$j, $code] = cli($empty, $tmp . '/none.sqlite', ['pull']);
    assert_same(1, $code, 'exit 1');
    assert_true(isset($j['error']), 'JSON error');
});

// Tidy up.
foreach (glob($tmp . '/*', GLOB_ONLYDIR) ?: [] as $d) { array_map('unlink', glob($d . '/*') ?: []); @rmdir($d); }
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

finish();
