<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$f = build_filters($_GET);
$w = $f['where'];
$b = $f['binds'];
$join = '';

if ($f['fts'] && ($q = trim($_GET['q'] ?? ''))) {
    $join = "JOIN job_postings_fts fts ON fts.rowid = j.id";
    $w[] = "job_postings_fts MATCH ?";
    $b[] = fts_escape($q);
}

$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$total_stmt = db()->prepare("SELECT COUNT(*) FROM job_postings j $join $where");
$total_stmt->execute($b);
$total = (int)$total_stmt->fetchColumn();

$stmt = db()->prepare("SELECT j.*, e.name AS employer_name_disp, jd.name AS judet_name_disp
    FROM job_postings j $join
    LEFT JOIN employers e ON e.id = j.employer_id
    LEFT JOIN judete jd ON jd.id = j.judet_id
    $where ORDER BY j.published_at DESC, j.id DESC LIMIT 200");
$stmt->execute($b);
$rows = $stmt->fetchAll();

$results = [];
foreach ($rows as $r) {
    $inferred = json_decode($r['inferred'] ?? '{}', true) ?: [];
    $results[] = [
        'id'               => $r['id'],
        'title'            => $r['title'],
        'url'              => $r['url'],
        'employer'         => $r['employer_name_disp'] ?? $r['employer_name'],
        'judet'            => $r['judet_name_disp'] ?? $r['judet_name'],
        'published_at'     => $r['published_at'] ? substr($r['published_at'], 0, 10) : null,
        'expires_at'       => $r['expires_at'] ? substr($r['expires_at'], 0, 10) : null,
        'job_level'        => $r['job_level'],
        'job_type'         => $r['job_type'],
        'categorie'        => $r['categorie'],
        'employer_category'=> $r['employer_category'],
        'nr_posturi'       => $r['nr_posturi'],
        'contact_phone'    => $r['contact_phone'],
        'contact_email'    => $r['contact_email'],
        'profession_family'=> $inferred['profession_family'] ?? null,
        'seniority'        => $inferred['seniority'] ?? null,
        'anomaly_flags'    => $inferred['anomaly_flags'] ?? [],
    ];
}

echo json_encode(['count' => $total, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
