<?php
declare(strict_types=1);

$today = ro_today();
$seven_ago = date('Y-m-d', strtotime('-7 days'));

$total    = (int)db()->query("SELECT COUNT(*) FROM job_postings")->fetchColumn();
$active   = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE application_status IN (" . OPEN_STATUS_SQL . ")")->fetchColumn();
$inferred_cnt = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE inferred != '{}'")->fetchColumn();
$unique_employers = (int)db()->query("SELECT COUNT(DISTINCT employer_id) FROM job_postings")->fetchColumn();
$unique_judete    = (int)db()->query("SELECT COUNT(DISTINCT judet_id) FROM job_postings WHERE judet_id IS NOT NULL")->fetchColumn();
$new_7days = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE published_at >= '$seven_ago'")->fetchColumn();
$with_salary = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE inf_salary_min IS NOT NULL")->fetchColumn();

// Avg deadline
$avg_deadline = null;
$r = db()->query("SELECT AVG(julianday(expires_at) - julianday(published_at)) FROM job_postings WHERE expires_at IS NOT NULL AND published_at IS NOT NULL")->fetchColumn();
if ($r) $avg_deadline = (int)round((float)$r);

// By family
$by_family = db()->query("SELECT inf_profession_family AS family, COUNT(*) AS cnt FROM job_postings WHERE inf_profession_family IS NOT NULL AND inf_profession_family != '' AND inf_profession_family != 'altele' GROUP BY inf_profession_family ORDER BY cnt DESC LIMIT 10")->fetchAll();
$max_family = $by_family ? $by_family[0]['cnt'] : 1;

// By judet
$by_judet = db()->query("SELECT judet_name, judet_slug, COUNT(*) AS cnt FROM job_postings WHERE judet_id IS NOT NULL GROUP BY judet_id ORDER BY cnt DESC LIMIT 10")->fetchAll();
$max_judet = $by_judet ? $by_judet[0]['cnt'] : 1;

// By seniority
$by_seniority = db()->query("SELECT inf_seniority AS seniority, COUNT(*) AS cnt FROM job_postings WHERE inf_seniority IS NOT NULL AND inf_seniority != '' GROUP BY inf_seniority ORDER BY cnt DESC LIMIT 10")->fetchAll();
$max_seniority = $by_seniority ? $by_seniority[0]['cnt'] : 1;

// By studies
$by_studies = db()->query("SELECT inf_studies_required AS studies, COUNT(*) AS cnt FROM job_postings WHERE inf_studies_required IS NOT NULL AND inf_studies_required != '' GROUP BY inf_studies_required ORDER BY cnt DESC LIMIT 10")->fetchAll();
$max_studies = $by_studies ? $by_studies[0]['cnt'] : 1;

// By employer category
$by_emp_cat = db()->query("SELECT employer_category AS category, COUNT(*) AS cnt FROM job_postings WHERE employer_category != '' GROUP BY employer_category ORDER BY cnt DESC LIMIT 10")->fetchAll();
$max_emp_cat = $by_emp_cat ? $by_emp_cat[0]['cnt'] : 1;

// Monthly trends (last 12 months)
$by_month = db()->query("SELECT strftime('%Y-%m', published_at) AS month, COUNT(*) AS cnt FROM job_postings WHERE published_at IS NOT NULL GROUP BY month ORDER BY month DESC LIMIT 12")->fetchAll();
$by_month = array_reverse($by_month);
$max_monthly = $by_month ? max(array_column($by_month, 'cnt')) : 1;

// Anomaly counts
$anomaly_counts = [];
foreach (array_keys(ANOMALY_LABELS) as $flag) {
    $escaped = '%"' . $flag . '"%';
    $stmt = db()->prepare("SELECT COUNT(*) FROM job_postings WHERE inf_anomaly_flags LIKE ?");
    $stmt->execute([$escaped]);
    $anomaly_counts[$flag] = (int)$stmt->fetchColumn();
}

// LLM spend (llm_costs is built by export-to-sqlite.py). Since FIX-10 it joins two sources:
// `ledger` rows (every provider call, measured) and `legacy` rows (an estimate from the
// variant table, for the time before the ledger). The two never overlap, so every total
// below is a plain SUM. An export from before that has no step/source columns, and one
// from before the table has no table at all: show what is there rather than take the
// whole page down.
$llm = null;
$llm_days = [];
$llm_steps = [];
$llm_ledger = null;
try {
    $thirty_ago = date('Y-m-d', strtotime('-30 days'));
    $llm_cols = db()->query("SELECT name FROM pragma_table_info('llm_costs')")->fetchAll(PDO::FETCH_COLUMN);
    $has_source = in_array('source', $llm_cols, true);
    $llm = db()->query("SELECT COALESCE(SUM(cost_usd), 0) AS usd, COALESCE(SUM(calls), 0) AS calls, MIN(day) AS since FROM llm_costs")->fetch();
    $stmt = db()->prepare("SELECT COALESCE(SUM(cost_usd), 0) AS usd FROM llm_costs WHERE day >= ?");
    $stmt->execute([$thirty_ago]);
    $llm['usd_30'] = (float)$stmt->fetchColumn();
    if ($has_source) {
        // Per 100 extractions: the schema step only (occupations and infer calls are not per
        // posting), against valid extractions. `postings` is distinct per row, so a posting
        // extracted again on another day or with another prompt or model counts again; a
        // legacy row is one variant per posting, so its `postings` equals its `calls`.
        $per = db()->query("SELECT COALESCE(SUM(cost_usd), 0) AS usd, COALESCE(SUM(postings), 0) AS postings FROM llm_costs WHERE step = 'schema'")->fetch();
        $llm['postings'] = (int)$per['postings'];
        $llm['per_posting_usd'] = $llm['postings'] > 0 ? 100 * (float)$per['usd'] / $llm['postings'] : null;
        $llm_days = db()->query("SELECT day, SUM(cost_usd) AS usd, SUM(CASE WHEN source = 'ledger' THEN calls ELSE 0 END) AS ledger_calls, SUM(CASE WHEN source = 'legacy' THEN calls ELSE 0 END) AS legacy_postings FROM llm_costs GROUP BY day ORDER BY day DESC LIMIT 30")->fetchAll();
        $llm_ledger = db()->query("SELECT MIN(day) AS since, COALESCE(SUM(unpriced_calls), 0) AS unpriced FROM llm_costs WHERE source = 'ledger'")->fetch();
        if (!$llm_ledger || $llm_ledger['since'] === null) $llm_ledger = null;
        if ($llm_ledger) {
            $llm_steps = db()->query("SELECT step, SUM(calls) AS calls, SUM(cost_usd) AS usd FROM llm_costs WHERE source = 'ledger' GROUP BY step ORDER BY usd DESC")->fetchAll();
        }
    } else {
        // Before the ledger every row is a stored variant, i.e. one posting.
        $llm['postings'] = (int)$llm['calls'];
        $llm['per_posting_usd'] = $llm['postings'] > 0 ? 100 * (float)$llm['usd'] / $llm['postings'] : null;
        $llm_days = db()->query("SELECT day, SUM(cost_usd) AS usd, 0 AS ledger_calls, SUM(calls) AS legacy_postings FROM llm_costs GROUP BY day ORDER BY day DESC LIMIT 30")->fetchAll();
    }
} catch (PDOException $e) {
    $llm = null;
}
if ($llm !== null && (int)$llm['calls'] === 0) $llm = null;
$max_llm_day = $llm_days ? max(array_map(fn($d) => (float)$d['usd'], $llm_days)) : 0.0;

$ttl = $total ?: 1;

$page_title = 'Statistici';
require __DIR__ . '/../inc/header.php';
?>
<div class="max-w-screen-lg mx-auto px-4 sm:px-6 py-10">

  <h1 class="font-display text-[2rem] italic font-semibold text-ink mb-1">Statistici</h1>
  <p class="text-ink-muted text-sm mb-2 font-mono">Distribuție agregată a anunțurilor de angajare din sectorul public</p>
  <p class="text-sm mb-8"><a href="/angajatori/" class="text-gov hover:underline">Mergi la statistici Angajatori →</a></p>

  <!-- KPI row 1 -->
  <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-4">
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-3xl font-display font-semibold text-gov"><?= $total ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Anunțuri totale</div>
    </div>
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-3xl font-display font-semibold text-ok-ink"><?= $active ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Anunțuri active</div>
      <div class="text-xs text-ink-faint mt-0.5"><?= round(100 * $active / $ttl) ?>% din total</div>
      <?php if ($avg_deadline): ?><div class="text-xs text-ink-faint mt-1">avg <?= $avg_deadline ?> zile</div><?php endif; ?>
    </div>
    <div class="bg-surface border border-line rounded-lg p-5 col-span-2 sm:col-span-1">
      <div class="text-3xl font-display font-semibold text-ink"><?= $inferred_cnt ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Clasificate automat</div>
      <div class="text-xs text-ink-faint mt-0.5"><?= round(100 * $inferred_cnt / $ttl) ?>% din total</div>
    </div>
  </div>

  <!-- KPI row 2 -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-10">
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-2xl font-display font-semibold text-ink"><?= $unique_employers ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Angajatori unici</div>
    </div>
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-2xl font-display font-semibold text-ink"><?= $unique_judete ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Județe acoperite</div>
    </div>
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-2xl font-display font-semibold text-ink"><?= $new_7days ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Noi (7 zile)</div>
      <div class="text-xs text-ink-faint mt-0.5"><?= round(100 * $new_7days / $ttl) ?>% din total</div>
    </div>
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-2xl font-display font-semibold text-ink"><?= $with_salary ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Cu salariu</div>
      <div class="text-xs text-ink-faint mt-0.5"><?= round(100 * $with_salary / $ttl) ?>% din total</div>
    </div>
  </div>

  <?php
  function bar_chart(array $items, string $key_col, string $cnt_col, int $max, string $link_param = ''): void {
      if (!$items) { echo '<p class="text-ink-muted text-sm italic">Date indisponibile.</p>'; return; }
      foreach ($items as $item) {
          $val  = $item[$key_col] ?? '';
          $cnt  = $item[$cnt_col] ?? 0;
          $pct  = $max > 0 ? round(100 * $cnt / $max) : 0;
          $href = $link_param ? '/?'.e($link_param).'='.urlencode($val) : null;
          echo '<div>';
          echo '<div class="flex justify-between text-sm mb-0.5">';
          if ($href) {
              echo '<a href="' . $href . '" class="text-gov hover:underline capitalize">' . e($val) . '</a>';
          } else {
              echo '<span class="text-ink capitalize">' . e($val) . '</span>';
          }
          echo '<span class="text-ink-muted font-mono text-xs">' . $cnt . '</span>';
          echo '</div>';
          echo '<div class="h-2 bg-sunken rounded-full overflow-hidden">';
          echo '<div class="h-full bg-gov rounded-full" style="width:' . $pct . '%"></div>';
          echo '</div></div>';
      }
  }
  ?>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
    <section class="bg-surface border border-line rounded-lg p-6">
      <h2 class="font-display text-lg italic font-semibold text-ink mb-4">Top domenii profesionale</h2>
      <div class="space-y-2">
        <?php bar_chart($by_family, 'family', 'cnt', $max_family, 'family'); ?>
      </div>
    </section>

    <section class="bg-surface border border-line rounded-lg p-6">
      <h2 class="font-display text-lg italic font-semibold text-ink mb-4">Top județe</h2>
      <div class="space-y-2">
        <?php foreach ($by_judet as $item): ?>
        <div>
          <div class="flex justify-between text-sm mb-0.5">
            <a href="/?judet=<?= urlencode($item['judet_slug']) ?>" class="text-gov hover:underline"><?= e($item['judet_name']) ?></a>
            <span class="text-ink-muted font-mono text-xs"><?= $item['cnt'] ?></span>
          </div>
          <div class="h-2 bg-sunken rounded-full overflow-hidden">
            <div class="h-full bg-gov rounded-full" style="width:<?= round(100 * $item['cnt'] / $max_judet) ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="bg-surface border border-line rounded-lg p-6">
      <h2 class="font-display text-lg italic font-semibold text-ink mb-4">Grad/funcție</h2>
      <div class="space-y-2">
        <?php bar_chart($by_seniority, 'seniority', 'cnt', $max_seniority, 'seniority'); ?>
      </div>
    </section>

    <section class="bg-surface border border-line rounded-lg p-6">
      <h2 class="font-display text-lg italic font-semibold text-ink mb-4">Studii minime</h2>
      <div class="space-y-2">
        <?php
        $mapped = array_map(fn($x) => ['studies' => STUDIES_LABELS[$x['studies']] ?? $x['studies'], 'cnt' => $x['cnt']], $by_studies);
        bar_chart($mapped, 'studies', 'cnt', $max_studies);
        ?>
      </div>
    </section>

    <section class="bg-surface border border-line rounded-lg p-6">
      <h2 class="font-display text-lg italic font-semibold text-ink mb-4">Tip angajator</h2>
      <div class="space-y-2">
        <?php bar_chart($by_emp_cat, 'category', 'cnt', $max_emp_cat, 'employer_cat'); ?>
      </div>
    </section>

    <!-- Monthly trend -->
    <section class="bg-surface border border-line rounded-lg p-6">
      <h2 class="font-display text-lg italic font-semibold text-ink mb-4">Tendință lunară (12 luni)</h2>
      <div class="space-y-1.5">
        <?php foreach ($by_month as $item): ?>
        <div>
          <div class="flex justify-between text-xs mb-0.5">
            <span class="font-mono text-ink-muted"><?= e($item['month']) ?></span>
            <span class="text-ink-muted font-mono"><?= $item['cnt'] ?></span>
          </div>
          <div class="h-1.5 bg-sunken rounded-full overflow-hidden">
            <div class="h-full bg-gov rounded-full" style="width:<?= round(100 * $item['cnt'] / $max_monthly) ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <!-- Anomalies -->
  <section class="bg-surface border border-line rounded-lg p-6 mb-8">
    <h2 class="font-display text-lg italic font-semibold text-ink mb-4">Anomalii detectate</h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
      <?php foreach (ANOMALY_LABELS as $flag => $alabel): ?>
      <?php $cnt = $anomaly_counts[$flag] ?? 0; $pct = round(100 * $cnt / $ttl); ?>
      <div class="border border-note-line bg-note/40 rounded p-3">
        <div class="text-xl font-display font-semibold text-note-ink"><?= $cnt ?></div>
        <div class="text-xs text-note-ink mt-0.5"><?= e($alabel) ?></div>
        <div class="text-xs text-ink-faint mt-0.5"><?= $pct ?>% din total</div>
        <?php if ($cnt): ?>
          <a href="/?anomaly=<?= urlencode($flag) ?>" class="text-xs text-gov hover:underline mt-1 block">Explorează →</a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- LLM extraction spend -->
  <section class="bg-surface border border-line rounded-lg p-6 mb-8">
    <h2 class="font-display text-lg italic font-semibold text-ink mb-1">Costuri inferență</h2>
    <p class="text-xs text-ink-faint mb-4">Apelurile către modelul LLM, în USD, pe zile.</p>
    <?php if ($llm === null): ?>
      <p class="text-ink-muted text-sm italic">Date indisponibile.</p>
    <?php else: ?>
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
        <div class="border border-line rounded p-3">
          <div class="text-2xl font-display font-semibold text-ink"><?= e(usd_label((float)$llm['usd'])) ?></div>
          <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Total</div>
          <div class="text-xs text-ink-faint mt-0.5">din <?= e(fmt_date($llm['since'])) ?></div>
        </div>
        <div class="border border-line rounded p-3">
          <div class="text-2xl font-display font-semibold text-ink"><?= e(usd_label($llm['usd_30'])) ?></div>
          <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Ultimele 30 zile</div>
        </div>
        <?php if ($llm['per_posting_usd'] !== null): ?>
        <div class="border border-line rounded p-3 col-span-2 sm:col-span-1">
          <div class="text-2xl font-display font-semibold text-ink"><?= e(usd_label((float)$llm['per_posting_usd'])) ?></div>
          <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">La 100 de extrageri</div>
          <div class="text-xs text-ink-faint mt-0.5"><?= e(extractions_label((int)$llm['postings'])) ?>; un anunț reextras se numără din nou</div>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($llm_steps): ?>
      <div class="mb-6">
        <h3 class="text-xs text-ink-muted font-mono uppercase tracking-wide mb-2">Pe etape, de la <?= e(fmt_date($llm_ledger['since'])) ?> (apeluri măsurate)</h3>
        <ul class="text-sm space-y-1">
          <?php foreach ($llm_steps as $step): ?>
          <li class="flex justify-between">
            <span class="text-ink-muted"><?= e(llm_step_label((string)$step['step'])) ?> · <?= e(calls_label((int)$step['calls'])) ?></span>
            <span class="font-mono text-ink-muted"><?= e(usd_label((float)$step['usd'])) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <div class="space-y-1.5">
        <?php foreach ($llm_days as $d): ?>
        <div>
          <div class="flex justify-between text-xs mb-0.5">
            <span class="font-mono text-ink-muted"><?= e(fmt_date($d['day'])) ?> · <?= e(llm_day_label((int)$d['ledger_calls'], (int)$d['legacy_postings'])) ?></span>
            <span class="text-ink-muted font-mono"><?= e(usd_label((float)$d['usd'])) ?></span>
          </div>
          <div class="h-1.5 bg-sunken rounded-full overflow-hidden">
            <div class="h-full bg-gov rounded-full" style="width:<?= $max_llm_day > 0 ? round(100 * (float)$d['usd'] / $max_llm_day) : 0 ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($llm_ledger): ?>
      <p class="text-xs text-ink-faint mt-4">De la <?= e(fmt_date($llm_ledger['since'])) ?> fiecare apel către modelul LLM este înregistrat, cu reîncercările și cererile de reparare, pe etape și la prețul de la momentul apelului: sumele sunt măsurate, dar nu sunt o factură a furnizorului.<?php if ((int)$llm_ledger['unpriced'] > 0): ?> Apeluri fără cost cunoscut, neincluse în sume: <?= e((int)$llm_ledger['unpriced']) ?>.<?php endif; ?> Zilele marcate „estimare” sunt calculate din extragerile păstrate: o reextragere o suprascrie pe cea veche, așa că estimarea nu include apelurile înlocuite ulterior și nici normalizarea ocupațiilor sau clasificarea profesiilor.</p>
      <?php else: ?>
      <p class="text-xs text-ink-faint mt-4">Estimare incompletă: fiecare anunț este numărat la data ultimei sale extrageri; o reextragere mută costul pe ziua nouă. Clasificarea și normalizarea ocupațiilor nu sunt incluse.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
