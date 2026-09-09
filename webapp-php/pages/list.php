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
$sort         = $_GET['sort']          ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));

if (!isset(STATUS_LABELS[$status])) $status = DEFAULT_STATUS;

// ---- Check if HTMX partial request ----
$is_htmx = !empty($_SERVER['HTTP_HX_REQUEST']);

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
    $order = "ORDER BY j.expires_at ASC, j.published_at DESC";
} elseif ($sort === 'employer') {
    $order = "ORDER BY j.employer_name ASC, j.published_at DESC";
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

// ---- Active filters ----
$active_chips  = active_filter_chips();
$is_unfiltered = $page === 1 && !$active_chips;

// ---- Quick stats (unfiltered home page only) ----
$quick_stats = null;
if ($is_unfiltered) {
    $today = date('Y-m-d');
    // Județe and domenii counts were dropped 2026-09-08: as numbers they said
    // nothing a visitor could act on, and both are reachable as facets. The two
    // that stayed are corpus scale, and they now render as one line of text
    // rather than as four cards above the fold.
    $quick_stats = [
        'active'    => (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE expires_at >= '$today'")->fetchColumn(),
        'employers' => (int)db()->query("SELECT COUNT(DISTINCT employer_id) FROM job_postings")->fetchColumn(),
    ];
}

// ---- Landing shortcuts ----
//
// The unfiltered page needs a way in that is not "type something". These are
// entry points, not a filter UI: plain links, shown only when nothing is
// selected, each landing on a filter the sidebar can then refine. Counts are
// computed here rather than read off $..._options so capping and ordering in
// the facet lists cannot quietly change what the landing offers.
$shortcuts = [];
if ($is_unfiltered) {
    $today = date('Y-m-d');
    $live  = "(expires_at IS NULL OR expires_at >= '$today')";

    foreach (db()->query(
        "SELECT inf_profession_family AS v, COUNT(*) AS c FROM job_postings
         WHERE inf_profession_family <> '' AND $live
         GROUP BY 1 ORDER BY 2 DESC LIMIT 3") as $r) {
        $shortcuts[] = ['param' => 'family', 'value' => $r['v'],
                        'label' => mb_convert_case($r['v'], MB_CASE_TITLE, 'UTF-8'), 'count' => (int)$r['c']];
    }

    $probes = [
        ['type',         'Temporar',             'Temporar',             "job_type = 'Temporar'"],
        ['employer_cat', 'Funcție publică',      'Funcție publică',      "employer_category = 'Funcție publică'"],
        ['employer_cat', 'Funcție contractuală', 'Funcție contractuală', "employer_category = 'Funcție contractuală'"],
        ['remote',       '1',                    'Telemuncă',            "inf_remote_eligible = 1"],
    ];
    foreach ($probes as [$param, $value, $label, $clause]) {
        $c = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE $clause AND $live")->fetchColumn();
        if ($c > 0) $shortcuts[] = compact('param', 'value', 'label') + ['count' => $c];
    }
}

// ---- Facet counts ----

/**
 * WHERE fragments + FTS join for a facet count query, with this facet's own
 * selection excluded so its options keep showing sibling counts.
 */
function facet_scope(string $excl_key): array {
    global $q;
    $ff = build_filters($_GET, true, $excl_key);
    $scope = ['join' => '', 'where' => $ff['where'], 'binds' => $ff['binds']];
    if ($ff['fts'] && ($m = fts_query($q)) !== '') {
        $scope['join']    = "JOIN job_postings_fts fts ON fts.rowid = j.id";
        $scope['where'][] = "job_postings_fts MATCH ?";
        $scope['binds'][] = $m;
    }
    return $scope;
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
$level_options    = get_facet('job_level',            'levels');
$type_options     = get_facet('job_type',             'types');
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

$seniority_options = get_facet('inf_seniority',       'seniorities');
$work_type_options = get_facet('inf_work_type',        'work_types');
$studies_options   = get_facet('inf_studies_required', 'studies_levels');

// Status counts — one pass instead of three
{
    $s = facet_scope('status');
    $where_st = $s['where'] ? 'WHERE ' . implode(' AND ', $s['where']) : '';
    $today = date('Y-m-d');
    $in7   = date('Y-m-d', strtotime('+7 days'));
    $st = db()->prepare("SELECT
            SUM(CASE WHEN j.expires_at IS NULL OR j.expires_at >= ? THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN j.expires_at >= ? AND j.expires_at <= ? THEN 1 ELSE 0 END)   AS soon
        FROM job_postings j {$s['join']} $where_st");
    $st->execute(array_merge([$today, $today, $in7], $s['binds']));
    $r = $st->fetch() ?: [];
    $status_counts = [
        'active' => (int)($r['active'] ?? 0),
        'soon'   => (int)($r['soon'] ?? 0),
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

// Anomalii is the same JSON-array shape, and until 2026-09-09 it was the one
// group rendered with no counts at all — every flag looked clickable, including
// the ones that would empty the list. Counted like the rest, the values that
// would zero out simply stop being offered.
$anomaly_options = v3_facet('inf_anomaly_flags', 'anomaly',
                            fn ($v) => ANOMALY_LABELS[$v] ?? $v);

$isced_options  = v3_facet('v3_isced_fields',   'isced',      'isced_label');
$skill_options  = v3_facet('v3_skills',         'skill',      fn($v) => $v, 40);
$lang_options   = v3_facet('v3_languages',      'lang',       'language_token_label');
$cred_options   = v3_facet('v3_credentials',    'credential', 'credential_kind_label');
$domain_options = v3_facet('v3_policy_domains', 'domain',     'policy_domain_label');
$stage_options  = v3_facet('v3_exam_stages',    'stage',      'exam_stage_label');

// Minimum study level a posting requires, as EQF. Counts are per exact level
// and so is the filter — see the `eqf` block in build_filters().
$eqf_options = [];
{
    $s = facet_scope('eqf');
    $where = $s['where'] ? 'WHERE ' . implode(' AND ', $s['where']) . ' AND j.v3_eqf_level IS NOT NULL'
                        : 'WHERE j.v3_eqf_level IS NOT NULL';
    $st = db()->prepare("SELECT j.v3_eqf_level AS val, COUNT(*) AS cnt
        FROM job_postings j {$s['join']} $where GROUP BY j.v3_eqf_level ORDER BY j.v3_eqf_level");
    $st->execute($s['binds']);
    foreach ($st->fetchAll() as $r) {
        $eqf_options[] = ['val' => (string)$r['val'],
                          'label' => EQF_LABELS[(int)$r['val']] ?? ('EQF ' . $r['val']),
                          'cnt' => (int)$r['cnt']];
    }
}

// Structured-description counts (LLM schema present vs raw body)
$schema_options = [];
{
    $sc = facet_scope('schema');
    $yes = scope_count($sc, ["j.schema_json IS NOT NULL"]);
    $no  = scope_count($sc, ["j.schema_json IS NULL"]);
    if ($yes) $schema_options[] = ['val' => 'yes', 'label' => 'Structurată (LLM)', 'cnt' => $yes];
    if ($no)  $schema_options[] = ['val' => 'no',  'label' => 'Doar text brut',    'cnt' => $no];
}

// Remote count
{
    $s = facet_scope('remote');
    $remote_count = scope_count($s, ["j.inf_remote_eligible = 1"]);
}

// Experience buckets
$exp_options = [];
{
    $s = facet_scope('exp_levels');
    foreach (EXP_BUCKETS as $bk) {
        if ($bk['min'] == 0 && $bk['max'] !== null) {
            $cnt = scope_count($s, ["(j.inf_experience_years IS NULL OR j.inf_experience_years < ?)"], [$bk['max']]);
        } elseif ($bk['max'] === null) {
            $cnt = scope_count($s, ["j.inf_experience_years >= ?"], [$bk['min']]);
        } else {
            $cnt = scope_count($s, ["(j.inf_experience_years >= ? AND j.inf_experience_years < ?)"], [$bk['min'], $bk['max']]);
        }
        if ($cnt) $exp_options[] = ['val' => $bk['key'], 'cnt' => $cnt, 'label' => $bk['label']];
    }
}

// Salary buckets
$salary_options = [];
{
    $sal_total = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE inf_salary_min IS NOT NULL")->fetchColumn();
    if ($sal_total >= 100) {
        $s = facet_scope('salary_bucket');
        foreach (SALARY_BUCKETS as $bk) {
            $extra_w = ["j.inf_salary_min IS NOT NULL", "j.inf_salary_min >= ?"];
            $extra_b = [$bk['min']];
            if ($bk['max'] !== null) { $extra_w[] = "j.inf_salary_min < ?"; $extra_b[] = $bk['max']; }
            $cnt = scope_count($s, $extra_w, $extra_b);
            if ($cnt) $salary_options[] = ['val' => $bk['key'], 'cnt' => $cnt, 'label' => $bk['label']];
        }
    }
}

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

// ---- Render ----
if (!$is_htmx) {
    $page_title       = $q !== '' ? "Căutare: $q" : 'Posturi în sectorul public';
    $meta_description = 'Explorator independent pentru anunțurile de angajare din sectorul public românesc: '
                      . 'căutare full-text, filtre pe județ, domeniu, grad și termen limită.';
    $canonical_path   = '/' . (($cq = http_build_query($_GET)) ? '?' . $cq : '');
    require __DIR__ . '/../inc/header.php';
}

if (!$is_htmx): ?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-6">

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

    <!-- Backdrop for the mobile drawer. Outside the columns: it is fixed, and
         keeping it out of the sticky wrapper avoids any stacking-context doubt. -->
    <div id="facet-backdrop" hidden
         class="fixed inset-0 z-30 bg-ink/40 lg:hidden"></div>

    <div class="lg:flex lg:gap-6 lg:items-start">

      <!-- LEFT COLUMN — search above the facets.
           The filter is the main exploration tool, so on desktop it owns a
           column and the search sits at the top of it rather than spanning the
           page. On mobile there is no column: the search stays inline at the
           top and the facets become the slide-over drawer, which is why the
           search input lives here rather than inside <aside> — one input, one
           name, reachable at every width. -->
      <div class="lg:sticky lg:top-4 lg:flex lg:max-h-[calc(100vh-2rem)] lg:w-64 lg:shrink-0 lg:flex-col">

    <div class="flex gap-2 mb-3">
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

        <!-- Feed links -->
        <div class="mt-6 space-y-1 border-t border-line pt-4 text-xs text-ink-muted">
          <div class="mb-1 font-semibold uppercase tracking-widest">Export</div>
          <a data-feed="posturi.atom" href="<?= e(feed_url('posturi.atom')) ?>" class="block py-1 text-gov hover:underline">Atom (RSS)</a>
          <a data-feed="posturi.json" href="<?= e(feed_url('posturi.json')) ?>" class="block py-1 text-gov hover:underline">JSON API</a>
          <a data-feed="posturi.ics"  href="<?= e(feed_url('posturi.ics'))  ?>" class="block py-1 text-gov hover:underline">iCal</a>
        </div>

        <!-- Drawer footer (mobile only) -->
        <div class="sticky bottom-0 -mx-4 mt-4 border-t border-line bg-page px-4 py-3 lg:hidden">
          <button type="button" id="drawer-apply"
                  class="w-full rounded-md bg-gov px-4 py-2.5 text-sm font-medium text-on-gov focus:outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2">
            Arată <span id="drawer-count"><?= $total_count ?></span> rezultate
          </button>
        </div>
      </aside>

      </div><!-- /left column -->

      <!-- RIGHT COLUMN — shortcuts, controls, results -->
      <div class="min-w-0 lg:flex-1">

    <?php if ($shortcuts): ?>
    <div class="mb-5 border-b border-line pb-4">
      <?php if ($quick_stats): ?>
      <p class="mb-2 font-mono text-xs text-ink-muted">
        <strong class="font-semibold text-ink"><?= number_format($quick_stats['active'], 0, ',', '.') ?></strong> anunțuri active ·
        <strong class="font-semibold text-ink"><?= number_format($quick_stats['employers'], 0, ',', '.') ?></strong> angajatori
      </p>
      <?php endif; ?>
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs uppercase tracking-widest text-ink-muted">Începe cu</span>
        <?php foreach ($shortcuts as $sc): ?>
        <a href="/?<?= e($sc['param']) ?>=<?= rawurlencode($sc['value']) ?>"
           class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1 text-xs text-ink transition-colors hover:border-gov hover:text-gov">
          <?= e($sc['label']) ?>
          <span class="font-mono text-ink-muted"><?= number_format($sc['count'], 0, ',', '.') ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- STATUS + SORT -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
      <div role="group" aria-label="Stare anunț"
           class="inline-flex rounded-md border border-line-strong bg-surface overflow-hidden">
        <?php foreach (STATUS_LABELS as $sval => $slabel): ?>
        <label class="relative border-r border-line-strong last:border-r-0">
          <input type="radio" name="status" value="<?= e($sval) ?>" class="peer sr-only"<?= checked_if($status === $sval) ?>>
          <span class="block cursor-pointer px-3 py-1.5 text-xs sm:text-sm text-ink-muted transition-colors hover:text-gov peer-checked:bg-gov peer-checked:text-on-gov peer-focus-visible:ring-2 peer-focus-visible:ring-inset peer-focus-visible:ring-focus">
            <?= e($slabel) ?>
            <span class="ml-1 font-mono text-xs opacity-70"><?= $status_counts[$sval] ?></span>
          </span>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="flex items-center gap-2">
        <label for="sort" class="text-xs uppercase tracking-widest text-ink-muted">Sortare</label>
        <select id="sort" name="sort" class="rounded-md bg-surface border border-line-strong px-2 py-1.5 text-sm text-ink focus:outline-none focus:border-gov focus:ring-1 focus:ring-focus">
          <option value=""<?= selected_if(!$sort) ?>><?= $q ? 'Relevanță' : 'Cele mai noi' ?></option>
          <option value="deadline"<?= selected_if($sort === 'deadline') ?>>Termen limită</option>
          <option value="employer"<?= selected_if($sort === 'employer') ?>>Angajator A–Z</option>
        </select>
      </div>
    </div>


        <p id="results-status" role="status" aria-live="polite" aria-atomic="true" class="sr-only"><?= $total_count ?> rezultate</p>
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

  function applyFacetState() {
    document.querySelectorAll('.facet-group').forEach(function (el) {
      var key = el.dataset.facet;
      // A facet holding an active selection is rendered open and stays open.
      if (!el.open && state[key] === true) el.open = true;
      else if (el.open && state[key] === false && !el.querySelector(':checked')) el.open = false;
    });
  }
  applyFacetState();

  // `toggle` does not bubble, so this listens in the capture phase — which also
  // keeps it working across sidebar swaps, unlike a listener per <details>.
  document.addEventListener('toggle', function (ev) {
    var el = ev.target;
    if (!el.dataset || !el.classList.contains('facet-group')) return;
    state[el.dataset.facet] = el.open;
    try { localStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* private mode */ }
  }, true);

  // ---- Live facet counts ----
  // Every HTMX response carries a fresh #facet-list as an out-of-band swap, so
  // the counts next to each checkbox narrow with the results instead of going
  // stale until the next full page load. Replacing the markup costs three
  // pieces of client state, put back here: which control had focus (the one
  // just clicked — losing it breaks keyboard multi-select), how far a long
  // group like Județ was scrolled, and the panel's own scroll offset.
  // <details> open/closed is restored by applyFacetState() above.
  var pending = null;

  document.body.addEventListener('htmx:beforeRequest', function () {
    var list = document.getElementById('facet-list');
    if (!list) return;
    var a = document.activeElement;
    pending = {
      focus:  (a && list.contains(a) && a.name) ? { name: a.name, value: a.value } : null,
      panel:  panel.scrollTop,
      groups: {}
    };
    list.querySelectorAll('.facet-group > div').forEach(function (d) {
      if (d.scrollTop) pending.groups[d.parentElement.dataset.facet] = d.scrollTop;
    });
  });

  document.body.addEventListener('htmx:afterSettle', function () {
    applyFacetState();
    var list = document.getElementById('facet-list');
    if (!list || !pending) return;

    panel.scrollTop = pending.panel;
    Object.keys(pending.groups).forEach(function (key) {
      var d = list.querySelector('.facet-group[data-facet="' + key + '"] > div');
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
      if (el.type === 'checkbox' || el.type === 'radio') {
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
  // The export block sits outside #facet-list, so unlike the facet counts it is
  // not re-rendered on a filter change. Without this the Atom/JSON/iCal hrefs
  // keep the query string from the last full page load, and subscribing after
  // narrowing the filters hands you a feed of whatever you were looking at
  // before — silently, since the link still works. `page` and `sort` are
  // dropped for the same reason feed_url() drops them server-side.
  var feedLinks = document.querySelectorAll('[data-feed]');

  function syncFeedLinks() {
    var params = new URLSearchParams(location.search);
    params.delete('page');
    params.delete('sort');
    var qs = params.toString();
    feedLinks.forEach(function (a) {
      a.setAttribute('href', '/' + a.dataset.feed + (qs ? '?' + qs : ''));
    });
  }

  // hx-push-url writes the address bar after the swap settles; both events fire
  // for a filter change, and back/forward only fires the popstate one.
  document.body.addEventListener('htmx:pushedIntoHistory', syncFeedLinks);
  window.addEventListener('popstate', syncFeedLinks);

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
