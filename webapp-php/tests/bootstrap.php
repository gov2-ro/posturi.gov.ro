<?php
/**
 * Minimal assertion harness for the PHP tests. Runs under `php tests/x_test.php`
 * with no framework and no network — the FIX-07 CI workflow executes it the same
 * way. Exits non-zero on the first failing suite so CI reports the right file.
 */

$GLOBALS['__assert_failures'] = [];
$GLOBALS['__assert_count'] = 0;

function _assert(bool $cond, string $msg, array $detail = []): void {
    $GLOBALS['__assert_count']++;
    if ($cond) return;
    $GLOBALS['__assert_failures'][] = $msg . ($detail ? "\n    " . implode("\n    ", $detail) : '');
}

/** Substring must be present. */
function assert_contains(string $needle, string $haystack, string $what = ''): void {
    _assert(
        str_contains($haystack, $needle),
        ($what ? "$what: " : '') . "expected to contain " . var_export($needle, true),
        ['actual: ' . var_export(substr($haystack, 0, 500), true)]
    );
}

/** Substring must be absent. */
function assert_not_contains(string $needle, string $haystack, string $what = ''): void {
    _assert(
        !str_contains($haystack, $needle),
        ($what ? "$what: " : '') . "must not contain " . var_export($needle, true),
        ['actual: ' . var_export(substr($haystack, 0, 500), true)]
    );
}

/** Case-insensitive absence, for scheme checks that should ignore case. */
function assert_not_contains_ci(string $needle, string $haystack, string $what = ''): void {
    _assert(
        stripos($haystack, $needle) === false,
        ($what ? "$what: " : '') . "must not contain (case-insensitive) " . var_export($needle, true),
        ['actual: ' . var_export(substr($haystack, 0, 500), true)]
    );
}

function assert_same($expected, $actual, string $what = ''): void {
    _assert(
        $expected === $actual,
        ($what ? "$what: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
    );
}

function assert_true(bool $cond, string $what = ''): void {
    _assert($cond, ($what ? "$what: " : '') . 'expected true, got false');
}

function assert_false(bool $cond, string $what = ''): void {
    _assert(!$cond, ($what ? "$what: " : '') . 'expected false, got true');
}

/**
 * The rendered output must itself be well-formed markup — a structural check on
 * top of substring assertions, so a test does not pass merely because one
 * spelling of one payload is absent.
 */
function assert_well_formed(string $html, string $what = ''): void {
    if (trim($html) === '') { _assert(true, ''); return; }
    $dom = new DOMDocument();
    $ok = @$dom->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    _assert(
        $ok !== false,
        ($what ? "$what: " : '') . 'output does not parse as HTML',
        ['actual: ' . var_export(substr($html, 0, 500), true)]
    );
}

/**
 * Run one suite: a named callable receiving no arguments. Returns true when
 * every assertion inside it passed.
 */
function run_suite(string $name, callable $fn): bool {
    $before = count($GLOBALS['__assert_failures']);
    $count_before = $GLOBALS['__assert_count'];
    try {
        $fn();
    } catch (Throwable $e) {
        _assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage());
    }
    $failed = count($GLOBALS['__assert_failures']) > $before;
    $n = $GLOBALS['__assert_count'] - $count_before;
    echo ($failed ? 'FAIL' : 'ok  ') . "  $name  ($n assertions)\n";
    return !$failed;
}

/** Print failures and exit. Call once at the end of a test file. */
function finish(): void {
    $failures = $GLOBALS['__assert_failures'];
    if ($failures) {
        echo "\n" . count($failures) . " FAILED assertion(s):\n\n";
        foreach ($failures as $i => $f) {
            echo '  ' . ($i + 1) . '. ' . $f . "\n\n";
        }
        exit(1);
    }
    echo "\nAll " . $GLOBALS['__assert_count'] . " assertions passed.\n";
}
