</main>

<footer class="border-t border-line mt-12 py-6 text-center text-xs text-ink-muted font-mono space-y-1">
 <p> Acesta <b>Nu este un proiect oficial</b> al Guvernului României.
 <?php if (!empty($_build_stamp)): ?>
   &middot;    MVP / <b>WIP</b> — versiune în lucru.
 &ensp; &middot; &ensp; Bază de date generată
    <time datetime="<?= e($_build_stamp_iso ?? '') ?>"<?= !empty($_build_tooltip) ? ' title="' . e($_build_tooltip) . '" class="cursor-help"' : '' ?>><?= $_build_stamp ?></time>
    <?php if ($_source_label): ?>
    &ensp; &middot; &ensp; <?= e($_source_label) ?>
    <?php endif; ?>
    &ensp; &middot; &ensp; sursă: <a href="https://posturi.gov.ro" rel="noopener" class="text-gov hover:underline">posturi.gov.ro</a>
  <?php endif; ?>
  &ensp; &middot; &ensp;
    <a href="https://forms.gle/96WusM2qr4pbUXhW7" class="text-gov underline hover:no-underline" target="_blank" rel="noopener">Acceptăm sugestii</a> (gForm) ·
    <a href="https://github.com/gov2-ro/posturi.gov.ro" class="text-gov underline hover:no-underline" target="_blank" rel="noopener">cod sursă</a> ·
    <a href="/despre/" class="text-gov underline hover:no-underline">metodologie</a>
  </p>
  <p class="flex items-center justify-center gap-2 pt-1">
    <label for="skin-picker" class="text-ink-faint">Stil vizual</label>
    <?= pg_skin_select() ?>
  </p>
</footer>

<?php /* UX-09 live region and undo notice. Outside every swapped region and
   outside the rows, so an undo stays reachable after its row disappears. */ ?>
<div id="pref-live" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
<div id="pref-notice" hidden tabindex="-1" class="fixed inset-x-3 bottom-3 z-40 mx-auto max-w-md border border-line-strong bg-surface p-3 text-sm text-ink shadow-lg">
  <p data-notice-text class="leading-snug"></p>
  <p data-notice-storage hidden class="mt-1 text-xs leading-snug text-ink-muted">Salvările și anunțurile ascunse rămân în acest browser. Nu se sincronizează între dispozitive și se pot pierde dacă ștergi datele browserului.</p>
  <div class="mt-2 flex flex-wrap gap-2">
    <button type="button" data-notice-undo hidden class="min-h-[2.5rem] border border-gov bg-gov px-3 py-2 text-xs font-medium text-on-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">Anulează</button>
    <button type="button" data-notice-close class="min-h-[2.5rem] border border-line px-3 py-2 text-xs text-ink-muted hover:border-gov hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">Închide</button>
  </div>
</div>

</body>
</html>
