<?php
/**
 * UX-EMP-01 — /pentru-angajatori/anunt/: the employer posting builder.
 *
 * A static shell: the page is cached publicly, so it holds no user data. The form,
 * the profile of the institution and the drafts live in this browser
 * (static/employer-builder.js; `posturi.employer.v1`, `posturi.employer-drafts.v1`).
 * The download is a .docx that carries the structured posting (ADR 0002).
 *
 * Scope of v1: one post per anunț, contractual staff (HG 1336/2022).
 * Every class name is written out here, not built in JS, so that Tailwind finds it.
 */
declare(strict_types=1);

$page_title = 'Anunț structurat pentru angajatori';
$meta_description = 'Completați un formular și descărcați un anunț de concurs în format .docx, cu datele structurate încorporate. Datele rămân în browserul dumneavoastră.';

$study_levels = ['generala', 'profesionala', 'liceala', 'postliceala', 'licenta', 'master', 'doctorat'];
$study_labels = ['generala' => 'Generale (8 clase)', 'profesionala' => 'Școală profesională', 'liceala' => 'Liceale', 'postliceala' => 'Postliceale',
    'licenta' => 'Superioare (licență)', 'master' => 'Master', 'doctorat' => 'Doctorat'];
$judete = judet_names();
asort($judete);

$field  = 'mt-1 w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-gov focus:outline-none focus:ring-1 focus:ring-focus';
$legend = 'text-xs font-semibold uppercase tracking-widest text-ink-muted';
$hint   = 'mt-1 text-xs text-ink-muted';
$btn    = 'min-h-[2.5rem] rounded-md border border-line-strong bg-surface px-3 py-2 text-sm text-ink hover:border-gov hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus disabled:opacity-50';
$btn_main = 'min-h-[2.5rem] rounded-md border border-gov bg-gov px-4 py-2 text-sm font-medium text-on-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50';
$sec    = 'mt-8 border-t border-line pt-6 scroll-mt-4';
$h2     = 'font-display text-xl font-semibold italic text-ink';
$tag    = 'ml-2 inline-block rounded border border-note-line bg-note px-1.5 py-0.5 align-middle text-[0.7rem] font-normal normal-case tracking-normal text-note-ink';

/** A labelled input. $kind is the binding kind of employer-builder.js. */
$input = function (string $label, string $path, string $id, array $o = []) use ($field, $legend, $hint, $tag): void {
    $type = $o['type'] ?? 'text';
    $kind = $o['kind'] ?? 'text';
    $attrs = $o['attrs'] ?? '';
    echo '<div class="' . ($o['wrap'] ?? '') . '"><label for="' . e($id) . '" class="' . $legend . '">' . e($label);
    if (!empty($o['req'])) echo '<span class="' . $tag . '">obligatoriu (art. 18 alin. 2)</span>';
    echo '</label>';
    if ($type === 'textarea') {
        echo '<textarea id="' . e($id) . '" rows="' . (int)($o['rows'] ?? 4) . '" class="' . $field . '" data-path="' . e($path) . '" data-kind="' . e($kind) . '" ' . $attrs . '></textarea>';
    } else {
        echo '<input id="' . e($id) . '" type="' . e($type) . '" class="' . $field . '" data-path="' . e($path) . '" data-kind="' . e($kind) . '" ' . $attrs . '>';
    }
    if (!empty($o['hint'])) echo '<p class="' . $hint . '">' . e($o['hint']) . '</p>';
    echo '</div>';
};

$select = function (string $label, string $path, string $id, array $options, array $o = []) use ($field, $legend, $hint, $tag): void {
    echo '<div class="' . ($o['wrap'] ?? '') . '"><label for="' . e($id) . '" class="' . $legend . '">' . e($label);
    if (!empty($o['req'])) echo '<span class="' . $tag . '">obligatoriu (art. 18 alin. 2)</span>';
    echo '</label><select id="' . e($id) . '" class="' . $field . '" data-path="' . e($path) . '" data-kind="text"><option value="">— alege —</option>';
    foreach ($options as $v => $l) echo '<option value="' . e((string)$v) . '">' . e($l) . '</option>';
    echo '</select>';
    if (!empty($o['hint'])) echo '<p class="' . $hint . '">' . e($o['hint']) . '</p>';
    echo '</div>';
};

$cls = [
    'link' => 'text-gov underline hover:no-underline',
    'okLink' => 'text-ok-ink underline hover:no-underline',
    'missingLi' => 'py-0.5 text-sm',
    'pvH1' => 'font-display text-lg font-semibold italic text-ink',
    'pvH2' => 'mt-4 text-xs font-semibold uppercase tracking-widest text-ink-muted',
    'pvP' => 'mt-1 text-sm leading-snug text-ink',
    'pvDl' => 'mt-1 grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-[12rem_1fr]',
    'pvDt' => 'font-semibold text-ink-muted',
    'pvDd' => 'text-ink',
    'pvUl' => 'mt-1 list-disc space-y-0.5 pl-5 text-sm text-ink',
    'pvWrap' => 'mt-1 overflow-x-auto',
    'pvTable' => 'w-full border-collapse text-sm',
    'pvTh' => 'border-b border-line-strong px-2 py-1 text-left font-semibold text-ink-muted',
    'pvTd' => 'border-b border-line px-2 py-1 text-ink',
];

$v = static fn(string $f): string => '/static/' . $f . '?v=' . (@filemtime(__DIR__ . '/../static/' . $f) ?: '1');

require __DIR__ . '/../inc/header.php';
?>
<div class="w-full max-w-3xl px-4 sm:px-6 py-6" data-employer-builder>
  <script type="application/json" data-config><?= json_encode(['cls' => $cls], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

  <p class="text-sm"><a href="/angajatori/" class="text-gov hover:underline">← Angajatori</a></p>
  <h1 class="mt-2 font-display text-[1.7rem] sm:text-[2rem] font-semibold italic leading-tight text-ink">Anunț structurat</h1>
  <p class="mt-2 text-sm leading-snug text-ink-muted">
    Completați formularul și descărcați anunțul ca fișier .docx. Fișierul poartă și datele structurate ale anunțului, ca site-ul să le citească exact.
    Versiunea 1 acoperă un singur post pentru fiecare anunț și doar personalul contractual (HG 1336/2022). Funcționarii publici (OUG 57/2019) au alte termene.
  </p>
  <p class="mt-3 border border-info-line bg-info px-3 py-2 text-sm leading-snug text-info-ink">
    Datele rămân doar în acest browser. Pe un calculator comun, șterge-le când termini.
  </p>
  <p class="mt-2 text-xs leading-snug text-ink-muted">Acesta este un instrument neoficial. Nu trimite nimic la un server și nu publică anunțul.</p>

  <p data-nostorage hidden class="mt-3 border border-alert-line bg-alert px-3 py-2 text-sm leading-snug text-alert-ink" role="alert">
    Browserul nu lasă pagina să păstreze datele. Poți lucra, dar ciorna se pierde la închiderea paginii. Descarcă anunțul înainte să pleci.
  </p>
  <p data-profile-blocked hidden class="mt-3 border border-alert-line bg-alert px-3 py-2 text-sm leading-snug text-alert-ink" role="alert">
    Profilul instituției din acest browser nu poate fi citit. Nu l-am modificat. Salvarea profilului este oprită.
  </p>
  <div data-unavailable hidden class="mt-4 border border-alert-line bg-alert px-3 py-3 text-sm leading-snug text-alert-ink" role="alert">
    <p><strong>Ciornele nu sunt disponibile.</strong> Datele păstrate în acest browser sunt deteriorate sau au altă versiune. Nu le-am modificat. Formularul este oprit ca să nu le suprascriem.</p>
    <p class="mt-2">Dacă nu mai ai nevoie de ele, le poți șterge.</p>
    <button type="button" data-action="reset-drafts" class="<?= $btn ?> mt-2">Șterge datele nefolosibile</button>
  </div>

  <noscript><p class="mt-6 text-sm text-ink-muted">Instrumentul are nevoie de JavaScript.</p></noscript>

  <div data-form-wrap>
    <section class="mt-6" aria-labelledby="drafts-h">
      <h2 id="drafts-h" class="<?= $h2 ?>">Ciorne <span class="text-sm font-normal not-italic text-ink-muted" data-draft-count></span></h2>
      <ul data-draft-list class="mt-2 divide-y divide-line border border-line"></ul>
      <div class="mt-2 flex flex-wrap gap-2">
        <button type="button" data-action="new-draft" class="<?= $btn ?>">Ciornă nouă</button>
        <button type="button" data-action="export-all" class="<?= $btn ?>">Exportă datele</button>
        <label class="<?= $btn ?> cursor-pointer">Importă datele
          <input type="file" accept="application/json,.json" data-import class="sr-only">
        </label>
      </div>
      <p class="<?= $hint ?>">Exportul conține profilul instituției și toate ciornele, într-un singur fișier .json.</p>
    </section>

    <template data-draft-item>
      <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
        <span class="min-w-0 flex-1 text-sm">
          <span data-di-title class="font-medium text-ink"></span>
          <span data-di-current hidden class="ml-1 text-xs text-ok-ink">(deschisă)</span>
          <span data-di-date class="block text-xs text-ink-muted"></span>
        </span>
        <button type="button" data-action="open-draft" class="<?= $btn ?>">Deschide</button>
        <button type="button" data-action="duplicate-draft" class="<?= $btn ?>">Duplică</button>
        <button type="button" data-action="delete-draft" class="<?= $btn ?>">Șterge</button>
      </li>
    </template>

    <section class="mt-6 border border-line bg-surface p-4" aria-labelledby="meter-h">
      <h2 id="meter-h" class="<?= $h2 ?>">Cât de complet este anunțul</h2>
      <p class="mt-1 text-sm text-ink"><span class="text-2xl font-semibold" data-meter-score>0</span> din 100</p>
      <div class="mt-1 h-2 w-full bg-sunken" role="meter" aria-labelledby="meter-h" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-meter>
        <div class="h-2 bg-gov" style="width:0%" data-meter-bar></div>
      </div>
      <h3 class="mt-3 <?= $legend ?>">De completat</h3>
      <ul class="mt-1" data-meter-missing></ul>
      <h3 class="mt-3 <?= $legend ?>">Elementele obligatorii ale anunțului (art. 18 alin. 2)</h3>
      <ul class="mt-1" data-mandatory></ul>
      <p class="<?= $hint ?>">Poți descărca anunțul și cu elemente lipsă. Scorul este un ajutor, nu o regulă.</p>
    </section>

    <form id="builder-form" class="mt-2" onsubmit="return false" novalidate>

      <section id="sec-1" class="<?= $sec ?>" aria-labelledby="h-1">
        <h2 id="h-1" class="<?= $h2 ?>">1. Instituția</h2>
        <p class="<?= $hint ?>">Aceste date pot fi păstrate ca profil al instituției și apar în fiecare ciornă nouă.</p>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <?php $input('Denumirea instituției', 'meta.institution.name', 'f-inst-name', ['wrap' => 'sm:col-span-2', 'attrs' => 'autocomplete="organization"']); ?>
          <?php $input('CUI', 'meta.institution.cui', 'f-inst-cui'); ?>
          <?php $input('Adresa', 'meta.institution.address', 'f-inst-address', ['attrs' => 'autocomplete="street-address"']); ?>
          <?php $input('Localitatea', 'meta.institution.locality', 'f-inst-locality'); ?>
          <div>
            <label for="f-inst-county" class="<?= $legend ?>">Județul</label>
            <select id="f-inst-county" class="<?= $field ?>" data-path="meta.institution.county" data-kind="text">
              <option value="">— alege —</option>
              <?php foreach ($judete as $name): ?><option value="<?= e($name) ?>"><?= e($name) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php $input('Telefon', 'meta.institution.phone', 'f-inst-phone', ['type' => 'tel', 'attrs' => 'autocomplete="tel"']); ?>
          <?php $input('E-mail', 'meta.institution.email', 'f-inst-email', ['type' => 'email', 'attrs' => 'autocomplete="email"']); ?>
        </div>
        <div class="mt-3"><button type="button" data-action="save-profile" class="<?= $btn ?>">Salvează ca profil</button>
          <span class="<?= $hint ?>">Salvează și canalele de depunere și documentele bifate mai jos.</span></div>
      </section>

      <section id="sec-2" class="<?= $sec ?>" aria-labelledby="h-2">
        <h2 id="h-2" class="<?= $h2 ?>">2. Postul</h2>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <?php $input('Denumirea postului', 'occupation_title', 'f-title', ['wrap' => 'sm:col-span-2', 'req' => true]); ?>
          <?php $input('Numărul de posturi', 'vacancies_total', 'f-vacancies', ['type' => 'number', 'kind' => 'int', 'req' => true, 'attrs' => 'min="1" max="5000" step="1" inputmode="numeric"', 'hint' => 'Un singur post pentru fiecare anunț în această versiune. Numărul poate fi mai mare dacă toate posturile sunt la fel.']); ?>
          <?php $input('Structura (compartiment, serviciu)', 'meta.department', 'f-dept', ['req' => true]); ?>
          <?php $select('Statutul postului', 'contract.post_status', 'f-status', ['vacant' => 'Vacant', 'temporar_vacant' => 'Temporar vacant']); ?>
          <?php $select('Durata contractului', 'contract.duration', 'f-duration', ['nedeterminata' => 'Nedeterminată', 'determinata' => 'Determinată', 'sezonier' => 'Sezonier', 'proiect' => 'Pe durata unui proiect'], ['req' => true]); ?>
          <?php $input('Durata în luni (pentru contract pe durată determinată)', 'contract.duration_months', 'f-months', ['type' => 'number', 'kind' => 'int', 'attrs' => 'min="1" step="1" inputmode="numeric"']); ?>
          <?php $select('Programul de lucru', 'contract.schedule', 'f-schedule', ['norma_intreaga' => 'Normă întreagă', 'norma_partiala' => 'Normă parțială', 'schimburi' => 'În schimburi', 'tura_noapte' => 'Tură de noapte', 'flexibil' => 'Flexibil'], ['req' => true]); ?>
          <?php $input('Ore pe săptămână', 'contract.hours_per_week', 'f-hours', ['type' => 'number', 'kind' => 'num', 'attrs' => 'min="1" max="80" step="any" inputmode="decimal"']); ?>
          <?php $input('Locul muncii (adresa)', 'jobLocation', 'f-location', ['wrap' => 'sm:col-span-2']); ?>
          <?php $input('Localități în care se lucrează', 'work_localities', 'f-localities', ['type' => 'textarea', 'kind' => 'lines', 'rows' => 2, 'wrap' => 'sm:col-span-2', 'hint' => 'Una pe linie. Lasă gol dacă este doar localitatea instituției.']); ?>
        </div>
      </section>

      <section id="sec-3" class="<?= $sec ?>" aria-labelledby="h-3">
        <h2 id="h-3" class="<?= $h2 ?>">3. Cerințe</h2>
        <p class="<?= $hint ?>">Condițiile generale de la art. 15 se aplică tuturor candidaților. Nu le copia aici. Anunțul trimite la ele.</p>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <?php $select('Nivelul studiilor', 'education.minimum_level', 'f-edu', $study_labels, ['req' => true]); ?>
          <?php $input('Domeniul sau specializarea', 'education.fields_of_study', 'f-fields', ['type' => 'textarea', 'kind' => 'labels', 'rows' => 3, 'attrs' => 'data-key="label_ro"', 'hint' => 'Un domeniu pe linie. Fiecare linie este o alternativă.']); ?>
          <fieldset class="sm:col-span-2">
            <legend class="<?= $legend ?>">Experiența<span class="<?= $tag ?>">obligatoriu (art. 18 alin. 2)</span></legend>
            <div class="mt-1 flex flex-wrap items-end gap-x-6 gap-y-2">
              <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" class="accent-gov" data-path="experience.none_required" data-kind="bool"> Fără experiență</label>
              <?php $input('Ani de experiență (minimum)', 'experience.years_minimum', 'f-exp', ['type' => 'number', 'kind' => 'num', 'attrs' => 'min="0" max="50" step="0.5" inputmode="decimal"']); ?>
              <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" class="accent-gov" data-path="experience.in_specialty" data-kind="bool"> În specialitate</label>
            </div>
          </fieldset>
          <?php $input('Atestate, autorizații, permise', 'credentials', 'f-creds', ['type' => 'textarea', 'kind' => 'creds', 'rows' => 3, 'hint' => 'Unul pe linie, de exemplu „permis categoria B”.']); ?>
          <?php $input('Limbi străine', 'language_list', 'f-langs', ['type' => 'textarea', 'kind' => 'langs', 'rows' => 3, 'hint' => 'Una pe linie, cu nivelul dacă îl știi: „engleză B2”.']); ?>
          <?php $input('Alte condiții specifice', 'qualifications', 'f-qual', ['type' => 'textarea', 'rows' => 3, 'wrap' => 'sm:col-span-2']); ?>
        </div>
      </section>

      <section id="sec-4" class="<?= $sec ?>" aria-labelledby="h-4">
        <h2 id="h-4" class="<?= $h2 ?>">4. Salariul</h2>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <?php $input('Salariul minim (lei brut pe lună)', 'baseSalary.minValue', 'f-sal-min', ['type' => 'number', 'kind' => 'num', 'attrs' => 'min="0" step="any" inputmode="decimal"', 'hint' => 'Pentru o sumă fixă, completează doar un câmp.']); ?>
          <?php $input('Salariul maxim (lei brut pe lună)', 'baseSalary.maxValue', 'f-sal-max', ['type' => 'number', 'kind' => 'num', 'attrs' => 'min="0" step="any" inputmode="decimal"']); ?>
        </div>
        <p class="mt-2 border border-note-line bg-note px-3 py-2 text-sm leading-snug text-note-ink" data-salary-nudge>Candidații văd salariul înainte să aplice. Anunțurile fără salariu primesc mai puține dosare.</p>
      </section>

      <section id="sec-5" class="<?= $sec ?>" aria-labelledby="h-5">
        <h2 id="h-5" class="<?= $h2 ?>">5. Atribuții</h2>
        <?php $input('Atribuțiile postului', 'responsibilities', 'f-duties', ['type' => 'textarea', 'kind' => 'bullets', 'rows' => 6, 'hint' => 'Una pe linie.']); ?>
      </section>

      <section id="sec-6" class="<?= $sec ?>" aria-labelledby="h-6">
        <h2 id="h-6" class="<?= $h2 ?>">6. Dosarul de concurs<span class="<?= $tag ?>">obligatoriu (art. 18 alin. 2)</span></h2>
        <fieldset class="mt-3">
          <legend class="<?= $legend ?>">Documente standard</legend>
          <div class="mt-2 grid gap-y-1 sm:grid-cols-2 sm:gap-x-4">
            <?php foreach (DOCUMENT_CODE_LABELS as $code => $label): ?>
            <label class="flex items-start gap-2 text-sm text-ink"><input type="checkbox" class="mt-1 accent-gov" data-path="documents.standard" data-kind="checks" value="<?= e($code) ?>"> <span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <div class="mt-4">
          <?php $input('Alte documente cerute', 'documents.specific', 'f-docs-extra', ['type' => 'textarea', 'kind' => 'labels', 'rows' => 3, 'attrs' => 'data-key="label"', 'hint' => 'Unul pe linie.']); ?>
        </div>
      </section>

      <section id="sec-7" class="<?= $sec ?>" aria-labelledby="h-7">
        <h2 id="h-7" class="<?= $h2 ?>">7. Bibliografie și tematică</h2>
        <?php $input('Bibliografia și tematica', 'bibliography_topics', 'f-bib', ['type' => 'textarea', 'kind' => 'lines', 'rows' => 6, 'req' => true, 'hint' => 'Un act normativ sau un capitol pe linie.']); ?>
      </section>

      <section id="sec-8" class="<?= $sec ?>" aria-labelledby="h-8">
        <h2 id="h-8" class="<?= $h2 ?>">8. Depunerea dosarului</h2>
        <fieldset class="mt-3">
          <legend class="<?= $legend ?>">Dosarul se depune</legend>
          <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1">
            <?php foreach (APPLICATION_CHANNEL_LABELS as $code => $label): ?>
            <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" class="accent-gov" data-path="application.channels" data-kind="checks" value="<?= e($code) ?>"> <?= e(ucfirst($label)) ?></label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <?php $input('Adresa de depunere', 'application_contact.address', 'f-ap-address', ['req' => true]); ?>
          <?php $input('E-mail pentru depunere și contact', 'application_contact.email', 'f-ap-email', ['type' => 'email']); ?>
          <?php $input('Telefon de contact', 'application_contact.phone', 'f-ap-phone', ['type' => 'tel']); ?>
          <?php $input('Persoana de contact', 'application_contact.name', 'f-ap-name'); ?>
          <?php $input('Taxa de participare (lei)', 'application_fee.amount', 'f-fee', ['type' => 'number', 'kind' => 'num', 'attrs' => 'min="0" step="any" inputmode="decimal"', 'hint' => 'Lasă gol dacă nu există taxă.']); ?>
          <?php $input('Detalii despre taxă (cont, scutiri)', 'application_fee.details', 'f-fee-details'); ?>
        </div>
      </section>

      <section id="sec-9" class="<?= $sec ?>" aria-labelledby="h-9">
        <h2 id="h-9" class="<?= $h2 ?>">9. Calendarul concursului<span class="<?= $tag ?>">obligatoriu (art. 18 alin. 2)</span></h2>
        <p class="<?= $hint ?>">Alege data publicării și statutul postului. Pagina propune datele din HG 1336/2022. Poți schimba orice dată. Un avertisment nu oprește descărcarea.</p>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <?php $input('Data publicării anunțului', 'application.window_start', 'f-pub', ['type' => 'date', 'kind' => 'text']); ?>
          <?php $select('Statutul postului', 'contract.post_status', 'f-status-cal', ['vacant' => 'Vacant', 'temporar_vacant' => 'Temporar vacant']); ?>
        </div>
        <label class="mt-3 flex items-center gap-2 text-sm text-ink"><input type="checkbox" class="accent-gov" data-path="meta.interview" data-kind="bool"> Concursul are și interviu</label>
        <div class="mt-3"><button type="button" data-action="propose" class="<?= $btn ?>">Propune calendarul</button></div>
        <p class="<?= $hint ?>">Am numărat ziua publicării ca prima zi a termenului de depunere. Zilele libere legale sunt cele din art. 139 din Codul muncii pentru 2026 și 2027. Propunerea folosește maximul permis pentru fiecare etapă, iar proba scrisă vine cât mai devreme.</p>
        <p data-cal-year-note hidden class="mt-2 border border-alert-line bg-alert px-3 py-2 text-sm text-alert-ink" role="status">Zilele libere legale nu sunt cunoscute pentru acest an. Calculul ia în seamă doar sâmbetele și duminicile.</p>
        <p data-cal-empty class="mt-3 text-sm text-ink-muted">Calendarul apare după ce alegi data publicării și statutul postului.</p>
        <div class="mt-3 overflow-x-auto">
          <table class="w-full border-collapse text-sm">
            <thead><tr>
              <th class="border-b border-line-strong px-2 py-1 text-left text-xs font-semibold text-ink-muted" scope="col">Etapa</th>
              <th class="border-b border-line-strong px-2 py-1 text-left text-xs font-semibold text-ink-muted" scope="col">Data</th>
              <th class="border-b border-line-strong px-2 py-1 text-left text-xs font-semibold text-ink-muted" scope="col">Ora</th>
              <th class="border-b border-line-strong px-2 py-1 text-left text-xs font-semibold text-ink-muted" scope="col">Temei</th>
            </tr></thead>
            <tbody data-cal-body></tbody>
          </table>
        </div>
        <template data-cal-row>
          <tr class="align-top">
            <td class="border-b border-line px-2 py-2 text-ink"><span data-cal-label></span>
              <p data-cal-warn hidden class="mt-1 border border-alert-line bg-alert px-2 py-1 text-xs text-alert-ink" role="alert"></p></td>
            <td class="border-b border-line px-2 py-2"><input type="date" data-ev-date class="<?= $field ?> mt-0 min-w-[9.5rem]"></td>
            <td class="border-b border-line px-2 py-2"><input type="time" data-ev-time class="<?= $field ?> mt-0 min-w-[6rem]"></td>
            <td class="border-b border-line px-2 py-2 text-xs text-ink-muted"><span data-cal-art></span> <span data-cal-tag class="ml-1 border border-neutral-line bg-neutral px-1 text-neutral-ink"></span></td>
          </tr>
        </template>
      </section>
    </form>

    <section class="<?= $sec ?>" aria-labelledby="h-prev">
      <h2 id="h-prev" class="<?= $h2 ?>">Previzualizare</h2>
      <div class="mt-3 border border-line bg-surface p-4" data-preview></div>
    </section>

    <section class="<?= $sec ?>" aria-labelledby="h-dl">
      <h2 id="h-dl" class="<?= $h2 ?>">Descarcă</h2>
      <div class="mt-3 flex flex-wrap gap-2">
        <button type="button" data-action="download-docx" class="<?= $btn_main ?>">Descarcă anunțul (.docx)</button>
        <button type="button" data-action="download-json" class="<?= $btn ?>">Descarcă datele (.json)</button>
      </div>
      <p class="mt-2 text-sm leading-snug text-ink">Datele structurate se pierd dacă salvezi anunțul ca PDF. Publică fișierul .docx.</p>
      <p class="<?= $hint ?>">Datele structurate se pierd și dacă copiezi textul în alt document. Dacă schimbi textul din fișier după descărcare, datele încorporate nu se schimbă.</p>
      <p data-status role="status" class="mt-2 text-sm text-ink-muted"></p>
    </section>
  </div>
</div>

<script src="<?= e($v('vendor/docx.min.js')) ?>" defer></script>
<script src="<?= e($v('ro-holidays.js')) ?>" defer></script>
<script src="<?= e($v('employer-docx.js')) ?>" defer></script>
<script src="<?= e($v('employer-builder.js')) ?>" defer></script>
<?php require __DIR__ . '/../inc/footer.php'; ?>
