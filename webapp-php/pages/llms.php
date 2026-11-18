<?php
declare(strict_types=1);

/**
 * /llms.txt — a plain-text Markdown file for language models (llmstxt.org): one title, one summary, then sections with link
 * lists. Built from the code (the themes, the filter parameters, the database), so that it cannot go stale. Romanian.
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$origin = site_origin();
$repo = 'https://github.com/gov2-ro/posturi.gov.ro';
$fmt = static fn(int $n): string => number_format($n, 0, ',', '.');

/** One line of a link list: "- [name](absolute URL): note". */
$link = static fn(string $name, string $href, string $note) => '- [' . $name . '](' . (str_starts_with($href, 'http') ? $href : $origin . $href) . '): ' . $note . "\n";

// The filters that a search URL can carry: parameter => [what it selects, example values]. Each name is a key that
// build_filters() reads (checked in tests/llms_test.php). Examples are written with search_key(): the canonical URL.
$filters = [
    ['q',             'text liber (căutare în titlu și în anunț)',        ['q' => 'asistent medical']],
    ['judet',         'județul (slug-ul județului)',                      ['judet' => ['cluj']]],
    ['family',        'domeniul profesional',                             ['family' => ['sănătate']]],
    ['occupation',    'ocupația',                                         ['occupation' => ['Asistent medical']]],
    ['medspec',       'specialitatea medicală',                           ['medspec' => ['Cardiologie']]],
    ['studies_level', 'nivelul de studii cerut (' . implode(', ', array_keys(STUDIES_LABELS)) . ')', ['studies_level' => ['licenta']]],
    ['sector',        'domeniul angajatorului',                           ['sector' => ['sanatate']]],
    ['exp_level',     'experiența cerută',                                ['exp_level' => ['fara']]],
    ['salary_bucket', 'intervalul de salariu net estimat',                ['salary_bucket' => 'net-peste-5000']],
    ['status',        'starea anunțului (active, soon = se închid în 7 zile, closed, all)', ['status' => 'soon']],
];

$active = home_count_for([]);
$built = build_time('d.m.Y, H:i');
$meta = build_meta();

echo "# posturi.gov2.ro\n\n";
echo "> Anunțurile de angajare din sectorul public românesc, preluate de pe posturi.gov.ro, structurate și ușor de căutat. "
   . "Este un proiect independent. Nu este un site oficial al Guvernului României. Anunțul oficial de pe posturi.gov.ro are prioritate.\n\n";

echo "## Pagini\n\n";
echo $link('Prima pagină', '/', 'căutare, domenii profesionale, harta județelor');
echo $link('Toate posturile', '/posturi/', 'lista tuturor anunțurilor active, cu filtre');
foreach (themes() as $slug => $t) {
    echo $link($t['label'], '/' . $slug . '/', 'pagină tematică: ' . ($t['title'] ?? $t['label']));
}
echo $link('Statistici', '/statistici/', 'cifre despre anunțuri și despre date');
echo $link('Angajatori', '/angajatori/', 'angajatorii și anunțurile lor');
echo $link('Despre și metodologie', '/despre/', 'de unde vin datele și cum sunt prelucrate');
echo $link('Pot aplica?', '/pot-aplica/', 'verifică dacă îndeplinești condițiile unui post');
echo $link('Estimator de salariu', '/estimator-salariu/', 'salariul de bază brut și net al unei funcții, după Legea 153/2017 și după proiectul de lege');

echo "\n## Date și fluxuri\n\n";
echo $link('Flux RSS', '/posturi.rss', 'anunțurile noi, în format RSS');
echo $link('Flux Atom', '/posturi.atom', 'anunțurile noi, în format Atom');
echo $link('Calendar', '/posturi.ics', 'termenele concursurilor, în format iCalendar');
echo $link('Harta site-ului', '/sitemap.xml', 'toate paginile');

echo "\n## Căutare prin URL\n\n";
echo "O căutare este un URL al listei: `" . $origin . "/?parametru=valoare`. Un parametru cu paranteze drepte (`[]`) poate avea mai multe valori. Filtrele diferite se combină cu ȘI.\n\n";
foreach ($filters as [$name, $what, $example]) {
    $url = $origin . home_list_url($example);
    echo '- `' . $name . '`: ' . $what . '. Exemplu: ' . $url . "\n";
}

echo "\n## Despre date\n\n";
echo '- Baza de date a fost generată la ' . ($built !== '' ? $built : 'o dată necunoscută') . " (ora României).\n";
echo '- Anunțuri active: ' . $fmt($active) . ".\n";
echo "- Sursa: posturi.gov.ro. Site-ul preia anunțurile și le restructurează automat; pot apărea erori de conversie.\n";
echo "- Salariile nete sunt estimări, acolo unde pagina spune așa. Nu sunt cifre oficiale.\n";

echo "\n## Cod sursă\n\n";
echo $link('Depozitul GitHub', $repo, 'codul proiectului');
