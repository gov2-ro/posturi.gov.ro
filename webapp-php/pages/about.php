<?php
$page_title = 'Despre';
// The table of contents and the headings come from this one array (heading id => text). Keep each id: other pages link to them.
$toc = [
  'ce-ofera'             => 'Ce oferă acest site',
  'cum-functioneaza'     => 'Cum funcționează',
  'salarii'              => '💰💰 Salarii',
  'alerte-email'         => '🔔 Alerte pe email',
  'aplicare'             => 'Cum aplic?',
  //  'termene'              => 'Termene și starea înscrierilor',
  'anunturi-expirate'     => 'Anunțuri expirate',
  'statistici-angajatori' => 'Statistici, angajatori',
  'actualizarea-datelor'  => 'Actualizarea datelor',
  'confidentialitate'     => 'Confidențialitate',
  'contact'               => 'Contact',
];
$head_extra = '<script src="/static/toc.js?v=' . (@filemtime(__DIR__ . '/../static/toc.js') ?: '1') . '" defer></script>';
require __DIR__ . '/../inc/header.php';
?>
<div class="max-w-screen-lg mx-auto px-4 sm:px-6 py-10">
  <h1 class="font-display text-[2rem] italic font-semibold text-ink mb-6">Despre</h1>

  <div class="pg-toc-layout">
    <?php require __DIR__ . '/../partials/toc.php'; ?>
    <div class="min-w-0 max-w-screen-md">

      <div class="prose-body text-sm leading-relaxed text-ink space-y-4">
        <img src="/static/logo-g2-256.png" alt="" width="160"  class="float-right ml-4 mb-2 ">
        <p style="font-size: 1.25em; line-height: 1.5em;">
          <strong>posturi.gov2.ro</strong> este un explorator independent al anunțurilor de angajare din sectorul
          public, <mark>fără nicio afiliere oficială cu posturi.gov.ro sau cu autoritățile publice</mark>.
        </p>
        <hr/>
        <p>
          Datele sunt preluate periodic de pe portalul oficial <a href="https://posturi.gov.ro" class="text-gov hover:underline" target="_blank" rel="noopener">posturi.gov.ro</a>
          și procesate automat pentru a permite căutare avansată, filtrare și statistici.
        </p>
        <p>E un proiect <mark>încă în lucru</mark>, <em>best effort</em>, cum ar veni. <mark>
            Înainte
            de a aplica la un job, consultați întotdeauna anunțul oficial
          </mark> – găsiți link în pagina fiecărui anunț de pe posturi.gov2.ro.</p>

        <hr /><?= toc_h2($toc, 'ce-ofera') ?>
        <ul>
          <li>Căutare full-text și filtrare după județ, nivel, tip, domeniu, grad, studii etc.</li>
          <li>Statistici agregate despre piața muncii în sectorul public</li>
          <li>Clasificare automată a anunțurilor (domeniu profesional, nivel de experiență, studii)</li>
          <li>Export în formate standard: <a href="/posturi.json" class="text-gov hover:underline">JSON</a>, <a href="/posturi.atom" class="text-gov hover:underline">Atom</a>, <a href="/posturi.ics" class="text-gov hover:underline">iCal</a> — respectă filtrele active, iar cu <code>?employer=&lt;slug&gt;</code> se restrâng la un singur angajator (link direct pe fiecare pagină de angajator)</li>
          <li>Calendarul iCal se poate <strong>abona</strong> (nu doar descărca): adaugă adresa <code>/posturi.ics?…</code> în Google Calendar (<em>Alte calendare → Prin URL</em>) sau într-un client care acceptă <code>webcal://</code>. Filtrează cu aceiași parametri ca lista (<code>?judet[]=cluj&amp;judet[]=timis</code>). Calendarul primește numele din filtre (de exemplu „posturi.gov2.ro Liceală Cluj”), deci fiecare abonament filtrat are un nume propriu.</li>
        </ul>

        <hr /><?= toc_h2($toc, 'cum-functioneaza') ?>
        <p>
          De două ori pe zi citim lista de anunțuri de pe posturi.gov.ro, descărcăm paginile noi sau modificate
          și documentele atașate, apoi un robot chinezesc le restructurează:
          termene, cerințe de studii și experiență, documente de depus, etapele concursului.
        </p>
        <p>
          <mark>‼️ ⚠️ <strong>Atenție</strong></mark>: <mark>Restructurarea automată poate greși</mark>: poate omite un detaliu, poate citi greșit un tabel sau un document
          scanat, sau poate combina informații de la posturi diferite din același anunț. De aceea fiecare
          pagină trimite la <strong>anunțul oficial</strong>, care este singura sursă care contează.
          Dacă găsiți o greșeală, folosiți butonul <code>[<b>?!</b>]</code> din colțul paginii.
        </p>



        <hr /><?= toc_h2($toc, 'salarii') ?>
        <p>
          <strong>Ce arată site-ul.</strong> Foarte puține anunțuri spun salariul. Când anunțul îl spune,
          site-ul îl afișează ca „salariu anunțat”. Când anunțul nu îl spune, site-ul afișează un
          <strong>salariu net estimat</strong>. Site-ul îl calculează din salariul de bază brut după
          Legea 153/2017, la gradația 0, fără persoane în întreținere. Filtrul „Salariu” folosește tot
          salariul net. Salariul brut este pe pagina fiecărui anunț.
        </p>
        <p>
          <strong><mark>Estimarea nu este un calcul oficial</mark>.</strong> Ea nu include sporurile, gradațiile de
          vechime și indemnizația de hrană. Angajatorul stabilește salariul real. Un anunț al cărui titlu nu
          corespunde unei funcții din lege nu are estimare. Filtrul „fără salariu estimat” îl arată.
        </p>
        <p>
          <strong>În administrația locală</strong> consiliul local sau județean stabilește salariul, până la un plafon
          legal. Plafonul depinde de tipul și de populația localității. De aceea site-ul arată un interval:
          limita de jos este valoarea din tabelul legii, iar limita de sus este plafonul.
        </p>
        <figure class="my-6 flex flex-col gap-2">
          <img src="/static/img/salariu-estimare.svg" width="480" height="1128" loading="lazy"
               class="h-auto w-full max-w-[30rem] rounded border border-line"
               alt="Când anunțul spune salariul, site-ul îl afișează pe acela. Altfel, site-ul estimează salariul de bază brut din grila Legii 153/2017, după funcție și angajator, folosește grila proiectului de lege doar când legea nu are rând, și arată salariul net.">
          <figcaption class="g2-text-meta">
            Cum estimăm salariul: întâi salariul anunțat, apoi grila Legii 153/2017, iar grila proiectului
            de lege numai când legea nu are rând. Toate cifrele sunt estimări, nu calcule oficiale.
          </figcaption>
        </figure>
        <p>
          <strong>Proiectul de lege.</strong> Un proiect de lege din iulie 2026 propune alte salarii. Legea
          nu este adoptată. <a href="/estimator-salariu/" class="text-gov hover:underline">Estimatorul de salariu</a> arată și cifra după proiect.
        </p>
        <p>
          <strong>Sursele.</strong> Site-ul a citit textul legii la 18 noiembrie 2026.
        </p>
        <ul>
          <li><a href="https://legislatie.just.ro/Public/DetaliiDocument/298840" class="text-gov hover:underline" target="_blank" rel="noopener">Legea-cadru 153/2017</a>, forma consolidată (legislatie.just.ro, id 298840)</li>
          <li><a href="https://legislatie.just.ro/Public/DetaliiDocument/279777" class="text-gov hover:underline" target="_blank" rel="noopener">OUG 19/2024</a> (legislatie.just.ro, id 279777): salariile din sănătate și asistență socială</li>
          <li>OUG 115/2023 și OUG 53/2024: majorările din 2024</li>
          <li>Legea 141/2025: înghețarea salariilor în 2026</li>
          <li>HG 146/2026: salariul minim brut din 1 iulie 2026</li>
        </ul>

        <hr /><?= toc_h2($toc, 'alerte-email') ?>

        <p>
          Dacă te abonezi la o alertă pe email, vom salva niște informații, duh! Iată-le, după cum urmează:
        </p>
        <ul>
          <li><strong>Contact:</strong> <a href="&#109;&#97;&#x69;&#108;&#x74;&#111;&#58;&#99;&#x61;&#x6e;&#99;&#x65;&#108;&#97;&#x72;&#105;&#101;&#x40;&#103;&#x6f;&#118;&#50;&#46;&#114;&#111;">&#99;&#x61;&#110;&#99;&#x65;&#x6c;&#97;&#x72;&#105;&#101;&#64;&#x67;&#111;&#118;&#50;&#x2e;&#x72;&#x6f;</a></li>
          <li><strong><mark>Ce păstrăm</mark>:</strong> adresa de email, căutarea abonată (filtrele ei și numele ei), data confirmării și data ultimului rezumat. Pentru limita de cereri păstrăm o amprentă a adresei IP, cel mult 2 ore.</li>
          <li><strong>Unde:</strong> pe serverul de găzduire al site-ului (momentan în SUA 🤷🏻‍♀️🙄) păstrăm adresa <strong>criptată</strong> și căutarea. Cheia care deschide adresa și jurnalul trimiterilor sunt pe alt server, al nostru. Emailurile pleacă prin Brevo (Franța; datele rămân în UE, jurnalele se șterg după 1 lună).</li>
          <li><strong>Ce primești:</strong> un email de confirmare, apoi cel mult un rezumat la 3 zile, doar când apar anunțuri noi pentru căutarea ta.</li>
          <li><strong><mark>Numărarea deschiderilor și a click-urilor</mark>:</strong> Brevo numără anonim deschiderile și clicurile din emailuri. Numărătoarea nu este legată de adresa ta. O folosim ca să știm dacă alertele sunt utile.</li>
          <li><strong>Temeiul:</strong> consimțământul tău, dat prin abonare și confirmare. Îl poți retrage oricând.</li>
          <li><strong>Cât timp:</strong> până ștergi abonarea. O abonare neconfirmată se șterge după 48 de ore.</li>
          <li><strong><mark>Cum ștergi</mark>:</strong> apasă „Șterge această abonare” în orice email primit sau deschide <a href="/alerte/">Abonamentele mele</a> (primești un link pe email). Dacă folosești butonul de dezabonare al clientului de email, ștergem toate abonările adresei tale.</li>
          <li><strong>Drepturile tale:</strong> acces, rectificare, ștergere, restrângere și portabilitate. Scrie-ne la adresa de contact. Legea îți permite să depui o plângere la ANSPDCP (<a href="https://www.dataprotection.ro" rel="noopener">dataprotection.ro</a>). Da acum serios, de ce-ai face asta?</li>
        </ul>



        <hr /><?= toc_h2($toc, 'aplicare') ?>
        <p>
          <mark>Nu se poate aplica prin acest site.</mark> Dosarul se depune la instituția care organizează concursul,
          exact cum cere anunțul oficial (la sediu, prin poștă sau electronic). Lista de documente și
          verificarea „Pot aplica?” de pe pagina postului sunt doar ajutoare de pregătire: doar instituția
          decide cine este admis.
        </p>

        <?php /* Hidden by the owner, 2026-11-18. Restore the 'termene' key in $toc first.
        <hr/><?= toc_h2($toc, 'termene') ?>
    <p>
      Pentru fiecare anunț căutăm data-limită de depunere a dosarelor. Când o găsim în anunț, postul apare
      ca „înscrieri deschise” până la acea dată. Când anunțul nu o precizează clar, folosim data de expirare
      a anunțului de pe posturi.gov.ro și o marcăm drept <em>estimată</em> — de obicei înscrierile se închid
      mai devreme. Starea se calculează după data de azi (ora României), așa că poate fi depășită cu cel
      mult o oră după miezul nopții.
    </p> */ ?>
        <hr /><?= toc_h2($toc, 'anunturi-expirate') ?>
        <p>
          Păstrăm un anunț în listă cât timp este publicat pe posturi.gov.ro. După ce expiră sau este anulat,
          dispare din căutare. Anunțurile salvate rămân în lista dvs. de <a href="/salvate/" class="text-gov hover:underline">Salvate</a>,
          marcate ca indisponibile.
        </p>



      </div>

      <?php /* Statistici and Angajatori used to be masthead links. They are pages
     you read once rather than places you navigate between, so they live here
     now — as cards, since a bare list item is easy to miss on the one page that
     has to carry them.

     Outside the .prose-body wrapper on purpose: `.prose-body a` is (0,1,1) and
     underlines every link it contains, which a utility class cannot override.
     Hence also the hand-set heading size, matching `.prose-body h2`. */ ?>
      <br />
      <hr />
      <section class="my-6">
        <?= toc_h2($toc, 'statistici-angajatori', 'mb-3 font-display text-[1.15rem] font-semibold italic text-ink') ?>

        <div class="grid gap-3 sm:grid-cols-2">
          <?php foreach (
            [
              ['/statistici/', 'Statistici', 'Distribuția anunțurilor pe județe, domenii, grade și termene.'],
              ['/angajatori/', 'Angajatori', 'Instituțiile care recrutează, cu numărul de posturi al fiecăreia.'],
            ] as [$href, $label, $blurb]
          ): ?>
            <a href="<?= e($href) ?>"
              class="block rounded border border-line bg-surface p-4 no-underline transition-colors hover:border-gov hover:bg-gov-light">
              <span class="block font-display text-lg font-semibold italic text-ink"><?= e($label) ?></span>
              <span class="mt-1 block text-xs text-ink-muted"><?= e($blurb) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="prose-body text-sm leading-relaxed text-ink space-y-4">
        <hr /><?= toc_h2($toc, 'actualizarea-datelor') ?>
        <p>
          Site-ul nu interoghează portalul oficial în timp real: servește o bază de date
          generată periodic din arhiva noastră. Pipeline-ul rulează de două ori pe zi, iar
          fiecare rulare reconstruiește complet fișierul de mai jos, păstrând doar anunțurile
          încă active.
        </p>
        <?php $_bm = build_meta();
        $_bt = build_time(); ?>
        <?php if ($_bm && $_bt): ?>
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-xs font-mono
               bg-sunken border border-line rounded p-4 my-4">
            <dt class="text-ink-muted">Bază generată</dt>
            <dd class="text-ink"><?= e($_bt) ?> <span class="text-ink-muted">(ora României)</span></dd>

            <dt class="text-ink-muted">Sursă verificată</dt>
            <dd class="text-ink">
              <?php if (!empty($_bm['detail_fetched_at_max'])): ?>
                <?= fmt_date($_bm['detail_fetched_at_max']) ?>
                <span class="text-ink-muted">— data la care pagina anunțului a fost citită cu succes, direct de la sursă</span>
              <?php else: ?>
                <span class="text-note-ink">neverificată</span>
                <span class="text-ink-muted">— acest export nu conține încă observații de sursă (metadatele au apărut pe 04.10.2026)</span>
              <?php endif; ?>
            </dd>

            <dt class="text-ink-muted">Anunțuri active</dt>
            <dd class="text-ink"><?= number_format((int) $_bm['job_postings'], 0, ',', '.') ?></dd>

            <dt class="text-ink-muted">Angajatori</dt>
            <dd class="text-ink"><?= number_format((int) $_bm['employers'], 0, ',', '.') ?></dd>

            <dt class="text-ink-muted">Evenimente de concurs</dt>
            <dd class="text-ink"><?= number_format((int) $_bm['calendar_events'], 0, ',', '.') ?></dd>

            <?php if (!empty($_bm['git_sha'])): ?>
              <dt class="text-ink-muted">Versiune date</dt>
              <dd>
                <a href="https://github.com/gov2-ro/posturi.gov.ro/commit/<?= e($_bm['git_sha']) ?>"
                  class="text-gov hover:underline" target="_blank" rel="noopener"><?= e($_bm['git_sha']) ?></a>
                <span class="text-ink-muted">— commit-ul care a construit baza de date</span>
              </dd>
            <?php endif; ?>
            <?php if (code_version() !== ''): ?>
              <dt class="text-ink-muted">Versiune cod</dt>
              <dd>
                <a href="https://github.com/gov2-ro/posturi.gov.ro/commit/<?= e(code_version()) ?>"
                  class="text-gov hover:underline" target="_blank" rel="noopener"><?= e(code_version()) ?></a>
                <span class="text-ink-muted">— commit-ul care a construit site-ul servit acum</span>
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

        <hr /><?= toc_h2($toc, 'confidentialitate') ?>
        <p>
          Site-ul nu are conturi și nu setează cookie-uri, așa că v-am scutit de un click, măi. Anunțurile salvate sau ascunse,
          bifele din lista de pregătire, modul de afișare, stilul vizual și data ultimei vizite (pentru mesajul
          de bun venit) — rămân <strong>în browser</strong> (<a href="https://developer.mozilla.org/en-US/docs/Web/API/Window/localStorage">localStorage</a>), nu ajung la noi, nu se
          sincronizează între dispozitive și se pierd dacă ștergeți datele browserului.
        </p>
        <p>
          Site-ul este servit prin Cloudflare, care vede adresa IP și cererile, ca orice rețea de livrare.
          Folosim statisticile de trafic Cloudflare și Cloudflare Web Analytics ca să știm câte pagini sunt
          vizitate. Scriptul Cloudflare nu folosește cookie-uri, nu salvează nimic
          în browserul dvs. și nu vă urmărește de la un site la altul. Noi vedem doar totaluri. Formularul de sugestii este un Google Form (se aplică politica Google), iar problemele
          raportate pe GitHub sunt publice.
        </p>
        <p class="text-xs text-ink-muted">
          Datele de contact din anunțuri (nume, telefoane, e-mailuri) sunt cele publicate de instituții pe
          posturi.gov.ro, pentru candidați.
        </p>
        <hr /><?= toc_h2($toc, 'contact') ?>
        <p style="font-size: 1.15em; line-height: 1.5em;">
          Sugestii, sesizări & încurajări: <a href="https://forms.gle/96WusM2qr4pbUXhW7" target="_blank" rel="noopener">formular</a> (gForm),
          <a href="mailto:<?= e(FEEDBACK_EMAIL) ?>"><?= e(FEEDBACK_EMAIL) ?></a>
          sau GitHub (<a href="https://github.com/gov2-ro/posturi.gov.ro/issues" target="_blank" rel="noopener">gov2-ro/posturi.gov.ro</a>).
        </p>

        <p style="margin-top: 8vh; margin-bottom: 18vh;">
          <img src="/static/logo-g2-256.png" alt="" class="inline-block mr-5" style="width: 7.5rem; height: auto; vertical-align: middle; opacity: 0.66;">
          <a href="https://gov2.ro" target="_blank" rel="noopener"><strong style="font-size: 1.5em;">gov2</strong>.ro</a> – <em>date publice, nițel mai la îndemână</em>
        </p>

      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>