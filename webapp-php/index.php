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
    header('Cache-Control: no-store');
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

// ---- BETA-CACHE: one URL, one response ----
// Cloudflare and the browser key a cached copy on the URL alone, so a URL must
// never answer with a full page to one request and a results fragment to
// another. HTMX sends its requests with `org.htmx.cache-buster` (enabled in
// inc/header.php; PHP spells the key with underscores) and that parameter,
// not the HX-Request header, selects the fragment. htmx strips it from the
// address it pushes, and its history-restore request (HX-Request set, no
// parameter) gets the full page it expects. Removed here so no link, chip or
// feed URL built from $_GET carries it.
$hx_fragment = array_key_exists('org_htmx_cache-buster', $_GET);
unset($_GET['org_htmx_cache-buster']);

// Pages change only when a deploy lands, and deploy-php.sh purges the edge
// then, so Cloudflare may keep a page for an hour; the browser, which nobody
// purges, for five minutes. Decided at shutdown, once the status is known: an
// error (a 404, a 500 from an uncaught exception) is never stored, and a route
// that chose its own Cache-Control (versiuni.json, robots, sitemap) keeps it.
// The buffer holds the headers back until then. Expires is sent too because
// the host's mod_expires adds its own max-age=600 only when the response has
// no Expires.
$__timings = [];
$__timing_start = $__timing_last = microtime(true);
ob_start();
register_shutdown_function(function (): void {
    global $__timings, $__timing_start;
    if (headers_sent()) return;
    $parts = array_map(fn ($t) => sprintf('%s;dur=%.1f', $t[0], $t[1]), $__timings ?? []);
    $parts[] = sprintf('total;dur=%.1f', (microtime(true) - $__timing_start) * 1000);
    header('Server-Timing: ' . implode(', ', $parts));
    foreach (headers_list() as $h) {
        if (stripos($h, 'Cache-Control:') === 0) return;
    }
    $status = http_response_code();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($status, [200, 301], true) && in_array($method, ['GET', 'HEAD'], true)) {
        header('Cache-Control: public, max-age=300, s-maxage=3600');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 300) . ' GMT');
    } else {
        header('Cache-Control: no-store');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    }
});

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
