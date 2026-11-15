<?php
/**
 * CLI: read the outbox of the alerts test directory the way the VPS does.
 *   ALERTS_DIR=/tmp/x php tests/fixtures/alerts_outbox.php [--clear]
 *
 * Runs `bin/alerts.php pull`, opens each sealed payload with test-private.key
 * and prints JSON: [{id, kind, email, token, subscription_id}, ...]. With
 * --clear it also runs `outbox-done` for the rows it printed. Test code only.
 */
$dir = rtrim((string)getenv('ALERTS_DIR'), '/');
$secret = base64_decode((string)file_get_contents($dir . '/test-private.key'));
$public = sodium_crypto_box_publickey_from_secretkey($secret);
$pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, $public);
$bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/alerts.php');
$pull = json_decode((string)shell_exec($bin . ' pull'), true);
$out = [];
foreach ($pull['outbox'] ?? [] as $row) {
    $plain = sodium_crypto_box_seal_open(base64_decode($row['payload_sealed']), $pair);
    $out[] = ['id' => (int)$row['id'], 'kind' => $row['kind']] + (json_decode((string)$plain, true) ?: []);
}
if (in_array('--clear', $argv, true) && $out) {
    shell_exec($bin . ' outbox-done ' . escapeshellarg(implode(',', array_column($out, 'id'))));
}
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
