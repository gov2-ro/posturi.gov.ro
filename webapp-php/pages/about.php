<?php
$page_title = 'Despre';
require __DIR__ . '/../inc/header.php';
?>
<div class="max-w-screen-md mx-auto px-4 sm:px-6 py-10">
  <h1 class="font-display text-3xl italic font-semibold text-ink mb-6">Despre</h1>

  <div class="prose-body text-sm leading-relaxed text-ink space-y-4">
    <p>
      <strong>posturi.gov.ro</strong> este un explorator independent al anunțurilor de angajare din sectorul public din România,
      fără nicio afiliere oficială cu posturi.gov.ro sau cu autoritățile publice.
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
      <li>Export în formate standard: <a href="/posturi.json" class="text-gov hover:underline">JSON</a>, <a href="/posturi.atom" class="text-gov hover:underline">Atom</a>, <a href="/posturi.ics" class="text-gov hover:underline">iCal</a></li>
    </ul>
    <h2>Date și actualizare</h2>
    <p>
      Baza de date se actualizează zilnic. Toate datele provin exclusiv din surse publice.
    </p>
    <h2>Contact</h2>
    <p>
      Proiect open-source. Întrebări și feedback: <a href="mailto:pax@mioritics.ro" class="text-gov hover:underline">pax@mioritics.ro</a>
    </p>
  </div>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
