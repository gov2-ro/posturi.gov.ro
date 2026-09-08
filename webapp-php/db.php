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
        // No journal_mode=WAL. This copy is a read-only replica: WAL would make PHP
        // create -shm/-wal beside it (the document root may not be writable), and a
        // -wal surviving an rsync swap describes a database that is gone, which
        // SQLite reports as "database disk image is malformed". query_only makes the
        // read-only intent something SQLite enforces rather than something we assume.
        $pdo->exec("PRAGMA query_only=1; PRAGMA cache_size=-8000; PRAGMA temp_store=MEMORY;");
    }
    return $pdo;
}
