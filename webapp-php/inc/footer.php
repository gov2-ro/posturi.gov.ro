</main>

<?php /* 2026-11-16 (UX-05): four groups of links, then the disclaimer. ADR 0005: the DS footer. */
$_footer_groups = [
    'Posturi' => [
        ['Toate posturile', '/posturi/'], ['Angajatori', '/angajatori/'],
        ['Statistici', '/statistici/'], ['Pot aplica?', '/pot-aplica/'],
    ],
    // The themed pages (inc/themes.php), in configuration order.
    'Pagini tematice' => array_map(fn($slug, $t) => [$t['label'], '/' . $slug . '/'], array_keys(themes()), themes()),
    'Urmărește' => [
        ['Alerte pe email', '/alerte/'], ['Flux RSS', '/posturi.rss'],
        ['Calendar', '/posturi.ics'], ['Salvate', '/salvate/'],
    ],
    'Proiect' => [
        ['Despre și metodologie', '/despre/'], ['Confidențialitate', '/despre/#confidentialitate'],
        ['Cod sursă', 'https://github.com/gov2-ro/posturi.gov.ro', 'i-brand-github'], ['Pentru angajatori', '/pentru-angajatori/anunt/'],
    ],
]; ?>
<footer class="g2-footer mt-12">
  <div class="g2-footer-in g2-container">
    <?php foreach ($_footer_groups as $_fg_title => $_fg_links): ?>
    <div>
      <h2 class="g2-label mb-1"><?= e($_fg_title) ?></h2>
      <ul>
        <?php /* A link is 26 px high (24 px at least, WCAG 2.5.8) and the rows are 30 px apart: the targets are 4 px apart. */ ?>
        <?php foreach ($_fg_links as $_fl_row): [$_fl_label, $_fl_href, $_fl_icon] = $_fl_row + [2 => null]; $_ext = str_starts_with($_fl_href, 'https://'); ?>
        <li class="flex py-0.5"><a href="<?= e($_fl_href) ?>"<?= $_ext ? ' target="_blank" rel="noopener"' : '' ?> class="inline-flex items-center gap-1.5 py-[3px] text-sm leading-5"><?= $_fl_icon ? icon($_fl_icon, 'h-[18px] w-[18px] shrink-0') : '' ?><?= e($_fl_label) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
    <?php /* The project logo (assets/g2-0.png, scaled down in static/), in the free space at the right of the link groups. */ ?>
    <a href="/" class="ml-auto shrink-0 self-start"><img src="/static/logo-g2-128.png" alt="posturi.gov2.ro, prima pagină" width="96" height="96" class="h-24 w-24"></a>
    <?php /* Each part is one inline block, so a line break falls between two parts and not inside one. */ ?>
    <p class="g2-text-meta basis-full">
      <?php $_sep = '<span aria-hidden="true">&ensp;&middot;&ensp;</span>'; ?>
      <span class="inline-block">Acesta <b>nu este un proiect oficial</b> al Guvernului României. Versiune beta.<?= $_sep ?></span>
      <?php if (!empty($_build_stamp)): ?>
      <span class="inline-block">Bază de date generată
      <time datetime="<?= e($_build_stamp_iso ?? '') ?>"<?= !empty($_build_tooltip) ? ' title="' . e($_build_tooltip) . '" class="cursor-help"' : '' ?>><?= $_build_stamp ?></time><?= $_sep ?></span>
      <?php if ($_source_label): ?>
      <span class="inline-block"><?= e($_source_label) ?><?= $_sep ?></span>
      <?php endif; ?>
      <?php endif; ?>
      <span class="inline-block">sursă: <a href="https://posturi.gov.ro" rel="noopener" class="py-4">posturi.gov.ro</a></span>
    </p>
  </div>
</footer>

<?php /* D9: the [?!] button replaces "Acceptăm sugestii" in the masthead. A native
   <details>, so it opens without JavaScript; the script below only adds Escape
   and click-outside. The FAQ answers live on /despre/, one anchor each. */ ?>
<details class="feedback" id="feedback">
  <summary aria-label="Raportează o problemă sau citește întrebările frecvente"
           class="flex h-11 w-11 cursor-pointer items-center justify-center rounded-full border border-gov bg-gov font-mono text-sm font-bold text-on-gov shadow-lg hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">?!</summary>
  <div class="feedback-panel border border-line-strong bg-surface p-4 text-left text-sm leading-snug text-ink shadow-lg">
    <h2 class="font-semibold text-ink">Raportează problemă?</h2>
    <p class="mt-1 text-ink-muted">
      Acest site preia anunțuri de pe posturi.gov.ro și le restructurează automat. Dacă ați descoperit vreo
      greșeală la convertirea conținutului, vă rugăm să ne anunțați:
    </p>
    <ul class="mt-2 flex flex-wrap gap-x-3 gap-y-1">
      <li><a href="https://forms.gle/96WusM2qr4pbUXhW7" target="_blank" rel="noopener" class="text-gov underline underline-offset-2 hover:no-underline">formular</a></li>
      <?php if (FEEDBACK_EMAIL !== ''): ?>
      <li><a href="mailto:<?= e(FEEDBACK_EMAIL) ?>" class="text-gov underline underline-offset-2 hover:no-underline">e-mail</a></li>
      <?php endif; ?>
      <li><a href="https://github.com/gov2-ro/posturi.gov.ro/issues" target="_blank" rel="noopener" class="text-gov underline underline-offset-2 hover:no-underline">GitHub</a></li>
    </ul>
    <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-ink-muted">Întrebări frecvente</h3>
    <ul class="mt-1 space-y-1">
      <?php foreach (FEEDBACK_FAQ as [$anchor, $question]): ?>
      <li><a href="/despre/#<?= e($anchor) ?>" class="text-gov hover:underline"><?= e($question) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
</details>

<?php /* UX-09 live region and undo notice. Outside every swapped region and
   outside the rows, so an undo stays reachable after its row disappears. */ ?>
<div id="pref-live" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
<div id="pref-notice" hidden tabindex="-1" class="fixed inset-x-3 bottom-3 z-40 mx-auto max-w-md border border-line-strong bg-surface p-3 text-sm text-ink shadow-lg">
  <p data-notice-text class="leading-snug"></p>
  <p data-notice-storage hidden class="mt-1 text-xs leading-snug text-ink-muted">Salvările și anunțurile ascunse rămân în acest browser. Nu se sincronizează între dispozitive și se pot pierde dacă ștergi datele browserului.</p>
  <div class="mt-2 flex flex-wrap gap-2">
    <button type="button" data-notice-undo hidden class="min-h-[2.5rem] border border-gov bg-gov px-3 py-2 text-xs font-medium text-on-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">Anulează</button>
    <a data-notice-link hidden href="/salvate/" class="inline-flex min-h-[2.5rem] items-center border border-line px-3 py-2 text-xs font-medium text-gov hover:border-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">Vezi salvatele</a>
    <?php /* 261116: after a saved search, "Urmărește" opens the subscription dialog (pages/list.php). */ ?>
    <button type="button" data-notice-follow data-follow-open hidden class="min-h-[2.5rem] border border-gov bg-gov px-3 py-2 text-xs font-medium text-on-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">Urmărește</button>
    <button type="button" data-notice-close class="min-h-[2.5rem] border border-line px-3 py-2 text-xs text-ink-muted hover:border-gov hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">Închide</button>
  </div>
</div>

<script>
(function () {
  // D10: "Am înțeles" hides the welcome for this page; the visit is already recorded.
  var w = document.querySelector('[data-welcome-close]');
  if (w) w.addEventListener('click', function () { delete document.documentElement.dataset.welcome; });
  // D9: the [?!] panel closes on Escape (focus back on the button) and on a click outside.
  var fb = document.getElementById('feedback');
  if (!fb) return;
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && fb.open) { fb.open = false; fb.querySelector('summary').focus(); }
  });
  document.addEventListener('click', function (ev) {
    if (fb.open && !fb.contains(ev.target)) fb.open = false;
  });
})();
</script>

<?php /* Cloudflare Web Analytics: cookieless, stores nothing on the device (BETA-CHROME).
         Only on the live host, so local runs and tests send no page views. */ ?>
<?php if (($_SERVER['HTTP_HOST'] ?? '') === 'posturi.gov2.ro'): ?>
<script defer src="https://static.cloudflareinsights.com/beacon.min.js" data-cf-beacon='{"token": "88d0d83c8a27414b9fd440433e0c0dae"}'></script>
<?php endif; ?>

</body>
</html>
