<?php
/**
 * The sprite of Health Icons (ADR 0007): one `<symbol id="i-health-<name>" viewBox="0 0 48 48">` for each
 * SVG file in outline/<category>/. The inner markup of each file is copied without change.
 *
 * Usage: php assets/icons/healthicons/build-sprite.php   (writes static/health-icons.svg)
 * tests/theme_test.php compares that file with health_sprite_source(), so the two cannot differ.
 */

function health_sprite_source(): string {
    $files = glob(__DIR__ . '/outline/*/*.svg') ?: [];
    sort($files);
    $out = '<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0">' . "\n";
    foreach ($files as $f) {
        $svg = (string)file_get_contents($f);
        if (!preg_match('#<svg[^>]*>(.*)</svg>#s', $svg, $m)) throw new RuntimeException("not an SVG: $f");
        $inner = trim(preg_replace('/\s*\n\s*/', '', $m[1]));
        $out .= '<symbol id="i-health-' . basename($f, '.svg') . '" viewBox="0 0 48 48">' . $inner . "</symbol>\n";
    }
    return $out . "</svg>\n";
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    file_put_contents(__DIR__ . '/../../../static/health-icons.svg', health_sprite_source());
    echo "written\n";
}
