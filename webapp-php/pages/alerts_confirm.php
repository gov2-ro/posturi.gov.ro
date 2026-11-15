<?php
/**
 * UX-14 — /alerte/confirma/{id}/{token}: confirm one subscription.
 *
 * A GET never changes a row: mail scanners follow the link in an email.
 * The button sends the POST.
 */
declare(strict_types=1);

al_headers();
$page_title = 'Confirmă abonarea';
$head_extra = '<meta name="robots" content="noindex">';

$pdo = al_enabled() ? al_db() : null;
$row = $pdo ? al_auth($pdo, (int)$al_id, (string)$al_token) : null;
// A pending row older than 48 h is expired, even before the next write deletes it.
if ($row && $row['status'] === 'pending' && (string)$row['created_at'] < al_iso(al_now() - ALERTS_PENDING_TTL)) {
    $row = null;
}
$done = false;
if ($row === null) {
    http_response_code(404);
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    al_confirm($pdo, $row);
    $done = true;
}

require __DIR__ . '/../inc/header.php';
?>
<div class="w-full px-4 sm:px-6 py-6">
  <h1 class="font-display text-[1.7rem] sm:text-[2rem] font-semibold italic leading-tight text-ink">Confirmă abonarea</h1>
<?php if ($row === null): ?>
  <?= al_notice('alert', 'Linkul nu este valid sau a expirat. Abonează-te din nou din <a href="/salvate/" class="underline">Salvate</a>.') ?>
<?php elseif ($done): ?>
  <?= al_notice('ok', 'Abonarea este confirmată. Primești un rezumat la fiecare 3 zile, când apar anunțuri noi.') ?>
  <p class="mt-3 text-sm"><a href="/alerte/abonari/<?= (int)$row['id'] ?>/<?= e($al_token) ?>" class="text-gov underline">Abonamentele mele</a></p>
<?php else: ?>
  <p class="mt-3 max-w-2xl text-sm text-ink-muted">Confirmi abonarea la email pentru această căutare:</p>
  <p class="mt-2 font-display text-lg font-semibold italic text-ink"><a href="<?= e(al_list_href((string)$row['search_key'])) ?>" class="hover:text-gov"><?= e($row['label']) ?></a></p>
  <form method="post" class="mt-4">
    <button type="submit" class="<?= al_cls_primary() ?>">Confirmă abonarea</button>
  </form>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
