<?php
/**
 * ADR 0005 — one look: the gov2 design system (DS), no skins.
 *
 * Checks, against the fixture database on the built-in router:
 *  - no page has a `data-skin` attribute, a skin `<link>` or the skin picker;
 *  - each page links `gov2.css` and `fonts.css` (with the DS version in the URL),
 *    and both files and each font file answer 200;
 *  - the files of the skin model are gone;
 *  - `static/app.css` has no rule outside a cascade layer;
 *  - the DS copy in `static/gov2/` is byte-identical to the tag (skipped when the
 *    DS clone is not on this machine).
 *
 * Usage: php tests/ds_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

putenv('POSTURI_TODAY=2026-10-03');

function ds_get(string $base, string $path): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0]]);
    $body = (string)@file_get_contents($base . $path, false, $ctx);
    $status = 500;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
    }
    return [$status, $body];
}

$db = sys_get_temp_dir() . '/posturi-ds-' . getmypid() . '.sqlite';
build_fixture_db($db);
$srv = start_fixture_server($db);
$base = $srv['base'];

$PAGES = ['/', '/posturi/', '/job/1001-asistent-medical-generalist/', '/angajatori/', '/statistici/', '/salvate/',
          '/alerte/', '/pot-aplica/', '/pentru-angajatori/anunt/', '/despre/', '/nu-exista/', '/?q%5B%5D=x'];
$VER = GOV2_DS_VERSION;

run_suite('no page has a skin attribute, a skin link or the picker', function () use ($base, $PAGES) {
    foreach ($PAGES as $path) {
        [, $html] = ds_get($base, $path);
        assert_true(strlen($html) > 200, "$path answers a page");
        assert_not_contains('data-skin', $html, "$path: data-skin");
        assert_not_contains('/static/skins/', $html, "$path: skin link");
        assert_not_contains('skin-picker', $html, "$path: picker");
        assert_not_contains('pg.skin', $html, "$path: stored skin");
        assert_not_contains('prefs.js', $html, "$path: prefs.js");
    }
});

run_suite('each page links gov2.css and fonts.css; both and the font files answer 200', function () use ($base, $PAGES, $VER) {
    foreach ($PAGES as $path) {
        [, $html] = ds_get($base, $path);
        assert_contains('<link rel="stylesheet" href="/static/gov2/gov2.css?v=' . $VER . '">', $html, "$path: gov2.css");
        assert_contains('<link rel="stylesheet" href="/static/gov2/fonts.css?v=' . $VER . '">', $html, "$path: fonts.css");
    }
    foreach (['/static/gov2/gov2.css', '/static/gov2/fonts.css', '/static/app.css'] as $css) {
        [$st, $body] = ds_get($base, $css . '?v=' . $VER);
        assert_same(200, $st, "$css answers 200");
        assert_true(strlen($body) > 1000, "$css is not empty");
    }
    [, $fonts] = ds_get($base, '/static/gov2/fonts.css');
    preg_match_all('#url\("(fonts/[^"]+)"\)#', $fonts, $m);
    assert_true(count($m[1]) >= 4, 'fonts.css names at least four font files');
    foreach ($m[1] as $f) {
        [$st, $bin] = ds_get($base, '/static/gov2/' . $f);
        assert_same(200, $st, "$f answers 200");
        assert_true(strlen($bin) > 5000, "$f is a font file");
    }
    // The old fonts are not served.
    foreach (['manrope-normal-latin', 'fraunces-normal-latin', 'dm-sans-normal-latin', 'dm-mono-400-latin'] as $old) {
        [$st] = ds_get($base, "/static/fonts/$old.woff2");
        assert_true($st !== 200, "$old is removed");
    }
});

run_suite('the files of the skin model are gone', function () {
    $root = dirname(__DIR__);
    foreach (['static/skins', 'inc/skins.php', 'assets/check-skins.php', 'static/prefs.js', 'static/fonts', 'assets/fonts.css'] as $gone) {
        assert_false(file_exists("$root/$gone"), "$gone is removed");
    }
});

run_suite('static/app.css has no rule outside a cascade layer', function () {
    $css = (string)file_get_contents(__DIR__ . '/../static/app.css');
    assert_true(str_starts_with($css, '@layer reset,gov2,utilities,app;') || str_starts_with($css, '@layer reset, gov2, utilities, app;'), 'the layer order is the first statement');
    $depth = 0; $top = [];
    $css = preg_replace('#/\*.*?\*/#s', '', $css);
    $start = 0;
    for ($i = 0, $n = strlen($css); $i < $n; $i++) {
        $ch = $css[$i];
        if ($ch === '{') { if ($depth === 0) $top[] = trim(substr($css, $start, $i - $start)); $depth++; }
        elseif ($ch === '}') { $depth--; if ($depth === 0) $start = $i + 1; }
        elseif ($ch === ';' && $depth === 0) $start = $i + 1;
    }
    assert_true(count($top) > 0, 'there are top-level blocks');
    foreach ($top as $sel) assert_true(str_starts_with($sel, '@layer '), 'top-level block is a layer: ' . substr($sel, 0, 60));
});

run_suite('the DS copy is byte-identical to the tag', function () {
    $ds = getenv('GOV2_DS') ?: (getenv('HOME') . '/devbox/gov2/gov2ro-design-system');
    $tag = 'v' . GOV2_DS_VERSION;
    $ok = is_dir($ds . '/.git') && trim((string)shell_exec('git -C ' . escapeshellarg($ds) . ' rev-parse --verify -q ' . escapeshellarg($tag . '^{commit}') . ' 2>/dev/null')) !== '';
    if (!$ok) { echo "SKIP  DS clone or tag $tag not found at $ds\n"; return; }
    $list = trim((string)shell_exec('git -C ' . escapeshellarg($ds) . ' ls-tree -r --name-only ' . escapeshellarg($tag) . ' -- gov2.css fonts.css fonts'));
    $files = array_filter(explode("\n", $list));
    assert_true(count($files) >= 8, 'the tag has gov2.css, fonts.css and the font folder: ' . count($files));
    $root = __DIR__ . '/../static/gov2/';
    foreach ($files as $f) {
        $want = (string)shell_exec('git -C ' . escapeshellarg($ds) . ' show ' . escapeshellarg("$tag:$f"));
        assert_true(is_file($root . $f), "$f is installed");
        assert_same(hash('sha256', $want), is_file($root . $f) ? hash_file('sha256', $root . $f) : '', "$f is byte-identical");
    }
    $installed = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fi) $installed[] = substr($fi->getPathname(), strlen($root));
    sort($installed); $expected = $files; sort($expected);
    assert_same(array_values($expected), $installed, 'no extra and no missing file');
});

stop_fixture_server($srv, $db);
finish();
