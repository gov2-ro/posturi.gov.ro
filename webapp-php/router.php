<?php
/**
 * Router for the PHP built-in server only: `php -S localhost:8000 router.php`.
 * In production Apache serves static files directly (see .htaccess) and this
 * file is never loaded.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$file = __DIR__ . $path;
// Mirror the .htaccess deny so local testing matches production.
if (preg_match('/\.sqlite(-wal|-shm)?$/i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
if ($path !== '/' && is_file($file)) {
    return false; // let the built-in server serve it with the right MIME type
}
require __DIR__ . '/index.php';
