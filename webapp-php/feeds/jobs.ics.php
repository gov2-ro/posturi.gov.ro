<?php
declare(strict_types=1);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="posturi.ics"');

$f = build_filters($_GET);
$w = $f['where'];
$b = $f['binds'];
$join = '';

if ($f['fts'] && ($q = trim($_GET['q'] ?? ''))) {
    $join = "JOIN job_postings_fts fts ON fts.rowid = j.id";
    $w[] = "job_postings_fts MATCH ?";
    $b[] = fts_query($q);
}
$w[] = "j.expires_at IS NOT NULL";
$where = 'WHERE ' . implode(' AND ', $w);

$stmt = db()->prepare("SELECT j.*, e.name AS employer_name_disp, jd.name AS judet_name_disp
    FROM job_postings j $join
    LEFT JOIN employers e ON e.id = j.employer_id
    LEFT JOIN judete jd ON jd.id = j.judet_id
    $where ORDER BY j.expires_at ASC LIMIT 200");
$stmt->execute($b);
$rows = $stmt->fetchAll();

function ical_escape(string $s): string {
    $s = str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $s);
    return wordwrap($s, 75, "\r\n ", true);
}

$scope   = feed_employer($_GET);
$calname = $scope ? 'posturi.gov2.ro — ' . $scope['name'] : 'posturi.gov2.ro';

// `?title=` overrides the calendar name outright. Someone following several
// filtered feeds — one per județ, say — needs each to carry its own label in a
// client that shows X-WR-CALNAME and won't let you rename a subscription
// (Apple Calendar, Outlook). Strip control chars, cap at 80 chars; ical_escape
// still handles delimiters and line folding on the way out.
if (($t = trim((string)($_GET['title'] ?? ''))) !== '') {
    $calname = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $t), 0, 80);
}

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//posturi.gov2.ro//RO\r\n";
echo "X-WR-CALNAME:" . ical_escape($calname) . "\r\n";
echo "X-WR-CALDESC:" . ical_escape('Termene de depunere — ' . $calname) . "\r\n";
echo "CALSCALE:GREGORIAN\r\n";

foreach ($rows as $r) {
    $employer = $r['employer_name_disp'] ?? $r['employer_name'] ?? '';
    $title = trim($r['title'] . ($employer ? ' — ' . $employer : ''));
    $dtstart = str_replace('-', '', substr($r['expires_at'], 0, 10));
    $dtend   = $dtstart;
    $dtstamp = $r['published_at'] ? str_replace('-', '', substr($r['published_at'], 0, 10)) . 'T000000Z' : gmdate('Ymd') . 'T000000Z';
    $parts = [];
    if ($r['judet_name_disp'] ?? $r['judet_name']) $parts[] = 'Județ: ' . ($r['judet_name_disp'] ?? $r['judet_name']);
    if ($r['categorie']) $parts[] = 'Categorie: ' . $r['categorie'];
    if ($r['contact_phone']) $parts[] = 'Tel: ' . $r['contact_phone'];
    if ($r['contact_email']) $parts[] = 'Email: ' . $r['contact_email'];
    $parts[] = 'URL: ' . $r['url'];

    echo "BEGIN:VEVENT\r\n";
    echo "UID:posturi-gov2-ro-{$r['id']}@posturi.gov2.ro\r\n";
    echo "SUMMARY:" . ical_escape($title) . "\r\n";
    echo "DTSTART;VALUE=DATE:{$dtstart}\r\n";
    echo "DTEND;VALUE=DATE:{$dtend}\r\n";
    echo "DTSTAMP:{$dtstamp}\r\n";
    echo "DESCRIPTION:" . ical_escape(implode("\n", $parts)) . "\r\n";
    echo "URL:" . ical_escape($r['url']) . "\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";
