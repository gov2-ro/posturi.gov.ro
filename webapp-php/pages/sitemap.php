<?php
declare(strict_types=1);

/**
 * Single-file sitemap. The corpus is a few thousand URLs — well under the
 * 50,000 / 50 MB limit that would force a sitemap index.
 */
const SITEMAP_MAX_URLS = 45000;

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$origin = site_origin();

function sm_url(string $loc, ?string $lastmod = null, string $changefreq = 'weekly', string $priority = '0.5'): void {
    echo "  <url>\n";
    echo "    <loc>" . e($loc) . "</loc>\n";
    if ($lastmod) echo "    <lastmod>" . e(substr($lastmod, 0, 10)) . "</lastmod>\n";
    echo "    <changefreq>$changefreq</changefreq>\n";
    echo "    <priority>$priority</priority>\n";
    echo "  </url>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$last_update = db()->query("SELECT MAX(last_seen_at) FROM job_postings")->fetchColumn() ?: null;

sm_url($origin . '/',            $last_update, 'daily',   '1.0');
sm_url($origin . '/angajatori/', $last_update, 'weekly',  '0.6');
sm_url($origin . '/statistici/', $last_update, 'weekly',  '0.5');
sm_url($origin . '/despre/',     null,         'monthly', '0.3');

$budget = SITEMAP_MAX_URLS - 4;

// Job pages — freshest first, so a truncated sitemap keeps the useful half.
$rows = db()->query(
    "SELECT id, title, updated_at, last_seen_at, expires_at
     FROM job_postings
     ORDER BY published_at DESC, id DESC
     LIMIT " . (int)$budget
)->fetchAll();

foreach ($rows as $r) {
    $expired = $r['expires_at'] && $r['expires_at'] < date('Y-m-d');
    sm_url(
        $origin . job_url($r),
        $r['updated_at'] ?: $r['last_seen_at'],
        $expired ? 'yearly' : 'daily',
        $expired ? '0.2' : '0.8'
    );
}
$budget -= count($rows);

// Employer profiles
if ($budget > 0) {
    // Only employers that actually have a posting in this export — the
    // employers table carries the full history, so the rest would be thin pages.
    $emps = db()->query(
        "SELECT DISTINCT e.slug
         FROM employers e
         JOIN job_postings j ON j.employer_id = e.id
         WHERE e.slug != ''
         ORDER BY e.slug
         LIMIT " . (int)$budget
    )->fetchAll();
    foreach ($emps as $emp) {
        sm_url($origin . '/angajator/' . rawurlencode($emp['slug']) . '/', $last_update, 'weekly', '0.4');
    }
}

echo "</urlset>\n";
