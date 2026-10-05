<?php
/**
 * UX-09 — /salvate/: the browser-local shortlist and hidden announcements.
 *
 * Nothing about the reader is known to the server: this page is the same for
 * everyone. static/saved.js reads the browser's own state, shows it in batches
 * of 25, and asks /preferinte-posturi.json for the current public data of the
 * ids on screen. The page therefore renders no export data at all.
 */
declare(strict_types=1);

$page_title = 'Salvate';
$meta_description = 'Anunțurile salvate și ascunse în acest browser.';
$head_extra = '<meta name="robots" content="noindex">';

$btn = 'inline-flex min-h-[2.5rem] items-center border border-line bg-surface px-3 py-2 text-xs font-medium '
     . 'text-ink-muted transition-colors hover:border-gov hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus';

require __DIR__ . '/../inc/header.php';
?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-6" data-saved-page>
  <h1 class="font-display text-[1.7rem] sm:text-[2rem] font-semibold italic leading-tight text-ink">Salvate</h1>

  <p class="mt-3 max-w-2xl border border-info-line bg-info px-3 py-2 text-sm leading-snug text-info-ink">
    Salvările și anunțurile ascunse rămân în acest browser. Nu se sincronizează între dispozitive și se pot pierde dacă ștergi datele browserului.
  </p>

  <nav aria-label="Vedere" class="mt-5 flex gap-1 border-b border-line text-sm">
    <a data-view-tab="saved" href="/salvate/" class="-mb-px border border-transparent px-4 py-2 text-ink-muted hover:text-gov aria-[current=page]:border-line aria-[current=page]:border-b-page aria-[current=page]:bg-page aria-[current=page]:font-semibold aria-[current=page]:text-ink">Salvate <span class="font-mono text-xs" data-count="saved"></span></a>
    <a data-view-tab="hidden" href="/salvate/?vedere=ascunse" class="-mb-px border border-transparent px-4 py-2 text-ink-muted hover:text-gov aria-[current=page]:border-line aria-[current=page]:border-b-page aria-[current=page]:bg-page aria-[current=page]:font-semibold aria-[current=page]:text-ink">Ascunse <span class="font-mono text-xs" data-count="hidden"></span></a>
  </nav>

  <noscript>
    <p class="mt-6 text-sm text-ink-muted">Lista este păstrată în browserul tău și poate fi citită doar cu JavaScript activat. Anunțurile rămân accesibile din <a href="/" class="text-gov underline">lista principală</a>.</p>
  </noscript>

  <div data-saved-empty hidden class="mt-8 py-8 text-center">
    <p class="text-sm text-ink-muted" data-empty-text></p>
    <a href="/" class="mt-3 inline-block text-sm text-gov hover:underline">Caută anunțuri</a>
  </div>

  <ul data-saved-list class="mt-2 divide-y divide-line"></ul>

  <nav data-saved-pager hidden aria-label="Paginare listă" class="mt-6 text-sm">
    <div class="flex items-center justify-between gap-2">
      <span data-pager-info class="font-mono text-xs text-ink-muted"></span>
      <span class="flex items-center gap-1">
        <a data-pager-prev rel="prev" href="#" class="border border-line px-3 py-2 text-ink-muted hover:border-gov hover:text-gov">← Prev</a>
        <a data-pager-next rel="next" href="#" class="border border-line px-3 py-2 text-ink-muted hover:border-gov hover:text-gov">Următor →</a>
      </span>
    </div>
  </nav>

  <section aria-labelledby="saved-tools" class="mt-10 border-t border-line pt-5">
    <h2 id="saved-tools" class="font-display text-base font-semibold italic text-ink">Gestionare</h2>
    <p class="mt-1 text-xs text-ink-muted">Fiecare acțiune șterge doar lista ei din acest browser; preferințele de aspect și de filtre rămân.</p>
    <div class="mt-3 flex flex-wrap gap-2">
      <button type="button" data-clear="saved" class="<?= $btn ?>">Șterge lista de salvate</button>
      <button type="button" data-clear="hidden" class="<?= $btn ?>">Restabilește toate anunțurile ascunse</button>
    </div>
    <div data-clear-confirm hidden role="alertdialog" aria-labelledby="clear-confirm-text" class="mt-3 max-w-md border border-alert-line bg-alert p-3 text-sm text-alert-ink">
      <p id="clear-confirm-text" data-clear-confirm-text class="leading-snug"></p>
      <div class="mt-2 flex gap-2">
        <button type="button" data-clear-yes class="min-h-[2.5rem] border border-alert-line bg-surface px-3 py-2 text-xs font-medium text-alert-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">Confirmă</button>
        <button type="button" data-clear-no class="<?= $btn ?>">Anulează</button>
      </div>
    </div>
  </section>
</div>

<template id="saved-item-tpl">
  <li data-posting data-pref-managed class="py-4">
    <h2 class="font-display text-lg font-semibold italic leading-snug text-ink">
      <a data-f="link" class="inline-block py-0.5 hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus"></a><span data-f="plain"></span>
    </h2>
    <p data-f="meta" class="mt-1 text-xs text-ink-muted"></p>
    <p data-f="deadline" class="mt-0.5 font-mono text-xs text-ink-muted"></p>
    <p data-f="note" class="mt-0.5 text-[11px] italic leading-snug text-ink-muted"></p>
    <p data-f="state" class="mt-1 text-xs text-ink-muted"></p>
    <p data-f="hidden-tag" hidden class="mt-1 text-xs font-medium text-note-ink">Ascuns din rezultate</p>
    <div class="mt-2 flex flex-wrap items-center gap-2">
      <button type="button" data-pref="save" class="<?= $btn ?>"><span data-pref-label></span><span class="sr-only" data-f="sr-title"></span></button>
      <button type="button" data-pref="hide" class="<?= $btn ?>"><span data-pref-label></span><span class="sr-only" data-f="sr-title2"></span></button>
      <button type="button" data-retry hidden class="<?= $btn ?>">Reîncearcă</button>
    </div>
  </li>
</template>
<?php require __DIR__ . '/../inc/footer.php'; ?>
