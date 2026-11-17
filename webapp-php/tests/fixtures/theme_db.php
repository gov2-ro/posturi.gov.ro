<?php
/**
 * Themed pages fixture (docs/briefs/2026-11-17-themed-landing-sanatate.md): the shared fixture plus health
 * postings in several counties, with specialties, occupations, study levels and employer sectors. The changes
 * are UPDATEs after the shared fixture is built, so no other suite's pinned counts move: a suite that
 * uses this database builds it itself.
 *
 * Health (family `sănătate`), active, by posting:
 *   1001 cluj     Asistent medical   sector sanatate   Cardiologie            licenta
 *   1002 bacau    Medic specialist   sector educatie   Neurologie             master   (a school doctor: in the theme, sector not health)
 *   1004 bacau    Asistent medical   unconfirmed       Cardiologie, Neurologie  liceala
 *   1006 cluj     Medic specialist   sector justitie   Cardiologie            master
 *   2001 ilfov    Farmacist         sector sanatate   no specialty           licenta
 *   2002 ilfov    Asistent medical  (no sector)       Radiologie             postliceala
 * Not health, sector sanatate (the "other postings of health employers" line): 1007 (cluj), 1008 (ilfov).
 * 1003 is closed and health (Asistent medical, Cardiologie): no count may include it. 1003 stays closed; 1005 stays "unknown" (counts as active) and is not health.
 *
 * The history of the statistics panel (archived_postings, dates of the months) is theme_statistics_sql().
 *
 * Usage: build_theme_fixture_db('/tmp/x.sqlite');  CLI: php tests/fixtures/make.php <path> theme
 */

require_once __DIR__ . '/build_db.php';

function build_theme_fixture_db(string $path): void {
    build_fixture_db($path);
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("
        UPDATE job_postings SET inf_profession_family='sănătate', occ_canonical='Asistent medical', v4_employer_sector='sanatate',
            med_specialty='[\"Cardiologie\"]' WHERE id=1001;
        UPDATE job_postings SET inf_profession_family='sănătate', occ_canonical='Medic specialist', v4_employer_sector='educatie',
            med_specialty='[\"Neurologie\"]' WHERE id=1002;
        UPDATE job_postings SET inf_profession_family='sănătate', occ_canonical='Asistent medical',
            med_specialty='[\"Cardiologie\",\"Neurologie\"]' WHERE id=1004;
        UPDATE job_postings SET inf_profession_family='sănătate', occ_canonical='Medic specialist', v4_employer_sector='justitie',
            med_specialty='[\"Cardiologie\"]' WHERE id=1006;
        UPDATE job_postings SET inf_profession_family='sănătate', occ_canonical='Asistent medical', med_specialty='[\"Cardiologie\"]' WHERE id=1003;
        UPDATE job_postings SET v4_employer_sector='sanatate' WHERE id IN (1007, 1008);
        UPDATE job_postings SET inf_profession_family='sănătate', occ_canonical='Farmacist', v4_employer_sector='sanatate' WHERE id=2001;
        UPDATE job_postings SET inf_profession_family='sănătate', occ_canonical='Asistent medical', med_specialty='[\"Radiologie\"]' WHERE id=2002;
        UPDATE posting_roles SET study_level='licenta' WHERE posting_id IN (1001, 2001);
        UPDATE posting_roles SET study_level='master' WHERE posting_id IN (1002, 1006);
        UPDATE posting_roles SET study_level='liceala' WHERE posting_id=1004;
        UPDATE posting_roles SET study_level='postliceala' WHERE posting_id=2002;
    ");
    $db->exec(theme_statistics_sql());
}

/**
 * The history of the statistics panel (today 2026-10-03; the chart covers the weeks of 2026-07-06 to 2026-09-27). Health
 * postings in job_postings: 1003 (closed) on 10 Aug, 1002/1004/1006 on 28-30 Sep (the current week: out), 2001/2002 on 15 Sep,
 * 1001 on 1 Oct. The archive adds: Asistent medical on 8 Jul (9101), Medic specialist on 12 Aug (9102) and 2 Sep (9106),
 * Kinetoterapeut and Psiholog on 14 and 15 Jul, Infirmier on 3 Aug; also a posting of another family (9103: not counted), an
 * archive row with the id of a live posting (9104 = 2001: counted once, as the live one) and a health posting before the
 * weeks (9105), and Medic rezident on 19 Aug (9110). The shared fixture has the health row 9001 (Asistent medical, 1 Aug). In the
 * 12 weeks, by group of the chart: Medic (Medic specialist) 2, Rezident (Medic rezident) 1, Asistent (Asistent medical) 4,
 * Îngrijitor (Infirmier) 1, and in no group: Farmacist, Kinetoterapeut, Psiholog (3). Counties: 9102 and 9109 bacau, 9106 and 9108 ilfov, the other archive rows cluj.
 */
function theme_statistics_sql(): string {
    $ins = fn(int $id, string $date, string $occ, string $fam) =>
        "INSERT INTO archived_postings (id, url, title, employer_id, employer_name, judet_name, judet_slug, locality, published_at, expires_at, apply_deadline, cancelled, nr_posturi, occ_canonical, inf_profession_family, sal_min, sal_max, summary)
         VALUES ($id, 'https://x/$id', 't', 1, 'e', 'Cluj', 'cluj', '', '$date 09:00:00', NULL, NULL, 0, NULL, '$occ', '$fam', NULL, NULL, '');\n";
    return "UPDATE job_postings SET published_at='2026-08-10 09:00:00' WHERE id=1003;\n"
        . $ins(9101, '2026-07-08', 'Asistent medical', 'sănătate')
        . $ins(9102, '2026-08-12', 'Medic specialist', 'sănătate')
        . $ins(9103, '2026-08-12', 'Referent', 'administrație')
        . $ins(9104, '2026-07-20', 'Farmacist', 'sănătate')
        . $ins(9105, '2026-06-20', 'Farmacist', 'sănătate')
        . $ins(9106, '2026-09-02', 'Medic specialist', 'sănătate')
        . $ins(9107, '2026-07-14', 'Kinetoterapeut', 'sănătate')
        . $ins(9108, '2026-07-15', 'Psiholog', 'sănătate')
        . $ins(9109, '2026-08-03', 'Infirmier', 'sănătate')
        . $ins(9110, '2026-08-19', 'Medic rezident', 'sănătate')
        . "UPDATE archived_postings SET id=2001 WHERE id=9104;\n"
        // Other counties, so that the sums over the counties are a real check (the others stay in cluj).
        . "UPDATE archived_postings SET judet_slug='bacau', judet_name='Bacău' WHERE id IN (9102, 9109);\n"
        . "UPDATE archived_postings SET judet_slug='ilfov', judet_name='Ilfov' WHERE id IN (9106, 9108);\n";
}

