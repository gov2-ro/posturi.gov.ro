<?php
/**
 * UX-11 checklist fixture. Built on top of the plain 38-posting fixture (build_fixture_db($path)),
 * so every count other suites pin is untouched: it ADDS postings 1101-1107 to a database of its
 * own and is used only by tests/checklist_test.php and tests/browser/checklist.spec.js.
 *
 *   php tests/fixtures/make_checklist.php /tmp/x.sqlite a    # revision A
 *   php tests/fixtures/make_checklist.php /tmp/y.sqlite b    # the same postings after a source revision
 *
 * Variant B differs from A only in 1101 (new detail_content_hash, one document reworded, one
 * removed, one added, the interview moved) and in 1107 (one duplicate document fewer). Today is
 * 2026-10-03 (POSTURI_TODAY).
 *
 *   1101  documents + calendar + exact-hour deadline; the revision case
 *   1102  hostile / nested / ordered / introduction-line documents; estimated (expiry) deadline
 *   1103  no documents extracted; deadline and a stage already passed; one stage still ahead
 *   1104  no schema and no content hash: the revision is unknown
 *   1105  `</script>` and quotes in a document and in a stage label; a 400-character document
 *   1106  nothing extracted, no deadline at all
 *   1107  three identical document labels
 */

require_once __DIR__ . '/build_db.php';

function build_checklist_fixture(string $path, string $variant = 'a'): void {
    if (!in_array($variant, ['a', 'b'], true)) throw new InvalidArgumentException('variant must be a or b');
    build_fixture_db($path, false, false, false);   // rows are added below, then split
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $b = $variant === 'b';

    $postings = [];
    $docs = $b
        ? "- Cerere de înscriere;\n- Copie după cartea de identitate;\n- Copie legalizată după diploma de studii;\n- Curriculum vitae;\n- Cazier judiciar"
        : "- Cerere de înscriere;\n- Copie după cartea de identitate;\n- Copie după diploma de studii;\n- Curriculum vitae;\n- Adeverință medicală";
    $postings[1101] = [
        'title' => 'Referent cu dosar (checklist)', 'hash' => ($b ? 'b' : 'a') . '1101' . str_repeat('0', 19),
        'schema' => ['application_docs' => $docs, 'responsibilities' => "- Evidență documente"],
        'deadline' => '2026-10-16', 'source' => 'anunt', 'exact' => '2026-10-16 14:30:00', 'status' => 'confirmed_open',
        'events' => [['Depunere dosare', '2026-10-16', '14:30'], ['Probă scrisă', '2026-10-28', '10:00'],
            ['Interviu', $b ? '2026-11-05' : '2026-11-03', '09:00']],
    ];
    $postings[1102] = [
        'title' => 'Referent cu documente neobișnuite', 'hash' => 'h1102' . str_repeat('0', 19),
        'schema' => ['application_docs' => "Documente necesare:\n1. <script>alert('x')</script> cerere **tip**\n"
            . "2. Copie [CI](javascript:alert(1)) & _anexe_\n   - fața\n   - verso\n* Dosar cu șină"],
        'deadline' => '2026-10-20', 'source' => 'expirare', 'exact' => null, 'status' => 'unconfirmed',
        'events' => [],
    ];
    $postings[1103] = [
        'title' => 'Inspector fără listă de documente', 'hash' => 'c1103' . str_repeat('0', 19),
        'schema' => ['responsibilities' => "- Control"],
        'deadline' => '2026-09-30', 'source' => 'anunt', 'exact' => '2026-09-30 12:00:00', 'status' => 'closed',
        'events' => [['Depunere dosare', '2026-09-30', '12:00'], ['Interviu', '2026-10-20', null]],
    ];
    $postings[1104] = [
        'title' => 'Muncitor cu versiune necunoscută', 'hash' => '', 'schema' => null,
        'deadline' => '2026-10-16', 'source' => 'anunt', 'exact' => null, 'status' => 'confirmed_open',
        'events' => [['Probă scrisă', '2026-10-28', '10:00']],
    ];
    $postings[1105] = [
        'title' => 'Text periculos în listă', 'hash' => 'd1105' . str_repeat('0', 19),
        'schema' => ['application_docs' => "- </script><script>window.__pwned=1</script>\n- Cod \"citat\" & 'apostrof' <b>bold</b>\n- "
            . trim(str_repeat('lung ', 80))],
        'deadline' => '2026-10-25', 'source' => 'concurs', 'exact' => null, 'status' => 'confirmed_open',
        'events' => [['</script><img src=x onerror=alert(2)>', '2026-11-01', null]],
    ];
    $postings[1106] = [
        'title' => 'Fără nicio extragere', 'hash' => 'e1106' . str_repeat('0', 19), 'schema' => null,
        'deadline' => null, 'source' => '', 'exact' => null, 'status' => 'unknown', 'events' => [],
    ];
    $postings[1107] = [
        'title' => 'Documente identice repetate', 'hash' => ($b ? 'b' : 'a') . '1107' . str_repeat('0', 19),
        'schema' => ['application_docs' => $b ? "- Copie CI\n- Copie CI" : "- Copie CI\n- Copie CI\n- Copie CI"],
        'deadline' => '2026-10-18', 'source' => 'anunt', 'exact' => null, 'status' => 'confirmed_open', 'events' => [],
    ];

    $ins = $db->prepare("INSERT INTO job_postings (id, url, title, employer_id, employer_name, judet_id, judet_name,
        judet_slug, locality, published_at, expires_at, job_level, job_type, categorie, body_markdown, detail_content_hash,
        schema_json, schema_source_revision, schema_prompt_version, data_limita_depunere, apply_deadline, deadline_source,
        application_status) VALUES (?, ?, ?, 1, 'Spitalul Clinic Județean Cluj', 1, 'Cluj', 'cluj', 'Cluj-Napoca',
        '2026-09-30 09:00:00', ?, 'executie', 'Permanent', 'Personal contractual', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $cal = $db->prepare('INSERT INTO calendar_events (posting_id, eveniment, data, ora) VALUES (?, ?, ?, ?)');
    $fts = $db->prepare('INSERT INTO job_postings_fts (rowid, title, employer_name, judet_name, body_text) VALUES (?, ?, ?, ?, ?)');
    foreach ($postings as $id => $x) {
        $schema = $x['schema'] === null ? null : json_encode($x['schema'], JSON_UNESCAPED_UNICODE);
        $ins->execute([
            $id, "https://posturi.gov.ro/anunt/fixture-$id", $x['title'],
            $x['deadline'] === null ? null : $x['deadline'] . ' 23:59:59', 'Anunț de test pentru lista de pregătire.',
            $x['hash'], $schema, $schema === null ? '' : 'f' . $id . str_repeat('0', 20), $schema === null ? '' : 'v4',
            $x['exact'], $x['deadline'], $x['source'], $x['status'],
        ]);
        foreach ($x['events'] as [$name, $date, $time]) $cal->execute([$id, $name, $date, $time]);
        $fts->execute([$id, $x['title'], 'Spitalul Clinic Județean Cluj', 'Cluj', 'Anunț de test pentru lista de pregătire.']);
    }

    // posting_roles, the way the export writes it, for the added postings.
    $ins_r = $db->prepare('INSERT INTO posting_roles (posting_id, ' . implode(', ', FIXTURE_ROLE_COLUMNS)
        . ') VALUES (?, ' . implode(', ', array_fill(0, count(FIXTURE_ROLE_COLUMNS), '?')) . ')');
    $rows = $db->query('SELECT * FROM job_postings WHERE id BETWEEN 1101 AND 1199 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        foreach (fixture_role_rows($row) as $role) {
            $ins_r->execute(array_merge([$row['id']], array_map(fn($c) => $role[$c], FIXTURE_ROLE_COLUMNS)));
        }
    }
    $n = (int)$db->query('SELECT COUNT(*) FROM job_postings')->fetchColumn();
    $ev = (int)$db->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn();
    $db->exec("UPDATE build_meta SET job_postings = $n, calendar_events = $ev WHERE id = 1");
    fixture_split_posting_text($db);
}
