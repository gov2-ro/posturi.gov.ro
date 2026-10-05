<?php
/**
 * UX-04-FEEDS — RSS 2.0, Atom and iCal subscriptions.
 *
 * Fixture-served checks: feed documents parse as XML/iCalendar, identities and
 * links, hostile text, shared selection across formats, the 50/200 caps (rows
 * beyond the standing fixture are added to this test's own database copy), the
 * 400 path on every feed route, empty filtered feeds, RFC 5545 escaping/folding
 * at 75 octets without splitting UTF-8, URI fidelity, and the subscription
 * block in the list markup. Browser behaviour is in browser/feeds.spec.js.
 *
 * Usage: php tests/feeds_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';
require_once __DIR__ . '/../feeds/_feed.php';

putenv('POSTURI_TODAY=2026-10-03');

$db_path = sys_get_temp_dir() . '/posturi-feeds-' . getmypid() . '.sqlite';
build_fixture_db($db_path);
// Two databases: the standing fixture (selection-agreement checks need exact
// sets) and a copy with the extra rows below (hostile text, caps).
$db_path2 = $db_path . '.big';
copy($db_path, $db_path2);

// ---- Rows only this suite needs, added to its own copy of the fixture ----
$HOSTILE_TITLE = "<script>alert(1)</script> & \"Ț\" \x01 </description>";
$LONG_TITLE = str_repeat('Referent superior pentru coordonarea activității administrative și juridice, ', 3) . 'șțăâî';
$COMMA_URL = 'https://posturi.gov.ro/anunt/test,virgula;punct?x=1,2&y=%C8%9B&z=ș';
$pdo = new PDO('sqlite:' . $db_path2);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ins = $pdo->prepare("INSERT INTO job_postings
    (id, url, title, employer_id, employer_name, judet_id, judet_name, judet_slug, locality,
     published_at, expires_at, apply_deadline, deadline_source, application_status, updated_at, job_type, categorie)
    VALUES (?, ?, ?, 3, 'Administrația de Salubrizare Tunari', 3, 'Ilfov', 'ilfov', 'Tunari', ?, ?, ?, ?, ?, ?, 'Permanent', 'Test')");
// Hostile text and a long Romanian title with an awkward URL (all dated, status open).
$ins->execute([3901, 'https://posturi.gov.ro/anunt/hostil?a=1&b=<2>', $HOSTILE_TITLE,
    '2026-10-02 12:00:00', '2026-12-01 23:59:59', '2026-11-20', 'anunt', 'confirmed_open', '2026-10-02 12:00:00']);
$ins->execute([3902, $COMMA_URL, $LONG_TITLE,
    '2026-10-02 11:00:00', '2026-12-02 23:59:59', '2026-11-21', 'expirare', 'unconfirmed', '2026-10-02 11:00:00']);
// 60 newest rows (RSS/Atom cap) and 210 dated rows later than every existing deadline (iCal cap).
for ($i = 1; $i <= 60; $i++) {
    $ins->execute([3000 + $i, "https://posturi.gov.ro/anunt/cap-$i", "Cap noutate $i",
        sprintf('2026-10-02 10:%02d:00', $i - 1 < 60 ? $i - 1 : 59), '2027-03-01 23:59:59', null, '', 'unknown', '2026-10-02 10:00:00']);
}
for ($i = 1; $i <= 210; $i++) {
    $ins->execute([4000 + $i, "https://posturi.gov.ro/anunt/cal-$i", "Cap calendar $i",
        '2026-09-10 09:00:00', '2027-06-01 23:59:59',
        (new DateTimeImmutable('2026-12-01'))->modify('+' . $i . ' days')->format('Y-m-d'), 'anunt', 'confirmed_open', '2026-09-10 09:00:00']);
}
unset($pdo, $ins);

$srv = start_fixture_server($db_path);
$base = $srv['base'];
$srv2 = start_fixture_server($db_path2);
$big = $srv2['base'];

function fetch(string $base, string $path): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0]]);
    $body = (string)@file_get_contents($base . $path, false, $ctx);
    $status = 500;
    $headers = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
        if (preg_match('#^([^:]+):\s*(.*)$#', $h, $m)) $headers[strtolower($m[1])] = $m[2];
    }
    return [$status, $body, $headers];
}

function xml_doc(string $xml): ?DOMDocument {
    $d = new DOMDocument();
    return @$d->loadXML($xml, LIBXML_NONET) ? $d : null;
}

/** guid / Atom id values of a feed, in order. */
function rss_ids(DOMDocument $d): array {
    $o = [];
    foreach ($d->getElementsByTagName('guid') as $g) $o[] = $g->textContent;
    return $o;
}
function atom_ids(DOMDocument $d): array {
    $o = [];
    foreach ($d->getElementsByTagName('entry') as $e) {
        foreach ($e->childNodes as $c) if ($c instanceof DOMElement && $c->tagName === 'id') $o[] = $c->textContent;
    }
    return $o;
}

/** Unfold an iCalendar body into logical lines. */
function ics_lines(string $ics): array {
    return explode("\r\n", rtrim(preg_replace("/\r\n[ \t]/", '', $ics), "\r\n"));
}

/** Logical lines of the VEVENT with this UID, as name => value (first wins). */
function ics_event(string $ics, int $id): ?array {
    $in = false; $ev = [];
    foreach (ics_lines($ics) as $l) {
        if ($l === 'BEGIN:VEVENT') { $in = true; $ev = []; continue; }
        if ($l === 'END:VEVENT') { if (($ev['UID'] ?? '') === "posturi-gov2-ro-$id@posturi.gov2.ro") return $ev; $in = false; continue; }
        if ($in && ($p = strpos($l, ':')) !== false) {
            $name = explode(';', substr($l, 0, $p))[0];
            $ev[$name] ??= substr($l, $p + 1);
            if ($name === 'DTSTART') $ev['DTSTART'] = substr($l, $p + 1);
        }
    }
    return null;
}

function ids_from_urls(array $urls): array {
    $o = [];
    foreach ($urls as $u) if (preg_match('#/(?:anunt/fixture-|cap-|cal-)?(\d+)$#', $u, $m)) $o[] = (int)$m[1];
    return $o;
}

function list_ids(string $base, string $qs): array {
    $ids = [];
    for ($p = 1; $p <= 12; $p++) {
        [, $b] = fetch($base, '/?' . $qs . ($qs !== '' ? '&' : '') . 'page=' . $p);
        preg_match_all('#<h2 class="font-display[^>]*>\s*<a href="/job/(\d+)-#', $b, $m);
        foreach ($m[1] as $i) $ids[] = (int)$i;
        if (!str_contains($b, 'rel="next"')) break;
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

// ---------------------------------------------------------------------------

run_suite('RSS 2.0: content type, channel and item structure', function () use ($base) {
    [$status, $rss, $h] = fetch($base, '/posturi.rss?judet%5B%5D=cluj&status=all');
    assert_same(200, $status, 'rss 200');
    assert_same('application/rss+xml; charset=utf-8', $h['content-type'] ?? '', 'rss content type');
    $d = xml_doc($rss);
    assert_true($d !== null, 'rss parses as XML');
    $root = $d->documentElement;
    assert_same('rss', $root->tagName, 'root rss');
    assert_same('2.0', $root->getAttribute('version'), 'version 2.0');
    $ch = $root->getElementsByTagName('channel')->item(0);
    foreach (['title', 'link', 'description'] as $req) {
        assert_true($ch->getElementsByTagName($req)->length > 0 && trim($ch->getElementsByTagName($req)->item(0)->textContent) !== '', "channel $req");
    }
    assert_contains('căutare filtrată', $ch->getElementsByTagName('title')->item(0)->textContent, 'filtered channel title is truthful');
    $items = $d->getElementsByTagName('item');
    assert_true($items->length > 0, 'rss has items');
    $first = $items->item(0);
    $guid = $first->getElementsByTagName('guid')->item(0);
    assert_same('false', $guid->getAttribute('isPermaLink'), 'guid is not a permalink');
    assert_true(str_starts_with($guid->textContent, 'https://posturi.gov.ro/anunt/'), 'guid is the official URL');
    assert_true((bool)preg_match('#^http://127\.0\.0\.1:\d+/job/\d+-[a-z0-9-]+/$#', $first->getElementsByTagName('link')->item(0)->textContent), 'item link is the local detail page');
    assert_true((bool)preg_match('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} 00:00:00 \+0[23]00$/', $first->getElementsByTagName('pubDate')->item(0)->textContent), 'pubDate RFC 822 with a real offset');
    $desc = $first->getElementsByTagName('description')->item(0)->textContent;
    assert_contains('Stare:', $desc, 'status in summary');
    assert_contains('Termen', $desc, 'deadline in summary');
    assert_contains('Sursa oficială (posturi.gov.ro): <a href="https://posturi.gov.ro/anunt/', $desc, 'official-source link in summary');
    // Self link names the feed itself, filters included.
    $self = null;
    foreach ($ch->getElementsByTagName('link') as $l) if ($l->getAttribute('rel') === 'self') $self = $l->getAttribute('href');
    assert_true($self !== null && str_contains($self, '/posturi.rss?judet%5B0%5D=cluj'), 'atom:link rel=self carries the filters');
});

run_suite('Atom keeps its ids, moves alternate to the local page, adds the official source', function () use ($base) {
    [, $atom, $h] = fetch($base, '/posturi.atom?status=all&judet%5B%5D=cluj');
    assert_true(str_starts_with($h['content-type'] ?? '', 'application/atom+xml'), 'atom content type');
    $d = xml_doc($atom);
    assert_true($d !== null, 'atom parses');
    [, $rss] = fetch($base, '/posturi.rss?status=all&judet%5B%5D=cluj');
    $r = xml_doc($rss);
    assert_same(atom_ids($d), rss_ids($r), 'same ids, same newest-first order as RSS');
    foreach (atom_ids($d) as $id) assert_true(str_starts_with($id, 'https://posturi.gov.ro/anunt/fixture-'), "entry id stays the official URL ($id)");
    $e = $d->getElementsByTagName('entry')->item(0);
    $alt = $rel = null;
    foreach ($e->getElementsByTagName('link') as $l) {
        if ($l->getAttribute('rel') === 'alternate') $alt = $l->getAttribute('href');
        if ($l->getAttribute('rel') === 'related') $rel = $l->getAttribute('href');
    }
    assert_true((bool)preg_match('#/job/\d+-[a-z0-9-]+/$#', (string)$alt), 'alternate is the local detail URL');
    assert_true(str_starts_with((string)$rel, 'https://posturi.gov.ro/anunt/'), 'related is the official source');
    foreach (['updated', 'published'] as $t) {
        $v = $e->getElementsByTagName($t)->item(0)->textContent;
        assert_true((bool)preg_match('/^\d{4}-\d\d-\d\dT00:00:00\+0[23]:00$/', $v), "$t carries a real UTC offset ($v)");
    }
    assert_contains('Sursa oficială (posturi.gov.ro): https://posturi.gov.ro/anunt/', $e->getElementsByTagName('summary')->item(0)->textContent, 'official source in summary');
    // Identity does not depend on the slug: an id with a different title stays put.
    assert_true(count(array_unique(atom_ids($d))) === count(atom_ids($d)), 'ids unique');
});

run_suite('hostile text stays text and the XML stays valid', function () use ($big) {
    foreach (['/posturi.rss?status=all', '/posturi.atom?status=all'] as $path) {
        [, $x] = fetch($big, $path);
        $d = xml_doc($x);
        assert_true($d !== null, "$path parses (control chars removed, markup escaped)");
        assert_not_contains("\x01", $x, "$path has no control characters");
        assert_not_contains('<script>', $x, "$path has no raw script tag");
        $titles = [];
        foreach ($d->getElementsByTagName('title') as $t) $titles[] = $t->textContent;
        assert_true((bool)array_filter($titles, fn($t) => str_contains($t, '<script>alert(1)</script> & "Ț"')), "$path title round-trips as text");
    }
    // RSS description is HTML by convention: decoded once, it must hold no active element.
    $d = xml_doc(fetch($big, '/posturi.rss?status=all')[1]);
    $seen_link = false;
    foreach ($d->getElementsByTagName('description') as $n) {
        if ($n->parentNode->nodeName !== 'item') continue;
        $h = new DOMDocument();
        @$h->loadHTML('<?xml encoding="UTF-8"><body>' . $n->textContent . '</body>');
        assert_same(0, $h->getElementsByTagName('script')->length, 'no <script> after decoding the description');
        assert_same(0, $h->getElementsByTagName('img')->length, 'no <img> after decoding the description');
        foreach ($h->getElementsByTagName('a') as $a) {
            if ($a->getAttribute('href') === 'https://posturi.gov.ro/anunt/hostil?a=1&b=<2>') $seen_link = true;
        }
    }
    assert_true($seen_link, 'hostile official URL survives as an attribute value, not markup');
});

run_suite('RSS, Atom, JSON and the list agree on the selection; iCal on its dated subset', function () use ($base) {
    $queries = [
        'judet%5B%5D=cluj&judet%5B%5D=bacau&status=all',
        'q=spital&status=all',
        'eqf%5B%5D=4&status=all',
        'skill%5B%5D=Excel&skill%5B%5D=Contabilitate&skill_mode=any&status=all',
        'skill%5B%5D=Excel&skill%5B%5D=Contabilitate&skill_mode=all&status=all',
        'employer=spitalul-clinic-judetean-cluj&status=all',
        'status=closed',
        'status=unknown',
        'status=soon',
        'expires_after=2026-10-15&expires_before=2026-10-31&status=all',
    ];
    foreach ($queries as $qs) {
        $json = json_decode(fetch($base, "/posturi.json?$qs")[1], true);
        $j = array_map(fn($r) => (int)$r['id'], $json['results']);
        sort($j);
        assert_true($json['count'] <= 50, "$qs: under the cap, so ids are comparable before caps");
        foreach (['rss' => 'rss_ids', 'atom' => 'atom_ids'] as $fmt => $fn) {
            $ids = ids_from_urls($fn(xml_doc(fetch($base, "/posturi.$fmt?$qs")[1])));
            sort($ids);
            assert_same($j, $ids, "$qs: $fmt ids == JSON ids");
        }
        assert_same($j, list_ids($base, $qs), "$qs: HTML list ids == JSON ids");
        // iCal: exactly the postings with a resolved date, in deadline order.
        [, $ics] = fetch($base, "/posturi.ics?$qs");
        $uids = [];
        preg_match_all('/^UID:posturi-gov2-ro-(\d+)@/m', $ics, $m);
        foreach ($m[1] as $u) $uids[] = (int)$u;
        $dated = array_map(fn($r) => (int)$r['id'], array_filter($json['results'], fn($r) => $r['apply_deadline'] !== null));
        sort($dated);
        $sorted = $uids;
        sort($sorted);
        assert_same($dated, $sorted, "$qs: iCal ids == dated JSON ids");
    }
    // The facet-narrowed sets are not trivially "everything".
    $a = json_decode(fetch($base, '/posturi.json?status=closed')[1], true)['count'];
    $b = json_decode(fetch($base, '/posturi.json?status=unknown')[1], true)['count'];
    assert_true($a >= 1 && $b >= 1, 'closed and unknown rows are included only when selected');
    $default = ids_from_urls(rss_ids(xml_doc(fetch($base, '/posturi.rss')[1])));
    assert_false(in_array(1003, $default, true), 'closed row not in the default feed');
});

run_suite('caps: newest 50 for RSS/Atom, earliest 200 dated for iCal', function () use ($big) {
    foreach (['rss' => 'rss_ids', 'atom' => 'atom_ids'] as $fmt => $fn) {
        preg_match_all('#/job/(\d+)-#', fetch($big, "/posturi.$fmt?status=all")[1], $mm);
        $ids = array_map('intval', $mm[1]);
        assert_same(50, count($ids), "$fmt: exactly 50 entries from 300+ matches");
        // 3001..3060 were published 2026-10-02 10:00..10:59; 3901/3902 at 11:00/12:00. Newest first.
        assert_same(3901, $ids[0], "$fmt: newest first");
        assert_same(3902, $ids[1], "$fmt: newest first (2)");
        assert_same(3060, $ids[2], "$fmt: newest first (3)");
        assert_same(3015, $ids[47], "$fmt: 50th entry is the 48th newest cap row");
    }
    [, $ics] = fetch($big, '/posturi.ics?status=all');
    assert_same(200, substr_count($ics, "BEGIN:VEVENT\r\n"), 'iCal: exactly 200 events');
    preg_match_all('/^UID:posturi-gov2-ro-(\d+)@/m', $ics, $m);
    $got = array_map('intval', $m[1]);
    $pdo = new PDO('sqlite:' . $GLOBALS['db_path2']);
    $want = array_map('intval', $pdo->query("SELECT id FROM job_postings WHERE apply_deadline IS NOT NULL ORDER BY apply_deadline ASC, id ASC LIMIT 200")->fetchAll(PDO::FETCH_COLUMN));
    assert_same($want, $got, 'iCal: the earliest 200 dated postings, in deadline order');
    preg_match_all('/^DTSTART;VALUE=DATE:(\d{8})/m', $ics, $d);
    $s = $d[1]; $t = $s; sort($t);
    assert_same($t, $s, 'iCal DTSTART never decreases');
    assert_true(count($s) === 200, 'one DTSTART per event');
});

run_suite('malformed query shapes: controlled machine-readable 400 on every feed route', function () use ($base) {
    foreach (['posturi.rss', 'posturi.atom', 'posturi.ics', 'posturi.json'] as $f) {
        foreach (['q%5B%5D=medic', 'sort%5B%5D=x', 'judet%5Ba%5D%5Bb%5D=cluj', 'title%5B%5D=x', 'q=' . urlencode(str_repeat('x', 301))] as $qs) {
            [$status, $body, $h] = fetch($base, "/$f?$qs");
            assert_same(400, $status, "$f?$qs: 400");
            assert_true(str_starts_with($h['content-type'] ?? '', 'application/json'), "$f?$qs: JSON error body");
            $j = json_decode($body, true);
            assert_true(is_array($j) && !empty($j['error']), "$f?$qs: error message present");
            assert_not_contains('Fatal', $body, "$f?$qs: no fatal");
        }
    }
});

run_suite('empty filtered feeds stay valid with truthful titles', function () use ($base) {
    $x = xml_doc(fetch($base, '/posturi.rss?q=zzzzqqqq')[1]);
    assert_true($x !== null, 'empty RSS parses');
    assert_same(0, $x->getElementsByTagName('item')->length, 'no items');
    assert_contains('căutare filtrată', $x->getElementsByTagName('title')->item(0)->textContent, 'title says filtered');
    $a = xml_doc(fetch($base, '/posturi.atom?q=zzzzqqqq')[1]);
    assert_true($a !== null, 'empty Atom parses');
    assert_same(0, $a->getElementsByTagName('entry')->length, 'no entries');
    $upd = $a->getElementsByTagName('updated')->item(0)->textContent;
    assert_same('2026-10-03T09:00:00+00:00', $upd, 'feed updated falls back to the database build time, not "now"');
    $ics = fetch($base, '/posturi.ics?q=zzzzqqqq')[1];
    assert_contains("BEGIN:VCALENDAR\r\n", $ics, 'empty iCal still a calendar');
    assert_same(0, substr_count($ics, 'BEGIN:VEVENT'), 'no invented events');
    // Employer-only feeds are named for the employer, not "filtered".
    $e = xml_doc(fetch($base, '/posturi.rss?employer=spitalul-clinic-judetean-cluj')[1]);
    $t = $e->getElementsByTagName('title')->item(0)->textContent;
    assert_same('posturi.gov2.ro — Spitalul Clinic Județean Cluj', $t, 'employer feed title');
    $plain = xml_doc(fetch($base, '/posturi.rss')[1]);
    assert_same('posturi.gov2.ro', $plain->getElementsByTagName('title')->item(0)->textContent, 'unfiltered title');
});

run_suite('iCal: escaping, 75-octet folding, UTF-8, CRLF, URI fidelity', function () use ($big, $LONG_TITLE, $COMMA_URL) {
    [$status, $ics, $h] = fetch($big, '/posturi.ics?status=all&title=' . urlencode("Cal\x07endar, cu; virgulă"));
    assert_same(200, $status, 'ics 200');
    assert_true(str_starts_with($h['content-type'] ?? '', 'text/calendar'), 'content type');
    assert_true(mb_check_encoding($ics, 'UTF-8'), 'valid UTF-8 overall');
    assert_same(0, preg_match('/(?<!\r)\n/', $ics), 'only CRLF line endings');
    // Physical lines: <= 75 octets, each valid UTF-8 on its own (no split sequence).
    // Unfolding removes exactly one space per continuation (checked via SUMMARY below).
    $phys = explode("\r\n", rtrim($ics, "\r\n"));
    $over = 0; $broken = 0;
    foreach ($phys as $l) {
        if (strlen($l) > 75) $over++;
        if (!mb_check_encoding($l, 'UTF-8')) $broken++;
    }
    assert_same(0, $over, 'no physical line over 75 octets');
    assert_same(0, $broken, 'no UTF-8 character split across lines');
    assert_true(count(array_filter($phys, fn($l) => ($l[0] ?? '') === ' ')) > 5, 'long lines really were folded');

    $logical = ics_lines($ics);
    $name = array_values(array_filter($logical, fn($l) => str_starts_with($l, 'X-WR-CALNAME:')))[0] ?? '';
    assert_same('X-WR-CALNAME:Calendar\\, cu\; virgulă', $name, 'custom title: control char stripped, TEXT escaped');
    // Cap at 80 characters.
    $long = array_values(array_filter(ics_lines(fetch($big, '/posturi.ics?title=' . urlencode(str_repeat('ă', 120)))[1]), fn($l) => str_starts_with($l, 'X-WR-CALNAME:')))[0];
    assert_same(80, mb_strlen(substr($long, strlen('X-WR-CALNAME:'))), 'title capped at 80 characters');

    // The long Romanian title: unfolds to exactly the (escaped) text.
    $ev = ics_event($ics, 3902);
    assert_true($ev !== null, 'event for the long-title posting');
    $want_summary = 'Termen estimat: ' . ical_text(trim($LONG_TITLE . ' — Administrația de Salubrizare Tunari'));
    assert_same($want_summary, $ev['SUMMARY'] ?? null, 'long summary survives folding byte for byte, with the estimate qualifier');
    assert_contains('\\,', $ev['SUMMARY'], 'comma in TEXT escaped');
    // URL is a URI property: not TEXT-escaped, non-ASCII percent-encoded, existing escapes kept.
    assert_same('https://posturi.gov.ro/anunt/test,virgula;punct?x=1,2&y=%C8%9B&z=%C8%99', $ev['URL'] ?? null, 'URI: commas/semicolons verbatim, ș encoded, %C8%9B kept');
    assert_not_contains('\\,', $ev['URL'] ?? '', 'URL has no TEXT escapes');
    // Estimated deadline is visible in both SUMMARY and DESCRIPTION.
    assert_contains('Termen estimat (data expirării anunțului): 2026-11-21', $ev['DESCRIPTION'], 'estimate in DESCRIPTION');
    assert_same('20261121', $ev['DTSTART'], 'all-day start');
    assert_same('20261122', $ev['DTEND'], 'exclusive end is the next day');
    assert_same('posturi-gov2-ro-3902@posturi.gov2.ro', $ev['UID'], 'stable UID');
    // Confirmed deadlines are not labelled as estimates.
    $c = ics_event($ics, 1001);
    assert_not_contains('Termen estimat', $c['SUMMARY'], 'confirmed date: no estimate prefix');
    // No invented events for a posting without any date (1005), no alarms or extra stages.
    assert_same(null, ics_event($ics, 1005), 'no event without a date');
    assert_not_contains('VALARM', $ics, 'no reminders');
    // Hostile title in iCal: control character gone, delimiters escaped.
    $h = ics_event($ics, 3901);
    assert_not_contains("\x01", $h['SUMMARY'] ?? '', 'control char removed from SUMMARY');
    assert_contains('<script>alert(1)</script> & "Ț"', $h['SUMMARY'], 'text kept as text');
});

run_suite('iCal helpers: folding and encoding at the boundaries', function () {
    // Exactly 75 octets: not folded. 76: folded.
    $l75 = 'X:' . str_repeat('a', 73);
    assert_same($l75 . "\r\n", ical_fold($l75), '75 octets stay on one line');
    $f = ical_fold($l75 . 'b');
    assert_same(2, count(explode("\r\n", rtrim($f, "\r\n"))), '76 octets fold once');
    // Multibyte at the boundary: 'ț' is 2 octets and must move whole to the next line.
    $s = 'X:' . str_repeat('a', 72) . 'ț';   // 74 + 2 = 76 octets
    $parts = explode("\r\n", rtrim(ical_fold($s), "\r\n"));
    assert_same('X:' . str_repeat('a', 72), $parts[0], 'the 2-octet char is not split');
    assert_same(' ț', $parts[1], 'continuation = one space + the whole character');
    // 4-octet characters.
    $e = 'X:' . str_repeat('😀', 40);
    foreach (explode("\r\n", rtrim(ical_fold($e), "\r\n")) as $p) {
        assert_true(strlen($p) <= 75 && mb_check_encoding($p, 'UTF-8'), 'emoji lines <= 75 and valid');
    }
    assert_same($e, preg_replace("/\r\n /", '', rtrim(ical_fold($e), "\r\n")), 'unfolding restores the line');
    // TEXT escaping.
    assert_same('a\\\\b\;c\\,d\\ne', ical_text("a\\b;c,d\r\ne"), 'TEXT escapes backslash ; , newline');
    assert_same('ab', ical_text("a\x00\x1Fb"), 'controls dropped');
    // URI encoding.
    assert_same('https://x.ro/a%20b/%C8%9B?q=1,2;3&r=%C8%9B%5C%22', ical_uri("https://x.ro/a b/ț?q=1,2;3&r=%C8%9B\\\""), 'URI octets');
    assert_same('https://x.ro/p?a=1&b=2#f', ical_uri('https://x.ro/p?a=1&b=2#f'), 'plain URL untouched');
});

run_suite('subscription block: one block, labels, limits, no page/sort, no local state', function () use ($base) {
    $qs = 'judet%5B%5D=cluj&judet%5B%5D=bacau&q=spital&status=all&page=1&sort=deadline&title=Cal';
    [, $html] = fetch($base, "/?$qs");
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $x = new DOMXPath($dom);
    // No duplicate ids anywhere on the page.
    $seen = []; $dups = [];
    foreach ($x->query('//*[@id]') as $n) { $i = $n->getAttribute('id'); if (isset($seen[$i])) $dups[] = $i; $seen[$i] = true; }
    assert_same([], $dups, 'no duplicate ids');
    assert_same(1, $x->query('//details[@id="urmareste"]')->length, 'one subscription disclosure');
    assert_same(1, $x->query('//details[@id="urmareste"]/summary[normalize-space()="Urmărește această căutare"]')->length, 'summary label');
    assert_same(0, $x->query('//details[@id="urmareste"][@open]')->length, 'closed by default');
    // Labels and links, one each.
    $labels = [];
    foreach (['posturi.rss', 'posturi.atom', 'posturi.ics', 'posturi.json'] as $f) {
        $a = $x->query('//a[@data-feed="' . $f . '"]');
        assert_same(1, $a->length, "$f: exactly one link");
        $labels[$f] = trim($a->item(0)->textContent);
        $href = $a->item(0)->getAttribute('href');
        parse_str((string)parse_url($href, PHP_URL_QUERY), $q);
        assert_same(['cluj', 'bacau'], $q['judet'] ?? null, "$f: counties");
        assert_same('spital', $q['q'] ?? null, "$f: keyword");
        assert_same('all', $q['status'] ?? null, "$f: status");
        assert_false(isset($q['page']) || isset($q['sort']), "$f: no page/sort");
        assert_same($f === 'posturi.ics', isset($q['title']), "$f: title only on the calendar");
        foreach (array_keys($q) as $k) assert_false(str_contains($k, 'sav') || str_contains($k, 'hid'), "$f: no saved/hidden state ($k)");
    }
    assert_same(['posturi.rss' => 'RSS', 'posturi.atom' => 'Atom', 'posturi.ics' => 'Calendar iCal', 'posturi.json' => 'JSON API'], $labels, 'link labels');
    assert_not_contains('Atom (RSS)', $html, 'old mislabel gone');
    // Calendar subscription links encode the same ICS URL.
    $ics = html_entity_decode($x->query('//a[@data-feed="posturi.ics"]')->item(0)->getAttribute('href'));
    $g = html_entity_decode($x->query('//a[@id="gcal-link"]')->item(0)->getAttribute('href'));
    assert_same($base . $ics, rawurldecode((string)substr($g, strpos($g, 'url=') + 4)), 'Google link wraps the ICS URL');
    $w = html_entity_decode($x->query('//a[@id="webcal-link"]')->item(0)->getAttribute('href'));
    assert_same(preg_replace('#^http://#', 'webcal://', $base . $ics), $w, 'webcal link is the ICS URL');
    // Scope text and caveats.
    $block = $x->query('//details[@id="urmareste"]')->item(0)->textContent;
    $block = preg_replace('/\s+/', ' ', $block);
    assert_contains('RSS/Atom: cele mai noi 50 de anunțuri care corespund filtrelor. Calendar: primele 200 cu dată disponibilă, în ordinea termenului.', $block, 'limits sentence');
    assert_contains('hotărât de cititorul de fluxuri sau de aplicația de calendar', $block, 'refresh is the client’s');
    assert_contains('copie de moment', $block, 'ICS import is a snapshot');
    assert_contains('nu sunt o arhivă completă', $block, 'not an archive');
    foreach (['imediat', 'garant'] as $bad) assert_not_contains($bad . 'e', str_replace('Nu trimit notificări imediate', '', $block), "no promise: $bad");
    // Copy buttons are script-only; links work without them.
    foreach ($x->query('//button[@data-copy]') as $b) assert_true($b->hasAttribute('hidden'), 'copy buttons ship hidden');
    assert_same(1, $x->query('//a[@href="#urmareste-linkuri"]')->length, 'export line links to the block (no-JS anchor)');
    // Home page, no filters: plain links.
    [, $home] = fetch($base, '/');
    assert_contains('data-feed="posturi.rss" href="/posturi.rss"', $home, 'unfiltered RSS link');
    assert_contains('rel="alternate" type="application/rss+xml"', $home, 'RSS autodiscovery');
    // Employer page.
    [, $emp] = fetch($base, '/angajator/spitalul-clinic-judetean-cluj/');
    foreach (['>RSS<', '>Atom<', '>Calendar iCal<', '>JSON API<', '/posturi.rss?employer=spitalul-clinic-judetean-cluj'] as $needle) assert_contains($needle, $emp, "employer page: $needle");
});

stop_fixture_server($srv2, $db_path2);
stop_fixture_server($srv, $db_path);
finish();
