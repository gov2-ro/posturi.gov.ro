<?php
/**
 * fts_query(): a quoted part is one FTS5 phrase (owner report 2026-11-24: "manager proiect"
 * matched postings with both words anywhere). Loose words stay AND-ed, the last one a prefix.
 *
 * Usage: php tests/fts_query_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';

run_suite('fts_query() keeps quoted phrases together', function () {
    assert_same('"manager proiect"', fts_query('"manager proiect"'), 'straight quotes');
    assert_same('"manager de proiect" AND "București" *', fts_query('„manager de proiect” București'), 'Romanian quotes, then a loose word');
    assert_same('"manager" AND "proiect" *', fts_query('manager proiect'), 'no quotes: AND, last word a prefix');
    assert_same('"medic"', fts_query('"medic"'), 'one quoted word: exact, no prefix');
    assert_same('"medic" *', fts_query('"medic'), 'an unclosed quote is loose text: a prefix, as while typing');
    assert_same('', fts_query('""'), 'empty quotes');
    assert_same('', fts_query('  '), 'blank');
});

run_suite('fts_query() phrases match adjacent words only', function () {
    $db = new PDO('sqlite::memory:');
    $db->exec("CREATE VIRTUAL TABLE t USING fts5(body, tokenize='unicode61 remove_diacritics 2')");
    $db->exec("INSERT INTO t(rowid, body) VALUES (1, 'Manager proiect'), (2, 'Impresar. Raportează managerului. Detalii proiect.'),
        (3, 'Manager de proiect')");
    $ids = static function (string $q) use ($db): array {
        $st = $db->prepare('SELECT rowid FROM t WHERE t MATCH ? ORDER BY rowid');
        $st->execute([fts_query($q)]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    };
    assert_same([1], $ids('"manager proiect"'), 'the exact phrase');
    assert_same([3], $ids('„manager de proiect”'), 'the phrase with "de"');
    assert_same([1, 3], $ids('manager proiect'), 'loose words: both, anywhere');
});

finish();
