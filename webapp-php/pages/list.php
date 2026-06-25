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
$sort         = $_GET['sort']          ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));

// ---- Check if HTMX partial request ----
$is_htmx = !empty($_SERVER['HTTP_HX_REQUEST']);

// ---- Build base filter ----
$f = build_filters($_GET);
$base_where = $f['where'];
$base_binds = $f['binds'];
$use_fts    = $f['fts'];

// ---- Main results query ----
$w = $base_where;
$b = $base_binds;
$join = '';

if ($use_fts && $q !== '') {
    $join = "JOIN job_postings_fts fts ON fts.rowid = j.id";
    $w[] = "job_postings_fts MATCH ?";
    $b[] = fts_escape($q);
}

$where_sql = $w ? 'WHERE ' . implode(' AND ', $w) : '';

if ($use_fts && !$sort) {
    $order = "ORDER BY rank, j.published_at DESC";
} elseif ($sort === 'deadline') {
    $order = "ORDER BY j.expires_at ASC, j.published_at DESC";
} elseif ($sort === 'employer') {
    $order = "ORDER BY j.employer_name ASC, j.published_at DESC";
} else {
    $order = "ORDER BY j.published_at DESC, j.id DESC";
}

// Count
$count_sql = "SELECT COUNT(*) FROM job_postings j $join $where_sql";
$total_count = (int)db()->prepare($count_sql)->execute($b) ? db()->prepare($count_sql) : 0;
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

// ---- Quick stats (unfiltered home page only) ----
$is_unfiltered = $page === 1 && !$q && !$judet_slugs && !$levels && !$types
              && !$categories && !$emp_cats && !$families && !$seniorities
              && !$work_types && !$exp_levels && !$studies_lvls && !$anomaly_flags
              && !$remote && !$computer && !$sal_bucket && !$exp_before && !$exp_after;

$quick_stats = null;
if ($is_unfiltered) {
    $today = date('Y-m-d');
    $quick_stats = [
        'active'    => (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE expires_at >= '$today'")->fetchColumn(),
        'judete'    => (int)db()->query("SELECT COUNT(DISTINCT judet_id) FROM job_postings WHERE judet_id IS NOT NULL")->fetchColumn(),
        'employers' => (int)db()->query("SELECT COUNT(DISTINCT employer_id) FROM job_postings")->fetchColumn(),
        'families'  => (int)db()->query("SELECT COUNT(DISTINCT inf_profession_family) FROM job_postings WHERE inf_profession_family IS NOT NULL AND inf_profession_family != ''")->fetchColumn(),
    ];
}

// ---- Facet counts ----
function facet_f(string $excl_key): array {
    return build_filters($_GET, true, $excl_key);
}

function get_facet(string $col, string $excl_key, int $limit = 0): array {
    global $q;
    $ff = facet_f($excl_key);
    return facet_counts($col, $ff['where'], $ff['binds'], $ff['fts'], $q, $limit);
}

$judet_options    = get_facet('judet_slug',          'judet_slugs',    25);
$level_options    = get_facet('job_level',            'levels');
$type_options     = get_facet('job_type',             'types');
$cat_options      = get_facet('categorie',            'categories');
$emp_cat_options  = get_facet('employer_category',    'employer_cats',  20);
$family_options   = [];
{
    $ff = facet_f('families');
    $join2 = '';
    $w2 = $ff['where'];
    $b2 = $ff['binds'];
    if ($ff['fts'] && $q) {
        $join2 = "JOIN job_postings_fts fts ON fts.rowid = j.id";
        $w2[] = "job_postings_fts MATCH ?";
        $b2[] = fts_escape($q);
    }
    $w2[] = "j.inf_profession_family IS NOT NULL";
    $w2[] = "j.inf_profession_family != ''";
    $w2[] = "j.inf_profession_family != 'altele'";
    $where2 = 'WHERE ' . implode(' AND ', $w2);
    $stmt2 = db()->prepare("SELECT j.inf_profession_family AS val, COUNT(*) AS cnt
        FROM job_postings j $join2 $where2
        GROUP BY j.inf_profession_family ORDER BY cnt DESC");
    $stmt2->execute($b2);
    $family_options = $stmt2->fetchAll();
}
$seniority_options = get_facet('inf_seniority',       'seniorities');
$work_type_options = get_facet('inf_work_type',        'work_types');
$studies_options   = get_facet('inf_studies_required', 'studies_levels');

// Remote count
{
    $ff = facet_f('remote');
    $join3 = '';
    $w3 = $ff['where'];
    $b3 = $ff['binds'];
    if ($ff['fts'] && $q) {
        $join3 = "JOIN job_postings_fts fts ON fts.rowid = j.id";
        $w3[] = "job_postings_fts MATCH ?"; $b3[] = fts_escape($q);
    }
    $where3 = $w3 ? 'WHERE ' . implode(' AND ', $w3) . ' AND j.inf_remote_eligible = 1' : 'WHERE j.inf_remote_eligible = 1';
    $stmt3 = db()->prepare("SELECT COUNT(*) FROM job_postings j $join3 $where3");
    $stmt3->execute($b3);
    $remote_count = (int)$stmt3->fetchColumn();
}

// Experience bucket facet
$exp_options = [];
{
    $ff = facet_f('exp_levels');
    $join4 = '';
    $w4 = $ff['where'];
    $b4 = $ff['binds'];
    if ($ff['fts'] && $q) {
        $join4 = "JOIN job_postings_fts fts ON fts.rowid = j.id";
        $w4[] = "job_postings_fts MATCH ?"; $b4[] = fts_escape($q);
    }
    $where4_base = $w4 ? 'WHERE ' . implode(' AND ', $w4) : '';

    foreach (EXP_BUCKETS as $bk) {
        $w5 = $w4;
        $b5 = $b4;
        if ($bk['min'] == 0 && $bk['max'] !== null) {
            $w5[] = "(j.inf_experience_years IS NULL OR j.inf_experience_years < ?)";
            $b5[] = $bk['max'];
        } elseif ($bk['max'] === null) {
            $w5[] = "j.inf_experience_years >= ?";
            $b5[] = $bk['min'];
        } else {
            $w5[] = "(j.inf_experience_years >= ? AND j.inf_experience_years < ?)";
            $b5[] = $bk['min'];
            $b5[] = $bk['max'];
        }
        $where5 = $w5 ? 'WHERE ' . implode(' AND ', $w5) : '';
        $stmt5 = db()->prepare("SELECT COUNT(*) FROM job_postings j $join4 $where5");
        $stmt5->execute($b5);
        $cnt = (int)$stmt5->fetchColumn();
        if ($cnt) $exp_options[] = ['val' => $bk['key'], 'cnt' => $cnt, 'label' => $bk['label']];
    }
}

// Salary options
$salary_options = [];
{
    $sal_total = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE inf_salary_min IS NOT NULL")->fetchColumn();
    if ($sal_total >= 100) {
        $ff = facet_f('salary_bucket');
        $join6 = '';
        $w6 = $ff['where'];
        $b6 = $ff['binds'];
        if ($ff['fts'] && $q) {
            $join6 = "JOIN job_postings_fts fts ON fts.rowid = j.id";
            $w6[] = "job_postings_fts MATCH ?"; $b6[] = fts_escape($q);
        }
        foreach (SALARY_BUCKETS as $bk) {
            $w7 = $w6;
            $b7 = $b6;
            $w7[] = "j.inf_salary_min IS NOT NULL AND j.inf_salary_min >= ?";
            $b7[] = $bk['min'];
            if ($bk['max'] !== null) { $w7[] = "j.inf_salary_min < ?"; $b7[] = $bk['max']; }
            $where7 = $w7 ? 'WHERE ' . implode(' AND ', $w7) : '';
            $stmt7 = db()->prepare("SELECT COUNT(*) FROM job_postings j $join6 $where7");
            $stmt7->execute($b7);
            $cnt = (int)$stmt7->fetchColumn();
            if ($cnt) $salary_options[] = ['val' => $bk['key'], 'cnt' => $cnt, 'label' => $bk['label']];
        }
    }
}

// ---- Render ----
if (!$is_htmx) {
    $page_title = 'Posturi publice';
    require __DIR__ . '/../inc/header.php';
}

if (!$is_htmx): ?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-6">

  <div class="mb-5">
    <h1 class="font-display text-3xl font-semibold italic text-ink leading-tight">Posturi în sectorul public</h1>
    <p class="text-ink-muted text-sm mt-1"><?= $total_count ?> anunțuri indexate · sursă: posturi.gov.ro</p>
  </div>

  <?php if ($is_unfiltered && $quick_stats): ?>
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-8">
    <div class="border border-border-warm rounded-lg p-4">
      <div class="text-2xl font-display font-semibold text-green-700"><?= $quick_stats['active'] ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Anunțuri active</div>
    </div>
    <div class="border border-border-warm rounded-lg p-4">
      <div class="text-2xl font-display font-semibold text-ink"><?= $quick_stats['judete'] ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Județe</div>
    </div>
    <div class="border border-border-warm rounded-lg p-4">
      <div class="text-2xl font-display font-semibold text-ink"><?= $quick_stats['employers'] ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Angajatori</div>
    </div>
    <div class="border border-border-warm rounded-lg p-4">
      <div class="text-2xl font-display font-semibold text-ink"><?= $quick_stats['families'] ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Domenii</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="flex gap-6 items-start">

    <!-- SIDEBAR -->
    <aside class="hidden lg:flex flex-col gap-0 w-64 shrink-0 sticky top-4 self-start">
      <form id="filter-form"
            hx-get="/"
            hx-target="#results"
            hx-push-url="true"
            hx-trigger="change, input delay:400ms from:[name=q]"
            hx-indicator="#results">

        <div class="mb-4">
          <div class="relative">
            <input type="text" name="q" value="<?= e($q) ?>"
                   placeholder="Caută posturi…"
                   class="w-full bg-white border border-border-warm px-3 py-2 pr-8 text-sm text-ink placeholder-ink-faint focus:outline-none focus:border-gov focus:ring-1 focus:ring-gov transition-colors">
            <?php if ($q): ?>
              <button type="button" onclick="document.querySelector('[name=q]').value=''; htmx.trigger('#filter-form', 'change')"
                      class="absolute right-2 top-1/2 -translate-y-1/2 text-ink-faint hover:text-ink text-lg leading-none">×</button>
            <?php else: ?>
              <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-ink-faint text-sm">⌕</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="mb-5">
          <label class="block text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1.5">Sortare</label>
          <select name="sort" class="w-full bg-white border border-border-warm px-2 py-1.5 text-sm text-ink focus:outline-none focus:border-gov">
            <option value=""<?= selected_if(!$sort) ?>><?= $q ? 'Relevanță' : 'Cele mai noi' ?></option>
            <option value="deadline"<?= selected_if($sort === 'deadline') ?>>Termen limită</option>
            <option value="employer"<?= selected_if($sort === 'employer') ?>>Angajator A–Z</option>
          </select>
        </div>

        <div class="border-t border-border-warm mb-4"></div>

        <?php
        function facet_group(string $label, array $items, string $name, array $active): void {
            if (!$items) return;
            echo '<div class="mb-4">';
            echo '<div class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-2">' . e($label) . '</div>';
            echo '<div class="space-y-0.5 max-h-48 overflow-y-auto pr-1">';
            foreach ($items as $item) {
                $val   = $item['val'] ?? $item['value'] ?? '';
                $lbl   = $item['label'] ?? $val;
                $cnt   = $item['cnt'] ?? $item['count'] ?? 0;
                $chk   = in_array($val, $active, true) ? ' checked' : '';
                echo '<label class="flex items-center gap-2 py-0.5 cursor-pointer group">';
                echo '<input type="checkbox" name="' . e($name) . '" value="' . e($val) . '" class="facet-check accent-gov shrink-0"' . $chk . '>';
                echo '<span class="text-sm text-ink group-hover:text-gov transition-colors truncate flex-1">' . e($lbl) . '</span>';
                echo '<span class="text-xs text-ink-faint font-mono shrink-0">' . $cnt . '</span>';
                echo '</label>';
            }
            echo '</div></div>';
        }
        facet_group('Domeniu',        $family_options,    'family',        $families);
        facet_group('Județ',           $judet_options,     'judet',         $judet_slugs);
        facet_group('Nivel',           $level_options,     'level',         $levels);
        facet_group('Tip',             $type_options,      'type',          $types);
        facet_group('Categorie',       $cat_options,       'categorie',     $categories);
        facet_group('Grad/funcție',    $seniority_options, 'seniority',     $seniorities);
        facet_group('Tip normă',       $work_type_options, 'work_type',     $work_types);
        facet_group('Experiență minimă', array_map(fn($x) => ['val' => $x['val'], 'label' => $x['label'], 'cnt' => $x['cnt']], $exp_options), 'exp_level', $exp_levels);
        facet_group('Studii minime',   $studies_options,   'studies_level', $studies_lvls);
        ?>

        <?php if ($remote_count): ?>
        <div class="mb-4">
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-2">Telemuncă</div>
          <label class="flex items-center gap-2 py-0.5 cursor-pointer group">
            <input type="checkbox" name="remote" value="1" class="facet-check accent-gov shrink-0"<?= checked_if((bool)$remote) ?>>
            <span class="text-sm text-ink group-hover:text-gov transition-colors flex-1">Disponibil remote</span>
            <span class="text-xs text-ink-faint font-mono shrink-0"><?= $remote_count ?></span>
          </label>
        </div>
        <?php endif; ?>

        <?php
        facet_group('Salariu',    $salary_options,  'salary_bucket', $sal_bucket ? [$sal_bucket] : []);
        facet_group('Angajator',  $emp_cat_options, 'employer_cat',  $emp_cats);
        ?>

        <!-- Anomaly flags -->
        <div class="mb-4">
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-2">Anomalii</div>
          <div class="space-y-1">
            <?php foreach (ANOMALY_LABELS as $flag => $alabel): ?>
            <label class="flex items-center gap-2 cursor-pointer group">
              <input type="checkbox" name="anomaly" value="<?= e($flag) ?>"
                     class="facet-check accent-gov"
                     <?= checked_if(in_array($flag, $anomaly_flags, true)) ?>>
              <span class="text-xs text-ink-muted group-hover:text-ink transition-colors"><?= e($alabel) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Deadline range -->
        <div class="mb-4">
          <div class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-2">Termen limită</div>
          <div class="space-y-1.5">
            <div>
              <label class="text-xs text-ink-muted">De la</label>
              <input type="date" name="expires_after" value="<?= e($exp_after) ?>"
                     class="w-full bg-white border border-border-warm px-2 py-1 text-xs text-ink focus:outline-none focus:border-gov mt-0.5">
            </div>
            <div>
              <label class="text-xs text-ink-muted">Până la</label>
              <input type="date" name="expires_before" value="<?= e($exp_before) ?>"
                     class="w-full bg-white border border-border-warm px-2 py-1 text-xs text-ink focus:outline-none focus:border-gov mt-0.5">
            </div>
          </div>
        </div>

        <?php if ($q || $judet_slugs || $levels || $types || $categories || $emp_cats ||
                   $exp_before || $exp_after || $families || $seniorities || $anomaly_flags ||
                   $work_types || $remote || $computer || $exp_levels || $studies_lvls || $sal_bucket): ?>
          <a href="/" class="block text-center text-xs text-gov hover:underline mt-2 py-1">✕ Șterge filtrele</a>
        <?php endif; ?>

      </form>

      <!-- Feed links -->
      <div class="mt-6 pt-4 border-t border-border-warm text-xs text-ink-faint space-y-1">
        <div class="font-semibold uppercase tracking-widest mb-1">Export</div>
        <a href="<?= e(feed_url('posturi.atom')) ?>" class="block text-gov hover:underline">Atom (RSS)</a>
        <a href="<?= e(feed_url('posturi.json')) ?>" class="block text-gov hover:underline">JSON API</a>
        <a href="<?= e(feed_url('posturi.ics')) ?>" class="block text-gov hover:underline">iCal</a>
      </div>
    </aside>

    <!-- RESULTS -->
    <div class="flex-1 min-w-0">
      <div class="lg:hidden mb-4">
        <details class="border border-border-warm bg-white">
          <summary class="px-4 py-2 text-sm font-medium cursor-pointer text-gov">Filtre ▾</summary>
          <div class="px-4 pb-4"><p class="text-xs text-ink-muted mt-2">Deschide pe desktop pentru filtre complete.</p></div>
        </details>
      </div>
      <div id="results">
<?php endif; // !$is_htmx ?>

<?php require __DIR__ . '/../partials/result_list.php'; ?>

<?php if (!$is_htmx): ?>
      </div>
    </div>
  </div>
</div>
<?php
    require __DIR__ . '/../inc/footer.php';
endif;
