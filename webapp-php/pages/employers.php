<?php
declare(strict_types=1);

$today = date('Y-m-d');
$judet_filter = trim($_GET['judet'] ?? '');

$total_employers = (int)db()->query("SELECT COUNT(DISTINCT employer_id) FROM job_postings")->fetchColumn();
$active_employers = (int)db()->query("SELECT COUNT(DISTINCT employer_id) FROM job_postings WHERE expires_at >= '$today'")->fetchColumn();
$total_postings  = (int)db()->query("SELECT COUNT(*) FROM job_postings")->fetchColumn();
$ttl_e = $total_employers ?: 1;

// Top 20 overall
$top_employers = db()->query("SELECT j.employer_id, j.employer_name, e.slug AS employer_slug, COUNT(*) AS cnt FROM job_postings j LEFT JOIN employers e ON e.id = j.employer_id GROUP BY j.employer_id ORDER BY cnt DESC LIMIT 20")->fetchAll();

// Top 20 active
$top_active = db()->query("SELECT j.employer_id, j.employer_name, e.slug AS employer_slug, COUNT(*) AS cnt FROM job_postings j LEFT JOIN employers e ON e.id = j.employer_id WHERE j.expires_at >= '$today' GROUP BY j.employer_id ORDER BY cnt DESC LIMIT 20")->fetchAll();

$max_employer = $top_employers ? $top_employers[0]['cnt'] : 1;
$max_active   = $top_active ? $top_active[0]['cnt'] : 1;

// Distribution
$one_post  = (int)db()->query("SELECT COUNT(*) FROM (SELECT employer_id FROM job_postings GROUP BY employer_id HAVING COUNT(*)=1)")->fetchColumn();
$two_five  = (int)db()->query("SELECT COUNT(*) FROM (SELECT employer_id FROM job_postings GROUP BY employer_id HAVING COUNT(*) BETWEEN 2 AND 5)")->fetchColumn();
$six_ten   = (int)db()->query("SELECT COUNT(*) FROM (SELECT employer_id FROM job_postings GROUP BY employer_id HAVING COUNT(*) BETWEEN 6 AND 10)")->fetchColumn();
$ten_plus  = (int)db()->query("SELECT COUNT(*) FROM (SELECT employer_id FROM job_postings GROUP BY employer_id HAVING COUNT(*) > 10)")->fetchColumn();
$total_with = $one_post + $two_five + $six_ten + $ten_plus ?: 1;

$distribution = [
    ['label' => '1 anunț',       'count' => $one_post,  'pct' => round(100 * $one_post  / $total_with)],
    ['label' => '2–5 anunțuri',  'count' => $two_five,  'pct' => round(100 * $two_five  / $total_with)],
    ['label' => '6–10 anunțuri', 'count' => $six_ten,   'pct' => round(100 * $six_ten   / $total_with)],
    ['label' => '10+ anunțuri',  'count' => $ten_plus,  'pct' => round(100 * $ten_plus  / $total_with)],
];
$max_dist = max(array_column($distribution, 'count')) ?: 1;

// Top 10 concentration
$top_ten_count = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE employer_id IN (SELECT employer_id FROM job_postings GROUP BY employer_id ORDER BY COUNT(*) DESC LIMIT 10)")->fetchColumn();
$concentration_pct = $total_postings ? round(100 * $top_ten_count / $total_postings) : 0;
$avg_postings = $total_with ? round($total_postings / $total_with, 1) : 0;

// Judet filter: dynamic top employers
$selected_judet_name = null;
if ($judet_filter) {
    $jd = db()->prepare("SELECT name FROM judete WHERE slug = ?");
    $jd->execute([$judet_filter]);
    $jd_row = $jd->fetch();
    if ($jd_row) {
        $selected_judet_name = $jd_row['name'];
        $stmt = db()->prepare("SELECT j.employer_name, e.slug AS employer_slug, COUNT(*) AS cnt FROM job_postings j LEFT JOIN employers e ON e.id = j.employer_id WHERE j.judet_slug = ? GROUP BY j.employer_id ORDER BY cnt DESC LIMIT 30");
        $stmt->execute([$judet_filter]);
        $top_employers_filtered = $stmt->fetchAll();
    } else {
        $top_employers_filtered = $top_employers;
    }
} else {
    $top_employers_filtered = $top_employers;
}
$max_filtered = $top_employers_filtered ? max(array_column($top_employers_filtered, 'cnt')) : 1;

// Judet list for dropdown
$judet_list = db()->query("SELECT DISTINCT judet_name AS name, judet_slug AS slug FROM job_postings WHERE judet_id IS NOT NULL ORDER BY judet_name")->fetchAll();

$page_title = 'Angajatori';
require __DIR__ . '/../inc/header.php';
?>
<div class="max-w-screen-lg mx-auto px-4 sm:px-6 py-10">

  <h1 class="font-display text-3xl italic font-semibold text-ink mb-1">Angajatori</h1>
  <p class="text-ink-muted text-sm mb-2 font-mono">Distribuție și concentrare după angajator</p>
  <p class="text-sm mb-8"><a href="/statistici/" class="text-gov hover:underline">← Înapoi la statistici generale</a></p>

  <!-- KPI tiles -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-10">
    <div class="bg-white border border-border-warm rounded-lg p-5">
      <div class="text-2xl font-display font-semibold text-gov"><?= $total_employers ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Angajatori totali</div>
    </div>
    <div class="bg-white border border-border-warm rounded-lg p-5">
      <div class="text-2xl font-display font-semibold text-green-700"><?= $active_employers ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Cu anunțuri active</div>
    </div>
    <div class="bg-white border border-border-warm rounded-lg p-5">
      <div class="text-2xl font-display font-semibold text-ink"><?= $avg_postings ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Medie anunțuri/ang.</div>
    </div>
    <div class="bg-white border border-border-warm rounded-lg p-5">
      <div class="text-2xl font-display font-semibold text-ink"><?= $concentration_pct ?>%</div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Top 10 concentrare</div>
      <div class="text-xs text-ink-faint mt-0.5"><?= $top_ten_count ?> din <?= $total_postings ?> anunțuri</div>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">

    <!-- Top overall with judet filter -->
    <section class="bg-white border border-border-warm rounded-lg p-6">
      <div class="flex items-center justify-between mb-4 gap-2">
        <h2 class="font-display text-lg italic font-semibold text-ink">Top angajatori</h2>
        <form method="get" action="/angajatori/">
          <select name="judet" onchange="this.form.submit()"
                  class="text-xs border border-border-warm bg-parchment px-2 py-1 focus:outline-none focus:border-gov">
            <option value="">Toate județele</option>
            <?php foreach ($judet_list as $jd): ?>
              <option value="<?= e($jd['slug']) ?>"<?= $judet_filter === $jd['slug'] ? ' selected' : '' ?>><?= e($jd['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <?php if ($selected_judet_name): ?>
        <p class="text-xs text-ink-muted mb-3">Afișând: <strong><?= e($selected_judet_name) ?></strong> <a href="/angajatori/" class="text-gov hover:underline ml-1">× resetează</a></p>
      <?php endif; ?>
      <div class="space-y-2">
        <?php foreach ($top_employers_filtered as $emp): ?>
        <div>
          <div class="flex justify-between text-sm mb-0.5">
            <a href="/angajator/<?= e($emp['employer_slug']) ?>/" class="text-gov hover:underline truncate max-w-xs"><?= e($emp['employer_name']) ?></a>
            <span class="text-ink-muted font-mono text-xs shrink-0 ml-2"><?= $emp['cnt'] ?></span>
          </div>
          <div class="h-1.5 bg-parchment-dark rounded-full overflow-hidden">
            <div class="h-full bg-gov rounded-full" style="width:<?= round(100 * $emp['cnt'] / $max_filtered) ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Top active -->
    <section class="bg-white border border-border-warm rounded-lg p-6">
      <h2 class="font-display text-lg italic font-semibold text-ink mb-4">Top angajatori activi</h2>
      <div class="space-y-2">
        <?php foreach ($top_active as $emp): ?>
        <div>
          <div class="flex justify-between text-sm mb-0.5">
            <a href="/angajator/<?= e($emp['employer_slug']) ?>/" class="text-gov hover:underline truncate max-w-xs"><?= e($emp['employer_name']) ?></a>
            <span class="text-ink-muted font-mono text-xs shrink-0 ml-2"><?= $emp['cnt'] ?></span>
          </div>
          <div class="h-1.5 bg-parchment-dark rounded-full overflow-hidden">
            <div class="h-full bg-green-600 rounded-full" style="width:<?= round(100 * $emp['cnt'] / $max_active) ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Distribution -->
    <section class="bg-white border border-border-warm rounded-lg p-6">
      <h2 class="font-display text-lg italic font-semibold text-ink mb-4">Distribuție angajatori</h2>
      <div class="space-y-3">
        <?php foreach ($distribution as $d): ?>
        <div>
          <div class="flex justify-between text-sm mb-0.5">
            <span class="text-ink"><?= e($d['label']) ?></span>
            <span class="text-ink-muted font-mono text-xs"><?= $d['count'] ?> (<?= $d['pct'] ?>%)</span>
          </div>
          <div class="h-2 bg-parchment-dark rounded-full overflow-hidden">
            <div class="h-full bg-gov rounded-full" style="width:<?= round(100 * $d['count'] / $max_dist) ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

  </div>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
