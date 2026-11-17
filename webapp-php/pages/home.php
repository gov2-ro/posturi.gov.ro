<?php
declare(strict_types=1);

/**
 * The landing page (UX-05, ADR 0004): bare `/`. Every count here is the number
 * of results of the link beside it (home_count_for(), build_filters() under the
 * default status), and the server output is the same for every reader, so the
 * edge can cache it. Personal state does not appear here.
 */

$tz = new DateTimeZone('Europe/Bucharest');
$today = ro_today();
$live = 'application_status IN (' . OPEN_STATUS_SQL . ')';
$fmt = static fn(int $n): string => number_format($n, 0, ',', '.');

// ---- Figures ----
$active_total = home_count_for([]);
$employers_active = (int)db()->query("SELECT COUNT(DISTINCT employer_id) FROM job_postings WHERE $live")->fetchColumn();
// Seven calendar days, today included. No list filter selects "published in the last
// 7 days" (`published` is one day), so this figure links to the whole list.
$since7 = (new DateTimeImmutable($today, $tz))->modify('-6 days')->format('Y-m-d');
$st = db()->prepare("SELECT COUNT(*) FROM job_postings WHERE $live AND substr(published_at, 1, 10) >= ?");
$st->execute([$since7]);
$new_7days = (int)$st->fetchColumn();

// ---- Chart: postings published in each of the last 12 complete ISO weeks ----
$weekly = home_weekly_postings(12);
$weeks_with_data = count(array_filter($weekly, fn($w) => $w['n'] > 0));
$chart = null;
if ($weeks_with_data >= 4) {
    $vals = array_column($weekly, 'n');
    [$vmin, $vmax] = [min($vals), max($vals)];
    $W = 320; $H = 64; $P = 4;
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = $i / (count($vals) - 1) * $W;
        $y = $vmax === $vmin ? $H / 2 : $P + (1 - ($v - $vmin) / ($vmax - $vmin)) * ($H - 2 * $P);
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }
    $chart = [
        'points' => implode(' ', $pts),
        'area' => "M0,$H L" . implode(' L', $pts) . " L$W,$H Z",
        'min' => $vmin, 'max' => $vmax, 'last' => end($vals),
        'from' => home_short_date($weekly[0]['start']),
        'to' => home_short_date(end($weekly)['start']),
    ];
}

// ---- Ready-made searches ----
$combos = [
    ['title' => 'Salarii peste 7.000 lei, în afara sectorului medical',
     'params' => ['salary_bucket' => 'peste-7000', 'isced_not' => ['09_sanatate_asistenta_sociala']],
     'tags' => ['peste 7.000 lei', 'fără Sănătate și asistență socială']],
    ['title' => '5.000–7.000 lei, normă parțială, pe proiecte PNRR sau europene',
     'params' => ['salary_bucket' => '5000-7000', 'schedule' => ['norma_partiala'], 'funding' => ['pnrr', 'fonduri_europene']],
     'tags' => ['5.000–7.000 lei', 'normă parțială', 'PNRR', 'fonduri europene']],
    ['title' => 'Magyar nyelvtudást igénylő állások', 'lang' => 'hu', 'flag' => true,
     'params' => ['lang' => ['hu']],
     'tags' => ['necesită limba maghiară']],
    ['title' => 'Primul job: fără experiență cerută',
     'params' => ['exp_level' => ['fara']],
     'tags' => ['fără experiență']],
    ['title' => 'Funcții de conducere',
     // The value of the facet "Funcție" for management, as the source spells it (JOB_LEVEL_LABELS maps the legacy 'conducere').
     'params' => ['level' => ['Funcții de conducere']],
     'tags' => ['conducere']],
    ['title' => 'Proiecte cu fonduri europene, pe perioadă determinată',
     'params' => ['funding' => ['fonduri_europene'], 'duration' => ['determinata']],
     'tags' => ['fonduri europene', 'perioadă determinată']],
];
foreach ($combos as $i => $c) {
    $combos[$i]['href'] = home_list_url($c['params']);
    $combos[$i]['n'] = home_count_for($c['params']);
}
$combos = array_values(array_filter($combos, fn($c) => $c['n'] > 0));

// ---- Professional domains and counties: one GROUP BY for (county, family, COR major group) ----
$domains = [];
foreach (HOME_DOMAINS as $d) {
    $d['params'] = ['family' => [$d['family']]] + (isset($d['cor']) ? ['cor' => $d['cor']] : []);
    $d['n'] = 0;
    $d['counties'] = [];
    $domains[$d['key']] = $d;
}
foreach (db()->query(
    "SELECT inf_profession_family AS f, substr(occ_group, 1, 1) AS c, judet_slug AS j, COUNT(*) AS n FROM job_postings
     WHERE $live GROUP BY 1, 2, 3") as $r) {
    foreach ($domains as $k => $d) {
        if ($d['family'] !== $r['f']) continue;
        if (isset($d['cor']) && !in_array((string)$r['c'], $d['cor'], true)) continue;
        $domains[$k]['n'] += (int)$r['n'];
        if ($r['j'] !== '' && $r['j'] !== null) $domains[$k]['counties'][$r['j']] = ($domains[$k]['counties'][$r['j']] ?? 0) + (int)$r['n'];
    }
}
$domains = array_values(array_filter($domains, fn($d) => $d['n'] > 0));
foreach ($domains as $i => $d) $domains[$i]['href'] = home_list_url($d['params']);
usort($domains, fn($a, $b) => ($a['key'] === 'altele') <=> ($b['key'] === 'altele') ?: $b['n'] <=> $a['n'] ?: strcmp($a['label'], $b['label']));

// Employer sectors, a row of text links under "Alte puncte de pornire" (count > 0, by count, Altele last).
$sector_total = [];
foreach (db()->query(
    "SELECT v4_employer_sector AS s, COUNT(*) AS c FROM job_postings
     WHERE $live AND v4_employer_sector <> '' GROUP BY 1") as $r) {
    if (isset(EMPLOYER_SECTOR_LABELS[$r['s']])) $sector_total[$r['s']] = (int)$r['c'];
}
$sectors = [];
foreach ($sector_total as $val => $n) {
    if ($n > 0) $sectors[] = ['val' => $val, 'label' => EMPLOYER_SECTOR_LABELS[$val], 'n' => $n];
}
usort($sectors, fn($a, $b) => ($a['val'] === 'altele') <=> ($b['val'] === 'altele') ?: $b['n'] <=> $a['n'] ?: strcmp($a['label'], $b['label']));

$county_total = [];   // county slug => active postings
foreach (db()->query(
    "SELECT judet_slug AS j, COUNT(*) AS c FROM job_postings
     WHERE $live AND judet_slug <> '' GROUP BY 1") as $r) {
    $county_total[$r['j']] = (int)$r['c'];
}
$names = judet_names();
$county_name = static fn(string $slug): string => $names[$slug] ?? ucwords(str_replace('-', ' ', $slug));
$county_options = judet_names_sorted();

// ---- Other starting points ----
$groups = [];
$studies = [];
foreach (STUDIES_LABELS as $key => $label) {
    $n = home_count_for(['studies_level' => [$key]]);
    if ($n > 0) $studies[] = ['label' => $label, 'n' => $n, 'href' => home_list_url(['studies_level' => [$key]])];
}
$groups[] = ['title' => 'După studii', 'items' => $studies];

$occupations = [];
foreach (db()->query(
    "SELECT occ_canonical AS v, COUNT(*) AS c FROM job_postings
     WHERE occ_canonical <> '' AND $live GROUP BY 1 ORDER BY 2 DESC, 1 LIMIT 8") as $r) {
    $occupations[] = ['label' => $r['v'], 'n' => (int)$r['c'], 'href' => home_list_url(['occupation' => [$r['v']]])];
}
$groups[] = ['title' => 'După ocupație', 'items' => $occupations];

$groups[] = ['title' => 'După angajator', 'items' => array_map(
    fn($x) => ['label' => $x['label'], 'n' => $x['n'], 'href' => home_list_url(['sector' => [$x['val']]])], $sectors)];

$other = [];
foreach ([
    ['Fără experiență', ['exp_level' => ['fara']]],
    ['Salariu peste 7.000 lei', ['salary_bucket' => 'peste-7000']],
    ['Funcții de conducere', ['level' => ['Funcții de conducere']]],
    ['Perioadă determinată', ['duration' => ['determinata']]],
    ['Normă parțială', ['schedule' => ['norma_partiala']]],
    ['Fonduri europene', ['funding' => ['fonduri_europene', 'pnrr']]],
] as [$label, $params]) {
    $n = home_count_for($params);
    if ($n > 0) $other[] = ['label' => $label, 'n' => $n, 'href' => home_list_url($params)];
}
$groups[] = ['title' => 'Alte filtre', 'items' => $other];

// ---- "Noi azi" and "Se închid în 3 zile" (moved from the list page, rules unchanged) ----
// New = the latest publication day in the data (named "azi" when it is today),
// so the section is not empty every morning before the first run.
$home_new = $home_soon = [];
$home_new_day = (string)db()->query("SELECT MAX(substr(published_at, 1, 10)) FROM job_postings WHERE $live")->fetchColumn();
if ($home_new_day !== '') {
    $st = db()->prepare("SELECT j.*, e.slug AS employer_slug_display FROM job_postings j
        LEFT JOIN employers e ON e.id = j.employer_id
        WHERE j.$live AND substr(j.published_at, 1, 10) = ?
        ORDER BY j.published_at DESC, j.id DESC LIMIT 6");
    $st->execute([$home_new_day]);
    $home_new = $st->fetchAll();
}
$in3 = (new DateTimeImmutable($today, $tz))->modify('+3 days')->format('Y-m-d');
$st = db()->prepare("SELECT j.*, e.slug AS employer_slug_display FROM job_postings j
    LEFT JOIN employers e ON e.id = j.employer_id
    WHERE j.application_status = 'confirmed_open' AND " . DEADLINE_COL . " >= ? AND " . DEADLINE_COL . " <= ?
    ORDER BY " . DEADLINE_COL . " ASC, j.published_at DESC LIMIT 6");
$st->execute([$today, $in3]);
$home_soon = $st->fetchAll();
timing_mark('home');

// ---- Page ----
$page_title       = 'Anunțuri de angajare din administrația publică';
$meta_description = 'Explorator independent pentru anunțurile de angajare din sectorul public românesc: '
                  . 'căutare full-text, filtre pe județ, domeniu, grad și termen limită.';
$canonical_path   = '/';
$head_extra       = '<script src="/static/home.js?v=' . (@filemtime(__DIR__ . '/../static/home.js') ?: '1') . '" defer></script>';
require __DIR__ . '/../inc/header.php';

$home_data = ['total' => $active_total, 'counties' => $county_total, 'domains' => []];
foreach ($domains as $d) $home_data['domains'][$d['key']] = ['family' => [$d['family']], 'cor' => $d['cor'] ?? [], 'counties' => $d['counties']];
$heading = 'g2-heading-section';
?>
<div class="g2-container flex flex-col gap-12 pb-14">

  <?php /* 3.1 Hero and figures */ ?>
  <section aria-labelledby="h-hero" class="pt-8">
    <div class="grid gap-8 lg:grid-cols-2 lg:items-start lg:gap-12">
      <div class="flex min-w-0 flex-col gap-4">
    <?php /* The title sits in the left column, above the search, and is smaller than the DS size so that it fits there. */ ?>
    <h1 id="h-hero" class="g2-heading-page mb-1 text-xl leading-tight sm:text-2xl">Anunțuri de angajare din administrația publică</h1>
        <?php /* Empty form: with JavaScript, static/home.js sends the reader to /posturi/ (or to the
           filled fields only). Without it, the server (index.php) redirects a request whose
           values are all blank to /posturi/ and drops blank values from the rest. */ ?>
        <form method="get" action="/" data-home-search class="flex flex-wrap gap-2">
          <label for="home-q" class="sr-only">Caută un post</label>
          <input id="home-q" type="search" name="q" placeholder="Caută: asistent medical, consilier, șofer…"
                 class="g2-input min-h-12 min-w-0 flex-[1_1_14rem] placeholder:text-ink-muted">
          <label for="home-judet" class="sr-only">Județ</label>
          <select id="home-judet" name="judet[]"
                  class="g2-input min-h-12 min-w-0 flex-[0_1_14rem]">
            <option value="" data-name="Toate județele">Toate județele</option>
            <?php foreach ($county_options as $slug => $name): ?>
            <option value="<?= e($slug) ?>" data-name="<?= e($name) ?>"><?= e($name) ?> (<?= e($fmt($county_total[$slug] ?? 0)) ?>)</option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="g2-btn min-h-12 justify-center px-6">Caută</button>
        </form>
        <p class="flex flex-wrap items-center gap-x-5 gap-y-0"><span class="inline-flex items-center gap-1.5"><a href="/posturi/" class="inline-flex min-h-[44px] items-center font-semibold">Toate anunțurile</a> <span data-home-all-count class="text-ink-muted">(<?= e($fmt($active_total)) ?>)</span></span>
          <a href="#domenii" data-home-to-domains class="inline-flex min-h-[44px] items-center font-semibold">După domeniu <span aria-hidden="true">↓</span></a></p>
        <p class="g2-text-meta">Proiect independent. Preluăm datele de pe <a href="https://posturi.gov.ro" class="-my-3 inline-block py-3">posturi.gov.ro</a>. <strong>Nu este un site oficial</strong> al Guvernului României.</p>
      </div>

      <div data-home-figures class="flex min-w-0 flex-col gap-5">
        <h2 class="sr-only">Cifre</h2>
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
          <?php foreach ([
              [$active_total, 'posturi active', '/posturi/'],
              [$employers_active, 'angajatori', '/angajatori/'],
              [$new_7days, 'noi în 7 zile', '/posturi/'],
          ] as [$fig_n, $fig_label, $fig_href]): ?>
          <a href="<?= e($fig_href) ?>" class="flex min-h-[44px] min-w-0 flex-col gap-0.5 text-ink no-underline hover:text-gov">
            <span class="text-xl font-bold leading-tight sm:text-3xl"><?= e($fmt($fig_n)) ?></span>
            <span class="g2-text-meta"><?= e($fig_label) ?></span>
          </a>
          <?php endforeach; ?>
          <a href="/statistici/" data-home-stats class="inline-flex min-h-[44px] items-center gap-1.5 font-semibold sm:ml-auto"><?= icon('i-chart-bar', 'h-5 w-5 shrink-0') ?>Statistici <span aria-hidden="true">→</span></a>
        </div>
        <?php if ($chart): ?>
        <figure class="flex flex-col gap-2 border-t border-line pt-4">
          <figcaption class="g2-label">Posturi publicate pe săptămână</figcaption>
          <svg role="img" aria-label="Posturi publicate pe săptămână, ultimele 12 săptămâni complete: între <?= e($fmt($chart['min'])) ?> și <?= e($fmt($chart['max'])) ?>"
               viewBox="0 0 320 64" preserveAspectRatio="none" class="block h-16 w-full overflow-visible">
            <path d="<?= e($chart['area']) ?>" class="fill-chart/15 stroke-none"></path>
            <polyline points="<?= e($chart['points']) ?>" fill="none" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" class="stroke-chart"></polyline>
          </svg>
          <div class="g2-text-meta flex flex-wrap justify-between gap-x-3">
            <span><?= e($chart['from']) ?></span>
            <span>min <?= e($fmt($chart['min'])) ?> · max <?= e($fmt($chart['max'])) ?> · ultima <?= e($fmt($chart['last'])) ?></span>
            <span><?= e($chart['to']) ?></span>
          </div>
        </figure>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php /* 3.2 Ready-made searches. No box: a title link, the tags and the count. */ ?>
  <?php if ($combos): ?>
  <section aria-labelledby="h-combo" class="flex flex-col gap-2">
    <h2 id="h-combo" class="sr-only">Exemple de căutare</h2>
    <ul class="grid grid-cols-1 gap-x-8 gap-y-2 md:grid-cols-2 lg:grid-cols-3">
      <?php foreach ($combos as $c): ?>
      <li data-home-example class="flex min-w-0 flex-col">
        <?php /* One run of inline text: the count follows the last word of the title (a no-break space
           joins them), so it never starts a line alone. The 44 px target is padding on an inline link. */ ?>
        <h3 class="py-0.5">
          <?php if (!empty($c['flag'])): ?><img src="/static/flags/hu.svg" alt="" width="30" height="20" class="mr-2 inline-block border border-line align-middle"><?php endif; ?><a href="<?= e($c['href']) ?>"<?= !empty($c['lang']) ? ' lang="' . e($c['lang']) . '"' : '' ?> class="g2-link-title py-2.5"><?= e($c['title']) ?></a><span data-example-count class="text-base font-normal text-ink-muted">&nbsp;(<?= e($fmt($c['n'])) ?>)</span>
        </h3>
        <ul class="flex flex-wrap gap-1.5 pb-2">
          <?php foreach ($c['tags'] as $tag): ?>
          <li class="g2-tag"><?= e($tag) ?></li>
          <?php endforeach; ?>
        </ul>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php /* 3.3 Domains and counties */ ?>
  <section id="domenii" aria-labelledby="h-dom" tabindex="-1" class="scroll-mt-4 focus:outline-none">
    <h2 id="h-dom" class="sr-only">Posturi pe domenii profesionale și județe</h2>
    <div class="grid gap-8 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:items-start lg:gap-10">
      <div class="flex min-w-0 flex-col gap-3">
        <div data-home-js hidden class="flex flex-wrap items-center gap-x-3 gap-y-2">
          <label for="domenii-judet" class="font-semibold">Județ</label>
          <select id="domenii-judet" class="g2-input min-h-[44px] min-w-[14rem]">
            <option value="" data-name="Toate județele">Toate județele</option>
            <?php foreach ($county_options as $slug => $name): ?>
            <option value="<?= e($slug) ?>" data-name="<?= e($name) ?>"><?= e($name) ?> (<?= e($fmt($county_total[$slug] ?? 0)) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <ul class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,15rem),1fr))] gap-x-4 gap-y-0">
          <?php foreach ($domains as $d): ?>
          <li data-home-tile data-domain="<?= e($d['key']) ?>" data-total="<?= (int)$d['n'] ?>" class="flex min-h-[44px] items-center gap-2.5">
            <?= icon($d['icon'], 'h-8 w-8 shrink-0') ?>
            <?php /* Inline text, as in the examples: the count follows the last word (no-break space); the 44 px target is padding on an inline link. */ ?>
            <span class="min-w-0"><a href="<?= e($d['href']) ?>" class="py-3 text-base font-semibold"><?= e($d['label']) ?></a><span data-tile-count class="text-sm text-ink-muted">&nbsp;(<?= e($fmt($d['n'])) ?>)</span></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div data-home-js hidden class="flex min-w-0 flex-col gap-2.5">
        <span class="g2-label">Posturi active pe județe</span>
        <div role="group" aria-label="Alege județul pe hartă" class="pg-map">
          <?php foreach (HOME_COUNTY_TILES as $code => [$slug, $col, $row]):
              $n = $county_total[$slug] ?? 0;
              $aria = $county_name($slug) . ': ' . posturi_label($n); ?>
          <button type="button" data-county="<?= e($slug) ?>" data-step="<?= home_map_step($n) ?>" aria-pressed="false"
                  aria-label="<?= e($aria) ?>" title="<?= e($aria) ?>" style="grid-column: <?= $col ?>; grid-row: <?= $row ?>"
                  class="pg-map-cell"><?= e($code) ?></button>
          <?php endforeach; ?>
        </div>
        <ul class="g2-text-meta flex flex-wrap items-center gap-x-3 gap-y-1">
          <?php foreach (['0–19', '20–39', '40–79', '80+'] as $step => $legend): ?>
          <li class="inline-flex items-center gap-1.5"><span aria-hidden="true" data-step="<?= $step ?>" class="pg-map-swatch"></span><?= e($legend) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <p data-home-live role="status" aria-live="polite" class="sr-only"></p>
    <script type="application/json" id="home-data"><?= json_encode($home_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_FORCE_OBJECT) ?></script>
  </section>

  <?php /* 3.4 Other starting points */ ?>
  <section aria-labelledby="h-start" class="flex flex-col">
    <h2 id="h-start" class="sr-only">Sau începe de aici</h2>
    <?php foreach ($groups as $g):
        if (!$g['items']) continue; ?>
    <?php /* One run of inline text: the label, then the links. A wrapped line starts at the left edge.
       Each link has 3 px of padding above and below (a target of at least 24 px) on a 28 px line: the targets of two lines do not touch. */ ?>
    <div class="py-1 leading-7">
      <h3 class="mr-3 inline font-bold"><?= e($g['title']) ?></h3>
      <ul class="inline">
        <?php foreach ($g['items'] as $it): ?>
        <li class="mr-5 inline"><a href="<?= e($it['href']) ?>" data-home-link class="py-[3px]"><?= e($it['label']) ?></a><span data-home-count class="g2-text-meta g2-mono">&nbsp;<?= e($fmt($it['n'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </section>

  <?php /* 3.5 New and closing soon */ ?>
  <?php if ($home_new || $home_soon): ?>
  <section aria-labelledby="h-now" class="flex flex-col gap-4">
    <h2 id="h-now" class="<?= $heading ?>">Noi și pe terminate</h2>
    <div class="grid grid-cols-[minmax(0,1fr)] gap-x-8 gap-y-6 md:grid-cols-2">
      <?php
        $new_word = $home_new_day === $today ? 'azi' : ($home_new_day !== '' ? fmt_date($home_new_day) : '');
        foreach ([
          ['Noi ' . $new_word, '/?published=' . rawurlencode($home_new_day), $home_new, false],
          ['Se închid în 3 zile', '/?status=soon&sort=deadline', $home_soon, true],
        ] as [$htitle, $hhref, $hrows, $show_deadline]):
          if (!$hrows) continue; ?>
      <div class="min-w-0">
        <h3 class="g2-heading-item mb-1">
          <a href="<?= e($hhref) ?>" class="g2-link-title inline-block py-2.5"><?= e($htitle) ?> <span aria-hidden="true">→</span></a>
        </h3>
        <ul class="text-sm">
          <?php foreach ($hrows as $hp):
              $hdt = display_title($hp); $hdl = posting_deadline($hp); ?>
          <li class="flex items-baseline justify-between gap-3 border-t border-line">
            <span class="min-w-0 truncate"><a href="<?= e(job_url($hp)) ?>" class="inline-block py-3 font-medium"><?= e($hdt['primary']) ?></a>
              <span class="text-xs text-ink-muted">· <?= e($hp['employer_name']) ?></span></span>
            <?php if ($show_deadline && $hdl['date']): ?>
              <span class="shrink-0 font-mono text-xs text-alert-ink"><?php $hd = (int)days_until($hdl['date']); ?><?= e($hd === 0 ? 'azi' : days_label($hd)) ?></span>
            <?php elseif ($place = place_label($hp)): ?>
              <span class="max-w-[40%] shrink-0 truncate text-xs text-ink-muted"><?= e($place) ?></span>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php /* 3.6 Notifications */ ?>
  <section aria-labelledby="h-follow" class="flex flex-col gap-4 bg-sunken p-5 sm:p-6">
    <h2 id="h-follow" class="<?= $heading ?>">Abonează-te la notificări</h2>
    <p class="max-w-2xl">Alerte pe email și RSS pentru orice combinație de filtre / căutări.</p>
    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
      <?php foreach ([
          ['i-mail', 'Email', 'Un rezumat la 3 zile cu posturile noi din căutarea ta.', 'Cum funcționează alertele', '/alerte/'],
          ['i-calendar', 'Calendar', 'Termenele de depunere și probele de concurs, în Google Calendar, Apple sau Outlook.', 'Calendarul tuturor posturilor', feed_url('posturi.ics')],
          ['i-feed', 'RSS', 'Un flux pentru cititorul tău de știri, pentru orice căutare.', 'Fluxul tuturor posturilor', feed_url('posturi.rss')],
      ] as [$ico, $ftitle, $ftext, $flink, $fhref]): ?>
      <div class="g2-tile flex flex-col gap-2 bg-surface p-4">
        <svg class="h-7 w-7 fill-none stroke-current stroke-2 [stroke-linecap:round] [stroke-linejoin:round]" aria-hidden="true" focusable="false"><use href="#<?= $ico ?>"/></svg>
        <h3 class="g2-heading-item"><?= e($ftitle) ?></h3>
        <p><?= e($ftext) ?></p>
        <a href="<?= e($fhref) ?>" class="inline-flex min-h-[44px] items-center"><?= e($flink) ?></a>
      </div>
      <?php endforeach; ?>
    </div>
    <p>Poți și să salvezi posturi și căutări în browser. Le găsești la <a href="/salvate/" class="inline-block py-3">Salvate</a>.</p>
  </section>

  <?php /* 3.7 Tools */ ?>
  <section aria-labelledby="h-tools" class="flex flex-col gap-4">
    <h2 id="h-tools" class="<?= $heading ?>">Instrumente</h2>
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
      <?php foreach ([
          ['Pot aplica?', 'Verifică dacă îndeplinești condițiile unui post.', '/pot-aplica/'],
          ['Pentru angajatori', 'Asistent pentru redactarea unui anunț de angajare.', '/pentru-angajatori/anunt/'],
      ] as [$ttitle, $ttext, $thref]): ?>
      <a href="<?= e($thref) ?>" class="g2-tile flex flex-col gap-1 p-4 text-ink no-underline hover:border-gov">
        <span class="g2-heading-item text-gov"><?= e($ttitle) ?></span>
        <span><?= e($ttext) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

</div>
<?php
require __DIR__ . '/../inc/footer.php';
timing_mark('render');
