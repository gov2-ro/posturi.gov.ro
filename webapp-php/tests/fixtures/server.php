<?php
/**
 * Serve the app on the built-in PHP router against the fixture database, with
 * a fixed clock. Request tests fetch real pages this way — the production
 * DOM, the production SQL, no network.
 */

function start_fixture_server(string $db_path, string $today = '2026-10-03'): array {
    $port = random_int(12000, 19999);
    $env = ['POSTURI_DB' => $db_path, 'POSTURI_TODAY' => $today] + getenv();
    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/../../router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, dirname(__DIR__, 2), $env
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('could not start the PHP fixture server');
    }
    $base = "http://127.0.0.1:$port";
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        if (@file_get_contents("$base/") !== false) { $ready = true; break; }
        usleep(100_000);
    }
    if (!$ready) {
        proc_terminate($proc);
        proc_close($proc);
        throw new RuntimeException('fixture server did not become ready');
    }
    return ['proc' => $proc, 'base' => $base];
}

function stop_fixture_server(array $s, string $db_path): void {
    if (isset($s['proc']) && is_resource($s['proc'])) {
        proc_terminate($s['proc']);
        proc_close($s['proc']);
    }
    @unlink($db_path);
    @unlink($db_path . '-shm');
    @unlink($db_path . '-wal');
}
