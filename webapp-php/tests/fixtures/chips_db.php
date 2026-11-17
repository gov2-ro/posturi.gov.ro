<?php
/**
 * Occupation chips fixture (docs/briefs/2026-11-17-themes-admin-financiar-chips.md): the shared fixture plus 28 active
 * postings of the family `tehnic`, COR group 5 (`occ_group` 5xxx), so that `/?family[]=tehnic&cor[]=5` fills more than one page
 * (25). The occupations come from $dist (occupation => number of postings, 28 in all); the default is
 * Pază 12, Îngrijitor 8, Bucătar 6, Brancardier 1, Frizer 1: three occupations with 2 or more postings, the largest with 43 %.
 * The postings 2003-2030 are in ilfov by default; the first 25 of them are in cluj, the next one is in bacau, the other two stay in
 * ilfov: `judet[]=cluj` shows 25 postings (one page), `judet[]=cluj&judet[]=bacau` 26 (two pages).
 *
 * Usage: build_chips_fixture_db('/tmp/x.sqlite');  CLI: php tests/fixtures/make.php <path> chips
 */

require_once __DIR__ . '/build_db.php';

const CHIPS_DEFAULT = ['Pază' => 12, 'Îngrijitor' => 8, 'Bucătar' => 6, 'Brancardier' => 1, 'Frizer' => 1];

function build_chips_fixture_db(string $path, array $dist = CHIPS_DEFAULT): void {
    build_fixture_db($path);
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sql = '';
    $id = 2003;
    foreach ($dist as $occ => $n) {
        for ($i = 0; $i < $n; $i++, $id++) {
            $county = $id < 2028 ? "judet_slug='cluj', judet_name='Cluj'" : ($id === 2028 ? "judet_slug='bacau', judet_name='Bacău'" : "judet_slug='ilfov', judet_name='Ilfov'");
            $sql .= sprintf("UPDATE job_postings SET inf_profession_family='tehnic', occ_group='5120', occ_canonical=%s, %s WHERE id=%d;\n", $db->quote($occ), $county, $id);
        }
    }
    if ($id !== 2031) throw new InvalidArgumentException('the distribution must have 28 postings, it has ' . ($id - 2003));
    $db->exec($sql);
}
