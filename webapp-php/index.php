<?php
/**
 * Front controller — routes all requests to the correct page/feed handler.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/query.php';

// Load Parsedown if present
if (file_exists(__DIR__ . '/Parsedown.php')) {
    require_once __DIR__ . '/Parsedown.php';
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = '/' . trim($uri ?? '/', '/');

// UX-09: read-only POST lookup. Routed before the query-string decoder and any
// shared HTML so every outcome, errors included, is machine-readable JSON; the
// query string is ignored entirely.
if ($uri === '/preferinte-posturi.json') { require __DIR__ . '/feeds/preferinte.json.php'; exit; }

// ---- Request validation: one decoder, one 400 path ----
// Every page, filter builder and feed reads the normalized $_GET that this
// produces; a malformed shape (?q[]=medic) never reaches trim() or SQL.
[$valid_params, $query_error] = validated_query($_GET);
$is_feed = in_array($uri, ['/posturi.json', '/posturi.atom', '/posturi.rss', '/posturi.ics'], true);

if ($query_error !== null) {
    http_response_code(400);
    if ($is_feed) {
        // Machine-readable, same shape as the feed errors anywhere else.
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $query_error], JSON_UNESCAPED_UNICODE);
    } else {
        // Standalone: renders without the database, which a bad request must
        // never be able to depend on.
        echo '<!doctype html><html lang="ro"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Cerere invalidă — posturi.gov2.ro</title></head>'
            . '<body style="font-family:system-ui,sans-serif;margin:2rem;color:#1e293b">'
            . '<h1 style="font-size:1.25rem">Cerere invalidă</h1>'
            . '<p>' . e($query_error) . '.</p>'
            . '<p><a href="/" style="color:#1d4ed8">← Înapoi acasă</a></p>'
            . '</body></html>';
    }
    exit;
}
$_GET = $valid_params;

// Route feeds first (exact match, sets Content-Type before any output)
if ($uri === '/posturi.json') { require __DIR__ . '/feeds/jobs.json.php';  exit; }
if ($uri === '/posturi.atom') { require __DIR__ . '/feeds/jobs.atom.php';  exit; }
if ($uri === '/posturi.rss')  { require __DIR__ . '/feeds/jobs.rss.php';   exit; }
if ($uri === '/posturi.ics')  { require __DIR__ . '/feeds/jobs.ics.php';   exit; }
// Version marker endpoint — the deploy verifies against it (FIX-06).
if ($uri === '/versiuni.json') { require __DIR__ . '/feeds/versiuni.json.php'; exit; }

// Crawler surface
if ($uri === '/robots.txt') { require __DIR__ . '/pages/robots.php';  exit; }
if ($uri === '/sitemap.xml') { require __DIR__ . '/pages/sitemap.php'; exit; }

// Route pages
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/pages/list.php';
} elseif (preg_match('#^/job/(\d+)(?:-[^/]*)?/?$#', $uri, $m)) {
    // `/job/1234/` and `/job/1234-anything/` both resolve; detail.php redirects
    // to the canonical slug form.
    $_GET['id'] = $m[1];
    $requested_path = $uri;
    require __DIR__ . '/pages/detail.php';
} elseif ($uri === '/angajatori' || $uri === '/angajatori/') {
    require __DIR__ . '/pages/employers.php';
} elseif (preg_match('#^/angajator/([^/]+)/?$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/pages/employer.php';
} elseif ($uri === '/statistici' || $uri === '/statistici/') {
    require __DIR__ . '/pages/stats.php';
} elseif ($uri === '/salvate' || $uri === '/salvate/') {
    require __DIR__ . '/pages/saved.php';
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
