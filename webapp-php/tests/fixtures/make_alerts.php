<?php
/**
 * CLI: make a fresh private directory for the email alerts tests (UX-14).
 *   php tests/fixtures/make_alerts.php /tmp/posturi-alerts-test
 *
 * Writes alerts-config.php (a test key pair and pepper) and test-private.key
 * (the VPS secret key, base64) into the directory. The directory is emptied
 * first. Used by tests/alerts_test.php and by the Playwright config.
 */
function make_alerts_dir(string $dir, array $extra = []): array {
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    } else {
        mkdir($dir, 0700, true);
    }
    $pair = sodium_crypto_box_keypair();
    $cfg = [
        'vps_public_key' => base64_encode(sodium_crypto_box_publickey($pair)),
        'pepper'         => base64_encode(random_bytes(32)),
        'site_url'       => 'https://posturi.example',
    ] + $extra;
    file_put_contents($dir . '/alerts-config.php', "<?php\nreturn " . var_export($cfg, true) . ";\n");
    file_put_contents($dir . '/test-private.key', base64_encode(sodium_crypto_box_secretkey($pair)));
    return ['config' => $cfg, 'pair' => $pair];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $dir = $argv[1] ?? (sys_get_temp_dir() . '/posturi-alerts-test');
    $extra = isset($argv[2]) ? (json_decode($argv[2], true) ?: []) : [];
    make_alerts_dir($dir, $extra);
    echo "$dir\n";
}
