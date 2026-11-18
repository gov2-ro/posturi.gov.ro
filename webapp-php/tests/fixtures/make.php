<?php
/**
 * CLI: build the deterministic fixture database at the given path.
 *   php tests/fixtures/make.php /tmp/posturi-fixture.sqlite
 *   php tests/fixtures/make.php /tmp/posturi-evidence.sqlite evidence   # role + evidence cases (UX-13)
 *   php tests/fixtures/make.php /tmp/posturi-theme.sqlite theme         # health postings for the themed pages
 *   php tests/fixtures/make.php /tmp/posturi-chips.sqlite chips         # 28 postings of one COR group, for the occupation chips (tests/browser/chips.spec.js)
 *   php tests/fixtures/make.php /tmp/posturi-professional.sqlite professional   # administrație and financiar postings (tests/browser/theme.spec.js)
 *   php tests/fixtures/make.php /tmp/posturi-salary.sqlite salary       # postings with no salary figure (tests/browser/salary.spec.js)
 *   php tests/fixtures/make.php /tmp/posturi-project.sqlite project     # three project-hired posts, 1001-1003 (tests/project_pay_test.php, browser/project_pay.spec.js)
 * Used by the request tests and by the Playwright global setup.
 */
require_once __DIR__ . '/build_db.php';
require_once __DIR__ . '/theme_db.php';
require_once __DIR__ . '/chips_db.php';
require_once __DIR__ . '/professional_db.php';

$path = $argv[1] ?? (sys_get_temp_dir() . '/posturi-fixture.sqlite');
$evidence = ($argv[2] ?? '') === 'evidence';
if (($argv[2] ?? '') === 'theme') build_theme_fixture_db($path);
elseif (($argv[2] ?? '') === 'chips') build_chips_fixture_db($path);
elseif (($argv[2] ?? '') === 'professional') build_professional_fixture_db($path);
else build_fixture_db($path, $evidence, $evidence);
if (($argv[2] ?? '') === 'salary') {
    // 12 postings with no figure (none, and a zero): the filter has an option for them and the note a count.
    $pdo = new PDO('sqlite:' . $path);
    $pdo->exec("UPDATE job_postings SET sal_min = NULL, sal_max = NULL, inf_salary_min = NULL, inf_salary_max = NULL WHERE id BETWEEN 2011 AND 2020");
    $pdo->exec("UPDATE job_postings SET sal_min = NULL, sal_max = NULL, inf_salary_min = 0, inf_salary_max = 0 WHERE id IN (2021, 2022)");
    // Three postings with a law estimate, for the estimator page (tests/browser/salary_estimator.spec.js).
    fixture_seed_law_estimates($pdo);
}
if (($argv[2] ?? '') === 'project') {
    $pdo = new PDO('sqlite:' . $path);
    fixture_seed_law_estimates($pdo);
    fixture_seed_project_posts($pdo);
}
echo "$path\n";
