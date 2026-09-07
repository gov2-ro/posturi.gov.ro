<?php
/**
 * PDO singleton for posturi.sqlite.
 * The export script generates posturi.sqlite directly in this directory.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // POSTURI_DB lets a test or a preview point at another export without
        // touching the deployed file. Unset in production.
        $path = getenv('POSTURI_DB') ?: __DIR__ . '/posturi.sqlite';
        if (!file_exists($path)) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            die("Database not found. Run: python export-to-sqlite.py");
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA cache_size=-8000; PRAGMA temp_store=MEMORY;");
    }
    return $pdo;
}
