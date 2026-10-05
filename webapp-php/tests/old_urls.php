<?php
/**
 * UX-01A — representative pre-regroup filter URLs (query strings). The sidebar
 * regrouping must not change which postings any of them returns; the expected
 * ID sets in discovery_test.php were captured from the code at 6accc59 against
 * the fixture database.
 */
function old_url_cases(): array {
    return [
        'default'              => '',
        'judet scalar legacy'  => 'judet=cluj',
        'judet multi'          => 'judet%5B%5D=cluj&judet%5B%5D=bacau',
        'eqf exact'            => 'eqf%5B%5D=4',
        'eqf legacy scalar'    => 'eqf=6',
        'eqf multi OR'         => 'eqf%5B%5D=2&eqf%5B%5D=6',
        'studies legacy'       => 'studies_level%5B%5D=licenta',
        'mixed source+struct'  => 'level%5B%5D=executie&seniority%5B%5D=consilier&status=all',
        'family+eqf+status'    => 'family%5B%5D=administra%C8%9Bie&eqf%5B%5D=6&status=all',
        'skills any'           => 'skill%5B%5D=Excel&skill%5B%5D=Contabilitate&skill_mode=any',
        'skills all'           => 'skill%5B%5D=Excel&skill%5B%5D=Contabilitate&skill_mode=all',
        'employer scope'       => 'employer=spitalul-clinic-judetean-cluj',
        'date bounds'          => 'expires_after=2026-10-10&expires_before=2026-10-21&status=all',
        'status soon'          => 'status=soon',
        'status closed'        => 'status=closed',
        'status unknown'       => 'status=unknown',
        'status all legacy'    => 'status=all',
        'type+duration+sched'  => 'type%5B%5D=Temporar&duration%5B%5D=determinata&schedule%5B%5D=norma_partiala&status=all',
        'exp+remote+computer'  => 'exp_level%5B%5D=1-2&remote=1&computer=nesolicitat&status=all',
        'funding+sector'       => 'funding%5B%5D=buget_local&sector%5B%5D=sanatate',
        'anomaly+shift+schema' => 'anomaly%5B%5D=missing_contact&status=all',
        'shift'                => 'shift=1&status=all',
        'salary bucket'        => 'salary_bucket=3000-4000&status=all',
        'employer_cat'         => 'employer_cat%5B%5D=Func%C8%9Bie+public%C4%83&status=all',
        'free text'            => 'q=spital',
        'unknown value'        => 'duration%5B%5D=zz_nou&status=all',
    ];
}
