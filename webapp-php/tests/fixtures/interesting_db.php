<?php
/**
 * UX-INTERESTING fixture: postings for the five "Posturi interesante" filter values, on the shared fixture
 * database, and (with $anomalies) the anomaly tables for the two phase B values.
 * Used by tests/interesting_test.php and tests/browser/interesting.spec.js
 * (php tests/fixtures/make.php <path> interesting).
 *
 * 60 filler postings (pay 6.000 to 6.590 lei gross, a licență, 3 years) set the pay quintiles, so that a pay of
 * 20.000 is level 5 and a pay of 100 is level 1 whatever else the fixture holds. Each case below has one
 * reason to be in or out of a value. The ids and the expected values are listed in INTERESTING_CASES.
 */

/** id => the `interes` values the posting is in (the contract of the test). */
const INTERESTING_CASES = [
    // value 1: salariu-cerinte-mici (level 5 and: no experience, or studies up to "medii")
    3001 => ['salariu-cerinte-mici'],                       // level 5, role with the flag "none required" and no years
    3002 => ['salariu-cerinte-mici'],                       // level 5, liceală, 5 years: the "studii medii" branch
    3003 => [],                                             // level 5, licență, 5 years: requirements not low
    3004 => [],                                             // no experience, but pay level 1
    3005 => [],                                             // level 5; two roles, liceală and licență, both 5 years: highest is licență
    3006 => [],                                             // level 5; the flag "none required" next to 5 years: not "no experience"
    // value 3: multe-posturi (5 or more vacancies of one role)
    3010 => ['multe-posturi'],                              // one role, nr_posturi 5
    3011 => [],                                             // one role, nr_posturi 4
    3012 => [],                                             // two roles of 4 (nr_posturi 8 is their sum)
    3013 => ['multe-posturi'],                              // two roles, 6 and 2
    3014 => ['multe-posturi'],                              // nr_posturi empty, v5_vacancies_total 7
    3015 => ['multe-posturi'], 3016 => ['multe-posturi'], 3017 => ['multe-posturi'],   // 6 each: with the three above, the value reaches the 5 postings of its home card
    // value 4: debutant-platit (an entry title and level 4 or 5)
    3020 => ['debutant-platit'],                            // "debutant", level 5
    3021 => ['debutant-platit'],                            // "stagiar"
    3022 => ['debutant-platit'],                            // "ÎNCEPĂTOR" in capitals
    3023 => [],                                             // "debutant", pay level 1
    3024 => [],                                             // level 5, no entry word
    3025 => ['debutant-platit'],                            // "debutant", level 4 (pay between two cuts)
    // phase B (with the anomaly tables)
    3030 => ['greu-de-ocupat'],                             // an E03 signal, peer 3031
    3032 => [],                                             // an E03 signal marked false_positive
    3033 => ['competente-rare'],                            // an E05 signal
];

function fixture_seed_interesting(PDO $db, bool $anomalies = true): void {
    $clone = static function (int $id, array $set, array $roles) use ($db): void {
        $db->exec('DROP TABLE IF EXISTS temp.t_clone');
        $db->exec('CREATE TEMP TABLE t_clone AS SELECT * FROM main.job_postings WHERE id = 1006');
        $set += ['id' => $id, 'url' => "https://posturi.gov.ro/anunt/fixture-$id", 'title' => "Post $id",
                 'sal_min' => null, 'sal_max' => null, 'inf_salary_min' => null, 'inf_salary_max' => null,
                 'pay_min' => 5000, 'pay_max' => null, 'nr_posturi' => null, 'v5_vacancies_total' => null,
                 'project_hire' => 0];
        $cols = array_keys($set);
        $st = $db->prepare('UPDATE temp.t_clone SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)));
        $st->execute(array_values($set));
        $db->exec('INSERT INTO main.job_postings SELECT * FROM temp.t_clone');
        $db->exec('DROP TABLE temp.t_clone');
        $db->exec("INSERT INTO posting_text (posting_id) VALUES ($id)");
        $ins = $db->prepare('INSERT INTO posting_roles (posting_id, ordinal, role_key, decomposed, title, vacancy_count, study_level,
            exp_years, exp_none_required, role_format) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, 1)');
        foreach (array_values($roles) as $i => $r) {
            $ins->execute([$id, $i + 1, "fixture:$id:" . ($i + 1), count($roles) > 1 ? 1 : 0,
                           $r['vc'] ?? null, $r['study'] ?? 'licenta', $r['exp'] ?? null, $r['none'] ?? null]);
        }
    };
    $lic3 = [['study' => 'licenta', 'exp' => 3.0]];   // the default role: not a low requirement

    for ($i = 0; $i < 60; $i++) {
        $clone(4000 + $i, ['pay_min' => 6000 + 10 * $i, 'title' => 'Referent filler ' . $i], $lic3);
    }
    $high = 20000;
    // value 1
    $clone(3001, ['pay_min' => $high, 'title' => 'Muncitor calificat'], [['study' => 'licenta', 'none' => 1]]);
    $clone(3002, ['pay_min' => $high, 'title' => 'Șofer'], [['study' => 'liceala', 'exp' => 5.0]]);
    $clone(3003, ['pay_min' => $high, 'title' => 'Expert'], [['study' => 'licenta', 'exp' => 5.0]]);
    $clone(3004, ['pay_min' => 100, 'title' => 'Paznic'], [['study' => 'liceala', 'exp' => 0.0, 'none' => 1]]);
    $clone(3005, ['pay_min' => $high, 'title' => 'Referent doua roluri'], [['study' => 'liceala', 'exp' => 5.0], ['study' => 'licenta', 'exp' => 5.0]]);
    $clone(3006, ['pay_min' => $high, 'title' => 'Inspector'], [['study' => 'licenta', 'exp' => 5.0, 'none' => 1]]);
    // value 3 (pay level 1, so that nothing else matches)
    $clone(3010, ['pay_min' => 100, 'title' => 'Îngrijitor', 'nr_posturi' => 5], [['study' => 'generala', 'exp' => 3.0]]);
    $clone(3011, ['pay_min' => 100, 'title' => 'Îngrijitor patru', 'nr_posturi' => 4], [['study' => 'generala', 'exp' => 3.0]]);
    $clone(3012, ['pay_min' => 100, 'title' => 'Doua roluri de patru', 'nr_posturi' => 8], [['study' => 'licenta', 'exp' => 3.0, 'vc' => 4], ['study' => 'licenta', 'exp' => 3.0, 'vc' => 4]]);
    $clone(3013, ['pay_min' => 100, 'title' => 'Doua roluri, sase si doua', 'nr_posturi' => 8], [['study' => 'licenta', 'exp' => 3.0, 'vc' => 6], ['study' => 'licenta', 'exp' => 3.0, 'vc' => 2]]);
    $clone(3014, ['pay_min' => 100, 'title' => 'Muncitor v5', 'v5_vacancies_total' => 7], [['study' => 'generala', 'exp' => 3.0]]);
    foreach ([3015, 3016, 3017] as $k) $clone($k, ['pay_min' => 100, 'title' => "Îngrijitor sase $k", 'nr_posturi' => 6], [['study' => 'generala', 'exp' => 3.0]]);
    // value 4
    $clone(3020, ['pay_min' => $high, 'title' => 'Referent debutant'], $lic3);
    $clone(3021, ['pay_min' => $high, 'title' => 'Stagiar contabil'], $lic3);
    $clone(3022, ['pay_min' => $high, 'title' => 'MUNCITOR ÎNCEPĂTOR'], $lic3);
    $clone(3023, ['pay_min' => 100, 'title' => 'Referent debutant slab platit'], $lic3);
    $clone(3024, ['pay_min' => $high, 'title' => 'Referent'], $lic3);
    $clone(3025, ['pay_min' => 6400, 'title' => 'Inspector debutant'], $lic3);
    // phase B
    $clone(3030, ['pay_min' => 100, 'title' => 'Bibliotecar republicat', 'published_at' => '2026-09-20 09:00:00'], $lic3);
    $clone(3031, ['pay_min' => 100, 'title' => 'Bibliotecar republicat', 'published_at' => '2026-08-15 09:00:00'], $lic3);
    $clone(3032, ['pay_min' => 100, 'title' => 'Referent semnal respins'], $lic3);
    $clone(3033, ['pay_min' => 100, 'title' => 'Referent cerinte rare'], $lic3);

    if (!$anomalies) return;
    $db->exec("CREATE TABLE anomaly_signals (posting_id INTEGER, check_id TEXT, value TEXT, peer_value TEXT, detail_json TEXT)");
    $sig = $db->prepare('INSERT INTO anomaly_signals VALUES (?,?,?,?,?)');
    $sig->execute([3030, 'E03', '36', '3031', '{"level": "employer"}']);
    $sig->execute([3032, 'E03', '20', '3031', '{"verdict": "false_positive"}']);
    $sig->execute([3033, 'E05', 'exp=10.0, skill=9', '22', '{}']);
}
