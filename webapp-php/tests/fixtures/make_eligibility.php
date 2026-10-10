<?php
/**
 * CLI: build the UX-10 fixture (evidence fixture + postings 1040-1044) at the given path.
 *   php tests/fixtures/make_eligibility.php /tmp/posturi-eligibility.sqlite
 * Used by browser/eligibility.spec.js, which serves its own copy of the app.
 */
require_once __DIR__ . '/eligibility_db.php';

$path = $argv[1] ?? (sys_get_temp_dir() . '/posturi-eligibility.sqlite');
build_eligibility_db($path);
echo "$path\n";
