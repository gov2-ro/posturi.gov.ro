<?php
/**
 * FIX-02 — deadline presentation tests.
 *
 * Unit suites run with a fixed clock (POSTURI_TODAY); the rendered suite builds
 * the fixture database, serves the real app on the built-in PHP router and
 * asserts list/detail/Atom/iCal/JSON agree on ONE date per posting — the
 * regression that showed "13 zile" beside a different date must fail here.
 * See docs/specs/2026-10-03-project-audit/02-deadline-presentation.md.
 *
 * Usage: php tests/deadline_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../Parsedown.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

const FIXTURE_TODAY = '2026-10-03';
putenv('POSTURI_TODAY=' . FIXTURE_TODAY);

// ---- Unit: days_until on the fixed clock ----

run_suite('days_until uses one Bucharest clock', function () {
    assert_same(13, days_until('2026-10-16'), 'future deadline');
    assert_same(0, days_until('2026-10-03'), 'same-day deadline');
    assert_same(-3, days_until('2026-09-30'), 'past deadline');
    assert_same(null, days_until(null), 'no date');
    assert_same(null, days_until(''), 'empty date');
    assert_same(null, days_until('not-a-date'), 'garbage date');
    // Midnight boundary: one day before is -1, the day itself is 0.
    assert_same(-1, days_until('2026-10-02'), 'one day before');
});

// ---- Unit: posting_deadline resolution ----

run_suite('posting_deadline resolves one date with provenance', function () {
    assert_same(
        ['date' => '2026-10-16', 'source' => 'anunt'],
        posting_deadline(['apply_deadline' => '2026-10-16', 'deadline_source' => 'anunt', 'expires_at' => '2026-11-10'])
    );
    assert_same(
        ['date' => '2026-10-20', 'source' => 'expirare'],
        posting_deadline(['apply_deadline' => '2026-10-20', 'deadline_source' => 'expirare', 'expires_at' => '2026-10-20'])
    );
    // No deadline at all: expiry fallback still lands as an estimate.
    assert_same(
        ['date' => '2026-11-10', 'source' => 'expirare'],
        posting_deadline(['apply_deadline' => null, 'deadline_source' => '', 'expires_at' => '2026-11-10'])
    );
    // Nothing anywhere: explicit unknown.
    assert_same(
        ['date' => null, 'source' => null],
        posting_deadline(['apply_deadline' => null, 'deadline_source' => '', 'expires_at' => null])
    );
    // Old export without deadline_source: same date as expiry infers expirare.
    assert_same(
        ['date' => '2026-10-20', 'source' => 'expirare'],
        posting_deadline(['apply_deadline' => '2026-10-20', 'expires_at' => '2026-10-20'])
    );
    // …and a date different from expiry keeps no (unknown) source.
    assert_same(
        ['date' => '2026-10-16', 'source' => null],
        posting_deadline(['apply_deadline' => '2026-10-16', 'expires_at' => '2026-11-10'])
    );
});

// ---- Unit: ro_today() ignores the host timezone ----

run_suite('ro_today is Bucharest on any host timezone', function () {
    $expected = (new DateTimeImmutable('today', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
    $php = escapeshellarg(PHP_BINARY);
    $code = escapeshellarg('require "/dev/stdin"; echo ro_today();');
    foreach (['UTC', 'America/New_York', 'Asia/Tokyo'] as $tz) {
        $out = shell_exec("TZ=$tz $php -r \"require '" . __DIR__ . "/../helpers.php'; echo ro_today();\" 2>&1");
        assert_same($expected, trim((string)$out), "TZ=$tz");
    }
    // And the fixed clock seam feeds the same path.
    assert_same(FIXTURE_TODAY, ro_today(), 'POSTURI_TODAY fixed clock');
});

// ---- Rendered: one date across list / detail / feeds ----

run_suite('rendered: countdown and printed date agree everywhere', function () {
    $db_path = sys_get_temp_dir() . '/posturi-fix02-' . getmypid() . '.sqlite';
    build_fixture_db($db_path);
    $srv = start_fixture_server($db_path);
    $base = $srv['base'];
    $get = function (string $path) use ($base) {
        return (string)@file_get_contents($base . $path);
    };

    $list = $get('/');
    assert_not_contains('Fatal error', $list, 'list renders');

    // Extract one result row by its canonical job URL — title needles can also
    // match facet labels in the sidebar, the URL anchor cannot.
    $row = function (string $html, string $anchor) {
        $pos = strpos($html, $anchor);
        return $pos === false ? '' : substr($html, $pos, 3000);
    };

    // 1001: scraped deadline 13 days away, competition ends later. The countdown
    // and the machine/printed dates all read 2026-10-16 — the original bug.
    $row1001 = $row($list, 'href="/job/1001-asistent-medical-generalist/"');
    assert_contains('13 zile', $row1001, '1001 countdown');
    assert_contains('datetime="2026-10-16"', $row1001, '1001 <time datetime>');
    assert_contains('16.10.2026', $row1001, '1001 printed date');
    assert_not_contains('datetime="2026-11-10"', $row1001, '1001 must not show the expiry as the deadline');
    assert_not_contains('10.11.2026', $row1001, '1001 must not print the expiry beside the countdown');

    // 1002 same-day.
    $row1002 = $row($list, 'href="/job/1002-referent-debutant/"');
    assert_contains('Azi!', $row1002, '1002 same-day');

    // 1003 is past its deadline, so the default "active" list excludes it;
    // ?status=all keeps old links working and shows it as closed.
    $list_all = $get('/?status=all');
    assert_not_contains('Fatal error', $list_all, 'status=all renders');
    $row1003 = $row($list_all, 'href="/job/1003-inspector-grad-ii/"');
    assert_contains('Expirat', $row1003, '1003 past deadline');

    // 1004: expiry fallback is visibly an estimate, never a confirmed deadline.
    $row1004 = $row($list, 'href="/job/1004-ingrijitor-scoala/"');
    assert_contains('17 zile', $row1004, '1004 countdown');
    assert_contains('estimat', $row1004, '1004 visible qualification');
    assert_contains('datetime="2026-10-20"', $row1004, '1004 machine date');

    // 1005: no dates → explicit unknown, no countdown.
    $row1005 = $row($list, 'href="/job/1005-muncitor-necalificat/"');
    assert_contains('Termen neprecizat', $row1005, '1005 unknown state');

    // Detail page for the fallback row: the visible (not tooltip-only) warning.
    $detail1004 = $get('/job/1004/');
    assert_not_contains('Fatal error', $detail1004, 'detail renders');
    assert_contains('Data expirării; termenul de înscriere nu este confirmat.', $detail1004, 'detail warning');
    assert_not_contains('Înscrieri până la 20.10.2026', $detail1004, 'fallback never reads as confirmed');

    // Detail for 1001: exact source time survives into validThrough (Bucharest
    // is UTC+3 in October), not a fabricated 23:59.
    $detail1001 = $get('/job/1001/');
    assert_contains('"validThrough":"2026-10-16T14:30:00+03:00"', $detail1001, 'exact-time validThrough');

    // 1006: deadline equals expiry — the "se încheie" line must not duplicate it.
    $detail1006 = $get('/job/1006/');
    $six = substr($detail1006, strpos($detail1006, 'Termen depunere') ?: 0, 1500);
    assert_not_contains('concursul se încheie', $six, '1006 no duplicate end line');

    // Atom: provenance is visible in the summary.
    $atom = $get('/posturi.atom');
    assert_not_contains('Fatal error', $atom, 'atom renders');
    assert_contains('Termen estimat (data expirării anunțului): 2026-10-20', $atom, 'atom estimate label');
    assert_contains('Termen depunere: 2026-10-16', $atom, 'atom confirmed label');

    // iCal: all-day events must have an EXCLUSIVE end date of the next day.
    $ics = $get('/posturi.ics');
    assert_not_contains('Fatal error', $ics, 'ics renders');
    assert_contains('DTSTART;VALUE=DATE:20261016', $ics, 'ics start');
    assert_contains('DTEND;VALUE=DATE:20261017', $ics, 'ics exclusive end next day');
    assert_not_contains('DTEND;VALUE=DATE:20261016', $ics, 'ics end is never the start day');
    assert_contains('Termen estimat (data expirării anunțului): 2026-10-20', $ics, 'ics estimate label');
    // UIDs stay stable across the change.
    assert_contains('UID:posturi-gov2-ro-1001@posturi.gov2.ro', $ics, 'ics stable UID');
    // Every VEVENT spans exactly one day.
    preg_match_all('/DTSTART;VALUE=DATE:(\d{8})\r?\nDTEND;VALUE=DATE:(\d{8})/', $ics, $m);
    assert_true(count($m[1]) > 0, 'ics has events');
    foreach ($m[1] as $i => $start) {
        $s = DateTime::createFromFormat('!Ymd', $start);
        $e = DateTime::createFromFormat('!Ymd', $m[2][$i]);
        assert_same(1, $s->diff($e)->days, "event $start spans exactly one day");
    }

    // JSON: expires_at/apply_deadline keys keep their shape and values.
    $json = json_decode($get('/posturi.json'), true);
    assert_true(is_array($json) && count($json['results'] ?? []) > 0, 'json feed decodes');
    $by_id = [];
    foreach (($json['results'] ?? []) as $entry) $by_id[$entry['id'] ?? 0] = $entry;
    assert_same('2026-10-16', $by_id[1001]['apply_deadline'] ?? null, 'json apply_deadline');
    assert_same('2026-11-10', $by_id[1001]['expires_at'] ?? null, 'json expires_at kept');
    assert_same('anunt', $by_id[1001]['deadline_source'] ?? null, 'json deadline_source');

    proc_terminate($srv['proc']);
    proc_close($srv['proc']);
    @unlink($db_path);
    @unlink($db_path . '-shm');
    @unlink($db_path . '-wal');
});

finish();
