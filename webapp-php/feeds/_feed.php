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
    $stmt = db()->prepare("SELECT j.*, e.name AS employer_name_disp, jd.name AS judet_name_disp
        FROM job_postings j $join
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
            ? 'Termen estimat (data expirării anunțului): ' . $dl['date']
            : 'Termen depunere: ' . $dl['date'];
    } else {
        $parts[] = 'Termen depunere: neprecizat';
    }
    return $parts;
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

/** Publication and last-change instants for a row, from stored facts only. */
function feed_row_times(array $r): array {
    $pub = feed_date($r['published_at'] ?? null);
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
