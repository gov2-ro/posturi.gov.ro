<?php
/**
 * Front controller — routes all requests to the correct page/feed handler.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/inc/themes.php';

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

// UX-SALVATE-FLUX: the Flux tab of /salvate/ asks for the open postings of the reader's sources in one GET.
// Routed here for the same reason: s[], e[] and id[] are not list filters, and the handler gives its own JSON errors.
if ($uri === '/flux.json') { require __DIR__ . '/feeds/flux.json.php'; exit; }

// UX-14: email alerts. Routed before the query-string decoder: the subscribe
// form takes the saved search as ?cheie=, which is not a list filter and must
// not meet the list validation. Each page sets its own no-store headers and
// reads POST data from $_POST. A GET here never changes data.
if ($uri === '/alerte' || str_starts_with($uri, '/alerte/')) {
    $alerts_get = $_GET;
    $_GET = [];
    require_once __DIR__ . '/inc/alerts.php';
    require_once __DIR__ . '/inc/alerts_view.php';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $tok = '([A-Za-z0-9_-]{20,64})';
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD, POST');
        header('Cache-Control: no-store');
    } elseif ($uri === '/alerte' || $uri === '/alerte/') {
        require __DIR__ . '/pages/alerts_home.php';
    } elseif ($uri === '/alerte/abonare') {
        require __DIR__ . '/pages/alerts_subscribe.php';
    } elseif (preg_match('#^/alerte/confirma/(\d{1,12})/' . $tok . '$#', $uri, $m)) {
        [$al_id, $al_token] = [$m[1], $m[2]];
        require __DIR__ . '/pages/alerts_confirm.php';
    } elseif (preg_match('#^/alerte/abonari/(\d{1,12})/' . $tok . '$#', $uri, $m)) {
        [$al_mode, $al_id, $al_token] = ['list', $m[1], $m[2]];
        require __DIR__ . '/pages/alerts_manage.php';
    } elseif (preg_match('#^/alerte/sterge/(\d{1,12})/' . $tok . '$#', $uri, $m)) {
        [$al_mode, $al_id, $al_token] = ['delete', $m[1], $m[2]];
        require __DIR__ . '/pages/alerts_manage.php';
    } elseif (preg_match('#^/alerte/intra/' . $tok . '$#', $uri, $m)) {
        [$al_mode, $al_id, $al_token] = ['login', 0, $m[1]];
        require __DIR__ . '/pages/alerts_manage.php';
    } else {
        http_response_code(404);
        header('Cache-Control: no-store');
        $page_title = '404';
        require __DIR__ . '/inc/header.php';
        echo '<div class="max-w-screen-xl mx-auto px-4 py-16 text-center"><h1 class="font-display text-4xl italic text-ink-faint mb-4">404</h1>'
           . '<p class="text-ink-muted">Pagina nu a fost găsită.</p></div>';
        require __DIR__ . '/inc/footer.php';
    }
    exit;
}

// ---- Request validation: one decoder, one 400 path ----
// Every page, filter builder and feed reads the normalized $_GET that this
// produces; a malformed shape (?q[]=medic) never reaches trim() or SQL.
// API-01: /api/v1/* validates its own parameters (strict: an unknown one is a 400, as JSON), so the raw query
// goes to inc/api.php. The decoder below is for the HTML pages and the old feeds, which ignore unknown parameters.
$is_api = $uri === '/api/v1' || str_starts_with($uri, '/api/v1/');
$api_get = $_GET;
[$valid_params, $query_error] = $is_api ? [[], null] : validated_query($_GET);
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
            . '<title>Cerere invalidă — posturi.gov2.ro</title>'
            . '<link rel="stylesheet" href="/static/gov2/gov2.css?v=' . GOV2_DS_VERSION . '">'
            . '<link rel="stylesheet" href="/static/gov2/fonts.css?v=' . GOV2_DS_VERSION . '">'
            . '</head><body><main class="g2-container" style="padding-block:var(--g2-space-6)">'
            . '<h1 class="g2-heading-section">Cerere invalidă</h1>'
            . '<p>' . e($query_error) . '.</p>'
            . '<p><a href="/">← Înapoi acasă</a></p></main>'
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
// that chose its own Cache-Control (versiuni.json, robots, sitemap, llms.txt) keeps it.
// The buffer holds the headers back until then. Expires is sent too because
// the host's mod_expires adds its own max-age=600 only when the response has
// no Expires.
/** The cache policy of a successful GET: the edge keeps it for an hour, the browser for five minutes. */
function default_cache_headers(): void {
    header('Cache-Control: public, max-age=300, s-maxage=3600');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 300) . ' GMT');
}
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
        default_cache_headers();
    } else {
        header('Cache-Control: no-store');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    }
});

// API-01: the public JSON API. Never returns; the error answers are JSON with no-store (inc/api.php).
if ($is_api) {
    require __DIR__ . '/inc/api.php';
    api_dispatch($uri, $api_get);
}

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
if ($uri === '/llms.txt')    { require __DIR__ . '/pages/llms.php';    exit; }   // a text file for language models (llmstxt.org)

// ADR 0004: `/` is the landing page; the list is `/?…` and, unfiltered, `/posturi/`.
// "Empty" means a parameter whose every value is blank (a form that was sent
// with nothing typed). A blank value never selects anything — `judet[]=` would
// match no posting — so a request that carries one goes to the same address
// without it, or to /posturi/ when nothing else is left.
$is_root = ($uri === '/' || $uri === '');
if ($is_root && !$hx_fragment && $_GET && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $has_blank = false;
    $non_blank = [];
    foreach ($_GET as $k => $v) {
        if (is_array($v)) {
            $vals = array_values(array_filter($v, fn($x) => trim((string)$x) !== ''));
            if (count($vals) !== count($v) || !$vals) $has_blank = true;
            if ($vals) $non_blank[$k] = $vals;
        } elseif (trim((string)$v) === '') {
            $has_blank = true;
        } else {
            $non_blank[$k] = $v;
        }
    }
    if ($has_blank) {
        http_response_code(301);
        $parts = [];
        foreach ($non_blank as $k => $v) {
            foreach ((array)$v as $one) $parts[] = rawurlencode((string)$k) . (is_array($v) ? '%5B%5D=' : '=') . rawurlencode((string)$one);
        }
        header('Location: ' . ($parts ? '/?' . implode('&', $parts) : '/posturi/'));
        exit;
    }
}

// Route pages
if ($is_root && !$hx_fragment && !$_GET) {
    require __DIR__ . '/pages/home.php';
} elseif ($is_root) {
    // An HTMX fragment that no filter, sort, status or page narrows is the
    // unfiltered list: the address bar shows /posturi/, not a bare `/`.
    if ($hx_fragment && search_key($_GET) === ''
        && !array_intersect_key(clean_query($_GET), ['sort' => 1, 'status' => 1, 'page' => 1])) {
        header('HX-Push-Url: /posturi/');
    }
    require __DIR__ . '/pages/list.php';
} elseif ($uri === '/posturi') {
    if ($_GET) {
        // Each filtered list has one URL: /?… (the query string is kept as sent).
        http_response_code(301);
        header('Location: /?' . ($_SERVER['QUERY_STRING'] ?? ''));
        exit;
    }
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
} elseif (preg_match('#^/statistici/anomalii(/.*)?$#', $uri, $m)) {
    // 2026-11-24: the anomaly page moved to /anomalii/. The old URL, and any path under it, redirects.
    http_response_code(301);
    $rest = rtrim($m[1] ?? '', '/');
    header('Location: /anomalii' . $rest . '/' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
} elseif ($uri === '/anomalii' || $uri === '/anomalii/') {
    require __DIR__ . '/pages/anomalies_public.php';
} elseif (preg_match('#^/anomalii/([A-Za-z0-9]+)/?$#', $uri, $m)) {
    $_GET['check'] = $m[1];
    require __DIR__ . '/pages/anomalies_public.php';
} elseif ($uri === '/admin/anomalii' || $uri === '/admin/anomalii/') {
    require __DIR__ . '/pages/anomalies_admin.php';
} elseif ($uri === '/salvate' || $uri === '/salvate/') {
    require __DIR__ . '/pages/saved.php';
} elseif ($uri === '/pot-aplica' || $uri === '/pot-aplica/') {
    require __DIR__ . '/pages/eligibility_tool.php';
} elseif ($uri === '/estimator-salariu' || $uri === '/estimator-salariu/') {
    require __DIR__ . '/pages/salary_estimator.php';
} elseif ($uri === '/salarii-declarate' || $uri === '/salarii-declarate/') {
    require __DIR__ . '/pages/declared_index.php';
} elseif (preg_match('#^/salarii-declarate/([^/]+)/?$#', $uri, $m)) {
    $_GET['slug'] = rawurldecode($m[1]);
    require __DIR__ . '/pages/declared_institution.php';
} elseif ($uri === '/pentru-angajatori/anunt' || $uri === '/pentru-angajatori/anunt/') {
    require __DIR__ . '/pages/employer_builder.php';
} elseif ($uri === '/despre' || $uri === '/despre/') {
    require __DIR__ . '/pages/about.php';
} elseif ($uri === '/api') {
    require __DIR__ . '/pages/api_docs.php';
} elseif (($theme = theme_for_uri($uri)) !== null) {
    // A themed landing page (inc/themes.php). `/{slug}/?…` is a filtered list: it has one URL, `/?…` (ADR 0004).
    if ($_GET) {
        http_response_code(301);
        header('Location: ' . theme_redirect_url($theme, $_GET));
        exit;
    }
    require __DIR__ . '/pages/theme.php';
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
