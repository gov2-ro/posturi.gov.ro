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
    $db->exec(social_sql());   // ids 2025-2030: no administrație or financiar suite counts them
    add_meserii_fixture($db);  // ids 2101-2113 and the archive rows 9301-9308: the family `tehnic` with COR codes
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

/**
 * SQL for the theme "social" on the shared fixture (docs/briefs/2026-11-17-theme-social.md): the families `social` and `educație` together.
 * Postings 2025-2030 (ids that no other themed suite uses) and four archive rows. Today is 2026-10-03: the chart covers 2026-07-06 to 2026-09-27.
 *   2025 social    Asistent social   primarie          cluj    licenta  2026-07-08  domains asistenta_sociala, fonduri_europene
 *   2026 social    Asistent social   primarie          ilfov   licenta  2026-07-15  asistenta_sociala
 *   2027 educație  Consilier școlar  scoala            cluj    master   2026-07-15  (3 posts)  educatie_cercetare, asistenta_sociala
 *   2028 educație  Mediator școlar   primarie          ilfov   liceala  2026-08-05  educatie_cercetare
 *   2029 social    Psiholog          asistenta-sociala ilfov   master   2026-08-05  asistenta_sociala
 *   2030 tehnic    Infirmier         asistenta-sociala ilfov            2026-08-05  (the line "other": a social-assistance employer, no family of the theme)
 * Archive: 9201 social Asistent social (2026-07-08, 12 posts), 9202 educație Consilier școlar (2026-07-22), 9203 educație Profesor (2026-08-12,
 * in no group), 9204 tehnic (2026-07-08: not counted), 9205 social Asistent social with the id of the live 2025 (counted once).
 * So the theme has 5 live postings and in the 12 weeks, in posts: Asistent social 1 + 1 + 12 = 14 (9205 has the id of 2025: not counted), Consilier școlar 3 + 1 = 4, Mediator 1, Psiholog 1, no group 1 (Profesor); total 21.
 */
function social_sql(): string {
    $post = [
        // id => [family, occupation, employer_kind, county, study_level, published_at, domains]
        2025 => ['social', 'Asistent social', 'primarie', 'cluj', 'licenta', '2026-07-08', '["asistenta_sociala","fonduri_europene"]'],
        2026 => ['social', 'Asistent social', 'primarie', 'ilfov', 'licenta', '2026-07-15', '["asistenta_sociala"]'],
        2027 => ['educație', 'Consilier școlar', 'scoala', 'cluj', 'master', '2026-07-15', '["educatie_cercetare","asistenta_sociala"]'],
        2028 => ['educație', 'Mediator școlar', 'primarie', 'ilfov', 'liceala', '2026-08-05', '["educatie_cercetare"]'],
        2029 => ['social', 'Psiholog', 'asistenta-sociala', 'ilfov', 'master', '2026-08-05', '["asistenta_sociala"]'],
        2030 => ['tehnic', 'Infirmier', 'asistenta-sociala', 'ilfov', '', '2026-08-05', '[]'],
    ];
    $sql = '';
    foreach ($post as $id => [$fam, $occ, $kind, $county, $study, $date, $dom]) {
        $sql .= sprintf("UPDATE job_postings SET inf_profession_family='%s', occ_canonical='%s', employer_kind='%s', judet_slug='%s', judet_name='%s', published_at='%s 09:00:00', v3_policy_domains='%s' WHERE id=%d;\n",
            $fam, $occ, $kind, $county, ucfirst($county), $date, $dom, $id);
        if ($study !== '') $sql .= "UPDATE posting_roles SET study_level='$study' WHERE posting_id=$id;\n";
    }
    $sql .= "UPDATE job_postings SET nr_posturi=3 WHERE id=2027;\n";
    $ins = fn(int $id, string $date, string $occ, string $fam, ?int $n) =>
        "INSERT INTO archived_postings (id, url, title, employer_id, employer_name, judet_name, judet_slug, locality, published_at, expires_at, apply_deadline, cancelled, nr_posturi, occ_canonical, inf_profession_family, sal_min, sal_max, summary)
         VALUES ($id, 'https://x/$id', 't', 1, 'e', 'Cluj', 'cluj', '', '$date 09:00:00', NULL, NULL, 0, " . ($n ?? 'NULL') . ", '$occ', '$fam', NULL, NULL, '');\n";
    return $sql
        . $ins(9201, '2026-07-08', 'Asistent social', 'social', 12)
        . $ins(9202, '2026-07-22', 'Consilier școlar', 'educație', null)
        . $ins(9203, '2026-08-12', 'Profesor', 'educație', null)
        . $ins(9204, '2026-07-08', 'Referent', 'administrație', null)
        . $ins(9205, '2026-07-08', 'Asistent social', 'social', 7)
        . "UPDATE archived_postings SET id=2025 WHERE id=9205;\n";   // the id of a live posting: counted once, as the live one
}

/**
 * The theme "meserii" (docs/briefs/2026-11-17-theme-meserii.md): live postings 2101-2113 (copies of 2003 with other values, so they
 * need no other columns) and archive rows with a COR code (`occ_group`). Today is 2026-10-03: the chart covers 2026-07-06 to 2026-09-27.
 *   id   family       occ_group  occupation           employer  county  published   posts
 *   2101 tehnic       7231       Muncitor calificat   scoala    cluj    2026-07-08  1
 *   2102 tehnic       7233       Lăcătuș mecanic      spital    ilfov   2026-07-15  1
 *   2103 tehnic       7112       Muncitor calificat   primarie  cluj    2026-08-05  3
 *   2104 tehnic       5153       Îngrijitor           scoala    ilfov   2026-07-15  1
 *   2105 tehnic       5153       Îngrijitor           scoala    ilfov   2026-08-05  1
 *   2106 tehnic       5120       Bucătar              scoala    cluj    2026-08-12  1
 *   2107 tehnic       8322       Șofer                primarie  ilfov   2026-07-22  1
 *   2108 tehnic       8322       Șofer autosanitară   spital    cluj    2026-08-19  1
 *   2109 tehnic       9629       Paznic               scoala    ilfov   2026-07-08  1
 *   2110 tehnic       9629       Portar               spital    ilfov   2026-09-02  2
 *   2111 tehnic       2511       Programator          primarie  cluj    2026-07-08  1   (COR 2: not in the theme)
 *   2112 tehnic       (none)     Muncitor             primarie  cluj    2026-07-08  1   (no COR code: not in the theme)
 *   2113 cultură      8322      Șofer                primarie  ilfov   2026-07-08  1   (another family: not in the theme)
 * The shared fixture adds 1005 (tehnic, COR 9, status unknown, which counts as active): in the theme.
 * Archive: 9301 tehnic 7132 (4 posts, 8 Jul), 9302 tehnic 5130 (22 Jul), 9303 tehnic 8322 (2 posts, 12 Aug), 9304 tehnic 2511 (12 Aug: COR 2),
 * 9305 tehnic 9 (2 Sep), 9306 tehnic with no code (15 Jul), 9307 cultură 7 (15 Jul), 9308 tehnic 7 with the id of the live 2101 (counted once).
 */
function add_meserii_fixture(PDO $db): void {
    $rows = [
        // id => [family, occ_group, occupation, employer_kind, county, published, posts]
        2101 => ['tehnic', '7231', 'Muncitor calificat', 'scoala', 'cluj', '2026-07-08', 1],
        2102 => ['tehnic', '7233', 'Lăcătuș mecanic', 'spital', 'ilfov', '2026-07-15', 1],
        2103 => ['tehnic', '7112', 'Muncitor calificat', 'primarie', 'cluj', '2026-08-05', 3],
        2104 => ['tehnic', '5153', 'Îngrijitor', 'scoala', 'ilfov', '2026-07-15', 1],
        2105 => ['tehnic', '5153', 'Îngrijitor', 'scoala', 'ilfov', '2026-08-05', 1],
        2106 => ['tehnic', '5120', 'Bucătar', 'scoala', 'cluj', '2026-08-12', 1],
        2107 => ['tehnic', '8322', 'Șofer', 'primarie', 'ilfov', '2026-07-22', 1],
        2108 => ['tehnic', '8322', 'Șofer autosanitară', 'spital', 'cluj', '2026-08-19', 1],
        2109 => ['tehnic', '9629', 'Paznic', 'scoala', 'ilfov', '2026-07-08', 1],
        2110 => ['tehnic', '9629', 'Portar', 'spital', 'ilfov', '2026-09-02', 2],
        2111 => ['tehnic', '2511', 'Programator', 'primarie', 'cluj', '2026-07-08', 1],
        2112 => ['tehnic', '', 'Muncitor', 'primarie', 'cluj', '2026-07-08', 1],
        2113 => ['cultură', '8322', 'Șofer', 'primarie', 'ilfov', '2026-07-08', 1],
    ];
    $db->exec('CREATE TEMP TABLE m_job AS SELECT * FROM job_postings WHERE id = 2003');
    $db->exec('CREATE TEMP TABLE m_role AS SELECT * FROM posting_roles WHERE posting_id = 2003');
    $has_text = (bool)$db->query("SELECT 1 FROM sqlite_master WHERE name = 'posting_text'")->fetchColumn();
    if ($has_text) $db->exec('CREATE TEMP TABLE m_text AS SELECT * FROM posting_text WHERE posting_id = 2003');
    foreach ($rows as $id => [$fam, $og, $occ, $kind, $county, $date, $posts]) {
        $db->exec(sprintf("UPDATE m_job SET id=%d, url='https://x/%d', inf_profession_family=%s, occ_group=%s, occ_canonical=%s, employer_kind=%s, judet_slug=%s, judet_name=%s,
            published_at=%s, nr_posturi=%d", $id, $id, $db->quote($fam), $db->quote($og), $db->quote($occ), $db->quote($kind), $db->quote($county), $db->quote(ucfirst($county)),
            $db->quote("$date 09:00:00"), $posts));
        $db->exec('INSERT INTO job_postings SELECT * FROM m_job');
        $db->exec("UPDATE m_role SET posting_id=$id, study_level='" . ($id % 2 ? 'liceala' : 'scoala_generala') . "', skills='[]'");
        $db->exec('INSERT INTO posting_roles SELECT * FROM m_role');
        if ($has_text) { $db->exec("UPDATE m_text SET posting_id=$id"); $db->exec('INSERT INTO posting_text SELECT * FROM m_text'); }
    }
    $db->exec('DROP TABLE m_job'); $db->exec('DROP TABLE m_role');
    if ($has_text) $db->exec('DROP TABLE m_text');
    $ins = fn(int $id, string $date, string $occ, string $fam, string $og, ?int $n) =>
        "INSERT INTO archived_postings (id, url, title, employer_id, employer_name, judet_name, judet_slug, locality, published_at, expires_at, apply_deadline, cancelled, nr_posturi, occ_canonical, inf_profession_family, occ_group, sal_min, sal_max, summary)
         VALUES ($id, 'https://x/$id', 't', 1, 'e', 'Cluj', 'cluj', '', '$date 09:00:00', NULL, NULL, 0, " . ($n ?? 'NULL') . ", '$occ', '$fam', '$og', NULL, NULL, '');\n";
    $db->exec($ins(9301, '2026-07-08', 'Zugrav', 'tehnic', '7132', 4)
        . $ins(9302, '2026-07-22', 'Îngrijitor', 'tehnic', '5130', null)
        . $ins(9303, '2026-08-12', 'Șofer', 'tehnic', '8322', 2)
        . $ins(9304, '2026-08-12', 'Programator', 'tehnic', '2511', null)
        . $ins(9305, '2026-09-02', 'Muncitor necalificat', 'tehnic', '9', null)
        . $ins(9306, '2026-07-15', 'Muncitor', 'tehnic', '', null)
        . $ins(9307, '2026-07-15', 'Referent', 'cultură', '7', null)
        . $ins(9308, '2026-07-08', 'Muncitor calificat', 'tehnic', '7', 6)
        . "UPDATE archived_postings SET id=2101 WHERE id=9308;\n");
}
