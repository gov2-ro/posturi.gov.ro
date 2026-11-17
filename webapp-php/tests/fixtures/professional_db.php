<?php
/**
 * Fixture of the themes "administratie" and "financiar" (docs/briefs/2026-11-17-themes-admin-financiar-chips.md): the theme
 * fixture plus professional_sql(). Used by tests/theme_test.php (UPDATEs on its own database) and, through
 * `php tests/fixtures/make.php <path> professional`, by tests/browser/theme.spec.js.
 */

require_once __DIR__ . '/theme_db.php';

function build_professional_fixture_db(string $path): void {
    build_theme_fixture_db($path);   // the same base as tests/theme_test.php: the health postings, and no other administrație or financiar one
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(professional_sql());
}

/**
 * SQL for the two professional themes on the shared fixture (ids 2003-2024 have no family, one county and the same occupation):
 *   administrație, 2003-2012 (10 postings): Referent 4, Secretar 2, Inspector, Șef serviciu, Administrator, Secretar șef;
 *     job_level: execuție 7 (2003-2009), conducere 3 (2010-2012); instituție: scoala 5 (2003-2007), primarie 3 (2008-2010), spital 2;
 *     nine policy domains (administratie_publica 4, educatie_cercetare 3, achizitii_publice 2, economie_finante 2, five of 1);
 *   financiar, 2013-2024 (12 postings): Economist 5, Contabil 3, Contabil-șef 2, Administrator financiar 2;
 *     job_level: execuție 10, conducere 2; instituție: scoala 5, spital 3, primarie 2, cultura 2;
 *     skills of the role: "FOREXEBUG" 1 and "Forexebug" 1 (one product), EDUSAL 3, SIIIR 1, "Excel" 4 (not offered by the theme).
 * 2003-2006 (administrație) and 2013-2014 (financiar) are in cluj, the others stay in ilfov. Published one a week from 13 July (ten weeks).
 */
function professional_sql(): string {
    $domains = [
        2003 => '["administratie_publica","educatie_cercetare"]', 2004 => '["administratie_publica","educatie_cercetare"]',
        2005 => '["administratie_publica","educatie_cercetare","achizitii_publice"]', 2006 => '["administratie_publica","economie_finante","achizitii_publice"]',
        2007 => '["economie_finante","energie"]', 2008 => '["mediu"]', 2009 => '["sanatate_publica"]', 2010 => '["transporturi"]', 2011 => '["turism"]', 2012 => '[]',
    ];
    $occ = [2003 => 'Referent', 2004 => 'Referent', 2005 => 'Referent', 2006 => 'Referent', 2007 => 'Secretar', 2008 => 'Secretar', 2009 => 'Inspector',
            2010 => 'Șef serviciu', 2011 => 'Administrator', 2012 => 'Secretar șef'];
    $inst = [2003 => 'scoala', 2004 => 'scoala', 2005 => 'scoala', 2006 => 'scoala', 2007 => 'scoala', 2008 => 'primarie', 2009 => 'primarie', 2010 => 'primarie',
             2011 => 'spital', 2012 => 'spital'];
    $sql = '';
    foreach ($occ as $id => $o) {
        $lvl = $id <= 2009 ? 'Funcții de execuție' : 'Funcții de conducere';
        $sql .= sprintf("UPDATE job_postings SET inf_profession_family='administrație', occ_canonical='%s', job_level='%s', employer_kind='%s', v3_policy_domains='%s'%s WHERE id=%d;\n",
            $o, $lvl, $inst[$id], $domains[$id], $id <= 2006 ? ", judet_slug='cluj', judet_name='Cluj'" : '', $id);
    }
    $focc = [2013 => 'Economist', 2014 => 'Economist', 2015 => 'Economist', 2016 => 'Economist', 2017 => 'Economist', 2018 => 'Contabil', 2019 => 'Contabil', 2020 => 'Contabil',
             2021 => 'Contabil-șef', 2022 => 'Contabil-șef', 2023 => 'Administrator financiar', 2024 => 'Administrator financiar'];
    $finst = [2013 => 'scoala', 2014 => 'scoala', 2015 => 'scoala', 2016 => 'scoala', 2017 => 'scoala', 2018 => 'spital', 2019 => 'spital', 2020 => 'spital',
              2021 => 'primarie', 2022 => 'primarie', 2023 => 'cultura', 2024 => 'cultura'];
    $skills = [2013 => '["FOREXEBUG","Excel"]', 2014 => '["Forexebug","Excel"]', 2015 => '["EDUSAL"]', 2016 => '["EDUSAL","Excel"]', 2017 => '["EDUSAL"]', 2018 => '["SIIIR","Excel"]'];
    foreach ($focc as $id => $o) {
        $lvl = $id >= 2023 ? 'Funcții de conducere' : 'Funcții de execuție';
        $sql .= sprintf("UPDATE job_postings SET inf_profession_family='financiar', occ_canonical='%s', job_level='%s', employer_kind='%s'%s WHERE id=%d;\n",
            $o, $lvl, $finst[$id], $id <= 2014 ? ", judet_slug='cluj', judet_name='Cluj'" : '', $id);
        if (isset($skills[$id])) $sql .= "UPDATE posting_roles SET skills='" . $skills[$id] . "' WHERE posting_id=$id AND ordinal=0;\n";
    }
    $sql .= "UPDATE posting_roles SET study_level='licenta' WHERE posting_id IN (2013, 2014, 2018, 2003, 2004);\n";
    // One posting a week from 13 July on (ten weeks), so that the chart has weeks with data.
    $sql .= "UPDATE job_postings SET published_at = date('2026-07-13', '+' || (((id - 2003) % 10) * 7) || ' days') || ' 09:00:00' WHERE id BETWEEN 2003 AND 2024;\n";
    return $sql;
}
