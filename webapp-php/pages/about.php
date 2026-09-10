<?php
$page_title = 'Despre';
require __DIR__ . '/../inc/header.php';
?>
<div class="max-w-screen-md mx-auto px-4 sm:px-6 py-10">
  <h1 class="font-display text-[2rem] italic font-semibold text-ink mb-6">Despre</h1>

  <div class="prose-body text-sm leading-relaxed text-ink space-y-4">
<mark>⚠️ WIP / MVP - versiune in lucru</mark>
  <p>
      <strong>posturi.gov2.ro</strong> este un explorator independent, fără nicio afiliere oficială cu posturi.gov.ro sau cu autoritățile publice.
    </p>

 
    <p>
      Datele sunt preluate periodic de pe portalul oficial <a href="https://posturi.gov.ro" class="text-gov hover:underline" target="_blank" rel="noopener">posturi.gov.ro</a>
      și procesate automat pentru a permite căutare avansată, filtrare și statistici.
    </p>
    <h2>Ce oferă acest site</h2>
    <ul>
      <li>Căutare full-text și filtrare după județ, nivel, tip, domeniu, grad, studii etc.</li>
      <li>Statistici agregate despre piața muncii în sectorul public</li>
      <li>Clasificare automată a anunțurilor (domeniu profesional, nivel de experiență, studii)</li>
      <li>Export în formate standard: <a href="/posturi.json" class="text-gov hover:underline">JSON</a>, <a href="/posturi.atom" class="text-gov hover:underline">Atom</a>, <a href="/posturi.ics" class="text-gov hover:underline">iCal</a> — respectă filtrele active, iar cu <code>?employer=&lt;slug&gt;</code> se restrâng la un singur angajator (link direct pe fiecare pagină de angajator)</li>
      <li>Calendarul iCal se poate <strong>abona</strong> (nu doar descărca): adaugă adresa <code>/posturi.ics?…</code> în Google Calendar (<em>Alte calendare → Prin URL</em>) sau într-un client care acceptă <code>webcal://</code>. Filtrează cu aceiași parametri ca lista (<code>?judet[]=cluj&amp;judet[]=timis</code>) și denumește calendarul cu <code>?title=</code> — util pentru mai multe feed-uri, câte unul pe județ</li>
    </ul>

  </div>

  <?php /* Statistici and Angajatori used to be masthead links. They are pages
     you read once rather than places you navigate between, so they live here
     now — as cards, since a bare list item is easy to miss on the one page that
     has to carry them.

     Outside the .prose-body wrapper on purpose: `.prose-body a` is (0,1,1) and
     underlines every link it contains, which a utility class cannot override.
     Hence also the hand-set heading size, matching `.prose-body h2`. */ ?>
  <section class="my-6">
    <h2 class="mb-3 font-display text-[1.15rem] font-semibold italic text-ink">Vezi și</h2>
    <div class="grid gap-3 sm:grid-cols-2">
      <?php foreach ([
        ['/statistici/', 'Statistici', 'Distribuția anunțurilor pe județe, domenii, grade și termene.'],
        ['/angajatori/', 'Angajatori', 'Instituțiile care recrutează, cu numărul de posturi al fiecăreia.'],
      ] as [$href, $label, $blurb]): ?>
        <a href="<?= e($href) ?>"
           class="block rounded border border-line bg-surface p-4 no-underline transition-colors hover:border-gov hover:bg-gov-light">
          <span class="block font-display text-lg font-semibold italic text-ink"><?= e($label) ?></span>
          <span class="mt-1 block text-xs text-ink-muted"><?= e($blurb) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="prose-body text-sm leading-relaxed text-ink space-y-4">
    <h2>Versiunea acestor date</h2>
    <p>
      Site-ul nu interoghează portalul oficial în timp real: servește o bază de date
      generată periodic din arhiva noastră. Pipeline-ul rulează de două ori pe zi, iar
      fiecare rulare reconstruiește complet fișierul de mai jos, păstrând doar anunțurile
      încă active.
    </p>
<?php $_bm = build_meta(); $_bt = build_time(); ?>
<?php if ($_bm && $_bt): ?>
    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-xs font-mono
               bg-sunken border border-line rounded p-4 my-4">
      <dt class="text-ink-faint">Generată la</dt>
      <dd class="text-ink"><?= e($_bt) ?> <span class="text-ink-faint">(ora României)</span></dd>

      <dt class="text-ink-faint">Anunțuri active</dt>
      <dd class="text-ink"><?= number_format((int) $_bm['job_postings'], 0, ',', '.') ?></dd>

      <dt class="text-ink-faint">Angajatori</dt>
      <dd class="text-ink"><?= number_format((int) $_bm['employers'], 0, ',', '.') ?></dd>

      <dt class="text-ink-faint">Evenimente de concurs</dt>
      <dd class="text-ink"><?= number_format((int) $_bm['calendar_events'], 0, ',', '.') ?></dd>

<?php if (!empty($_bm['git_sha'])): ?>
      <dt class="text-ink-faint">Cod sursă</dt>
      <dd>
        <a href="https://github.com/gov2-ro/posturi.gov.ro/commit/<?= e($_bm['git_sha']) ?>"
           class="text-gov hover:underline" target="_blank" rel="noopener"><?= e($_bm['git_sha']) ?></a>
      </dd>
<?php endif; ?>
    </dl>
    <p class="text-xs text-ink-muted">
      „Date actualizate la” din subsol arată ziua ultimei verificări a anunțurilor la sursă,
      urmată de ora la care s-a încheiat rularea care a construit fișierul servit acum —
      aceeași oră cu „Generată la” de mai sus. În mod normal verificarea și construcția au
      loc în aceeași zi; când nu, sursa nu a putut fi citită la ultima rulare.
    </p>
<?php else: ?>
    <p class="text-xs text-ink-muted">
      Această versiune a bazei de date nu conține informații despre generarea ei.
    </p>
<?php endif; ?>

    <hr />
    <!-- <h2>Contact</h2> -->
    <p>
        &rarr; &nbsp; <a href="https://forms.gle/96WusM2qr4pbUXhW7" target=_blank><u>Acceptăm sugestii</u></a> (gForm) &emsp; &starf; &emsp; gh: <a href="https://github.com/gov2-ro/posturi.gov.ro"><u>gov2-ro/posturi.gov.ro</u></a> 
    </p>
    
  </div>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
