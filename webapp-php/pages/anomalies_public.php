<?php
declare(strict_types=1);

/**
 * ANOMALY-PUBLIC — "Anomalii și abateri", and the render code of the admin page too.
 *
 * Public routes (2026-11-24 change, docs/specs/2026-11-23-anomaly-public.md):
 *   `/anomalii/`            every check of the register, the first 20 signals of each;
 *   `/anomalii/{ID}/`       the full list of one check, 50 signals a page (`?pagina=N`).
 * `/statistici/anomalii/…` redirects here (index.php). Everything is public and indexed.
 * `pages/anomalies_admin.php` sets `$anomaly_admin = true`, checks the login and requires this
 * file: the same layout, with the review numbers and the false-positive signals.
 * The export holds the signals in `anomaly_signals` and the checks in `anomaly_checks`
 * (ops/anomaly-survey.py --write-table). An older export has neither table: the page says so.
 * A signal with a `false_positive` review is hidden on the public page.
 */

$anomaly_admin = $anomaly_admin ?? false;
// Signal rows per check: the overview, the page of one check, the admin page.
const ANOMALY_OVERVIEW_ROWS = 20;
const ANOMALY_PAGE_ROWS = 50;
const ANOMALY_ADMIN_ROWS = 500;
// The feedback form of the footer (inc/footer.php). The form has no prefill: the link is plain.
const ANOMALY_FEEDBACK_URL = 'https://forms.gle/96WusM2qr4pbUXhW7';
// The groups, in page order: register `group` => heading.
const ANOMALY_GROUPS = [
    'erori_noastre' => 'Posibile erori în datele noastre',
    'erori_anunt'   => 'Posibile erori în anunț',
    'neobisnuit'    => 'Anunțuri neobișnuite',
    'angajator'     => 'Tipare la angajatori',
    'context'       => 'Context',
];
const ANOMALY_GROUP_OF_KIND = [
    'error_ours' => 'erori_noastre', 'error_posting' => 'erori_anunt', 'unusual' => 'neobisnuit',
    'unusual_employer' => 'angajator', 'context' => 'context',
];
// A signal with this review is hidden on the public page (the survey writes {"verdict": "false_positive"}).
const ANOMALY_FP_LIKE = '%"verdict": "false_positive"%';

function anomaly_group(array $c): string {
    return $c['group_id'] ?? ANOMALY_GROUP_OF_KIND[$c['kind']] ?? 'neobisnuit';
}

function anomaly_pct(int $count, int $den): string {
    if ($den <= 0) return '';
    return number_format(100 * $count / $den, 1, ',', '.') . '%';
}

/** The rows of one check, newest posting first. `$visible` = the signals that stay after the false positives. */
function anomaly_rows(string $id, bool $admin, int $limit, int $offset = 0): array {
    $where = $admin ? '' : ' AND s.detail_json NOT LIKE :fp';
    $stmt = db()->prepare("SELECT s.value, s.peer_value, s.detail_json, j.id, j.title, j.employer_name, j.published_at
        FROM anomaly_signals s JOIN job_postings j ON j.id = s.posting_id
        WHERE s.check_id = :id$where ORDER BY j.published_at DESC, j.id LIMIT $limit OFFSET $offset");
    $stmt->bindValue(':id', $id);
    if (!$admin) $stmt->bindValue(':fp', ANOMALY_FP_LIKE);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Signals per check that a reader sees: check id => count. */
function anomaly_visible_counts(bool $admin): array {
    $where = $admin ? '' : ' WHERE s.detail_json NOT LIKE :fp';
    $stmt = db()->prepare("SELECT s.check_id, COUNT(*) FROM anomaly_signals s
        JOIN job_postings j ON j.id = s.posting_id$where GROUP BY s.check_id");
    if (!$admin) $stmt->bindValue(':fp', ANOMALY_FP_LIKE);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** The quote block of one signal: the paragraph of the text, or the calendar line(s). '' when there is none. */
function anomaly_quote(?string $detail_json): string {
    $d = json_decode((string)$detail_json, true);
    if (!is_array($d)) return '';
    if (isset($d['excerpt']) && is_string($d['excerpt']) && $d['excerpt'] !== '') {
        $t = $d['excerpt'];
        $a = (int)($d['excerpt_start'] ?? 0);
        $z = (int)($d['excerpt_end'] ?? 0);
        $body = ($d['cut_start'] ?? false ? '… ' : '')
            . e(mb_substr($t, 0, $a)) . '<mark>' . e(mb_substr($t, $a, $z - $a)) . '</mark>' . e(mb_substr($t, $z))
            . ($d['cut_end'] ?? false ? ' …' : '');
        return '<blockquote class="pg-quote"><p>' . $body . '</p><footer>din textul anunțului</footer></blockquote>';
    }
    if (!empty($d['calendar']) && is_array($d['calendar'])) {
        $lines = '';
        foreach ($d['calendar'] as $l) {
            if (!is_array($l)) continue;
            $label = trim((string)($l['eveniment'] ?? ''));
            $when = trim((string)($l['data'] ?? '') . ' ' . (string)($l['ora'] ?? ''));
            $lines .= '<p>' . ($label !== '' ? e($label) : '<i>(fără denumire)</i>') . ': <mark>' . e($when) . '</mark></p>';
        }
        return $lines === '' ? '' : '<blockquote class="pg-quote">' . $lines . '<footer>din calendarul concursului</footer></blockquote>';
    }
    return '';
}

/** One signal. */
function anomaly_render_signal(array $r, array $c, bool $admin): void {
    $fp = str_contains((string)$r['detail_json'], '"verdict": "false_positive"');
    $value = trim((string)$r['value']);
    $peer = trim((string)$r['peer_value']);
    ?>
    <li class="pg-signal">
      <p class="font-semibold text-ink"><a href="<?= e(job_url($r)) ?>" class="text-gov hover:underline"><?= e($r['title']) ?></a></p>
      <p class="text-sm text-ink-muted"><?= e($r['employer_name']) ?> · publicat <?= e(fmt_date($r['published_at'])) ?></p>
      <?php if ($value !== '' || $peer !== ''): ?>
      <p class="text-sm text-ink mt-1">
        <?= $value !== '' ? 'Valoare: <strong>' . e($value) . '</strong>' : '' ?><?= $value !== '' && $peer !== '' ? ' · ' : '' ?><?= $peer !== '' ? 'Comparație: ' . e($peer) : '' ?><?= $admin && $fp ? ' (fals pozitiv)' : '' ?>
      </p>
      <?php endif; ?>
      <?= anomaly_quote($r['detail_json']) ?>
      <p class="text-xs mt-1"><a href="<?= e(ANOMALY_FEEDBACK_URL) ?>" target="_blank" rel="noopener" class="text-gov hover:underline"
        aria-label="Raportează o greșeală: <?= e($c['check_id'] . ', ' . $r['title']) ?>">Raportează o greșeală</a></p>
    </li>
    <?php
}

/** One check: label, explanation, figures, caveat and a list of signals. */
function anomaly_render_check(array $c, array $rows, int $visible, bool $admin, bool $full): void {
    $id = (string)$c['check_id'];
    $has_count = $c['count'] !== null;
    $den = (int)($c['denominator'] ?? 0);
    $caveat = trim((string)($c['caveat_ro'] ?? ''));
    ?>
    <article class="bg-surface border border-line rounded-lg p-5 mb-4" id="check-<?= e($id) ?>">
      <h3 class="font-display text-lg italic font-semibold text-ink">
        <?= $admin ? '<span class="font-mono text-sm not-italic text-ink-muted">' . e($id) . '</span> ' : '' ?><?= e($c['label_ro']) ?>
      </h3>
      <p class="text-sm text-ink mt-2"><?= e($c['explain_ro']) ?></p>
      <?php if ($caveat !== ''): ?>
        <p class="bg-note border border-note-line text-note-ink text-sm mt-2 p-2"><strong>Atenție.</strong> <?= e($caveat) ?></p>
      <?php endif; ?>
      <p class="text-xs text-ink-muted mt-2">
        <?php if (!$has_count): ?>
          Verificarea nu a putut rula pe acest export.
        <?php else: ?>
          <strong><?= $visible ?></strong> semnale<?= $den > 0 ? ' din ' . $den . ' de elemente verificate (' . e(anomaly_pct($visible, $den)) . ')' : '' ?>.
        <?php endif; ?>
      </p>
      <?php if ($admin): ?>
        <p class="text-xs text-ink-muted mt-1">
          Citite: <?= (int)$c['reviewed'] ?>; reale: <?= (int)$c['real'] ?>; fals pozitive: <?= (int)$c['false_positive'] ?>.
        </p>
      <?php endif; ?>
      <?php if ($rows): ?>
      <ul class="pg-signals mt-3" aria-label="Semnale: <?= e($c['label_ro']) ?>">
        <?php foreach ($rows as $r) anomaly_render_signal($r, $c, $admin); ?>
      </ul>
      <?php if (!$full && $visible > count($rows)): ?>
        <p class="text-sm mt-3"><a href="/anomalii/<?= e($id) ?>/" class="text-gov hover:underline">Toate cele <?= $visible ?> semnale →</a></p>
      <?php endif; ?>
      <?php endif; ?>
    </article>
    <?php
}

/** The table of contents: groups, checks and their counts. Printed twice (a block below lg, a sidebar from lg). */
function anomaly_render_toc(array $by_group, array $visible_counts): void {
    ?>
    <ul>
      <?php foreach ($by_group as $g => $checks): ?>
      <li class="pg-atoc-group">
        <a href="#grup-<?= e($g) ?>" class="pg-atoc-g"><?= e(ANOMALY_GROUPS[$g]) ?></a>
        <ul>
          <?php foreach ($checks as $c): $n = $c['count'] === null ? '–' : (string)($visible_counts[$c['check_id']] ?? 0); ?>
          <li><a href="#check-<?= e($c['check_id']) ?>"><span><?= e($c['label_ro']) ?></span> <span class="pg-atoc-n"><?= e($n) ?></span></a></li>
          <?php endforeach; ?>
        </ul>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php
}

$anomaly_checks = [];
$anomaly_missing = false;
try {
    $anomaly_checks = db()->query("SELECT * FROM anomaly_checks ORDER BY check_id")->fetchAll();
    $anomaly_visible = anomaly_visible_counts($anomaly_admin);
} catch (PDOException $ex) {
    $anomaly_missing = true;   // an export from before ANOMALY-PUBLIC
    $anomaly_visible = [];
}

// `/anomalii/{ID}/`: one check, with the full list.
$anomaly_one = (!$anomaly_admin && isset($_GET['check'])) ? (string)$_GET['check'] : '';
if ($anomaly_one !== '') {
    $anomaly_checks = array_values(array_filter($anomaly_checks, fn ($c) => $c['check_id'] === $anomaly_one));
    if (!$anomaly_checks && !$anomaly_missing) {
        http_response_code(404);
        $page_title = '404';
        require __DIR__ . '/../inc/header.php';
        echo '<div class="max-w-screen-xl mx-auto px-4 py-16 text-center">';
        echo '<h1 class="font-display text-4xl italic text-ink-faint mb-4">404</h1>';
        echo '<p class="text-ink-muted">Verificarea nu există.</p>';
        echo '<a href="/anomalii/" class="mt-4 inline-block text-gov hover:underline">← Anomalii și abateri</a></div>';
        require __DIR__ . '/../inc/footer.php';
        return;
    }
}

$by_group = [];
foreach (ANOMALY_GROUPS as $g => $_label) {
    $in = array_values(array_filter($anomaly_checks, fn ($c) => anomaly_group($c) === $g));
    if ($in) $by_group[$g] = $in;
}

$anomaly_page = max(1, (int)($_GET['pagina'] ?? 1));
$anomaly_data_date = build_time('d.m.Y');
$page_title = $anomaly_admin ? 'Semnale: toate verificările'
    : ($anomaly_one !== '' && $anomaly_checks ? $anomaly_checks[0]['label_ro'] . ' — Anomalii și abateri' : 'Anomalii și abateri');
$meta_description = 'Semnale calculate din datele publice ale anunțurilor: valori care diferă de anunțuri asemănătoare. Sunt statistici, nu acuzații.';
if ($anomaly_admin) {
    $head_extra = '<meta name="robots" content="noindex">';
} elseif ($anomaly_one !== '') {
    $canonical_path = '/anomalii/' . $anomaly_one . '/';
}
require __DIR__ . '/../inc/header.php';
?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-10">
  <h1 class="font-display text-[2rem] italic font-semibold text-ink mb-6"><?= $anomaly_admin ? 'Semnale: toate verificările' : 'Anomalii și abateri' ?></h1>

  <?php if ($anomaly_one === ''): ?>
  <div class="g2-inset mb-6" id="explicatie">
    <p>Un semnal arată un anunț care diferă de anunțuri asemănătoare, sau o valoare care pare greșită.
      Programul calculează fiecare semnal din datele publice, cu aceeași regulă pentru toate anunțurile.</p>
    <p class="mt-2"><strong>Semnalele sunt statistici din date publice, nu acuzații.</strong>
      Comparația este cu anunțuri asemănătoare. Un semnal nu spune nimic despre intenția cuiva.
      Verificați întotdeauna anunțul oficial.</p>
    <p class="mt-2">Algoritmul poate greși. Dacă vedeți o greșeală, spuneți-ne: vrem să aflăm.
      <a href="<?= e(ANOMALY_FEEDBACK_URL) ?>" target="_blank" rel="noopener" class="text-gov underline">Formularul de raportare</a>.</p>
    <?php if ($anomaly_data_date !== ''): ?>
      <p class="mt-2 text-sm text-ink-muted">Datele sunt din <?= e($anomaly_data_date) ?>.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($anomaly_missing): ?>
    <p class="text-sm text-ink-muted">Acest export nu conține încă semnale.</p>
  <?php else: ?>
    <div class="pg-toc-layout">
      <?php if ($anomaly_one === ''): ?>
      <details class="pg-atoc-small" data-atoc-small>
        <summary>Cuprins</summary>
        <nav aria-label="Cuprins" data-atoc><?php anomaly_render_toc($by_group, $anomaly_visible); ?></nav>
      </details>
      <nav aria-label="Cuprins" class="pg-atoc-side" data-atoc><p class="pg-toc-title">Cuprins</p><?php anomaly_render_toc($by_group, $anomaly_visible); ?></nav>
      <?php endif; ?>
      <div class="min-w-0<?= $anomaly_one !== '' ? ' lg:col-span-2' : '' ?>">
      <?php foreach ($by_group as $g => $group): ?>
        <section class="mb-8" aria-labelledby="grup-<?= e($g) ?>">
          <h2 class="font-display text-xl italic font-semibold text-ink mb-3" id="grup-<?= e($g) ?>"><?= e(ANOMALY_GROUPS[$g]) ?></h2>
          <?php foreach ($group as $c):
              $vis = (int)($anomaly_visible[$c['check_id']] ?? 0);
              if ($anomaly_one !== '') {
                  $rows = anomaly_rows($c['check_id'], $anomaly_admin, ANOMALY_PAGE_ROWS, ($anomaly_page - 1) * ANOMALY_PAGE_ROWS);
              } else {
                  $rows = anomaly_rows($c['check_id'], $anomaly_admin, $anomaly_admin ? ANOMALY_ADMIN_ROWS : ANOMALY_OVERVIEW_ROWS);
              }
              anomaly_render_check($c, $rows, $vis, $anomaly_admin, $anomaly_one !== '');
              if ($anomaly_one !== ''):
                  $pages = max(1, (int)ceil($vis / ANOMALY_PAGE_ROWS)); ?>
                <?php if ($pages > 1): ?>
                <nav aria-label="Paginare" class="flex flex-wrap gap-4 text-sm mb-4">
                  <?php if ($anomaly_page > 1): ?><a href="?pagina=<?= $anomaly_page - 1 ?>" rel="prev" class="text-gov hover:underline">← Pagina anterioară</a><?php endif; ?>
                  <span>Pagina <?= $anomaly_page ?> din <?= $pages ?></span>
                  <?php if ($anomaly_page < $pages): ?><a href="?pagina=<?= $anomaly_page + 1 ?>" rel="next" class="text-gov hover:underline">Pagina următoare →</a><?php endif; ?>
                </nav>
                <?php endif; ?>
              <?php endif; ?>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <p class="text-sm mt-6">
    <?php if ($anomaly_one !== ''): ?><a href="/anomalii/" class="text-gov hover:underline">← Anomalii și abateri</a>
    <?php else: ?><a href="/statistici/" class="text-gov hover:underline">← Statistici</a><?php endif; ?>
  </p>
</div>
<?php if ($anomaly_one === '' && !$anomaly_missing): ?>
<script>
(function () {
  // Marks the section in view in both lists of the table of contents. Without script the lists work and have no mark.
  var navs = document.querySelectorAll('[data-atoc]');
  if (!navs.length || !('IntersectionObserver' in window)) return;
  var links = [];
  navs.forEach(function (n) { n.querySelectorAll('a[href^="#"]').forEach(function (a) { links.push(a); }); });
  var targets = links.map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); });
  function update() {
    var line = window.innerHeight * 0.15, cur = -1;
    targets.forEach(function (t, i) { if (t && t.getBoundingClientRect().top <= line) cur = i; });
    var id = cur >= 0 ? links[cur].getAttribute('href') : null;
    links.forEach(function (a) {
      if (a.getAttribute('href') === id) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
    });
  }
  var io = new IntersectionObserver(update, { rootMargin: '0px 0px -85% 0px', threshold: [0, 1] });
  targets.forEach(function (t) { if (t) io.observe(t); });
  window.addEventListener('scroll', update, { passive: true });
  update();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../inc/footer.php';
