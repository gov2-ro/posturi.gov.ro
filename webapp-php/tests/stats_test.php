<?php
/**
 * FIX-09 — /statistici says which dataset each figure describes.
 *
 * Server-rendered checks against the fixture database: the current export (what the SQLite
 * file holds) and the historical aggregate (`publication_monthly`, computed from all of
 * Postgres) are separate, labelled sections; deleting expired rows from a later export
 * moves the first and never the second; an export without the aggregate says
 * "indisponibil" instead of inferring history from the surviving rows; salary announced
 * and salary estimated are counted apart; the employer-category chart is the source's
 * own label, with unknown counted. The aggregate's SQL is covered against Postgres in
 * webapp/tests/test_publication_monthly_export.py; the layout in tests/browser/stats.spec.js.
 *
 * Usage: php tests/stats_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/fixtures/build_db.php';
require_once __DIR__ . '/fixtures/server.php';

const STATS_TODAY = '2026-10-03';
putenv('POSTURI_TODAY=' . STATS_TODAY);

$tmp = sys_get_temp_dir() . '/posturi-stats-' . getmypid();

/** Build a fixture, let $mutate change it, serve it and return /statistici/ as a DOM. */
function stats_page(string $path, ?callable $mutate = null): array {
    build_fixture_db($path);
    if ($mutate) {
        $pdo = new PDO("sqlite:$path", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $mutate($pdo);
        $pdo = null;
    }
    $srv = start_fixture_server($path, STATS_TODAY);
    $html = (string)@file_get_contents($srv['base'] . '/statistici/');
    $log = (string)@file_get_contents($srv['log']);
    stop_fixture_server($srv, $path);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    return [$html, new DOMXPath($dom), $dom, $log];
}

/** Whitespace-collapsed text of the element with this id ('' when absent). */
function section_text(DOMXPath $x, string $id): string {
    $parts = [];
    foreach ($x->query('//*[@id="' . $id . '"]//text()') as $t) {
        $parts[] = $t->textContent;
    }
    return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
}

/** The big number of the card whose label reads $label, or null. */
function card_number(DOMXPath $x, string $label): ?string {
    $n = $x->query('//div[normalize-space(text())=' . "'" . $label . "'" . ']/preceding-sibling::div[1]')->item(0);
    return $n ? trim($n->textContent) : null;
}

/** The small line under the label. */
function card_note(DOMXPath $x, string $label): ?string {
    $n = $x->query('//div[normalize-space(text())=' . "'" . $label . "'" . ']/following-sibling::div[1]')->item(0);
    return $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent)) : null;
}

$fixture_count = fn(PDO $p, string $where = '1') => (int)$p->query("SELECT COUNT(*) FROM job_postings WHERE $where")->fetchColumn();

// ---- current export ----------------------------------------------------------------

[$html, $x, , $log] = stats_page("$tmp-a.sqlite");
$truth = (function () use ($tmp) {
    build_fixture_db("$tmp-truth.sqlite");
    $p = new PDO("sqlite:$tmp-truth.sqlite");
    $o = [
        'total' => (int)$p->query('SELECT COUNT(*) FROM job_postings')->fetchColumn(),
        'confirmed' => (int)$p->query("SELECT COUNT(*) FROM job_postings WHERE application_status = 'confirmed_open'")->fetchColumn(),
        'unconfirmed' => (int)$p->query("SELECT COUNT(*) FROM job_postings WHERE application_status = 'unconfirmed'")->fetchColumn(),
        'unknown' => (int)$p->query("SELECT COUNT(*) FROM job_postings WHERE application_status = 'unknown'")->fetchColumn(),
        'closed' => (int)$p->query("SELECT COUNT(*) FROM job_postings WHERE application_status = 'closed'")->fetchColumn(),
        'no_category' => (int)$p->query("SELECT COUNT(*) FROM job_postings WHERE employer_category = ''")->fetchColumn(),
    ];
    $p = null;
    @unlink("$tmp-truth.sqlite");
    return $o;
})();

run_suite('the current export is labelled with its dataset and build date', function () use ($x, $html, $log) {
    $scope = section_text($x, 'domeniu');
    assert_contains('Export curent', $scope, 'scope box names the current export');
    assert_contains('doar anunțurile încă neexpirate și neanulate', $scope, 'says what an active-only export holds');
    assert_contains('generat la 03.10.2026, 12:00', $scope, 'says when it was built (Bucharest time)');
    assert_contains('Nu este arhiva sursei', $scope, 'says it is not the archive');
    assert_contains('Istoric', $scope, 'points to the historical section');
    assert_same('Export curent', trim($x->query('//h2[@id="h-export-curent"]')->item(0)->textContent), 'section heading');
    assert_not_contains('Fatal error', $html);
    assert_not_contains('stats.php', $log, 'server log: nothing from this page');
});

run_suite('exported, confirmed open, uncertain and closed are separate figures that add up', function () use ($x, $truth) {
    assert_same((string)$truth['total'], card_number($x, 'Anunțuri în export'), 'exported');
    assert_same((string)$truth['confirmed'], card_number($x, 'Deschise, termen confirmat'), 'confirmed open');
    assert_same((string)($truth['unconfirmed'] + $truth['unknown']), card_number($x, 'Termen neconfirmat'), 'uncertain');
    assert_same((string)$truth['closed'], card_number($x, 'Închise'), 'closed');
    assert_same($truth['total'], $truth['confirmed'] + $truth['unconfirmed'] + $truth['unknown'] + $truth['closed'], 'fixture: every row has one of the four statuses');
    assert_contains($truth['unconfirmed'] . ' cu termen estimat din expirare · ' . $truth['unknown'] . ' fără termen',
        (string)card_note($x, 'Termen neconfirmat'), 'uncertain splits into estimated-from-expiry and no deadline');
    $active = $truth['confirmed'] + $truth['unconfirmed'] + $truth['unknown'];
    assert_contains("Active în căutare (deschise confirmate + cu termen neconfirmat): $active din {$truth['total']}",
        section_text($x, 'export-curent'), 'the search default is derived from the parts');
    assert_same(null, card_number($x, 'Anunțuri totale'), 'the old unlabeled total card is gone');
    assert_same(null, card_number($x, 'Anunțuri active'), 'the old unlabeled active card is gone');
    assert_contains('% din export', (string)card_note($x, 'Deschise, termen confirmat'), 'percentages name their base');
});

run_suite('the employer-category chart is named for its source and counts unknown', function () use ($x, $html, $truth) {
    assert_not_contains('Tip angajator', $html, 'no institution-type heading');
    $sec = section_text($x, 'categorie-sursa');
    assert_contains('Categoria din anunț (câmp al sursei)', $sec, 'heading');
    assert_contains('nu un tip de angajator', $sec, 'says it is not an employer type');
    assert_contains('Necunoscut (necompletat în sursă) ' . $truth['no_category'], $sec, 'empty values are counted as unknown');
    assert_contains('Funcție contractuală', $sec, 'source labels are shown as stated');
    assert_not_contains('Primării', $sec, 'no institution classes are invented');
});

run_suite('salary announced and salary estimated are counted apart', function () use ($tmp, $fixture_count) {
    [, $x] = stats_page("$tmp-sal.sqlite", function (PDO $p) {
        // 3 rows state a salary (one of them also has the grid estimate: announced wins); 1 row has neither.
        $p->exec("UPDATE job_postings SET inf_salary_min = 4000, inf_salary_max = 4500 WHERE id IN (1001, 1002, 1003)");
        $p->exec("UPDATE job_postings SET sal_min = NULL, sal_max = NULL WHERE id = 1004");
        $p->exec("UPDATE job_postings SET inf_salary_min = 0, sal_min = 3000 WHERE id = 1005");   // 0 = no figure
    });
    assert_same('3', card_number($x, 'Cu salariu anunțat'), 'announced');
    assert_same('34', card_number($x, 'Doar salariu estimat'), 'estimated only = 38 rows - 3 announced - 1 without an estimate');
    assert_contains('declarat de angajator', (string)card_note($x, 'Cu salariu anunțat'));
    assert_contains('nu oferit de angajator', (string)card_note($x, 'Doar salariu estimat'), 'an estimate is not an offered salary');
    assert_same(null, card_number($x, 'Cu salariu'), 'no single ambiguous salary card');
});

// ---- historical aggregate ----------------------------------------------------------

[$html, $x] = stats_page("$tmp-h.sqlite");
$sum = fn(int $col) => array_sum(array_column(FIXTURE_PUBLICATION_MONTHLY, $col));

run_suite('the historical section is its own scope and matches the aggregate', function () use ($x, $html, $sum) {
    assert_same('Istoric: anunțuri publicate pe lună', trim($x->query('//h2[@id="h-istoric"]')->item(0)->textContent));
    $hist = section_text($x, 'istoric');
    assert_same((string)$sum(2), card_number($x, 'Anunțuri publicate, în total'), 'all-history total (152)');
    assert_same(152, $sum(2));
    assert_same((string)$sum(3), card_number($x, 'Din care anulate'), 'cancelled are inside the total and said so');
    assert_same('3', card_number($x, 'Fără dată de publicare'), 'missing dates are an explicit bucket');
    assert_contains('din 2025-11 până în 2026-10', $hist);
    assert_contains('nu câte posturi erau deschise la o anumită dată', $hist, 'intake is not an open-stock curve');
    assert_contains('inclusiv anulate și fără dată; fără județ: 4', $hist, 'county denominator and the county-less bucket');
    $months = section_text($x, 'luna-istoric');
    assert_contains('2026-09 70', $months, 'September counts every announcement, not the 37 in the slice');
    assert_contains('2025-12 11', $months, 'months are summed across counties');
    assert_contains('2026-01 0', $months, 'a month with nothing is a zero bar, not a gap');
    assert_not_contains('unknown', $months, 'the undated bucket is not drawn as a month');
    assert_contains('2026-10 (în curs) 1', $months, 'the build month is marked partial');
    assert_contains('Ilfov 61', section_text($x, 'judete-istoric'), 'county totals');
    assert_not_contains('Luna publicării, doar în exportul curent', $html, 'the slice-only chart is not shown beside real history');
});

run_suite('a later export loses expired rows but not the historical totals', function () use ($tmp, $x, $truth) {
    $before = section_text($x, 'istoric');
    [, $x2, , $log] = stats_page("$tmp-later.sqlite", function (PDO $p) {
        // The next day's build: expired announcements and the closed one dropped from the slice.
        $p->exec("DELETE FROM job_postings WHERE id > 2020 OR application_status = 'closed'");
        $p->exec("UPDATE build_meta SET built_at = '2026-10-04 09:00:00', job_postings = (SELECT COUNT(*) FROM job_postings)");
    });
    assert_same($before, section_text($x2, 'istoric'), 'the historical section is byte-for-byte the same text');
    assert_not_contains('stats.php', $log, 'server log: nothing from this page');
    $now = (int)card_number($x2, 'Anunțuri în export');
    assert_true($now > 0 && $now < $truth['total'], "the current slice shrank ($now < {$truth['total']})");
    assert_same((string)$truth['total'], card_number($x, 'Anunțuri în export'));
    assert_contains('generat la 04.10.2026, 12:00', section_text($x2, 'domeniu'), 'the current block carries the new build date');
    assert_same('0', card_number($x2, 'Închise'), 'closed rows left the slice');
});

run_suite('an export without the aggregate says indisponibil and infers no history', function () use ($tmp) {
    [$html, $x, , $log] = stats_page("$tmp-nohist.sqlite", function (PDO $p) {
        $p->exec('DROP TABLE publication_monthly');
        $p->exec("UPDATE job_postings SET published_at = NULL WHERE id = 1001");   // an undated row
    });
    assert_contains('Istoric indisponibil', section_text($x, 'istoric'));
    assert_contains('Indisponibil în acest export', section_text($x, 'domeniu'), 'the scope box says so too');
    assert_same(null, card_number($x, 'Anunțuri publicate, în total'), 'no all-history figure is invented');
    assert_not_contains('Pe luna publicării (toată baza de date)', $html);
    $slice = section_text($x, 'luna-export');
    assert_contains('Luna publicării, doar în exportul curent', $slice, 'the monthly chart is labelled as current-export only');
    assert_contains('Nu este istoric', $slice);
    assert_contains('subestimate', $slice, 'old months are said to be understated');
    preg_match_all('#<span class="text-ink-muted font-mono">(\d+)</span>#', substr($html, strpos($html, 'id="luna-export"')), $m);
    $in_chart = array_sum(array_map('intval', array_slice($m[1], 0, 12)));
    assert_true($in_chart > 0 && $in_chart <= 37, "the undated row is left out of the monthly bars ($in_chart)");
    assert_not_contains('Fatal error', $html);
    assert_not_contains('stats.php', $log, 'server log: nothing from this page');
    assert_not_contains('no such table', $log, 'server log');
    assert_not_contains('helpers.php', $log, 'server log: no failures from the history reader');
});

run_suite('an aggregate layout this code does not know is treated as unavailable', function () use ($tmp) {
    [, $x] = stats_page("$tmp-v2.sqlite", fn(PDO $p) => $p->exec('UPDATE publication_monthly SET agg_version = 2'));
    assert_contains('Istoric indisponibil', section_text($x, 'istoric'), 'version 2 rows are not read as version 1');
    assert_true($x->query('//*[@id="luna-export"]')->length === 1, 'falls back to the labelled current-export chart');
});

run_suite('a long history shows 24 months and sums the rest', function () use ($tmp) {
    [, $x] = stats_page("$tmp-long.sqlite", function (PDO $p) {
        $p->exec('DELETE FROM publication_monthly');
        $ins = $p->prepare('INSERT INTO publication_monthly VALUES (1, ?, 1, ?, 0)');
        for ($i = 0; $i < 30; $i++) {   // 2024-05 .. 2026-10, 2 a month
            $ins->execute([date('Y-m', mktime(0, 0, 0, 5 + $i, 1, 2024)), 2]);
        }
    });
    $months = section_text($x, 'luna-istoric');
    assert_same(24, $x->query('//*[@id="luna-istoric"]//span[contains(@class,"font-mono") and contains(@class,"text-ink-muted") and string-length(normalize-space(.)) > 4 and contains(., "-")]')->length, 'window of 24 bars');
    assert_contains('Înainte de 2024-11: 12 anunțuri', $months, 'the 6 older months are one summed line');
    assert_not_contains('2024-10', substr($months, 0, strpos($months, 'Înainte')), 'older months are not drawn');
    assert_same('60', card_number($x, 'Anunțuri publicate, în total'), '30 months x 2');
});

foreach (glob("$tmp-*") ?: [] as $f) @unlink($f);
finish();
