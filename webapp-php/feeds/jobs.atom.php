<?php
declare(strict_types=1);

header('Content-Type: application/atom+xml; charset=utf-8');

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

$stmt = db()->prepare("SELECT j.*, e.name AS employer_name_disp, jd.name AS judet_name_disp
    FROM job_postings j $join
    LEFT JOIN employers e ON e.id = j.employer_id
    LEFT JOIN judete jd ON jd.id = j.judet_id
    $where ORDER BY j.published_at DESC, j.id DESC LIMIT 50");
$stmt->execute($b);
$rows = $stmt->fetchAll();

$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
          . '://' . ($_SERVER['HTTP_HOST'] ?? 'posturi.gov.ro');

$updated = $rows ? substr($rows[0]['updated_at'] ?? $rows[0]['published_at'] ?? '', 0, 10) . 'T00:00:00Z' : date('Y-m-d') . 'T00:00:00Z';

echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>posturi.gov2.ro</title>
  <link href="<?= e($base_url . '/') ?>" rel="alternate" type="text/html"/>
  <link href="<?= e($base_url . '/posturi.atom' . current_qs()) ?>" rel="self" type="application/atom+xml"/>
  <id><?= e($base_url . '/') ?></id>
  <updated><?= e($updated) ?></updated>
  <subtitle>Anunțuri de angajare în sectorul public din România</subtitle>
<?php foreach ($rows as $r):
    $employer = $r['employer_name_disp'] ?? $r['employer_name'] ?? '';
    $title = trim($r['title'] . ($employer ? ' — ' . $employer : ''));
    $pub = $r['published_at'] ? substr($r['published_at'], 0, 10) . 'T00:00:00Z' : date('Y-m-d') . 'T00:00:00Z';
    $parts = [];
    if ($r['judet_name_disp'] ?? $r['judet_name']) $parts[] = 'Județ: ' . ($r['judet_name_disp'] ?? $r['judet_name']);
    if ($r['job_type']) $parts[] = 'Tip: ' . $r['job_type'];
    if ($r['categorie']) $parts[] = 'Categorie: ' . $r['categorie'];
    if ($r['expires_at']) $parts[] = 'Termen: ' . substr($r['expires_at'], 0, 10);
    $summary = e(implode(' | ', $parts));
?>
  <entry>
    <title><?= e($title) ?></title>
    <link href="<?= e($r['url']) ?>" rel="alternate"/>
    <id><?= e($r['url']) ?></id>
    <published><?= e($pub) ?></published>
    <updated><?= e($pub) ?></updated>
    <?php if ($employer): ?><author><name><?= e($employer) ?></name></author><?php endif; ?>
    <summary><?= $summary ?></summary>
  </entry>
<?php endforeach; ?>
</feed>
