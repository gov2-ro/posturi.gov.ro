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
 * precedence as SALARY_EXPR, so a row and the salary filter never disagree. The text is the
 * net pay per month (net_label()); the gross range is in `gross` (the title of the figure, the
 * detail page). An estimate is always labelled as one. Null when there is no figure (or an old
 * export without the columns).
 *
 * An estimate from the asset declarations (ADR 0008 rule 9; `sal_variant` starts with "declaratii") says so in
 * `kind`: "după declarații de avere"; any other estimate has no `kind` (the row says "estimat").
 *
 * @return array{label: string, text: string, gross: string, estimated: bool, kind?: string}|null
 */
function list_salary(array $p): ?array {
    if (!empty($p['project_hire'])) {
        // A post hired for an EU/PNRR project: never a grid figure (SALARY-EU-DETAIL).
        $pv = project_pay_view($p);
        $short = $pv ? ($pv['month'] ?? $pv['rate']) : 'salariu din bugetul proiectului';
        return ['label' => $pv ? 'Salariu anunțat' : 'Post finanțat din fonduri europene', 'text' => $short,
                'gross' => '', 'estimated' => false, 'project' => true,
                'title' => $pv ? $pv['full'] : 'Post finanțat din fonduri europene: salariul depinde de bugetul proiectului'];
    }
    foreach ([['inf_salary_min', 'inf_salary_max', 'Salariu anunțat', false],
              ['sal_min', 'sal_max', 'Salariu estimat', true]] as [$lo, $hi, $label, $estimated]) {
        $min = $p[$lo] ?? null;
        if ($min === null || $min === '' || (float)$min <= 0) continue;
        $rawmax = ($p[$hi] ?? null) === null || ($p[$hi] ?? '') === '' ? null : (float)$p[$hi];
        $imin = (int)round((float)$min);
        $imax = $rawmax === null ? $imin : (int)round($rawmax);
        $fmt = static fn(int $v): string => number_format($v, 0, ',', '.');
        $gross = $imax > $imin ? $fmt($imin) . '–' . $fmt($imax) . ' lei' : $fmt($imin) . ' lei';
        $declared = $estimated && str_starts_with((string)($p['sal_variant'] ?? ''), 'declaratii');
        $row = ['label' => $declared ? 'Estimare după declarații de avere' : $label, 'text' => net_label((float)$min, $rawmax),
                'gross' => $gross, 'estimated' => $estimated];
        if ($declared) $row['kind'] = 'după declarații de avere';
        return $row;
    }
    return null;
}

/**
 * The pay a project-hired post states, as words (SALARY-EU-DETAIL; the columns come from
 * classify.project_pay()). `rate` is the figure with its unit and basis ("50 lei/oră net"),
 * `hours` the hours per month, `month` the month figure ("≈ 4.200 lei net/lună": the figure
 * itself when the unit is a month; rate × hours when the unit is an hour and the hours are
 * known; none otherwise), `note` says when the basis is not stated, `full` joins the parts.
 * Null when the post states no pay.
 *
 * @return array{rate: string, hours: ?string, month: ?string, note: string, full: string, quote: string}|null
 */
function project_pay_view(array $p): ?array {
    $amount = (float)($p['proj_pay_amount'] ?? 0);
    $unit = (string)($p['proj_pay_unit'] ?? '');
    if (empty($p['project_hire']) || $amount <= 0 || !in_array($unit, ['ora', 'luna'], true)) return null;
    $basis = (string)($p['proj_pay_basis'] ?? '');
    $b = $basis === 'brut' || $basis === 'net' ? ' ' . $basis : '';
    $fmt = static fn(float $v): string => number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2, ',', '.');
    $hours = (float)($p['proj_hours_month'] ?? 0);
    $month = null;
    if ($unit === 'luna') {
        $rate = $fmt($amount) . ' lei' . $b . '/lună';
        $month = $rate;
    } else {
        $rate = $fmt($amount) . ' lei/oră' . $b;
        if ($hours > 0) $month = '≈ ' . $fmt($amount * $hours) . ' lei' . $b . '/lună';
    }
    $hours_s = $unit === 'ora' && $hours > 0 ? $fmt($hours) . ' ore/lună' : null;
    $full = $unit === 'luna' ? $rate : $rate . ($hours_s ? ' · ' . $hours_s : '') . ($month ? ' ' . $month : '');
    return ['rate' => $rate, 'hours' => $hours_s, 'month' => $month,
            'note' => $b === '' ? 'brut sau net nespecificat' : '', 'full' => $full,
            'quote' => (string)($p['proj_pay_quote'] ?? '')];
}

/**
 * 261016 "salariu net": take-home pay estimated from a monthly gross, for an
 * employee at their base job with no dependants. Arithmetic, never extracted —
 * postings almost never state pay, so this runs on the same figure the row shows.
 * Codul fiscal 2026 (rates checked 2026-10-16): CAS 25 %, CASS 10 %, income tax
 * 10 % of gross − CAS − CASS − the personal deduction (art. 77): 20 % of the
 * minimum wage up to the minimum wage, 0.5 points less per started 50 lei above
 * it, nothing above minimum wage + 2.000 lei. Left out on purpose: dependants,
 * the minimum-wage tax facility, and the exemptions some posts have (disability,
 * IT, construction) — the result is labelled an estimate wherever it is shown.
 */
const NET_MIN_WAGE = 4325;          // gross minimum wage from 2026-07-01 (HG 146/2026)

function net_from_gross(float $gross): int {
    if ($gross <= 0) return 0;
    $cas  = round($gross * 0.25);
    $cass = round($gross * 0.10);
    $over = $gross - NET_MIN_WAGE;
    if ($over <= 0) {
        $pct = 20.0;
    } elseif ($over > 2000) {
        $pct = 0.0;
    } else {
        $pct = max(0.0, 20.0 - 0.5 * ceil($over / 50));
    }
    $deduction = round(NET_MIN_WAGE * $pct / 100);
    $tax = round(max(0, $gross - $cas - $cass - $deduction) * 0.10);
    return (int)($gross - $cas - $cass - $tax);
}

/**
 * 261016 "show pay visually, compared to the median, on a 1–5 scale": the
 * quintiles of the pay rows show (SALARY_EXPR) over the open postings, computed
 * once per request. Level 3 holds the median; 5 is the top fifth.
 *
 * @return array{cuts: float[], median: float}|null  null with too few figures
 */
function pay_quintiles(): ?array {
    static $q = false;
    if ($q !== false) return $q;
    $st = db()->prepare("SELECT CAST(" . SALARY_EXPR . " AS REAL) FROM job_postings j
        WHERE j.application_status IN (" . OPEN_STATUS_SQL . ") AND " . SALARY_EXPR . " > CAST(? AS REAL)
        ORDER BY 1");
    $st->execute([0]);
    $vals = $st->fetchAll(PDO::FETCH_COLUMN);
    $n = count($vals);
    if ($n < 20) return $q = null;
    $at = static fn(float $f): float => (float)$vals[min($n - 1, (int)floor($f * $n))];
    return $q = ['cuts' => [$at(0.2), $at(0.4), $at(0.6), $at(0.8)], 'median' => $at(0.5)];
}

/** Level 1–5 of a row's pay against pay_quintiles(), with a sentence that says so; null without one. */
function pay_level(array $p): ?array {
    if (!empty($p['project_hire'])) return null;       // the pay of a project post is not on the grid scale
    $v = (float)($p['inf_salary_min'] ?? 0) > 0 ? (float)$p['inf_salary_min'] : (float)($p['sal_min'] ?? 0);
    if ($v <= 0 || !($q = pay_quintiles())) return null;
    $level = 1;
    foreach ($q['cuts'] as $cut) if ($v >= $cut) $level++;
    $median = number_format(net_from_gross($q['median']), 0, ',', '.');
    $words = [1 => 'printre cele mai mici', 2 => 'sub mediană', 3 => 'în jurul medianei',
              4 => 'peste mediană', 5 => 'printre cele mai mari'][$level];
    return ['level' => $level,
            'text' => "Salariu $words: treapta $level din 5 față de posturile active (mediana $median lei net)"];
}

/** The 1–5 pay meter: five small bars, the first `level` filled. Decorative beside a text title. */
function pay_meter(array $p, string $extra = ''): string {
    $pl = pay_level($p);
    if (!$pl) return '';
    $bars = '';
    for ($i = 1; $i <= 5; $i++) $bars .= '<i' . ($i <= $pl['level'] ? ' class="on"' : '') . '></i>';
    return '<span class="pay-meter ' . e($extra) . '" data-level="' . $pl['level'] . '" role="img" aria-label="' . e($pl['text'])
         . '" title="' . e($pl['text']) . '">' . $bars . '</span>';
}

/** classify.QUALITY_CHECKS: key => [group, label, weight]. Keep in step. */
const QUALITY_CHECKS = [
    'studies'      => ['Completitudine', 'Studiile cerute sunt precizate', 8],
    'experience'   => ['Completitudine', 'Experiența cerută este precizată', 7],
    'documents'    => ['Completitudine', 'Lista documentelor de depus', 8],
    'calendar'     => ['Completitudine', 'Calendar cu cel puțin două date', 10],
    'contact'      => ['Completitudine', 'Telefon sau e-mail de contact', 6],
    'duties'       => ['Completitudine', 'Atribuțiile postului', 3],
    'bibliography' => ['Completitudine', 'Bibliografie / tematică', 3],
    'salary'       => ['Salariu și condiții', 'Salariul este precizat', 10],
    'duration'     => ['Salariu și condiții', 'Durata contractului', 5],
    'schedule'     => ['Salariu și condiții', 'Programul de lucru', 5],
    'confirmed'    => ['Termene', 'Termen de depunere precizat (nu doar expirarea)', 8],
    'window'       => ['Termene', 'Cel puțin 10 zile pentru depunere', 12],
    'text'         => ['Claritate', 'Text propriu, nu doar formule standard', 6],
    'specific'     => ['Claritate', 'Cerințe concrete, nu doar calități generice', 4],
    'attachment'   => ['Claritate', 'Anunț complet în pagină sau atașat ca document', 5],
];

/** Short regime word for a result row (261016: replaces the source's unreliable `categorie`). */
const JOB_REGIME_SHORT = ['contractuala' => 'Contractual', 'publica' => 'Funcție publică', 'militara' => 'Funcție militară'];

/** "≈ 2.981 lei net" (or a range) for a gross min/max, '' when there is none. */
function net_label(?float $min, ?float $max = null): string {
    if ($min === null || $min <= 0) return '';
    $fmt = static fn(int $v): string => number_format($v, 0, ',', '.');
    $lo = net_from_gross($min);
    $hi = ($max !== null && $max > $min) ? net_from_gross($max) : $lo;
    return '≈ ' . ($hi > $lo ? $fmt($lo) . '–' . $fmt($hi) : $fmt($lo)) . ' lei net';
}

/**
 * "37 de angajați", "5 angajați", "21 de angajați": Romanian puts "de" after a number whose last two
 * digits are 00 or 20-99 (ADR 0008 rule 9, explainer 2026-11-20).
 */
function ro_count_de(int $n, string $noun): string {
    $mod = $n % 100;
    return $n . (($mod === 0 || $mod >= 20) ? ' de ' : ' ') . $noun;
}

/** True when the estimate of a posting comes from the asset declarations (ADR 0008 rule 9). */
function sal_declared(?array $sal): bool {
    return is_array($sal) && ($sal['baza'] ?? '') === 'declaratii' && is_array($sal['declaratii'] ?? null);
}

/**
 * The figures of the declared-pay block, as text (explainer docs/specs/2026-11-20-salary-declared-explainer.md).
 * `$sal` is the decoded `sal_json`; `$employer` the employer name of the posting (the reader knows that name),
 * '' to use the name of the group. Null when the estimate is not a declared one. Every lei figure is rounded to
 * 50 lei: the build already did it for the net values; the gross is rounded here.
 *
 * @return array{estimare: string, brut: string, p25: string, p75: string, mediana: string, n: string, n_raw: int,
 *               functie: string, institutie: string, crestere: int, eroare: int, an_venit: int}|null
 */
function declared_view(?array $sal, string $employer = ''): ?array {
    if (!sal_declared($sal)) return null;
    $d = $sal['declaratii'];
    $fmt = static fn($v): string => number_format((int)round((float)$v), 0, ',', '.');
    $est = (int)$d['mediana_net'];
    $name = trim($employer) !== '' ? trim($employer) : (string)($sal['motiv'] ?? '');
    return [
        'estimare' => $fmt($est),
        'brut' => $fmt((int)(round(gross_for_net($est) / 50) * 50)),
        'p25' => $fmt($d['p25_net']), 'p75' => $fmt($d['p75_net']), 'mediana' => $fmt($d['mediana_net_2023'] ?? ($est / (float)$d['factor'])),
        'n' => ro_count_de((int)$d['n'], 'angajați'), 'n_raw' => (int)$d['n'],
        'functie' => (string)$d['functie'], 'institutie' => $name,
        'crestere' => (int)round(((float)$d['factor'] - 1) * 100),
        'eroare' => (int)round((float)$d['eroare_tipica']), 'an_venit' => (int)$d['an_venit'],
    ];
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
    return '<p class="robots-disclaimer w-fit max-w-full rounded border border-info-line bg-info px-3 py-2 text-sm text-info-ink ' . e($class) . '">Datele sunt preluate de pe posturi.gov.ro și restructurate cu '
         . '<span aria-hidden="true">🤖</span> roboți. Verificați întotdeauna '
         . '<a href="' . e($href) . '" target="_blank" rel="noopener" class="text-gov underline underline-offset-2 hover:no-underline">anunțul oficial</a>.</p>';
}

/** The version of the gov2 design system in static/gov2/ (ADR 0005). It is the cache key of the two DS links in inc/header.php. */
const GOV2_DS_VERSION = '0.17.1';

/** Feedback address shown in the [?!] panel; empty hides the e-mail link (D9). */
const FEEDBACK_EMAIL = 'midas@gov2.ro';

/** The [?!] panel's FAQ: [anchor on /despre/, question]. Each anchor must exist there. */
const FEEDBACK_FAQ = [
    ['cum-functioneaza',  'De ce diferă unele informații de anunțul oficial?'],
    ['salarii',           'Salariul afișat este cel real?'],
    ['anunturi-expirate', 'Un anunț a dispărut din listă'],
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
    // BETA-V5: a v5 row has no free-text requirement keys; rebuild them from the structured ones.
    $schema = schema_text_fallbacks($schema);

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
    'pnrr'             => 'PNRR',
];

/**
 * 261016: the job's legal regime, read from the title's grade and the body's
 * hiring wording (classify.py::regime). The source `categorie` is the
 * institution's free pick and put muncitori and îngrijitori under "Funcție publică".
 */
const JOB_REGIME_LABELS = [
    'contractuala' => 'Contractuală (Codul muncii)',
    'publica'      => 'Funcție publică (funcționar public)',
    'militara'     => 'Militară (cadre militare)',
];

/** Institution type from the employer's name (classify.py::EMPLOYER_KINDS — keep in step). */
const EMPLOYER_KIND_LABELS = [
    'primarie'          => 'Primării și consilii locale',
    'spital'            => 'Spitale și unități sanitare',
    'scoala'            => 'Școli, licee, grădinițe',
    'asistenta-sociala' => 'Asistență socială (DGASPC, DAS, cămine)',
    'militar'           => 'Unități militare, poliție, penitenciare',
    'universitate'      => 'Universități',
    'servicii-publice'  => 'Servicii publice locale (gospodărie, apă, transport)',
    'cultura'           => 'Cultură (muzee, teatre, biblioteci)',
    'cercetare'         => 'Institute de cercetare',
    'sport'             => 'Cluburi și baze sportive',
    'agentie'           => 'Agenții, autorități, inspectorate',
    'directie'          => 'Alte direcții publice',
    'companie'          => 'Companii și societăți de stat',
    'minister'          => 'Ministere și Guvern',
    'dsp'               => 'Direcții de sănătate publică, DSV, CAS',
    'consiliu-judetean' => 'Consilii județene',
    'prefectura'        => 'Prefecturi',
    'justitie'          => 'Instanțe și parchete',
];

/**
 * 261016: the occupation tree. COR (Clasificarea Ocupațiilor din România) uses
 * ISCO-08's codes for its first four digits; these are its major and sub-major
 * groups, plus the minor groups that answer "what kind of doctor / engineer /
 * teacher". A code with no label here is not offered as a tree level.
 */
const ISCO_GROUP_LABELS = [
    '1' => 'Conducători', '2' => 'Specialiști', '3' => 'Tehnicieni și asimilați',
    '4' => 'Funcționari administrativi', '5' => 'Lucrători în servicii',
    '6' => 'Agricultură, silvicultură, pescuit', '7' => 'Muncitori calificați',
    '8' => 'Operatori de mașini și șoferi', '9' => 'Muncitori necalificați', '0' => 'Forțe armate',
    '11' => 'Conducători în administrație', '12' => 'Conducători administrativi și comerciali',
    '13' => 'Conducători în producție și servicii specializate', '14' => 'Conducători în servicii și comerț',
    '21' => 'Științe și inginerie', '22' => 'Sănătate', '23' => 'Învățământ',
    '24' => 'Afaceri și administrație', '25' => 'IT și comunicații', '26' => 'Juridic, social și cultural',
    '31' => 'Tehnicieni în științe și inginerie', '32' => 'Tehnicieni în sănătate',
    '33' => 'Tehnicieni în afaceri și administrație', '34' => 'Tehnicieni în domeniul juridic, social, cultural',
    '35' => 'Tehnicieni IT și comunicații',
    '41' => 'Funcționari de birou', '42' => 'Funcționari cu publicul', '43' => 'Evidență date și materiale',
    '44' => 'Alți funcționari administrativi',
    '51' => 'Servicii personale (bucătari, administratori, îngrijitori de clădiri)', '52' => 'Vânzări',
    '53' => 'Îngrijire personală (infirmieri, îngrijitori)', '54' => 'Pază și protecție',
    '61' => 'Agricultori și lucrători calificați', '62' => 'Silvicultură și pescuit', '63' => 'Agricultură de subzistență',
    '71' => 'Construcții (zidari, instalatori, zugravi)', '72' => 'Metalurgie, mecanici, lăcătuși',
    '73' => 'Meșteșugari și tipografi', '74' => 'Electricieni și electroniști',
    '75' => 'Alimentație, lemn, textile',
    '81' => 'Operatori la instalații fixe', '82' => 'Asamblori', '83' => 'Șoferi și operatori de utilaje',
    '91' => 'Curățenie și ajutor', '92' => 'Agricultură și silvicultură (necalificați)',
    '93' => 'Construcții, industrie, transport (necalificați)', '94' => 'Ajutori în bucătărie',
    '95' => 'Vânzători ambulanți', '96' => 'Salubritate și alte munci elementare',
    '211' => 'Fizicieni, chimiști, geologi', '212' => 'Matematicieni, statisticieni',
    '213' => 'Biologi, agronomi, specialiști în mediu', '214' => 'Ingineri (construcții, mecanică, chimie …)',
    '215' => 'Ingineri electrotehnici și electroniști', '216' => 'Arhitecți, urbaniști, topografi',
    '221' => 'Medici', '222' => 'Asistenți medicali și moașe', '224' => 'Paramedici',
    '225' => 'Medici veterinari', '226' => 'Farmaciști, stomatologi și alții',
    '231' => 'Profesori universitari', '232' => 'Formatori profesionali', '233' => 'Profesori de liceu și gimnaziu',
    '234' => 'Învățători și educatori', '235' => 'Alți specialiști în învățământ',
    '241' => 'Finanțe și contabilitate', '242' => 'Administrație și politici publice', '243' => 'Vânzări, marketing, PR',
    '261' => 'Juriști', '262' => 'Arhiviști, bibliotecari, muzeografi', '263' => 'Psihologi, asistenți sociali, economiști',
    '264' => 'Autori, jurnaliști, traducători', '265' => 'Artiști',
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
 * The month pay a project-hired post states, as a gross figure in SQL (NULL when it states none, or
 * an hourly rate without hours). A net figure becomes gross with gross_for_net(), an SQLite function
 * of the PDO connection (db.php); a basis that is not stated counts as gross, as for every stated
 * pay (DATA-SALARY-GROSS-NET). A project-hired post never takes the grid figure.
 */
const PROJECT_MONTH_EXPR = "(CASE WHEN j.proj_pay_amount > 0
        AND (j.proj_pay_unit = 'luna' OR (j.proj_pay_unit = 'ora' AND j.proj_hours_month > 0))
    THEN (CASE WHEN j.proj_pay_basis = 'net'
        THEN gross_for_net(j.proj_pay_amount * (CASE WHEN j.proj_pay_unit = 'ora' THEN j.proj_hours_month ELSE 1 END))
        ELSE j.proj_pay_amount * (CASE WHEN j.proj_pay_unit = 'ora' THEN j.proj_hours_month ELSE 1 END) END)
    END)";

/**
 * The pay figure the site filters and sorts on, in SQL.
 *
 * An announced salary wins where one exists, but only 44 of 9,757 postings
 * state one, so in practice this is the estimate from the 2026 draft grid.
 * Defined once because three call sites have to agree: build_filters(), the
 * facet counts in pages/list.php, and the landing shortcut.
 */
const SALARY_EXPR = 'CASE WHEN j.project_hire = 1 THEN ' . PROJECT_MONTH_EXPR
    . ' ELSE COALESCE(j.inf_salary_min, j.sal_min) END';

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

/**
 * The old salary buckets: gross lei per month. Not offered in the filter any more (the filter
 * works in net pay, SALARY_NET_BUCKETS), but every value stays valid with its old meaning, so
 * that a saved search and an email subscription select the same postings as before.
 */
const SALARY_BUCKETS = [
    ['key' => 'sub-3000',   'label' => 'sub 3.000 lei brut',        'min' => 0,    'max' => 3000],
    ['key' => '3000-4000',  'label' => '3.000 – 4.000 lei brut',    'min' => 3000, 'max' => 4000],
    ['key' => '4000-5000',  'label' => '4.000 – 5.000 lei brut',    'min' => 4000, 'max' => 5000],
    ['key' => '5000-7000',  'label' => '5.000 – 7.000 lei brut',    'min' => 5000, 'max' => 7000],
    ['key' => 'peste-7000', 'label' => 'peste 7.000 lei brut',      'min' => 7000, 'max' => null],
];

/**
 * The salary filter: monthly net pay (net_from_gross() of the lower figure SALARY_EXPR gives).
 * `min` and `max` are net lei; the bucket holds min <= net < max. Order = order in the filter.
 */
const SALARY_NET_BUCKETS = [
    ['key' => 'net-sub-3000',   'label' => 'sub 3.000 lei net',        'min' => null, 'max' => 3000],
    ['key' => 'net-3000-4000',  'label' => '3.000 – 4.000 lei net',    'min' => 3000, 'max' => 4000],
    ['key' => 'net-4000-5000',  'label' => '4.000 – 5.000 lei net',    'min' => 4000, 'max' => 5000],
    ['key' => 'net-5000-7000',  'label' => '5.000 – 7.000 lei net',    'min' => 5000, 'max' => 7000],
    ['key' => 'net-peste-7000', 'label' => 'peste 7.000 lei net',      'min' => 7000, 'max' => null],
];

/**
 * Net values that the filter does not list but that stay valid (2026-11-18: "peste 5.000" became
 * 5.000 – 7.000 and peste 7.000). A saved search, an email subscription and a link of the
 * landing page select the same postings as before.
 */
const SALARY_NET_LEGACY = [
    ['key' => 'net-peste-5000', 'label' => 'peste 5.000 lei net',      'min' => 5000, 'max' => null],
];

/** The postings where SALARY_EXPR gives no figure (null or zero). */
const SALARY_NONE = 'fara-estimare';
const SALARY_NONE_LABEL = 'fără salariu estimat';

/**
 * The gross lei (to the cent) from which net_from_gross() gives at least $net: a net threshold
 * as a gross one, found once per request, so that the SQL compares the stored gross column
 * (index-friendly). net_from_gross() dips by 1-3 lei at each 50-lei step of the deduction
 * between 4.325 and 6.325 gross, but it crosses 3.000, 4.000, 5.000 and 7.000 net once and stays above
 * (tests/discovery_test.php checks this over every cent around each threshold), so a binary
 * search finds the one crossing.
 */
function gross_for_net(int $net): float {
    static $memo = [];
    if (isset($memo[$net])) return $memo[$net];
    [$lo, $hi] = [0, 100000000];                       // cents; net_from_gross($hi / 100) >= $net
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi, 2);
        if (net_from_gross($mid / 100) >= $net) { $hi = $mid; } else { $lo = $mid + 1; }
    }
    return $memo[$net] = $lo / 100;
}

/**
 * The SQL of one salary_bucket value: [where fragments, binds], or null for an unknown value
 * (no filter, as before). One definition for build_filters() and the facet counts. Compares the
 * stored gross figure. The CAST is load-bearing: see build_filters().
 *
 * @return array{0: string[], 1: float[]}|null
 */
function salary_bucket_sql(string $key): ?array {
    if ($key === SALARY_NONE) {
        return [['(' . SALARY_EXPR . ' IS NULL OR ' . SALARY_EXPR . ' <= 0)'], []];
    }
    foreach (SALARY_BUCKETS as $b) {
        if ($b['key'] !== $key) continue;
        $w = [SALARY_EXPR . ' IS NOT NULL', SALARY_EXPR . ' >= CAST(? AS REAL)'];
        $bind = [$b['min']];
        if ($b['max'] !== null) { $w[] = SALARY_EXPR . ' < CAST(? AS REAL)'; $bind[] = $b['max']; }
        return [$w, $bind];
    }
    foreach ([...SALARY_NET_BUCKETS, ...SALARY_NET_LEGACY] as $b) {
        if ($b['key'] !== $key) continue;
        $w = [SALARY_EXPR . ' IS NOT NULL', SALARY_EXPR . ' > 0'];
        $bind = [];
        if ($b['min'] !== null) { $w[] = SALARY_EXPR . ' >= CAST(? AS REAL)'; $bind[] = gross_for_net($b['min']); }
        if ($b['max'] !== null) { $w[] = SALARY_EXPR . ' < CAST(? AS REAL)'; $bind[] = gross_for_net($b['max']); }
        return [$w, $bind];
    }
    return null;
}

/** The label of any salary_bucket value (net, old gross, none); the key itself when unknown. */
function salary_bucket_label(string $key): string {
    if ($key === SALARY_NONE) return SALARY_NONE_LABEL;
    foreach ([SALARY_NET_BUCKETS, SALARY_NET_LEGACY, SALARY_BUCKETS] as $set) {
        foreach ($set as $b) if ($b['key'] === $key) return $b['label'];
    }
    return $key;
}

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
    // 2026-10-18: requirements only (grade, studies, experience). The domain,
    // schedule, telework and "Calculator" moved out: the occupation tree says the
    // domain, and schedule / telework show as distinctive tags when they are
    // uncommon (row_tags()) — the stored `inferred.remote_eligible` was also the
    // regex that matched "Remote Sensing".
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
    return $meta;
}

/** Whether the export itself has a column (not a shim's stand-in). Cached per request. */
function export_has_column(string $name): bool {
    static $cols = null;
    if ($cols === null) {
        $cols = array_flip(db()->query("SELECT name FROM pragma_table_info('job_postings') WHERE schema = 'main'")
            ->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (!$cols) {   // older SQLite: pragma_table_info without a schema column
            $cols = array_flip(db()->query("SELECT name FROM main.pragma_table_info('job_postings')")->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }
    }
    return isset($cols[$name]);
}

/**
 * The short facts a result row shows after the pay (2026-10-18): the export's
 * distinctive tags — uncommon facts, rarest first (classify.rank_tags). An export
 * from before the tags falls back to the source labels it always showed.
 */
function row_tags(array $p): array {
    if (export_has_column('tags')) {
        $t = json_decode((string)($p['tags'] ?? '[]'), true);
        return is_array($t) ? array_values(array_filter(array_map('strval', $t), fn($v) => $v !== '')) : [];
    }
    return array_values(array_filter([
        $p['job_level'] ?? '' ? job_level_label((string)$p['job_level']) : '',
        (string)($p['job_type'] ?? ''),
        JOB_REGIME_SHORT[(string)($p['job_regime'] ?? '')] ?? '',
        ($p['nr_posturi'] ?? 0) > 1 ? $p['nr_posturi'] . ' posturi' : '',
    ], fn($v) => $v !== ''));
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

/**
 * The deduced conditions of a posting, label => value: the detail page strip and the feeds
 * read this one list. A condition nobody could deduce is left out, never shown as "—".
 *
 * @return array<string,string>
 */
function posting_inferred_conditions(array $p): array {
    $inferred = inferred_of($p);
    $wt = $inferred['work_type'] ?? null;
    $re = $inferred['remote_eligible'] ?? null;
    $rc = isset($inferred['requires_computer']) ? $inferred['requires_computer'] : null;
    $exp = $inferred['experience_years'] ?? null;
    $st = $inferred['studies_required'] ?? null;
    $smn = $inferred['salary_min'] ?? null;
    $smx = $inferred['salary_max'] ?? null;
    $cond = [];
    if ($wt) $cond['Program'] = work_type_label($wt);
    if ($re) $cond['Telemuncă'] = 'Da';
    if ($rc === true || $rc === 1) $cond['Calculator'] = 'Solicitat' . (($inferred['computer_level'] ?? '') === 'advanced' ? ' (avansat)' : '');
    elseif ($rc === false || $rc === 0) $cond['Calculator'] = 'Nesolicitat';
    if ($exp) $cond['Experiență'] = 'Min. ' . years_label((int)$exp);
    if ($st) $cond['Studii'] = STUDIES_LABELS[$st] ?? ucfirst($st);
    if ($smn || $smx) {
        if ($smn && $smx && $smn != $smx) $cond['Salariu'] = (int)$smn . '–' . (int)$smx . ' RON';
        else $cond['Salariu'] = ($smn ? (int)$smn : (int)$smx) . ' RON';
    }
    return $cond;
}

/**
 * The contract terms of a decoded `schema_json` (v3 and later), label => value, then the v5
 * who-can-apply-and-how facts. [] when the extraction states none.
 *
 * @return array<string,string>
 */
function posting_contract_terms(array $schema): array {
    $ct  = $schema['contract'] ?? null;
    $sen = $schema['seniority_hint'] ?? null;
    $terms = [];
    if ($ct) {
        if (!empty($ct['duration'])) {
            $d = CONTRACT_DURATION_LABELS[$ct['duration']] ?? $ct['duration'];
            if (!empty($ct['duration_months'])) $d .= ' (' . (int)$ct['duration_months'] . ' luni)';
            $terms['Contract'] = $d;
        }
        if (!empty($ct['schedule']))    $terms['Program'] = SCHEDULE_LABELS[$ct['schedule']] ?? $ct['schedule'];
        if (!empty($ct['hours_per_week'])) $terms['Ore/săptămână'] = rtrim(rtrim(number_format((float)$ct['hours_per_week'], 1, ',', '.'), '0'), ',');
        if (!empty($ct['probation_months'])) $terms['Perioadă de probă'] = (int)$ct['probation_months'] . ' luni';
        if (!empty($ct['shift_work']))  $terms['Ture'] = 'Da — ture, gărzi sau weekend';
    }
    if ($sen && isset(SENIORITY_HINT_LABELS[$sen])) $terms['Grad'] = SENIORITY_HINT_LABELS[$sen];
    foreach (v5_facts($schema) as $k => $v) $terms[$k] = $v;
    return $terms;
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
    'es' => 'Spaniolă', 'ru' => 'Rusă', 'hu' => 'Maghiară', 'ro' => 'Română', 'uk' => 'Ucraineană',
    'de-at' => 'Germană', 'pt' => 'Portugheză', 'tr' => 'Turcă', 'ar' => 'Arabă', 'zh' => 'Chineză',
    'straina' => 'O limbă străină (oricare)',
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

/**
 * Query parameters without the noise (261016): empty values, empty arrays and
 * the default status. `?q=&expires_after=&status=active&sort=` means the same
 * as `/`; links and the address bar should say so. The client strips the same
 * from HTMX requests (htmx:configRequest in list.php).
 */
function clean_query(array $params): array {
    $out = [];
    foreach ($params as $k => $v) {
        if (is_array($v)) {
            $v = array_values(array_filter($v, fn($x) => is_array($x) || trim((string)$x) !== ''));
            if (!$v) continue;
        } elseif (trim((string)$v) === '') {
            continue;
        }
        if ($k === 'status' && $v === DEFAULT_STATUS) continue;
        $out[$k] = $v;
    }
    return $out;
}

function qs_page(int $page): string {
    $params = clean_query($_GET);
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

function current_qs(): string {
    $q = http_build_query(clean_query($_GET));
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
    // Old calendar links carry `?title=`; the calendar now takes its name from the filters.
    unset($params['title']);
    $qs = http_build_query($params);
    return '/' . $filename . ($qs ? '?' . $qs : '');
}

/**
 * The search key of a list URL (ADR 0001, docs/decisions/0001-saved-search-identity.md).
 * Two URLs with the same key select the same postings. static/searches.js
 * `searchKey()` implements the same rule; tests/fixtures/search_keys.json is
 * read by both test suites.
 *
 * @param array $params Query parameters, as in $_GET.
 */
function search_key(array $params): string {
    $enc = static fn(string $s): string =>
        strtr(rawurlencode($s), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
    $multi = array_flip(MULTI_PARAMS);
    $parts = [];
    foreach ($params as $name => $value) {
        $name = (string)$name;
        if ($name === 'page' || $name === 'sort' || $name === 'title') continue;
        if (str_ends_with($name, '[]')) $name = substr($name, 0, -2);
        $values = is_array($value) ? array_values($value) : [$value];
        $values = array_values(array_filter($values, 'is_scalar'));
        $values = array_map('strval', $values);
        if (!array_filter($values, fn($v) => trim($v) !== '')) continue;
        if ($name === 'status' && count($values) === 1 && $values[0] === DEFAULT_STATUS) continue;
        if (is_array($value) || isset($multi[$name])) {
            $values = array_values(array_unique(array_filter($values, fn($v) => trim($v) !== '')));
            sort($values, SORT_STRING);
            $parts[$name] = array_map(fn($v) => $enc($name) . '%5B%5D=' . $enc($v), $values);
        } else {
            $parts[$name] = [$enc($name) . '=' . $enc(end($values))];
        }
    }
    ksort($parts, SORT_STRING);
    return implode('&', array_merge(...array_values($parts) ?: [[]]));
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
    'loc'            => 'Localitate',
    'recruitment'    => 'Tip concurs',
    'parttime'       => '',
    'temp_vacant'    => '',
    'remote_apply'   => '',
    'published'      => 'Publicat',
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
    'regime'         => 'Tip funcție',
    'inst'           => 'Instituție',
    'cor'            => 'Grupă ocupațională',
    'medspec'        => 'Specialitate medicală',
    'family_not'     => 'Fără',
    'occupation_not' => 'Fără',
    'inst_not'       => 'Fără',
    'sector_not'     => 'Fără',
    'regime_not'     => 'Fără',
    'funding_not'    => 'Fără',
    'duration_not'   => 'Fără',
    'isced_not'      => 'Fără',
    'skill_not'      => 'Fără',
    'lang_not'       => 'Fără',
    'credential_not' => 'Fără',
    'domain_not'     => 'Fără',
    'cor_not'        => 'Fără',
    'studies_level_not' => 'Fără studii',
    'exp_level_not'  => 'Fără experiență',
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
 * 261016: groups whose values can also be *excluded* ("fără permis de conducere"):
 * `?credential_not[]=permis_conducere`. Param => the exclusion key its facet
 * counts with (build_filters' $excl), so a group's own counts ignore its own
 * exclusions the way they ignore its own selections.
 */
const NEGATABLE_PARAMS = [
    'family' => 'families', 'occupation' => 'occupation', 'inst' => 'inst', 'sector' => 'sector',
    'regime' => 'regime', 'funding' => 'funding', 'duration' => 'duration', 'isced' => 'isced',
    'skill' => 'skill', 'lang' => 'lang', 'credential' => 'credential', 'domain' => 'domain',
    'cor' => 'cor', 'studies_level' => 'studies_levels', 'exp_level' => 'exp_levels',
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
    'occupation', 'funding', 'sector', 'duration', 'schedule', 'loc', 'recruitment',
    'regime', 'inst', 'cor', 'medspec', 'salary_bucket',
    // NEGATABLE_PARAMS' exclusion lists
    'family_not', 'occupation_not', 'inst_not', 'sector_not', 'regime_not', 'funding_not',
    'duration_not', 'isced_not', 'skill_not', 'lang_not', 'credential_not', 'domain_not', 'cor_not',
    'studies_level_not', 'exp_level_not',
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
    if (str_ends_with($key, '_not') && isset(NEGATABLE_PARAMS[substr($key, 0, -4)])) {
        return filter_value_label(substr($key, 0, -4), $value);
    }
    return match ($key) {
        'judet'                            => judet_names()[$value] ?? $value,
        'studies_level'                    => STUDIES_LABELS[$value] ?? $value,
        'anomaly'                          => ANOMALY_LABELS[$value] ?? $value,
        'work_type'                        => WORK_TYPE_LABELS[$value] ?? $value,
        'exp_level'                        => bucket_label(EXP_BUCKETS, $value),
        'salary_bucket'                    => salary_bucket_label($value),
        'status'                           => STATUS_LABELS[$value] ?? ($value === 'all' ? 'Toate' : $value),
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
        'recruitment'                      => RECRUITMENT_KIND_SHORT[$value] ?? $value,
        'parttime'                         => 'Normă parțială',
        'temp_vacant'                      => 'Post temporar vacant',
        'remote_apply'                     => 'Depunere fără deplasare',
        'sector'                           => EMPLOYER_SECTOR_LABELS[$value] ?? $value,
        'regime'                           => JOB_REGIME_LABELS[$value] ?? $value,
        'inst'                             => EMPLOYER_KIND_LABELS[$value] ?? $value,
        'cor'                              => ISCO_GROUP_LABELS[$value] ?? $value,
        'has_salary'                       => 'Cu salariu estimat',
        'expires_after', 'expires_before', 'published' => fmt_date($value),
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

/**
 * The occupation chips above the results of the list (partials/occupation_chips.php): the most frequent occupations of the
 * filter "Ocupație", as links. Returns the chips (`val`, `cnt`, largest first, at most $max), or [] when the row is not shown:
 *  - the results fill one page or less ($total <= $page_size);
 *  - an occupation filter is on ($selected, or $excluded: the facet counts leave `occupation_not` out, so a chip count could
 *    differ from the result count after the click);
 *  - fewer than 2 occupations have 2 or more postings each;
 *  - the largest occupation has more than 90 % of the results.
 * $options are the rows of the filter list (`val`, `cnt`), which leaves out the occupation filter: a chip count is the result
 * count after the click. No query.
 *
 * @param list<array{val:string,cnt:int|string}> $options
 * @return list<array{val:string,cnt:int}>
 */
function occupation_chips(array $options, int $total, int $page_size, array $selected, array $excluded = [], int $max = 8): array {
    if ($total <= $page_size) return [];
    foreach ([$selected, $excluded] as $vals) {
        foreach ((array)$vals as $v) if (trim((string)$v) !== '') return [];
    }
    $rows = [];
    foreach ($options as $o) {
        if ((int)$o['cnt'] >= 2 && (string)$o['val'] !== '') $rows[] = ['val' => (string)$o['val'], 'cnt' => (int)$o['cnt']];
    }
    if (count($rows) < 2) return [];
    usort($rows, fn($a, $b) => $b['cnt'] <=> $a['cnt']);
    if ($rows[0]['cnt'] * 10 > $total * 9) return [];
    return array_slice($rows, 0, $max);
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
        // Same words in a different case ("infirmier", "ASISTENT SOCIAL"): show
        // the canonical casing, and no second line.
        return ['primary' => $canonical ?: $raw, 'secondary' => null, 'normalized' => false];
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
    // Notes (2026-10-17): opens an editor under the posting (static/saved.js). Last, so
    // save and hide keep their tab order.
    $h .= '<button type="button" data-pref="note" aria-expanded="false" aria-label="Adaugă o notiță: ' . $title
        . '" class="pref-btn pref-note">' . $icon('i-note') . '</button>';
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

/** A decorative icon from the sprite in inc/header.php: `<svg><use>`, hidden from assistive technology. */
function icon(string $id, string $class = ''): string {
    return '<svg' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ' aria-hidden="true" focusable="false"><use href="#' . e($id) . '"/></svg>';
}

/**
 * A section heading of a page with a table of contents (partials/toc.php): the `<h2>` takes its id and its text from the
 * array `$toc` of the page (id => label), so the heading and the list cannot differ. `tabindex="-1"` lets a click on the
 * list move the keyboard position here; `scroll-mt-4` leaves room above it.
 */
function toc_h2(array $toc, string $id, string $class = ''): string {
    return '<h2 id="' . e($id) . '" tabindex="-1" class="scroll-mt-4 focus:outline-none' . ($class !== '' ? ' ' . e($class) : '') . '">'
         . e($toc[$id]) . '</h2>';
}

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
    $sal_bucket = (array)($p['salary_bucket'] ?? []);
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
    // UX-02 (D16): localities, offered once a county is chosen (list.php).
    $locs = array_values(array_filter(array_map('strval', (array)($p['loc'] ?? [])), fn($v) => $v !== ''));
    if ($locs && $excl !== 'locs') {
        $ph = implode(',', array_fill(0, count($locs), '?'));
        $where[] = "j.locality IN ($ph)";
        array_push($binds, ...$locs);
    }
    // D15: one publication day (the home page's "Noi azi" link).
    $published = (string)($p['published'] ?? '');
    if ($published !== '' && $excl !== 'published' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $published)) {
        $where[] = "substr(j.published_at, 1, 10) = ?";
        $binds[] = $published;
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
        'medspec'    => 'med_specialty',
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
            $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$value);
            if ($param === 'lang' && !str_contains((string)$value, ':')) {
                // 261016 language tree: a bare language ("en") is every level of it.
                $probes[] = "($target LIKE ? ESCAPE '\\' OR $target LIKE ? ESCAPE '\\')";
                $pb[]     = '%"' . $esc . '"%';
                $pb[]     = '%"' . $esc . ':%';
                continue;
            }
            $probes[] = "$target LIKE ? ESCAPE '\\'";
            $pb[]     = '%"' . $esc . '"%';
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
    //
    // Several values combine with OR (a checkbox group): each value is its own AND of fragments in parentheses.
    if ($sal_bucket && $excl !== 'salary_bucket') {
        $sal_or = [];
        $sal_binds = [];
        foreach ($sal_bucket as $sv) {
            $sb = is_scalar($sv) ? salary_bucket_sql((string)$sv) : null;
            if (!$sb) continue;
            $sal_or[] = '(' . implode(' AND ', $sb[0]) . ')';
            array_push($sal_binds, ...$sb[1]);
        }
        if ($sal_or) {
            $where[] = '(' . implode(' OR ', $sal_or) . ')';
            array_push($binds, ...$sal_binds);
        }
    }

    // Shift work is a yes/no a reader either wants or rules out, so it is a
    // switch rather than a facet group. NULL means "no contract block", which
    // is not the same as "no shift work" and must not match either way.
    if (($p['shift'] ?? '') === '1' && $excl !== 'shift') {
        $where[] = "j.v3_shift_work = 1";
    }

    // BETA-V5 facts (export-to-sqlite.py::_v5_columns). Empty on v4 rows, so each of
    // these narrows to v5 postings: a v4 row has not been asked, which is not "no".
    $recr = array_values(array_filter(array_map('strval', (array)($p['recruitment'] ?? [])), fn($v) => $v !== ''));
    if ($recr && $excl !== 'recruitments') {
        $where[] = "j.v5_recruitment_kind IN (" . implode(',', array_fill(0, count($recr), '?')) . ")";
        array_push($binds, ...$recr);
    }
    if (($p['parttime'] ?? '') === '1' && $excl !== 'parttime') {
        $where[] = "j.v5_fte IS NOT NULL AND j.v5_fte < 1";
    }
    if (($p['temp_vacant'] ?? '') === '1' && $excl !== 'temp_vacant') {
        $where[] = "j.v5_post_status = 'temporar_vacant'";
    }
    if (($p['remote_apply'] ?? '') === '1' && $excl !== 'remote_apply') {
        $where[] = "(j.v5_channels LIKE '%\"email\"%' OR j.v5_channels LIKE '%\"posta\"%' OR j.v5_channels LIKE '%\"online\"%')";
    }

    // Quick choice: only postings we can put a number on.
    if (($p['has_salary'] ?? '') === '1' && $excl !== 'has_salary') {
        $where[] = SALARY_EXPR . " IS NOT NULL";
    }

    // Plain-column facets added with the occupation dictionary and prompt v4.
    foreach ([
        'occupation' => 'occ_canonical',
        'funding'    => 'funding_kind',        // 261016: text signals + v4 (export-to-sqlite.py)
        'sector'     => 'v4_employer_sector',
        'regime'     => 'job_regime',
        'inst'       => 'employer_kind',
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
    // 261016: occupation group (COR = ISCO-08), by code prefix: ?cor[]=22 is every
    // health professional, ?cor[]=221 the doctors.
    $cor = array_values(array_filter(array_map('strval', (array)($p['cor'] ?? [])), fn($v) => preg_match('/^\d{1,4}$/', $v)));
    if ($cor && $excl !== 'cor') {
        $where[] = '(' . implode(' OR ', array_fill(0, count($cor), 'j.occ_group LIKE ?')) . ')';
        foreach ($cor as $c) $binds[] = $c . '%';
    }

    // 261016: exclusions. The positive clause for the excluded values, built by
    // this same function (so a role group excludes postings with ANY role
    // holding the value), negated as a posting-id set. Any-of: excluding two
    // values drops postings that have either.
    foreach (NEGATABLE_PARAMS as $param => $excl_key) {
        $vals = array_values(array_filter(array_map('strval', (array)($p[$param . '_not'] ?? [])), fn($v) => trim($v) !== ''));
        if (!$vals || $excl === $excl_key) continue;
        $sub = build_filters(['status' => 'all', $param => $vals, $param . '_mode' => 'any']);
        if (!$sub['where']) continue;
        $where[] = "j.id NOT IN (SELECT j.id FROM job_postings j WHERE " . implode(' AND ', $sub['where']) . ")";
        array_push($binds, ...$sub['binds']);
    }

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

// BETA-V5: reading v5 extractions (facts, calendar, dosar, text fallbacks).
require_once __DIR__ . '/inc/v5.php';

/**
 * 261116: "Salvează căutarea" and "Urmărește" in one group. It sits at the end
 * of the active-filter chips (partials/result_list.php), so it is part of the
 * search it acts on, and again as #follow-fab at the bottom of the screen
 * (pages/list.php). The list page's script shows both only for a search; the
 * fab only while the chips' group is off screen. The save toggle is
 * static/searches.js's [data-search-toggle]; "Urmărește" opens the
 * subscription block (#urmareste) in a dialog.
 */
function follow_group_html(string $extra_attrs): string {
    $ico = static fn(string $id): string => '<svg class="sub-ico" aria-hidden="true"><use href="#' . $id . '"/></svg>';
    return '<div ' . $extra_attrs . ' role="group" aria-label="Căutarea aceasta">'
        . '<button type="button" data-search-toggle hidden aria-pressed="false" class="follow-btn">'
        . '<svg class="pref-ico" aria-hidden="true"><use href="#i-bookmark"/></svg>'
        . '<span data-search-toggle-label>Salvează căutarea</span></button>'
        . '<button type="button" data-follow-open aria-haspopup="dialog" class="follow-btn follow-btn-primary">'
        . '<span>Urmărește</span><span class="follow-icons" title="RSS, email, calendar">'
        . $ico('i-feed') . $ico('i-mail') . $ico('i-calendar') . '</span>'
        . '<span class="sr-only"> (RSS, email, calendar)</span></button>'
        . '</div>';
}

// ---- Landing page (UX-05, ADR 0004) ----

/** Romanian count of postings as "posturi": 0 posturi · 1 post · 2–19 posturi · 20+ de posturi. */
function posturi_label(int $n): string {
    if ($n === 1) return '1 post';
    $mod = $n % 100;
    $num = number_format($n, 0, ',', '.');
    return ($n > 0 && ($mod === 0 || $mod >= 20)) ? $num . ' de posturi' : $num . ' posturi';
}

/** The sentence of the link "all postings" on the landing page, correct for each N. */
function home_all_link_text(int $n): string {
    if ($n === 0) return 'Vezi lista posturilor';
    if ($n === 1) return 'Vezi singurul post activ';
    $mod = $n % 100;
    $num = number_format($n, 0, ',', '.');
    return 'Vezi toate cele ' . $num . (($mod === 0 || $mod >= 20) ? ' de' : '') . ' posturi active';
}

/**
 * How many postings the list shows for $params under the default status. Each
 * count on the landing page is this number for the link beside it, so the
 * count and the list cannot disagree (the same rule as `$count_for` in
 * pages/list.php before the landing page moved out of it).
 */
function home_count_for(array $params): int {
    $f = build_filters($params + ['status' => DEFAULT_STATUS]);
    $w = $f['where'] ? 'WHERE ' . implode(' AND ', $f['where']) : '';
    $st = db()->prepare("SELECT COUNT(*) FROM job_postings j $w");
    $st->execute($f['binds']);
    return (int)$st->fetchColumn();
}

/** The canonical list URL (ADR 0001) of $params. Bare `/` is the landing page, so an empty key is `/posturi/`. */
function home_list_url(array $params): string {
    $key = search_key($params);
    return $key === '' ? '/posturi/' : '/?' . $key;
}

/**
 * The number of postings of $params under the default status, and the same number for each
 * county (judet_slug). One query, built by build_filters() like the list, so the total and each
 * county count are the result count of the link of $params (see home_count_for()). Used by the
 * items of the landing page and of the themed pages, which show the count of the selected county.
 *
 * @return array{n:int, counties:array<string,int>}
 */
function home_item_counts(array $params): array {
    $f = build_filters($params + ['status' => DEFAULT_STATUS]);
    $w = $f['where'] ? 'WHERE ' . implode(' AND ', $f['where']) : '';
    $st = db()->prepare("SELECT j.judet_slug AS j, COUNT(*) AS c FROM job_postings j $w GROUP BY 1");
    $st->execute($f['binds']);
    $n = 0;
    $counties = [];
    foreach ($st->fetchAll() as $r) {
        $n += (int)$r['c'];
        if ((string)$r['j'] !== '') $counties[(string)$r['j']] = (int)$r['c'];
    }
    return ['n' => $n, 'counties' => $counties];
}

/**
 * The salary chart (partials/salary_chart.php): the active postings of the filter $base by range of net pay, one row for each
 * SALARY_NET_BUCKETS range in the order of the filter, then the row "fără salariu estimat" (SALARY_NONE). Each count is the result
 * count of its link (home_item_counts(), home_list_url(): the rule of the landing page, ADR 0004), so the chart and the filter
 * cannot disagree. `counties` is the count for each county (judet_slug), for the county selection of a themed page; `params` are
 * the filter parameters of the link. A key of $base wins over `salary_bucket`.
 *
 * @return array{rows: list<array{key:string,label:string,n:int,counties:array<string,int>,params:array,href:string,none:bool}>, total:int}
 *         `total` is the sum of the five ranges: 0 means that no posting of the scope has a salary figure.
 */
function salary_distribution(array $base = []): array {
    $rows = [];
    $total = 0;
    foreach ([...SALARY_NET_BUCKETS, ['key' => SALARY_NONE, 'label' => SALARY_NONE_LABEL]] as $b) {
        $params = $base + ['salary_bucket' => $b['key']];
        $c = home_item_counts($params);
        $none = $b['key'] === SALARY_NONE;
        if (!$none) $total += $c['n'];
        $rows[] = ['key' => $b['key'], 'label' => $b['label'], 'n' => $c['n'], 'counties' => $c['counties'],
                   'params' => $params, 'href' => home_list_url($params), 'none' => $none];
    }
    return ['rows' => $rows, 'total' => $total];
}

/**
 * One item with a count (a link, and its count in brackets outside the link). `params` are the
 * filter parameters of the link; the JSON of the page carries them with the county counts, so that
 * static/home.js can rebuild the link and the count for a selected county.
 */
function home_item(string $id, string $label, array $params): array {
    $c = home_item_counts($params);
    return ['id' => $id, 'label' => $label, 'params' => $params, 'n' => $c['n'], 'counties' => $c['counties'],
            'href' => home_list_url($params)];
}

/** $extra added to $base: the values of a parameter that both have are joined, the others are kept. */
function home_merge_params(array $base, array $extra): array {
    foreach ($extra as $k => $v) {
        $base[$k] = isset($base[$k]) && is_array($base[$k]) && is_array($v)
            ? array_values(array_unique(array_merge($base[$k], $v))) : $v;
    }
    return $base;
}

/**
 * The parameters of an item of a `values` section of a themed page: the base filter and `$param` = `$value`. When `$param` is a key
 * of the base filter, the value replaces the base values (base `cor` [5, 7, 8, 9] and the value 7 give `cor` [7]); else the value
 * is added, as home_merge_params() does.
 */
function theme_item_params(array $base, string $param, string $value): array {
    return array_key_exists($param, $base) ? array_replace($base, [$param => [$value]]) : home_merge_params($base, [$param => [$value]]);
}

/** The filter parameters of an item, as form fields (`family[]` => [values]): the shape of the JSON of the page. */
function home_item_fields(array $params): array {
    $out = [];
    foreach ($params as $k => $v) $out[param_field((string)$k)] = array_values(array_map('strval', (array)$v));
    return $out;
}

/** The feed URL (`posturi.rss`, `posturi.ics`) of $params, written as feed_url() writes the URL of the current list. */
function feed_url_for(string $filename, array $params): string {
    $saved = $_GET;
    $_GET = $params;
    try {
        return feed_url($filename);
    } finally {
        $_GET = $saved;
    }
}

/**
 * The two short lists of the landing page and of the themed pages, inside the filter $base:
 * "new" = the latest publication day in the data, "soon" = confirmed deadline in the next 3 days.
 *
 * @return array{day:string, new:array, soon:array}
 */
function home_new_soon(array $base, string $today): array {
    $tz = new DateTimeZone('Europe/Bucharest');
    $f = build_filters($base + ['status' => 'all']);
    $bw = $f['where'] ? ' AND ' . implode(' AND ', $f['where']) : '';
    $live = 'j.application_status IN (' . OPEN_STATUS_SQL . ')';
    $st = db()->prepare("SELECT MAX(substr(j.published_at, 1, 10)) FROM job_postings j WHERE $live$bw");
    $st->execute($f['binds']);
    $day = (string)$st->fetchColumn();
    $new = [];
    if ($day !== '') {
        $st = db()->prepare("SELECT j.*, e.slug AS employer_slug_display FROM job_postings j
            LEFT JOIN employers e ON e.id = j.employer_id
            WHERE $live AND substr(j.published_at, 1, 10) = ?$bw
            ORDER BY j.published_at DESC, j.id DESC LIMIT 6");
        $st->execute([$day, ...$f['binds']]);
        $new = $st->fetchAll();
    }
    $in3 = (new DateTimeImmutable($today, $tz))->modify('+3 days')->format('Y-m-d');
    $st = db()->prepare("SELECT j.*, e.slug AS employer_slug_display FROM job_postings j
        LEFT JOIN employers e ON e.id = j.employer_id
        WHERE j.application_status = 'confirmed_open' AND " . DEADLINE_COL . " >= ? AND " . DEADLINE_COL . " <= ?$bw
        ORDER BY " . DEADLINE_COL . " ASC, j.published_at DESC LIMIT 6");
    $st->execute([$today, $in3, ...$f['binds']]);
    return ['day' => $day, 'new' => $new, 'soon' => $st->fetchAll()];
}

/** Sort key for Romanian names: a < ă < â < b … i < î … s < ș < t < ț < u. */
function ro_sort_key(string $s): string {
    static $order = null;
    if ($order === null) $order = array_flip(mb_str_split(" -aăâbcdefghiîjklmnopqrsștțuvwxyz"));
    $s = strtr(mb_strtolower($s, 'UTF-8'), ['ş' => 'ș', 'ţ' => 'ț']);
    $out = '';
    foreach (mb_str_split($s) as $ch) $out .= chr(48 + ($order[$ch] ?? 60));
    return $out;
}

/** Counties by Romanian name: slug => name. */
function judet_names_sorted(): array {
    $names = judet_names();
    uasort($names, fn($a, $b) => strcmp(ro_sort_key($a), ro_sort_key($b)));
    return $names;
}

/**
 * The county tile map: code => [slug, column, row] (9 columns, 7 rows), after
 * the wireframe (docs/specs/2026-11-16-ui-overhaul/landing-wireframe.dc.html).
 */
const HOME_COUNTY_TILES = [
    'SM' => ['satu-mare', 3, 1], 'MM' => ['maramures', 4, 1], 'SV' => ['suceava', 6, 1], 'BT' => ['botosani', 7, 1],
    'BH' => ['bihor', 2, 2], 'SJ' => ['salaj', 3, 2], 'BN' => ['bistrita-nasaud', 4, 2], 'NT' => ['neamt', 6, 2], 'IS' => ['iasi', 7, 2],
    'AR' => ['arad', 2, 3], 'CJ' => ['cluj', 3, 3], 'MS' => ['mures', 4, 3], 'HR' => ['harghita', 5, 3], 'BC' => ['bacau', 6, 3], 'VS' => ['vaslui', 7, 3],
    'TM' => ['timis', 1, 4], 'HD' => ['hunedoara', 2, 4], 'AB' => ['alba', 3, 4], 'SB' => ['sibiu', 4, 4], 'BV' => ['brasov', 5, 4],
    'CV' => ['covasna', 6, 4], 'VN' => ['vrancea', 7, 4], 'GL' => ['galati', 8, 4],
    'CS' => ['caras-severin', 1, 5], 'GJ' => ['gorj', 2, 5], 'VL' => ['valcea', 3, 5], 'AG' => ['arges', 4, 5], 'DB' => ['dambovita', 5, 5],
    'PH' => ['prahova', 6, 5], 'BZ' => ['buzau', 7, 5], 'BR' => ['braila', 8, 5], 'TL' => ['tulcea', 9, 5],
    'MH' => ['mehedinti', 1, 6], 'DJ' => ['dolj', 2, 6], 'OT' => ['olt', 3, 6], 'TR' => ['teleorman', 4, 6], 'IF' => ['ilfov', 5, 6],
    'B' => ['bucuresti', 6, 6], 'IL' => ['ialomita', 7, 6], 'CT' => ['constanta', 8, 6],
    'GR' => ['giurgiu', 5, 7], 'CL' => ['calarasi', 7, 7],
];

/**
 * Professional domains of the landing page, 
 * Professional domains of the landing page. A domain is a combination of two filters that
 * exist: `family[]` and, for the split of "tehnic", `cor[]` (a code prefix; a one-digit prefix
 * is the first digit of `occ_group`). The count of each item is the count of its link
 * (checked against home_count_for() in tests/home_test.php).
 */
const HOME_DOMAINS = [
    ['key' => 'sanatate',     'label' => 'Sănătate',                         'icon' => 'i-sector-sanatate',          'family' => 'sănătate'],
    ['key' => 'administratie','label' => 'Administrație',                    'icon' => 'i-domain-administratie',     'family' => 'administrație'],
    ['key' => 'meseriasi',    'label' => 'Meseriași și muncitori calificați','icon' => 'i-domain-meseriasi',         'family' => 'tehnic', 'cor' => ['7']],
    ['key' => 'paza',         'label' => 'Pază, îngrijire și servicii',      'icon' => 'i-domain-paza',              'family' => 'tehnic', 'cor' => ['5']],
    ['key' => 'soferi',       'label' => 'Șoferi și operatori',              'icon' => 'i-domain-soferi',            'family' => 'tehnic', 'cor' => ['8']],
    // Two domains on one row (key `row`): one icon, two links. The row sorts by the sum of its counts.
    ['key' => 'ingineri',     'label' => 'Ingineri',                         'icon' => 'i-domain-ingineri',          'family' => 'tehnic', 'cor' => ['2'], 'row' => 'ingineri'],
    ['key' => 'tehnicieni',   'label' => 'Tehnicieni',                       'icon' => 'i-domain-ingineri',          'family' => 'tehnic', 'cor' => ['3'], 'row' => 'ingineri'],
    ['key' => 'necalificati', 'label' => 'Muncitori necalificați',           'icon' => 'i-domain-necalificati',      'family' => 'tehnic', 'cor' => ['9']],
    ['key' => 'social',       'label' => 'Social',                           'icon' => 'i-sector-asistenta_sociala', 'family' => 'social'],
    ['key' => 'financiar',    'label' => 'Financiar',                        'icon' => 'i-domain-financiar',         'family' => 'financiar'],
    ['key' => 'educatie',     'label' => 'Educație',                         'icon' => 'i-sector-educatie',          'family' => 'educație'],
    ['key' => 'cultura',      'label' => 'Cultură',                          'icon' => 'i-sector-cultura',           'family' => 'cultură'],
    ['key' => 'it',           'label' => 'IT',                               'icon' => 'i-domain-it',                'family' => 'IT'],
    ['key' => 'juridic',      'label' => 'Juridic',                          'icon' => 'i-sector-justitie',          'family' => 'juridic'],
    ['key' => 'ordine',       'label' => 'Ordine publică',                   'icon' => 'i-sector-ordine_publica',    'family' => 'ordine publică'],
    ['key' => 'altele',       'label' => 'Altele',                           'icon' => 'i-sector-altele',            'family' => 'altele'],
];

/**
 * The breaks of the county map (owner, 2026-11-18): a log scale between the smallest and the largest non-zero count of the
 * map, so that the colours spread on every page (the whole country, a themed page), not only on the whole country. Fixed
 * steps (0–19, 20–39, 40–79, 80+) put almost every county of a themed page in the first step; a linear scale does the same,
 * because București is far above every other county. Returns the lower bound of each step above the first, in order; a
 * break that would hold no whole number is left out, so small counts give fewer steps. No break when every non-zero count
 * is the same.
 */
function home_map_breaks(array $counts): array {
    $nz = array_values(array_filter(array_map('intval', $counts), fn($n) => $n > 0));
    if (!$nz) return [];
    [$lo, $hi] = [min($nz), max($nz)];
    if ($hi <= $lo) return [];
    $out = [];
    $prev = $lo;
    for ($k = 1; $k <= 3; $k++) {
        $b = (int)ceil(exp(log($lo) + $k / 4 * (log($hi) - log($lo))) - 1e-9);
        if ($b > $prev && $b <= $hi) { $out[] = $b; $prev = $b; }
    }
    return $out;
}

/**
 * The map step of a county: "none" for 0 (a blank tile), else 0–3 by the breaks of home_map_breaks(). With fewer than 3
 * breaks the steps spread over 0–3, so the largest county is always the darkest (step 3); with no break every county with
 * a posting has the middle step 2.
 */
function home_map_step(int $n, array $breaks): string {
    if ($n <= 0) return 'none';
    $m = count($breaks);
    if ($m === 0) return '2';
    $idx = count(array_filter($breaks, fn($b) => $n >= $b));
    return (string)(int)round($idx * 3 / $m);
}

/** The legend of the county map: [['step' => string, 'label' => "1–4"], …], "0" first when a county has no posting. */
function home_map_legend(array $counts): array {
    $counts = array_map('intval', $counts);
    $nz = array_filter($counts, fn($n) => $n > 0);
    $out = [];
    if (count($nz) < count($counts)) $out[] = ['step' => 'none', 'label' => '0'];
    if (!$nz) return $out;
    [$lo, $hi] = [min($nz), max($nz)];
    $breaks = home_map_breaks($counts);
    $m = count($breaks);
    for ($i = 0; $i <= $m; $i++) {
        $from = $i === 0 ? $lo : $breaks[$i - 1];
        $to = $i === $m ? $hi : $breaks[$i] - 1;
        $out[] = ['step' => home_map_step($from, $breaks), 'label' => $from === $to ? (string)$from : $from . '–' . $to];
    }
    return $out;
}

/** The Mondays of the last $weeks complete ISO weeks (this week is not complete), oldest first. */
function home_week_starts(int $weeks = 12): array {
    $tz = new DateTimeZone('Europe/Bucharest');
    $this_monday = (new DateTimeImmutable(ro_today(), $tz))->modify('monday this week');
    $out = [];
    for ($i = $weeks; $i >= 1; $i--) $out[] = $this_monday->modify("-$i weeks")->format('Y-m-d');
    return $out;
}

/**
 * The posts of an announcement, as one SQL expression (the weekly chart counts posts, not announcements): `nr_posturi` when it
 * is from 1 to 30, else 1 (missing, below 1 or above 30: the source holds wrong values, such as a year). Use it in a SELECT of
 * job_postings or archived_postings with no table alias, or with the alias `a` in the archive (`posts_sql('a.nr_posturi')`).
 * An estimate: DATA-NR-POSTURI in docs/backlog.md replaces the rule with a validated count.
 */
function posts_sql(string $col = 'nr_posturi'): string {
    return "CASE WHEN $col BETWEEN 1 AND 30 THEN $col ELSE 1 END";
}

/**
 * Posts (see posts_sql()) published in each of the last 12 complete ISO weeks, oldest first:
 * [['start' => 'Y-m-d', 'n' => int], …]. Source: `published_at` of
 * job_postings and archived_postings, each posting counted once (an id in
 * both tables counts for job_postings). With $family, only the postings of
 * that profession family (`inf_profession_family`) count, in both tables.
 */
function home_weekly_postings(int $weeks = 12, ?string $family = null): array {
    $starts = home_week_starts($weeks);
    $from = $starts[0];
    $to = (new DateTimeImmutable(end($starts), new DateTimeZone('Europe/Bucharest')))->modify('+7 days')->format('Y-m-d');
    $fj = $family === null ? '' : ' WHERE inf_profession_family = ?';
    $fa = $family === null ? '' : ' AND a.inf_profession_family = ?';
    $pj = posts_sql(); $pa = posts_sql('a.nr_posturi');
    $st = db()->prepare(
        "SELECT d, SUM(p) AS c FROM (
            SELECT substr(published_at, 1, 10) AS d, $pj AS p FROM job_postings$fj
            UNION ALL
            SELECT substr(a.published_at, 1, 10), $pa FROM archived_postings a
            WHERE a.id NOT IN (SELECT id FROM job_postings)$fa
         ) WHERE d >= ? AND d < ? GROUP BY d");
    $st->execute($family === null ? [$from, $to] : [$family, $family, $from, $to]);
    $by_week = array_fill_keys($starts, 0);
    foreach ($st->fetchAll() as $r) {
        $ts = (new DateTimeImmutable((string)$r['d'], new DateTimeZone('Europe/Bucharest')))->modify('monday this week')->format('Y-m-d');
        if (isset($by_week[$ts])) $by_week[$ts] += (int)$r['c'];
    }
    $out = [];
    foreach ($by_week as $start => $n) $out[] = ['start' => $start, 'n' => $n];
    return $out;
}

/**
 * The group of an occupation, for the buttons of the chart: the index of the first group of $groups that holds it, or null.
 * A group has `label` and any of `values` (exact occupations), `prefixes` (the occupation starts with one), `except` (exact
 * occupations that a prefix does not take) and `prefixes_cor` (the COR code of the posting starts with one; needs $occ_group).
 * So a posting is in one group at most.
 */
function theme_group_of(array $groups, string $occupation, string $occ_group = ''): ?int {
    foreach ($groups as $i => $g) {
        if (in_array($occupation, $g['values'] ?? [], true)) return $i;
        if (in_array($occupation, $g['except'] ?? [], true)) continue;
        foreach ($g['prefixes'] ?? [] as $prefix) if (str_starts_with($occupation, (string)$prefix)) return $i;
        // `prefixes_cor`: the COR code (`occ_group`) of the posting starts with one of them (a posting with no code is in no such group).
        if ($occ_group !== '') foreach ($g['prefixes_cor'] ?? [] as $prefix) if (str_starts_with($occ_group, (string)$prefix)) return $i;
    }
    return null;
}

/** True when the table `archived_postings` has the column (an export made before the column existed has not). */
function archive_has_column(string $col): bool {
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        foreach (db()->query('PRAGMA table_info(archived_postings)')->fetchAll() as $r) $cols[(string)$r['name']] = true;
    }
    return isset($cols[$col]);
}

/**
 * The weekly series of the chart of a themed page, for the whole country and for each county: the postings of the profession
 * family (or a list of families) in the last $weeks complete weeks, as posts (posts_sql()) (same weeks, same source and same once-only rule as home_weekly_postings(): job_postings
 * and archived_postings, an id in both tables counts for job_postings), split into the occupation groups of the theme. A posting
 * is in one group at most (theme_group_of()), so no series is above the total.
 *
 * @param list<array> $groups see theme_group_of()
 * @param list<string> $cor COR prefixes (theme key `history.cor`), or none
 * @return array{total:list<int>, groups:list<list<int>>, none:int, counties:array<string,array{t:list<int>, s:list<list<int>>}>}
 *         `none` is the number of postings of the weeks that are in no group; `counties` has the weekly total and the group
 *         series of each county (a posting with no county is in no county)
 */
function theme_group_series(string|array $family, array $groups, int $weeks = 12, array $cor = []): array {
    $starts = home_week_starts($weeks);
    $idx = array_flip($starts);
    $from = $starts[0];
    $to = (new DateTimeImmutable(end($starts), new DateTimeZone('Europe/Bucharest')))->modify('+7 days')->format('Y-m-d');
    $pj = posts_sql(); $pa = posts_sql('a.nr_posturi');
    // One family or a list of families (`IN`): a posting is in one family, so no posting is counted twice.
    $fams = array_values(array_unique(array_map('strval', (array)$family)));
    $in = implode(', ', array_fill(0, count($fams), '?'));
    // `$cor` (theme key `history.cor`): only the postings whose COR code (`occ_group`) starts with one of the prefixes, in both tables.
    // An archive of an export made before `occ_group` existed adds nothing to such a chart (it cannot be told which row is in the prefixes).
    $cor = array_values(array_filter(array_map('strval', $cor), fn($v) => preg_match('/^\d{1,4}$/', $v)));
    $has_og = archive_has_column('occ_group');
    $likes = fn(string $c) => ' AND (' . implode(' OR ', array_fill(0, count($cor), "$c LIKE ?")) . ')';
    $cj = $cor ? $likes('occ_group') : '';
    $ca = !$cor ? '' : ($has_og ? $likes('a.occ_group') : ' AND 0');
    $binds = [...$fams];
    foreach ($cor as $c) $binds[] = $c . '%';
    array_push($binds, ...$fams);
    if ($cor && $has_og) foreach ($cor as $c) $binds[] = $c . '%';
    array_push($binds, $from, $to);
    $og_a = $has_og ? 'a.occ_group' : "''";
    $st = db()->prepare(
        "SELECT j, occ, og, d, SUM(p) AS c FROM (
            SELECT judet_slug AS j, occ_canonical AS occ, occ_group AS og, substr(published_at, 1, 10) AS d, $pj AS p FROM job_postings WHERE inf_profession_family IN ($in)$cj
            UNION ALL
            SELECT a.judet_slug, a.occ_canonical, $og_a, substr(a.published_at, 1, 10), $pa FROM archived_postings a
            WHERE a.id NOT IN (SELECT id FROM job_postings) AND a.inf_profession_family IN ($in)$ca
         ) WHERE d >= ? AND d < ? GROUP BY j, occ, og, d");
    $st->execute($binds);
    $zero = array_fill(0, $weeks, 0);
    $out = ['total' => $zero, 'groups' => array_fill(0, count($groups), $zero), 'none' => 0, 'counties' => []];
    foreach ($st->fetchAll() as $r) {
        $w = $idx[(new DateTimeImmutable((string)$r['d'], new DateTimeZone('Europe/Bucharest')))->modify('monday this week')->format('Y-m-d')] ?? null;
        if ($w === null) continue;
        $c = (int)$r['c'];
        $g = theme_group_of($groups, (string)$r['occ'], (string)$r['og']);
        $out['total'][$w] += $c;
        if ($g === null) $out['none'] += $c; else $out['groups'][$g][$w] += $c;
        $j = (string)$r['j'];
        if ($j === '') continue;
        $out['counties'][$j] ??= ['t' => $zero, 's' => array_fill(0, count($groups), $zero)];
        $out['counties'][$j]['t'][$w] += $c;
        if ($g !== null) $out['counties'][$j]['s'][$g][$w] += $c;
    }
    return $out;
}

/**
 * The options of a select of the search form of a themed page: every value of a filter that has a posting inside the
 * theme (default status), in Romanian alphabetical order. `source` is `json` (the column holds a JSON list) or `column`.
 *
 * @param string $base_where the WHERE of the postings inside the theme, with the alias `j`
 * @return list<string>
 */
function theme_select_values(array $sel, string $base_where, array $binds): array {
    $col = preg_match('/^[a-z0-9_]+$/', (string)($sel['column'] ?? '')) ? $sel['column'] : '';
    if ($col === '') return [];
    $vals = [];
    if (($sel['source'] ?? '') === 'json') {
        $st = db()->prepare("SELECT j.$col AS vals FROM job_postings j WHERE $base_where AND j.$col NOT IN ('', '[]')");
        $st->execute($binds);
        foreach ($st->fetchAll() as $r) {
            foreach ((array)(json_decode((string)$r['vals'], true) ?: []) as $v) if ($v !== '' && $v !== null) $vals[(string)$v] = true;
        }
    } else {
        $st = db()->prepare("SELECT DISTINCT j.$col AS v FROM job_postings j WHERE $base_where AND j.$col <> ''");
        $st->execute($binds);
        foreach ($st->fetchAll() as $r) $vals[(string)$r['v']] = true;
    }
    $vals = array_keys($vals);
    usort($vals, fn($a, $b) => strcmp(ro_sort_key((string)$a), ro_sort_key((string)$b)) ?: strcmp((string)$a, (string)$b));
    return $vals;
}

/** The symbol id for a value of a `values` section (`icons`: value => id, then `icon_default`), or null. */
function theme_icon_for(array $sec, string $value): ?string {
    return !empty($sec['icons'][$value]) ? (string)$sec['icons'][$value] : ($sec['icon_default'] ?? null);
}

/** An icon from a separate SVG file (ADR 0007): `<use href="/static/<file>?v=<mtime>#<id>">`, hidden from assistive technology. */
function icon_file(string $file, string $id, string $class = ''): string {
    $v = @filemtime(__DIR__ . '/static/' . $file) ?: '1';
    return '<svg' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ' aria-hidden="true" focusable="false"><use href="/static/' . e($file) . '?v=' . $v . '#' . e($id) . '"/></svg>';
}

/** "24 aug" (and the year when it is not this year), for the ends of the chart. */
function home_short_date(string $iso): string {
    $months = ['ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'nov', 'dec'];
    $d = new DateTimeImmutable($iso, new DateTimeZone('Europe/Bucharest'));
    $s = (int)$d->format('j') . ' ' . $months[(int)$d->format('n') - 1];
    if ($d->format('Y') !== substr(ro_today(), 0, 4)) $s .= ' ' . $d->format('Y');
    return $s;
}

// ---- Salary estimator (/estimator-salariu/) ----
//
// Python computes, PHP looks up (ADR 0008): export-to-sqlite.py writes the pay of every grid row at
// gradation 0-5 (salary_grid.Grid.row_pay()). Nothing here repeats the pay formula. The page takes
// the min and max of the rows that the reader selects, adds the local cap and converts to net.

/** The tables that the export writes for the estimator. An older export has none of them. */
const SALARY_EST_TABLES = ['salary_grid_rows', 'salary_grid_pay', 'salary_grid_link', 'salary_cap', 'salary_param'];

/** The choices of step 2: URL parameter => [column of salary_grid_rows, label]. */
const SALARY_EST_DIMS = [
    'sec'    => ['sectiune', 'Categorie'],
    'regim'  => ['regim', 'Regim'],
    'nivel'  => ['nivel_administrativ', 'Nivel administrativ'],
    'post'   => ['tip_post', 'Tip de post'],
    'grad'   => ['grad_treapta', 'Grad sau treaptă'],
    'studii' => ['studii', 'Studii'],
    'unit'   => ['tip_unitate', 'Tip de unitate sanitară'],
];

const SALARY_EST_STUDY_LABELS = [
    'S' => 'superioare', 'SSD' => 'superioare de scurtă durată', 'M' => 'medii', 'G' => 'generale', 'PL' => 'postliceale',
    'PL/M' => 'postliceale sau medii', 'M/G' => 'medii sau generale',
];

const SALARY_EST_UNIT_LABELS = [
    'a.1' => 'a.1, unități clinice', 'a.2' => 'a.2, anatomie patologică și medicină legală',
    'a.3' => 'a.3, ambulanță și urgențe', 'a.4' => 'a.4, alte unități sanitare',
];

/** True when the export has the tables of the estimator (an older export does not). */
function salary_est_available(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $in = "'" . implode("','", SALARY_EST_TABLES) . "'";
        $n = (int)db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type IN ('table','view') AND name IN ($in)")->fetchColumn();
        $ok = $n === count(SALARY_EST_TABLES)
            && (int)db()->query("SELECT COUNT(*) FROM salary_grid_rows")->fetchColumn() > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** salary_param as an array: law, draft, gradatii, salariu_minim, exported_on, link. */
function salary_est_params(): array {
    static $p = null;
    if ($p !== null) return $p;
    $p = [];
    foreach (db()->query("SELECT key, value FROM salary_param") as $r) {
        $p[$r['key']] = json_decode((string)$r['value'], true);
    }
    return $p;
}

/** The search key of salary_grid_rows.cautare: lower case, no diacritics, letters and digits only (as salary_grid.norm()). */
function salary_est_norm(string $s): string {
    $s = strtr(mb_strtolower($s, 'UTF-8'), [
        'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ö' => 'o', 'ô' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '');
}

/** The words of a search (at most 6, at least 2 characters each), normalised. */
function salary_est_tokens(string $q): array {
    $t = array_filter(array_unique(explode(' ', salary_est_norm($q))), fn($w) => strlen($w) >= 2);
    return array_slice(array_values($t), 0, 6);
}

const SALARY_EST_ANNEX_ORDER = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9];

/** Shorten a long function name (a row can list 20 alternatives). */
function salary_est_short(string $s, int $max = 140): string {
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
}

/**
 * Step 1: the functions whose name or synonym holds every word of the query, from the law grid and,
 * for a function that the law does not list, from the draft. One item for each (grid, annex, function).
 *
 * @return array{total:int, shown:int, groups:array<string, array<int, array>>}
 *         groups: heading => items [b (lege|proiect), ref (its first row: the link to step 2), a, f, name, also, n]
 */
function salary_est_search(string $q, int $limit = 60): array {
    $tokens = salary_est_tokens($q);
    $out = ['total' => 0, 'shown' => 0, 'groups' => []];
    if (!$tokens) return $out;
    $par = $tokens ? array_map(fn($t) => '%' . $t . '%', $tokens) : [];
    $like = implode(' AND ', array_fill(0, count($tokens), 'r.cautare LIKE ?'));
    $p = salary_est_params();
    $items = [];
    foreach ([['lege', $p['law']['version'], false], ['proiect', $p['draft']['version'], true]] as [$b, $version, $draft]) {
        $sql = "SELECT r.ref, r.anexa, r.functie, r.sectiune, r.sinonime, r.cautare,
                       EXISTS (SELECT 1 FROM salary_grid_link k WHERE k.draft_ref = r.ref) AS linked
                FROM salary_grid_rows r WHERE r.version = ? AND $like ORDER BY r.rowid";
        $st = db()->prepare($sql);
        $st->execute(array_merge([$version], $par));
        foreach ($st->fetchAll() as $r) {
            $key = $b . '|' . $r['anexa'] . '|' . $r['functie'];
            if (!isset($items[$key])) {
                // The synonym that matches, when the name itself does not.
                $also = '';
                if (!array_reduce($tokens, fn($c, $t) => $c && str_contains(salary_est_norm($r['functie']), $t), true)) {
                    foreach (explode(' | ', (string)$r['sinonime']) as $syn) {
                        $n = salary_est_norm($syn);
                        if (array_reduce($tokens, fn($c, $t) => $c && str_contains($n, $t), true)) { $also = $syn; break; }
                    }
                }
                $items[$key] = ['b' => $b, 'ref' => $r['ref'], 'a' => $r['anexa'], 'f' => $r['functie'], 'sec' => $r['sectiune'],
                                'name' => $r['functie'], 'also' => $also, 'n' => 0, 'linked' => 0];
            }
            $items[$key]['n']++;
            $items[$key]['linked'] += (int)$r['linked'];
        }
    }
    // A draft function that has a law row is reached through the law; only the others stay.
    $items = array_filter($items, fn($i) => $i['b'] === 'lege' || $i['linked'] === 0);
    usort($items, fn($x, $y) => [$x['b'] === 'lege' ? 0 : 1, SALARY_EST_ANNEX_ORDER[$x['a']] ?? 99, $x['sec'], $x['name']]
                           <=> [$y['b'] === 'lege' ? 0 : 1, SALARY_EST_ANNEX_ORDER[$y['a']] ?? 99, $y['sec'], $y['name']]);
    $out['total'] = count($items);
    foreach (array_slice($items, 0, $limit) as $i) {
        $head = ($i['b'] === 'proiect' ? 'Numai în proiect · ' : '') . 'Anexa ' . $i['a'] . ($i['sec'] !== '' ? ' · ' . salary_est_short($i['sec'], 90) : '');
        $out['groups'][$head][] = $i;
        $out['shown']++;
    }
    return $out;
}

/** One grid row by its ref (`$b`: lege|proiect), or null. A URL carries a ref: a function name can be 900 characters long. */
function salary_est_row(string $b, string $ref): ?array {
    $p = salary_est_params();
    $st = db()->prepare("SELECT * FROM salary_grid_rows WHERE version = ? AND ref = ?");
    $st->execute([$b === 'proiect' ? $p['draft']['version'] : $p['law']['version'], $ref]);
    return $st->fetch() ?: null;
}

/** The rows of the function of a row (same grid, annex and name), in grid order. */
function salary_est_function_rows(array $row): array {
    $st = db()->prepare("SELECT * FROM salary_grid_rows WHERE version = ? AND anexa = ? AND functie = ? ORDER BY rowid");
    $st->execute([$row['version'], $row['anexa'], $row['functie']]);
    return $st->fetchAll();
}

/**
 * The choices of step 2 for a function: for each dimension with more than one value among its rows,
 * the values in grid order. A blank value is `_`. "local" joins `nivel` for an Anexa VIII function that has a
 * territorial row: the pay of a local post comes from that row and the cap (ADR 0008, rule 3).
 *
 * @return array<string, array{label:string, options:array<string,string>}>
 */
function salary_est_dims(array $rows): array {
    $dims = [];
    foreach (SALARY_EST_DIMS as $param => [$col, $label]) {
        $vals = [];
        foreach ($rows as $r) $vals[(string)$r[$col]] = true;
        $has_local = $param === 'nivel' && isset($vals['teritorial']) && ($rows[0]['anexa'] ?? '') === 'VIII';
        if (count($vals) < 2 && !$has_local) continue;
        $opts = [];
        foreach (array_keys($vals) as $v) {
            $opts[$v === '' ? '_' : $v] = salary_est_dim_label($param, $v);
        }
        if ($has_local) $opts['local'] = 'local (primărie, consiliu județean)';
        $dims[$param] = ['label' => $label, 'options' => $opts];
    }
    return $dims;
}

function salary_est_dim_label(string $param, string $v): string {
    if ($v === '') return 'nespecificat';
    return match ($param) {
        'studii' => SALARY_EST_STUDY_LABELS[$v] ?? $v,
        'unit'   => SALARY_EST_UNIT_LABELS[$v] ?? $v,
        'sec'    => salary_est_short($v, 90),
        default  => $v,
    };
}

/** Keep the rows that match the chosen values ($chosen: param => value; '' = every value, '_' = blank, 'local' = territorial). */
function salary_est_filter(array $rows, array $chosen): array {
    foreach (SALARY_EST_DIMS as $param => [$col]) {
        $v = (string)($chosen[$param] ?? '');
        if ($v === '') continue;
        $want = $v === '_' ? '' : ($v === 'local' ? 'teritorial' : $v);
        $rows = array_values(array_filter($rows, fn($r) => (string)$r[$col] === $want));
    }
    return $rows;
}

/** The pay (lei_min, lei_max) of the given rows at one gradation, keyed by ref. */
function salary_est_pay(string $version, array $refs, int $g): array {
    $out = [];
    foreach (array_chunk(array_values(array_unique($refs)), 400) as $chunk) {
        $st = db()->prepare("SELECT ref, lei_min, lei_max FROM salary_grid_pay WHERE version = ? AND gradatie = ? AND ref IN ("
            . implode(',', array_fill(0, count($chunk), '?')) . ")");
        $st->execute(array_merge([$version, $g], $chunk));
        foreach ($st->fetchAll() as $r) $out[$r['ref']] = [(int)$r['lei_min'], (int)$r['lei_max']];
    }
    return $out;
}

/** The draft rows that the link table pairs with these law rows. */
function salary_est_draft_rows(array $law_refs): array {
    $p = salary_est_params();
    $refs = [];
    foreach (array_chunk(array_values(array_unique($law_refs)), 400) as $chunk) {
        $st = db()->prepare("SELECT DISTINCT draft_ref FROM salary_grid_link WHERE law_ref IN ("
            . implode(',', array_fill(0, count($chunk), '?')) . ")");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $r) $refs[$r] = true;
    }
    $out = [];
    foreach (array_chunk(array_keys($refs), 400) as $chunk) {
        $st = db()->prepare("SELECT * FROM salary_grid_rows WHERE version = ? AND ref IN ("
            . implode(',', array_fill(0, count($chunk), '?')) . ") ORDER BY rowid");
        $st->execute(array_merge([$p['draft']['version']], $chunk));
        foreach ($st->fetchAll() as $r) $out[] = $r;
    }
    return $out;
}

/** The local caps (salary_cap), by id. */
function salary_est_caps(): array {
    return db()->query("SELECT * FROM salary_cap ORDER BY id")->fetchAll();
}

/** "comună, până la 3.000 de locuitori": the type and population of a cap row. */
function salary_est_cap_label(array $c): string {
    $f = fn($n) => number_format((int)$n, 0, ',', '.');
    $lo = $c['populatie_min']; $hi = $c['populatie_max'];
    $pop = ($lo === null && $hi === null) ? ''
         : ($hi === null ? ', peste ' . $f((int)$lo - 1 > 0 ? (int)$lo - 1 : 0) . ' de locuitori'
         : ((int)$lo <= 0 ? ', până la ' . $f($hi) . ' de locuitori' : ', ' . $f($lo) . '–' . $f($hi) . ' de locuitori'));
    return $c['tip_unitate'] . $pop;
}

/** The cap that the page uses without a choice: the widest one that is not the Capital's (as Grid.local_cap()). */
function salary_est_default_cap(array $caps): ?array {
    $best = null;
    foreach ($caps as $c) {
        if ($c['tip_unitate'] === 'capitală') continue;
        if ($best === null || (int)$c['plafon_maxim_lei'] > (int)$best['plafon_maxim_lei']) $best = $c;
    }
    return $best;
}

/**
 * Everything the page shows for one function and one set of choices.
 *
 * @param array $in  b (lege|proiect), ref (a row of the function), dims (param => value), g (0-5), cap (cap id or 0)
 * @return array|null  null when the function does not exist
 */
function salary_est_compute(array $in): ?array {
    $p = salary_est_params();
    $b = $in['b'] === 'proiect' ? 'proiect' : 'lege';
    $row = salary_est_row($b, (string)$in['ref']);
    if (!$row) return null;
    $all = salary_est_function_rows($row);
    // The wizard's studies step narrows the rows. A level that matches no row is ignored.
    $st = (string)($in['st'] ?? '');
    if (isset(SALARY_EST_LEVELS[$st])) {
        $by_level = array_values(array_filter($all, fn($r) => salary_est_row_has_level($r, $st)));
        if ($by_level) $all = $by_level;
    }
    $g = max(0, min(5, (int)($in['g'] ?? 0)));
    $dims = salary_est_dims($all);
    // A value that is not an option of this function means "every value".
    $chosen = [];
    foreach ($dims as $param => $d) {
        $v = (string)(($in['dims'] ?? [])[$param] ?? '');
        $chosen[$param] = isset($d['options'][$v]) ? $v : '';
    }
    $local = ($chosen['nivel'] ?? '') === 'local';
    $rows = salary_est_filter($all, $chosen);
    $res = ['b' => $b, 'ref' => $row['ref'], 'a' => $row['anexa'], 'f' => $row['functie'], 'g' => $g, 'st' => isset(SALARY_EST_LEVELS[$st]) ? $st : '', 'dims' => $dims, 'chosen' => $chosen,
            'local' => $local, 'n_all' => count($all), 'rows' => $rows, 'law' => null, 'draft' => null, 'cap' => null,
            'caps' => [], 'hints' => []];

    $unchosen = [];
    foreach ($dims as $param => $d) {
        if ($chosen[$param] !== '' || $param === 'nivel' && count($d['options']) < 2) continue;
        if (count(array_unique(array_map(fn($r) => $r[SALARY_EST_DIMS[$param][0]], $rows))) > 1) $unchosen[] = mb_strtolower($d['label']);
    }
    $res['hints'] = $unchosen;

    if ($b === 'lege') {
        $pay = salary_est_pay($p['law']['version'], array_column($rows, 'ref'), $g);
        $lo = $hi = null;
        foreach ($rows as $r) {
            [$a, $z] = $pay[$r['ref']] ?? [null, null];
            if ($a === null) continue;
            $lo = $lo === null ? $a : min($lo, $a);
            $hi = $hi === null ? $z : max($hi, $z);
        }
        $law = ['rows' => $rows, 'pay' => $pay, 'lo' => $lo, 'hi' => $hi, 'local' => $local,
                'mgmt' => $rows && count(array_filter($rows, fn($r) => $r['tip_post'] === 'conducere')) === count($rows)];
        if ($local && $lo !== null) {
            $caps = salary_est_caps();
            $cap = null;
            foreach ($caps as $c) if ((int)$c['id'] === (int)($in['cap'] ?? 0)) $cap = $c;
            $res['cap_chosen'] = $cap !== null;
            $cap = $cap ?? salary_est_default_cap($caps);
            if ($cap) $law['hi'] = max($law['hi'], (int)$cap['plafon_maxim_lei']);
            $res['cap'] = $cap;
            $res['caps'] = $caps;
        }
        $res['law'] = $rows ? $law : null;
        // The draft: the rows that the link table pairs with the law rows.
        $drows = $rows ? salary_est_draft_rows(array_column($rows, 'ref')) : [];
        if ($drows) {
            $dpay = salary_est_pay($p['draft']['version'], array_column($drows, 'ref'), $g);
            $res['draft'] = ['rows' => $drows, 'pay' => $dpay,
                             'lo' => min(array_column($dpay, 0)), 'hi' => max(array_column($dpay, 1)),
                             'bands' => count(array_unique(array_filter(array_column($drows, 'banda_populatie'))))];
        }
    } else {
        $dpay = salary_est_pay($p['draft']['version'], array_column($rows, 'ref'), $g);
        if ($rows && $dpay) {
            $res['draft'] = ['rows' => $rows, 'pay' => $dpay, 'lo' => min(array_column($dpay, 0)), 'hi' => max(array_column($dpay, 1)),
                             'bands' => count(array_unique(array_filter(array_column($rows, 'banda_populatie'))))];
        }
    }
    return $res;
}

/** The estimator URL of one law row: the link of the detail page. `$local` keeps the range of a local post. */
function salary_est_row_url(string $ref, int $g = 0, bool $local = false): string {
    return '/estimator-salariu/?rand=' . rawurlencode($ref) . '&g=' . $g . ($local ? '&nivel=local' : '');
}


// ---- Salary estimator wizard (UX-SALARY-WIZARD) and INS context line (SALARY-INS-LINE) ----
//
// The wizard: domain (annex) -> studies -> function -> variants -> result. Every state has a GET URL
// (`?d=<annex>`, `?d=<annex>&st=<level>`, `?b=&fn=&st=…`). The domain and the studies only filter the
// list: the page stores nothing about the reader (ADR 0008).

/** The studies levels of the wizard, in the order of the page. */
const SALARY_EST_LEVELS = [
    'S' => 'Superioare', 'SSD' => 'Superioare de scurtă durată', 'PL' => 'Postliceale', 'M' => 'Medii', 'G' => 'Generale',
];

/**
 * The domains: annex => [short label, CAEN Rev. 2 section of the INS line or null].
 * The labels are short forms of the titles of the law annexes (Legea 153/2017, Anexele I-IX).
 * The CAEN mapping is the one of ops/build-ins-context.py (DOMAIN_CAEN): Învățământ P, Sănătate Q,
 * Cultură R, Administrație, Justiție, Diplomație, Apărare și autorități O. Anexa VII (own revenues) has no sector.
 */
const SALARY_EST_DOMAINS = [
    'I'    => ['Învățământ', 'P'],
    'II'   => ['Sănătate și asistență socială', 'Q'],
    'III'  => ['Cultură', 'R'],
    'IV'   => ['Diplomație', 'O'],
    'V'    => ['Justiție', 'O'],
    'VI'   => ['Apărare, ordine publică și securitate națională', 'O'],
    'VII'  => ['Instituții finanțate din venituri proprii', null],
    'VIII' => ['Administrație și alte funcții comune', 'O'],
    'IX'   => ['Autorități publice și instituții autonome', 'O'],
];

/** The CAEN Rev. 2 section => the activity of the INS line. */
const SALARY_EST_CAEN_LABELS = [
    'O' => 'administrația publică și apărare', 'P' => 'învățământ', 'Q' => 'sănătate și asistență socială',
    'R' => 'activități de spectacole, culturale și recreative',
];

/** True when the export has this table (an older export has no `salary_title` or `salary_ins_context`). */
function salary_est_has_table(string $name): bool {
    static $cache = [];
    if (!isset($cache[$name])) {
        try {
            $st = db()->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            $st->execute([$name]);
            $cache[$name] = (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            $cache[$name] = false;
        }
    }
    return $cache[$name];
}

/** The studies levels that a `studii` value of the grid names: `PL/M` => PL, M. A value with no level: []. */
function salary_est_study_levels(string $studii): array {
    preg_match_all('/(?<![A-Za-z])(SSD|PL|S|M|G)(?![A-Za-z])/', $studii, $m);
    return array_values(array_unique($m[1]));
}

/** True when a row belongs under a level: it names the level, or it names no level at all. */
function salary_est_row_has_level(array $row, string $level): bool {
    $lv = salary_est_study_levels((string)$row['studii']);
    return !$lv || in_array($level, $lv, true);
}

/** The domains of the wizard, in annex order: [annex, label, in_law (bool)]. Only annexes that the export has. */
function salary_est_domains(): array {
    $p = salary_est_params();
    $in_law = [];
    $have = [];
    foreach (db()->query("SELECT DISTINCT version, anexa FROM salary_grid_rows") as $r) {
        $have[$r['anexa']] = true;
        if ($r['version'] === $p['law']['version']) $in_law[$r['anexa']] = true;
    }
    $out = [];
    foreach (SALARY_EST_DOMAINS as $annex => [$label]) {
        if (isset($have[$annex])) $out[] = ['a' => $annex, 'label' => $label, 'law' => isset($in_law[$annex])];
    }
    return $out;
}

/**
 * The functions of one annex, one item for each (grid, function name): the law grid first; a draft
 * function that has a law row is reached through the law, so only the other draft functions stay.
 *
 * @return list<array{b:string, ref:string, name:string, post:string, levels:list<string>, blank:bool}>
 *         post: execuție | conducere | altele; levels: the studies levels that the rows name;
 *         blank: some row names no level (the function then shows under every level)
 */
function salary_est_items(string $annex): array {
    static $cache = [];
    if (isset($cache[$annex])) return $cache[$annex];
    $p = salary_est_params();
    $items = [];
    foreach ([['lege', $p['law']['version']], ['proiect', $p['draft']['version']]] as [$b, $version]) {
        $st = db()->prepare("SELECT r.ref, r.functie, r.tip_post, r.studii,
                                    EXISTS (SELECT 1 FROM salary_grid_link k WHERE k.draft_ref = r.ref) AS linked
                             FROM salary_grid_rows r WHERE r.version = ? AND r.anexa = ? ORDER BY r.rowid");
        $st->execute([$version, $annex]);
        foreach ($st->fetchAll() as $r) {
            $k = $b . '|' . $r['functie'];
            $items[$k] ??= ['b' => $b, 'ref' => $r['ref'], 'name' => $r['functie'], 'posts' => [], 'levels' => [], 'blank' => false, 'linked' => 0];
            $lv = salary_est_study_levels((string)$r['studii']);
            if ($lv) $items[$k]['levels'] = array_values(array_unique(array_merge($items[$k]['levels'], $lv)));
            else $items[$k]['blank'] = true;
            $items[$k]['posts'][$r['tip_post']] = true;
            $items[$k]['linked'] += (int)$r['linked'];
        }
    }
    $out = [];
    foreach ($items as $i) {
        if ($i['b'] === 'proiect' && $i['linked'] > 0) continue;
        $posts = array_keys($i['posts']);
        $i['post'] = count($posts) === 1 && in_array($posts[0], ['execuție', 'conducere'], true) ? $posts[0] : 'altele';
        unset($i['posts'], $i['linked']);
        $out[] = $i;
    }
    usort($out, fn($x, $y) => salary_est_norm($x['name']) <=> salary_est_norm($y['name']));
    return $cache[$annex] = $out;
}

/** The levels of one annex that some row names, with the number of functions under each: level => count. */
function salary_est_level_counts(string $annex): array {
    $items = salary_est_items($annex);
    $named = [];
    foreach ($items as $i) foreach ($i['levels'] as $l) $named[$l] = true;
    $out = [];
    foreach (SALARY_EST_LEVELS as $l => $_label) {
        if (!isset($named[$l])) continue;
        $out[$l] = count(array_filter($items, fn($i) => $i['blank'] || in_array($l, $i['levels'], true)));
    }
    return $out;
}

/** The functions of one annex under one level (step 3), optionally narrowed by a text filter. A blank level means all (an annex whose rows name no studies). */
function salary_est_level_items(string $annex, string $level, string $filter = ''): array {
    $tokens = salary_est_tokens($filter);
    return array_values(array_filter(salary_est_items($annex), function ($i) use ($level, $tokens) {
        if ($level !== '' && !$i['blank'] && !in_array($level, $i['levels'], true)) return false;
        $n = salary_est_norm($i['name']);
        foreach ($tokens as $t) if (!str_contains($n, $t)) return false;
        return true;
    }));
}

/**
 * The title shortcut: the job titles of the postings (salary_title) that hold every word of the query.
 * The grid row and the grade come with the title. Empty when the export has no `salary_title`.
 *
 * @return array{total:int, items:list<array>}  items: titlu, nr, ref, grad, functie, anexa
 */
function salary_est_title_search(string $q, int $limit = 30): array {
    $out = ['total' => 0, 'items' => []];
    $tokens = salary_est_tokens($q);
    if (!$tokens || !salary_est_has_table('salary_title')) return $out;
    $like = implode(' AND ', array_fill(0, count($tokens), 't.cautare LIKE ?'));
    $par = array_map(fn($t) => '%' . $t . '%', $tokens);
    $law = salary_est_params()['law']['version'];
    $st = db()->prepare("SELECT COUNT(*) FROM salary_title t WHERE $like");
    $st->execute($par);
    $out['total'] = (int)$st->fetchColumn();
    $st = db()->prepare("SELECT t.titlu, t.nr, t.ref, t.grad, r.functie, r.anexa FROM salary_title t
                         JOIN salary_grid_rows r ON r.version = ? AND r.ref = t.ref
                         WHERE $like ORDER BY t.nr DESC, t.titlu LIMIT " . (int)$limit);
    $st->execute(array_merge([$law], $par));
    $out['items'] = $st->fetchAll();
    return $out;
}

/** The counties of the INS rows of one CAEN section (the select of step 4), in alphabetical order. */
function salary_est_ins_counties(string $section): array {
    if (!salary_est_has_table('salary_ins_context')) return [];
    $st = db()->prepare("SELECT DISTINCT judet FROM salary_ins_context WHERE sectiune_caen = ? AND judet <> ''");
    $st->execute([$section]);
    $c = $st->fetchAll(PDO::FETCH_COLUMN);
    usort($c, fn($a, $b) => salary_est_norm($a) <=> salary_est_norm($b));
    return $c;
}

/**
 * The INS context line of a result: the average net earnings of the domain of the annex, for the country
 * or for one county. Context, never the estimate (ADR 0008 rules 5 and 8). Null when the annex has no
 * sector, the export has no table, or no row fits (a county without a row gets no line).
 *
 * @return array{text:string, matrix_links:list<array{code:string,url:string}>, section:string, judet:string, period:string, value:int}|null
 */
function salary_est_ins_line(string $annex, string $judet = ''): ?array {
    $section = SALARY_EST_DOMAINS[$annex][1] ?? null;
    if ($section === null || !salary_est_has_table('salary_ins_context')) return null;
    $st = db()->prepare("SELECT matrice, perioada, valoare FROM salary_ins_context
                         WHERE sectiune_caen = ? AND judet = ? AND masura = 'castig_net_mediu_lunar'
                         ORDER BY perioada DESC LIMIT 1");
    $st->execute([$section, $judet]);
    $r = $st->fetch();
    if (!$r) return null;
    $months = [1 => 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
    $period = (string)$r['perioada'];
    $period_label = preg_match('/^(\d{4})-(\d{2})$/', $period, $m) ? $months[(int)$m[2]] . ' ' . $m[1] : 'anul ' . $period;
    $place = $judet === '' ? 'România' : ($judet === 'București' ? 'municipiul București' : 'județul ' . $judet);
    $links = [];
    foreach (explode('+', (string)$r['matrice']) as $code) {
        $links[] = ['code' => $code, 'url' => 'https://ins.gov2.ro/dataset-v2.html?code=' . rawurlencode($code)];
    }
    return [
        'text' => 'câștigul salarial mediu net lunar în ' . SALARY_EST_CAEN_LABELS[$section] . ', ' . $place . ', ' . $period_label
                . ': ' . number_format((int)round((float)$r['valoare']), 0, ',', '.') . ' lei',
        'matrix_links' => $links, 'section' => $section, 'judet' => $judet, 'period' => $period, 'value' => (int)round((float)$r['valoare']),
    ];
}

// ---- Declared pay per institution (SALARY-DECLARATII-PAGE, ADR 0008 rule 9) ----

/** True when the export has the declared-pay tables (an older export does not). */
function declared_available(): bool {
    static $ok = null;
    if ($ok === null) {
        $n = (int)db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('declared_institutions', 'declared_groups')")->fetchColumn();
        $ok = $n === 2;
    }
    return $ok;
}

/** One institution (the row of declared_institutions) by slug, or null. */
function declared_institution(string $slug): ?array {
    if ($slug === '' || !declared_available()) return null;
    $st = db()->prepare("SELECT * FROM declared_institutions WHERE slug = ?");
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

/** The institution of an employer (at most one), or null. */
function declared_institution_of_employer(int $employer_id): ?array {
    if (!declared_available()) return null;
    // Every employer record that matches the institution (declared_employers), not only the main one:
    // the site has several records for one body. An export from before that table uses the main employer.
    static $links = null;
    if ($links === null) {
        $links = (int)db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'declared_employers'")->fetchColumn() === 1;
    }
    $st = db()->prepare($links
        ? "SELECT di.* FROM declared_employers de JOIN declared_institutions di ON di.slug = de.institution_slug WHERE de.employer_id = ?"
        : "SELECT * FROM declared_institutions WHERE employer_id = ?");
    $st->execute([$employer_id]);
    return $st->fetch() ?: null;
}

/** The groups of an institution, the largest first. */
function declared_groups_of(string $slug): array {
    $st = db()->prepare("SELECT * FROM declared_groups WHERE institution_slug = ? ORDER BY n DESC, functie");
    $st->execute([$slug]);
    return $st->fetchAll();
}
