<?php
/**
 * /salarii-declarate/ — the institutions with pay from the asset declarations (SALARY-DECLARATII-PAGE,
 * ADR 0008 rule 9). GET only, no JavaScript: `q` (part of the name), `judet` (county slug), `pagina`.
 * 50 institutions per page, sorted by name. Fields and pagination are the DS components.
 */
declare(strict_types=1);

const DECLARED_PER_PAGE = 50;

$q = trim((string)($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 80);
// `judet` is a facet to the query decoder (an array); this form has one county.
$judet_raw = $_GET['judet'] ?? '';
$judet = trim((string)(is_array($judet_raw) ? ($judet_raw[0] ?? '') : $judet_raw));
$pagina = max(1, (int)($_GET['pagina'] ?? 1));

$available = declared_available();
$counties = $available ? db()->query("SELECT judet_slug AS slug, judet AS name FROM declared_institutions GROUP BY judet_slug ORDER BY judet")->fetchAll() : [];
if ($judet !== '' && !in_array($judet, array_column($counties, 'slug'), true)) $judet = '';

$total = 0; $rows = []; $pages = 1;
if ($available) {
    // 2,100 short rows: filter and sort in PHP, so that "Găiceana" finds "Gaiceana" (the names have no diacritics).
    $st = db()->prepare("SELECT slug, name, judet, n_total, groups FROM declared_institutions" . ($judet !== '' ? " WHERE judet_slug = ?" : ""));
    $st->execute($judet !== '' ? [$judet] : []);
    $all = $st->fetchAll();
    $words = $q === '' ? [] : array_values(array_filter(explode(' ', salary_est_norm($q))));
    $hits = [];
    foreach ($all as $r) {
        $key = salary_est_norm($r['name']);
        foreach ($words as $w) if (!str_contains($key, $w)) continue 2;
        $hits[] = [$key, $r];
    }
    usort($hits, fn($a, $b) => [$a[0], $a[1]['slug']] <=> [$b[0], $b[1]['slug']]);
    $total = count($hits);
    $pages = max(1, (int)ceil($total / DECLARED_PER_PAGE));
    $pagina = min($pagina, $pages);
    $rows = array_map(fn($h) => $h[1], array_slice($hits, ($pagina - 1) * DECLARED_PER_PAGE, DECLARED_PER_PAGE));
}

/** The address of this index with the current filters and a page number. */
$page_url = static function (int $n) use ($q, $judet): string {
    $p = [];
    if ($q !== '') $p['q'] = $q;
    if ($judet !== '') $p['judet'] = $judet;
    if ($n > 1) $p['pagina'] = $n;
    return '/salarii-declarate/' . ($p ? '?' . http_build_query($p) : '');
};
$filtered = $q !== '' || $judet !== '';

$page_title = 'Salarii declarate';
$meta_description = 'Cât câștigă funcționarii publici, pe instituții și funcții, după declarațiile de avere din 2023, aduse la nivelul din 2026.';
require __DIR__ . '/../inc/header.php';
?>
<div class="w-full max-w-5xl px-4 sm:px-6 py-6" data-declared-index>
  <h1 class="g2-heading-page">Salarii declarate</h1>
  <p class="mt-2 max-w-3xl">Această pagină arată cât câștigă funcționarii publici, pe instituții și pe funcții.
    Cifrele vin din declarațiile de avere din 2023, aduse la nivelul din 2026.
    Personalul contractual nu apare aici.
    <a href="/estimator-salariu/#metoda-declaratii" class="text-gov underline" data-pay-method-link>Metoda pe larg →</a></p>

<?php if (!$available): ?>
  <p class="mt-6" data-declared-unavailable>Datele nu sunt disponibile acum.</p>
<?php else: ?>
  <form method="get" action="/salarii-declarate/" class="mt-6 flex flex-wrap items-end gap-4" role="search" data-declared-form>
    <div class="g2-field w-full max-w-md">
      <label class="g2-label" for="dc-q">Numele instituției</label>
      <input class="g2-input" id="dc-q" name="q" type="search" value="<?= e($q) ?>" maxlength="80" autocomplete="off" placeholder="ex. Comuna Găiceana, Spitalul, Poliția">
    </div>
    <div class="g2-field w-full max-w-xs">
      <label class="g2-label" for="dc-judet">Județul</label>
      <select class="g2-input" id="dc-judet" name="judet">
        <option value="">Toate județele</option>
        <?php foreach ($counties as $c): ?>
        <option value="<?= e($c['slug']) ?>"<?= $judet === $c['slug'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="g2-btn" type="submit">Caută</button>
  </form>

  <p class="g2-text-meta mt-4" role="status" data-declared-count><?= $total === 0 ? 'Nicio instituție.' : e(ro_count_de($total, 'instituții')) ?></p>

<?php if ($total === 0): ?>
  <p class="mt-2" data-declared-empty>Nicio instituție nu corespunde căutării.<?php if ($filtered): ?> <a href="/salarii-declarate/" class="text-gov underline">Șterge filtrele</a>.<?php endif; ?></p>
<?php else: ?>
  <div class="g2-table-wrap mt-3" role="region" aria-label="Instituții cu salarii declarate" tabindex="0">
    <table class="g2-table g2-table-stacked" role="table">
      <caption class="g2-text-meta">Instituții, în ordine alfabetică.</caption>
      <thead role="rowgroup"><tr role="row">
        <th scope="col" role="columnheader">Instituția</th>
        <th scope="col" role="columnheader">Județul</th>
        <th scope="col" role="columnheader" class="g2-cell-num">Declaranți</th>
        <th scope="col" role="columnheader" class="g2-cell-num">Funcții</th>
      </tr></thead>
      <tbody role="rowgroup">
      <?php foreach ($rows as $r): ?>
        <tr role="row" data-declared-item>
          <th scope="row" role="rowheader" data-label="Instituția"><a class="g2-link-title" href="/salarii-declarate/<?= e($r['slug']) ?>/"><?= e($r['name']) ?></a></th>
          <td role="cell" data-label="Județul"><?= e($r['judet']) ?></td>
          <td role="cell" class="g2-cell-num" data-label="Declaranți"><?= (int)$r['n_total'] ?></td>
          <td role="cell" class="g2-cell-num" data-label="Funcții"><?= (int)$r['groups'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($pages > 1):
    // The window of numbers: the first, the last and two on each side of the current page.
    $shown = array_unique(array_filter(array_merge([1, $pages], range($pagina - 2, $pagina + 2)), fn($n) => $n >= 1 && $n <= $pages));
    sort($shown);
?>
  <nav class="g2-pagination mt-4" aria-label="Paginare" data-declared-pages>
    <p>Pagina <span class="g2-mono"><?= $pagina ?></span> din <span class="g2-mono"><?= $pages ?></span></p>
    <ul>
      <?php if ($pagina > 1): ?><li><a rel="prev" href="<?= e($page_url($pagina - 1)) ?>">Înapoi</a></li><?php endif; ?>
      <?php $prev = 0; foreach ($shown as $n): if ($prev && $n > $prev + 1): ?><li aria-hidden="true">…</li><?php endif; $prev = $n; ?>
      <li><a href="<?= e($page_url($n)) ?>" aria-label="Pagina <?= $n ?>"<?= $n === $pagina ? ' aria-current="page"' : '' ?>><?= $n ?></a></li>
      <?php endforeach; ?>
      <?php if ($pagina < $pages): ?><li><a rel="next" href="<?= e($page_url($pagina + 1)) ?>">Înainte</a></li><?php endif; ?>
    </ul>
  </nav>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
