<?php
/**
 * Shared helpers for the PHP webapp.
 */

// ---- Markdown rendering ----

/**
 * Markdown → HTML. Parsedown is bundled (see Parsedown.php); when it is absent
 * a minimal fallback covers paragraphs and emphasis.
 *
 * Parsedown safe mode is deliberately OFF: the announcement bodies arrive from
 * a DOCX→Markdown conversion whose fallback path can emit raw HTML (tables,
 * images), and safe mode would print those tags as literal text. The security
 * boundary is NOT Parsedown — every rendered path below passes through
 * sanitize_html(), whose allowlist is what strips script-bearing markup.
 */
function markdown_to_html(string $text): string {
    if (trim($text) === '') return '';
    if (class_exists('Parsedown')) {
        static $pd = null;
        if ($pd === null) { $pd = new Parsedown(); }
        return $pd->text($text);
    }
    return markdown_fallback_to_html($text);
}

/**
 * Minimal fallback: paragraphs and emphasis only, everything escaped. Named
 * separately so the fixture tests can exercise it without unloading Parsedown.
 */
function markdown_fallback_to_html(string $text): string {
    $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Paragraphs: blank lines
    $html = preg_replace('/\n{2,}/', '</p><p>', $html);
    $html = '<p>' . $html . '</p>';
    // Bold **text**
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    // Italic *text*
    $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html);
    // Line breaks within paragraph
    $html = str_replace("\n", '<br>', $html);
    return $html;
}

// ---- HTML sanitization ----

/**
 * The one sanitizer every rendered path must go through. Parser-based
 * (HTMLPurifier v4.19.1, LGPL-2.1+, vendored under lib/htmlpurifier-library/
 * — see lib/htmlpurifier-LICENSE.txt). `strip_tags()` passed attributes and
 * URLs through untouched; this validates elements, attributes and URL
 * schemes, so an `<a onclick>` or a `javascript:` href cannot survive from
 * scraped or model-produced text. lib/ ships with the normal rsync deploy.
 */
function sanitizer(): HTMLPurifier {
    static $purifier = null;
    if ($purifier === null) {
        $auto = __DIR__ . '/lib/htmlpurifier-library/HTMLPurifier.auto.php';
        if (!class_exists('HTMLPurifier') && file_exists($auto)) {
            require_once $auto;
        }
        if (!class_exists('HTMLPurifier')) {
            throw new RuntimeException('HTMLPurifier missing — vendored copy expected under lib/htmlpurifier-library/');
        }
        $config = HTMLPurifier_Config::createDefault();
        // Same elements the strip_tags() allowlist admitted, plus the attributes
        // real announcement tables need. Everything else is removed.
        $config->set('HTML.Allowed',
            'p,br,ul,ol,li,strong,b,em,i,h1,h2,h3,h4,h5,h6,'
            . 'table,thead,tbody,tr,th[scope|colspan|rowspan],td[colspan|rowspan],'
            . 'blockquote,pre,code,a[href|title|target|rel]');
        // Document links only. Relative and fragment URLs stay allowed (no
        // URI.MakeAbsolute); script/data schemes — encoded or not — do not.
        $config->set('URI.AllowedSchemes', [
            'http' => true, 'https' => true, 'mailto' => true, 'tel' => true,
        ]);
        // A link that does open a new tab must not inherit this page.
        $config->set('Attr.AllowedFrameTargets', ['_blank' => true, '_self' => true, '_top' => true]);
        $config->set('Attr.AllowedRel', ['noopener' => true, 'noreferrer' => true, 'nofollow' => true, 'external' => true]);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        // Belt and braces on top of the allowlist: never keep these anywhere.
        $config->set('HTML.ForbiddenAttributes', ['style' => true, 'class' => true, 'id' => true]);
        $purifier = new HTMLPurifier($config);
    }
    return $purifier;
}

/** Sanitize a rendered HTML fragment with the parser-based allowlist. */
function sanitize_html(string $html): string {
    if (trim($html) === '') return '';
    return sanitizer()->purify($html);
}

/** Markdown → sanitized HTML. The only renderer callers should use. */
function render_markdown(string $text): string {
    return sanitize_html(markdown_to_html($text));
}

// ---- Date helpers ----

/**
 * Today in Europe/Bucharest. Every "still open?" comparison and countdown must
 * use this — the shared host's clock may sit in any timezone, and a deadline
 * must flip at Bucharest midnight, not at 21:00 or 02:00 local time.
 *
 * POSTURI_TODAY (a Y-m-d string) fixes the clock for the fixture tests; it is
 * unset in production.
 */
function ro_today(): string {
    $fixed = getenv('POSTURI_TODAY');
    if ($fixed !== false && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fixed)) {
        return $fixed;
    }
    return (new DateTimeImmutable('today', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
}

/**
 * The one application deadline a posting is judged by, plus where it came from.
 *
 * `apply_deadline` is already resolved at export time (v4 calendar, then the
 * scraped card field, then the announcement-expiry fallback), so this returns
 * that date with the stored `deadline_source`; for old exports without the
 * column it infers `expirare` when the date is exactly the expiry. Returns
 * `['date' => ?string, 'source' => ?string]` — source is one of
 * `concurs|anunt|expirare` or null. Callers must render the qualification for
 * `expirare`; never present it as a confirmed deadline.
 */
function posting_deadline(array $p): array {
    $date = $p['apply_deadline'] ?? null;
    if ($date === null || $date === '') {
        if (!empty($p['expires_at'])) {
            return ['date' => substr((string)$p['expires_at'], 0, 10), 'source' => 'expirare'];
        }
        return ['date' => null, 'source' => null];
    }
    $date = substr((string)$date, 0, 10);
    $source = (string)($p['deadline_source'] ?? '');
    if (!in_array($source, ['concurs', 'anunt', 'expirare'], true)) {
        $ends = $p['expires_at'] ? substr((string)$p['expires_at'], 0, 10) : null;
        $source = ($ends !== null && $date === $ends) ? 'expirare' : null;
    }
    return ['date' => $date, 'source' => $source];
}

/** Whole days from Bucharest-today to the deadline; negative when past. */
function days_until(?string $date_str): ?int {
    if (!$date_str) return null;
    try {
        $tz = new DateTimeZone('Europe/Bucharest');
        $d = new DateTimeImmutable(substr($date_str, 0, 10), $tz);
        // ro_today(), not `new DateTimeImmutable('today')`: one source of
        // "today" for every countdown, sort window and status filter — and the
        // POSTURI_TODAY test seam. Two day-boundary computations that can
        // disagree are exactly the bug class FIX-02 exists to remove.
        $today = new DateTimeImmutable(ro_today(), $tz);
        // $d->diff($today) is the interval *from the deadline back to today*,
        // so invert=1 means the deadline is still ahead of us.
        $diff = $d->diff($today);
        return $diff->invert ? $diff->days : -$diff->days;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Romanian day count: 1 zi · 2–19 zile · 20+ de zile.
 * (The "de" is required from 20 up, and again above 100 for 120, 121, …)
 */
function days_label(int $n): string {
    if ($n === 1) return '1 zi';
    $mod = $n % 100;
    return ($mod === 0 || ($mod >= 20)) ? "$n de zile" : "$n zile";
}

/** Romanian posting count: 1 anunț · 2–19 anunțuri · 20+ de anunțuri. */
function postings_label(int $n): string {
    if ($n === 1) return '1 anunț';
    $mod = $n % 100;
    return ($mod === 0 || $mod >= 20) ? "$n de anunțuri" : "$n anunțuri";
}

/** Romanian count of schema extractions: 1 extragere · 2–19 extrageri · 20+ de extrageri. */
function extractions_label(int $n): string {
    if ($n === 1) return '1 extragere';
    $mod = $n % 100;
    return ($mod === 0 || $mod >= 20) ? "$n de extrageri" : "$n extrageri";
}

/**
 * US dollars for LLM spend. Daily figures are fractions of a cent per posting, so
 * below $1 three decimals ($0.095) keep a quiet weekend day from reading as $0.00.
 */
function usd_label(float $v): string {
    return '$' . number_format($v, $v >= 1 ? 2 : 3, '.', ',');
}

/**
 * The pay a result row shows (list, grid and table views): the salary the posting states
 * when it states one, else the estimate from the 2026 draft salary grid. The same
 * precedence as SALARY_EXPR, so a row and the salary filter never disagree. Gross lei per
 * month at gradația 0 for the estimate (the detail page says how it was reached), and an
 * estimate is always labelled as one. Null when there is no figure (or an old export
 * without the columns).
 *
 * @return array{label: string, text: string, estimated: bool}|null
 */
function list_salary(array $p): ?array {
    foreach ([['inf_salary_min', 'inf_salary_max', 'Salariu anunțat', false],
              ['sal_min', 'sal_max', 'Salariu estimat', true]] as [$lo, $hi, $label, $estimated]) {
        $min = $p[$lo] ?? null;
        if ($min === null || $min === '' || (float)$min <= 0) continue;
        $min = (int)round((float)$min);
        $max = ($p[$hi] ?? null) === null || ($p[$hi] ?? '') === '' ? $min : (int)round((float)$p[$hi]);
        $fmt = static fn(int $v): string => number_format($v, 0, ',', '.');
        $text = $max > $min ? $fmt($min) . '–' . $fmt($max) . ' lei' : $fmt($min) . ' lei';
        return ['label' => $label, 'text' => $text, 'estimated' => $estimated];
    }
    return null;
}

/** Romanian count of provider calls: 1 apel · 2–19 apeluri · 20+ de apeluri. */
function calls_label(int $n): string {
    if ($n === 1) return '1 apel';
    $mod = $n % 100;
    return ($mod === 0 || $mod >= 20) ? "$n de apeluri" : "$n apeluri";
}

/** The LLM pipeline step as the stats page names it (llm_costs.step). */
function llm_step_label(string $step): string {
    return match ($step) {
        'schema'      => 'Extracția structurată',
        'occupations' => 'Normalizarea ocupațiilor',
        'infer'       => 'Clasificarea profesiilor',
        default       => $step,
    };
}

/**
 * One day of LLM spend, said honestly: legacy rows are postings in an estimate, ledger
 * rows are measured provider calls, and a day can hold both (the day the ledger began).
 */
function llm_day_label(int $ledger_calls, int $legacy_postings): string {
    $parts = [];
    if ($legacy_postings > 0) $parts[] = postings_label($legacy_postings) . ' (estimare)';
    if ($ledger_calls > 0)    $parts[] = calls_label($ledger_calls);
    return implode(' + ', $parts);
}

/** Romanian year count: 1 an · 2–19 ani · 20+ de ani. */
function years_label(int $n): string {
    if ($n === 1) return '1 an';
    $mod = $n % 100;
    return ($mod === 0 || $mod >= 20) ? "$n de ani" : "$n ani";
}

/**
 * Romanian label for a (possibly fractional) year count: 0.5 → "6 luni",
 * 1.5 → "1,5 ani", 3 → "3 ani". years_label() takes an int, and `(int)0.5` is 0 —
 * "Min. 0 ani" for a role that asks six months.
 */
function experience_years_label(float $years): string {
    if ($years > 0 && $years < 1) {
        $months = max(1, (int)round($years * 12));
        return $months === 1 ? '1 lună' : "$months luni";
    }
    if ($years == floor($years)) return years_label((int)$years);
    return rtrim(rtrim(number_format($years, 1, ',', ''), '0'), ',') . ' ani';
}

/**
 * The experience line of one role (a `positions[].experience` block), or '' when
 * the role states nothing. Absent is unknown, so it renders nothing; "Fără
 * experiență" appears only when the extraction says none is required or the minimum
 * is explicitly zero. A positive minimum wins over a stray none_required flag.
 *
 * @param array<string,mixed>|null $exp
 */
function role_experience_label(?array $exp): string {
    if (!$exp) return '';
    $years = isset($exp['years_minimum']) && is_numeric($exp['years_minimum']) ? (float)$exp['years_minimum'] : null;
    if ($years !== null && $years > 0) {
        return 'Experiență: min. ' . experience_years_label($years)
             . (($exp['in_specialty'] ?? null) === true ? ', în specialitate' : '');
    }
    if (($exp['none_required'] ?? null) === true || $years === 0.0) {
        return 'Fără experiență';
    }
    return '';
}

// ---- Source evidence (UX-13) ----
//
// `requirement_evidence` (export-to-sqlite.py, evidence.py) holds, per extracted
// requirement fact of one role, the quote the extraction kept and whether it could be
// LOCATED in the posting text the export holds. Three states, three labels:
//   verified    the quote occurs in the page or the attachments (after folding case,
//               diacritics, punctuation and whitespace): shown as a quotation, with where.
//   unverified  a quote exists but was not found (or is too short to mean anything): shown
//               as unverified model output, never as a quotation.
//   absent      no quote: says nothing about whether the requirement exists.
// An official link is a convenience, not proof of verification, and is worded that way.

/**
 * The evidence rows of one posting, indexed [ordinal][fact_kind][fact_index] => row.
 * Empty on any failure: the page must render without evidence (an export that predates
 * the table is answered by an empty temp view, see shim_requirement_evidence()).
 *
 * @return array<int,array<string,array<int,array<string,mixed>>>>
 */
function load_requirement_evidence(PDO $pdo, int $posting_id): array {
    try {
        $st = $pdo->prepare('SELECT ordinal, fact_kind, fact_index, fact_label, basis, quote, status, reason,
                                    part, section, occurrences
                             FROM requirement_evidence WHERE posting_id = ?
                             ORDER BY ordinal, fact_kind, fact_index');
        $st->execute([$posting_id]);
        $rows = $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['ordinal']][(string)$r['fact_kind']][(int)$r['fact_index']] = $r;
    }
    return $out;
}

/** An absolute http(s) URL safe to put in an href, or null. Anything else (javascript:, data:, relative, spaces) is dropped. */
function evidence_safe_url(?string $url): ?string {
    $url = trim((string)$url, " \t\n\r");   // not NUL or other control bytes: those are rejected below
    if ($url === '' || preg_match('/[\x00-\x20\x7f"\'<>\\\\`]/', $url) || !preg_match('#^https?://#i', $url)) {
        return null;
    }
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) && $host !== '' ? $url : null;
}

/**
 * One native `<details>` disclosure for one evidence row, or '' when there is no row.
 * Keyboard accessible and working with no JavaScript; the fact it belongs to stays
 * visible outside it. Every piece of source or model text goes through e(); nothing
 * from the source is ever emitted as markup. A "verified" row without a quote or a
 * known part is malformed and renders nothing rather than claim verification.
 *
 * @param array<string,mixed>|null $row
 */
function evidence_disclosure(?array $row, string $official_url, ?string $announcement_url, string $fact_label = '', string $id = ''): string {
    if (!$row) return '';
    $status = (string)($row['status'] ?? '');
    $quote  = trim((string)($row['quote'] ?? ''));
    $reason = (string)($row['reason'] ?? '');
    $part   = (string)($row['part'] ?? '');
    if (!in_array($status, ['verified', 'unverified', 'absent'], true)) return '';
    if ($status === 'verified' && ($quote === '' || !in_array($part, ['anunt', 'anexe'], true))) return '';

    $official = evidence_safe_url($official_url);
    $document = $status === 'verified' && $part === 'anexe' ? evidence_safe_url($announcement_url) : null;
    $links = '';
    if ($official) {
        $links .= '<a href="' . e($official) . '" target="_blank" rel="noopener noreferrer" class="text-gov hover:underline">Anunțul oficial</a>';
    }
    if ($document && $document !== $official) {
        $links .= ($links !== '' ? ' · ' : '')
            . '<a href="' . e($document) . '" target="_blank" rel="noopener noreferrer" class="text-gov hover:underline">Documentul anunțului</a>';
    }
    $links = $links !== '' ? '<p class="mt-1 text-xs">' . $links . '</p>' : '';

    if ($status === 'verified') {
        $summary = 'Sursa';
        $summary_class = 'text-gov';
        $box = 'border-info-line bg-info text-info-ink';
        $where = $part === 'anunt'
            ? 'textul anunțului' . (($sec = trim((string)($row['section'] ?? ''))) !== '' ? ' — secțiunea «' . $sec . '»' : '')
            : 'documentele atașate (documentul exact nu este identificat)';
        $n = (int)($row['occurrences'] ?? 0);
        $body = '<blockquote class="whitespace-pre-line border-l-2 border-info-line pl-2 text-sm text-ink">' . e($quote) . '</blockquote>'
            . '<p class="mt-1 text-xs">Găsit în: ' . e($where) . ($n > 1 ? ' · apare de ' . $n . ' ori' : '') . '</p>'
            . '<p class="mt-1 text-xs opacity-80">Potrivire după ignorarea diacriticelor, a majusculelor, a punctuației și a spațiilor.</p>'
            . $links;
    } elseif ($status === 'unverified') {
        $summary = 'Sursă neverificată';
        $summary_class = 'text-note-ink';
        $box = 'border-note-line bg-note text-note-ink';
        $why = match ($reason) {
            'too_short'      => 'Citatul este prea scurt pentru a fi verificat.',
            'no_source_text' => 'Textul sursă nu este disponibil pentru verificare.',
            'stale_revision' => 'Textul citat de extragerea automată nu a fost regăsit în sursa curentă. '
                              . 'Extragerea a fost făcută pe o versiune mai veche a anunțului.',
            default          => 'Textul citat de extragerea automată nu a fost regăsit în sursa curentă.',
        };
        $body = '<p class="text-xs">' . e($why) . '</p>'
            . ($quote !== '' ? '<p class="mt-1 text-xs">Text extras automat (neverificat): ' . e($quote) . '</p>' : '')
            . $links;
    } else {
        $summary = 'Fără citat';
        $summary_class = 'text-ink-muted';
        $box = 'border-neutral-line bg-neutral text-neutral-ink';
        $body = '<p class="text-xs">Nu avem un citat din sursă pentru această cerință. '
              . 'Lipsa unui citat nu înseamnă că cerința nu există — verificați anunțul oficial.</p>'
            . $links;
    }

    $aria = $fact_label !== '' ? ' aria-label="' . e($summary . ': ' . $fact_label) . '"' : '';
    // Closed it sits beside its fact; open, it takes a full row so the box starts at the left edge.
    // `$id` (optional, UX-10) is the anchor the "Pot aplica?" panel links to; it adds an attribute only.
    return '<details class="min-w-0 max-w-full open:basis-full" data-evidence="' . $status . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>'
        . '<summary' . $aria . ' class="cursor-pointer list-none rounded text-xs font-semibold underline decoration-dotted underline-offset-2 marker:content-none hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus ' . $summary_class . '">'
        . $summary . ' <span aria-hidden="true">▾</span></summary>'
        . '<div class="mt-1 max-w-prose rounded border px-2.5 py-2 ' . $box . '">' . $body . '</div>'
        . '</details>';
}

/**
 * Stable DOM id of one evidence row's disclosure, `ev-<ordinal>-<kind>-<index>`, or ''
 * when the row does not carry its key (the id is unique per posting page: one row per key).
 *
 * @param array<string,mixed>|null $row
 */
function evidence_anchor_id(?array $row): string {
    if (!$row || !isset($row['ordinal'], $row['fact_kind'], $row['fact_index'])) return '';
    $kind = preg_replace('/[^a-z]/', '', strtolower((string)$row['fact_kind'])) ?? '';
    return $kind === '' ? '' : 'ev-' . (int)$row['ordinal'] . '-' . $kind . '-' . (int)$row['fact_index'];
}

/**
 * A short list of facts, each with its evidence disclosure beside it. Items without a
 * row are skipped; '' when nothing is left. `$labels = false` omits the visible fact label
 * where the surrounding section already shows the one fact it belongs to (the summary's
 * aria-label still names it).
 *
 * @param list<array{kind:string,label:string,row:?array<string,mixed>}> $items
 */
function evidence_fact_list(array $items, string $official_url, ?string $announcement_url, bool $labels = true): string {
    $lis = '';
    foreach ($items as $it) {
        $d = evidence_disclosure($it['row'] ?? null, $official_url, $announcement_url, (string)$it['label'], evidence_anchor_id($it['row'] ?? null));
        if ($d === '') continue;
        $lis .= '<li class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-xs" data-evidence-kind="' . e($it['kind']) . '">'
            . ($labels ? '<span class="text-ink-muted">' . e($it['label']) . '</span>' : '') . $d . '</li>';
    }
    return $lis === '' ? '' : '<ul class="mt-1.5 space-y-1" data-evidence-list>' . $lis . '</ul>';
}

/**
 * The evidence items of one role (or of the flat posting, ordinal 0) for the given
 * fact kinds. `$box` is the dict the facts were read from: a positions[] entry, or the
 * flat schema. A decomposed posting has rows for its roles only, so asking for ordinal 0
 * on one returns nothing: the flat block must never show a role's evidence.
 *
 * @param array<string,mixed> $box
 * @param array<int,array<string,array<int,array<string,mixed>>>> $evidence
 * @param list<string> $kinds
 * @return list<array{kind:string,label:string,row:array<string,mixed>}>
 */
function evidence_items(array $box, array $evidence, int $ordinal, array $kinds): array {
    $by = $evidence[$ordinal] ?? [];
    $items = [];
    if (in_array('education', $kinds, true) && ($row = $by['education'][0] ?? null)) {
        $lvl = is_array($box['education'] ?? null) ? (string)($box['education']['minimum_level'] ?? '') : '';
        $items[] = ['kind' => 'education', 'row' => $row,
            'label' => 'Studii: ' . ($lvl !== '' ? (STUDIES_LABELS[$lvl] ?? $lvl) : (string)$row['fact_label'])];
    }
    if (in_array('experience', $kinds, true) && ($row = $by['experience'][0] ?? null)) {
        $label = role_experience_label(is_array($box['experience'] ?? null) ? $box['experience'] : null);
        $items[] = ['kind' => 'experience', 'row' => $row,
            'label' => $label !== '' ? $label : 'Experiență: ' . (string)$row['fact_label']];
    }
    foreach (['skill', 'credential'] as $kind) {
        if (!in_array($kind, $kinds, true)) continue;
        foreach ($by[$kind] ?? [] as $row) {
            $items[] = ['kind' => $kind, 'row' => $row, 'label' => (string)$row['fact_label']];
        }
    }
    return $items;
}

function fmt_date(?string $iso, string $fmt = 'd.m.Y'): string {
    if (!$iso) return '';
    try {
        return (new DateTime(substr($iso, 0, 10)))->format($fmt);
    } catch (Exception $e) {
        return $iso;
    }
}

function fmt_datetime(?string $iso, string $fmt = 'd.m.Y H:i'): string {
    if (!$iso) return '';
    try {
        return (new DateTime($iso))->format($fmt);
    } catch (Exception $e) {
        return $iso;
    }
}

// ---- Provenance ----

/**
 * The one `build_meta` row: when export-to-sqlite.py built this database, from which
 * commit, and what it holds. Two timestamps describe this site and they are not the
 * same thing — `MAX(last_seen_at)` is when the source was last scraped, `built_at` is
 * when this file was generated. They usually coincide, and when they do not, that
 * gap is the interesting part.
 *
 * Returns null on an export predating the table, or any read failure: every caller
 * must render sensibly without it.
 */
function build_meta(): ?array {
    static $meta = false;          // false = not looked up, null = looked up, absent
    if ($meta === false) {
        try {
            $meta = db()->query("SELECT * FROM build_meta WHERE id = 1")->fetch() ?: null;
        } catch (Exception $e) {
            $meta = null;
        }
    }
    return $meta;
}

/**
 * Build timestamp in Romanian local time — `built_at` is stored UTC, and 21:46 in
 * Bucharest must not read as 18:46 to someone checking how fresh the site is.
 */
function build_time(string $fmt = 'd.m.Y, H:i'): string {
    $meta = build_meta();
    if (empty($meta['built_at'])) return '';
    try {
        return (new DateTime($meta['built_at']))
            ->setTimezone(new DateTimeZone('Europe/Bucharest'))
            ->format($fmt);
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Tooltip text for the "actualizat" stamps in the header and footer. The visible
 * stamp is the database generation time; the tooltip adds the row count, the
 * data-artifact commit and the deployed code marker. `source_host` is
 * deliberately not exposed — infrastructure.
 */
function build_tooltip(): string {
    $when = build_time();
    if (!$when) return '';
    $meta = build_meta();
    $parts = ["Bază de date generată la {$when}"];
    if (!empty($meta['job_postings'])) {
        // Every row of the export: the "active" count is the open-status subset
        // (FIX-05), which the page itself shows — 2.267 vs 1.896 on 05.10.
        $parts[] = number_format((int) $meta['job_postings'], 0, ',', '.') . ' anunțuri în bază';
    }
    if (!empty($meta['git_sha'])) {
        $parts[] = 'date @ ' . $meta['git_sha'];
    }
    if (!empty($meta['run_id'])) {
        $parts[] = 'rulare ' . $meta['run_id'];
    }
    if (code_version() !== '') {
        $parts[] = 'cod ' . code_version();
    }
    return implode(' · ', $parts);
}

/**
 * The deployed CODE version, stamped by deploy-php.sh from the git SHA at code
 * deploy time (webapp-php/static/code-version.txt). Data-only pushes never
 * touch it, code-only pushes never touch the data marker — the two drift apart
 * exactly when a code release is pending, which ops/check-served-version
 * (deploy-php.sh --verify) surfaces. '' when running from a checkout without a
 * stamp.
 */
function code_version(): string {
    static $version = false;
    if ($version === false) {
        $path = __DIR__ . '/static/code-version.txt';
        $version = is_file($path) ? trim((string)@file_get_contents($path)) : '';
    }
    return $version;
}

/**
 * The database generation time as the masthead says it (BETA-CHROME, D9):
 * "azi, 16 oct, 14:00" / "ieri, …" / "alaltăieri, …", else the date alone.
 * Returns ['long' => …, 'iso' => …], or null without build_meta.
 *
 * The day word is computed per request, so a cached page can say "azi" for up
 * to the edge TTL past midnight (s-maxage=3600 in index.php) — the same bound
 * the open/closed status already lives with.
 */
function build_stamp_relative(): ?array {
    $meta = build_meta();
    if (empty($meta['built_at'])) return null;
    try {
        $tz = new DateTimeZone('Europe/Bucharest');
        $at = (new DateTimeImmutable($meta['built_at']))->setTimezone($tz);
        $today = new DateTimeImmutable(ro_today(), $tz);
    } catch (Exception $e) {
        return null;
    }
    $months = ['ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'nov', 'dec'];
    $date = (int)$at->format('j') . ' ' . $months[(int)$at->format('n') - 1];
    if ($at->format('Y') !== $today->format('Y')) $date .= ' ' . $at->format('Y');
    $time = $at->format('H:i');
    $days = (int)$today->diff(new DateTimeImmutable($at->format('Y-m-d'), $tz))->format('%r%a');
    $word = [0 => 'azi', -1 => 'ieri', -2 => 'alaltăieri'][$days] ?? null;
    return [
        'long'  => ($word ? "$word, " : '') . "$date, $time",
        'iso'   => $at->format('Y-m-d\TH:i'),
    ];
}

/**
 * The robots disclaimer at the start and end of every posting (D9). $url is the
 * official announcement; without one the sentence points at the portal.
 */
function robots_disclaimer(string $url, string $class = ''): string {
    $href = evidence_safe_url($url) ?? 'https://posturi.gov.ro/';
    return '<p class="robots-disclaimer ' . e($class) . '">Datele sunt preluate de pe posturi.gov.ro și restructurate cu '
         . '<span aria-hidden="true">🤖</span> roboți. Verificați întotdeauna '
         . '<a href="' . e($href) . '" target="_blank" rel="noopener" class="text-gov underline underline-offset-2 hover:no-underline">anunțul oficial</a>.</p>';
}

/** Feedback address shown in the [?!] panel; empty hides the e-mail link (D9). */
const FEEDBACK_EMAIL = 'midas@gov2.ro';

/** The [?!] panel's FAQ: [anchor on /despre/, question]. Each anchor must exist there. */
const FEEDBACK_FAQ = [
    ['cum-functioneaza',  'De ce diferă unele informații de anunțul oficial?'],
    ['termene',           'Termenul sau starea înscrierilor pare greșită'],
    ['salarii',           'Salariul afișat este cel real?'],
    ['anunturi-inchise',  'Un anunț a dispărut din listă'],
    ['aplicare',          'Cum aplic la un post?'],
    ['confidentialitate', 'Unde se păstrează anunțurile salvate?'],
];

/** How stale a source-verification observation is before the UI says so. */
const SOURCE_CHECK_STALE_HOURS = 48;

/**
 * The last real successful detail retrieval (build_meta.detail_fetched_at_max),
 * rendered honestly: a date when known, an explicit "neverificat" when not, and
 * a visible stale marker when the observation is old. Null when the export
 * predates provenance — nothing to claim either way.
 */
function source_check_label(): ?string {
    $meta = build_meta();
    if (!$meta) return null;
    $at = $meta['detail_fetched_at_max'] ?? '';
    if ($at === '' || $at === null) return 'sursă neverificată';
    try {
        $when = (new DateTime($at))->setTimezone(new DateTimeZone('Europe/Bucharest'));
    } catch (Exception $e) {
        return 'sursă neverificată';
    }
    $label = 'sursă verificată ' . $when->format('d.m.Y');
    $age_h = (time() - $when->getTimestamp()) / 3600;
    return $age_h > SOURCE_CHECK_STALE_HOURS ? $label . ' (depășită)' : $label;
}

// ---- Salary / fee renderers ----

function render_base_salary($salary): string {
    if (!is_array($salary)) return '';
    $mn = $salary['minValue'] ?? null;
    $mx = $salary['maxValue'] ?? null;
    $currency = $salary['currency'] ?? 'RON';
    $unit = strtolower($salary['unitText'] ?? 'month');
    $unit_ro = ['hour' => '/oră', 'day' => '/zi', 'week' => '/săptămână', 'month' => '/lună', 'year' => '/an'][$unit] ?? '';
    if ($mn === null && $mx === null) return '';
    if ($mn !== null && $mx !== null && $mn != $mx) return "{$mn}–{$mx} {$currency}{$unit_ro}";
    return (($mn ?? $mx)) . " {$currency}{$unit_ro}";
}

function render_application_fee($fee): string {
    if (!is_array($fee)) return '';
    $parts = [];
    if (isset($fee['amount'])) $parts[] = $fee['amount'] . ' ' . ($fee['currency'] ?? 'RON');
    if (!empty($fee['account'])) $parts[] = 'Cont: ' . $fee['account'];
    if (!empty($fee['details'])) $parts[] = $fee['details'];
    return implode('. ', $parts);
}

function render_application_contact($contact): string {
    if (!is_array($contact)) return '';
    $rows = [];
    if (!empty($contact['name']))    $rows[] = '- ' . $contact['name'];
    if (!empty($contact['phone']))   $rows[] = '- Telefon: ' . $contact['phone'];
    if (!empty($contact['email']))   $rows[] = '- Email: ' . $contact['email'];
    if (!empty($contact['address'])) $rows[] = '- Adresă: ' . $contact['address'];
    return implode("\n", $rows);
}

// ---- Schema section rendering ----

const SCHEMA_SECTION_LABELS = [
    'responsibilities'       => 'Atribuții principale',
    'educationRequirements'  => 'Studii',
    'experienceRequirements' => 'Experiență',
    'qualifications'         => 'Condiții specifice',
    'skills'                 => 'Competențe',
    'application_docs'       => 'Dosar de candidatură',
    'baseSalary'             => 'Salarizare',
    'salary'                 => 'Salarizare',
    'application_fee'        => 'Taxă de participare',
    'application_contact'    => 'Contact pentru depunere',
    'jobBenefits'            => 'Beneficii',
    'workHours'              => 'Program de lucru',
    'jobLocation'            => 'Locație',
    'work_conditions'        => 'Condiții de muncă',
];

function render_schema_sections(?string $schema_json_str): ?array {
    if (!$schema_json_str) return null;
    $schema = json_decode($schema_json_str, true);
    if (!is_array($schema)) return null;

    $sections = [];
    foreach (SCHEMA_SECTION_LABELS as $key => $label) {
        if (!array_key_exists($key, $schema)) continue;
        $value = $schema[$key];
        if ($value === null) continue;

        if (is_array($value)) {
            $rendered = match($key) {
                'baseSalary'          => render_base_salary($value),
                'application_fee'     => render_application_fee($value),
                'application_contact' => render_application_contact($value),
                default               => implode(', ', array_filter($value)),
            };
        } else {
            $rendered = (string)$value;
        }

        if (trim($rendered) === '') continue;
        // `key` is the Schema.org JobPosting property (or one of the three
        // RO-specific custom keys) the value came from — surfaced in the UI so
        // the structure is self-documenting.
        $sections[] = ['key' => $key, 'label' => $label, 'html' => render_markdown($rendered)];
    }
    return $sections ?: null;
}

// ---- Label lookups ----

const WORK_TYPE_LABELS = [
    'norma_intreaga' => 'Normă întreagă',
    'norma_partiala' => 'Normă parțială',
    'schimburi'      => 'Schimburi',
];

function work_type_label(string $v): string {
    return WORK_TYPE_LABELS[$v] ?? $v;
}

const ANOMALY_LABELS = [
    'short_deadline'         => 'Termen scurt',
    'missing_contact'        => 'Contact lipsă',
    'contact_in_attachment'  => 'Contact în atașament',
    'gender_criteria'        => 'Criteriu de gen',
    'no_body'                => 'Fără corp',
    'frequent_repost'        => 'Re-publicare frecventă',
];

/** Seniority buckets from `_infer_seniority` — the raw values are snake_case. */
const SENIORITY_LABELS = [
    'conducere_superioara' => 'Conducere superioară',
    'director'             => 'Director / șef',
    'expert'               => 'Expert',
    'consilier'            => 'Consilier',
    'inspector'            => 'Inspector',
    'referent'             => 'Referent',
    'asistent'             => 'Asistent',
    'debutant'             => 'Debutant',
];

function seniority_label(string $v): string {
    return SENIORITY_LABELS[$v] ?? ucfirst(str_replace('_', ' ', $v));
}

/** Professional grade from the title ("gradul II", "principal", "superior"). */
function grade_label(string $v): string {
    return match (strtolower($v)) {
        'debutant'  => 'Debutant',
        'principal' => 'Grad principal',
        'superior'  => 'Grad superior',
        default     => 'Gradul ' . strtoupper($v),
    };
}

const STUDIES_LABELS = [
    'doctorat'    => 'Doctorat',
    'master'      => 'Master / Magistru',
    'licenta'     => 'Licență',
    'postliceala' => 'Postliceală',
    'liceala'     => 'Liceală',
    'generala'    => 'Generală',
];

/** Legacy source/inferred classification labels. The live source already sends
 *  Romanian phrases ("Funcții de execuție"); these cover the snake/ASCII spellings
 *  older exports and fixtures carry. Unfamiliar values are shown as-is. */
const JOB_LEVEL_LABELS = [
    'executie'  => 'Funcții de execuție',
    'conducere' => 'Funcții de conducere',
];

function job_level_label(string $v): string { return JOB_LEVEL_LABELS[$v] ?? $v; }

/** Profession family as a visible label: first letter upper-cased, underscores
 *  read as spaces. The value itself (and the URL) is untouched. */
function family_label(string $v): string {
    $v = str_replace('_', ' ', $v);
    return mb_strtoupper(mb_substr($v, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($v, 1, null, 'UTF-8');
}

// ---- Contract terms (prompt v3, exported from 2026-09-13) ----

/** Fixed-term vs open-ended. Finer than the scraped `job_type`, which only ever
 *  says Permanent/Temporar and disagrees with this on a minority of rows. */
const CONTRACT_DURATION_LABELS = [
    'nedeterminata' => 'Perioadă nedeterminată',
    'determinata'   => 'Perioadă determinată',
    'sezonier'      => 'Sezonier',
    'proiect'       => 'Pe durata proiectului',
];

const SCHEDULE_LABELS = [
    'norma_intreaga' => 'Normă întreagă',
    'norma_partiala' => 'Normă parțială',
    'schimburi'      => 'În schimburi',
    'tura_noapte'    => 'Tură de noapte',
    'flexibil'       => 'Program flexibil',
];

const REMOTE_MODE_LABELS = [
    'la_sediu'  => 'La sediu',
    'hibrid'    => 'Hibrid',
    'telemunca' => 'Telemuncă',
];

/** Career stage as v3 reads it. A different axis from `inf_seniority`, which
 *  names the role family (referent, inspector, consilier) rather than the
 *  stage — they are not interchangeable and are not merged. */
const SENIORITY_HINT_LABELS = [
    'debutant'   => 'Debutant',
    'asistent'   => 'Asistent',
    'practicant' => 'Practicant',
    'specialist' => 'Specialist',
    'principal'  => 'Principal',
    'superior'   => 'Superior',
    'conducere'  => 'Conducere',
];

// ---- Occupation, funding and employer sector (prompt v4 + the title dictionary) ----

const FUNDING_SOURCE_LABELS = [
    'fonduri_europene' => 'Fonduri europene',
    'buget_stat'       => 'Buget de stat',
    'buget_local'      => 'Buget local',
    'venituri_proprii' => 'Venituri proprii',
    'mixt'             => 'Finanțare mixtă',
    'nespecificat'     => 'Nespecificat',
];

const EMPLOYER_SECTOR_LABELS = [
    'administratie_locala'   => 'Administrație locală',
    'administratie_centrala' => 'Administrație centrală',
    'sanatate'               => 'Sănătate',
    'educatie'               => 'Educație',
    'cultura'                => 'Cultură',
    'aparare'                => 'Apărare',
    'ordine_publica'         => 'Ordine publică',
    'justitie'               => 'Justiție',
    'asistenta_sociala'      => 'Asistență socială',
    'cercetare'              => 'Cercetare',
    'transport'              => 'Transport',
    'mediu'                  => 'Mediu',
    'agricultura'            => 'Agricultură',
    'altele'                 => 'Altele',
];

/** How sure the title -> occupation mapping is. Shown, not hidden: the salary
 *  estimate is built on it, so a reader deserves to know when it is a guess. */
const OCC_CONFIDENCE_LABELS = [
    'exact'    => 'Potrivire exactă',
    'probabil' => 'Potrivire probabilă',
    'incert'   => 'Potrivire incertă',
    'none'     => 'Fără corespondent',
];

/**
 * Display form for one value of the salary selector.
 *
 * The selector is stored in the grid's own vocabulary so the calculator can be
 * re-run against it; this is only for reading. A value may be a list — an
 * unresolved population band is two plausible bands, not a guess.
 */
function selector_value_label(string $field, $value): string {
    if (is_array($value)) {
        return implode(' sau ', array_map(fn($v) => selector_value_label($field, $v), $value));
    }
    $value = (string)$value;
    return match ($field) {
        'studii'   => STUDIES_LABELS[$value] ?? $value,
        'anexa'    => 'Anexa ' . $value,
        default    => $value,
    };
}

/** Selector fields shown in the "cum a fost calculat" breakdown. */
const SELECTOR_FIELD_LABELS = [
    'grad_treapta'        => 'Grad',
    'studii'              => 'Nivel studii',
    'tip_post'            => 'Tip post',
    'nivel_administrativ' => 'Nivel administrativ',
    'banda_populatie'     => 'Mărime UAT',
    'regim'               => 'Regim',
    'anexa'               => 'Anexa',
];

// ---- Salary/experience buckets ----

/**
 * The pay figure the site filters and sorts on, in SQL.
 *
 * An announced salary wins where one exists, but only 44 of 9,757 postings
 * state one, so in practice this is the estimate from the 2026 draft grid.
 * Defined once because three call sites have to agree: build_filters(), the
 * facet counts in pages/list.php, and the landing shortcut.
 */
const SALARY_EXPR = 'COALESCE(j.inf_salary_min, j.sal_min)';

/**
 * The date the site treats as "can I still apply?".
 *
 * NOT `expires_at`. That is when the *competition* ends — the final-results
 * date — and on a 20-posting sample it ran a median of 15 days, up to 52, past
 * the day applications actually closed. The site was advertising closed
 * competitions as open. `apply_deadline` is the real deadline where one is
 * known (from the v4 competition calendar, else the scraped card field) and
 * falls back to `expires_at` where none is, so nothing regresses.
 *
 * `expires_at` is still the right column for how long a competition runs
 * (pages/stats.php) and for showing a reader when the announcement itself
 * lapses.
 */
const DEADLINE_COL = 'j.apply_deadline';

/** Where a posting's application deadline came from. */
const DEADLINE_SOURCE_LABELS = [
    'concurs'  => 'din calendarul concursului',
    'anunt'    => 'din anunț',
    'expirare' => 'estimat din data expirării anunțului',
];

const SALARY_BUCKETS = [
    ['key' => 'sub-3000',   'label' => 'sub 3000',    'min' => 0,    'max' => 3000],
    ['key' => '3000-4000',  'label' => '3000 – 4000', 'min' => 3000, 'max' => 4000],
    ['key' => '4000-5000',  'label' => '4000 – 5000', 'min' => 4000, 'max' => 5000],
    ['key' => '5000-7000',  'label' => '5000 – 7000', 'min' => 5000, 'max' => 7000],
    ['key' => 'peste-7000', 'label' => 'peste 7000',  'min' => 7000, 'max' => null],
];

const EXP_BUCKETS = [
    ['key' => 'fara',  'label' => 'Fără experiență', 'min' => 0, 'max' => 1],
    ['key' => '1-2',   'label' => '1–2 ani',          'min' => 1, 'max' => 3],
    ['key' => '3-5',   'label' => '3–5 ani',          'min' => 3, 'max' => 6],
    ['key' => '5plus', 'label' => '5+ ani',            'min' => 5, 'max' => null],
];

function bucket_label(array $buckets, string $key): string {
    foreach ($buckets as $b) {
        if ($b['key'] === $key) return $b['label'];
    }
    return $key;
}

// ---- Status (replaces the raw date pickers as the primary deadline control) ----

const DEFAULT_STATUS = 'active';

// "Toate" was dropped 2026-09-08: the deployed SQLite is exported --active-only,
// so it counted exactly the same rows as "Active" (1,799 of 1,799) and cost a
// third of the control's width to say nothing. An unknown status in the URL
// falls back to DEFAULT_STATUS, so old ?status=all links still resolve.
// FIX-05 added closed/unknown from the export's application_status vocabulary;
// "active" includes confirmed open, unconfirmed expiry fallbacks and unknown
// dates — the rows whose display already qualifies the difference.
const STATUS_LABELS = [
    'active'  => 'Active',
    'soon'    => 'Termen în 7 zile',
    'closed'  => 'Înscrieri închise',
    'unknown' => 'Termen neprecizat',
];

/** Persistent copy under the status control (UX-01A): "Active" is not "confirmed open". */
const STATUS_HELP = 'Active include anunțuri cu înscrieri deschise și anunțuri al căror termen nu este confirmat. Verifică termenul în anunțul oficial.';
const STATUS_HELP_SOON = 'Include și date estimate din expirarea anunțului.';

/** Shown beside every date that is the announcement expiry, not a confirmed submission deadline. */
const DEADLINE_FALLBACK_NOTE = 'Expirarea anunțului; înscriere neconfirmată';

/** The open-application statuses, for every "still active?" count query. */
const OPEN_STATUS_SQL = "'confirmed_open','unconfirmed','unknown'";

/** slug => name, for turning `judet=cluj` back into "Cluj" on a chip. */
function judet_names(): array {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (db()->query("SELECT slug, name FROM judete")->fetchAll() as $r) {
            $map[$r['slug']] = $r['name'];
        }
    }
    return $map;
}

// ---- Attachments ----

/**
 * Labels for the attachment kinds worth calling out. Deliberately short: the
 * source ships one file per posting and ~89% of them are the announcement
 * itself, whose bibliografie / fișă / cerere live inside it as sections rather
 * than as separate documents. See webapp/apps/jobs/attachments.py.
 */
const ATTACHMENT_KIND_LABELS = [
    'erata'     => 'Erată / modificare',
    'rezultate' => 'Rezultate',
];

const ATTACHMENT_ROLE_LABELS = [
    'announcement' => 'Anunț oficial',
    'other'        => 'Document anexat',
];

/** Byte count as a download link should show it: 12 KB, 1.4 MB. */
function human_size($bytes): string {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '';
    if ($bytes < 1024) return $bytes . ' B';
    $kb = $bytes / 1024;
    return $kb < 1024 ? round($kb) . ' KB' : round($kb / 1024, 1) . ' MB';
}

/**
 * Describe a posting's attachments for display.
 *
 * The filename is deliberately not shown — every one is opaque
 * (`j_11414_c_9189_anunt_688423.docx`, or an 8-char hex hash), so it labels
 * nothing. What a reader can use is the role, the format and the size, plus a
 * kind when the document declares itself something other than the announcement.
 */
function attachment_list(array $p): array {
    $meta = $p['attachment_meta'] ?? null;
    $meta = is_array($meta) ? $meta : (json_decode((string)$meta ?: '[]', true) ?: []);

    if (!$meta) {
        // Pre-metadata rows, and the feeds, still need something sensible.
        $meta = [];
        if (!empty($p['announcement_url'])) {
            $meta[] = ['url' => $p['announcement_url'], 'role' => 'announcement'];
        }
        foreach ((array)(json_decode($p['other_links'] ?? '[]', true) ?: []) as $link) {
            $meta[] = ['url' => $link, 'role' => 'other'];
        }
    }

    $out = [];
    foreach ($meta as $a) {
        // Attachment links come from third-party pages: only absolute http(s) URLs
        // are rendered, so a `javascript:` or relative href never reaches an <a>.
        $url = evidence_safe_url((string)($a['url'] ?? ''));
        if ($url === null) continue;
        $kind = (string)($a['kind'] ?? '');
        $role = (string)($a['role'] ?? 'other');
        $ext  = strtoupper((string)($a['ext'] ?? ''));
        if ($ext === '') {
            $ext = strtoupper(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        }
        $out[] = [
            'url'       => $url,
            'label'     => ATTACHMENT_KIND_LABELS[$kind] ?? (ATTACHMENT_ROLE_LABELS[$role] ?? 'Document'),
            'ext'       => $ext !== '' ? $ext : 'FIȘIER',
            'size'      => human_size($a['bytes'] ?? 0),
            'important' => isset(ATTACHMENT_KIND_LABELS[$kind]),
        ];
    }
    return $out;
}

// ---- Inferred metadata ----

/** Decode a posting's `inferred` JSON blob once, tolerating an already-decoded array. */
function inferred_of(array $p): array {
    $raw = $p['inferred'] ?? null;
    if (is_array($raw)) return $raw;
    return json_decode((string)$raw ?: '{}', true) ?: [];
}

/**
 * Minimum confidence before a dictionary-guessed profession family is shown.
 * Below this the guess is worse than saying nothing.
 */
const FAMILY_MIN_CONFIDENCE = 0.5;

/**
 * The derived attributes of a posting, in the order a job seeker scans them:
 * what field, what grade, what qualifications, what schedule.
 *
 * Kept apart from the source-of-truth badges (nivel / tip / categorie), because
 * these are inferred and the UI says so — see `/despre/` for the methodology.
 */
function inferred_meta(array $p): array {
    $inf  = inferred_of($p);
    $meta = [];

    $family = (string)($inf['profession_family'] ?? '');
    $conf   = (float)($inf['profession_family_confidence'] ?? 0);
    // 'altele' is the dictionary's catch-all — a label that says nothing.
    if ($family !== '' && $family !== 'altele' && $conf >= FAMILY_MIN_CONFIDENCE) {
        $meta[] = ucfirst($family);
    }
    if ($seniority = (string)($inf['seniority'] ?? '')) {
        $meta[] = seniority_label($seniority);
    }
    // The grade is a different axis from seniority ("Consilier, grad principal"),
    // so show it too — unless the regex landed on the same word for both.
    $grade = (string)($inf['grade'] ?? '');
    if ($grade !== '' && strcasecmp($grade, $seniority) !== 0) {
        $meta[] = grade_label($grade);
    }
    if ($studies = (string)($inf['studies_required'] ?? '')) {
        $meta[] = STUDIES_LABELS[$studies] ?? ucfirst($studies);
    }
    if ($years = $inf['experience_years'] ?? null) {
        $meta[] = 'Min. ' . years_label((int)$years);
    } elseif (!empty($inf['no_experience_required'])) {
        // The posting says so explicitly ("nu este cazul"), which is a
        // stronger signal than simply not mentioning experience.
        $meta[] = 'Fără experiență';
    }
    if ($work_type = (string)($inf['work_type'] ?? '')) {
        $meta[] = work_type_label($work_type);
    }
    if (!empty($inf['remote_eligible'])) {
        $meta[] = 'Telemuncă';
    }
    if (!empty($inf['requires_computer'])) {
        $meta[] = ($inf['computer_level'] ?? '') === 'advanced' ? 'Calculator (avansat)' : 'Calculator';
    }
    return $meta;
}

/** Free-form inferred tag lists, for the detail page. */
function inferred_tags(array $p): array {
    $inf = inferred_of($p);
    $out = [];
    foreach ([
        'skills'         => 'Competențe',
        'languages'      => 'Limbi străine',
        'certifications' => 'Certificări',
    ] as $key => $label) {
        $values = array_values(array_filter((array)($inf[$key] ?? []), fn($v) => trim((string)$v) !== ''));
        if ($values) $out[] = ['label' => $label, 'values' => $values];
    }
    return $out;
}

// ---- Prompt-v3 structured extraction ----

/**
 * Labels for the v3 controlled vocabularies. These stay empty in the UI until
 * `llm-schema.py --prompt-version v3` has run — `facet_group()` skips a group
 * with no options, so the filters appear by themselves once data exists.
 * See docs/metadata-schema-v3.md.
 */
const ISCED_FIELD_LABELS = [
    '00_generale'                            => 'Programe generale',
    '01_educatie'                            => 'Educație',
    '02_arte_umanioare'                      => 'Arte și științe umaniste',
    '03_stiinte_sociale'                     => 'Științe sociale și jurnalism',
    '04_afaceri_administratie_drept'         => 'Afaceri, administrație și drept',
    '05_stiinte_naturale_matematica'         => 'Științe naturale și matematică',
    '06_tic'                                 => 'Tehnologia informației (TIC)',
    '07_inginerie_constructii'               => 'Inginerie și construcții',
    '08_agricultura_silvicultura_veterinara' => 'Agricultură, silvicultură, veterinară',
    '09_sanatate_asistenta_sociala'          => 'Sănătate și asistență socială',
    '10_servicii'                            => 'Servicii',
];

const POLICY_DOMAIN_LABELS = [
    'achizitii_publice' => 'Achiziții publice',       'administratie_publica' => 'Administrație publică',
    'agricultura_alimentatie' => 'Agricultură',        'aparare_securitate' => 'Apărare și securitate',
    'asistenta_sociala' => 'Asistență socială',        'constructii_urbanism' => 'Construcții și urbanism',
    'cultura_patrimoniu' => 'Cultură și patrimoniu',   'demografie_migratie' => 'Demografie și migrație',
    'drept_justitie' => 'Drept și justiție',           'economie_finante' => 'Economie și finanțe',
    'educatie_cercetare' => 'Educație și cercetare',   'energie' => 'Energie',
    'mediu' => 'Mediu',                                'fonduri_europene' => 'Fonduri europene',
    'relatii_munca' => 'Relații de muncă',             'resurse_umane' => 'Resurse umane',
    'sanatate_publica' => 'Sănătate publică',          'securitate_sanatate_munca' => 'Securitatea muncii',
    'sport_tineret' => 'Sport și tineret',             'tehnologia_informatiei' => 'Tehnologia informației',
    'comunicare_media' => 'Comunicare și media',       'transporturi' => 'Transporturi',
    'turism' => 'Turism',                              'altele' => 'Altele',
];

const CREDENTIAL_KIND_LABELS = [
    'permis_conducere'             => 'Permis de conducere',
    'certificat_profesional'       => 'Certificat profesional',
    'autorizatie'                  => 'Autorizație / aviz de practică',
    'aviz_medical'                 => 'Aviz medical / psihologic',
    'acces_informatii_clasificate' => 'Acces informații clasificate',
    'altele'                       => 'Alte documente',
];

const EXAM_STAGE_LABELS = [
    'selectie_dosare' => 'Selecție dosare', 'proba_scrisa'   => 'Probă scrisă',
    'proba_practica'  => 'Probă practică',  'proba_sportiva' => 'Probă sportivă',
    'interviu'        => 'Interviu',        'proba_orala'    => 'Probă orală',
    'test_psihologic' => 'Test psihologic',
];

const EQF_LABELS = [
    2 => 'Studii generale',   3 => 'Școală profesională', 4 => 'Liceu / bacalaureat',
    5 => 'Postliceală',       6 => 'Licență',             7 => 'Master', 8 => 'Doctorat',
];

function isced_label(string $v): string      { return ISCED_FIELD_LABELS[$v] ?? $v; }
function policy_domain_label(string $v): string { return POLICY_DOMAIN_LABELS[$v] ?? ucfirst(str_replace('_', ' ', $v)); }
function credential_kind_label(string $v): string { return CREDENTIAL_KIND_LABELS[$v] ?? $v; }
function exam_stage_label(string $v): string { return EXAM_STAGE_LABELS[$v] ?? $v; }

/** Decode one of the `v3_*` JSON-array columns. */
function v3_list(array $p, string $column): array {
    $raw = $p[$column] ?? '[]';
    if (is_array($raw)) return $raw;
    return array_values(array_filter((array)(json_decode((string)$raw ?: '[]', true) ?: [])));
}

/** True when this posting carries a v3 extraction at all. */
function has_v3(array $p): bool {
    return !empty($p['v3_eqf_level']) || v3_list($p, 'v3_skills') || v3_list($p, 'v3_isced_fields');
}

/**
 * "en:B2" → ['engleză', 'B2']. The export packs the pair into one token so a
 * single LIKE probe can filter on language and level together.
 */
const LANGUAGE_NAMES = [
    'en' => 'Engleză', 'fr' => 'Franceză', 'de' => 'Germană', 'it' => 'Italiană',
    'es' => 'Spaniolă', 'ru' => 'Rusă', 'hu' => 'Maghiară',
];

function language_token_label(string $token): string {
    [$code, $cefr] = array_pad(explode(':', $token, 2), 2, '');
    $name = LANGUAGE_NAMES[strtolower($code)] ?? ucfirst($code);
    return $cefr !== '' ? "$name ($cefr)" : $name;
}

// ---- Query string helpers ----

/**
 * Return the current query string with the given key changed/removed.
 * Pass null to remove the key. Pass an array to set multiple values.
 */
function qs_with(string $key, $value): string {
    $params = $_GET;
    unset($params['page']); // reset page on filter change by default
    if ($value === null) {
        unset($params[$key]);
    } else {
        $params[$key] = $value;
    }
    $q = http_build_query($params);
    return $q ? '?' . $q : '';
}

/**
 * Return the current query string with a single value dropped from an array
 * param — `judet=cluj&judet=iasi` minus `cluj` leaves `judet=iasi`, where
 * qs_with($key, null) would have dropped both. Pass $value = null to drop the
 * whole key (scalar params, or "clear this facet entirely").
 */
function qs_without(string $key, ?string $value = null): string {
    $params = $_GET;
    unset($params['page']);
    $current = $params[$key] ?? null;

    if ($value === null || !is_array($current)) {
        unset($params[$key]);
    } else {
        $rest = array_values(array_filter($current, fn($v) => (string)$v !== $value));
        if ($rest) { $params[$key] = $rest; } else { unset($params[$key]); }
    }
    $q = http_build_query($params);
    return $q ? '?' . $q : '';
}

function qs_page(int $page): string {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

function current_qs(): string {
    $q = http_build_query($_GET);
    return $q ? '?' . $q : '';
}

/** Subscription caps (UX-04-FEEDS), stated in the list UI and enforced by the feeds: RSS/Atom keep the newest N matches; iCal the earliest N with a date. */
const FEED_ITEM_LIMIT = 50;
const FEED_CAL_LIMIT  = 200;

function feed_url(string $filename): string {
    // `page` and `sort` describe how the HTML list is being read, not which
    // postings it holds, and a feed reader has no use for either — a feed URL
    // carrying ?page=3 would silently hand back the third slice.
    $params = $_GET;
    unset($params['page'], $params['sort']);
    // An emptied search box arrives as `q=`; it selects nothing, so leave it out.
    $params = array_filter($params, fn($v) => !(is_string($v) && $v === ''));
    // `title` only names the calendar (X-WR-CALNAME); Atom and JSON ignore it,
    // and list.php's syncFeedLinks() strips it from them too.
    if (!str_ends_with($filename, '.ics')) unset($params['title']);
    $qs = http_build_query($params);
    return '/' . $filename . ($qs ? '?' . $qs : '');
}

/**
 * When a feed request is scoped to one angajator (`?employer=<slug>`), return
 * that employer's `{id, name, slug}` row so the feed can name itself after it;
 * null otherwise. Used by the three feed handlers for their titles.
 */
function feed_employer(array $p): ?array {
    $slug = trim((string)($p['employer'] ?? ''));
    if ($slug === '') return null;
    $stmt = db()->prepare("SELECT id, name, slug FROM employers WHERE slug = ?");
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

// ---- Active-filter chips ----

/**
 * Every filter param, in sidebar order, with the group name shown on its chip.
 * An empty group renders the value alone (the value already reads as a label).
 */
const FILTER_CHIP_GROUPS = [
    'q'              => 'Caută',
    'status'         => 'Stare',
    'family'         => 'Domeniu profesional',
    'judet'          => 'Județ',
    'level'          => '',
    'type'           => 'Tip',
    'categorie'      => '',
    'seniority'      => 'Grad',
    'work_type'      => 'Normă',
    'exp_level'      => 'Experiență',
    'studies_level'  => 'Studii din text',
    'remote'         => '',
    'computer'       => 'Calculator',
    'salary_bucket'  => 'Salariu',
    'employer_cat'   => 'Angajator',
    'schema'         => 'Descriere',
    'isced'          => 'Domeniu de studii',
    'eqf'            => 'Nivel de studii (EQF)',
    'skill'          => 'Competență',
    'lang'           => 'Limbă',
    'credential'     => 'Document',
    'domain'         => 'Domeniu de activitate',
    'stage'          => 'Etapă',
    'anomaly'        => 'Anomalie',
    'expires_after'  => 'Termen de la',
    'expires_before' => 'Termen până la',
    'occupation'     => 'Ocupație',
    'funding'        => 'Finanțare',
    'sector'         => 'Sector',
    'has_salary'     => '',
    'duration'       => 'Contract',
    'schedule'       => 'Program',
    'shift'          => '',
    'employer'       => 'Doar angajatorul',
    'employer_id'    => 'Doar angajatorul',
];

/**
 * Filters that accept several values at once. Their form fields must be named
 * `judet[]`, not `judet` — a browser serialises repeated checkboxes as
 * `judet=cluj&judet=iasi`, which PHP collapses to the *last* value, so every
 * multi-select silently narrowed to one option after an HTMX round-trip.
 */
const MULTI_PARAMS = [
    'judet', 'level', 'type', 'categorie', 'employer_cat',
    'family', 'seniority', 'work_type', 'exp_level', 'studies_level', 'anomaly',
    'isced', 'skill', 'lang', 'credential', 'domain', 'stage', 'eqf',
    'occupation', 'funding', 'sector', 'duration', 'schedule',
];

/** Form field name for a filter param. */
function param_field(string $name): string {
    return in_array($name, MULTI_PARAMS, true) ? $name . '[]' : $name;
}

/**
 * How several checked values inside one facet group combine, and which groups
 * let the visitor change it.
 *
 * The default everywhere is 'any' (OR): a facet reads as "which of these do you
 * accept?", not as a stack of requirements. That is the e-commerce convention,
 * and for most of these columns it is the only thing that can work — a posting
 * carries exactly one `inf_profession_family`, so "social AND administrație"
 * is empty by construction, not merely rare. The same held for four of the
 * JSON-array columns, which used to AND their values while averaging barely
 * more than one value per posting (v3_languages: 1.07, v3_isced_fields: 1.32).
 *
 * `skill` is the real exception: postings carry 9.6 skills on average, so
 * "știe Excel ȘI contabilitate" is a question worth asking. It keeps the AND
 * default and, in exchange, shows a visible any/all switch and residual counts
 * — see v3_facet() in list.php.
 *
 * Keys are filter params; values are the default mode for that group.
 */
const FACET_MODE_PARAMS = ['skill' => 'all'];

/** Combine mode in force for a facet group: 'any' (OR) or 'all' (AND). */
function facet_mode(string $param, ?array $p = null): string {
    $p ??= $_GET;
    if (!isset(FACET_MODE_PARAMS[$param])) return 'any';
    $mode = (string)($p[$param . '_mode'] ?? FACET_MODE_PARAMS[$param]);
    return $mode === 'all' ? 'all' : 'any';
}

/** Form field name carrying a group's combine mode. */
function facet_mode_field(string $param): string {
    return $param . '_mode';
}

/** Name behind an `?employer=<slug>` / `?employer_id=<n>` scope, for its chip. */
function employer_scope_label(string $key, string $value): string {
    $st = db()->prepare($key === 'employer' ? 'SELECT name FROM employers WHERE slug = ?' : 'SELECT name FROM employers WHERE id = ?');
    $st->execute([$value]);
    return (string)($st->fetchColumn() ?: $value);
}

function filter_value_label(string $key, string $value): string {
    return match ($key) {
        'judet'                            => judet_names()[$value] ?? $value,
        'studies_level'                    => STUDIES_LABELS[$value] ?? $value,
        'anomaly'                          => ANOMALY_LABELS[$value] ?? $value,
        'work_type'                        => WORK_TYPE_LABELS[$value] ?? $value,
        'exp_level'                        => bucket_label(EXP_BUCKETS, $value),
        'salary_bucket'                    => bucket_label(SALARY_BUCKETS, $value),
        'status'                           => STATUS_LABELS[$value] ?? $value,
        'remote'                           => 'Telemuncă',
        'schema'                           => $value === 'yes' ? 'Structurată' : 'Text brut',
        'isced'                            => isced_label($value),
        'eqf'                              => EQF_LABELS[(int)$value] ?? $value,
        'lang'                             => language_token_label($value),
        'credential'                       => credential_kind_label($value),
        'domain'                           => policy_domain_label($value),
        'stage'                            => exam_stage_label($value),
        'computer'                         => $value === 'solicitat' ? 'Calculator solicitat' : 'Fără calculator',
        'funding'                          => FUNDING_SOURCE_LABELS[$value] ?? $value,
        'duration'                         => CONTRACT_DURATION_LABELS[$value] ?? $value,
        'schedule'                         => SCHEDULE_LABELS[$value] ?? $value,
        'shift'                            => 'Lucru în ture / gărzi',
        'sector'                           => EMPLOYER_SECTOR_LABELS[$value] ?? $value,
        'has_salary'                       => 'Cu salariu estimat',
        'expires_after', 'expires_before'  => fmt_date($value),
        'family'                           => family_label($value),
        'seniority'                        => seniority_label($value),
        'level'                            => job_level_label($value),
        'employer', 'employer_id'          => employer_scope_label($key, $value),
        default                            => $value,
    };
}

/**
 * One removable chip per active filter *value*, so a three-județ selection
 * yields three chips that each drop only themselves.
 *
 * `href` is the no-JS fallback; `param`/`value` let the click handler in
 * list.php uncheck the matching sidebar input and re-fire the HTMX form
 * instead, which keeps the form and the URL in sync.
 */
function active_filter_chips(): array {
    $chips = [];
    foreach (FILTER_CHIP_GROUPS as $key => $group) {
        $raw = $_GET[$key] ?? null;
        if ($raw === null || $raw === '' || $raw === []) continue;
        if ($key === 'status' && $raw === DEFAULT_STATUS) continue; // the default reads as "no filter"

        foreach ((array)$raw as $value) {
            $value = trim((string)$value);
            if ($value === '') continue;
            $chips[] = [
                'param' => $key,
                'value' => $value,
                'group' => $group,
                'label' => filter_value_label($key, $value),
                'href'  => qs_without($key, is_array($raw) ? $value : null),
            ];
        }
    }
    return $chips;
}

// ---- URLs ----

/** Romanian-aware slugifier: ă/â/î/ș/ț fold to their ASCII bases, not to "?". */
function ro_slug(string $s, int $max = 60): string {
    $s = strtr($s, [
        'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't',
    ]);
    $s = mb_strtolower($s, 'UTF-8');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false) $s = $t;
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    if (strlen($s) > $max) {
        $s = substr($s, 0, $max);
        $s = preg_replace('/-[^-]*$/', '', $s) ?: $s;   // never cut mid-word
    }
    return $s;
}

/**
 * Canonical detail URL: `/job/1234-inspector-primaria-cluj/`.
 * The id stays in front so lookup is still a primary-key hit and the slug can
 * change with the title without breaking the link.
 */
function job_url(array $p): string {
    $slug = ro_slug((string)($p['title'] ?? ''));
    return '/job/' . (int)$p['id'] . ($slug !== '' ? '-' . $slug : '') . '/';
}

/**
 * Human-readable place for a posting row: "Cluj-Napoca, Cluj", or just "Cluj"
 * when the source gave no locality.
 *
 * The county alone is what the facet filters on — `locality` carries the city
 * precision that used to be welded into `judet_name` as "CLUJ-NAPOCA, Cluj".
 */
function place_label(array $p): string {
    $judet    = trim((string)($p['judet_name'] ?? ''));
    $locality = trim((string)($p['locality'] ?? ''));
    if ($locality === '') return $judet;
    if ($judet === '' || mb_strtolower($locality) === mb_strtolower($judet)) return $locality;
    return $locality . ', ' . $judet;
}

/**
 * Primary/secondary title pair for a posting row.
 *
 * `occ_canonical` (the COR-normalised occupation from `normalize-titles.py`)
 * is the primary title when present — the raw scraped `title` still carries
 * grade, seat count and department clutter ("ASISTENT MEDICAL GENERALIST,
 * TREAPTA I (2 POSTURI)"). The raw title is always kept as the secondary
 * line, unless it's blank or the two already read the same.
 */
function display_title(array $p): array {
    $raw       = trim((string)($p['title'] ?? ''));
    $canonical = trim((string)($p['occ_canonical'] ?? ''));
    if ($canonical === '' || $raw === '' || mb_strtolower($canonical) === mb_strtolower($raw)) {
        return ['primary' => $raw ?: $canonical, 'secondary' => null, 'normalized' => false];
    }
    return ['primary' => $canonical, 'secondary' => $raw, 'normalized' => true];
}

// ---- Browser-local save/hide (UX-09) ----

/** Identity and minimal snapshot of a posting, as data-* attributes (all escaped). */
function pref_attrs(array $p, ?string $employer = null): string {
    $dl = posting_deadline($p);
    $a = [
        'data-posting'         => '',
        'data-pref-id'         => (string)(int)$p['id'],
        'data-pref-url'        => (string)($p['url'] ?? ''),
        'data-pref-path'       => job_url($p),
        'data-pref-title'      => display_title($p)['primary'],
        'data-pref-employer'   => $employer ?? (string)($p['employer_name'] ?? ''),
        'data-pref-place'      => place_label($p),
        'data-pref-date'       => (string)($dl['date'] ?? ''),
        'data-pref-date-source' => (string)($dl['source'] ?? ''),
    ];
    $out = '';
    foreach ($a as $k => $v) $out .= ' ' . $k . ($v === '' && $k === 'data-posting' ? '' : '="' . e($v) . '"');
    return $out;
}

/**
 * Save and hide icon buttons for one posting, meant to sit inline right after
 * the title (outside the title link). Rendered hidden: they need JavaScript,
 * which un-hides them (no-JS readers see only normal links). Real buttons;
 * the accessible name carries the title so several rows are distinguishable,
 * and saved.js rewrites it (and aria-pressed) to match the stored state.
 * The glyphs are one shared SVG sprite (inc/header.php), so a row stays lean.
 * 20px glyph inside a 32px hit area; the negative block margin keeps that
 * hit area from stretching the title's line box.
 */
function pref_controls(array $p, bool $with_hide = true): string {
    $title = e(display_title($p)['primary']);
    // Classes live in assets/app.css (.pref-btn / .pref-ico): 4 buttons' worth of
    // utilities per row would crowd the deadline out of deadline_test's window.
    $icon = static fn(string $id): string =>
        '<svg class="pref-ico" aria-hidden="true"><use href="#' . $id . '"/></svg>';
    $h = '<span data-pref-controls hidden class="pref-group">'
       . '<button type="button" data-pref="save" aria-pressed="false" aria-label="Salvează anunțul: ' . $title
       . '" class="pref-btn pref-save">' . $icon('i-bookmark') . '</button>';
    if ($with_hide) {
        $h .= '<button type="button" data-pref="hide" aria-pressed="false" aria-label="Ascunde anunțul: ' . $title
            . '" class="pref-btn pref-hide">' . $icon('i-close') . '</button>';
    }
    return $h . '</span>';
}

/** "Hidden" marker for a posting; saved.js shows it while the posting is hidden. */
function pref_flag(): string {
    return '<span data-pref-flag hidden class="pref-flag">Ascuns din rezultate</span>';
}

function site_origin(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') $scheme = 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'posturi.gov2.ro';
    return $scheme . '://' . $host;
}

// ---- Request timing (BETA-CACHE) ----

/**
 * Close the current timed section under $name. index.php sends the sections
 * as a Server-Timing header, so the browser's network panel (or curl -D -)
 * shows where an uncached page spends its time on the real host.
 */
function timing_mark(string $name): void {
    global $__timings, $__timing_last;
    if (!isset($__timing_last)) return;
    $now = microtime(true);
    $__timings[] = [$name, ($now - $__timing_last) * 1000];
    $__timing_last = $now;
}

// ---- HTML helpers ----

function e(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function checked_if(bool $cond): string {
    return $cond ? ' checked' : '';
}

function selected_if(bool $cond): string {
    return $cond ? ' selected' : '';
}

// ---- Filter application ----

/**
 * SQL condition and binds for one EXP_BUCKETS bucket over an experience column.
 *
 * The one definition of what a bucket means. build_filters() ORs the selected
 * buckets with it and pages/list.php counts each bucket with it, so the number
 * next to a checkbox and the rows that checkbox returns cannot drift apart (they
 * were two hand-copied SQL blocks before DATA-04). The lowest bucket also takes
 * NULL — "no experience stated" — which is what the sidebar's explanation says.
 *
 * `CAST(? AS REAL)`: PDO binds every value as text; a bare column keeps REAL
 * affinity and converts it, but this runs against a view on old exports where
 * that affinity is not guaranteed, and a REAL compared with a TEXT is always
 * "less than" it.
 *
 * @return array{0: string, 1: array}  [sql, binds]
 */
function exp_bucket_condition(array $bucket, string $col = 'r.exp_years'): array {
    if ($bucket['min'] == 0 && $bucket['max'] !== null) {
        return ["($col IS NULL OR $col < CAST(? AS REAL))", [$bucket['max']]];
    }
    if ($bucket['max'] === null) {
        return ["$col >= CAST(? AS REAL)", [$bucket['min']]];
    }
    return ["($col >= CAST(? AS REAL) AND $col < CAST(? AS REAL))", [$bucket['min'], $bucket['max']]];
}

/**
 * Role-scoped filter groups (DATA-04): param => column of `posting_roles` (alias r).
 * Only the JSON-array groups are listed; EQF, study level and experience have their
 * own typed conditions in build_filters(). Language, domain, exam stage and the
 * rest are posting-level facts in the extraction schema and stay on `j`.
 */
const ROLE_ARRAY_GROUPS = [
    'isced'      => 'isced_fields',
    'skill'      => 'skills',
    'credential' => 'credentials',
];

/**
 * Build WHERE clause fragments and bindings from GET params.
 *
 * Returns ['where' => string[], 'binds' => array, 'fts' => bool] plus the same
 * selection split in two for callers that count role-scoped facets:
 *
 *   posting_where / posting_binds  every announcement-level condition (alias j)
 *   role_where / role_binds        the role-scoped groups (alias r) — EQF, study
 *                                  level, experience, field, skill, credential
 *
 * `where` is posting_where plus ONE clause, `EXISTS (SELECT 1 FROM posting_roles r
 * WHERE r.posting_id = j.id AND <every role condition>)`, so all role-scoped
 * groups must be satisfied by the same role (OR inside a group, AND across them)
 * and an announcement is returned once however many of its roles match. The
 * EXISTS clause comes last in `where` and its binds last in `binds`.
 */
function build_filters(array $p, bool $exclude_key = false, string $excl = ''): array {
    $where = [];
    $binds = [];
    $rwhere = [];   // role-scoped conditions, alias r — see the EXISTS below
    $rbinds = [];
    $fts = false;

    $q         = trim($p['q'] ?? '');
    $judets    = (array)($p['judet']        ?? []);
    $levels    = (array)($p['level']        ?? []);
    $types     = (array)($p['type']         ?? []);
    $cats      = (array)($p['categorie']    ?? []);
    $emp_cats  = (array)($p['employer_cat'] ?? []);
    $families  = (array)($p['family']       ?? []);
    $seniorities = (array)($p['seniority']  ?? []);
    $work_types = (array)($p['work_type']   ?? []);
    $exp_levels = (array)($p['exp_level']   ?? []);
    $studies    = (array)($p['studies_level'] ?? []);
    $remote     = $p['remote']       ?? '';
    $computer   = $p['computer']     ?? '';
    $sal_bucket = $p['salary_bucket'] ?? '';
    $exp_before = $p['expires_before'] ?? '';
    $exp_after  = $p['expires_after']  ?? '';
    $status     = $p['status'] ?? DEFAULT_STATUS;

    // Only claim FTS when the query actually yields terms — a query of pure
    // punctuation would otherwise reach SQLite as an empty MATCH expression.
    if ($q && $excl !== 'q' && fts_query($q) !== '') {
        $fts = true;
        // FTS join handled in caller; rank sort also set there
    }

    if ($excl !== 'status') {
        // FIX-05: one status vocabulary (export's application_status), shared
        // by every surface. "Active" = open applications: a confirmed date
        // ahead, an unconfirmed expiry fallback (visibly qualified in rows),
        // or no date at all — those are not known-closed. Old ?status=all
        // links still resolve: 'all' adds no clause.
        if ($status === 'active') {
            $where[] = "j.application_status IN (" . OPEN_STATUS_SQL . ")";
        } elseif ($status === 'soon') {
            $where[] = "j.application_status IN (" . OPEN_STATUS_SQL . ")";
            $today = ro_today();
            $where[] = "(" . DEADLINE_COL . " >= ? AND " . DEADLINE_COL . " <= ?)";
            $binds[] = $today;
            $binds[] = (new DateTimeImmutable($today, new DateTimeZone('Europe/Bucharest')))
                ->modify('+7 days')->format('Y-m-d');
        } elseif ($status === 'closed') {
            $where[] = "j.application_status = 'closed'";
        } elseif ($status === 'unknown') {
            $where[] = "j.application_status = 'unknown'";
        }
        // 'all' adds no clause
    }
    // Employer scope — `?employer=<slug>` (the angajator-profile feeds) or a
    // legacy `?employer_id=<n>`. Slug wins when both are present.
    $employer_slug = trim((string)($p['employer'] ?? ''));
    $employer_id   = (int)($p['employer_id'] ?? 0);
    if ($employer_slug !== '' && $excl !== 'employer') {
        $where[] = "j.employer_id = (SELECT id FROM employers WHERE slug = ?)";
        $binds[] = $employer_slug;
    } elseif ($employer_id > 0 && $excl !== 'employer') {
        $where[] = "j.employer_id = ?";
        $binds[] = $employer_id;
    }
    if ($judets && $excl !== 'judet_slugs') {
        $ph = implode(',', array_fill(0, count($judets), '?'));
        $where[] = "j.judet_slug IN ($ph)";
        array_push($binds, ...$judets);
    }
    if ($levels && $excl !== 'levels') {
        $ph = implode(',', array_fill(0, count($levels), '?'));
        $where[] = "j.job_level IN ($ph)";
        array_push($binds, ...$levels);
    }
    if ($types && $excl !== 'types') {
        $ph = implode(',', array_fill(0, count($types), '?'));
        $where[] = "j.job_type IN ($ph)";
        array_push($binds, ...$types);
    }
    if ($cats && $excl !== 'categories') {
        $ph = implode(',', array_fill(0, count($cats), '?'));
        $where[] = "j.categorie IN ($ph)";
        array_push($binds, ...$cats);
    }
    if ($emp_cats && $excl !== 'employer_cats') {
        $ph = implode(',', array_fill(0, count($emp_cats), '?'));
        $where[] = "j.employer_category IN ($ph)";
        array_push($binds, ...$emp_cats);
    }
    if ($families && $excl !== 'families') {
        $ph = implode(',', array_fill(0, count($families), '?'));
        $where[] = "j.inf_profession_family IN ($ph)";
        array_push($binds, ...$families);
    }
    if ($seniorities && $excl !== 'seniorities') {
        $ph = implode(',', array_fill(0, count($seniorities), '?'));
        $where[] = "j.inf_seniority IN ($ph)";
        array_push($binds, ...$seniorities);
    }
    if ($work_types && $excl !== 'work_types') {
        $ph = implode(',', array_fill(0, count($work_types), '?'));
        $where[] = "j.inf_work_type IN ($ph)";
        array_push($binds, ...$work_types);
    }
    if ($exp_before) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp_before)) {
            $where[] = DEADLINE_COL . " <= ?";
            $binds[] = $exp_before;
        }
    }
    if ($exp_after) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp_after)) {
            $where[] = DEADLINE_COL . " >= ?";
            $binds[] = $exp_after;
        }
    }
    if ($remote && $excl !== 'remote') {
        $where[] = "j.inf_remote_eligible = 1";
    }
    if ($computer && $excl !== 'computer') {
        if ($computer === 'solicitat') {
            $where[] = "j.inf_requires_computer = 1";
        } elseif ($computer === 'nesolicitat') {
            $where[] = "(j.inf_requires_computer IS NULL OR j.inf_requires_computer != 1)";
        }
    }
    if ($exp_levels && $excl !== 'exp_levels') {
        $exp_clauses = [];
        foreach ($exp_levels as $bk) {
            foreach (EXP_BUCKETS as $bucket) {
                if ($bucket['key'] !== $bk) continue;
                [$c, $cb] = exp_bucket_condition($bucket);
                $exp_clauses[] = $c;
                array_push($rbinds, ...$cb);
            }
        }
        if ($exp_clauses) $rwhere[] = '(' . implode(' OR ', $exp_clauses) . ')';
    }
    if ($studies && $excl !== 'studies_levels') {
        $ph = implode(',', array_fill(0, count($studies), '?'));
        $rwhere[] = "+r.study_level IN ($ph)";   // unary +: see the EXISTS note at the end
        array_push($rbinds, ...$studies);
    }
    // ---- JSON-array filters ----
    // Each column is probed with LIKE '%"value"%'. The v3_* ones are no-ops
    // until a v3 extraction has run; `anomaly` is populated by the inference
    // pass and joined here since it is the same shape — which also gets it the
    // ESCAPE it always needed (every ANOMALY_LABELS key contains an underscore,
    // and `_` is a LIKE wildcard) and drops a hand-rolled sanitiser in favour
    // of the bound parameter that was already doing the real work.
    //
    // The probes for one group are joined by that group's combine mode, so a
    // group lands as a single parenthesised clause and still ANDs against every
    // other filter. Before 2026-09-09 each value emitted its own top-level
    // clause, i.e. every one of these groups was AND-within — see
    // FACET_MODE_PARAMS for why that was wrong for five of the six.
    foreach ([
        'isced'      => 'v3_isced_fields',
        'skill'      => 'v3_skills',
        'lang'       => 'v3_languages',
        'credential' => 'v3_credentials',
        'domain'     => 'v3_policy_domains',
        'stage'      => 'v3_exam_stages',
        'anomaly'    => 'inf_anomaly_flags',
    ] as $param => $column) {
        $values = array_filter((array)($p[$param] ?? []), fn ($v) => (string)$v !== '');
        if (!$values || $excl === $param) continue;
        // Field, skill and credential are facts about a role: probe the role row.
        $on_role = isset(ROLE_ARRAY_GROUPS[$param]);
        $target  = $on_role ? 'r.' . ROLE_ARRAY_GROUPS[$param] : "j.$column";
        $probes = [];
        $pb = [];
        foreach ($values as $value) {
            // ESCAPE is required: these vocabularies are full of underscores
            // ("09_sanatate_asistenta_sociala"), and `_` is a LIKE wildcard.
            // Escaping without declaring the escape character matched nothing.
            $probes[] = "$target LIKE ? ESCAPE '\\'";
            $pb[]     = '%"' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$value) . '"%';
        }
        $glue = facet_mode($param, $p) === 'all' ? ' AND ' : ' OR ';
        if ($on_role) {
            $rwhere[] = '(' . implode($glue, $probes) . ')';
            array_push($rbinds, ...$pb);
        } else {
            $where[] = '(' . implode($glue, $probes) . ')';
            array_push($binds, ...$pb);
        }
    }
    // Exact level, like every other facet in the sidebar. This used to be
    // `v3_eqf_level <= ?` ("a candidate above the minimum still qualifies"),
    // but the facet counts next to each checkbox are a plain GROUP BY on the
    // level — so "Doctorat 12" returned 1437 rows headed by îngrijitoare.
    // Match-what-I-qualify-for belongs to CV matching, not to a browse facet.
    $eqf = array_values(array_filter(
        array_map('intval', (array)($p['eqf'] ?? [])),
        fn ($v) => isset(EQF_LABELS[$v])
    ));
    if ($eqf && $excl !== 'eqf') {
        // CAST: the unary + below strips the column's INTEGER affinity, and PDO binds
        // text — an unaffinitied INTEGER never equals the TEXT '6' (it matched nothing).
        $ph = implode(',', array_fill(0, count($eqf), 'CAST(? AS INTEGER)'));
        $rwhere[] = "+r.eqf_level IN ($ph)";   // unary +: see the EXISTS note at the end
        array_push($rbinds, ...$eqf);
    }

    // Whether the posting has LLM-extracted Schema.org sections, or only the
    // raw scraped body. Ported from the Django "Dev" panel (commit c9be4ce);
    // it is useful to readers too, not just for measuring backfill coverage.
    $schema = $p['schema'] ?? '';
    if ($schema && $excl !== 'schema') {
        $where[] = $schema === 'yes' ? "j.has_schema = 1" : "j.has_schema = 0";
    }
    // Salary buckets read the ESTIMATE, not the announced figure. Only 44 of
    // 9,757 postings state a salary in the text, so the old `inf_salary_min`
    // facet matched ~13 rows and was gated behind a >= 100 threshold that never
    // opened. `sal_min` comes from the draft salary grid and covers everything
    // with a resolved occupation. COALESCE keeps a genuinely announced salary
    // ahead of the estimate where one exists.
    //
    // CAST(? AS REAL) is load-bearing. PDO's execute($array) binds every value
    // as text, and COALESCE() returns an expression with no column affinity, so
    // SQLite compares a REAL against a TEXT — and a number is always less than
    // a string, making every `>=` false. The old `inf_salary_min >= ?` only
    // worked because a bare column carries REAL affinity and converts the bind.
    if ($sal_bucket && $excl !== 'salary_bucket') {
        foreach (SALARY_BUCKETS as $b) {
            if ($b['key'] !== $sal_bucket) continue;
            $where[] = SALARY_EXPR . " IS NOT NULL";
            $where[] = SALARY_EXPR . " >= CAST(? AS REAL)";
            $binds[] = $b['min'];
            if ($b['max'] !== null) {
                $where[] = SALARY_EXPR . " < CAST(? AS REAL)";
                $binds[] = $b['max'];
            }
        }
    }

    // Shift work is a yes/no a reader either wants or rules out, so it is a
    // switch rather than a facet group. NULL means "no contract block", which
    // is not the same as "no shift work" and must not match either way.
    if (($p['shift'] ?? '') === '1' && $excl !== 'shift') {
        $where[] = "j.v3_shift_work = 1";
    }

    // Quick choice: only postings we can put a number on.
    if (($p['has_salary'] ?? '') === '1' && $excl !== 'has_salary') {
        $where[] = SALARY_EXPR . " IS NOT NULL";
    }

    // Plain-column facets added with the occupation dictionary and prompt v4.
    foreach ([
        'occupation' => 'occ_canonical',
        'funding'    => 'v4_funding_source',
        'sector'     => 'v4_employer_sector',
        'duration'   => 'v3_contract_duration',
        'schedule'   => 'v3_schedule',
    ] as $param => $column) {
        $values = array_filter((array)($p[$param] ?? []), fn ($v) => (string)$v !== '');
        if (!$values || $excl === $param) continue;
        $ph = implode(',', array_fill(0, count($values), '?'));
        $where[] = "j.$column IN ($ph)";
        array_push($binds, ...array_values($values));
    }

    // One clause for every role-scoped group: they must all hold for the SAME
    // role. Role rows exist for every posting (an undecomposed one has a single
    // implicit row mirroring its flat columns), so a posting with no role-scoped
    // selection is untouched and one with such a selection behaves as before
    // unless its extraction lists several roles.
    //
    // The unary `+` on the EQF and study-level columns above (EQF binds are CAST
    // because `+` also drops the column's integer affinity) keeps SQLite from
    // choosing idx_pr_eqf / idx_pr_study for the correlated subquery. Without
    // sqlite_stat1 rows for posting_roles (a fresh file, a view-less old export)
    // the planner guesses those equality indexes are the most selective and
    // rescans every EQF-6 role once per posting — 250 ms per query on 1,848
    // postings, against 1 ms probing by posting_id (the primary key's prefix).
    $posting_where = $where;
    $posting_binds = $binds;
    if ($rwhere) {
        $where[] = 'EXISTS (SELECT 1 FROM posting_roles r WHERE r.posting_id = j.id AND '
                 . implode(' AND ', $rwhere) . ')';
        array_push($binds, ...$rbinds);
    }

    return [
        'where' => $where, 'binds' => $binds, 'fts' => $fts,
        'posting_where' => $posting_where, 'posting_binds' => $posting_binds,
        'role_where' => $rwhere, 'role_binds' => $rbinds,
    ];
}

/**
 * Run a facet GROUP BY count query, returning [{value, label, count}].
 * $col is the column in job_postings (aliased as j).
 * $base_where and $binds come from build_filters() excluding this facet's key.
 */
function facet_counts(
    string $col, array $base_where, array $binds,
    bool $fts, string $q_term, int $limit = 0
): array {
    $join = '';
    $w = $base_where;
    $b = $binds;
    if ($fts && ($match = fts_query($q_term)) !== '') {
        $join = "JOIN job_postings_fts fts ON fts.rowid = j.id";
        $w[] = "job_postings_fts MATCH ?";
        $b[] = $match;
    }
    $w[] = "j.$col != ''";
    $w[] = "j.$col IS NOT NULL";
    $where_sql = 'WHERE ' . implode(' AND ', $w);
    $lim = $limit > 0 ? "LIMIT $limit" : '';
    $sql = "SELECT j.$col AS val, COUNT(*) AS cnt
            FROM job_postings j $join $where_sql
            GROUP BY j.$col ORDER BY cnt DESC $lim";
    $stmt = db()->prepare($sql);
    $stmt->execute($b);
    return $stmt->fetchAll();
}

/**
 * Turn a user query into an FTS5 MATCH expression.
 *
 * Every term is quoted (so FTS5 operators typed by accident are literals) and
 * the terms are AND-ed, because quoting the *whole* query made it a single
 * phrase — "inspector primărie" then matched only that exact adjacent pair and
 * returned nothing. The trailing term gets a prefix wildcard so the 400ms
 * live-search doesn't show "no results" for every half-typed word.
 *
 * Returns '' when the query has no usable terms; callers must then skip the
 * MATCH clause entirely rather than pass an empty expression to SQLite.
 */
function fts_query(string $q): string {
    $terms = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$terms) return '';

    $terms = array_slice($terms, 0, 12); // cap pathological input
    $last  = count($terms) - 1;
    $parts = [];
    foreach ($terms as $i => $term) {
        $quoted = '"' . str_replace('"', '""', $term) . '"';
        // Single letters make useless prefixes ("a*" matches half the corpus).
        $parts[] = ($i === $last && mb_strlen($term) >= 2) ? $quoted . ' *' : $quoted;
    }
    return implode(' AND ', $parts);
}

/** Rank title matches above body matches. Column order matches the FTS5 DDL. */
const FTS_RANK = 'bm25(job_postings_fts, 10.0, 3.0, 1.0, 1.0)';

// ---- Statistics scope (FIX-09) ----

/** The publication_monthly layout this code reads (export-to-sqlite.py PUBLICATION_AGG_VERSION). */
const PUBLICATION_AGG_VERSION = 1;

/** How many months /statistici draws; older intake is one summed line. */
const PUBLICATION_MONTHS_SHOWN = 24;

/**
 * The historical publication aggregates of the export: announcements the source published
 * per month and per county, over the WHOLE database whatever `--active-only` left in
 * job_postings (see the table definition in export-to-sqlite.py). null = this export has
 * none (an older file, db.php's shim answers with an empty view) or only a layout this
 * code does not know: the page then says "indisponibil" and shows the current export only,
 * never history inferred from the surviving rows.
 *
 * `months` is a continuous series, so a month with no announcements is a zero bar rather
 * than a silent gap; `older` sums what falls before the window. `unknown_date` is the
 * explicit missing-date bucket. All counts include cancelled announcements (`cancelled`
 * says how many of them).
 *
 * @return array{total:int, cancelled:int, unknown_date:int, first:?string, last:?string,
 *               older:int, older_before:?string, months:list<array{month:string,postings:int,cancelled:int,partial:bool}>,
 *               counties:list<array{name:string,slug:string,postings:int}>, no_county:int}|null
 */
function publication_history(): ?array {
    static $hist = false;          // false = not looked up
    if ($hist !== false) {
        return $hist;
    }
    $hist = null;
    try {
        $st = db()->prepare("SELECT month, SUM(postings) AS postings, SUM(cancelled) AS cancelled
                             FROM publication_monthly WHERE agg_version = ? GROUP BY month ORDER BY month");
        $st->execute([PUBLICATION_AGG_VERSION]);
        $rows = $st->fetchAll();
        if (!$rows) {
            return $hist;
        }
        $by_month = [];
        $unknown = 0;
        $total = 0;
        $cancelled = 0;
        foreach ($rows as $r) {
            $total += (int)$r['postings'];
            $cancelled += (int)$r['cancelled'];
            if (preg_match('/^\d{4}-\d{2}$/', (string)$r['month'])) {
                $by_month[$r['month']] = ['postings' => (int)$r['postings'], 'cancelled' => (int)$r['cancelled']];
            } else {
                $unknown += (int)$r['postings'];     // 'unknown' = published_at was NULL
            }
        }
        ksort($by_month);
        $first = $by_month ? array_key_first($by_month) : null;
        $last  = $by_month ? array_key_last($by_month) : null;

        $months = [];
        $older = 0;
        $older_before = null;
        if ($first !== null) {
            $tz = new DateTimeZone('Europe/Bucharest');
            $end = new DateTimeImmutable($last . '-01', $tz);
            $start = $end->modify('-' . (PUBLICATION_MONTHS_SHOWN - 1) . ' months');
            $window_start = max($start->format('Y-m'), $first);
            if ($first < $window_start) {
                $older_before = $window_start;
                foreach ($by_month as $m => $v) {
                    if ($m < $window_start) $older += $v['postings'];
                }
            }
            // The build month is still filling up: its bar is a partial month.
            $build = build_meta();
            $build_month = null;
            if (!empty($build['built_at'])) {
                $build_month = (new DateTimeImmutable($build['built_at']))->setTimezone($tz)->format('Y-m');
            }
            for ($d = new DateTimeImmutable($window_start . '-01', $tz); $d->format('Y-m') <= $last; $d = $d->modify('+1 month')) {
                $m = $d->format('Y-m');
                $v = $by_month[$m] ?? ['postings' => 0, 'cancelled' => 0];
                $months[] = ['month' => $m, 'postings' => $v['postings'], 'cancelled' => $v['cancelled'],
                             'partial' => $m === $build_month];
            }
        }

        $st = db()->prepare("SELECT p.judet_id, j.name, j.slug, SUM(p.postings) AS postings
                             FROM publication_monthly p LEFT JOIN judete j ON j.id = p.judet_id
                             WHERE p.agg_version = ? AND p.judet_id != 0
                             GROUP BY p.judet_id ORDER BY postings DESC, p.judet_id LIMIT 10");
        $st->execute([PUBLICATION_AGG_VERSION]);
        $counties = [];
        foreach ($st->fetchAll() as $r) {
            $counties[] = ['name' => (string)($r['name'] ?? ('Județ #' . $r['judet_id'])),
                           'slug' => (string)($r['slug'] ?? ''), 'postings' => (int)$r['postings']];
        }
        $st = db()->prepare("SELECT COALESCE(SUM(postings), 0) FROM publication_monthly WHERE agg_version = ? AND judet_id = 0");
        $st->execute([PUBLICATION_AGG_VERSION]);

        $hist = ['total' => $total, 'cancelled' => $cancelled, 'unknown_date' => $unknown,
                 'first' => $first, 'last' => $last, 'older' => $older, 'older_before' => $older_before,
                 'months' => $months, 'counties' => $counties, 'no_county' => (int)$st->fetchColumn()];
    } catch (Exception $e) {
        $hist = null;
    }
    return $hist;
}

/**
 * What the current export holds, as a sentence for /statistici: what it contains
 * (`active_only` = only announcements still open at build time; otherwise every row of the
 * database) and when it was built. Without build_meta the build date is said to be unknown.
 */
function stats_scope_sentence(): string {
    $meta = build_meta();
    $when = build_time('d.m.Y, H:i');
    if (empty($meta) || !isset($meta['active_only'])) {
        $what = 'anunțurile din acest fișier de date (nu se știe dacă include și anunțuri expirate)';
    } else {
        $what = (int)$meta['active_only'] === 1
            ? 'doar anunțurile încă neexpirate și neanulate la data generării'
            : 'toate anunțurile din baza de date, inclusiv cele expirate';
    }
    return "Conține $what; generat " . ($when !== '' ? "la $when" : 'la o dată necunoscută') . '.';
}
