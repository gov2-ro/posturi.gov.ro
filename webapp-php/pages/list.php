<?php
declare(strict_types=1);

$PAGE_SIZE = 25;

// ---- Read GET params ----
$q            = trim($_GET['q'] ?? '');
$judet_slugs  = (array)($_GET['judet']          ?? []);
$levels       = (array)($_GET['level']          ?? []);
$types        = (array)($_GET['type']           ?? []);
$categories   = (array)($_GET['categorie']      ?? []);
$emp_cats     = (array)($_GET['employer_cat']   ?? []);
$families     = (array)($_GET['family']         ?? []);
$seniorities  = (array)($_GET['seniority']      ?? []);
$work_types   = (array)($_GET['work_type']      ?? []);
$exp_levels   = (array)($_GET['exp_level']      ?? []);
$studies_lvls = (array)($_GET['studies_level']  ?? []);
$anomaly_flags = (array)($_GET['anomaly']       ?? []);
$remote       = $_GET['remote']        ?? '';
$computer     = $_GET['computer']      ?? '';
$sal_bucket   = $_GET['salary_bucket'] ?? '';
$exp_before   = $_GET['expires_before'] ?? '';
$exp_after    = $_GET['expires_after']  ?? '';
$status       = $_GET['status']        ?? DEFAULT_STATUS;
$schema_filter = $_GET['schema']       ?? '';
$isced_sel     = (array)($_GET['isced']      ?? []);
$skill_sel     = (array)($_GET['skill']      ?? []);
$lang_sel      = (array)($_GET['lang']       ?? []);
$cred_sel      = (array)($_GET['credential'] ?? []);
$domain_sel    = (array)($_GET['domain']     ?? []);
$stage_sel     = (array)($_GET['stage']      ?? []);
// `(array)` keeps a legacy scalar `?eqf=6` link (detail.php ships them) working.
$eqf_sel       = (array)($_GET['eqf'] ?? []);
$occ_sel       = (array)($_GET['occupation'] ?? []);
$funding_sel   = (array)($_GET['funding']    ?? []);
$sector_sel    = (array)($_GET['sector']     ?? []);
$has_salary    = $_GET['has_salary'] ?? '';
$duration_sel  = (array)($_GET['duration'] ?? []);
$schedule_sel  = (array)($_GET['schedule'] ?? []);
$shift         = $_GET['shift'] ?? '';
$loc_sel       = (array)($_GET['loc'] ?? []);
$recr_sel      = (array)($_GET['recruitment'] ?? []);
$parttime      = $_GET['parttime'] ?? '';
$temp_vacant   = $_GET['temp_vacant'] ?? '';
$remote_apply  = $_GET['remote_apply'] ?? '';
$published     = $_GET['published'] ?? '';
$sort         = $_GET['sort']          ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));

// UX-01B: a legacy `?status=all` keeps its meaning (no status clause in
// build_filters) and no tab claims to be current; anything else unknown is the default.
$status_tab = isset(STATUS_LABELS[$status]) ? $status : ($status === 'all' ? '' : DEFAULT_STATUS);
if (!isset(STATUS_LABELS[$status])) $status = DEFAULT_STATUS;

// ---- Check if HTMX partial request ----
// The cache-buster parameter, not the header, decides (see index.php): the
// fragment and the full page must never share a URL.
$is_htmx = !empty($hx_fragment);

// ---- Build base filter ----
$f = build_filters($_GET);
$base_where = $f['where'];
$base_binds = $f['binds'];
$use_fts    = $f['fts'];
$match_expr = $use_fts ? fts_query($q) : '';

// ---- Main results query ----
$w = $base_where;
$b = $base_binds;
$join = '';

if ($match_expr !== '') {
    $join = "JOIN job_postings_fts fts ON fts.rowid = j.id";
    $w[] = "job_postings_fts MATCH ?";
    $b[] = $match_expr;
}

$where_sql = $w ? 'WHERE ' . implode(' AND ', $w) : '';

if ($match_expr !== '' && !$sort) {
    // bm25 with column weights, so a title hit outranks a passing body mention
    $order = "ORDER BY " . FTS_RANK . ", j.published_at DESC";
} elseif ($sort === 'deadline') {
    $order = "ORDER BY " . DEADLINE_COL . " ASC, j.published_at DESC";
} elseif ($sort === 'employer') {
    $order = "ORDER BY j.employer_name ASC, j.published_at DESC";
} elseif ($sort === 'salary') {
    // 261016: highest pay first, stated or estimated (the same SALARY_EXPR the
    // salary filter and every row's 💰 use); postings without one go last.
    $order = "ORDER BY " . SALARY_EXPR . " IS NULL, " . SALARY_EXPR . " DESC, j.published_at DESC";
} else {
    $order = "ORDER BY j.published_at DESC, j.id DESC";
}

// Count
$count_sql = "SELECT COUNT(*) FROM job_postings j $join $where_sql";
$stmt = db()->prepare($count_sql);
$stmt->execute($b);
$total_count = (int)$stmt->fetchColumn();

$num_pages = max(1, (int)ceil($total_count / $PAGE_SIZE));
$page = min($page, $num_pages);
$offset = ($page - 1) * $PAGE_SIZE;

$list_sql = "SELECT j.*, e.name AS employer_name_display, e.slug AS employer_slug_display
             FROM job_postings j
             $join
             LEFT JOIN employers e ON e.id = j.employer_id
             $where_sql
             $order
             LIMIT $PAGE_SIZE OFFSET $offset";
$stmt = db()->prepare($list_sql);
$stmt->execute($b);
$postings = $stmt->fetchAll();

timing_mark('main');
// ---- Active filters ----
$active_chips  = active_filter_chips();
$is_unfiltered = $page === 1 && !$active_chips;

// The "N anunțuri active · M angajatori" line above the shortcuts was dropped
// 2026-10-16: the total is said once, by the count just above the results.

// ---- Landing shortcuts ----
//
// The unfiltered page needs a way in that is not "type something". These are
// entry points, not a filter UI: plain links, shown only when nothing is
// selected, each landing on a filter the sidebar can then refine. Counts are
// computed here rather than read off $..._options so capping and ordering in
// the facet lists cannot quietly change what the landing offers.
$shortcuts = [];
$home_new = $home_soon = [];
$home_new_day = '';
if ($is_unfiltered) {
    // 261016: the ways in are the jobs most on offer (the occupations with the
    // most open postings) and a few cuts that need several filters' worth of
    // knowledge to find — no experience, secondary studies, high pay, a civil
    // service post. Each count is what the link will show: the same
    // build_filters() predicate, under the default "Active" status.
    $count_for = function (array $params): int {
        $f = build_filters($params + ['status' => DEFAULT_STATUS]);
        $w = $f['where'] ? 'WHERE ' . implode(' AND ', $f['where']) : '';
        $st = db()->prepare("SELECT COUNT(*) FROM job_postings j $w");
        $st->execute($f['binds']);
        return (int)$st->fetchColumn();
    };
    $live = "application_status IN (" . OPEN_STATUS_SQL . ")";
    foreach (db()->query(
        "SELECT occ_canonical AS v, COUNT(*) AS c FROM job_postings
         WHERE occ_canonical <> '' AND $live
         GROUP BY 1 ORDER BY 2 DESC, 1 LIMIT 6") as $r) {
        $shortcuts[] = ['href' => '/?occupation%5B%5D=' . rawurlencode($r['v']),
                        'label' => $r['v'], 'count' => (int)$r['c']];
    }
    foreach ([
        ['Fără experiență',     ['exp_level' => ['fara']]],
        ['Studii medii',        ['studies_level' => ['liceala']]],
        ['Salariu peste 7.000', ['salary_bucket' => 'peste-7000']],
        ['Funcție publică',     ['categorie' => ['Funcție publică']]],
        ['Fonduri europene',    ['funding' => ['fonduri_europene']]],
        ['Telemuncă',           ['remote' => '1']],
    ] as [$label, $params]) {
        $c = $count_for($params);
        if ($c > 0) $shortcuts[] = ['href' => '/?' . http_build_query($params), 'label' => $label, 'count' => $c];
    }

    // D15: "Noi azi" and "Se închid curând" above the results, on the bare home
    // page only (any parameter — a sort, a status — means a list is wanted). New = the latest
    // publication day in the data (named "azi" when it is today), so the section
    // is not empty every morning before the first run.
    if (!$_GET) {
        $home_new_day = (string)db()->query(
            "SELECT MAX(substr(published_at, 1, 10)) FROM job_postings WHERE $live")->fetchColumn();
        if ($home_new_day !== '') {
            $st = db()->prepare("SELECT j.*, e.slug AS employer_slug_display FROM job_postings j
                LEFT JOIN employers e ON e.id = j.employer_id
                WHERE j.$live AND substr(j.published_at, 1, 10) = ?
                ORDER BY j.published_at DESC, j.id DESC LIMIT 6");
            $st->execute([$home_new_day]);
            $home_new = $st->fetchAll();
        }
        $today = ro_today();
        $in3 = (new DateTimeImmutable($today, new DateTimeZone('Europe/Bucharest')))->modify('+3 days')->format('Y-m-d');
        $st = db()->prepare("SELECT j.*, e.slug AS employer_slug_display FROM job_postings j
            LEFT JOIN employers e ON e.id = j.employer_id
            WHERE j.application_status = 'confirmed_open' AND " . DEADLINE_COL . " >= ? AND " . DEADLINE_COL . " <= ?
            ORDER BY " . DEADLINE_COL . " ASC, j.published_at DESC LIMIT 6");
        $st->execute([$today, $in3]);
        $home_soon = $st->fetchAll();
    }
}

timing_mark('shortcuts');
// ---- Facet counts ----

/**
 * WHERE fragments + FTS join for a facet count query, with this facet's own
 * selection excluded so its options keep showing sibling counts.
 */
function facet_scope(string $excl_key): array {
    global $q;
    $ff = build_filters($_GET, true, $excl_key);
    // `where`/`binds` apply every group to `j` (role groups through one EXISTS).
    // `posting_*` and `role_*` are the same selection split for the role-scoped
    // facets below, which count over a join of j and r instead.
    $scope = ['join' => '', 'where' => $ff['where'], 'binds' => $ff['binds'],
              'posting_where' => $ff['posting_where'], 'posting_binds' => $ff['posting_binds'],
              'role_where' => $ff['role_where'], 'role_binds' => $ff['role_binds']];
    if ($ff['fts'] && ($m = fts_query($q)) !== '') {
        $scope['join']            = "JOIN job_postings_fts fts ON fts.rowid = j.id";
        $scope['where'][]         = "job_postings_fts MATCH ?";
        $scope['binds'][]         = $m;
        $scope['posting_where'][] = "job_postings_fts MATCH ?";
        $scope['posting_binds'][] = $m;
    }
    return $scope;
}

/**
 * Run a query over (announcement x role) for a role-scoped facet.
 *
 * Why a join and not facet_counts(): a role facet's count has to answer "how many
 * announcements would the list return if this value were added", and the list
 * requires ONE role to satisfy every role-scoped group. So the value being counted
 * is read off the same role row `r` that the other role groups are applied to
 * (their conditions come from the scope's `role_where`, this facet's own group
 * already excluded by facet_scope()), the announcement-level conditions stay on `j`,
 * and callers count DISTINCT j.id so an announcement with two matching roles counts
 * once. Posting-level binds come first, role binds second, `$extra_binds` last,
 * the order the clauses are written in.
 */
function role_scope_query(array $scope, string $select, array $extra_where = [],
                          array $extra_binds = [], string $tail = ''): PDOStatement {
    $w = array_merge($scope['posting_where'], $scope['role_where'], $extra_where);
    $b = array_merge($scope['posting_binds'], $scope['role_binds'], $extra_binds);
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $st = db()->prepare("SELECT $select
        FROM job_postings j JOIN posting_roles r ON r.posting_id = j.id {$scope['join']}
        $where $tail");
    $st->execute($b);
    return $st;
}

/** Single-valued role facet (EQF, study level): [{val, cnt}], announcements counted once. */
function role_value_facet(string $role_col, string $excl_key, string $order = 'cnt DESC'): array {
    $s = facet_scope($excl_key);
    return role_scope_query(
        $s, "r.$role_col AS val, COUNT(DISTINCT j.id) AS cnt",
        ["r.$role_col IS NOT NULL", "r.$role_col != ''"], [],
        "GROUP BY r.$role_col ORDER BY $order"
    )->fetchAll();
}

/** COUNT(*) over a facet scope with extra clauses appended. */
function scope_count(array $scope, array $extra_where = [], array $extra_binds = []): int {
    $w = array_merge($scope['where'], $extra_where);
    $b = array_merge($scope['binds'], $extra_binds);
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $st = db()->prepare("SELECT COUNT(*) FROM job_postings j {$scope['join']} $where");
    $st->execute($b);
    return (int)$st->fetchColumn();
}

function get_facet(string $col, string $excl_key, int $limit = 0): array {
    global $q;
    $ff = build_filters($_GET, true, $excl_key);
    return facet_counts($col, $ff['where'], $ff['binds'], $ff['fts'], $q, $limit);
}

// Județ facets on the slug but labels with the proper county name — the slug
// is what the URL carries, the name is what a reader recognises. No cap: after
// normalisation there are only 42 counties, and truncating used to hide
// selected values (see facet_group's orphan pinning).
$judet_options = [];
{
    $s = facet_scope('judet_slugs');
    $w = array_merge($s['where'], ["j.judet_slug != ''", "j.judet_slug IS NOT NULL"]);
    $st = db()->prepare("SELECT j.judet_slug AS val, MIN(j.judet_name) AS label, COUNT(*) AS cnt
        FROM job_postings j {$s['join']} WHERE " . implode(' AND ', $w) . "
        GROUP BY j.judet_slug ORDER BY cnt DESC, label ASC");
    $st->execute($s['binds']);
    $judet_options = $st->fetchAll();
}
// UX-02 (D16): localities of the chosen counties. Offered only once a county is
// chosen — across the whole country the list is thousands long and means
// little — or while a locality is selected, so its checkbox stays removable.
$loc_options = ($judet_slugs || $loc_sel) ? get_facet('locality', 'locs', 80) : [];
timing_mark('f-judet');

// BETA-FILTERS (D4, UX-01): one control per question. Dropped from the page
// (their URL parameters still filter and show as removable chips): the source
// job_type (Contract covers it, finer), the text-derived grade and work type,
// the EQF number (Nivel de studii says it in words), exam stages, and the
// pipeline diagnostics (anomalies, structured-or-not).
$level_options    = get_facet('job_level',            'levels');
$cat_options      = get_facet('categorie',            'categories');
$emp_cat_options  = get_facet('employer_category',    'employer_cats',  20);

// Profession family — same as get_facet(), minus the 'altele' catch-all bucket
$family_options = [];
{
    $s = facet_scope('families');
    $w2 = array_merge($s['where'], [
        "j.inf_profession_family IS NOT NULL",
        "j.inf_profession_family != ''",
        "j.inf_profession_family != 'altele'",
    ]);
    $stmt2 = db()->prepare("SELECT j.inf_profession_family AS val, COUNT(*) AS cnt
        FROM job_postings j {$s['join']} WHERE " . implode(' AND ', $w2) . "
        GROUP BY j.inf_profession_family ORDER BY cnt DESC");
    $stmt2->execute($s['binds']);
    $family_options = $stmt2->fetchAll();
}

// Minimum study level is a role fact (DATA-04): counted over posting_roles.
$studies_options   = role_value_facet('study_level', 'studies_levels');

timing_mark('f-basic');
// Romanian labels for the enum-valued facets (values and URLs are untouched; an
// unfamiliar value keeps its raw spelling so it stays visible, not guessed at).
foreach ($family_options    as &$o) { $o['label'] = family_label((string)$o['val']); } unset($o);
foreach ($level_options     as &$o) { $o['label'] = job_level_label((string)$o['val']); } unset($o);
foreach ($studies_options   as &$o) { $o['label'] = STUDIES_LABELS[$o['val']] ?? $o['val']; } unset($o);

// Status counts — one pass instead of three
{
    $s = facet_scope('status');
    $where_st = $s['where'] ? 'WHERE ' . implode(' AND ', $s['where']) : '';
    $today = ro_today();
    $in7   = (new DateTimeImmutable($today, new DateTimeZone('Europe/Bucharest')))
        ->modify('+7 days')->format('Y-m-d');
    $open  = "application_status IN (" . OPEN_STATUS_SQL . ")";
    $st = db()->prepare("SELECT
            SUM(CASE WHEN j.$open THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN j.$open AND j.apply_deadline >= ? AND j.apply_deadline <= ? THEN 1 ELSE 0 END) AS soon,
            SUM(CASE WHEN j.application_status = 'closed' THEN 1 ELSE 0 END) AS closed,
            SUM(CASE WHEN j.application_status = 'unknown' THEN 1 ELSE 0 END) AS unknown
        FROM job_postings j {$s['join']} $where_st");
    $st->execute(array_merge([$today, $in7], $s['binds']));
    $r = $st->fetch() ?: [];
    $status_counts = [
        'active' => (int)($r['active'] ?? 0),
        'soon'   => (int)($r['soon'] ?? 0),
        'closed'  => (int)($r['closed'] ?? 0),
        'unknown' => (int)($r['unknown'] ?? 0),
    ];
}

/**
 * Facet options for a `v3_*` JSON-array column.
 *
 * Counts each distinct value with a LIKE probe. Returns [] when no posting
 * carries v3 data yet, and facet_group() then omits the whole group — so these
 * filters stay invisible until `llm-schema.py --prompt-version v3` has run.
 *
 * The count scope depends on how the group combines its values. An OR group
 * drops its own selection, so each number reads "check this too and you get N
 * more" — the promise a facet count makes everywhere else in this sidebar. An
 * AND group cannot borrow that: there checking another box *narrows*, so the
 * honest number is the residual — rows still standing that also carry this
 * value — which means counting with the group's own selection applied. Values
 * whose residual is zero simply stop appearing, and facet_group() pins any
 * that are checked back to the top.
 */
function v3_facet(string $column, string $excl_key, callable $label, int $limit = 30): array {
    $s = facet_scope(facet_mode($excl_key) === 'all' ? '' : $excl_key);
    $where = $s['where'] ? 'WHERE ' . implode(' AND ', $s['where']) . " AND j.$column != '[]'"
                        : "WHERE j.$column != '[]'";
    $st = db()->prepare("SELECT j.$column AS vals FROM job_postings j {$s['join']} $where");
    $st->execute($s['binds']);

    $counts = [];
    foreach ($st->fetchAll() as $row) {
        foreach ((array)(json_decode($row['vals'] ?: '[]', true) ?: []) as $v) {
            if ($v === '' || $v === null) continue;
            $counts[(string)$v] = ($counts[(string)$v] ?? 0) + 1;
        }
    }
    arsort($counts);
    $out = [];
    foreach (array_slice($counts, 0, $limit, true) as $val => $cnt) {
        $out[] = ['val' => $val, 'label' => $label($val), 'cnt' => $cnt];
    }
    return $out;
}

/**
 * Facet options for a role-scoped JSON-array column (`posting_roles.<col>`).
 *
 * Same scope rules as v3_facet() — an OR group drops its own selection, an AND
 * group counts the residual with it applied — but evaluated per role: the rows
 * are (announcement, role) pairs for roles that satisfy the other role groups
 * (and, for an AND group, this group's own selection), values are unioned per
 * announcement, and each value counts the DISTINCT announcements carrying it. The
 * count next to a value is therefore the size of the list that adding it would
 * give, whichever of an announcement's roles it comes from.
 */
function role_array_facet(string $role_col, string $excl_key, callable $label, int $limit = 30): array {
    $s = facet_scope(facet_mode($excl_key) === 'all' ? '' : $excl_key);
    $st = role_scope_query($s, "j.id AS id, r.$role_col AS vals",
                           ["r.$role_col IS NOT NULL", "r.$role_col != '[]'"]);
    $postings = [];   // value => [announcement id => true]
    foreach ($st->fetchAll() as $row) {
        foreach ((array)(json_decode($row['vals'] ?: '[]', true) ?: []) as $v) {
            if ($v === '' || $v === null) continue;
            $postings[(string)$v][$row['id']] = true;
        }
    }
    $counts = array_map('count', $postings);
    arsort($counts);
    $out = [];
    foreach (array_slice($counts, 0, $limit, true) as $val => $cnt) {
        $out[] = ['val' => (string)$val, 'label' => $label((string)$val), 'cnt' => $cnt];
    }
    return $out;
}

// Anomalii is the same JSON-array shape, and until 2026-09-09 it was the one
// group rendered with no counts at all — every flag looked clickable, including
// the ones that would empty the list. Counted like the rest, the values that
// would zero out simply stop being offered.
timing_mark('f-status');

// Field of study, skills and credentials are role facts (DATA-04): counted per
// role, announcements counted once. Language, domain and exam stage describe the
// competition and stay posting-level.
$isced_options  = role_array_facet('isced_fields', 'isced',      'isced_label');
$skill_options  = role_array_facet('skills',       'skill',      fn($v) => $v, 40);
$lang_options   = v3_facet('v3_languages',      'lang',       'language_token_label');
$cred_options   = role_array_facet('credentials',  'credential', 'credential_kind_label');
$domain_options = v3_facet('v3_policy_domains', 'domain',     'policy_domain_label');
timing_mark('f-json');

// Remote count
{
    $s = facet_scope('remote');
    $remote_count = scope_count($s, ["j.inf_remote_eligible = 1"]);
}

timing_mark('f-remote');
// Experience buckets. A role fact (DATA-04): each bucket counts the announcements
// that have a role in it which also satisfies the other role groups, built from
// the same exp_bucket_condition() the filter uses.
$exp_options = [];
{
    $s = facet_scope('exp_levels');
    foreach (EXP_BUCKETS as $bk) {
        [$cond, $cbinds] = exp_bucket_condition($bk);
        $cnt = (int)role_scope_query($s, 'COUNT(DISTINCT j.id)', [$cond], $cbinds)->fetchColumn();
        if ($cnt) $exp_options[] = ['val' => $bk['key'], 'cnt' => $cnt, 'label' => $bk['label']];
    }
}

timing_mark('f-exp');
// Salary buckets, over the estimate from the draft salary grid.
//
// The >= 100 gate that used to guard this never opened: it counted
// `inf_salary_min`, the announced salary, and only 44 of 9,757 postings state
// one. The gate stays — the facet should still hide itself before
// `estimate-salaries.py` has ever run — but it now counts what the filter
// actually matches on.
$salary_options = [];
{
    $sal_total = (int)db()->query(
        "SELECT COUNT(*) FROM job_postings j WHERE " . SALARY_EXPR . " IS NOT NULL"
    )->fetchColumn();
    if ($sal_total >= 100) {
        $s = facet_scope('salary_bucket');
        foreach (SALARY_BUCKETS as $bk) {
            // CAST(? AS REAL): see the note in build_filters() — a COALESCE
            // expression has no column affinity and PDO binds text.
            $extra_w = [SALARY_EXPR . " IS NOT NULL", SALARY_EXPR . " >= CAST(? AS REAL)"];
            $extra_b = [$bk['min']];
            if ($bk['max'] !== null) {
                $extra_w[] = SALARY_EXPR . " < CAST(? AS REAL)";
                $extra_b[] = $bk['max'];
            }
            $cnt = scope_count($s, $extra_w, $extra_b);
            if ($cnt) $salary_options[] = ['val' => $bk['key'], 'cnt' => $cnt, 'label' => $bk['label']];
        }
    }
}

// Occupation, funding source and employer sector: plain columns, counted like
// any other single-value facet. Occupation is the one that changes browsing
// most — before the title dictionary, `Îngrijitor`, `ÎNGRIJITOR` and
// `îngrijitor` were three separate values of the free-text title.
timing_mark('f-salary');
$occupation_options = get_facet('occ_canonical',      'occupation', 40);
// Contract terms: extracted by v3 all along, exported and browsable from
// 2026-09-13. `duration` is finer than the scraped `job_type` (Permanent /
// Temporar) — it distinguishes a fixed-term post from one that runs only as
// long as its project.
$duration_options = get_facet('v3_contract_duration', 'duration');
$schedule_options = get_facet('v3_schedule',          'schedule');
foreach ($duration_options as &$o) { $o['label'] = CONTRACT_DURATION_LABELS[$o['val']] ?? $o['val']; }
unset($o);
foreach ($schedule_options as &$o) { $o['label'] = SCHEDULE_LABELS[$o['val']] ?? $o['val']; }
unset($o);
$shift_count = scope_count(facet_scope('shift'), ["j.v3_shift_work = 1"]);
// BETA-V5 facts: zero (and so hidden) until v5 rows exist in the export.
$recr_options = get_facet('v5_recruitment_kind', 'recruitments');
foreach ($recr_options as &$o) { $o['label'] = RECRUITMENT_KIND_SHORT[$o['val']] ?? $o['val']; }
unset($o);
$parttime_count     = scope_count(facet_scope('parttime'), ["j.v5_fte IS NOT NULL AND j.v5_fte < 1"]);
$temp_vacant_count  = scope_count(facet_scope('temp_vacant'), ["j.v5_post_status = 'temporar_vacant'"]);
$remote_apply_count = scope_count(facet_scope('remote_apply'),
    ["(j.v5_channels LIKE '%\"email\"%' OR j.v5_channels LIKE '%\"posta\"%' OR j.v5_channels LIKE '%\"online\"%')"]);
$funding_options    = get_facet('v4_funding_source',  'funding');
$sector_options     = get_facet('v4_employer_sector', 'sector');
// 261016: "nespecificat" narrows nothing a reader would ask for; not offered
// (a bookmarked ?funding[]=nespecificat still filters and keeps its chip).
$funding_options = array_values(array_filter($funding_options, fn($o) => $o['val'] !== 'nespecificat'));
foreach ($funding_options as &$o) { $o['label'] = FUNDING_SOURCE_LABELS[$o['val']] ?? $o['val']; }
unset($o);
foreach ($sector_options as &$o) { $o['label'] = EMPLOYER_SECTOR_LABELS[$o['val']] ?? $o['val']; }
unset($o);

timing_mark('f-occ');
// ---- Empty state: which single filter is over-narrowing? ----
// Only computed on the zero-results branch, so the extra COUNT per chip is free
// in the common case.
$relaxations = [];
if ($total_count === 0 && $active_chips) {
    foreach ($active_chips as $chip) {
        $params = $_GET;
        $raw = $params[$chip['param']] ?? null;
        if (is_array($raw)) {
            $rest = array_values(array_filter($raw, fn($v) => (string)$v !== $chip['value']));
            if ($rest) { $params[$chip['param']] = $rest; } else { unset($params[$chip['param']]); }
        } else {
            unset($params[$chip['param']]);
        }

        $rf = build_filters($params);
        $rw = $rf['where'];
        $rb = $rf['binds'];
        $rj = '';
        if ($rf['fts'] && ($m = fts_query(trim($params['q'] ?? ''))) !== '') {
            $rj   = "JOIN job_postings_fts fts ON fts.rowid = j.id";
            $rw[] = "job_postings_fts MATCH ?";
            $rb[] = $m;
        }
        $rwhere = $rw ? 'WHERE ' . implode(' AND ', $rw) : '';
        $rst = db()->prepare("SELECT COUNT(*) FROM job_postings j $rj $rwhere");
        $rst->execute($rb);
        $gain = (int)$rst->fetchColumn();
        if ($gain > 0) $relaxations[] = $chip + ['gain' => $gain];
    }
    usort($relaxations, fn($a, $b) => $b['gain'] <=> $a['gain']);
    $relaxations = array_slice($relaxations, 0, 3);
}

timing_mark('relax');
// ---- Render ----
if (!$is_htmx) {
    $page_title       = $q !== '' ? "Căutare: $q" : 'Posturi în sectorul public';
    $meta_description = 'Explorator independent pentru anunțurile de angajare din sectorul public românesc: '
                      . 'căutare full-text, filtre pe județ, domeniu, grad și termen limită.';
    $canonical_path   = '/' . (($cq = http_build_query(clean_query($_GET))) ? '?' . $cq : '');
    require __DIR__ . '/../inc/header.php';
}

if (!$is_htmx): ?>
<div class="w-full px-4 sm:px-6 py-6">

  <?php /* The visible title and the "N anunțuri indexate · sursă" line were removed
     2026-09-08: both restated the masthead, and they pushed the filters — the
     actual tool — below the fold. The heading stays in the document for SEO and
     for screen readers, which is the part that was doing real work. The corpus
     count and source attribution live in the footer. */ ?>
  <h1 class="sr-only">Posturi în sectorul public</h1>

  <form id="filter-form"
        hx-get="/"
        hx-target="#results"
        hx-push-url="true"
        hx-trigger="change, input delay:400ms from:[name=q], submit"
        hx-indicator="#results"
        onsubmit="return false;">

    <?php /* Scope params with no sidebar control. Without these an HTMX filter
       change would serialise the form without them and silently widen the list
       (and the feed links, which follow the address bar). Removed by their chip. */ ?>
    <?php foreach (['employer', 'employer_id'] as $scope_param):
        if (trim((string)($_GET[$scope_param] ?? '')) === '') continue; ?>
      <input type="hidden" name="<?= e($scope_param) ?>" value="<?= e((string)$_GET[$scope_param]) ?>">
    <?php endforeach; ?>

    <!-- Backdrop for the mobile drawer. Outside the columns: it is fixed, and
         keeping it out of the sticky wrapper avoids any stacking-context doubt. -->
    <div id="facet-backdrop" hidden
         class="fixed inset-0 z-30 bg-ink/40 lg:hidden"></div>

    <div class="pg-layout lg:flex lg:gap-6 lg:items-start">

      <!-- LEFT COLUMN — search above the facets.
           The filter is the main exploration tool, so on desktop it owns a
           column and the search sits at the top of it rather than spanning the
           page. On mobile there is no column: the search stays inline at the
           top and the facets become the slide-over drawer, which is why the
           search input lives here rather than inside <aside> — one input, one
           name, reachable at every width. -->
      <div class="pg-side lg:sticky lg:top-4 lg:flex lg:max-h-[calc(100vh-2rem)] lg:w-64 lg:shrink-0 lg:flex-col">

    <div class="pg-search flex gap-2 mb-3">
      <div class="relative flex-1 min-w-0">
        <label for="q" class="sr-only">Caută posturi după titlu, angajator sau conținut</label>
        <input type="search" id="q" name="q" value="<?= e($q) ?>"
               autocomplete="off" enterkeyhint="search"
               placeholder="Caută posturi…"
               class="w-full bg-surface border border-line-strong rounded-md px-3 py-2.5 pr-9 text-base sm:text-sm text-ink placeholder-ink-muted focus:outline-none focus:border-gov focus:ring-1 focus:ring-focus transition-colors">
        <?php if ($q): ?>
          <button type="button"
                  onclick="var i=document.getElementById('q'); i.value=''; i.focus(); htmx.trigger('#filter-form','change')"
                  aria-label="Șterge textul căutat"
                  class="absolute right-2 top-1/2 -translate-y-1/2 text-ink-muted hover:text-ink text-lg leading-none px-1">×</button>
        <?php else: ?>
          <span aria-hidden="true" class="absolute right-3 top-1/2 -translate-y-1/2 text-ink-muted text-sm">⌕</span>
        <?php endif; ?>
      </div>

      <!-- Drawer trigger (small screens only) -->
      <button type="button" id="filter-toggle"
              aria-controls="facet-panel" aria-expanded="false"
              class="lg:hidden shrink-0 inline-flex items-center gap-1.5 rounded-md border border-line-strong bg-surface px-3 py-2.5 text-sm font-medium text-gov focus:outline-none focus:ring-2 focus:ring-focus">
        <span aria-hidden="true">☰</span> Filtre
        <span id="filter-count"
              class="<?= $active_chips ? '' : 'hidden ' ?>inline-flex min-w-[1.25rem] justify-center rounded-full bg-gov px-1.5 py-0.5 text-xs font-mono text-on-gov"><?= count($active_chips) ?></span>
      </button>
    </div>

      <!-- FACETS — sticky column on lg, slide-over drawer below it -->
      <aside id="facet-panel"
             aria-label="Filtre"
             class="fixed inset-y-0 right-0 z-40 flex w-[86vw] max-w-sm translate-x-full flex-col overflow-y-auto overscroll-contain border-l border-line bg-page px-4 pb-4 transition-transform duration-200 ease-out
                    lg:static lg:z-auto lg:w-auto lg:max-w-none lg:min-h-0 lg:flex-1 lg:translate-x-0 lg:overscroll-auto lg:border-l-0 lg:bg-transparent lg:px-0 lg:transition-none">

        <!-- Drawer header (mobile only) -->
        <div class="sticky top-0 z-10 -mx-4 mb-3 flex items-center justify-between border-b border-line bg-page px-4 py-3 lg:hidden">
          <span class="font-display text-lg font-semibold italic text-ink">Filtre</span>
          <button type="button" id="filter-close" aria-label="Închide filtrele"
                  class="rounded p-1 text-2xl leading-none text-ink-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-focus">&times;</button>
        </div>

        <?php require __DIR__ . '/../partials/facets.php'; ?>

        <?php /* The export links used to sit here, under the facets. They are
           what you reach for once you are happy with a result set, not while
           you are narrowing one — and in the mobile drawer they were behind a
           button labelled "Filtre", which is the last place anyone would look
           for a feed. They now follow the results; see below #results. */ ?>

        <!-- Drawer footer (mobile only) -->
        <div class="sticky bottom-0 -mx-4 mt-4 border-t border-line bg-page px-4 py-3 lg:hidden">
          <button type="button" id="drawer-apply"
                  class="w-full rounded-md bg-gov px-4 py-2.5 text-sm font-medium text-on-gov focus:outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2">
            Arată <span id="drawer-count"><?= $total_count ?></span> anunțuri
          </button>
        </div>
      </aside>

      </div><!-- /left column -->

      <!-- RIGHT COLUMN — shortcuts, controls, results -->
      <div class="pg-main min-w-0 lg:flex-1">

    <?php if ($shortcuts): ?>
    <?php /* In the grid and table views the script moves this block into the filter
       bar, where it is the bar's last row, on a line of its own. */ ?>
    <div id="shortcuts" class="pg-shortcuts mb-4">
      <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1.5 text-sm">
        <span class="text-xs uppercase tracking-widest text-ink-muted">Începe cu</span>
        <?php foreach ($shortcuts as $sc): ?>
        <a href="<?= e($sc['href']) ?>" class="whitespace-nowrap text-gov hover:underline"><?= e($sc['label']) ?>
          <span class="font-mono text-xs text-ink-muted"><?= number_format($sc['count'], 0, ',', '.') ?></span></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- STATUS + view + sort. 261016: the status used to be a row of four buttons
         above the results; it is now a select beside the sort — the default
         (Active) is what nearly everyone wants, and the row was noise on the landing. -->
    <div class="status-block mb-4">
        <?php /* View switch and sort. In the list view this row sits above the results;
           in the grid and table views (lg+) the script moves it to the end of
           the filter bar, so search, filters, view and sort read as one row. It stays
           inside the form either way, so `sort` is serialised as before. */ ?>
        <div id="result-options" role="group" aria-label="Opțiuni rezultate" class="pg-options mb-3 mt-3 flex flex-wrap items-center gap-2">
          <?php /* Three views, lg and up: list (sidebar + rows), grid (filter bar +
             cards), table (filter bar + one line per posting). Shown by the script
             below (it does nothing without JavaScript); the choice is kept per
             browser in localStorage `posturi.layout` ('list' | 'compact' | 'table'). */ ?>
          <div id="layout-switch" role="group" aria-label="Vizualizare" hidden
               class="pg-views hidden h-10 overflow-hidden rounded-md border border-line-strong bg-surface lg:inline-flex">
            <?php foreach ([
                'list'    => ['Listă', '<path d="M4 6h16M4 12h16M4 18h16"/>'],
                'compact' => ['Grilă', '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>'],
                'table'   => ['Tabel', '<rect x="3" y="4" width="18" height="16"/><path d="M3 10h18M3 15h18M9 10v10"/>'],
            ] as $mode => [$mlabel, $micon]): ?>
            <button type="button" data-layout-mode="<?= $mode ?>" aria-pressed="<?= $mode === 'list' ? 'true' : 'false' ?>"
                    class="inline-flex items-center gap-1.5 border-l border-line px-3 text-sm text-ink-muted transition-colors first:border-l-0 hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus aria-pressed:bg-gov-light aria-pressed:font-medium aria-pressed:text-gov">
              <svg class="h-4 w-4 fill-none stroke-current" viewBox="0 0 24 24" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true" focusable="false"><?= $micon ?></svg>
              <?= $mlabel ?>
            </button>
            <?php endforeach; ?>
          </div>
          <div class="pg-status inline-flex h-10 items-center gap-2 rounded-md border border-line-strong bg-surface pl-3 focus-within:border-gov focus-within:ring-1 focus-within:ring-focus">
            <label for="status" class="text-xs uppercase tracking-widest text-ink-muted">Stare</label>
            <select id="status" name="status" title="<?= e(STATUS_HELP) ?>" class="h-full rounded-md border-0 bg-transparent py-0 pl-1 pr-2 text-sm text-ink focus:outline-none">
              <?php if ($status_tab === ''): ?><option value="all" selected>Toate</option><?php endif; ?>
              <?php foreach (STATUS_LABELS as $sval => $slabel): ?>
              <option value="<?= e($sval) ?>"<?= selected_if($status_tab === $sval) ?>><?= e($slabel) ?> (<?= number_format((int)$status_counts[$sval], 0, ',', '.') ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="pg-sort inline-flex h-10 items-center gap-2 rounded-md border border-line-strong bg-surface pl-3 focus-within:border-gov focus-within:ring-1 focus-within:ring-focus">
            <label for="sort" class="text-xs uppercase tracking-widest text-ink-muted">Sortare</label>
            <select id="sort" name="sort" class="h-full rounded-md border-0 bg-transparent py-0 pl-1 pr-2 text-sm text-ink focus:outline-none">
              <option value=""<?= selected_if(!$sort) ?>><?= $q ? 'Relevanță' : 'Cele mai noi' ?></option>
              <option value="deadline"<?= selected_if($sort === 'deadline') ?>>Termen limită</option>
              <option value="employer"<?= selected_if($sort === 'employer') ?>>Angajator A–Z</option>
              <option value="salary"<?= selected_if($sort === 'salary') ?>>Salariu (mare → mic)</option>
            </select>
          </div>
    </div>
    <?php /* "Active" is not "confirmed open" (UX-01A). Shown once a status other than
       the default is picked (CSS :has on the select), with the 7-day caveat. */ ?>
    <p id="status-help" class="status-help mb-3 text-xs leading-snug text-ink-muted">
      <?= e(STATUS_HELP) ?>
      <span class="status-soon-note"><?= e(STATUS_HELP_SOON) ?></span>
    </p>
    </div>

        <p id="results-status" role="status" aria-live="polite" aria-atomic="true" class="sr-only"><?= $total_count ?> <?= $total_count === 1 ? 'anunț găsit' : 'anunțuri găsite' ?></p>
        <div id="results">
<?php endif; // !$is_htmx ?>

<?php require __DIR__ . '/../partials/result_list.php'; ?>

<?php
// Live facet counts: the sidebar goes back with every HTMX response as an
// out-of-band swap. The counts were computed above either way — this is the
// only thing that puts the fresh ones on screen. htmx lifts an `hx-swap-oob`
// element out of the response before the main swap, so it never lands in
// #results.
//
// Paging is the exception. The filter form carries no `page` field, so a
// filter change always arrives without one and a `page` in the query string
// means a pagination link — same filters, same counts, no reason to re-ship
// ~120 KB of sidebar (10 KB over mod_deflate, but still the whole thing
// re-rendered and re-swapped) to redraw identical numbers.
if ($is_htmx && !isset($_GET['page'])) require __DIR__ . '/../partials/facets.php';
?>

<?php if (!$is_htmx): ?>
        </div>

    <?php /* UX-04-FEEDS: the one subscription block. It sits below results
       but outside #results, so HTMX never rebuilds it; syncFeedLinks()
       keeps every href in step with the address bar. A native <details>, so it
       opens and closes with no JavaScript, and the export line below links to
       the content id inside it (browsers expand a closed <details> for a
       fragment that targets its content). Hrefs are server-rendered from the same filters (feed_url()), so
       the links are right without JavaScript too. Saved/hidden announcements
       are browser-local and are never part of these URLs. */
       $ics_abs = site_origin() . feed_url('posturi.ics'); ?>
    <details id="urmareste" class="mt-3 rounded-md border border-line bg-surface">
      <summary class="cursor-pointer select-none px-3 py-2 text-sm font-medium text-gov hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus">Urmărește această căutare</summary>
      <div id="urmareste-linkuri" class="border-t border-line px-3 py-3 text-xs text-ink-muted">
        <ul class="space-y-1">
          <?php foreach ([['posturi.rss', 'RSS'], ['posturi.atom', 'Atom'], ['posturi.ics', 'Calendar iCal']] as [$feed_file, $feed_label]): ?>
          <li class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <a data-feed="<?= e($feed_file) ?>" href="<?= e(feed_url($feed_file)) ?>" class="py-1 text-sm text-gov hover:underline"><?= e($feed_label) ?></a>
            <button type="button" data-copy="<?= e($feed_file) ?>" hidden
                    class="rounded border border-line-strong bg-surface px-2 py-1 text-xs text-ink hover:border-gov hover:text-gov focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus">Copiază linkul <?= e($feed_label) ?></button>
          </li>
          <?php endforeach; ?>
        </ul>
        <p id="feed-copy-status" role="status" aria-live="polite" class="mt-1 min-h-4 text-ink"></p>
        <label id="feed-copy-fallback" hidden class="mt-1 block">
          <span class="block">Copiază manual adresa:</span>
          <input id="feed-copy-url" type="text" readonly
                 class="mt-1 w-full rounded border border-line-strong bg-sunken px-2 py-1 font-mono text-xs text-ink focus:border-gov focus:outline-none focus:ring-1 focus:ring-focus">
        </label>

        <?php /* Calendar subscription. `posturi.ics` is a live query, not a
           download — the browser only saves it because of the .ics filename.
           `?title=` names the calendar in clients that show X-WR-CALNAME (Apple
           Calendar, Outlook) and won't let you rename a subscription; it goes
           into the calendar links only, never RSS/Atom/JSON. */ ?>
        <div class="mt-3 flex flex-wrap items-baseline gap-x-4 gap-y-2 border-t border-line pt-3">
          <span class="font-semibold uppercase tracking-widest">Calendar</span>
          <a id="gcal-link" data-gcal
             href="https://calendar.google.com/calendar/u/0/r/settings/addbyurl?url=<?= e(rawurlencode($ics_abs)) ?>"
             target="_blank" rel="noopener"
             class="py-1 text-gov hover:underline">Adaugă în Google Calendar</a>
          <a id="webcal-link" data-webcal
             href="<?= e(preg_replace('#^https?://#', 'webcal://', $ics_abs)) ?>"
             class="py-1 text-gov hover:underline">Abonare (Apple / Outlook)</a>
          <label class="inline-flex items-baseline gap-1.5">
            <span>Titlu calendar</span>
            <input id="feed-title" type="text" autocomplete="off"
                   value="<?= e($_GET['title'] ?? '') ?>" placeholder="posturi.gov2.ro"
                   class="w-44 rounded border border-line-strong bg-surface px-2 py-1 text-xs text-ink focus:border-gov focus:outline-none focus:ring-1 focus:ring-focus">
          </label>
        </div>

        <ul id="feed-limits" class="mt-3 list-disc space-y-1 pl-4 leading-snug">
          <li>RSS/Atom: cele mai noi <?= FEED_ITEM_LIMIT ?> de anunțuri care corespund filtrelor. Calendar: primele <?= FEED_CAL_LIMIT ?> cu dată disponibilă, în ordinea termenului.</li>
          <li>Cât de des se reîmprospătează o abonare este hotărât de cititorul de fluxuri sau de aplicația de calendar, nu de acest site. Un fișier ICS descărcat sau importat este doar o copie de moment.</li>
          <li>Fluxurile nu sunt o arhivă completă: într-o căutare cu multe rezultate noi pot lipsi anunțuri apărute între două verificări. Nu trimit notificări imediate.</li>
        </ul>
      </div>
    </details>

        <?php /* Export, at the end of the list rather than in the sidebar.
           Deliberately OUTSIDE #results: htmx swaps that element on every
           filter change and on every page of pagination, so a block inside it
           would be rebuilt constantly for something that changes only when the
           filters do. Staying put also keeps the `[data-feed]` NodeList that
           syncFeedLinks() collects once, at script init, valid for the life of
           the page.

           `feed_url()` already carries the active filters (minus page/sort),
           so the hrefs are right on a full load; syncFeedLinks() is what keeps
           them right afterwards. Saying so in the line of text matters — an
           export that silently means something different from what is on
           screen is worse than no export. */ ?>
        <div class="mt-8 border-t border-line pt-4">
          <div class="flex flex-wrap items-baseline gap-x-4 gap-y-2 text-xs text-ink-muted">
            <span class="font-semibold uppercase tracking-widest">Export</span>
            <a data-feed="posturi.json" href="<?= e(feed_url('posturi.json')) ?>" class="py-1 text-gov hover:underline">JSON API</a>
            <a href="#urmareste-linkuri" data-open-subscribe class="py-1 text-gov hover:underline">Urmărește această căutare (RSS, Atom, calendar)</a>
            <?php /* Phrased so it holds at every moment, filtered or not. A
               server-rendered "cu filtrele active" / "toate anunțurile" pair
               would be stale the instant someone ticks a facet, since this
               block deliberately sits outside the swapped region. */ ?>
            <span class="basis-full text-ink-faint sm:basis-auto">urmează filtrele active</span>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
(function () {
  var panel    = document.getElementById('facet-panel');
  var backdrop = document.getElementById('facet-backdrop');
  var toggle   = document.getElementById('filter-toggle');
  var form     = document.getElementById('filter-form');
  var lastFocus = null;

  function drawerOpen() { return toggle.getAttribute('aria-expanded') === 'true'; }

  function setDrawer(open) {
    panel.classList.toggle('translate-x-full', !open);
    backdrop.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.documentElement.classList.toggle('overflow-hidden', open);
    if (open) {
      lastFocus = document.activeElement;
      var close = document.getElementById('filter-close');
      if (close) close.focus();
    } else if (lastFocus) {
      lastFocus.focus();
      lastFocus = null;
    }
  }

  toggle.addEventListener('click', function () { setDrawer(!drawerOpen()); });
  backdrop.addEventListener('click', function () { setDrawer(false); });
  document.getElementById('filter-close').addEventListener('click', function () { setDrawer(false); });
  document.getElementById('drawer-apply').addEventListener('click', function () { setDrawer(false); });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && drawerOpen()) setDrawer(false);
  });
  // Crossing the lg breakpoint with the drawer open would leave the sticky
  // column translated off-screen.
  window.matchMedia('(min-width: 1024px)').addEventListener('change', function (ev) {
    if (ev.matches && drawerOpen()) setDrawer(false);
  });

  // ---- Facet open/closed state, per browser ----
  var STORE = 'posturi.facets';
  var state = {};
  try { state = JSON.parse(localStorage.getItem(STORE) || '{}') || {}; } catch (e) { state = {}; }

  var drawerQuery = window.matchMedia('(min-width: 1024px)');
  function applyFacetState() {
    document.querySelectorAll('.facet-group').forEach(function (el) {
      var key = el.dataset.facet;
      // Desktop starts with every group collapsed; the drawer (below lg)
      // opens the server's default-open groups unless the reader chose.
      if (!el.open && state[key] === undefined && el.hasAttribute('data-default-open') && !drawerQuery.matches) el.open = true;
      // A group — or a parent of a group — holding an active selection is
      // rendered open (server sets data-selected) and stays open. Parent
      // groups introduced by UX-01A have keys no old preference can mention.
      if (!el.open && state[key] === true) el.open = true;
      else if (el.open && state[key] === false && !el.dataset.selected) el.open = false;
    });
  }
  applyFacetState();

  // Only a toggle the *reader* caused may be recorded, and the reader's act is
  // a click or keypress on a <summary>. The `toggle` event cannot tell: it also
  // fires for every `<details open>` the server renders — on page load and on
  // each sidebar swap — which used to write state[key] = true for every group
  // that merely happened to be open (default-open ones, and any group a
  // bookmarked selection had opened). Recording from the summary click instead
  // keeps those out of the stored preferences. Space/Enter on a focused
  // summary dispatch a click too. The state is read one task later, after the
  // browser has flipped `open`.
  document.addEventListener('click', function (ev) {
    var sum = ev.target.closest && ev.target.closest('summary');
    var el = sum && sum.parentNode;
    if (!el || !el.classList || !el.classList.contains('facet-group')) return;
    setTimeout(function () {
      state[el.dataset.facet] = el.open;
      try { localStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* private mode */ }
    }, 0);
  });

  // ---- Grid and table views (lg and up): filter bar + cards / table rows ----
  // The layout itself is CSS over this same markup (html[data-layout="compact"]
  // for the grid, "table" for the table; set before paint by inc/header.php), so
  // swaps and history need nothing from here. This owns the view switch, the
  // move of the view/sort row into the filter bar, and the dropdown behaviour of
  // the top-level groups. In the bar a group's panel is driven by
  // [data-pg-open], NOT by the native `open` attribute: the stored list-mode
  // preferences (STORE) are neither read nor written, and every panel starts closed.
  var LAYOUT_KEY = 'posturi.layout';
  var MODES = ['list', 'compact', 'table'];
  var root = document.documentElement;
  var layoutSwitch = document.getElementById('layout-switch');
  var bar = document.querySelector('.pg-side');
  // Blocks that join the filter bar in the bar views, in this order, and go back
  // to their place (a comment left there) in the list view.
  var movers = ['result-options', 'shortcuts'].map(function (id) {
    var el = document.getElementById(id);
    if (!el) return null;
    var slot = document.createComment(id);
    el.parentNode.insertBefore(slot, el);
    return { el: el, slot: slot };
  }).filter(Boolean);
  var lgQuery = window.matchMedia('(min-width: 1024px)');
  var openKey = null;

  /** True while the filters are a bar above the results (grid or table view, lg+). */
  function barOn() { return !!root.dataset.layout && lgQuery.matches; }
  function topGroups() { return Array.prototype.slice.call(document.querySelectorAll('#facet-list > .facet-group')); }
  function topSummary(el) { return el.querySelector(':scope > summary'); }

  /** View/sort and the shortcuts: end of the filter bar in the bar views, their own place otherwise. */
  function placeOptions() {
    if (!bar) return;
    var focused = document.activeElement;
    movers.forEach(function (m) {
      if (barOn()) { if (m.el.parentNode !== bar) bar.appendChild(m.el); }
      else if (m.el.previousSibling !== m.slot) m.slot.parentNode.insertBefore(m.el, m.slot.nextSibling);
    });
    if (focused && focused !== document.body && document.activeElement !== focused) focused.focus();
  }

  function placePanel(el) {
    var body = el.querySelector(':scope > .facet-body');
    if (!body) return;
    el.classList.remove('pg-pop-right');
    var r = body.getBoundingClientRect();
    if (r.right > document.documentElement.clientWidth - 8) el.classList.add('pg-pop-right');
  }

  /** Sync [data-pg-open] and aria-expanded to openKey (no-op outside the bar). */
  function syncPanels() {
    topGroups().forEach(function (el) {
      var on = barOn() && openKey !== null && el.dataset.facet === openKey;
      el.toggleAttribute('data-pg-open', on);
      // A closed <details> does not render its content at all, so an opened
      // panel needs the native attribute too. Only panels this code forced open
      // are closed again by it, and nothing here records into STORE (that is
      // written by the summary click handler, which a bar click never reaches).
      if (on && !el.open) { el.open = true; el.setAttribute('data-pg-forced', ''); }
      else if (!on && el.hasAttribute('data-pg-forced')) { el.open = false; el.removeAttribute('data-pg-forced'); }
      var sum = topSummary(el);
      if (sum) {
        if (barOn()) sum.setAttribute('aria-expanded', on ? 'true' : 'false');
        else sum.removeAttribute('aria-expanded');
      }
      if (on) placePanel(el);
    });
  }

  function closePanels(returnFocus) {
    var key = openKey;
    openKey = null;
    syncPanels();
    if (returnFocus && key !== null) {
      var el = topGroups().filter(function (g) { return g.dataset.facet === key; })[0];
      var sum = el && topSummary(el);
      if (sum) sum.focus();
    }
  }

  function setLayout(mode, persist) {
    if (MODES.indexOf(mode) < 0) mode = 'list';
    if (mode === 'list') delete root.dataset.layout; else root.dataset.layout = mode;
    if (persist) { try { localStorage.setItem(LAYOUT_KEY, mode); } catch (e) { /* private mode */ } }
    if (layoutSwitch) {
      layoutSwitch.querySelectorAll('[data-layout-mode]').forEach(function (b) {
        b.setAttribute('aria-pressed', b.dataset.layoutMode === mode ? 'true' : 'false');
      });
    }
    placeOptions();
    openKey = null;
    syncPanels();
  }

  if (layoutSwitch) {
    layoutSwitch.hidden = false;
    layoutSwitch.addEventListener('click', function (ev) {
      var b = ev.target.closest && ev.target.closest('[data-layout-mode]');
      if (b) setLayout(b.dataset.layoutMode, true);
    });
  }
  setLayout(root.dataset.layout || 'list', false);
  lgQuery.addEventListener('change', function () { openKey = null; placeOptions(); syncPanels(); });

  // Capture phase and stopPropagation: the persisted-state recorder below must
  // not see a click that only toggled a bar panel.
  document.addEventListener('click', function (ev) {
    if (!barOn()) return;
    var t = ev.target;
    var sum = t.closest && t.closest('#facet-list > .facet-group > summary');
    if (sum) {
      ev.preventDefault();
      ev.stopPropagation();
      var key = sum.parentNode.dataset.facet;
      openKey = openKey === key ? null : key;
      syncPanels();
      return;
    }
    if (openKey !== null && !(t.closest && t.closest('#facet-list > .facet-group[data-pg-open]'))) closePanels(false);
  }, true);

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && barOn() && openKey !== null) { ev.preventDefault(); closePanels(true); }
  });

  // An HTMX swap replaces #facet-list: put the open panel back.
  document.body.addEventListener('htmx:afterSwap', syncPanels);
  document.body.addEventListener('htmx:afterSettle', syncPanels);

  // ---- Live facet counts ----
  // Every HTMX response carries a fresh #facet-list as an out-of-band swap, so
  // the counts next to each checkbox narrow with the results instead of going
  // stale until the next full page load. Replacing the markup costs three
  // pieces of client state, put back here: which control had focus (the one
  // just clicked — losing it breaks keyboard multi-select), how far a long
  // group like Județ was scrolled, and the panel's own scroll offset.
  // <details> open/closed is restored by applyFacetState() above.
  var pending = null;

  // 261016: no empty parameters in requests or the address bar. A key is dropped
  // only when every value it carries is empty (FormData.delete removes them all);
  // `status=active` is the default and says nothing. Mirrors clean_query().
  document.body.addEventListener('htmx:configRequest', function (ev) {
    var fd = ev.detail.formData;
    if (!fd || ev.detail.verb !== 'get') return;
    var keys = {};
    fd.forEach(function (v, k) {
      var empty = typeof v === 'string' && (v.trim() === '' || (k === 'status' && v === 'active'));
      keys[k] = (keys[k] === undefined ? true : keys[k]) && empty;
    });
    Object.keys(keys).forEach(function (k) { if (keys[k]) delete ev.detail.parameters[k]; });
  });

  document.body.addEventListener('htmx:beforeRequest', function () {
    var list = document.getElementById('facet-list');
    if (!list) return;
    var a = document.activeElement;
    pending = {
      focus:  (a && list.contains(a) && a.name) ? { name: a.name, value: a.value } : null,
      panel:  panel.scrollTop,
      groups: {}
    };
    list.querySelectorAll('.facet-group > .facet-body > .facet-options').forEach(function (d) {
      if (d.scrollTop) pending.groups[d.parentElement.dataset.facet] = d.scrollTop;
    });
  });

  document.body.addEventListener('htmx:afterSettle', function () {
    applyFacetState();

    var list = document.getElementById('facet-list');
    if (!list || !pending) return;

    panel.scrollTop = pending.panel;
    Object.keys(pending.groups).forEach(function (key) {
      var d = list.querySelector('.facet-group[data-facet="' + key + '"] > .facet-body > .facet-options');
      if (d) d.scrollTop = pending.groups[key];
    });

    if (pending.focus) {
      var sel = '[name="' + pending.focus.name.replace(/"/g, '\\"') + '"]';
      var match = null;
      list.querySelectorAll(sel).forEach(function (el) {
        if (el.value === pending.focus.value) match = el;
      });
      // preventScroll: refocusing must not yank a sticky column back to the top.
      if (match && document.activeElement !== match) match.focus({ preventScroll: true });
    }
    pending = null;
  });

  // ---- Active-filter chips ----
  // Uncheck the matching control and re-fire the form, so the sidebar and the
  // URL stay in agreement. Falls through to the chip's href without JS.
  document.addEventListener('click', function (ev) {
    var chip = ev.target.closest('[data-chip-param]');
    if (!chip || !form) return;

    var param  = chip.dataset.chipParam;
    var value  = chip.dataset.chipValue;
    var esc    = param.replace(/"/g, '\\"');
    var fields = form.querySelectorAll('[name="' + esc + '"], [name="' + esc + '[]"]');
    if (!fields.length) return;   // no control for this param — let the link navigate

    ev.preventDefault();
    fields.forEach(function (el) {
      if (el.type === 'hidden') {
        el.parentNode.removeChild(el);   // scope param: drop it from the form
      } else if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.value === value) el.checked = false;
      } else {
        el.value = '';
      }
    });
    if (param === 'status') {
      var def = form.querySelector('[name="status"][value="<?= DEFAULT_STATUS ?>"]');
      if (def) def.checked = true;
    }
    htmx.trigger(form, 'change');
  });

  // ---- Export links follow the active filters ----
  //
  // The export block sits below #results and outside it, so — unlike the result
  // list and the facet counts — it is never re-rendered on a filter change.
  // Without this the Atom/JSON/iCal hrefs keep the query string from the last
  // full page load, and subscribing after narrowing the filters hands you a
  // feed of whatever you were looking at before — silently, since the link
  // still works. `page` and `sort` are dropped for the same reason feed_url()
  // drops them server-side.
  //
  // Collected once: the block is outside every swap target, so this NodeList
  // stays valid for the life of the page. Moving the block inside #results
  // would break that, and would rebuild it on every page of pagination for
  // something that only changes when the filters do.
  var titleInput  = document.getElementById('feed-title');

  function syncFeedLinks() {
    // Looked up on every call: a history restore replaces the body, and a
    // NodeList collected at init would then point at detached links.
    var feedLinks   = document.querySelectorAll('[data-feed]');
    var gcalLink    = document.querySelector('[data-gcal]');
    var webcalLink  = document.querySelector('[data-webcal]');
    titleInput = document.getElementById('feed-title') || titleInput;
    var params = new URLSearchParams(location.search);
    params.delete('page');
    params.delete('sort');
    // The title box is the source of truth for ?title=, not the address bar —
    // a shared link may seed it, but from then on the input drives it.
    params.delete('title');
    // The filter form serialises an emptied search box as `q=`; an empty value
    // selects nothing, so keep it out of subscription URLs.
    Array.from(params.keys()).forEach(function (k) {
      if (params.getAll(k).every(function (v) { return v === ''; })) params.delete(k);
    });
    var qs = params.toString();
    feedLinks.forEach(function (a) {
      a.setAttribute('href', '/' + a.dataset.feed + (qs ? '?' + qs : ''));
    });

    // The iCal link and the two subscription links also carry the custom title.
    var ical = new URLSearchParams(qs);
    var title = titleInput && titleInput.value.trim();
    if (title) ical.set('title', title);
    var icalQs = ical.toString();
    var icsPath = '/posturi.ics' + (icalQs ? '?' + icalQs : '');
    var icalEl = document.querySelector('[data-feed="posturi.ics"]');
    if (icalEl) icalEl.setAttribute('href', icsPath);

    var icsAbs = location.origin + icsPath;
    if (gcalLink) {
      gcalLink.setAttribute('href',
        'https://calendar.google.com/calendar/u/0/r/settings/addbyurl?url=' + encodeURIComponent(icsAbs));
    }
    if (webcalLink) webcalLink.setAttribute('href', icsAbs.replace(/^https?:/, 'webcal:'));

    // Copy buttons need script (the clipboard), so they ship hidden. A copied
    // address is stale once the filters move: clear the notice and the manual
    // fallback rather than leave an old URL on screen.
    document.querySelectorAll('[data-copy]').forEach(function (b) { b.hidden = false; });
    var fb = document.getElementById('feed-copy-fallback');
    var st = document.getElementById('feed-copy-status');
    if (fb) fb.hidden = true;
    if (st) st.textContent = '';
  }

  // ---- Copy a subscription link ----
  // The URL is the one in the link's current href (so it carries the encoded
  // filters exactly as the reader will request them), made absolute. If the
  // clipboard is unavailable or refuses, the same URL is shown selected.
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest && ev.target.closest('[data-copy]');
    if (!btn) return;
    var link = document.querySelector('[data-feed="' + btn.dataset.copy + '"]');
    if (!link) return;
    var url = location.origin + link.getAttribute('href');
    var status = document.getElementById('feed-copy-status');
    var fb = document.getElementById('feed-copy-fallback');
    var input = document.getElementById('feed-copy-url');
    function showUrl() {
      if (!input || !fb) return;
      input.value = url;
      fb.hidden = false;
      input.focus();
      input.select();
      if (status) status.textContent = 'Nu am putut copia automat; adresa este selectată mai jos.';
    }
    try {
      if (!navigator.clipboard || !navigator.clipboard.writeText) { showUrl(); return; }
      navigator.clipboard.writeText(url).then(function () {
        if (fb) fb.hidden = true;
        if (status) status.textContent = 'Link copiat: ' + link.textContent.trim() + '.';
      }, showUrl);
    } catch (err) { showUrl(); }
  });

  // The export line links to the subscription block; a closed <details> does
  // not open itself for every browser's fragment navigation.
  function openSubscribe() {
    var d = document.getElementById('urmareste');
    if (d) d.open = true;
  }
  document.addEventListener('click', function (ev) {
    if (ev.target.closest && ev.target.closest('[data-open-subscribe]')) openSubscribe();
  });
  function hashOpens() { return location.hash === '#urmareste' || location.hash === '#urmareste-linkuri'; }
  window.addEventListener('hashchange', function () { if (hashOpens()) openSubscribe(); });
  if (hashOpens()) openSubscribe();

  // hx-push-url writes the address bar after the swap settles; both events fire
  // for a filter change, and back/forward only fires the popstate one.
  document.body.addEventListener('htmx:pushedIntoHistory', syncFeedLinks);
  window.addEventListener('popstate', syncFeedLinks);
  document.body.addEventListener('htmx:historyRestore', syncFeedLinks);
  if (titleInput) {
    titleInput.addEventListener('input', syncFeedLinks);
    // Enter in a lone text field would submit the filter form; nothing to submit.
    titleInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') ev.preventDefault();
    });
  }
  syncFeedLinks();  // server renders correct hrefs on load; this also builds the subscription links from location.origin

  // Scroll back to the top of the list after paging.
  document.body.addEventListener('htmx:afterSwap', function (ev) {
    if (ev.target.id === 'results' && window.scrollY > ev.target.offsetTop) {
      window.scrollTo({ top: ev.target.offsetTop - 80, behavior: 'smooth' });
    }
  });
})();
</script>
<?php
    require __DIR__ . '/../inc/footer.php';
endif;
timing_mark('render');
