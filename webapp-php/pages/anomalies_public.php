<?php
declare(strict_types=1);

/**
 * ANOMALY-PUBLIC — the anomaly signals page, and the render code of the admin page too.
 *
 * Public route `/statistici/anomalii/`: only checks whose register state is `published`.
 * `pages/anomalies_admin.php` sets `$anomaly_admin = true`, checks the login and requires this
 * file: the same layout shows every check, with its state and its review numbers.
 * The export holds the signals in `anomaly_signals` and the checks in `anomaly_checks`
 * (ops/anomaly-survey.py --write-table). An older export has neither table: the page says so.
 */

$anomaly_admin = $anomaly_admin ?? false;
$anomaly_state_ro = ['hidden' => 'ascuns', 'listed' => 'în verificare', 'published' => 'publicat'];
// Signal rows shown per check on the admin page; the page says when it cuts a list.
const ANOMALY_ADMIN_ROWS = 500;

/** Is the check ready to publish? Review threshold: 10 samples (or all) and 20% false positives or fewer. */
function anomaly_ready(array $c): bool {
    $count = (int)($c['count'] ?? 0);
    $rev = (int)$c['reviewed'];
    if ($count <= 0 || $rev < min(10, $count)) return false;
    return (int)$c['false_positive'] <= 0.2 * $rev;
}

function anomaly_pct(array $c): string {
    $den = (int)($c['denominator'] ?? 0);
    if ($den <= 0 || $c['count'] === null) return '';
    return number_format(100 * (int)$c['count'] / $den, 1, ',', '.') . '%';
}

/** One check: label, explanation, figures and the list of signals. */
function anomaly_render_check(array $c, bool $admin, array $state_ro): void {
    $id = (string)$c['check_id'];
    $count = $c['count'] === null ? null : (int)$c['count'];
    $ours = $c['kind'] === 'error_ours';
    $where = $admin ? '' : " AND s.detail_json NOT LIKE '%false_positive%'";
    $limit = $admin ? ANOMALY_ADMIN_ROWS : 200;
    $stmt = db()->prepare("SELECT s.value, s.peer_value, s.detail_json, j.id, j.title, j.employer_name, j.published_at
        FROM anomaly_signals s JOIN job_postings j ON j.id = s.posting_id
        WHERE s.check_id = ?$where ORDER BY j.published_at DESC, j.id LIMIT $limit");
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll();
    ?>
    <article class="bg-surface border border-line rounded-lg p-5 mb-4" id="check-<?= e($id) ?>">
      <h3 class="font-display text-lg italic font-semibold text-ink">
        <?= $admin ? '<span class="font-mono text-sm not-italic text-ink-muted">' . e($id) . '</span> ' : '' ?><?= e($c['label_ro']) ?>
      </h3>
      <?php if ($ours): ?>
        <p class="text-xs text-note-ink mt-1">Posibilă eroare în datele noastre</p>
      <?php endif; ?>
      <p class="text-sm text-ink mt-2"><?= e($c['explain_ro']) ?></p>
      <p class="text-xs text-ink-muted mt-2">
        <?php if ($count === null): ?>
          Verificarea nu a putut rula pe acest export.
        <?php else: ?>
          <?= $count ?> semnale din <?= (int)$c['denominator'] ?> de elemente verificate<?= anomaly_pct($c) !== '' ? ' (' . e(anomaly_pct($c)) . ')' : '' ?>.
        <?php endif; ?>
      </p>
      <?php if ($admin): ?>
        <p class="text-xs text-ink-muted mt-1">
          Stare: <strong><?= e($state_ro[$c['state']] ?? $c['state']) ?></strong>.
          Citite: <?= (int)$c['reviewed'] ?>; reale: <?= (int)$c['real'] ?>; fals pozitive: <?= (int)$c['false_positive'] ?>.
          <?php if (anomaly_ready($c)): ?><strong class="text-ok-ink">Gata de publicare.</strong><?php endif; ?>
        </p>
      <?php endif; ?>
      <?php if ($rows): ?>
      <details class="mt-3"<?= $admin ? '' : ' open' ?>>
        <summary class="text-sm text-gov cursor-pointer">Lista de semnale (<?= count($rows) ?><?= $count !== null && $count > count($rows) ? ' din ' . $count : '' ?>)</summary>
        <div class="g2-table-wrap mt-2" role="region" aria-label="Semnale: <?= e($c['label_ro']) ?>" tabindex="0">
          <table class="g2-table g2-table-stacked" role="table">
            <caption class="sr-only">Semnale: <?= e($c['label_ro']) ?></caption>
            <thead role="rowgroup"><tr role="row">
              <th scope="col" role="columnheader">Anunț</th><th scope="col" role="columnheader">Angajator</th>
              <th scope="col" role="columnheader">Publicat</th><th scope="col" role="columnheader">Valoare</th>
              <th scope="col" role="columnheader">Comparație</th>
            </tr></thead>
            <tbody role="rowgroup">
              <?php foreach ($rows as $r): ?>
              <tr role="row">
                <th scope="row" role="rowheader" data-label="Anunț"><a href="<?= e(job_url($r)) ?>" class="text-gov hover:underline"><?= e($r['title']) ?></a></th>
                <td role="cell" data-label="Angajator"><?= e($r['employer_name']) ?></td>
                <td role="cell" data-label="Publicat"><?= e(fmt_date($r['published_at'])) ?></td>
                <td role="cell" data-label="Valoare"><?= e($r['value']) ?><?= $admin && str_contains((string)$r['detail_json'], 'false_positive') ? ' (fals pozitiv)' : '' ?></td>
                <td role="cell" data-label="Comparație"><?= e($r['peer_value']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
      <?php endif; ?>
    </article>
    <?php
}

$anomaly_checks = [];
$anomaly_missing = false;
try {
    $anomaly_checks = db()->query("SELECT * FROM anomaly_checks ORDER BY check_id")->fetchAll();
} catch (PDOException $ex) {
    $anomaly_missing = true;   // an export from before ANOMALY-PUBLIC
}
if (!$anomaly_admin) {
    $anomaly_checks = array_values(array_filter($anomaly_checks, fn ($c) => $c['state'] === 'published'));
}
$anomaly_sections = [
    'Posibile erori' => fn ($c) => str_starts_with($c['kind'], 'error'),
    'Posturi neobișnuite' => fn ($c) => str_starts_with($c['kind'], 'unusual'),
];
if ($anomaly_admin) {
    $anomaly_sections['Context'] = fn ($c) => $c['kind'] === 'context';
}

$page_title = $anomaly_admin ? 'Semnale: toate verificările' : 'Semnale în anunțuri';
$meta_description = 'Semnale calculate din datele anunțurilor: valori care diferă de un termen de comparație.';
if ($anomaly_admin) {
    $head_extra = '<meta name="robots" content="noindex">';
}
require __DIR__ . '/../inc/header.php';
?>
<div class="max-w-screen-lg mx-auto px-4 sm:px-6 py-10">
  <h1 class="font-display text-[2rem] italic font-semibold text-ink mb-6"><?= $anomaly_admin ? 'Semnale: toate verificările' : 'Semnale în anunțuri' ?></h1>

  <div class="g2-inset mb-6">
    <p>Un semnal arată o valoare care diferă de un termen de comparație. Programul calculează
      fiecare semnal din datele anunțului, cu aceeași regulă pentru toate anunțurile.
      Un semnal nu dovedește o eroare și nu spune nimic despre intenția cuiva.
      Verificați întotdeauna anunțul oficial.</p>
  </div>

  <?php if ($anomaly_missing): ?>
    <p class="text-sm text-ink-muted">Acest export nu conține încă semnale.</p>
  <?php elseif (!$anomaly_checks): ?>
    <p class="text-sm text-ink">Semnalele sunt în curs de verificare. Le publicăm după ce un om citește exemple din fiecare verificare.</p>
  <?php else: ?>
    <?php foreach ($anomaly_sections as $heading => $match): ?>
      <?php $group = array_values(array_filter($anomaly_checks, $match)); ?>
      <?php if (!$group) continue; ?>
      <section class="mb-8">
        <h2 class="font-display text-xl italic font-semibold text-ink mb-3"><?= e($heading) ?></h2>
        <?php foreach ($group as $c) anomaly_render_check($c, $anomaly_admin, $anomaly_state_ro); ?>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>

  <p class="text-sm mt-6"><a href="/statistici/" class="text-gov hover:underline">← Statistici</a></p>
</div>
<?php require __DIR__ . '/../inc/footer.php';
