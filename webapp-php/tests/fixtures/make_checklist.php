<?php
/**
 * CLI: build the UX-11 checklist fixture database.
 *   php tests/fixtures/make_checklist.php /tmp/posturi-checklist.sqlite a
 *   php tests/fixtures/make_checklist.php /tmp/posturi-checklist-b.sqlite b
 */
require_once __DIR__ . '/checklist.php';

$path = $argv[1] ?? (sys_get_temp_dir() . '/posturi-checklist.sqlite');
build_checklist_fixture($path, $argv[2] ?? 'a');
echo "$path\n";
