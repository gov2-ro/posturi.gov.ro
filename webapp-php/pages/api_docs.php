<?php
declare(strict_types=1);

/**
 * /api/ — the documentation of the public JSON API (API-01). Romanian. The parameter table and the field table are
 * generated from inc/api.php (api_list_params(), API_PARAM_DOCS, API_FIELD_DOCS), so the page cannot drift from the code.
 */
require_once __DIR__ . '/../inc/api.php';

$page_title = 'API public';
$meta_description = 'API-ul public al site-ului: anunțurile de angajare din sectorul public, în format JSON. Gratuit, fără cheie.';

$toc = [
    'ce-ofera'    => 'Ce oferă API-ul',
    'adrese'      => 'Adrese',
    'parametri'   => 'Parametrii listei',
    'paginare'    => 'Paginile și ordinea',
    'descarcare'  => 'Descărcarea completă',
    'limite'      => 'Limite și coduri HTTP',
    'campuri'     => 'Câmpurile unui anunț',
    'sursa'       => 'Sursa datelor',
    'fluxuri'     => 'Fluxurile vechi',
];
$head_extra = '<script src="/static/toc.js?v=' . (@filemtime(__DIR__ . '/../static/toc.js') ?: '1') . '" defer></script>';
$origin = site_origin();
$meta = build_meta();
$built = !empty($meta['built_at']) ? build_time('d.m.Y, H:i') : '';

/** One code block: a request or an answer. Scrolls inside its box, never the page. */
$code = static fn(string $text): string =>
    '<pre class="g2-mono overflow-x-auto border border-line bg-sunken p-3 text-sm" tabindex="0"><code>' . e($text) . '</code></pre>';

require __DIR__ . '/../inc/header.php';
?>
<div class="max-w-screen-lg mx-auto px-4 sm:px-6 py-10" data-api-docs>
  <h1 class="g2-heading-page mb-6">API public</h1>

  <div class="pg-toc-layout">
    <?php require __DIR__ . '/../partials/toc.php'; ?>
    <div class="min-w-0 max-w-screen-md">
      <div class="prose-body text-sm leading-relaxed text-ink space-y-4">

        <?= toc_h2($toc, 'ce-ofera', 'g2-heading-section') ?>
        <p>API-ul dă anunțurile de pe acest site în format JSON: lista, un anunț cu toate datele lui și o descărcare a tuturor anunțurilor.
          API-ul este gratuit. Nu cere cheie și nu cere cont.</p>
        <p>Datele sunt cele ale site-ului<?= $built !== '' ? ', generate la ' . e($built) . ' (ora României)' : '' ?>. Ele se schimbă de 2–3 ori pe zi.</p>

        <?= toc_h2($toc, 'adrese', 'g2-heading-section') ?>
        <p>Toate adresele răspund la GET și HEAD. Fiecare răspuns este JSON (UTF-8), cu antetul <code>Access-Control-Allow-Origin: *</code>.</p>

        <h3 class="g2-heading-item">Indexul</h3>
        <?= $code('GET ' . $origin . '/api/v1/') ?>
        <?= $code('{"api_version":"v1","data":{"built_at":"2026-11-24T09:52:55+00:00","postings":2961},"links":{"list":"…/api/v1/posturi","posting":"…/api/v1/posturi/{id}","download":"…/api/v1/descarcare","docs":"…/api/"}}') ?>

        <h3 class="g2-heading-item">Lista de anunțuri</h3>
        <p>Lista folosește aceleași filtre ca pagina de căutare. Parametrii sunt în secțiunea <a href="#parametri">Parametrii listei</a>.</p>
        <?= $code('GET ' . $origin . '/api/v1/posturi?judet[]=cluj&status=active&page=1') ?>
        <?= $code('{"api_version":"v1","data_built_at":"2026-11-24T09:52:55+00:00","count":102,"page":1,"pages":2,"per_page":100,
 "next":"…/api/v1/posturi?judet%5B%5D=cluj&status=active&page=2","prev":null,
 "results":[{"id":14194,"title":"…","employer":"…","judet":"Cluj","published_at":"2026-09-10",
   "apply_deadline":"2026-12-01","application_status":"confirmed_open","detail_url":"…/api/v1/posturi/14194","page_url":"…/job/14194-…/", …}]}') ?>

        <h3 class="g2-heading-item">Un anunț</h3>
        <p>Un anunț are datele din listă și datele structurate: extracția, salariul, calendarul, documentele și textul.</p>
        <?= $code('GET ' . $origin . '/api/v1/posturi/14194') ?>
        <?= $code('{"api_version":"v1","data_built_at":"…","posting":{"id":14194,"title":"…", …,
  "salary":{"currency":"RON","stated":null,"estimate":{"min":5250,"max":5530, …}},
  "extraction":{"prompt_version":"v4","data":{…}},
  "calendar":[{"event":"Proba scrisă","date":"2026-12-10","time":"10:00"}],
  "attachments":[{"url":"…","label":"Anunț","ext":"PDF","size":"170 KB"}],
  "body":"…"}}') ?>
        <p>Un număr care nu este în baza de date dă codul 404. Un anunț închis de mult timp poate lipsi din bază.</p>

        <h3 class="g2-heading-item">Descărcarea</h3>
        <?= $code('GET ' . $origin . '/api/v1/descarcare') ?>
        <p>Răspunsul este un fișier <code>.json.gz</code>. Vezi secțiunea <a href="#descarcare">Descărcarea completă</a>.</p>

        <?= toc_h2($toc, 'parametri', 'g2-heading-section') ?>
        <p>API-ul acceptă numai parametrii din tabel. Un parametru necunoscut dă codul 400. Un parametru cu paranteze drepte
          (<code>[]</code>) poate avea mai multe valori: <code>judet[]=cluj&amp;judet[]=iasi</code>. Valorile aceluiași parametru se combină cu SAU.
          Parametrii diferiți se combină cu ȘI.</p>
        <p>Valorile unui parametru sunt cele din adresa paginii de căutare. Alege filtrul pe <a href="/posturi/" class="text-gov hover:underline">pagina de căutare</a>
          și copiază parametrul din adresă.</p>
        <div class="g2-table-wrap" role="region" aria-label="Parametrii listei de anunțuri" tabindex="0">
          <table class="g2-table g2-table-stacked" role="table">
            <caption class="sr-only">Parametrii lui /api/v1/posturi</caption>
            <thead role="rowgroup"><tr role="row">
              <th scope="col" role="columnheader">Parametru</th><th scope="col" role="columnheader">Valori</th><th scope="col" role="columnheader">Ce face</th>
            </tr></thead>
            <tbody role="rowgroup">
              <?php foreach (array_merge(['page'], api_list_params()) as $_p): ?>
              <tr role="row" data-param="<?= e($_p) ?>">
                <th scope="row" role="rowheader"><code><?= e($_p) ?><?= $_p !== 'page' && api_param_is_multi($_p) ? '[]' : '' ?></code></th>
                <td role="cell" data-label="Valori"><?= $_p !== 'page' && api_param_is_multi($_p) ? 'una sau mai multe' : 'una' ?></td>
                <td role="cell" data-label="Ce face"><?= e(api_param_description($_p)) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?= toc_h2($toc, 'paginare', 'g2-heading-section') ?>
        <p>Fiecare pagină are 100 de rezultate. Nu există un parametru pentru dimensiunea paginii.
          Câmpurile <code>next</code> și <code>prev</code> dau adresa paginii următoare și a celei dinainte. Ele sunt null la capete.
          O pagină după ultima dă codul 404. O listă goală are o singură pagină, fără rezultate.</p>
        <p>Ordinea este <code>published_at</code> descrescător, apoi <code>id</code> descrescător. Ordinea nu se schimbă între două încărcări de date.</p>
        <div class="g2-inset"><p>Datele se schimbă de 2–3 ori pe zi. Dacă citiți paginile una după alta în timp ce datele se schimbă,
          puteți sări peste un anunț sau îl puteți citi de două ori. Pentru toate anunțurile folosiți
          <a href="#descarcare">descărcarea completă</a>.</p></div>

        <?= toc_h2($toc, 'descarcare', 'g2-heading-section') ?>
        <p>Adresa <a href="/api/v1/descarcare" class="text-gov hover:underline"><code>/api/v1/descarcare</code></a> dă toate anunțurile bazei de date într-un singur fișier:
          <code>posturi-gov2-AAAA-LL-ZZ.json.gz</code>. Fișierul conține
          <code>{"api_version", "data_built_at", "count", "postings": […]}</code>. Fiecare element din <code>postings</code> are aceleași câmpuri ca un anunț din
          <code>/api/v1/posturi/{id}</code>.</p>
        <p>Fișierul se schimbă la fiecare încărcare de date, adică de 2–3 ori pe zi. Prima cerere după o încărcare construiește fișierul. Ea durează câteva secunde.
          Celelalte cereri primesc fișierul gata.</p>
        <p><strong>Cine are nevoie de toate anunțurile trebuie să folosească descărcarea.</strong> Nu parcurgeți paginile listei pentru asta.</p>
        <?= $code("curl -O -J " . $origin . "/api/v1/descarcare\ngzip -dc posturi-gov2-*.json.gz | head -c 300") ?>
        <p>Serverul trimite fișierul <code>.gz</code> așa cum este. El nu are antetul <code>Content-Encoding</code>, deci clientul nu îl dezarhivează singur.</p>

        <?= toc_h2($toc, 'limite', 'g2-heading-section') ?>
        <ul class="list-disc pl-5 space-y-1">
          <li><strong>Memoria cache.</strong> Fiecare răspuns reușit poate rămâne în memoria cache până la 1 oră. După o încărcare de date, un răspuns poate fi vechi cel mult o oră.
            Câmpul <code>data_built_at</code> spune când a fost generată baza de date.</li>
          <li><strong>Limita de cereri.</strong> Rețeaua Cloudflare din fața site-ului limitează cererile la adresele <code>/api/</code>:
            cel mult 60 de cereri la 10 secunde de la o adresă IP. Peste limită, cererile sunt blocate 10 secunde. Și răspunsurile din memoria cache se numără.</li>
          <li><strong>Fără cheie.</strong> Limita este aceeași pentru toți.</li>
        </ul>
        <div class="g2-table-wrap" role="region" aria-label="Codurile HTTP" tabindex="0">
          <table class="g2-table">
            <caption class="sr-only">Codurile HTTP ale API-ului</caption>
            <thead><tr><th scope="col">Cod</th><th scope="col">Sens</th></tr></thead>
            <tbody>
              <tr><th scope="row">200</th><td>Răspuns reușit.</td></tr>
              <tr><th scope="row">400</th><td>Parametru necunoscut, valoare prea lungă sau structură greșită; <code>page</code> care nu este un număr întreg de la 1 în sus.</td></tr>
              <tr><th scope="row">404</th><td>Adresă necunoscută, anunț care nu este în baza de date sau pagină după ultima.</td></tr>
              <tr><th scope="row">405</th><td>Alta decât GET și HEAD. Antetul <code>Allow</code> spune metodele permise.</td></tr>
              <tr><th scope="row">429</th><td>Prea multe cereri: regula Cloudflare a blocat cererea. Așteptați 10 secunde.</td></tr>
              <tr><th scope="row">503</th><td>Descărcarea nu este disponibilă acum.</td></tr>
            </tbody>
          </table>
        </div>
        <p>O eroare are corp JSON <code>{"error": "…"}</code> și nu rămâne în memoria cache.</p>

        <?= toc_h2($toc, 'campuri', 'g2-heading-section') ?>
        <p>Un anunț (<code>posting</code>) are câmpurile din tabel. Câmpurile din listă sunt cele de la <code>id</code> până la <code>page_url</code>. Un câmp fără valoare este <code>null</code>.
          Un obiect grupează câmpurile apropiate. Numele lor sunt în coloana „Ce este”.</p>
        <div class="g2-table-wrap" role="region" aria-label="Câmpurile unui anunț" tabindex="0">
          <table class="g2-table g2-table-stacked" role="table">
            <caption class="sr-only">Câmpurile unui anunț</caption>
            <thead role="rowgroup"><tr role="row">
              <th scope="col" role="columnheader">Câmp</th><th scope="col" role="columnheader">Tip</th><th scope="col" role="columnheader">Ce este</th>
            </tr></thead>
            <tbody role="rowgroup">
              <?php foreach (API_FIELD_DOCS as [$_f, $_t, $_d]): ?>
              <tr role="row" data-field="<?= e($_f) ?>">
                <th scope="row" role="rowheader"><code><?= e($_f) ?></code></th>
                <td role="cell" data-label="Tip"><?= e($_t) ?></td>
                <td role="cell" data-label="Ce este"><?= e($_d) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p>Salariile din <code>salary.estimate</code> sunt estimări automate. Ele nu sunt cifre oficiale. Metoda este în <a href="/despre/#salarii" class="text-gov hover:underline">Despre</a>.
          Restructurarea automată a anunțurilor poate greși. Anunțul oficial, la adresa <code>url</code>, are prioritate.</p>

        <?= toc_h2($toc, 'sursa', 'g2-heading-section') ?>
        <p>Datele provin de pe <a href="https://posturi.gov.ro" class="text-gov hover:underline" target="_blank" rel="noopener">posturi.gov.ro</a>.
          Site-ul le preia periodic și le procesează automat. Proiectul este independent. Nu este un proiect oficial al Guvernului României.</p>
        <p>Când refolosiți datele, menționați ambele surse:
          <a href="https://posturi.gov.ro" class="text-gov hover:underline" target="_blank" rel="noopener">posturi.gov.ro</a> pentru anunțurile originale și
          <a href="https://posturi.gov2.ro" class="text-gov hover:underline">posturi.gov2.ro</a> pentru datele restructurate.</p>
        <p>Pentru întrebări despre API, scrieți la <a href="mailto:cancelarie@gov2.ro" class="text-gov hover:underline">cancelarie@gov2.ro</a>.</p>

        <?= toc_h2($toc, 'fluxuri', 'g2-heading-section') ?>
        <p>Fluxurile <a href="/posturi.json" class="text-gov hover:underline"><code>/posturi.json</code></a>, <a href="/posturi.atom" class="text-gov hover:underline">Atom</a>,
          <a href="/posturi.rss" class="text-gov hover:underline">RSS</a> și <a href="/posturi.ics" class="text-gov hover:underline">iCal</a> rămân neschimbate.
          Ele sunt făcute pentru cititoare de fluxuri și pentru calendare. Ele ignoră parametrii necunoscuți, dau cel mult 200 de rezultate (JSON) și nu au pagini.
          API-ul este pentru programe: are parametri stricți, pagini de 100 de rezultate, un anunț cu toate datele lui și descărcarea completă.</p>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
