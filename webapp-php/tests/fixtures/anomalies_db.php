<?php
/**
 * ANOMALY-PUBLIC fixture: the `anomaly_checks` and `anomaly_signals` tables of the export
 * (ops/anomaly-survey.py --write-table), on the shared fixture database.
 * Used by tests/anomalies_test.php and tests/browser/anomalies.spec.js
 * (php tests/fixtures/make.php <path> anomalies).
 *
 * One check in each of the five groups. R14 has one real signal, one `false_positive` signal
 * and eight more; R09a has a calendar quote; R25 has a caveat and a text quote.
 */

function fixture_seed_anomalies(PDO $db): void {
    $db->exec("CREATE TABLE anomaly_signals (posting_id INTEGER, check_id TEXT, value TEXT, peer_value TEXT, detail_json TEXT);
        CREATE TABLE anomaly_checks (check_id TEXT PRIMARY KEY, kind TEXT, label_ro TEXT, group_id TEXT, explain_ro TEXT,
            caveat_ro TEXT, count INTEGER, denominator INTEGER, reviewed INTEGER, real INTEGER, false_positive INTEGER)");
    $chk = $db->prepare("INSERT INTO anomaly_checks VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $chk->execute(['R01', 'error_ours', 'Eticheta GRUP-NOASTRE', 'erori_noastre', 'Explicație noastră.', '', 1, 100, 0, 0, 0]);
    $chk->execute(['R09a', 'error_posting', 'Eticheta GRUP-ANUNT', 'erori_anunt', 'Explicație anunț.', '', 1, 100, 0, 0, 0]);
    $chk->execute(['R14', 'unusual', 'Eticheta GRUP-NEOBISNUIT', 'neobisnuit', 'Explicație neobișnuit.', '', 10, 100, 12, 11, 1]);
    $chk->execute(['R16', 'unusual_employer', 'Eticheta GRUP-ANGAJATOR', 'angajator', 'Explicație angajator.', '', 0, 100, 0, 0, 0]);
    $chk->execute(['R25', 'error_posting', 'Eticheta CU-AVERTISMENT', 'erori_anunt', 'Explicație text.', 'AVERTISMENT-R25 semnal din text.', 1, 100, 0, 0, 0]);
    $chk->execute(['R18', 'context', 'Eticheta GRUP-CONTEXT', 'context', 'Explicație context.', 'Context, nu o anomalie: test.', 1, 100, 0, 0, 0]);
    $sig = $db->prepare("INSERT INTO anomaly_signals VALUES (?,?,?,?,?)");
    $sig->execute([1001, 'R14', '17', '16', '{}']);
    $sig->execute([1002, 'R14', '99', '16', '{"verdict": "false_positive"}']);
    for ($i = 0; $i < 8; $i++) $sig->execute([1001 + $i, 'R14', (string)(18 + $i), '16', '{}']);
    $sig->execute([1003, 'R01', '339395', '20', '{}']);
    $sig->execute([1001, 'R18', 'a@gmail.com', '', '{}']);
    $sig->execute([1002, 'R09a', '2026-01-01', '2026-02-01', json_encode(['source' => 'calendar', 'calendar' => [
        ['eveniment' => 'Proba scrisă', 'data' => '2026-01-01', 'ora' => '10:00']]], JSON_UNESCAPED_UNICODE)]);
    $sig->execute([1003, 'R25', 'sex masculin', '', json_encode(['source' => 'text',
        'excerpt' => "Condiții: candidați de sex masculin & <b>x</b>,\nconform legii.",
        'excerpt_start' => 23, 'excerpt_end' => 35, 'cut_start' => false, 'cut_end' => true], JSON_UNESCAPED_UNICODE)]);
}
