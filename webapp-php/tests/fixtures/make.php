<?php
/**
 * CLI: build the deterministic fixture database at the given path.
 *   php tests/fixtures/make.php /tmp/posturi-fixture.sqlite
 *   php tests/fixtures/make.php /tmp/posturi-evidence.sqlite evidence   # role + evidence cases (UX-13)
 * Used by the request tests and by the Playwright global setup.
 */
require_once __DIR__ . '/build_db.php';

$path = $argv[1] ?? (sys_get_temp_dir() . '/posturi-fixture.sqlite');
$evidence = ($argv[2] ?? '') === 'evidence';
build_fixture_db($path, $evidence, $evidence);
echo "$path\n";
