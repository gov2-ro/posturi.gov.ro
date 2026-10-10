<?php
/**
 * UX-10 — "Pot aplica?": a preparation aid on the detail page, collapsed by default.
 *
 * The server embeds, per role, the stated requirements and their evidence state; the
 * comparison runs in the browser (static/eligibility.js), so the reader's answers are
 * never sent anywhere, stored or put in the URL. To that end the controls sit in a plain
 * group, NOT in a <form>: a control with no form owner cannot be submitted, whatever
 * its attributes. Only the credential radios carry a `name`, because a native radio group
 * needs one; nothing else has any.
 *
 * Included once by pages/detail.php after the structured-requirements section, in that
 * page's scope ($p, $schema, $evidence). Emits nothing when no role states anything
 * comparable, or when the export predates posting_roles. Without JavaScript the body is a
 * note; the requirements themselves are listed elsewhere on the page.
 */
require_once __DIR__ . '/../inc/eligibility.php';
if (!eligibility_panel_enabled()) return;   // release gate, see inc/eligibility.php

$el = eligibility_panel_data(
    db(),
    (int)$p['id'],
    is_array($schema ?? null) ? $schema : [],
    is_array($evidence ?? null) ? $evidence : []
);
if ($el === null) return;

$el_official = evidence_safe_url((string)($p['url'] ?? ''));
$el_roles    = $el['roles'];
$el_multi    = count($el_roles) > 1;

$el_field = 'w-full rounded-md border border-line-strong bg-surface px-2 py-2 text-sm text-ink focus:outline-none focus:border-gov focus:ring-1 focus:ring-focus';
$el_label = 'mb-1 block text-xs font-semibold uppercase tracking-widest text-ink-muted';

// One result-row template per state. Classes are written out in full (never assembled)
// so the stylesheet build sees them, and only role/semantic tokens appear: ok, alert, note.
$el_states = [
    'match'   => ['box' => 'border-ok-line bg-ok text-ok-ink',        'icon' => '✓', 'text' => 'Îndeplinit'],
    'missing' => ['box' => 'border-alert-line bg-alert text-alert-ink', 'icon' => '✕', 'text' => 'Lipsește'],
    'unknown' => ['box' => 'border-note-line bg-note text-note-ink',    'icon' => '?', 'text' => 'De verificat'],
];
?>
<section class="mb-6" data-eligibility-section>
  <details data-eligibility class="rounded-lg border border-line bg-surface/60">
    <summary class="cursor-pointer list-none rounded-lg px-4 py-3 font-display text-lg font-semibold italic text-ink marker:content-none hover:text-gov focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus">
      Pot aplica? — verificare orientativă <span aria-hidden="true" class="font-sans text-sm not-italic text-ink-muted">▾</span>
    </summary>

    <div class="space-y-4 border-t border-line px-4 py-4 text-sm text-ink">
      <p data-eligibility-disclaimer>
        Un ajutor de pregătire, nu o decizie. Doar angajatorul, pe baza anunțului oficial, stabilește cine este admis.
        <?php if ($el_official): ?>
          <a href="<?= e($el_official) ?>" target="_blank" rel="noopener noreferrer" class="text-gov hover:underline">Anunțul oficial ↗</a>
        <?php endif; ?>
      </p>

      <p data-eligibility-nojs class="rounded border border-note-line bg-note px-3 py-2 text-xs text-note-ink">
        Comparația funcționează în browser, cu JavaScript activat. Cerințele din sursă sunt listate mai sus.
      </p>

      <div data-eligibility-app hidden class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <?php if ($el_multi): ?>
          <div class="sm:col-span-2">
            <label for="elig-role" class="<?= $el_label ?>">Postul comparat</label>
            <select id="elig-role" data-field="role" autocomplete="off" class="<?= $el_field ?>">
              <?php foreach ($el_roles as $ri => $role): ?>
                <option value="<?= (int)$ri ?>"><?= e($role['ordinal'] . '. ' . $role['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <div class="sm:col-span-2">
            <label for="elig-edu" class="<?= $el_label ?>">Nivelul dvs. de studii</label>
            <select id="elig-edu" data-field="education" autocomplete="off" class="<?= $el_field ?>">
              <option value="">Nu știu</option>
              <?php foreach (ELIGIBILITY_STUDY_LEVELS as $value => $label): ?>
                <option value="<?= e($value) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label for="elig-total" class="<?= $el_label ?>">Ani de experiență, în total</label>
            <input id="elig-total" type="number" min="0" step="0.5" inputmode="decimal" data-field="total" autocomplete="off" class="<?= $el_field ?>">
          </div>
          <div>
            <label for="elig-spec" class="<?= $el_label ?>">Ani în specialitatea postului (opțional)</label>
            <input id="elig-spec" type="number" min="0" step="0.5" inputmode="decimal" data-field="specialty" autocomplete="off" class="<?= $el_field ?>">
          </div>
        </div>

        <?php foreach ($el_roles as $ri => $role): ?>
          <?php if (!$role['credentials']) continue; ?>
          <div data-credentials-for="<?= (int)$ri ?>" class="space-y-3"<?= $ri === 0 ? '' : ' hidden' ?>>
            <?php foreach ($role['credentials'] as $ci => $cred): ?>
              <fieldset data-credential="<?= (int)$ci ?>" class="min-w-0">
                <legend class="mb-1 text-sm font-medium text-ink">Dețin: <?= e($cred['label']) ?></legend>
                <div class="flex flex-wrap gap-x-5 gap-y-1">
                  <?php foreach (['da' => 'Da', 'nu' => 'Nu', 'nu_stiu' => 'Nu știu'] as $opt => $opt_label): ?>
                    <label class="inline-flex min-h-[2.5rem] items-center gap-2">
                      <input type="radio" name="elig-<?= (int)$ri ?>-<?= (int)$ci ?>" value="<?= e($opt) ?>" autocomplete="off"<?= $opt === 'nu_stiu' ? ' checked' : '' ?>>
                      <span><?= e($opt_label) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <div class="flex flex-wrap items-center gap-3">
          <button type="button" data-action="reset"
                  class="min-h-[2.5rem] border border-line px-3 py-2 text-xs text-ink-muted hover:border-gov hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">Resetează răspunsurile</button>
          <p data-note role="status" aria-live="polite" class="text-xs text-ink-muted"></p>
        </div>

        <div>
          <h3 class="mb-2 text-xs font-semibold uppercase tracking-widest text-ink-muted">Comparație pe cerințe</h3>
          <div data-results-region aria-live="polite" class="space-y-2">
            <p data-tally class="text-sm font-medium text-ink"></p>
            <ul data-results class="space-y-2"></ul>
          </div>
        </div>

        <p class="text-xs text-ink-muted">
          Răspunsurile rămân în această pagină: nu sunt trimise, salvate sau puse în adresă, și dispar la reîncărcare.
          Rezultatul nu este o decizie de admitere.
        </p>
      </div>

      <?php foreach ($el_states as $state => $s): ?>
      <template data-row-template="<?= $state ?>">
        <li data-row class="rounded border px-3 py-2 <?= $s['box'] ?>">
          <p class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
            <span class="font-medium" data-slot="label"></span>
            <span class="whitespace-nowrap rounded border border-current bg-surface/70 px-1.5 py-0.5 text-xs font-semibold"><span aria-hidden="true"><?= $s['icon'] ?></span> <?= e($s['text']) ?></span>
          </p>
          <p class="mt-1 text-xs" data-slot="explain"></p>
          <p class="mt-1 text-xs" data-slot="source" hidden><a href="#" data-source-link class="underline">vezi sursa</a></p>
        </li>
      </template>
      <?php endforeach; ?>
    </div>
  </details>
  <?php /* Outside the <details>: a script element inside a disclosure would read as active text from a source quote to the evidence checks. */ ?>
  <script type="application/json" id="eligibility-data"><?= $el['json'] ?></script>
</section>
<script src="/static/eligibility.js?v=<?= @filemtime(__DIR__ . '/../static/eligibility.js') ?: '1' ?>" defer></script>
