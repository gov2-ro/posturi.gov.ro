<?php
declare(strict_types=1);

/**
 * Skin contract validator. Dev-only — it lives in assets/, which deploy-php.sh
 * excludes from the shared host.
 *
 *     php webapp-php/assets/check-skins.php
 *
 * Catches the four ways a skin fails silently in a browser:
 *
 *   1. An unscoped rule. Every skin file loads on every page, so a selector
 *      missing its [data-skin="<id>"] applies to all skins at once. @font-face is
 *      the sanctioned exception — it declares a family rather than applying one.
 *   2. A token name that is not in the contract. A typo'd --c-inkk simply never
 *      matches anything; nothing warns.
 *   3. A colour written as hex. Tailwind composes these with <alpha-value>, so a
 *      hex value does not throw — it quietly breaks every `bg-x/70` on the page.
 *   4. Text/background pairs under WCAG AA. The defaults in app.css were already
 *      tuned for this (see the --c-ink-muted comment); a skin can undo it.
 *
 * The contract is read from the :root block in assets/app.css rather than
 * duplicated here, so adding a token there is all that is needed.
 */

const AA_BODY = 4.5;   // WCAG 1.4.3 normal text
const AA_UI   = 3.0;   // WCAG 1.4.11 non-text contrast (borders on controls)

$root  = dirname(__DIR__);
$base  = (string)file_get_contents("$root/assets/app.css");

if (!preg_match('/:root\s*\{(.*?)\n  \}/s', $base, $m)) {
    fwrite(STDERR, "could not find the :root block in assets/app.css\n");
    exit(2);
}
preg_match_all('/--([a-z0-9-]+)\s*:/', $m[1], $mm);
$known = array_flip($mm[1]);
$colourTokens = array_values(array_filter(array_keys($known), fn($t) => str_starts_with($t, 'c-')));

/** Pairs that must clear a contrast ratio: [foreground, background, minimum]. */
const CONTRAST_PAIRS = [
    ['c-ink',          'c-page',    AA_BODY],
    ['c-ink',          'c-surface', AA_BODY],
    ['c-ink-muted',    'c-page',    AA_BODY],
    ['c-ink-muted',    'c-surface', AA_BODY],
    ['c-ink-faint',    'c-page',    AA_BODY],
    ['c-ink-faint',    'c-surface', AA_BODY],
    ['c-gov',          'c-page',    AA_BODY],
    ['c-gov',          'c-surface', AA_BODY],
    ['c-on-gov',        'c-gov',     AA_BODY],
    ['c-on-bar',        'c-gov-bar', AA_BODY],
    ['c-on-bar-muted',  'c-gov-bar', AA_BODY],
    ['c-on-bar-accent', 'c-gov-bar', AA_BODY],
    ['c-line-strong',  'c-surface', AA_UI],
    ['c-info-ink',     'c-info-bg',    AA_BODY],
    ['c-neutral-ink',  'c-neutral-bg', AA_BODY],
    ['c-ok-ink',       'c-ok-bg',      AA_BODY],
    ['c-note-ink',     'c-note-bg',    AA_BODY],
    ['c-alert-ink',    'c-alert-bg',   AA_BODY],
];

function channels(string $v): ?array
{
    if (!preg_match('/^\s*(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})\s*$/', $v, $m)) return null;
    return [(int)$m[1], (int)$m[2], (int)$m[3]];
}

function luminance(array $rgb): float
{
    $f = array_map(function (int $c): float {
        $s = $c / 255;
        return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    }, $rgb);
    return 0.2126 * $f[0] + 0.7152 * $f[1] + 0.0722 * $f[2];
}

function ratio(array $a, array $b): float
{
    $la = luminance($a);
    $lb = luminance($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** @return array<string,string> token => raw value, for one scope's declarations. */
function declarations(string $css): array
{
    preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/', $css, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $d) $out[$d[1]] = trim($d[2]);
    return $out;
}

$baseValues = declarations($m[1]);
printf("contract: %d tokens (%d colour)\n", count($known), count($colourTokens));

/** One skin — or the base palette, passed as $id = null. */
function checkPalette(?string $id, array $values, array $known, array $colourTokens, array $baseValues): int
{
    $label = $id ?? 'hartie (:root in app.css)';
    echo "\n── $label\n";
    $fail = 0;

    foreach (array_keys($values) as $t) {
        if (!isset($known[$t])) { echo "  UNKNOWN TOKEN: --$t\n"; $fail++; }
    }

    foreach ($values as $t => $v) {
        if (!str_starts_with($t, 'c-')) continue;
        if (channels($v) === null) { echo "  NOT RGB CHANNELS: --$t: $v\n"; $fail++; }
    }

    // Tokens a skin leaves alone fall through to the base palette, so contrast
    // has to be measured against the merged result, not the skin's own block.
    $merged = $values + $baseValues;

    foreach (CONTRAST_PAIRS as [$fg, $bg, $min]) {
        $a = channels($merged[$fg] ?? '');
        $b = channels($merged[$bg] ?? '');
        if ($a === null || $b === null) continue;
        $r = ratio($a, $b);
        if ($r < $min) {
            printf("  CONTRAST %.2f:1 (needs %.1f) — --%s on --%s\n", $r, $min, $fg, $bg);
            $fail++;
        }
    }

    if ($id !== null) {
        $set     = array_intersect(array_keys($values), $colourTokens);
        $missing = array_diff($colourTokens, $set);
        printf("  %d/%d colour tokens overridden%s\n", count($set), count($colourTokens),
            $missing ? "\n  inherits from hartie: " . implode(', ', array_map(fn($x) => "--$x", $missing)) : '');
    }

    return $fail;
}

$fail = checkPalette(null, $baseValues, $known, $colourTokens, $baseValues);

foreach (glob("$root/static/skins/*.css") ?: [] as $path) {
    $id = basename($path, '.css');
    if ($id === '' || $id[0] === '_') continue;

    $css      = (string)file_get_contents($path);
    $stripped = (string)preg_replace('#/\*.*?\*/#s', '', $css);
    $stripped = (string)preg_replace('/@font-face\s*\{[^}]*\}/s', '', $stripped);

    $unscoped = 0;
    preg_match_all('/(^|\})\s*([^{}@]+)\{/', $stripped, $sels);
    foreach ($sels[2] as $sel) {
        $sel = trim($sel);
        if ($sel !== '' && !str_contains($sel, "[data-skin=\"$id\"]")) {
            echo "\n── $id\n  UNSCOPED: $sel\n";
            $unscoped++;
        }
    }

    $fail += $unscoped + checkPalette($id, declarations($stripped), $known, $colourTokens, $baseValues);
}

echo $fail ? "\n$fail problem(s)\n" : "\nall palettes valid\n";
exit($fail ? 1 : 0);
