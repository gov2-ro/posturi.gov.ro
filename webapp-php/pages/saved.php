<?php
/**
 * UX-09 — /salvate/: the browser-local shortlist and hidden announcements.
 *
 * Nothing about the reader is known to the server: this page is the same for
 * everyone. static/saved.js reads the browser's own state, shows it in batches
 * of 25, and asks /preferinte-posturi.json for the current public data of the
 * ids on screen. The page therefore renders no export data at all.
 *
 * Three tabs (UX-SALVATE-FLUX, 2026-11-23): Flux (the default; static/flux.js, one /flux.json call for
 * every source), Liste (the lists: alerts, searches, employers, saved postings) and Ascunse.
 * The tab comes from ?vedere=; the old values `notite` and `cautari` open Liste.
 */
declare(strict_types=1);

$page_title = 'Salvate';
$meta_description = 'Anunțurile salvate și ascunse în acest browser.';
$head_extra = '<meta name="robots" content="noindex">';

$view = match ($_GET['vedere'] ?? '') {
    'ascunse' => 'hidden',
    'liste', 'notite', 'cautari' => 'saved',
    default => 'flux',
};

$btn = 'inline-flex min-h-[2.5rem] items-center border border-line bg-surface px-3 py-2 text-xs font-medium '
     . 'text-ink-muted transition-colors hover:border-gov hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus';
$tab = '-mb-px border border-transparent px-2 sm:px-4 py-2 text-ink-muted hover:text-gov aria-[current=page]:border-line '
     . 'aria-[current=page]:border-b-page aria-[current=page]:bg-page aria-[current=page]:font-semibold aria-[current=page]:text-ink';

require __DIR__ . '/../inc/header.php';
?>
<div class="w-full px-4 sm:px-6 py-6" data-saved-page data-view="<?= e($view) ?>">
  <h1 class="font-display text-[1.7rem] sm:text-[2rem] font-semibold italic leading-tight text-ink">Salvate</h1>

  <p class="mt-3 max-w-2xl border border-info-line bg-info px-3 py-2 text-sm leading-snug text-info-ink">
    Salvările și anunțurile ascunse rămân în acest browser. Nu se sincronizează între dispozitive și se pot pierde dacă ștergi datele browserului. Poți salva cel mult 4 căutări, 10 angajatori și 20 de anunțuri. Exportă anunțurile și notițele ca CSV (în Liste) ca să le păstrezi.
  </p>

  <nav aria-label="Vedere" class="mt-5 flex flex-wrap gap-1 border-b border-line text-sm">
    <a data-view-tab="flux" href="/salvate/" class="<?= $tab ?>"<?= $view === 'flux' ? ' aria-current="page"' : '' ?>>Flux</a>
    <a data-view-tab="saved" href="/salvate/?vedere=liste" class="<?= $tab ?>"<?= $view === 'saved' ? ' aria-current="page"' : '' ?>>Liste</a>
    <a data-view-tab="hidden" href="/salvate/?vedere=ascunse" class="<?= $tab ?>"<?= $view === 'hidden' ? ' aria-current="page"' : '' ?>>Ascunse <span class="font-mono text-xs" data-count="hidden"></span></a>
  </nav>

  <noscript>
    <p class="mt-6 text-sm text-ink-muted">Lista este păstrată în browserul tău și poate fi citită doar cu JavaScript activat. Anunțurile rămân accesibile din <a href="/posturi/" class="text-gov underline">lista principală</a>.</p>
  </noscript>

  <?php /* UX-SALVATE-FLUX: the open postings of every source in one list. static/flux.js reads the three browser stores,
     asks /flux.json once and draws everything in this section. */ ?>
  <section data-flux-panel data-view-panel="flux" aria-labelledby="flux-heading" class="mt-6"<?= $view === 'flux' ? '' : ' hidden' ?>>
    <h2 id="flux-heading" data-flux-heading tabindex="-1" class="font-display text-xl font-semibold italic text-ink focus:outline-none">Flux <span class="font-mono text-xs not-italic font-normal text-ink-muted" data-flux-count></span></h2>
    <p class="mt-1 max-w-2xl text-sm text-ink-muted">Anunțurile deschise din toate sursele tale: anunțurile salvate, căutările salvate și angajatorii urmăriți.</p>
    <p data-flux-status role="status" aria-live="polite" class="min-h-4 text-xs text-ink-muted"></p>

    <div data-flux-empty="none" hidden class="mt-2 py-4">
      <p class="text-sm text-ink-muted">Nu ai nicio sursă. Salvează un anunț sau o căutare, ori urmărește un angajator.</p>
      <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm"><a href="/posturi/" class="inline-block py-1 text-gov hover:underline">Caută anunțuri</a><a href="/angajatori/" class="inline-block py-1 text-gov hover:underline">Vezi angajatorii</a></p>
    </div>
    <div data-flux-empty="closed" hidden class="mt-2 py-4">
      <p class="text-sm text-ink-muted">Sursele tale nu au niciun anunț deschis acum.</p>
      <p class="mt-3 text-sm"><a href="/salvate/?vedere=liste" class="inline-block py-1 text-gov hover:underline">Vezi listele tale</a></p>
    </div>
    <p data-flux-error hidden class="mt-2 max-w-2xl border border-alert-line bg-alert px-3 py-2 text-sm text-alert-ink">Nu am putut încărca fluxul. <button type="button" data-flux-retry class="min-h-[2rem] underline">Reîncearcă</button></p>

    <div data-flux-body hidden>
      <div data-flux-stats class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"></div>

      <div data-flux-chips role="group" aria-label="Surse" class="mt-4 flex flex-wrap gap-2"></div>
      <div role="group" aria-label="Sortare" class="mt-3 flex flex-wrap items-center gap-2 text-xs text-ink-muted">
        <span>Sortare</span>
        <button type="button" data-flux-sort="deadline" aria-pressed="true" class="g2-chip">termen</button>
        <button type="button" data-flux-sort="new" aria-pressed="false" class="g2-chip">cele mai noi</button>
      </div>
      <div data-flux-notes class="mt-2 text-xs text-ink-muted"></div>

      <ul data-flux-list class="mt-2 divide-y divide-line"></ul>

      <nav data-flux-pager hidden aria-label="Paginare flux" class="mt-6 text-sm">
        <div class="flex items-center justify-between gap-2">
          <span data-flux-pager-info class="font-mono text-xs text-ink-muted"></span>
          <span class="flex items-center gap-1">
            <button type="button" data-flux-prev class="border border-line px-3 py-2 text-ink-muted hover:border-gov hover:text-gov">← Prev</button>
            <button type="button" data-flux-next class="border border-line px-3 py-2 text-ink-muted hover:border-gov hover:text-gov">Următor →</button>
          </span>
        </div>
      </nav>
    </div>
  </section>

  <div data-view-panel="saved"<?= $view === 'saved' ? '' : ' hidden' ?>>
    <?php /* 2026-11-17: the masthead has no "Alerte" item; the way to the alerts is here, for each reader (no script). */ ?>
    <section data-alerts-block aria-labelledby="alerts-block-title" class="mt-6 max-w-2xl">
      <h2 id="alerts-block-title" class="font-display text-base font-semibold italic text-ink">Alerte pe email</h2>
      <p class="mt-1 text-sm text-ink-muted">Primești pe email posturile noi dintr-o căutare salvată sau de la un angajator urmărit, o dată la 3 zile.</p>
      <a href="/alerte/" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-gov underline underline-offset-2 hover:no-underline">Gestionează alertele</a>
    </section>

    <?php /* UX-15: the „Căutări salvate” section. static/searches.js fills it from `posturi.searches.v1`. */ ?>
    <section data-searches-panel hidden aria-labelledby="searches-heading" class="mt-6">
      <h2 id="searches-heading" data-searches-heading tabindex="-1" class="font-display text-xl font-semibold italic text-ink focus:outline-none">Căutări salvate <span class="font-mono text-xs not-italic font-normal text-ink-muted" data-count="searches"></span></h2>
      <p data-searches-status role="status" aria-live="polite" class="min-h-4 text-xs text-ink-muted"></p>
      <div data-searches-empty hidden class="mt-2 py-4">
        <p class="text-sm text-ink-muted" data-searches-empty-text></p>
        <a href="/posturi/" class="mt-3 inline-block text-sm text-gov hover:underline">Caută anunțuri</a>
      </div>
      <ul data-searches-list class="divide-y divide-line"></ul>
    </section>

    <?php /* UX-FOLLOW-EMP: the „Angajatori urmăriți” section. static/employers.js fills it from `posturi.employers.v1`. */ ?>
    <section data-employers-panel aria-labelledby="employers-heading" class="mt-8">
      <h2 id="employers-heading" data-employers-heading tabindex="-1" class="font-display text-xl font-semibold italic text-ink focus:outline-none">Angajatori urmăriți <span class="font-mono text-xs not-italic font-normal text-ink-muted" data-count="employers"></span></h2>
      <p data-employers-status role="status" aria-live="polite" class="min-h-4 text-xs text-ink-muted"></p>
      <div data-employers-empty hidden class="mt-2 py-4">
        <p class="text-sm text-ink-muted" data-employers-empty-text></p>
        <a href="/angajatori/" class="mt-3 inline-block text-sm text-gov hover:underline">Vezi angajatorii</a>
      </div>
      <ul data-employers-list class="divide-y divide-line"></ul>
    </section>
  </div>

  <div data-view-panel="lista"<?= $view === 'flux' ? ' hidden' : '' ?>>
    <h2 data-saved-heading class="mt-8 font-display text-xl font-semibold italic text-ink"><span data-saved-heading-text>Anunțuri salvate</span> <span class="font-mono text-xs not-italic font-normal text-ink-muted" data-saved-heading-count></span></h2>

    <div data-saved-empty hidden class="mt-8 py-8 text-center">
      <p class="text-sm text-ink-muted" data-empty-text></p>
      <a href="/posturi/" class="mt-3 inline-block text-sm text-gov hover:underline">Caută anunțuri</a>
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
        <button type="button" data-clear-employers class="<?= $btn ?>">Șterge angajatorii urmăriți</button>
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
      <div data-employers-confirm hidden role="alertdialog" aria-labelledby="employers-confirm-text" class="mt-3 max-w-md border border-alert-line bg-alert p-3 text-sm text-alert-ink">
        <p id="employers-confirm-text" data-employers-confirm-text class="leading-snug"></p>
        <div class="mt-2 flex gap-2">
          <button type="button" data-employers-yes class="min-h-[2.5rem] border border-alert-line bg-surface px-3 py-2 text-xs font-medium text-alert-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">Confirmă</button>
          <button type="button" data-employers-no class="<?= $btn ?>">Anulează</button>
        </div>
      </div>
    </section>
  </div>
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
    <p class="mt-1 text-xs text-ink-muted"><span data-f="meta"></span><span data-f="default" hidden> · <span class="font-medium text-ink">implicită</span></span><span data-f="alert-asked" hidden> · alertă cerută <span data-f="alert-asked-date"></span></span></p>
    <div class="mt-2 flex flex-wrap items-center gap-2">
      <button type="button" data-search-rename class="<?= $btn ?>">Redenumește<span class="sr-only" data-f="sr-rename"></span></button>
      <button type="button" data-search-delete class="<?= $btn ?>">Șterge<span class="sr-only" data-f="sr-delete"></span></button>
      <a data-f="rss" class="<?= $btn ?>">RSS</a>
      <a data-f="alerts" class="<?= $btn ?>">Alertă pe email<span class="sr-only" data-f="sr-alerts"></span></a>
    </div>
  </li>
</template>

<template id="employer-item-tpl">
  <li class="py-4">
    <h3 class="font-display text-lg font-semibold italic leading-snug text-ink">
      <a data-f="link" class="inline-block py-0.5 hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus"></a>
    </h3>
    <p class="mt-1 text-xs text-ink-muted"><span data-f="meta"></span><span data-f="alert-asked" hidden> · alertă cerută <span data-f="alert-asked-date"></span></span></p>
    <div class="mt-2 flex flex-wrap items-center gap-2">
      <button type="button" data-employer-unfollow class="<?= $btn ?>">Nu mai urmări<span class="sr-only" data-f="sr-unfollow"></span></button>
      <a data-f="rss" class="<?= $btn ?>">RSS</a>
      <a data-f="alerts" class="<?= $btn ?>">Alertă pe email<span class="sr-only" data-f="sr-alerts"></span></a>
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

<?php /* The Flux card: the saved-item card as a normal row for saved.js (no data-pref-managed), so that save, hide and note work as on the list. */ ?>
<template id="flux-item-tpl">
  <li data-posting data-pref-hideable class="py-4">
    <h3 class="font-display text-lg font-semibold italic leading-snug text-ink">
      <a data-f="link" class="inline-block py-0.5 hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus"></a>
    </h3>
    <p data-f="meta" class="mt-1 text-xs text-ink-muted"></p>
    <p data-f="deadline" class="mt-0.5 font-mono text-xs text-ink-muted"></p>
    <p data-f="note" class="mt-0.5 text-[11px] italic leading-snug text-ink-muted"></p>
    <p data-f="salary" hidden class="mt-0.5 text-xs text-ink-muted"></p>
    <p data-f="sources" class="mt-1 flex flex-wrap gap-1"></p>
    <div class="mt-2 flex flex-wrap items-center gap-2">
      <button type="button" data-pref="save" class="<?= $btn ?>"><span data-pref-label></span><span class="sr-only" data-f="sr-title"></span></button>
      <button type="button" data-pref="hide" class="<?= $btn ?>"><span data-pref-label></span><span class="sr-only" data-f="sr-title2"></span></button>
      <button type="button" data-pref="note" aria-expanded="false" class="<?= $btn ?>"><span data-pref-label></span></button>
    </div>
  </li>
</template>
<script src="/static/flux.js?v=<?= @filemtime(__DIR__ . '/../static/flux.js') ?: '1' ?>" defer></script>
<?php require __DIR__ . '/../inc/footer.php'; ?>
