<?php
declare(strict_types=1);

// FIX-09: every figure here is labelled with the dataset it describes. The first block
// ("Export curent") counts the rows of THIS sqlite file, which on the public host holds only
// the announcements still open at build time; the second ("Istoric") reads the
// publication_monthly aggregate the export computes from all of PostgreSQL. The two are never
// added together or shown under one unlabeled total.

$seven_ago = date('Y-m-d', strtotime('-7 days'));

$total = (int)db()->query("SELECT COUNT(*) FROM job_postings")->fetchColumn();

// FIX-05 status vocabulary. "Active" (the search default) is confirmed open + the two
// uncertain states; a closed row is one whose resolved deadline has passed.
$by_status = array_fill_keys(['confirmed_open', 'unconfirmed', 'unknown', 'closed'], 0);
foreach (db()->query("SELECT application_status AS s, COUNT(*) AS n FROM job_postings GROUP BY application_status")->fetchAll() as $r) {
    if (isset($by_status[$r['s']])) $by_status[$r['s']] = (int)$r['n'];
}
$confirmed_open = $by_status['confirmed_open'];
$uncertain      = $by_status['unconfirmed'] + $by_status['unknown'];
$closed         = $by_status['closed'];
$active         = $confirmed_open + $uncertain;

$inferred_cnt = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE inferred != '{}'")->fetchColumn();
$unique_employers = (int)db()->query("SELECT COUNT(DISTINCT employer_id) FROM job_postings")->fetchColumn();
$unique_judete    = (int)db()->query("SELECT COUNT(DISTINCT judet_id) FROM job_postings WHERE judet_id IS NOT NULL")->fetchColumn();
$new_7days = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE published_at >= '$seven_ago'")->fetchColumn();
// Pay: what the announcement states and what the draft salary grid estimates are different
// facts, so they are counted apart (an estimate is only counted where no pay is announced).
$sal_announced = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE inf_salary_min > 0")->fetchColumn();
$sal_estimated = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE COALESCE(inf_salary_min, 0) <= 0 AND sal_min > 0")->fetchColumn();

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

// `employer_category` is the source's own category badge. It is NOT a classification of the
// employer: it mixes position/contract labels ("Funcție contractuală", "Funcție publică")
// with institution-style labels ("Primării", "Instituții locale") depending on how the
// source filled it, and is empty on a share of rows. It is shown as the source states it,
// the empty rows are counted as unknown, and nothing is mapped to an institution type (that
// needs its own classifier, see the backlog).
$by_emp_cat = db()->query("SELECT employer_category AS category, COUNT(*) AS cnt FROM job_postings WHERE employer_category != '' GROUP BY employer_category ORDER BY cnt DESC LIMIT 10")->fetchAll();
$emp_cat_unknown = (int)db()->query("SELECT COUNT(*) FROM job_postings WHERE employer_category = ''")->fetchColumn();
$max_emp_cat = max($by_emp_cat ? (int)$by_emp_cat[0]['cnt'] : 0, $emp_cat_unknown, 1);

// Publication month of the rows IN THIS EXPORT. Only shown (as such) when the export has no
// historical aggregate; with one, the history section answers the question properly.
$hist = publication_history();
$by_month = [];
$max_monthly = 1;
if ($hist === null) {
    $by_month = db()->query("SELECT strftime('%Y-%m', published_at) AS month, COUNT(*) AS cnt FROM job_postings WHERE published_at IS NOT NULL GROUP BY month ORDER BY month DESC LIMIT 12")->fetchAll();
    $by_month = array_reverse($by_month);
    $max_monthly = $by_month ? max(array_column($by_month, 'cnt')) : 1;
}

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
  <p class="text-sm mb-6"><a href="/angajatori/" class="text-gov hover:underline">Mergi la statistici Angajatori →</a></p>

  <!-- Which numbers describe what -->
  <div class="bg-neutral border border-neutral-line rounded-lg p-4 mb-8 text-sm text-neutral-ink" id="domeniu">
    <p class="font-semibold mb-1">Ce descriu cifrele de pe această pagină</p>
    <ul class="list-disc pl-5 space-y-1">
      <li><a href="#export-curent" class="underline">Export curent</a>: fișierul de date servit acum. <?= e(stats_scope_sentence()) ?> Nu este arhiva sursei.</li>
      <li><a href="#istoric" class="underline">Istoric</a>: câte anunțuri a publicat sursa în fiecare lună, din toată baza de date, inclusiv anunțurile expirate care nu mai sunt în exportul curent.<?= $hist === null ? ' Indisponibil în acest export.' : '' ?></li>
    </ul>
  </div>

  <section id="export-curent" aria-labelledby="h-export-curent" class="mb-12">
  <h2 id="h-export-curent" class="font-display text-2xl italic font-semibold text-ink mb-1">Export curent</h2>
  <p class="text-xs text-ink-faint mb-5">Numărătoare peste anunțurile din acest export: <?= (int)$total ?> în total. Procentele sunt din acest total.</p>

  <!-- Status of the announcements in the export (FIX-05 vocabulary) -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-3xl font-display font-semibold text-gov"><?= $total ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Anunțuri în export</div>
      <div class="text-xs text-ink-faint mt-0.5">toate stările de mai jos</div>
    </div>
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-3xl font-display font-semibold text-ok-ink"><?= $confirmed_open ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Deschise, termen confirmat</div>
      <div class="text-xs text-ink-faint mt-0.5"><?= round(100 * $confirmed_open / $ttl) ?>% din export</div>
    </div>
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-3xl font-display font-semibold text-note-ink"><?= $uncertain ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Termen neconfirmat</div>
      <div class="text-xs text-ink-faint mt-0.5"><?= $by_status['unconfirmed'] ?> cu termen estimat din expirare · <?= $by_status['unknown'] ?> fără termen</div>
    </div>
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-3xl font-display font-semibold text-ink"><?= $closed ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Închise</div>
      <div class="text-xs text-ink-faint mt-0.5">termen depășit, încă în export</div>
    </div>
  </div>
  <p class="text-sm text-ink-muted mb-8">Active în căutare (deschise confirmate + cu termen neconfirmat): <strong class="text-ink"><?= $active ?></strong> din <?= $total ?> (<?= round(100 * $active / $ttl) ?>%). „Neconfirmat” înseamnă că data de expirare a anunțului ține loc de termen de înscriere; verifică termenul în anunțul oficial.</p>

  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
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
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Publicate în 7 zile</div>
      <div class="text-xs text-ink-faint mt-0.5"><?= round(100 * $new_7days / $ttl) ?>% din export</div>
    </div>
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-2xl font-display font-semibold text-ink"><?= $inferred_cnt ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Clasificate automat</div>
      <div class="text-xs text-ink-faint mt-0.5"><?= round(100 * $inferred_cnt / $ttl) ?>% din export</div>
    </div>
  </div>

  <!-- Pay: announced and estimated are different facts -->
  <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-10">
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-2xl font-display font-semibold text-ink"><?= $sal_announced ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Cu salariu anunțat</div>
      <div class="text-xs text-ink-faint mt-0.5"><?= round(100 * $sal_announced / $ttl) ?>% din export · declarat de angajator</div>
    </div>
    <div class="bg-surface border border-line rounded-lg p-5">
      <div class="text-2xl font-display font-semibold text-ink"><?= $sal_estimated ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Doar salariu estimat</div>
      <div class="text-xs text-ink-faint mt-0.5"><?= round(100 * $sal_estimated / $ttl) ?>% din export · calculat din proiectul grilei salariale, nu oferit de angajator</div>
    </div>
    <?php if ($avg_deadline): ?>
    <div class="bg-surface border border-line rounded-lg p-5 col-span-2 sm:col-span-1">
      <div class="text-2xl font-display font-semibold text-ink"><?= $avg_deadline ?> zile</div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Publicare → expirare</div>
      <div class="text-xs text-ink-faint mt-0.5">medie în export, pe expirarea anunțului</div>
    </div>
    <?php endif; ?>
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
      <h3 class="font-display text-lg italic font-semibold text-ink mb-4">Top domenii profesionale</h3>
      <div class="space-y-2">
        <?php bar_chart($by_family, 'family', 'cnt', $max_family, 'family'); ?>
      </div>
    </section>

    <section class="bg-surface border border-line rounded-lg p-6">
      <h3 class="font-display text-lg italic font-semibold text-ink mb-4">Top județe</h3>
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
      <h3 class="font-display text-lg italic font-semibold text-ink mb-4">Grad/funcție</h3>
      <div class="space-y-2">
        <?php bar_chart($by_seniority, 'seniority', 'cnt', $max_seniority, 'seniority'); ?>
      </div>
    </section>

    <section class="bg-surface border border-line rounded-lg p-6">
      <h3 class="font-display text-lg italic font-semibold text-ink mb-4">Studii minime</h3>
      <div class="space-y-2">
        <?php
        $mapped = array_map(fn($x) => ['studies' => STUDIES_LABELS[$x['studies']] ?? $x['studies'], 'cnt' => $x['cnt']], $by_studies);
        bar_chart($mapped, 'studies', 'cnt', $max_studies);
        ?>
      </div>
    </section>

    <section class="bg-surface border border-line rounded-lg p-6" id="categorie-sursa">
      <h3 class="font-display text-lg italic font-semibold text-ink mb-1">Categoria din anunț (câmp al sursei)</h3>
      <p class="text-xs text-ink-faint mb-4">Eticheta pe care o afișează sursa, nu un tip de angajator: amestecă tipul funcției (contractuală, publică) cu categorii de instituții și poate lipsi. Nu este o clasificare a instituțiilor.</p>
      <div class="space-y-2">
        <?php bar_chart($by_emp_cat, 'category', 'cnt', $max_emp_cat, 'employer_cat'); ?>
        <?php if ($emp_cat_unknown > 0) bar_chart([['category' => 'Necunoscut (necompletat în sursă)', 'cnt' => $emp_cat_unknown]], 'category', 'cnt', $max_emp_cat); ?>
      </div>
    </section>

    <?php if ($hist === null): ?>
    <!-- No historical aggregate in this export: the monthly chart can only describe the rows in the file. -->
    <section class="bg-surface border border-line rounded-lg p-6" id="luna-export">
      <h3 class="font-display text-lg italic font-semibold text-ink mb-1">Luna publicării, doar în exportul curent</h3>
      <p class="text-xs text-ink-faint mb-4">Nu este istoric: numără doar anunțurile păstrate în acest export (<?= (int)$total ?>), după luna publicării. Anunțurile expirate și scoase din export lipsesc, deci lunile vechi apar subestimate.</p>
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
    <?php endif; ?>
  </div>

  <!-- Anomalies -->
  <section class="bg-surface border border-line rounded-lg p-6">
    <h3 class="font-display text-lg italic font-semibold text-ink mb-4">Anomalii detectate în exportul curent</h3>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
      <?php foreach (ANOMALY_LABELS as $flag => $alabel): ?>
      <?php $cnt = $anomaly_counts[$flag] ?? 0; $pct = round(100 * $cnt / $ttl); ?>
      <div class="border border-note-line bg-note/40 rounded p-3">
        <div class="text-xl font-display font-semibold text-note-ink"><?= $cnt ?></div>
        <div class="text-xs text-note-ink mt-0.5"><?= e($alabel) ?></div>
        <div class="text-xs text-ink-faint mt-0.5"><?= $pct ?>% din export</div>
        <?php if ($cnt): ?>
          <a href="/?anomaly=<?= urlencode($flag) ?>" class="text-xs text-gov hover:underline mt-1 block">Explorează →</a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  </section>

  <!-- Historical publication intake (publication_monthly) -->
  <section id="istoric" aria-labelledby="h-istoric" class="mb-12">
    <h2 id="h-istoric" class="font-display text-2xl italic font-semibold text-ink mb-1">Istoric: anunțuri publicate pe lună</h2>
    <?php if ($hist === null): ?>
      <p class="text-ink-muted text-sm italic" id="istoric-indisponibil">Istoric indisponibil: acest export nu conține agregatele istorice. Cifrele de mai sus descriu doar exportul curent și nu se pot folosi ca istoric.</p>
    <?php else: ?>
      <?php $max_hist = $hist['months'] ? max(1, ...array_column($hist['months'], 'postings')) : 1; ?>
      <p class="text-xs text-ink-faint mb-5">Număr de anunțuri apărute în sursă, după luna publicării, din toată baza de date: includ anunțurile expirate și anulate, care nu sunt în exportul curent. Este intrarea lunară de anunțuri, nu câte posturi erau deschise la o anumită dată. Un anunț republicat se numără din nou.</p>
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-surface border border-line rounded-lg p-5">
          <div class="text-2xl font-display font-semibold text-ink"><?= (int)$hist['total'] ?></div>
          <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Anunțuri publicate, în total</div>
          <div class="text-xs text-ink-faint mt-0.5"><?= $hist['first'] ? 'din ' . e($hist['first']) . ' până în ' . e($hist['last']) : 'fără lună cunoscută' ?></div>
        </div>
        <div class="bg-surface border border-line rounded-lg p-5">
          <div class="text-2xl font-display font-semibold text-ink"><?= (int)$hist['cancelled'] ?></div>
          <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Din care anulate</div>
          <div class="text-xs text-ink-faint mt-0.5">incluse în totalul de mai sus</div>
        </div>
        <div class="bg-surface border border-line rounded-lg p-5 col-span-2 sm:col-span-1">
          <div class="text-2xl font-display font-semibold text-ink"><?= (int)$hist['unknown_date'] ?></div>
          <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Fără dată de publicare</div>
          <div class="text-xs text-ink-faint mt-0.5">incluse în total, în nicio lună</div>
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <section class="bg-surface border border-line rounded-lg p-6" id="luna-istoric">
          <h3 class="font-display text-lg italic font-semibold text-ink mb-4">Pe luna publicării (toată baza de date)</h3>
          <div class="space-y-1.5">
            <?php foreach ($hist['months'] as $item): ?>
            <div>
              <div class="flex justify-between text-xs mb-0.5">
                <span class="font-mono text-ink-muted"><?= e($item['month']) ?><?= $item['partial'] ? ' (în curs)' : '' ?></span>
                <span class="text-ink-muted font-mono"><?= (int)$item['postings'] ?></span>
              </div>
              <div class="h-1.5 bg-sunken rounded-full overflow-hidden">
                <div class="h-full bg-gov rounded-full" style="width:<?= round(100 * $item['postings'] / $max_hist) ?>%"></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if ($hist['older'] > 0): ?>
          <p class="text-xs text-ink-faint mt-3">Înainte de <?= e($hist['older_before']) ?>: <?= (int)$hist['older'] ?> anunțuri.</p>
          <?php endif; ?>
        </section>
        <section class="bg-surface border border-line rounded-lg p-6" id="judete-istoric">
          <h3 class="font-display text-lg italic font-semibold text-ink mb-4">Top județe, toată baza de date</h3>
          <div class="space-y-2">
            <?php $max_hc = $hist['counties'] ? $hist['counties'][0]['postings'] : 1; ?>
            <?php foreach ($hist['counties'] as $c): ?>
            <div>
              <div class="flex justify-between text-sm mb-0.5">
                <span class="text-ink"><?= e($c['name']) ?></span>
                <span class="text-ink-muted font-mono text-xs"><?= (int)$c['postings'] ?></span>
              </div>
              <div class="h-2 bg-sunken rounded-full overflow-hidden">
                <div class="h-full bg-gov rounded-full" style="width:<?= round(100 * $c['postings'] / $max_hc) ?>%"></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <p class="text-xs text-ink-faint mt-3">Anunțuri cu județ cunoscut, inclusiv anulate și fără dată<?= $hist['no_county'] > 0 ? '; fără județ: ' . (int)$hist['no_county'] : '' ?>.</p>
        </section>
      </div>
    <?php endif; ?>
  </section>

  <!-- LLM extraction spend -->
  <section class="bg-surface border border-line rounded-lg p-6 mb-8">
    <h2 class="font-display text-lg italic font-semibold text-ink mb-1">Costuri inferență</h2>
    <p class="text-xs text-ink-faint mb-4">Apelurile către modelul LLM, în USD, pe zile. Cuprinde toate apelurile din baza de date, nu doar anunțurile din exportul curent.</p>
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
