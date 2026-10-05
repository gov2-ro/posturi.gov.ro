<?php
declare(strict_types=1);

require_once __DIR__ . '/_feed.php';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="posturi.ics"');

$rows = feed_rows('j.apply_deadline ASC, j.id ASC', FEED_CAL_LIMIT, true);

/** One CRLF-terminated content line, folded at 75 octets. */
function ical_out(string $line): void {
    echo ical_fold($line);
}

$scope   = feed_employer($_GET);
$calname = $scope ? 'posturi.gov2.ro — ' . $scope['name'] : 'posturi.gov2.ro';

// `?title=` overrides the calendar name outright. Someone following several
// filtered feeds — one per județ, say — needs each to carry its own label in a
// client that shows X-WR-CALNAME and won't let you rename a subscription
// (Apple Calendar, Outlook). Strip control chars, cap at 80 chars; ical_text()
// still handles delimiters and ical_fold() the line folding on the way out.
if (($t = trim((string)($_GET['title'] ?? ''))) !== '') {
    $calname = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $t), 0, 80);
}

ical_out("BEGIN:VCALENDAR");
ical_out("VERSION:2.0");
ical_out("PRODID:-//posturi.gov2.ro//RO");
ical_out("X-WR-CALNAME:" . ical_text($calname));
ical_out("X-WR-CALDESC:" . ical_text('Termene de depunere — ' . $calname . ' (primele ' . FEED_CAL_LIMIT . ' cu dată disponibilă, în ordinea termenului)'));
ical_out("CALSCALE:GREGORIAN");

foreach ($rows as $r) {
    $title = feed_item_title($r);
    // The deadline, not the expiry: a reminder for a competition that closed
    // three weeks ago is worse than no reminder.
    $dl = posting_deadline($r);
    // All-day event: DTEND is EXCLUSIVE, so it must be the next day. The old
    // DTEND == DTSTART emitted a zero-day event that some clients hid or
    // showed as an instant. Europe/Bucharest has no midnight DST transition
    // (shifts happen at 03:00/04:00), so +1 day from midnight is 24h.
    $tz = new DateTimeZone('Europe/Bucharest');
    $start_day = new DateTimeImmutable($dl['date'], $tz);
    $end_day   = $start_day->modify('+1 day');
    $dtstart = $start_day->format('Ymd');
    $dtend   = $end_day->format('Ymd');
    $dtstamp = $r['published_at'] ? str_replace('-', '', substr($r['published_at'], 0, 10)) . 'T000000Z' : gmdate('Ymd') . 'T000000Z';
    $estimated = $dl['source'] === 'expirare';
    $parts = [];
    if ($estimated) {
        $parts[] = 'Termen estimat (data expirării anunțului): ' . $dl['date'];
    } else {
        $parts[] = 'Termen depunere: ' . $dl['date'];
    }
    $parts[] = 'Stare: ' . feed_status_label($r);
    if ($r['judet_name_disp'] ?? $r['judet_name']) $parts[] = 'Județ: ' . ($r['judet_name_disp'] ?? $r['judet_name']);
    if ($r['categorie']) $parts[] = 'Categorie: ' . $r['categorie'];
    if ($r['contact_phone']) $parts[] = 'Tel: ' . $r['contact_phone'];
    if ($r['contact_email']) $parts[] = 'Email: ' . $r['contact_email'];
    $parts[] = 'URL: ' . $r['url'];

    ical_out("BEGIN:VEVENT");
    ical_out("UID:posturi-gov2-ro-{$r['id']}@posturi.gov2.ro");
    // The qualification sits in the title too: calendar grids show only SUMMARY.
    ical_out("SUMMARY:" . ical_text(($estimated ? 'Termen estimat: ' : '') . $title));
    ical_out("DTSTART;VALUE=DATE:{$dtstart}");
    ical_out("DTEND;VALUE=DATE:{$dtend}");
    ical_out("DTSTAMP:{$dtstamp}");
    ical_out("DESCRIPTION:" . ical_text(implode("\n", $parts)));
    ical_out("URL:" . ical_uri($r['url']));
    ical_out("END:VEVENT");
}

ical_out("END:VCALENDAR");
