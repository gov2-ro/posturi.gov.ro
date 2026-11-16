<?php
/**
 * UX-04-FEEDS — helpers shared by the Atom, RSS and iCal handlers.
 *
 * Not a feed framework: it holds the one query selection, the one summary text
 * and the XML/iCal text rules the three formats must agree on. Loaded by the
 * handlers after the front controller has validated the query.
 */

/**
 * Rows for a feed: the same build_filters()/FTS selection the list uses, then
 * a format-specific order and cap. `$dated_only` is the calendar's "has a
 * date" restriction — it is applied after the filters, never instead of them.
 */
function feed_rows(string $order, int $limit, bool $dated_only = false): array {
    $f = build_filters($_GET);
    $w = $f['where'];
    $b = $f['binds'];
    $join = '';
    if ($f['fts'] && ($q = trim((string)($_GET['q'] ?? '')))) {
        $join = "JOIN job_postings_fts fts ON fts.rowid = j.id";
        $w[] = "job_postings_fts MATCH ?";
        $b[] = fts_query($q);
    }
    if ($dated_only) $w[] = "j.apply_deadline IS NOT NULL";
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $stmt = db()->prepare("SELECT j.*, e.name AS employer_name_disp, jd.name AS judet_name_disp,
               t.schema_json, t.sal_json, t.attachment_meta, t.v3_bibliography
        FROM job_postings j $join
        LEFT JOIN posting_text t ON t.posting_id = j.id
        LEFT JOIN employers e ON e.id = j.employer_id
        LEFT JOIN judete jd ON jd.id = j.judet_id
        $where ORDER BY $order LIMIT " . (int)$limit);
    $stmt->execute($b);
    return $stmt->fetchAll();
}

/** Newest-first selection shared by RSS and Atom. */
function feed_item_rows(): array {
    return feed_rows('j.published_at DESC, j.id DESC', FEED_ITEM_LIMIT);
}

/** Channel/feed title, subtitle and HTML alternate; shared by RSS and Atom. */
function feed_channel(): array {
    $scope = feed_employer($_GET);
    $active = [];
    foreach ($_GET as $k => $v) {
        if (in_array($k, ['page', 'sort', 'title'], true)) continue;
        if (is_array($v) ? count($v) > 0 : trim((string)$v) !== '') $active[] = $k;
    }
    $filtered = $active !== [];
    $title = $scope ? 'posturi.gov2.ro — ' . $scope['name'] : 'posturi.gov2.ro';
    // An employer-only feed is already named for the employer; anything else
    // narrows the selection, and the title must not pass it off as the whole site.
    if (array_diff($active, ['employer']) !== []) $title .= ' — căutare filtrată';
    $subtitle = $scope
        ? 'Anunțuri de angajare — ' . $scope['name']
        : 'Anunțuri de angajare în sectorul public din România';
    if ($filtered) $subtitle .= ' (doar anunțurile care corespund filtrelor; cele mai noi ' . FEED_ITEM_LIMIT . ')';
    $origin = site_origin();
    // The id/alternate of the feed as it was before: existing readers key the
    // feed on it, so it must not start following the filters.
    $legacy_alt = $scope ? $origin . '/angajator/' . rawurlencode($scope['slug']) . '/' : $origin . '/';
    $params = $_GET;
    unset($params['page'], $params['sort'], $params['title']);
    $qs = http_build_query($params);
    return [
        'title' => $title, 'subtitle' => $subtitle, 'id' => $legacy_alt,
        'alternate' => $origin . '/' . ($qs ? '?' . $qs : ''),
        'origin' => $origin,
    ];
}

/** Local detail page, absolute. */
function feed_local_url(array $r): string {
    return site_origin() . job_url($r);
}

/** Item title: "Titlu — Angajator". */
function feed_item_title(array $r): string {
    $employer = $r['employer_name_disp'] ?? $r['employer_name'] ?? '';
    return trim($r['title'] . ($employer ? ' — ' . $employer : ''));
}

/** Application status in words, from the export's vocabulary. */
function feed_status_label(array $r): string {
    return match ((string)($r['application_status'] ?? '')) {
        'confirmed_open' => 'înscrieri deschise',
        'unconfirmed'    => 'termen neconfirmat',
        'closed'         => 'înscrieri închise',
        'unknown'        => 'termen neprecizat',
        default          => 'nespecificată',
    };
}

/**
 * The plain-text summary lines for RSS and Atom: place/type/category, status,
 * the resolved deadline (expiry-only dates qualified as estimates) and the
 * official source. Plain text only; each format escapes it for its own syntax.
 */
function feed_summary_lines(array $r): array {
    $parts = [];
    $judet = $r['judet_name_disp'] ?? $r['judet_name'] ?? '';
    if ($judet) $parts[] = 'Județ: ' . $judet;
    if ($r['job_type']) $parts[] = 'Tip: ' . $r['job_type'];
    if ($r['categorie']) $parts[] = 'Categorie: ' . $r['categorie'];
    $parts[] = 'Stare: ' . feed_status_label($r);
    $dl = posting_deadline($r);
    if ($dl['date']) {
        $parts[] = $dl['source'] === 'expirare'
            ? 'Termen estimat (data expirării anunțului): ' . fmt_date($dl['date'])
            : 'Termen depunere: ' . fmt_date($dl['date']);
    } else {
        $parts[] = 'Termen depunere: neprecizat';
    }
    return $parts;
}

/** The `calendar_events` rows of all the given postings in one query, by posting id. */
function feed_calendar_events(array $rows): array {
    $ids = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $rows)));
    if (!$ids) return [];
    $out = [];
    $q = db()->query('SELECT * FROM calendar_events WHERE posting_id IN (' . implode(',', $ids) . ') ORDER BY data, id');
    foreach ($q->fetchAll() as $ev) $out[(int)$ev['posting_id']][] = $ev;
    return $out;
}

const FEED_TEXT_CAP = 600;
const FEED_CONTINUES = '… (continuarea pe pagina anunțului)';

/** Add a fact unless it has no value. A repeated label gets `$dup` (or " (2)", " (3)"…). */
function feed_put(array &$facts, string $label, string $value, string $dup = ''): void {
    $value = trim($value);
    if ($label === '' || $value === '' || $value === '—') return;
    $k = $label;
    if (isset($facts[$k]) && $dup !== '') $k = $label . $dup;
    for ($i = 2; isset($facts[$k]); $i++) $k = $label . ' (' . $i . ')';
    $facts[$k] = $value;
}

/** Plain text of a rendered section: lists as "- " lines, no tags, no entities. */
function feed_html_to_text(string $html): string {
    $t = preg_replace('#<li[^>]*>#i', "\n- ", $html) ?? '';
    $t = preg_replace('#</(p|li|h[1-6]|ul|ol|tr|div)>|<br\s*/?>#i', "\n", $t) ?? '';
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $t = preg_replace('/[ \t]+/u', ' ', $t) ?? '';
    $t = preg_replace('/ ?\n ?/', "\n", $t) ?? '';
    return trim(preg_replace('/\n{2,}/', "\n", $t) ?? '');
}

/** Cut a text at about `$cap` characters, on a word boundary; add the continuation when cut. */
function feed_cap_text(string $s, int $cap): string {
    $s = trim($s);
    if (mb_strlen($s) <= $cap) return $s;
    $cut = mb_substr($s, 0, $cap);
    if (preg_match('/^(.*\S)\s+\S*$/su', $cut, $m) && mb_strlen($m[1]) > $cap / 2) $cut = $m[1];
    return rtrim($cut, " \n,;:-–—") . FEED_CONTINUES;
}

/**
 * The detail page's facts for one posting as ordered plain-text groups: the parsed page,
 * the competition calendar, the automatic extraction and the long text sections. Each group
 * is ['key', 'heading', 'facts' => [label => value]] or ['key', 'heading', 'text']. A fact
 * with no value is left out. Section text is whole; the renderers cut it.
 */
function feed_details(array $r, array $events): array {
    $groups = [];
    $schema = ($r['schema_json'] ?? null) ? (json_decode((string)$r['schema_json'], true) ?: []) : [];
    $has_schema = !empty($r['schema_json']);

    // 1. What the official page says (parsed, no LLM).
    $f = [];
    foreach (feed_summary_lines($r) as $line) {
        [$k, $v] = array_pad(explode(': ', $line, 2), 2, '');
        feed_put($f, $k, $v);
    }
    feed_put($f, 'Localitate', (string)($r['locality'] ?? ''));
    if ((int)($r['nr_posturi'] ?? 0) > 0) feed_put($f, 'Nr. posturi', (string)(int)$r['nr_posturi']);
    feed_put($f, 'Ocupație', (string)($r['occ_canonical'] ?? ''));
    feed_put($f, 'Persoană de contact', (string)($r['contact_person'] ?? ''));
    feed_put($f, 'Telefon', (string)($r['contact_phone'] ?? ''));
    feed_put($f, 'Email', (string)($r['contact_email'] ?? ''));
    feed_put($f, 'Publicat', fmt_date($r['published_at'] ?? null));
    feed_put($f, 'Expiră', fmt_date($r['expires_at'] ?? null));
    $att = [];
    foreach (attachment_list($r) as $a) $att[] = $a['label'] . ' (' . $a['ext'] . ($a['size'] ? ', ' . $a['size'] : '') . ')';
    feed_put($f, 'Atașamente', implode('; ', $att));
    $groups[] = ['key' => 'official', 'heading' => 'Din pagina oficială', 'facts' => $f];

    // 2. The competition calendar: the scraped rows, else the extracted ones.
    $cal = [];
    foreach ($events as $ev) {
        feed_put($cal, (string)$ev['eveniment'], trim(fmt_date($ev['data']) . ' ' . (string)($ev['ora'] ?? '')));
    }
    $heading = 'Calendarul concursului';
    if (!$cal && $has_schema) {
        foreach (llm_calendar_rows($schema) as $row) {
            $when = $row['date'] !== '' ? trim(fmt_date($row['date']) . ' ' . (string)$row['time']) : '';
            // The source wording often only repeats the date ("Proba scrisă: în data de
            // 09.12.2026, ora 10,00"). Keep it only when it does not hold the date already.
            $detail = trim((string)$row['detail']);
            if ($when !== '' && $detail !== '' && (str_contains($detail, fmt_date($row['date'])) || str_contains($detail, $row['date']))) $detail = '';
            feed_put($cal, $row['label'], trim($when . ($detail !== '' ? ($when !== '' ? ' — ' : '') . $detail : '')));
        }
        if ($cal) $heading .= ' (extras automat)';
    }
    if ($cal) $groups[] = ['key' => 'calendar', 'heading' => $heading, 'facts' => $cal];

    if (!$has_schema) return $groups;

    // 3. What the automatic extraction deduced.
    $x = [];
    foreach (posting_inferred_conditions($r) as $k => $v) feed_put($x, $k, $v);
    foreach (inferred_tags($r) as $g) {
        feed_put($x, $g['label'], implode(', ', array_map(fn($v) => ucfirst((string)$v), $g['values'])));
    }
    foreach (posting_contract_terms($schema) as $k => $v) feed_put($x, $k, (string)$v, ' (contract)');
    $sal = ($r['sal_json'] ?? null) ? (json_decode((string)$r['sal_json'], true) ?: []) : [];
    if (!empty($sal['lei_min'])) {
        $lo = number_format((int)$sal['lei_min'], 0, ',', '.');
        $hi = number_format((int)($sal['lei_max'] ?? $sal['lei_min']), 0, ',', '.');
        feed_put($x, 'Salariu estimat', ($lo === $hi ? $lo : $lo . '–' . $hi) . ' lei brut / lună, la gradația 0');
        feed_put($x, 'Net estimat', net_label((float)$sal['lei_min'], (float)($sal['lei_max'] ?? $sal['lei_min'])));
        feed_put($x, 'Despre estimare', (string)($sal['disclaimer'] ?? ''));
    }
    $edu = is_array($schema['education'] ?? null) ? $schema['education'] : [];
    if (!empty($edu['eqf_level'])) feed_put($x, 'Nivel de studii (EQF)', EQF_LABELS[(int)$edu['eqf_level']] ?? ('EQF ' . (int)$edu['eqf_level']));
    $fields = array_filter(array_map(fn($f) => is_array($f) ? ucfirst(trim((string)($f['label_ro'] ?? ''))) : '', (array)($edu['fields_of_study'] ?? [])));
    feed_put($x, 'Domenii de studii', implode(', ', $fields));
    $exp = role_experience_label(is_array($schema['experience'] ?? null) ? $schema['experience'] : null);
    feed_put($x, 'Experiență cerută', ucfirst(preg_replace('/^Experiență:\s*/u', '', $exp) ?? $exp));
    $bib = $schema['bibliography_topics'] ?? json_decode((string)($r['v3_bibliography'] ?? '[]'), true) ?: [];
    $bib = array_slice(array_values(array_filter(array_map(fn($t) => trim((string)$t), (array)$bib), fn($t) => $t !== '')), 0, 15);
    feed_put($x, 'Tematica examenului', implode(', ', $bib));
    if ($x) $groups[] = ['key' => 'extracted', 'heading' => 'Extras automat', 'facts' => $x];

    // 4. The long text sections.
    foreach (render_schema_sections((string)$r['schema_json']) ?? [] as $sec) {
        $t = feed_html_to_text($sec['html']);
        if ($t !== '') $groups[] = ['key' => 'text', 'heading' => $sec['label'], 'text' => $t];
    }
    return $groups;
}

/** The one note on how far to trust the item: no extraction, an older revision, or the usual caveat. */
function feed_extraction_note(array $r): string {
    if (empty($r['schema_json'])) {
        return 'Extragerea automată nu a rulat încă pentru acest anunț. Afișăm doar datele din pagina oficială.';
    }
    $rev = trim((string)($r['schema_source_revision'] ?? ''));
    // Both hashes must be known: an empty page hash (legacy row) is "unknown", not "changed".
    $hash = trim((string)($r['detail_content_hash'] ?? ''));
    if ($rev !== '' && $hash !== '' && $rev !== $hash) {
        return 'Datele extrase automat provin dintr-o versiune anterioară a anunțului. Pot fi incomplete sau greșite.';
    }
    return 'Datele marcate «extras automat» sunt deduse de un program din textul anunțului. Pot fi incomplete sau greșite. Textul oficial este pe posturi.gov.ro.';
}

/** The groups as HTML for RSS and Atom. Every value is escaped; no model HTML passes through. */
function feed_details_html(array $groups, string $note): string {
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $out = '<p><em>' . $h($note) . '</em></p>';
    foreach ($groups as $g) {
        $out .= '<h3>' . $h($g['heading']) . '</h3>';
        if (isset($g['facts'])) {
            if (!$g['facts']) continue;
            $out .= '<ul>';
            foreach ($g['facts'] as $k => $v) $out .= '<li><b>' . $h((string)$k) . ':</b> ' . $h((string)$v) . '</li>';
            $out .= '</ul>';
        } else {
            $out .= '<p>' . nl2br($h(feed_cap_text($g['text'], FEED_TEXT_CAP)), false) . '</p>';
        }
    }
    return $out;
}

/** The groups as plain lines for iCal; the caller escapes and folds them. */
function feed_details_text(array $groups, string $note, int $text_cap): array {
    $lines = [];
    foreach ($groups as $g) {
        if (isset($g['facts']) && !$g['facts']) continue;
        $lines[] = '';
        $lines[] = $g['heading'];
        if (isset($g['facts'])) {
            foreach ($g['facts'] as $k => $v) $lines[] = $k . ': ' . $v;
        } else {
            foreach (explode("\n", feed_cap_text($g['text'], $text_cap)) as $l) $lines[] = $l;
        }
    }
    $lines[] = '';
    $lines[] = $note;
    return $lines;
}

function feed_source_line(array $r): string {
    return 'Sursa oficială (posturi.gov.ro): ' . $r['url'];
}

/**
 * A stored date (the export keeps date precision) as midnight in Bucharest,
 * with a real UTC offset — never a bare `Z` that claims a different instant.
 * Null when the value is absent or unparseable.
 */
function feed_date(?string $s): ?DateTimeImmutable {
    $s = substr(trim((string)$s), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    try {
        return new DateTimeImmutable($s . ' 00:00:00', new DateTimeZone('Europe/Bucharest'));
    } catch (Exception $e) {
        return null;
    }
}

/**
 * The instant the pipeline first saw the posting (`created_at`, full timestamp
 * with offset), in Bucharest time. Null when absent or date-only.
 */
function feed_first_seen(?string $s): ?DateTimeImmutable {
    $s = trim((string)$s);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $s)) return null;
    try {
        return (new DateTimeImmutable($s, new DateTimeZone('Europe/Bucharest')))->setTimezone(new DateTimeZone('Europe/Bucharest'));
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Publication and last-change instants for a row, from stored facts only.
 * posturi.gov.ro gives a publication date with no time (its API says 00:00).
 * When the pipeline first saw the posting on that same day, the time of that
 * run is used, so items from the morning and the evening run differ. A posting
 * seen on a later day (a backfill, a missed run) keeps the bare date.
 */
function feed_row_times(array $r): array {
    $pub = feed_date($r['published_at'] ?? null);
    $seen = feed_first_seen($r['created_at'] ?? null);
    if ($pub !== null && $seen !== null && $seen->format('Y-m-d') === $pub->format('Y-m-d')) $pub = $seen;
    $upd = feed_date($r['updated_at'] ?? null) ?? $pub;
    if ($pub === null) $pub = $upd;
    if ($upd !== null && $pub !== null && $upd < $pub) $upd = $pub;
    return [$pub, $upd];
}

/** Newest change among the rows, else the database build time; null if neither is known. */
function feed_updated(array $rows): ?DateTimeImmutable {
    $max = null;
    foreach ($rows as $r) {
        [, $u] = feed_row_times($r);
        if ($u !== null && ($max === null || $u > $max)) $max = $u;
    }
    if ($max !== null) return $max;
    $built = build_meta()['built_at'] ?? null;
    if ($built) {
        try { return (new DateTimeImmutable($built, new DateTimeZone('UTC'))); } catch (Exception $e) {}
    }
    return null;
}

/**
 * Text for an XML node or attribute: invalid UTF-8 and the control characters
 * XML 1.0 forbids are dropped (a single NUL would make the whole feed
 * unparseable), then markup characters are escaped.
 */
function xml_text(mixed $v): string {
    $s = (string)($v ?? '');
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

// ---- iCalendar (RFC 5545) ----

/** Escape a TEXT value: backslash, semicolon, comma, newlines. Other controls are dropped. */
function ical_text(string $s): string {
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $s) ?? '';
    return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $s);
}

/**
 * A URI value. Not TEXT: backslashes, commas and semicolons are legal and must
 * not be escaped, or the link is corrupted. Octets outside the URI character
 * set (non-ASCII, space, controls, quotes, angle brackets…) are percent-encoded
 * as UTF-8; existing %XX escapes and reserved characters are left alone.
 */
function ical_uri(string $s): string {
    return preg_replace_callback('/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]/', fn($m) => rawurlencode($m[0]), $s) ?? '';
}

/**
 * Fold one content line at 75 octets, CRLF-terminated. Never splits a UTF-8
 * sequence; every continuation starts with one space, which counts toward
 * its 75 octets.
 */
function ical_fold(string $line): string {
    if (strlen($line) <= 75) return $line . "\r\n";
    $out = '';
    $cur = '';
    $limit = 75;
    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if (strlen($cur) + strlen($ch) > $limit) {
            $out .= $cur . "\r\n";
            $cur = ' ';
            $limit = 75;
        }
        $cur .= $ch;
    }
    return $out . $cur . "\r\n";
}
