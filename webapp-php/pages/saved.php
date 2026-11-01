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
<div class="w-full px-4 sm:px-6 py-6" data-saved-page>
  <h1 class="font-display text-[1.7rem] sm:text-[2rem] font-semibold italic leading-tight text-ink">Salvate</h1>

  <p class="mt-3 max-w-2xl border border-info-line bg-info px-3 py-2 text-sm leading-snug text-info-ink">
    Salvările și anunțurile ascunse rămân în acest browser. Nu se sincronizează între dispozitive și se pot pierde dacă ștergi datele browserului. Poți salva cel mult 4 căutări și 20 de anunțuri. Exportă anunțurile și notițele ca CSV (mai jos) ca să le păstrezi.
  </p>

  <nav aria-label="Vedere" class="mt-5 flex flex-wrap gap-1 border-b border-line text-sm">
    <a data-view-tab="saved" href="/salvate/" class="-mb-px border border-transparent px-2 sm:px-4 py-2 text-ink-muted hover:text-gov aria-[current=page]:border-line aria-[current=page]:border-b-page aria-[current=page]:bg-page aria-[current=page]:font-semibold aria-[current=page]:text-ink">Salvate <span class="font-mono text-xs" data-count="saved"></span></a>
    <a data-view-tab="hidden" href="/salvate/?vedere=ascunse" class="-mb-px border border-transparent px-2 sm:px-4 py-2 text-ink-muted hover:text-gov aria-[current=page]:border-line aria-[current=page]:border-b-page aria-[current=page]:bg-page aria-[current=page]:font-semibold aria-[current=page]:text-ink">Ascunse <span class="font-mono text-xs" data-count="hidden"></span></a>
  </nav>

  <noscript>
    <p class="mt-6 text-sm text-ink-muted">Lista este păstrată în browserul tău și poate fi citită doar cu JavaScript activat. Anunțurile rămân accesibile din <a href="/" class="text-gov underline">lista principală</a>.</p>
  </noscript>

  <?php /* UX-15: the „Căutări salvate” section. static/searches.js fills it from `posturi.searches.v1`. */ ?>
  <section data-searches-panel hidden aria-labelledby="searches-heading" class="mt-6">
    <h2 id="searches-heading" data-searches-heading tabindex="-1" class="font-display text-xl font-semibold italic text-ink focus:outline-none">Căutări salvate <span class="font-mono text-xs not-italic font-normal text-ink-muted" data-count="searches"></span></h2>
    <p data-searches-status role="status" aria-live="polite" class="min-h-4 text-xs text-ink-muted"></p>
    <div data-searches-empty hidden class="mt-2 py-4">
      <p class="text-sm text-ink-muted" data-searches-empty-text></p>
      <a href="/" class="mt-3 inline-block text-sm text-gov hover:underline">Caută anunțuri</a>
    </div>
    <ul data-searches-list class="divide-y divide-line"></ul>
  </section>

  <h2 data-saved-heading class="mt-8 font-display text-xl font-semibold italic text-ink"><span data-saved-heading-text>Anunțuri salvate</span> <span class="font-mono text-xs not-italic font-normal text-ink-muted" data-saved-heading-count></span></h2>

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
      <button type="button" data-export-csv class="<?= $btn ?>">Exportă salvatele și notițele (CSV)</button>
      <button type="button" data-clear="saved" class="<?= $btn ?>">Șterge lista de salvate</button>
      <button type="button" data-clear="hidden" class="<?= $btn ?>">Restabilește toate anunțurile ascunse</button>
      <button type="button" data-clear-searches class="<?= $btn ?>">Șterge căutările salvate</button>
    </div>
    <div data-clear-confirm hidden role="alertdialog" aria-labelledby="clear-confirm-text" class="mt-3 max-w-md border border-alert-line bg-alert p-3 text-sm text-alert-ink">
      <p id="clear-confirm-text" data-clear-confirm-text class="leading-snug"></p>
      <div class="mt-2 flex gap-2">
        <button type="button" data-clear-yes class="min-h-[2.5rem] border border-alert-line bg-surface px-3 py-2 text-xs font-medium text-alert-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">Confirmă</button>
        <button type="button" data-clear-no class="<?= $btn ?>">Anulează</button>
      </div>
    </div>
    <div data-searches-confirm hidden role="alertdialog" aria-labelledby="searches-confirm-text" class="mt-3 max-w-md border border-alert-line bg-alert p-3 text-sm text-alert-ink">
      <p id="searches-confirm-text" data-searches-confirm-text class="leading-snug"></p>
      <div class="mt-2 flex gap-2">
        <button type="button" data-searches-yes class="min-h-[2.5rem] border border-alert-line bg-surface px-3 py-2 text-xs font-medium text-alert-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">Confirmă</button>
        <button type="button" data-searches-no class="<?= $btn ?>">Anulează</button>
      </div>
    </div>
  </section>
</div>

<template id="search-item-tpl">
  <li class="py-4">
    <h3 class="font-display text-lg font-semibold italic leading-snug text-ink">
      <a data-f="link" data-search-open class="inline-block py-0.5 hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus"></a>
    </h3>
    <form data-f="rename-form" hidden onsubmit="return false" class="flex flex-wrap items-center gap-2">
      <input type="text" maxlength="200" autocomplete="off" aria-label="Nume nou pentru căutare" class="min-h-[2.5rem] w-full max-w-md border border-line-strong bg-surface px-2 py-1 text-sm text-ink focus:border-gov focus:outline-none focus:ring-1 focus:ring-focus">
      <button type="button" data-search-rename-save class="<?= $btn ?>">Salvează</button>
      <button type="button" data-search-rename-cancel class="<?= $btn ?>">Anulează</button>
    </form>
    <p class="mt-1 text-xs text-ink-muted"><span data-f="meta"></span><span data-f="default" hidden> · <span class="font-medium text-ink">implicită</span></span></p>
    <div class="mt-2 flex flex-wrap items-center gap-2">
      <button type="button" data-search-rename class="<?= $btn ?>">Redenumește<span class="sr-only" data-f="sr-rename"></span></button>
      <button type="button" data-search-delete class="<?= $btn ?>">Șterge<span class="sr-only" data-f="sr-delete"></span></button>
      <a data-f="rss" class="<?= $btn ?>">RSS</a>
    </div>
  </li>
</template>

<template id="saved-item-tpl">
  <li data-posting data-pref-managed class="py-4">
    <h3 class="font-display text-lg font-semibold italic leading-snug text-ink">
      <a data-f="link" class="inline-block py-0.5 hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus"></a><span data-f="plain"></span>
    </h3>
    <p data-f="meta" class="mt-1 text-xs text-ink-muted"></p>
    <p data-f="deadline" class="mt-0.5 font-mono text-xs text-ink-muted"></p>
    <p data-f="note" class="mt-0.5 text-[11px] italic leading-snug text-ink-muted"></p>
    <p data-f="state" class="mt-1 text-xs text-ink-muted"></p>
    <p data-f="hidden-tag" hidden class="mt-1 text-xs font-medium text-note-ink">Ascuns din rezultate</p>
    <p data-f="user-note" hidden class="note-view whitespace-pre-line"></p>
    <div class="mt-2 flex flex-wrap items-center gap-2">
      <button type="button" data-pref="save" class="<?= $btn ?>"><span data-pref-label></span><span class="sr-only" data-f="sr-title"></span></button>
      <button type="button" data-pref="hide" class="<?= $btn ?>"><span data-pref-label></span><span class="sr-only" data-f="sr-title2"></span></button>
      <button type="button" data-pref="note" aria-expanded="false" class="<?= $btn ?>"><span data-pref-label></span></button>
      <button type="button" data-retry hidden class="<?= $btn ?>">Reîncearcă</button>
    </div>
  </li>
</template>
<?php require __DIR__ . '/../inc/footer.php'; ?>
