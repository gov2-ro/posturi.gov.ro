<?php
/**
 * UX-14 — /alerte/abonare/: the form that subscribes one saved search to email.
 *
 * GET shows the form for the search key in ?cheie=. POST validates, stores a
 * pending row and queues the confirmation email. The answer to a valid POST is
 * the same for a new address, a known address and a full address.
 */
declare(strict_types=1);

al_headers();
$page_title = 'Alertă pe email';
$head_extra = '<meta name="robots" content="noindex">';
$meta_description = 'Primește pe email anunțurile noi ale unei căutări salvate.';

$is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in = $is_post ? $_POST : ($alerts_get ?? []);
$in = array_map(static fn($v) => is_string($v) ? $v : '', $in);
$key = $in['cheie'] ?? '';
$sort = $in['sort'] ?? '';
$label = al_clean_label($in['nume'] ?? '');
$email_value = $is_post ? ($in['email'] ?? '') : '';
$state = 'form';      // form | sent | unavailable | bad-key | rate
$error = '';

if (!al_enabled()) {
    http_response_code(503);
    $state = 'unavailable';
} elseif (al_parse_key($key) === null) {
    http_response_code(400);
    $state = 'bad-key';
} elseif ($is_post) {
    $result = al_subscribe(al_db(), $in, al_client_ip());
    if ($result === 'sent') {
        $state = 'sent';
    } elseif ($result === 'rate') {
        http_response_code(429);
        header('Retry-After: 3600');
        $state = 'rate';
    } elseif ($result === 'invalid-email') {
        http_response_code(400);
        $error = 'Adresa de email nu este validă. Verifică-o și încearcă din nou.';
    } else {
        http_response_code(400);
        $state = 'bad-key';
    }
}

require __DIR__ . '/../inc/header.php';
?>
<div class="w-full px-4 sm:px-6 py-6">
  <h1 class="font-display text-[1.7rem] sm:text-[2rem] font-semibold italic leading-tight text-ink">Alertă pe email</h1>

<?php if ($state === 'unavailable'): ?>
  <?= al_notice('note', 'Alertele pe email nu sunt disponibile încă. Încearcă mai târziu.') ?>
<?php elseif ($state === 'bad-key'): ?>
  <?= al_notice('alert', 'Căutarea nu este validă. Deschide <a href="/salvate/" class="underline">Salvate</a> și încearcă din nou.') ?>
<?php elseif ($state === 'rate'): ?>
  <?= al_notice('alert', 'Prea multe cereri de la această adresă de rețea. Încearcă din nou peste o oră.') ?>
<?php elseif ($state === 'sent'): ?>
  <?= al_notice('ok', 'Verifică-ți emailul. Linkul ajunge în cel mult 5 minute.') ?>
  <p class="mt-3 max-w-2xl text-sm text-ink-muted">Nu găsești mesajul? Caută în Spam. Abonarea nu este activă până nu confirmi.</p>
  <p class="mt-4"><a href="/salvate/" class="text-sm text-gov hover:underline">← Înapoi la Salvate</a></p>
<?php else: ?>
  <p class="mt-3 max-w-2xl text-sm text-ink-muted">Primești un rezumat la fiecare 3 zile, când apar anunțuri noi pentru această căutare:</p>
  <p class="mt-2 font-display text-lg font-semibold italic text-ink"><a href="<?= e(al_list_href($key)) ?>" class="hover:text-gov"><?= e($label) ?></a></p>
  <?php if ($error !== ''): ?><?= al_notice('alert', e($error)) ?><?php endif; ?>
  <form method="post" action="/alerte/abonare/" class="mt-4">
    <input type="hidden" name="cheie" value="<?= e($key) ?>">
    <input type="hidden" name="sort" value="<?= e($sort) ?>">
    <input type="hidden" name="nume" value="<?= e($label) ?>">
    <div class="sr-only" aria-hidden="true">
      <label for="al-website">Nu completa acest câmp</label>
      <input type="text" id="al-website" name="website" value="" tabindex="-1" autocomplete="off">
    </div>
    <label for="al-email" class="block text-sm font-medium text-ink">Adresa ta de email</label>
    <input type="email" id="al-email" name="email" value="<?= e($email_value) ?>" required maxlength="254"
           autocomplete="email" inputmode="email" class="mt-1 <?= al_cls_input() ?>">
    <?= al_consent_html() ?>
    <div class="mt-4 flex flex-wrap gap-2">
      <button type="submit" class="<?= al_cls_primary() ?>">Abonează-mă</button>
      <a href="/salvate/" class="<?= al_cls_btn() ?>">Anulează</a>
    </div>
  </form>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
