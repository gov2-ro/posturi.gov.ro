<?php
declare(strict_types=1);

/**
 * DATA-ORG-06: the verified link of an employer to org.gov2.ro.
 *
 * Returns `['cif' => ?string, 'url' => string]`, or null when the employer has no row, the export has no
 * `employer_org` table (an older export), or the row fails the checks below. The snapshot holds only verified
 * rows; the check repeats the one that matters for a public link (the host). A `P` org id is allowed:
 * the snapshot keeps it only when ciulei marks it `linked` (a reviewed CIF).
 */
function employer_org_link(int $employer_id): ?array {
    try {
        $st = db()->prepare("SELECT org_id, cif, org_url FROM employer_org WHERE employer_id = ?");
        $st->execute([$employer_id]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) return null;
    if (strncmp((string)$row['org_url'], 'https://org.gov2.ro/', 20) !== 0) return null;
    $cif = (string)($row['cif'] ?? '');
    return ['cif' => ctype_digit($cif) ? $cif : null, 'url' => (string)$row['org_url']];
}
