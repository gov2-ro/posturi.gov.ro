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

// ---- Chart: postings published in each of the last 12 complete ISO weeks (partials/weekly_chart.php) ----
$weekly = home_weekly_postings(12);

// ---- Ready-made searches ----
$combos = [
    ['title' => 'Peste 5.000 lei net, în afara sectorului medical',
     'params' => ['salary_bucket' => 'net-peste-5000', 'isced_not' => ['09_sanatate_asistenta_sociala']],
     'tags' => ['peste 5.000 lei net', 'fără Sănătate și asistență socială']],
    ['title' => '3.000–4.000 lei net, normă parțială, pe proiecte PNRR sau europene',
     'params' => ['salary_bucket' => 'net-3000-4000', 'schedule' => ['norma_partiala'], 'funding' => ['pnrr', 'fonduri_europene']],
     'tags' => ['3.000–4.000 lei net', 'normă parțială', 'PNRR', 'fonduri europene']],
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
$groups[] = ['title' => 'Studii', 'items' => $studies];

// Salary: the four net buckets, each with the result count of its link (not "fără salariu estimat").
$salaries = [];
foreach (SALARY_NET_BUCKETS as $bk) {
    $n = home_count_for(['salary_bucket' => $bk['key']]);
    if ($n > 0) $salaries[] = ['label' => $bk['label'], 'n' => $n, 'href' => home_list_url(['salary_bucket' => $bk['key']])];
}
$groups[] = ['title' => 'Salariu', 'items' => $salaries];

$occupations = [];
foreach (db()->query(
    "SELECT occ_canonical AS v, COUNT(*) AS c FROM job_postings
     WHERE occ_canonical <> '' AND $live GROUP BY 1 ORDER BY 2 DESC, 1 LIMIT 8") as $r) {
    $occupations[] = ['label' => $r['v'], 'n' => (int)$r['c'], 'href' => home_list_url(['occupation' => [$r['v']]])];
}
$groups[] = ['title' => 'Ocupație', 'items' => $occupations];

$groups[] = ['title' => 'Domeniu angajator', 'items' => array_map(
    fn($x) => ['label' => $x['label'], 'n' => $x['n'], 'href' => home_list_url(['sector' => [$x['val']]])], $sectors)];

// Institution type (the filter "Instituție", `inst[]`, EMPLOYER_KIND_LABELS): each value with a count above 0, largest first.
$insts = [];
foreach (db()->query(
    "SELECT employer_kind AS v, COUNT(*) AS c FROM job_postings
     WHERE employer_kind <> '' AND $live GROUP BY 1") as $r) {
    if (!isset(EMPLOYER_KIND_LABELS[$r['v']])) continue;
    $label = EMPLOYER_KIND_LABELS[$r['v']];
    $insts[] = ['label' => $label, 'n' => (int)$r['c'], 'href' => home_list_url(['inst' => [$r['v']]])];
}
usort($insts, fn($a, $b) => $b['n'] <=> $a['n'] ?: strcmp($a['label'], $b['label']));
$groups[] = ['title' => 'Tip instituție', 'items' => $insts];

$other = [];
foreach ([
    ['Fără experiență', ['exp_level' => ['fara']]],
    ['Peste 5.000 lei net', ['salary_bucket' => 'net-peste-5000']],
    ['Funcții de conducere', ['level' => ['Funcții de conducere']]],
    ['Perioadă determinată', ['duration' => ['determinata']]],
    ['Normă parțială', ['schedule' => ['norma_partiala']]],
    ['Fonduri europene', ['funding' => ['fonduri_europene', 'pnrr']]],
] as [$label, $params]) {
    $n = home_count_for($params);
    if ($n > 0) $other[] = ['label' => $label, 'n' => $n, 'href' => home_list_url($params)];
}
$groups[] = ['title' => 'Alte filtre', 'items' => $other];

// ---- "Noi azi" and "Se închid în 3 zile" (moved from the list page, rules unchanged; see home_new_soon()) ----
$ns = ['id' => 'now', 'base' => []] + home_new_soon([], $today);
timing_mark('home');

// ---- Page ----
$page_title       = 'Anunțuri de angajare în administrația publică';
$meta_description = 'Explorator independent pentru anunțurile de angajare din sectorul public românesc: '
                  . 'căutare full-text, filtre pe județ, domeniu, grad și termen limită.';
$canonical_path   = '/';
$head_extra       = '<script src="/static/home.js?v=' . (@filemtime(__DIR__ . '/../static/home.js') ?: '1') . '" defer></script>';
require __DIR__ . '/../inc/header.php';

$home_data = ['total' => $active_total, 'counties' => $county_total, 'items' => []];
foreach ($domains as $d) $home_data['items'][$d['key']] = ['params' => home_item_fields($d['params']), 'counties' => $d['counties']];
$heading = 'g2-heading-section';
?>
<div class="g2-container flex flex-col gap-12 pb-14">

  <?php /* 3.1 Hero and figures */ ?>
  <section aria-labelledby="h-hero" class="pt-8">
    <div class="grid gap-8 lg:grid-cols-2 lg:items-start lg:gap-12">
      <div class="flex min-w-0 flex-col gap-4">
    <?php /* The title sits in the left column, above the search, and is smaller than the DS size so that it fits there. */ ?>
    <h1 id="h-hero" class="g2-heading-page mb-1 text-xl leading-tight sm:text-2xl">Anunțuri de angajare în administrația publică</h1>
        <?php /* Empty form: with JavaScript, static/home.js sends the reader to /posturi/ (or to the
           filled fields only). Without it, the server (index.php) redirects a request whose
           values are all blank to /posturi/ and drops blank values from the rest. */ ?>
        <?php $hs = ['placeholder' => 'Caută: asistent medical, consilier, șofer…', 'counts' => $county_total, 'hidden' => []];
              require __DIR__ . '/../partials/home_search.php'; ?>
        <p class="flex flex-wrap items-center gap-x-5 gap-y-0"><span class="inline-flex items-center gap-1.5"><a href="/posturi/" class="inline-flex min-h-[44px] items-center font-semibold">Toate anunțurile</a> <span data-home-all-count class="text-ink-muted">(<?= e($fmt($active_total)) ?>)</span></span>
          <a href="#domenii" data-home-to-domains class="inline-flex min-h-[44px] items-center font-semibold">După domeniu <span aria-hidden="true">↓</span></a></p>
        <p class="g2-text-meta">Date preluate de pe <a href="https://posturi.gov.ro" class="-my-3 inline-block py-3">posturi.gov.ro</a>. Proiectul <mark><strong>nu este afiliat</strong> Guvernului României</mark>.</p>
      </div>

      <div data-home-figures class="flex min-w-0 flex-col gap-5">
        <h2 class="sr-only">Cifre</h2>
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
          <?php $figs = [
              ['n' => $active_total, 'label' => 'posturi active', 'href' => '/posturi/'],
              ['n' => $employers_active, 'label' => 'angajatori', 'href' => '/angajatori/'],
              ['n' => $new_7days, 'label' => 'noi în 7 zile', 'href' => '/posturi/'],
          ];
                require __DIR__ . '/../partials/figures.php'; ?>
          <a href="/statistici/" data-home-stats class="inline-flex min-h-[44px] items-center gap-1.5 font-semibold sm:ml-auto"><?= icon('i-chart-bar', 'h-5 w-5 shrink-0') ?>Statistici <span aria-hidden="true">→</span></a>
        </div>
        <?php $wc = ['weekly' => $weekly, 'caption' => 'Posturi publicate pe săptămână'];
              require __DIR__ . '/../partials/weekly_chart.php'; ?>
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
          <?php $cs = ['id' => 'domenii-judet', 'name' => null, 'class' => 'g2-input min-h-[44px] min-w-[14rem]', 'counts' => $county_total];
                require __DIR__ . '/../partials/county_select.php'; ?>
        </div>
        <ul class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,15rem),1fr))] gap-x-4 gap-y-0">
          <?php foreach ($domains as $d):
              $ci = ['id' => $d['key'], 'label' => $d['label'], 'href' => $d['href'], 'n' => $d['n'], 'icon' => $d['icon']];
              require __DIR__ . '/../partials/count_item.php';
          endforeach; ?>
        </ul>
        <?php /* One line below the grid: the themed pages (inc/themes.php). One run of inline text, as the starting-point groups. */ ?>
        <div data-home-themes class="py-1 leading-7">
          <h3 class="mr-3 inline font-bold">Pagini tematice</h3>
          <ul class="inline">
            <?php foreach (themes() as $_th_slug => $_th): ?>
            <li class="mr-5 inline"><a href="/<?= e($_th_slug) ?>/" data-home-link class="py-[3px]"><?= e($_th['label']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>

      <?php $cm_counts = $county_total;
            require __DIR__ . '/../partials/county_map.php'; ?>
    </div>
    <?php require __DIR__ . '/../partials/home_data.php'; ?>
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
  <?php require __DIR__ . '/../partials/new_soon.php'; ?>

  <?php /* 3.6 Notifications */ ?>
  <?php $fc = ['id' => 'follow', 'calendar' => [feed_url('posturi.ics'), 'Calendarul tuturor posturilor'], 'rss' => [feed_url('posturi.rss'), 'Fluxul tuturor posturilor']];
        require __DIR__ . '/../partials/follow_cards.php'; ?>

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
