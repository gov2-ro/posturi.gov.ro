<?php
/**
 * UX-14 — the pages that delete subscriptions.
 *
 *   $al_mode 'list'   /alerte/abonari/{id}/{token}  every subscription of the address
 *   $al_mode 'delete' /alerte/sterge/{id}/{token}   the link in each digest: one subscription
 *   $al_mode 'login'  /alerte/intra/{token}         the one-hour link of „Abonamentele mele”
 *
 * A GET never changes a row (mail scanners follow links). Each deletion is a POST.
 * A token of one subscription proves control of the address, so it opens the
 * list of every subscription with the same email_index.
 */
declare(strict_types=1);

al_headers();
$page_title = $al_mode === 'delete' ? 'Șterge abonarea' : 'Abonamentele mele';
$head_extra = '<meta name="robots" content="noindex">';

$pdo = al_enabled() ? al_db() : null;
$is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$index = null;      // the email_index this link proves
$self = null;       // the subscription of the link, when it has one
if ($pdo) {
    if ($al_mode === 'login') {
        $index = al_login_auth($pdo, (string)$al_token);
    } else {
        $self = al_auth($pdo, (int)$al_id, (string)$al_token);
        $index = $self ? (string)$self['email_index'] : null;
    }
}

$notice = '';
$base = '';         // where the forms post
if ($index === null) {
    http_response_code(404);
} else {
    if ($al_mode === 'login') {
        $base = '/alerte/intra/' . $al_token;
    } elseif ($al_mode === 'delete') {
        $base = '/alerte/sterge/' . (int)$al_id . '/' . $al_token;
    } else {
        $base = '/alerte/abonari/' . (int)$al_id . '/' . $al_token;
    }
    if ($is_post) {
        $p = array_map(static fn($v) => is_string($v) ? $v : '', $_POST);
        $n = 0;
        if ($al_mode === 'delete') {
            $n = al_delete_one($pdo, $index, (int)$al_id);
            $notice = $n ? 'Abonarea este ștearsă. Nu mai primești rezumate pentru această căutare.' : '';
        } elseif (($p['actiune'] ?? '') === 'sterge-toate') {
            $n = al_delete_all($pdo, $index);
            $notice = 'Toate abonările sunt șterse.';
        } elseif (($p['actiune'] ?? '') === 'sterge') {
            $n = al_delete_one($pdo, $index, (int)($p['abonare'] ?? 0));
            $notice = $n ? 'Abonarea este ștearsă.' : 'Abonarea nu mai există.';
        }
        if ($al_mode === 'login') al_login_mark_used($pdo, (string)$al_token);
        // The link of a deleted subscription no longer works. When other
        // subscriptions remain, the reader keeps a one-hour link to them.
        if ($self && $n && al_auth($pdo, (int)$al_id, (string)$al_token) === null && al_list($pdo, $index)) {
            $new = al_new_token();
            $pdo->prepare("INSERT INTO login_tokens (token_hash, email_index, expires_at) VALUES (?, ?, ?)")
                ->execute([al_token_hash($new), $index, al_iso(al_now() + ALERTS_LOGIN_TTL)]);
            $base = '/alerte/intra/' . $new;
        }
    }
}
$rows = ($index !== null && $pdo) ? al_list($pdo, $index) : [];

require __DIR__ . '/../inc/header.php';
?>
<div class="w-full px-4 sm:px-6 py-6">
  <h1 class="font-display text-[1.7rem] sm:text-[2rem] font-semibold italic leading-tight text-ink"><?= e($page_title) ?></h1>
<?php if ($index === null): ?>
  <?= al_notice('alert', 'Linkul nu este valid sau a expirat. Cere un link nou la <a href="/alerte/" class="underline">Abonamentele mele</a>.') ?>
<?php elseif ($al_mode === 'delete' && !$is_post): ?>
  <?php if ($self): ?>
  <p class="mt-3 max-w-2xl text-sm text-ink-muted">Ștergi abonarea la email pentru această căutare:</p>
  <p class="mt-2 font-display text-lg font-semibold italic text-ink"><?= e($self['label']) ?></p>
  <form method="post" action="<?= e($base) ?>" class="mt-4">
    <button type="submit" class="<?= al_cls_primary() ?>">Șterge abonarea</button>
  </form>
  <?php endif; ?>
<?php else: ?>
  <?php if ($notice !== ''): ?><?= al_notice('ok', e($notice)) ?><?php endif; ?>
  <?php if ($al_mode === 'delete'): ?>
    <?php if ($rows): ?><p class="mt-4 text-sm"><a href="<?= e($base) ?>" class="text-gov underline">Abonamentele mele</a></p><?php endif; ?>
  <?php elseif (!$rows): ?>
  <p class="mt-4 text-sm text-ink-muted">Nu ai nicio abonare la email.</p>
  <p class="mt-2"><a href="/salvate/" class="text-sm text-gov hover:underline">Vezi căutările salvate</a></p>
  <?php else: ?>
  <ul class="mt-4 max-w-2xl divide-y divide-line" data-alerts-list>
    <?php foreach ($rows as $r): ?>
    <li class="py-4" data-subscription="<?= (int)$r['id'] ?>">
      <h2 class="font-display text-lg font-semibold italic leading-snug text-ink">
        <a href="<?= e(al_list_href((string)$r['search_key'])) ?>" class="hover:text-gov"><?= e($r['label']) ?></a>
      </h2>
      <p class="mt-1 text-xs text-ink-muted"><?= $r['status'] === 'pending' ? 'în așteptarea confirmării' : 'confirmată' ?></p>
      <form method="post" action="<?= e($base) ?>" class="mt-2">
        <input type="hidden" name="actiune" value="sterge">
        <input type="hidden" name="abonare" value="<?= (int)$r['id'] ?>">
        <button type="submit" class="<?= al_cls_btn() ?>">Șterge<span class="sr-only">: <?= e($r['label']) ?></span></button>
      </form>
    </li>
    <?php endforeach; ?>
  </ul>
  <form method="post" action="<?= e($base) ?>" class="mt-4 border-t border-line pt-4">
    <input type="hidden" name="actiune" value="sterge-toate">
    <button type="submit" class="<?= al_cls_btn() ?>">Șterge toate</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
