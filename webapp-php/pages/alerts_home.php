<?php
/**
 * UX-14 — /alerte/ („Abonamentele mele”): ask for the address, send a one-hour link.
 *
 * The answer is the same for an address with subscriptions and one without.
 */
declare(strict_types=1);

al_headers();
$page_title = 'Abonamentele mele';
$head_extra = '<meta name="robots" content="noindex">';
$meta_description = 'Vezi și șterge abonările tale la alertele pe email.';

$is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$state = 'form';
$error = '';
if (!al_enabled()) {
    http_response_code(503);
    $state = 'unavailable';
} elseif ($is_post) {
    $result = al_login_request(al_db(), is_string($_POST['email'] ?? null) ? $_POST['email'] : '', al_client_ip());
    if ($result === 'ok') {
        $state = 'sent';
    } elseif ($result === 'rate') {
        http_response_code(429);
        header('Retry-After: 3600');
        $state = 'rate';
    } else {
        http_response_code(400);
        $error = 'Adresa de email nu este validă. Verifică-o și încearcă din nou.';
    }
}
// A honeypot field keeps simple bots out. A hit gets the normal answer.
if ($state === 'form' && $is_post && $error === '' && trim((string)($_POST['website'] ?? '')) !== '') $state = 'sent';

require __DIR__ . '/../inc/header.php';
?>
<div class="w-full px-4 sm:px-6 py-6">
  <h1 class="font-display text-[1.7rem] sm:text-[2rem] font-semibold italic leading-tight text-ink">Abonamentele mele</h1>
<?php if ($state === 'unavailable'): ?>
  <?= al_notice('note', 'Alertele pe email nu sunt disponibile încă. Încearcă mai târziu.') ?>
<?php elseif ($state === 'rate'): ?>
  <?= al_notice('alert', 'Prea multe cereri de la această adresă de rețea. Încearcă din nou peste o oră.') ?>
<?php elseif ($state === 'sent'): ?>
  <?= al_notice('ok', 'Dacă ai abonări, îți trimitem un link în cel mult 5 minute.') ?>
  <p class="mt-3 max-w-2xl text-sm text-ink-muted">Linkul este valabil o oră.</p>
<?php else: ?>
  <p class="mt-3 max-w-2xl text-sm text-ink-muted">Scrie adresa de email a abonărilor. Îți trimitem un link, valabil o oră, unde le vezi și le ștergi. Site-ul nu are conturi.</p>
  <?php if ($error !== ''): ?><?= al_notice('alert', e($error)) ?><?php endif; ?>
  <form method="post" action="/alerte/" class="mt-4">
    <div class="sr-only" aria-hidden="true">
      <label for="al-website">Nu completa acest câmp</label>
      <input type="text" id="al-website" name="website" value="" tabindex="-1" autocomplete="off">
    </div>
    <label for="al-email" class="block text-sm font-medium text-ink">Adresa ta de email</label>
    <input type="email" id="al-email" name="email" required maxlength="254" autocomplete="email" inputmode="email"
           class="mt-1 <?= al_cls_input() ?>">
    <div class="mt-4"><button type="submit" class="<?= al_cls_primary() ?>">Trimite linkul</button></div>
  </form>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
