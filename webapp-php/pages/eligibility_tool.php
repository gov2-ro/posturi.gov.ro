<?php
/**
 * 261016 — /pot-aplica/: "Pot aplica?" as a tool, not a panel on each posting.
 *
 * The reader states what they have — highest studies, years of experience,
 * counties, a driving licence, foreign languages — and gets the list filtered
 * to postings whose stated requirements they meet. Nothing goes to the server
 * but the resulting filter URL: the profile stays in this browser
 * (`posturi.profile.v1`), like Salvate.
 *
 * The mapping is the list's own filters, so the result is explainable and
 * editable in the sidebar afterwards. Every rule is an exclusion ("fără"), so a
 * posting that states no requirement stays in, as it should — the reader checks it:
 *   studies    → studies_level_not[] = every level above theirs
 *   experience → exp_level_not[]     = every bucket that starts above their years
 *   counties   → judet[]
 *   no licence → credential_not[] = permis_conducere
 *   languages  → lang_not[] for the common languages they do not speak (and
 *                "any foreign language" when they speak none)
 */
declare(strict_types=1);

$page_title = 'Pot aplica?';
$meta_description = 'Spuneți ce studii și experiență aveți și vedeți anunțurile ale căror cerințe le îndepliniți.';

// Highest level first in the select; the order is also the ladder the script climbs.
$levels = ['generala', 'liceala', 'postliceala', 'licenta', 'master', 'doctorat'];
$langs  = ['en' => 'Engleză', 'fr' => 'Franceză', 'de' => 'Germană', 'hu' => 'Maghiară', 'it' => 'Italiană', 'es' => 'Spaniolă'];
$judete = judet_names();
asort($judete);

$field = 'mt-1 w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-gov focus:outline-none focus:ring-1 focus:ring-focus';
$legend = 'text-xs font-semibold uppercase tracking-widest text-ink-muted';

require __DIR__ . '/../inc/header.php';
?>
<div class="w-full max-w-3xl px-4 sm:px-6 py-6" data-eligibility-tool>
  <h1 class="font-display text-[1.7rem] sm:text-[2rem] font-semibold italic leading-tight text-ink">Pot aplica?</h1>
  <p class="mt-2 text-sm leading-snug text-ink-muted">
    Spuneți ce aveți; vă arătăm anunțurile ale căror cerințe le îndepliniți. Răspunsurile rămân în acest browser.
    Cerințele sunt extrase automat din anunțuri — cele valabile sunt cele din anunțul oficial.
  </p>

  <form id="profile-form" class="mt-6 space-y-6" action="/" method="get" novalidate>
    <div>
      <label for="pf-studies" class="<?= $legend ?>">Cele mai înalte studii absolvite</label>
      <select id="pf-studies" name="pf_studies" class="<?= $field ?>">
        <option value="">— nu filtra după studii —</option>
        <?php foreach ($levels as $lv): ?>
        <option value="<?= e($lv) ?>"><?= e(STUDIES_LABELS[$lv] ?? $lv) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label for="pf-exp" class="<?= $legend ?>">Ani de experiență în muncă</label>
      <input id="pf-exp" name="pf_exp" type="number" min="0" max="60" step="1" inputmode="numeric" placeholder="ex. 3" class="<?= $field ?> max-w-[10rem]">
      <p class="mt-1 text-xs text-ink-muted">Gol = nu filtra după experiență. 0 = fără experiență. Intervalele sunt cele din filtre (1–2, 3–5, 5+ ani), deci aproximative.</p>
    </div>

    <fieldset>
      <legend class="<?= $legend ?>">Județe</legend>
      <p class="mt-1 text-xs text-ink-muted">Niciunul bifat = toată țara.</p>
      <div class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-3">
        <?php foreach ($judete as $slug => $name): ?>
        <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" name="pf_judet" value="<?= e((string)$slug) ?>" class="accent-gov"> <?= e($name) ?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <fieldset>
      <legend class="<?= $legend ?>">Permis de conducere</legend>
      <div class="mt-2 flex gap-4 text-sm text-ink">
        <label class="flex items-center gap-2"><input type="radio" name="pf_licence" value="" class="accent-gov" checked> Nu contează</label>
        <label class="flex items-center gap-2"><input type="radio" name="pf_licence" value="no" class="accent-gov"> Nu am</label>
      </div>
    </fieldset>

    <fieldset>
      <legend class="<?= $legend ?>">Limbi străine pe care le vorbiți</legend>
      <p class="mt-1 text-xs text-ink-muted">Anunțurile care cer o limbă pe care nu o bifați sunt scoase. Niciuna bifată și „nu filtra” debifat = scoatem și anunțurile care cer orice limbă străină.</p>
      <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-ink">
        <label class="flex items-center gap-2"><input type="checkbox" name="pf_lang_any" value="1" class="accent-gov" checked> Nu filtra după limbi</label>
        <?php foreach ($langs as $code => $name): ?>
        <label class="flex items-center gap-2"><input type="checkbox" name="pf_lang" value="<?= e($code) ?>" class="accent-gov"> <?= e($name) ?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="flex flex-wrap items-center gap-3">
      <button type="submit" class="rounded-md bg-gov px-5 py-2.5 text-sm font-medium text-on-gov focus:outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2">Arată anunțurile potrivite</button>
      <button type="button" data-profile-clear class="text-sm text-ink-muted underline hover:text-gov">Șterge răspunsurile</button>
      <span data-profile-status role="status" class="text-xs text-ink-muted"></span>
    </div>
  </form>

  <noscript><p class="mt-6 text-sm text-ink-muted">Instrumentul are nevoie de JavaScript. Aceleași filtre sunt în <a href="/" class="text-gov underline">lista principală</a>: Nivel de studii, Experiență minimă, Județ, Documente necesare („fără”).</p></noscript>
</div>

<script>
(function () {
  'use strict';
  var KEY = 'posturi.profile.v1';
  var LEVELS = <?= json_encode($levels) ?>;
  var LANGS = <?= json_encode(array_keys($langs)) ?>;
  var form = document.getElementById('profile-form');
  var status = document.querySelector('[data-profile-status]');

  function read() { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } }
  function write(p) { try { localStorage.setItem(KEY, JSON.stringify(p)); return true; } catch (e) { return false; } }

  function collect() {
    var fd = new FormData(form);
    return {
      v: 1,
      studies: fd.get('pf_studies') || '',
      exp: (fd.get('pf_exp') || '').trim(),
      judete: fd.getAll('pf_judet'),
      licence: fd.get('pf_licence') || '',
      langAny: fd.get('pf_lang_any') === '1',
      langs: fd.getAll('pf_lang')
    };
  }

  function fill(p) {
    if (!p) return;
    form.pf_studies.value = p.studies || '';
    form.pf_exp.value = p.exp || '';
    form.querySelectorAll('[name="pf_judet"]').forEach(function (c) { c.checked = (p.judete || []).indexOf(c.value) >= 0; });
    form.querySelectorAll('[name="pf_licence"]').forEach(function (r) { r.checked = r.value === (p.licence || ''); });
    form.pf_lang_any.checked = p.langAny !== false;
    form.querySelectorAll('[name="pf_lang"]').forEach(function (c) { c.checked = (p.langs || []).indexOf(c.value) >= 0; });
  }

  /** The list URL for a profile: the sidebar's own filters (see the header comment). */
  function urlFor(p) {
    var q = new URLSearchParams();
    if (p.studies && LEVELS.indexOf(p.studies) >= 0) {
      LEVELS.slice(LEVELS.indexOf(p.studies) + 1).forEach(function (l) { q.append('studies_level_not[]', l); });
    }
    if (p.exp !== '' && !isNaN(+p.exp)) {
      var y = +p.exp;
      // Buckets as EXP_BUCKETS: fara <1, 1-2 [1,3), 3-5 [3,6), 5plus ≥5.
      if (y < 1) q.append('exp_level_not[]', '1-2');
      if (y < 3) q.append('exp_level_not[]', '3-5');
      if (y < 5) q.append('exp_level_not[]', '5plus');
    }
    (p.judete || []).forEach(function (j) { q.append('judet[]', j); });
    if (p.licence === 'no') q.append('credential_not[]', 'permis_conducere');
    if (!p.langAny) {
      LANGS.forEach(function (l) { if ((p.langs || []).indexOf(l) < 0) q.append('lang_not[]', l); });
      if (!(p.langs || []).length) q.append('lang_not[]', 'straina');
    }
    var s = q.toString();
    return '/' + (s ? '?' + s : '');
  }

  fill(read());
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var p = collect();
    var kept = write(p);
    if (!kept) status.textContent = 'Răspunsurile nu au putut fi păstrate în browser; filtrul se aplică oricum.';
    window.location.href = urlFor(p);
  });
  document.querySelector('[data-profile-clear]').addEventListener('click', function () {
    try { localStorage.removeItem(KEY); } catch (e) {}
    form.reset();
    status.textContent = 'Răspunsurile au fost șterse din acest browser.';
  });
  // Ticking a language means languages matter.
  form.querySelectorAll('[name="pf_lang"]').forEach(function (c) {
    c.addEventListener('change', function () { if (c.checked) form.pf_lang_any.checked = false; });
  });
  window.posturiProfileUrl = urlFor;   // for the tests
})();
</script>
<?php require __DIR__ . '/../inc/footer.php'; ?>
