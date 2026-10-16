<?php
$page_title = 'Despre';
require __DIR__ . '/../inc/header.php';
?>
<div class="max-w-screen-md mx-auto px-4 sm:px-6 py-10">
  <h1 class="font-display text-[2rem] italic font-semibold text-ink mb-6">Despre</h1>

  <div class="prose-body text-sm leading-relaxed text-ink space-y-4">
<p>
      <strong>posturi.gov2.ro</strong> este un explorator independent al anunțurilor de angajare din sectorul
      public, fără nicio afiliere oficială cu posturi.gov.ro sau cu autoritățile publice. Site-ul este în
      <strong>versiune beta</strong>: funcționează, dar încă îl îmbunătățim și poate conține erori.
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

    <h2 id="cum-functioneaza">Cum funcționează</h2>
    <p>
      De două ori pe zi citim lista de anunțuri de pe posturi.gov.ro, descărcăm paginile noi sau modificate
      și documentele atașate, apoi un model de inteligență artificială (un „robot”) le restructurează:
      termene, cerințe de studii și experiență, documente de depus, etapele concursului. Textul original
      al anunțului rămâne pe pagina fiecărui post.
    </p>
    <p>
      Restructurarea automată poate greși: poate omite un detaliu, poate citi greșit un tabel sau un document
      scanat, sau poate combina informații de la posturi diferite din același anunț. De aceea fiecare
      pagină trimite la <strong>anunțul oficial</strong>, care este singura sursă care contează.
      Dacă găsiți o greșeală, folosiți butonul <b>?!</b> din colțul paginii.
    </p>

    <h2 id="termene">Termene și starea înscrierilor</h2>
    <p>
      Pentru fiecare anunț căutăm data-limită de depunere a dosarelor. Când o găsim în anunț, postul apare
      ca „înscrieri deschise” până la acea dată. Când anunțul nu o precizează clar, folosim data de expirare
      a anunțului de pe posturi.gov.ro și o marcăm drept <em>estimată</em> — de obicei înscrierile se închid
      mai devreme. Starea se calculează după data de azi (ora României), așa că poate fi depășită cu cel
      mult o oră după miezul nopții.
    </p>

    <h2 id="salarii">Salarii</h2>
    <p>
      Foarte puține anunțuri menționează salariul. Când îl menționează, îl afișăm ca „salariu anunțat”.
      Altfel afișăm un <strong>salariu estimat</strong> (brut), calculat din grila de salarizare din
      proiectul legii salarizării, după funcție, studii, grad și mărimea localității. Este o estimare
      orientativă, nu o ofertă: sporurile, vechimea și încadrarea exactă le stabilește angajatorul.
    </p>

    <h2 id="anunturi-inchise">Anunțuri închise sau dispărute</h2>
    <p>
      Păstrăm un anunț în listă cât timp este publicat pe posturi.gov.ro. După ce expiră sau este anulat,
      dispare din căutare. Anunțurile salvate rămân în lista dvs. de <a href="/salvate/" class="text-gov hover:underline">Salvate</a>,
      marcate ca indisponibile.
    </p>

    <h2 id="aplicare">Cum aplic?</h2>
    <p>
      Nu se poate aplica prin acest site. Dosarul se depune la instituția care organizează concursul,
      exact cum cere anunțul oficial (la sediu, prin poștă sau electronic). Lista de documente și
      verificarea „Pot aplica?” de pe pagina postului sunt doar ajutoare de pregătire: doar instituția
      decide cine este admis.
    </p>

    <h2 id="confidentialitate">Confidențialitate</h2>
    <p>
      Site-ul nu are conturi și nu setează cookie-uri. Ce salvați — anunțurile salvate sau ascunse,
      bifele din lista de pregătire, modul de afișare, stilul vizual și data ultimei vizite (pentru mesajul
      de bun venit) — rămâne <strong>în browserul dvs.</strong> (localStorage): nu ajunge la noi, nu se
      sincronizează între dispozitive și se pierde dacă ștergeți datele browserului. Răspunsurile din
      verificarea „Pot aplica?” nu se salvează deloc.
    </p>
    <p>
      Site-ul este servit prin Cloudflare, care vede adresa IP și cererile, ca orice rețea de livrare.
      Folosim statisticile de trafic Cloudflare (agregate, fără cookie-uri) ca să știm câte pagini sunt
      vizitate. Formularul de sugestii este un Google Form (se aplică politica Google), iar problemele
      raportate pe GitHub sunt publice.
    </p>
    <p class="text-xs text-ink-muted">
      Datele de contact din anunțuri (nume, telefoane, e-mailuri) sunt cele publicate de instituții pe
      posturi.gov.ro, pentru candidați.
    </p>
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
    <h2 id="versiunea-datelor">Versiunea acestor date</h2>
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
      <dt class="text-ink-faint">Bază generată</dt>
      <dd class="text-ink"><?= e($_bt) ?> <span class="text-ink-faint">(ora României)</span></dd>

      <dt class="text-ink-faint">Sursă verificată</dt>
      <dd class="text-ink">
        <?php if (!empty($_bm['detail_fetched_at_max'])): ?>
          <?= fmt_date($_bm['detail_fetched_at_max']) ?>
          <span class="text-ink-faint">— data la care pagina anunțului a fost citită cu succes, direct de la sursă</span>
        <?php else: ?>
          <span class="text-note-ink">neverificată</span>
          <span class="text-ink-faint">— acest export nu conține încă observații de sursă (metadatele au apărut pe 04.10.2026)</span>
        <?php endif; ?>
      </dd>

      <dt class="text-ink-faint">Anunțuri active</dt>
      <dd class="text-ink"><?= number_format((int) $_bm['job_postings'], 0, ',', '.') ?></dd>

      <dt class="text-ink-faint">Angajatori</dt>
      <dd class="text-ink"><?= number_format((int) $_bm['employers'], 0, ',', '.') ?></dd>

      <dt class="text-ink-faint">Evenimente de concurs</dt>
      <dd class="text-ink"><?= number_format((int) $_bm['calendar_events'], 0, ',', '.') ?></dd>

<?php if (!empty($_bm['git_sha'])): ?>
      <dt class="text-ink-faint">Versiune date</dt>
      <dd>
        <a href="https://github.com/gov2-ro/posturi.gov.ro/commit/<?= e($_bm['git_sha']) ?>"
           class="text-gov hover:underline" target="_blank" rel="noopener"><?= e($_bm['git_sha']) ?></a>
        <span class="text-ink-faint">— commit-ul care a construit baza de date</span>
      </dd>
<?php endif; ?>
<?php if (code_version() !== ''): ?>
      <dt class="text-ink-faint">Versiune cod</dt>
      <dd>
        <a href="https://github.com/gov2-ro/posturi.gov.ro/commit/<?= e(code_version()) ?>"
           class="text-gov hover:underline" target="_blank" rel="noopener"><?= e(code_version()) ?></a>
        <span class="text-ink-faint">— commit-ul care a construit site-ul servit acum</span>
      </dd>
<?php endif; ?>
    </dl>
    <p class="text-xs text-ink-muted">
      „Bază generată” este momentul în care fișierul de date servit acum a fost construit.
      „Sursă verificată” este ultima citire cu succes a paginii anunțului direct de pe
      posturi.gov.ro — cele două sunt fapte diferite și apar separat. O bază proaspăt
      generată nu înseamnă că sursa a fost citită azi, și invers.
    </p>
<?php else: ?>
    <p class="text-xs text-ink-muted">
      Această versiune a bazei de date nu conține informații despre generarea ei.
    </p>
<?php endif; ?>

    <h2 id="contact">Contact</h2>
    <p>
      Sugestii și greșeli: <a href="https://forms.gle/96WusM2qr4pbUXhW7" target="_blank" rel="noopener">formular</a>,
      <a href="mailto:<?= e(FEEDBACK_EMAIL) ?>"><?= e(FEEDBACK_EMAIL) ?></a>
      sau <a href="https://github.com/gov2-ro/posturi.gov.ro/issues" target="_blank" rel="noopener">GitHub</a>.
      Codul sursă este public: <a href="https://github.com/gov2-ro/posturi.gov.ro" target="_blank" rel="noopener">gov2-ro/posturi.gov.ro</a>.
    </p>
  </div>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
