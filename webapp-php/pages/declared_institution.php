<?php
/**
 * /salarii-declarate/{slug}/ — the pay of the asset declarations at one institution
 * (SALARY-DECLARATII-PAGE, ADR 0008 rule 9). GET only, no JavaScript. The table is the partial
 * partials/declared_pay.php. 404 for an unknown slug, as pages/employer.php does.
 */
declare(strict_types=1);

$declared_slug = (string)($_GET['slug'] ?? '');
$inst = declared_institution($declared_slug);
if (!$inst) { http_response_code(404); echo "Instituție negăsită."; exit; }

$emp = null;
if ($inst['employer_id'] !== null) {
    $st = db()->prepare("SELECT slug FROM employers WHERE id = ?");
    $st->execute([(int)$inst['employer_id']]);
    $emp = $st->fetch() ?: null;
}

$page_title = $inst['name'] . ' — salarii declarate';
$meta_description = 'Cât câștigă funcționarii de la ' . $inst['name'] . ', după declarațiile lor de avere din 2023, aduse la nivelul din 2026.';
require __DIR__ . '/../inc/header.php';
?>
<div class="w-full max-w-5xl px-4 sm:px-6 py-6" data-declared-institution>
  <nav class="g2-breadcrumbs" aria-label="Navigare">
    <ol>
      <li><a class="g2-link-quiet" href="/salarii-declarate/">Salarii declarate</a></li>
      <li aria-current="page"><?= e($inst['name']) ?></li>
    </ol>
  </nav>

  <h1 class="g2-heading-page mt-4"><?= e($inst['name']) ?></h1>
  <p class="g2-text-meta mt-1" data-declared-county><?= e($inst['judet']) ?></p>

  <?php $declared_slug = $inst['slug']; require __DIR__ . '/../partials/declared_pay.php'; ?>

  <?php if ($emp): ?>
  <p class="mt-6"><a href="/angajator/<?= e($emp['slug']) ?>/" class="text-gov underline" data-declared-employer-link>Posturi la acest angajator →</a></p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
