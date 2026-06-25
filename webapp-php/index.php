<?php
/**
 * Front controller — routes all requests to the correct page/feed handler.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// Load Parsedown if present
if (file_exists(__DIR__ . '/Parsedown.php')) {
    require_once __DIR__ . '/Parsedown.php';
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = '/' . trim($uri ?? '/', '/');

// Route feeds first (exact match, sets Content-Type before any output)
if ($uri === '/posturi.json') { require __DIR__ . '/feeds/jobs.json.php';  exit; }
if ($uri === '/posturi.atom') { require __DIR__ . '/feeds/jobs.atom.php';  exit; }
if ($uri === '/posturi.ics')  { require __DIR__ . '/feeds/jobs.ics.php';   exit; }

// Route pages
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/pages/list.php';
} elseif (preg_match('#^/job/(\d+)/?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/pages/detail.php';
} elseif ($uri === '/angajatori' || $uri === '/angajatori/') {
    require __DIR__ . '/pages/employers.php';
} elseif (preg_match('#^/angajator/([^/]+)/?$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/pages/employer.php';
} elseif ($uri === '/statistici' || $uri === '/statistici/') {
    require __DIR__ . '/pages/stats.php';
} elseif ($uri === '/despre' || $uri === '/despre/') {
    require __DIR__ . '/pages/about.php';
} else {
    http_response_code(404);
    $page_title = '404';
    require __DIR__ . '/inc/header.php';
    echo '<div class="max-w-screen-xl mx-auto px-4 py-16 text-center">';
    echo '<h1 class="font-display text-4xl italic text-ink-faint mb-4">404</h1>';
    echo '<p class="text-ink-muted">Pagina nu a fost găsită.</p>';
    echo '<a href="/" class="mt-4 inline-block text-gov hover:underline">← Înapoi acasă</a>';
    echo '</div>';
    require __DIR__ . '/inc/footer.php';
}
