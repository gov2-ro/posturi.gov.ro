<?php
declare(strict_types=1);

/**
 * UX-11 — "Pregătirea dosarului": the application-preparation checklist of one posting.
 *
 * Included by pages/detail.php, which already holds $p (the posting row), $events (its
 * calendar rows) and $dl (posting_deadline()). Everything here is rendered on the server and
 * is a complete, read-only list without JavaScript: documents and stages with their dates,
 * the passed state and the official link. static/checklist.js then adds the browser-local
 * check boxes, reader-added items and the revision review. Server markup carries no progress.
 *
 * Source facts and reader-added items look different on purpose: source rows are plain
 * lines under a neutral rule, reader rows sit in the info family with an "Adăugat de dvs." tag.
 * Reader-added items ("Elementele dvs.") are off unless checklist_custom_items_enabled().
 */

require_once __DIR__ . '/../inc/checklist.php';

$cl = checklist_model($p, $events, $dl);
$cl_docs   = array_values(array_filter($cl['items'], static fn($i) => $i['kind'] === 'document'));
$cl_stages = array_values(array_filter($cl['items'], static fn($i) => $i['kind'] === 'stage'));
$cl_official = evidence_safe_url((string)($p['url'] ?? ''));
$cl_has_deadline = (bool)array_filter($cl_stages, static fn($i) => ($i['evidence']['basis'] ?? '') === 'resolved');
$cl_custom = checklist_custom_items_enabled();
?>
<section id="pregatire-dosar" data-checklist aria-labelledby="cl-heading" class="mb-8">
  <h2 id="cl-heading" class="mb-3 border-b border-line pb-2 font-display text-xl font-semibold italic text-ink">
    Pregătirea dosarului
  </h2>
  <p class="mb-4 text-xs text-ink-muted">
    Lista provine din extragerea automată a anunțului și poate fi incompletă.
    <?php if ($cl_official): ?>
      <a href="<?= e($cl_official) ?>" target="_blank" rel="noopener noreferrer" class="text-gov underline hover:no-underline" data-cl-official>Anunțul oficial ↗</a>
    <?php endif; ?>
    rămâne autoritatea pentru documentele și termenele cerute.
  </p>

  <div class="mb-5" data-cl-region="document">
    <h3 class="mb-1 text-xs font-semibold uppercase tracking-widest text-ink-muted">Documente</h3>
    <?php if ($cl_docs): ?>
      <ul class="border-y border-line divide-y divide-line" data-cl-list="document">
        <?php foreach ($cl_docs as $it): ?>
        <li class="flex flex-wrap items-center gap-x-3 gap-y-0 border-l-2 border-line-strong py-1.5 pl-3 pr-2" data-cl-item data-cl-id="<?= e($it['id']) ?>" data-cl-kind="document">
          <span class="min-w-0 flex-1 basis-60 break-words text-sm text-ink" data-cl-label><?= e($it['label']) ?></span>
          <span data-cl-slot></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <p class="mt-1 text-xs text-ink-muted" data-cl-summary="document" hidden></p>
    <?php else: ?>
      <?php /* An empty extraction is "we do not know", never "no documents are required". */ ?>
      <p class="rounded border border-note-line bg-note px-3 py-2 text-sm text-note-ink" data-cl-empty="document">
        Lista documentelor nu a fost extrasă automat. Consultați anunțul oficial pentru documentele cerute.
      </p>
    <?php endif; ?>
  </div>

  <div class="mb-5" data-cl-region="stage">
    <h3 class="mb-1 text-xs font-semibold uppercase tracking-widest text-ink-muted">Termene și etape</h3>
    <?php if ($cl_stages): ?>
      <ul class="border-y border-line divide-y divide-line" data-cl-list="stage">
        <?php foreach ($cl_stages as $it):
            $is_deadline = ($it['evidence']['basis'] ?? '') === 'resolved';
            $time = $it['time'] ?? null;
            $src  = (string)($it['evidence']['deadline_source'] ?? '');
        ?>
        <li class="flex flex-wrap items-center gap-x-3 gap-y-0 border-l-2 border-line-strong py-1.5 pl-3 pr-2" data-cl-item data-cl-id="<?= e($it['id']) ?>" data-cl-kind="stage"
            data-cl-precision="<?= e($it['precision'] ?? 'none') ?>"<?= !empty($it['passed']) ? ' data-cl-passed="1"' : '' ?><?= $is_deadline ? ' data-cl-deadline' : '' ?>>
          <span class="min-w-0 flex-1 basis-60 break-words text-sm text-ink" data-cl-label><?= e($it['label']) ?></span>
          <span class="whitespace-nowrap font-mono text-xs text-ink-muted">
            <?php if (isset($it['date'])): ?>
              <time datetime="<?= e($it['date'] . ($time !== null ? 'T' . $time : '')) ?>"><?= e(fmt_date($it['date'])) ?><?= $time !== null ? e(', ora ' . $time) : '' ?></time>
            <?php else: ?>
              data neprecizată
            <?php endif; ?>
          </span>
          <?php if (!empty($it['passed'])): ?>
            <span class="border border-neutral-line bg-neutral px-1.5 py-0.5 text-[11px] text-neutral-ink" data-cl-passed-tag><?= !empty($it['estimated']) ? 'Termen estimat trecut' : 'Termen trecut' ?></span>
          <?php endif; ?>
          <span data-cl-slot></span>
          <?php if ($is_deadline): ?>
            <div class="basis-full pb-1 text-xs text-ink-muted">
              <?php if ($time === null): ?><span data-cl-date-only>doar data; ora nu este precizată în date</span><?php endif; ?>
              <?php if ($src !== '' && isset(DEADLINE_SOURCE_LABELS[$src])): ?>
                <span class="text-ink-faint"><?= $time === null ? '· ' : '' ?><?= e(DEADLINE_SOURCE_LABELS[$src]) ?></span>
              <?php endif; ?>
              <?php if (!empty($it['estimated'])): ?>
                <?php /* The expiry fallback must read as uncertainty, as on the page's own deadline block. */ ?>
                <div class="mt-1 rounded border border-note-line bg-note px-2 py-1 text-note-ink" data-cl-estimated><?= e(DEADLINE_FALLBACK_NOTE) ?>.</div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="rounded border border-note-line bg-note px-3 py-2 text-sm text-note-ink" data-cl-empty="stage">
        Etapele concursului nu au fost extrase automat. Consultați anunțul oficial pentru calendar.
      </p>
    <?php endif; ?>
    <?php if (!$cl_has_deadline): ?>
      <p class="mt-1 text-xs text-ink-muted" data-cl-no-deadline>Termenul de depunere nu este precizat în datele extrase; verificați anunțul oficial.</p>
    <?php endif; ?>
  </div>

  <noscript>
    <p class="mb-3 text-xs text-ink-muted">Bifarea elementelor se păstrează în browser și necesită JavaScript. Lista și legătura către anunțul oficial funcționează fără el.</p>
  </noscript>

  <?php /* Everything below is for readers with JavaScript: checklist.js un-hides it. */ ?>
  <div hidden data-cl-js>
    <p class="mb-3 rounded border border-note-line bg-note px-3 py-2 text-xs text-note-ink" data-cl-notice role="note" hidden></p>
    <p class="mb-3 rounded border border-neutral-line bg-neutral px-3 py-2 text-xs text-neutral-ink" data-cl-flash hidden></p>

    <div class="mb-4 rounded border border-info-line bg-info px-3 py-2 text-sm text-info-ink" data-cl-review hidden>
      <h3 class="mb-1 font-semibold">Anunțul s-a modificat de la ultima vizită</h3>
      <p class="mb-2 text-xs">Bifele s-au păstrat doar pentru elementele rămase identice. Verificați ce s-a schimbat în anunțul oficial.</p>
      <div data-cl-review-added hidden>
        <p class="text-xs font-semibold uppercase tracking-widest">Elemente noi sau modificate</p>
        <ul class="mb-2 list-disc pl-5 text-sm" data-cl-review-added-list></ul>
      </div>
      <div data-cl-review-removed hidden>
        <p class="text-xs font-semibold uppercase tracking-widest">Elemente bifate care nu mai apar identic</p>
        <ul class="mb-2 list-disc pl-5 text-sm" data-cl-review-removed-list></ul>
      </div>
      <button type="button" data-cl-dismiss class="min-h-[2.75rem] border border-gov bg-gov px-3 py-2 text-xs font-medium text-on-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 sm:min-h-[2.25rem]">Am înțeles</button>
    </div>

    <div class="mb-4 rounded border border-note-line bg-note px-3 py-2 text-sm text-note-ink" data-cl-unconfirmed hidden>
      <h3 class="mb-1 font-semibold">Progres salvat, neconfirmat pentru versiunea curentă</h3>
      <p class="mb-1 text-xs">Nu putem confirma că aceste elemente se potrivesc cu versiunea curentă a anunțului, deci nu sunt aplicate listei de mai sus.</p>
      <ul class="list-disc pl-5" data-cl-unconfirmed-list></ul>
    </div>

<?php if ($cl_custom): ?>
    <div class="mb-4" data-cl-custom-region>
      <h3 class="mb-1 text-xs font-semibold uppercase tracking-widest text-ink-muted">Elementele dvs.</h3>
      <ul class="mb-2 space-y-1" data-cl-custom-list></ul>
      <div class="flex flex-wrap items-center gap-2">
        <label for="cl-add-input" class="sr-only">Adaugă un element al dvs. (maximum 120 de caractere)</label>
        <input id="cl-add-input" type="text" maxlength="120" autocomplete="off" enterkeyhint="done" data-cl-add-input
               placeholder="Adaugă un element de pregătit"
               class="min-h-[2.75rem] min-w-0 flex-1 basis-56 border border-line bg-surface px-2 py-1 text-sm text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-focus sm:min-h-[2.25rem]">
        <button type="button" data-cl-add class="min-h-[2.75rem] border border-gov bg-gov px-3 py-2 text-xs font-medium text-on-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 sm:min-h-[2.25rem]">Adaugă</button>
      </div>
      <p class="mt-1 text-xs text-ink-faint">Nu scrie date personale (CNP, adresă, telefon). Textul rămâne doar în acest browser.</p>
    </div>
    <?php endif; ?>

  </div>

  <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-cl-live></div>
</section>

<template id="cl-tpl-check">
  <label class="inline-flex min-h-[2.75rem] cursor-pointer items-center gap-2 py-1 text-sm text-ink sm:min-h-[2rem]">
    <input type="checkbox" data-cl-check class="h-5 w-5 shrink-0 accent-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">
    <span data-cl-check-text></span>
  </label>
</template>
<?php if ($cl_custom): ?>
<template id="cl-tpl-custom">
  <li class="flex flex-wrap items-center gap-x-3 gap-y-0 border-l-2 border-info-line bg-info py-1 pl-3 pr-2" data-cl-custom-item>
    <span class="border border-info-line bg-surface px-1.5 py-0.5 text-[11px] font-medium text-info-ink">Adăugat de dvs.</span>
    <span class="min-w-0 flex-1 basis-40 break-words text-sm text-info-ink" data-cl-custom-label></span>
    <span data-cl-custom-slot></span>
    <button type="button" data-cl-remove class="min-h-[2.75rem] px-2 text-sm text-info-ink underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus sm:min-h-[2rem]">Elimină</button>
  </li>
</template>
<?php endif; ?>
<template id="cl-tpl-line"><li></li></template>

<script type="application/json" id="checklist-data"><?= checklist_json($cl) ?></script>
<script src="/static/checklist.js?v=<?= @filemtime(__DIR__ . '/../static/checklist.js') ?: '1' ?>" defer></script>
