<?php
declare(strict_types=1);

/**
 * Themed landing pages (docs/briefs/2026-11-17-themed-landing-sanatate.md): `/{slug}/` shows one part
 * of the postings with the sections that fit it. One template (pages/theme.php) renders each theme
 * from the array below; the template knows no theme by name.
 *
 * A theme is a base filter made of query parameters that exist. Each link of a themed page is the base
 * filter plus one more filter, and each count is the result count of its link (home_item_counts()).
 *
 * Keys of a theme:
 *   label        text of the links to the page (landing page, footer)
 *   icon         optional: the id of a sprite symbol, shown before the title (no `icon` = no icon, no gap)
 *   base         the base filter: parameter => list of values
 *   title        the page title; h1 the heading; description the meta description
 *   all_label    text of the link to the list of the base filter
 *   placeholder  placeholder of the search box
 *   sections     the sections in page order; each has `type`, `id` (the heading id is "h-<id>") and `title`:
 *     values     one item for each value of a filter, largest first (count 0 is not offered). Keys:
 *                `param` the filter parameter; `source` where the values come from: `json` (the column holds a
 *                JSON list of values), `column` (one value in the column) or `labels` (`labels`: value => text,
 *                in page order); `column` and `limit` (source json and column: the column, and how many items);
 *                `more` (source json: the text of the link to the list, when more values exist than `limit`);
 *                optional `order` ('count': a `labels` section sorts its items by count, largest first, and `limit` then keeps the
 *                first ones; with no `order` it keeps the order of `labels`; the text of an item is its `labels` text);
 *                When `param` is a key of the base filter (`cor` in /meserii/), the item replaces the base values with its own value
 *                (theme_item_params()), and so does a redirect of `/{slug}/?cor[]=7`.
 *                optional `columns` (3: three columns from lg; default: as many as fit) and `icons`
 *                value => symbol id (a symbol of the file `icon_file` of the theme; with no `icon_file`, no icon), `icon_default`, `icon_size` (classes, default `h-6 w-6`); a value with none of them has no icon.
 *                `sprite_icons` (value => symbol id of the page sprite, for a theme with no `icon_file`) gives the same, with the symbols of the landing page
 *     counties   the county map, in the first screen beside the title and the search (not in the section flow; `id` is
 *                not shown). The map is the control that selects a county; the search form keeps its own select
 *     stats      the statistics panel, in the first screen below the link to all postings (not in the section flow):
 *                with the theme key `history` the weekly chart with its occupation buttons
 *     recent     the two short lists, "Noi azi" and "Se închid în 3 zile"
 *     follow     the notification cards (calendar and RSS of the base filter)
 *   icon_file    optional: the file in static/ with the symbols of the item icons (no `icon_file` = no icon, no reference)
 *   history      optional: the part of the archive that belongs to the theme: `family` (a profession family, or a list of them: any of them),
 *                optional `cor` (a list of COR prefixes: only the postings whose `occ_group` starts with one of them; an archive with no
 *                `occ_group` column then adds nothing) and `groups`, the buttons of the chart after "Toate": each has `label` and any of
 *                `values` (exact occupations), `prefixes`, `except` (exact occupations that a prefix does not take) and `prefixes_cor`
 *                (the `occ_group` of the posting starts with one of them); a posting is in the first group that holds it.
 *                No `history` = no chart; no `groups` = a chart with no buttons
 *   search_selects optional: selects in the search form, in place of the text field: a list of `param`, `label`, `all` (the
 *                text of the empty option), `source` (`json` or `column`), `column` and an optional `labels` (value => text: the
 *                option shows the text and sends the value; a value with no text shows itself); each lists the values that have a
 *                posting inside the theme. The county select stays. No `search_selects` = the text field
 *   domains      optional: a list of keys of HOME_DOMAINS. On the landing page the item of such a domain links to `/{slug}/`
 *                (the count does not change). A key is in one theme at most (a test checks it)
 *   other        one more line, before the section `recent` (at the end when there is none): `label`, `params` (a filter that is
 *                not inside the base filter) and an optional `note` (one muted sentence after the line)
 *
 * The environment variable POSTURI_THEMES_EXTRA (a PHP file that returns more themes) adds themes. The
 * tests use it to show that a second theme needs no change of the template. It never replaces a theme.
 */
function themes(): array {
    static $themes = null;
    if ($themes !== null) return $themes;
    $themes = [
        'sanatate' => [
            'label'       => 'Sănătate',
            'icon'        => 'i-sector-sanatate',   // sprite symbol (inc/header.php), shown before the title
            'domains'     => ['sanatate'],          // the item "Sănătate" of the landing page links to /sanatate/
            // The medical professions wherever they work: a school nurse is here, the accountant of a hospital is not.
            'base'        => ['family' => ['sănătate']],
            // The symbol file of the icons of the `values` sections (static/<file>, ADR 0007).
            'icon_file'   => 'health-icons.svg',
            // The part of the archive that belongs to the theme (archived_postings has few columns, so it takes no base filter).
            'history'     => ['family' => 'sănătate',
                              // The buttons of the chart, after "Toate": fixed groups of occupations. An occupation is in the first group
                              // that holds it, so "Medic rezident" is in "Rezident" and not in "Medic".
                              'groups' => [
                                  ['label' => 'Medic', 'prefixes' => ['Medic'], 'except' => ['Medic rezident']],
                                  ['label' => 'Rezident', 'values' => ['Medic rezident']],
                                  ['label' => 'Asistent', 'prefixes' => ['Asistent medical']],
                                  ['label' => 'Îngrijitor', 'values' => ['Îngrijitor', 'Infirmier', 'Brancardier']],
                              ]],
            'title'       => 'Posturi în sănătate',
            'h1'          => 'Posturi în sănătate',
            'description' => 'Posturi în sănătate din sectorul public: medici, asistenți medicali, farmaciști și alte profesii medicale, '
                           . 'pe specialități, ocupații, județe și nivel de studii.',
            'all_label'   => 'Toate anunțurile din sănătate',
            'placeholder' => 'Caută: asistent medical, medic, farmacist…',
            // Selects instead of the text field (a theme with no `search_selects` keeps the field).
            'search_selects' => [
                ['param' => 'medspec', 'label' => 'Specialitate', 'all' => 'Toate specialitățile', 'source' => 'json', 'column' => 'med_specialty'],
                ['param' => 'occupation', 'label' => 'Ocupație', 'all' => 'Toate ocupațiile', 'source' => 'column', 'column' => 'occ_canonical'],
            ],
            'sections'    => [
                ['type' => 'stats', 'id' => 'statistici'],
                ['type' => 'values', 'id' => 'specialitati', 'title' => 'Specialități medicale', 'param' => 'medspec',
                 'source' => 'json', 'column' => 'med_specialty', 'limit' => 24, 'more' => 'Toate specialitățile în listă',
                 'columns' => 3, 'icon_size' => 'h-10 w-10',
                 // Own icon where Health Icons has one, else the nearest icon, else the default (ADR 0007).
                 'icon_default' => 'i-health-health',
                 'icons' => [
                        'ATI (anestezie și terapie intensivă)' => 'i-health-critical-care',
                        'Anatomie patologică' => 'i-health-microscope-with_specimen',
                        'Boli infecțioase' => 'i-health-virus',
                        'Cardiologie' => 'i-health-cardiology',
                        'Chirurgie (alte specialități)' => 'i-health-general-surgery',
                        'Chirurgie generală' => 'i-health-general-surgery',
                        'Dermatovenerologie' => 'i-health-dermatology',
                        'Diabet, nutriție și boli metabolice' => 'i-health-diabetes',
                        'Endocrinologie' => 'i-health-endocrinology',
                        'Epidemiologie' => 'i-health-outbreak',
                        'Gastroenterologie' => 'i-health-gastroenterology',
                        'Geriatrie' => 'i-health-geriatrics',
                        'Hematologie' => 'i-health-hematology',
                        'Igienă / sănătate publică' => 'i-health-wash-hands',
                        'Medicina muncii' => 'i-health-factory-worker',
                        'Medicină de familie' => 'i-health-outpatient-department',
                        'Medicină de laborator' => 'i-health-test-tubes',
                        'Medicină de urgență' => 'i-health-accident-and_emergency',
                        'Medicină dentară' => 'i-health-odontology',
                        'Medicină internă' => 'i-health-stethoscope',
                        'Medicină legală' => 'i-health-justice',
                        'Microbiologie' => 'i-health-bacteria',
                        'Nefrologie' => 'i-health-nephrology',
                        'Neonatologie' => 'i-health-baby-0203m',
                        'Neurologie' => 'i-health-neurology',
                        'ORL' => 'i-health-ears-nose_and_throat',
                        'Obstetrică-ginecologie' => 'i-health-gynecology',
                        'Oftalmologie' => 'i-health-opthalmology',
                        'Oncologie' => 'i-health-oncology',
                        'Ortopedie și traumatologie' => 'i-health-orthopaedics',
                        'Pediatrie' => 'i-health-pediatrics',
                        'Pneumologie' => 'i-health-respirology',
                        'Psihiatrie' => 'i-health-mental-health',
                        'Radiologie și imagistică' => 'i-health-radiology',
                        'Radioterapie / Medicină nucleară' => 'i-health-oncology',
                        'Recuperare / reabilitare medicală' => 'i-health-physical-therapy',
                        'Reumatologie' => 'i-health-rheumatology',
                        'Urologie' => 'i-health-urology',
                 ]],
                ['type' => 'values', 'id' => 'ocupatii', 'title' => 'Ocupații', 'param' => 'occupation',
                 'source' => 'column', 'column' => 'occ_canonical', 'limit' => 12],
                ['type' => 'counties', 'id' => 'judete'],
                ['type' => 'values', 'id' => 'studii', 'title' => 'Studii', 'param' => 'studies_level',
                 'source' => 'labels', 'labels' => STUDIES_LABELS],
                ['type' => 'recent', 'id' => 'noi'],
                ['type' => 'follow', 'id' => 'notificari'],
            ],
            'other'       => ['label'  => 'Posturi nemedicale la angajatori din sănătate',
                              'note'   => 'Economiști, muncitori, șoferi, registratori și alte posturi din spitale și unități sanitare.',
                              'params' => ['sector' => ['sanatate'], 'family_not' => ['sănătate']]],
        ],

        // Professional domain: the postings of the family `administrație`, wherever they work (a town hall, a school, a hospital).
        'administratie' => [
            'label'       => 'Administrație',
            'icon'        => 'i-domain-administratie',
            'domains'     => ['administratie'],
            'base'        => ['family' => ['administrație']],
            // An occupation is in the first group that holds it, so "Secretar șef" is in "Conducere" and not in "Secretar".
            'history'     => ['family' => 'administrație',
                              'groups' => [
                                  ['label' => 'Conducere', 'prefixes' => ['Șef', 'Director', 'Manager', 'Secretar șef']],
                                  ['label' => 'Referent', 'prefixes' => ['Referent']],
                                  ['label' => 'Inspector', 'prefixes' => ['Inspector']],
                                  ['label' => 'Secretar', 'prefixes' => ['Secretar']],
                              ]],
            'title'       => 'Posturi în administrație',
            'h1'          => 'Posturi în administrație',
            'description' => 'Posturi în administrație din sectorul public: referenți, inspectori, secretari și funcții de conducere, '
                           . 'la primării, spitale, școli și alte instituții, pe ocupații, județe și nivel de studii.',
            'all_label'   => 'Toate anunțurile din administrație',
            'placeholder' => 'Caută: referent, secretar, inspector…',
            'search_selects' => [
                ['param' => 'occupation', 'label' => 'Ocupație', 'all' => 'Toate ocupațiile', 'source' => 'column', 'column' => 'occ_canonical'],
                ['param' => 'inst', 'label' => 'Instituție', 'all' => 'Toate instituțiile', 'source' => 'column', 'column' => 'employer_kind',
                 'labels' => EMPLOYER_KIND_LABELS],
            ],
            'sections'    => [
                ['type' => 'stats', 'id' => 'statistici'],
                // The keys are the stored values of `job_level` (the source sends these phrases).
                ['type' => 'values', 'id' => 'tip-functie', 'title' => 'Tip funcție', 'param' => 'level', 'source' => 'labels', 'order' => 'count',
                 'labels' => ['Funcții de conducere' => 'Funcții de conducere', 'Funcții de execuție' => 'Funcții de execuție']],
                ['type' => 'values', 'id' => 'ocupatii', 'title' => 'Ocupații', 'param' => 'occupation',
                 'source' => 'column', 'column' => 'occ_canonical', 'limit' => 12],
                ['type' => 'values', 'id' => 'angajatori', 'title' => 'Angajatori', 'param' => 'inst', 'source' => 'labels', 'order' => 'count',
                 'labels' => EMPLOYER_KIND_LABELS],
                ['type' => 'values', 'id' => 'domenii', 'title' => 'Domenii de activitate', 'param' => 'domain', 'source' => 'labels', 'order' => 'count',
                 'limit' => 8, 'labels' => POLICY_DOMAIN_LABELS],
                ['type' => 'counties', 'id' => 'judete'],
                ['type' => 'values', 'id' => 'studii', 'title' => 'Studii', 'param' => 'studies_level',
                 'source' => 'labels', 'labels' => STUDIES_LABELS],
                ['type' => 'recent', 'id' => 'noi'],
                ['type' => 'follow', 'id' => 'notificari'],
            ],
        ],

        // Professional domain: finance and accounting, wherever the postings are (schools, hospitals, culture, town halls).
        'financiar' => [
            'label'       => 'Financiar',
            'icon'        => 'i-domain-financiar',
            'domains'     => ['financiar'],
            'base'        => ['family' => ['financiar']],
            // An occupation is in the first group that holds it, so "Contabil-șef" is in "Conducere" and not in "Contabil".
            'history'     => ['family' => 'financiar',
                              'groups' => [
                                  ['label' => 'Conducere', 'values' => ['Contabil-șef', 'Contabil șef'], 'prefixes' => ['Director', 'Șef', 'Manager']],
                                  ['label' => 'Administrator financiar', 'prefixes' => ['Administrator financiar']],
                                  ['label' => 'Economist', 'prefixes' => ['Economist']],
                                  ['label' => 'Contabil', 'prefixes' => ['Contabil']],
                              ]],
            'title'       => 'Posturi financiar-contabile',
            'h1'          => 'Posturi financiar-contabile',
            'description' => 'Posturi financiar-contabile din sectorul public: administratori financiari, economiști, contabili și funcții de conducere, '
                           . 'la școli, spitale, instituții de cultură și primării, pe județe și nivel de studii.',
            'all_label'   => 'Toate anunțurile financiar-contabile',
            'placeholder' => 'Caută: contabil, economist, administrator financiar…',
            'search_selects' => [
                ['param' => 'occupation', 'label' => 'Ocupație', 'all' => 'Toate ocupațiile', 'source' => 'column', 'column' => 'occ_canonical'],
                ['param' => 'inst', 'label' => 'Instituție', 'all' => 'Toate instituțiile', 'source' => 'column', 'column' => 'employer_kind',
                 'labels' => EMPLOYER_KIND_LABELS],
            ],
            'sections'    => [
                ['type' => 'stats', 'id' => 'statistici'],
                ['type' => 'values', 'id' => 'tip-functie', 'title' => 'Tip funcție', 'param' => 'level', 'source' => 'labels', 'order' => 'count',
                 'labels' => ['Funcții de conducere' => 'Funcții de conducere', 'Funcții de execuție' => 'Funcții de execuție']],
                ['type' => 'values', 'id' => 'ocupatii', 'title' => 'Ocupații', 'param' => 'occupation',
                 'source' => 'column', 'column' => 'occ_canonical', 'limit' => 12],
                ['type' => 'values', 'id' => 'angajatori', 'title' => 'Angajatori', 'param' => 'inst', 'source' => 'labels', 'order' => 'count',
                 'labels' => EMPLOYER_KIND_LABELS],
                // Software products and public IT systems with 3 or more postings in the theme; no generic office programs
                // (reviewed by Opus, 2026-11-18). The filter `skill` compares with LIKE, which ignores the case of
                // ASCII letters: "FOREXEBUG" also matches the spelling "Forexebug".
                ['type' => 'values', 'id' => 'programe', 'title' => 'Programe informatice cerute', 'param' => 'skill', 'source' => 'labels', 'order' => 'count',
                 'labels' => ['FOREXEBUG' => 'FOREXEBUG', 'EDUSAL' => 'EDUSAL', 'SIIIR' => 'SIIIR']],
                ['type' => 'counties', 'id' => 'judete'],
                ['type' => 'values', 'id' => 'studii', 'title' => 'Studii', 'param' => 'studies_level',
                 'source' => 'labels', 'labels' => STUDIES_LABELS],
                ['type' => 'recent', 'id' => 'noi'],
                ['type' => 'follow', 'id' => 'notificari'],
            ],
        ],

        // Professional domains `social` and `educație` together: social workers, school counsellors, mediators, psychologists,
        // pedagogues and home carers. The "educație" postings are not teacher posts.
        'social' => [
            'label'       => 'Asistență socială și consiliere',
            'icon'        => 'i-sector-asistenta_sociala',
            'domains'     => ['social', 'educatie'],
            'base'        => ['family' => ['social', 'educație']],
            // The archive part takes a list of families (`IN`). An occupation is in the first group that holds it.
            'history'     => ['family' => ['social', 'educație'],
                              'groups' => [
                                  ['label' => 'Asistent social', 'prefixes' => ['Asistent social']],
                                  ['label' => 'Consilier școlar', 'prefixes' => ['Consilier școlar']],
                                  ['label' => 'Mediator', 'prefixes' => ['Mediator']],
                                  ['label' => 'Psiholog', 'prefixes' => ['Psiholog']],
                              ]],
            'title'       => 'Posturi în asistență socială și consiliere',
            'h1'          => 'Posturi în asistență socială și consiliere',
            'description' => 'Posturi în asistență socială și consiliere din sectorul public: asistenți sociali, consilieri școlari, mediatori școlari, '
                           . 'psihologi și îngrijitori la domiciliu, la primării, direcții de asistență socială și școli, pe județe și nivel de studii.',
            'all_label'   => 'Toate anunțurile din asistență socială și consiliere',
            'placeholder' => 'Caută: asistent social, consilier școlar, psiholog…',
            'search_selects' => [
                ['param' => 'occupation', 'label' => 'Ocupație', 'all' => 'Toate ocupațiile', 'source' => 'column', 'column' => 'occ_canonical'],
                ['param' => 'inst', 'label' => 'Instituție', 'all' => 'Toate instituțiile', 'source' => 'column', 'column' => 'employer_kind',
                 'labels' => EMPLOYER_KIND_LABELS],
            ],
            'sections'    => [
                ['type' => 'stats', 'id' => 'statistici'],
                ['type' => 'values', 'id' => 'ocupatii', 'title' => 'Ocupații', 'param' => 'occupation',
                 'source' => 'column', 'column' => 'occ_canonical', 'limit' => 12],
                ['type' => 'values', 'id' => 'angajatori', 'title' => 'Angajatori', 'param' => 'inst', 'source' => 'labels', 'order' => 'count',
                 'labels' => EMPLOYER_KIND_LABELS],
                ['type' => 'values', 'id' => 'domenii', 'title' => 'Domenii de activitate', 'param' => 'domain', 'source' => 'labels', 'order' => 'count',
                 'limit' => 6, 'labels' => POLICY_DOMAIN_LABELS],
                ['type' => 'counties', 'id' => 'judete'],
                ['type' => 'values', 'id' => 'studii', 'title' => 'Studii', 'param' => 'studies_level',
                 'source' => 'labels', 'labels' => STUDIES_LABELS],
                ['type' => 'recent', 'id' => 'noi'],
                ['type' => 'follow', 'id' => 'notificari'],
            ],
            'other'       => ['label'  => 'Alte posturi la direcțiile de asistență socială',
                              'note'   => 'Infirmieri, îngrijitori, bucătari și alte posturi din centre și cămine.',
                              'params' => ['inst' => ['asistenta-sociala'], 'family_not' => ['social', 'educație']]],
        ],

        // The four groups of manual and service work: the family `tehnic` with the COR groups 5, 7, 8 and 9 (brief 3). A section with
        // `param` `cor` narrows inside the base filter: its item replaces the four values with one (theme_item_params()).
        'meserii' => [
            'label'       => 'Meserii și muncitori',
            'icon'        => 'i-domain-meseriasi',
            'domains'     => ['meseriasi', 'paza', 'soferi', 'necalificati'],
            'base'        => ['family' => ['tehnic'], 'cor' => ['5', '7', '8', '9']],
            // The archive part takes the postings of the family with these COR prefixes (`occ_group`); the groups are COR groups too.
            'history'     => ['family' => 'tehnic', 'cor' => ['5', '7', '8', '9'],
                              'groups' => [
                                  ['label' => 'Meseriași', 'prefixes_cor' => ['7']],
                                  ['label' => 'Îngrijire și servicii', 'prefixes_cor' => ['5']],
                                  ['label' => 'Șoferi', 'prefixes_cor' => ['8']],
                                  ['label' => 'Necalificați', 'prefixes_cor' => ['9']],
                              ]],
            'title'       => 'Meserii și muncitori',
            'h1'          => 'Meserii și muncitori',
            'description' => 'Posturi de meseriași și muncitori din sectorul public: muncitori calificați, îngrijitori, bucătari, șoferi, paznici '
                           . 'și muncitori necalificați, la școli, spitale și primării, pe județe și nivel de studii.',
            'all_label'   => 'Toate anunțurile de meserii și muncitori',
            'placeholder' => 'Caută: muncitor, șofer, îngrijitor, paznic…',
            'search_selects' => [
                ['param' => 'occupation', 'label' => 'Ocupație', 'all' => 'Toate ocupațiile', 'source' => 'column', 'column' => 'occ_canonical'],
                ['param' => 'inst', 'label' => 'Instituție', 'all' => 'Toate instituțiile', 'source' => 'column', 'column' => 'employer_kind',
                 'labels' => EMPLOYER_KIND_LABELS],
            ],
            'sections'    => [
                ['type' => 'stats', 'id' => 'statistici'],
                // The labels and the icons of the four landing items; sprite symbols of the page (no `icon_file`).
                ['type' => 'values', 'id' => 'categorii', 'title' => 'Categorii', 'param' => 'cor', 'source' => 'labels', 'order' => 'count',
                 'labels' => ['7' => 'Meseriași și muncitori calificați', '5' => 'Pază, îngrijire și servicii', '8' => 'Șoferi și operatori',
                              '9' => 'Muncitori necalificați'],
                 'sprite_icons' => ['7' => 'i-domain-meseriasi', '5' => 'i-domain-paza', '8' => 'i-domain-soferi', '9' => 'i-domain-necalificati']],
                ['type' => 'values', 'id' => 'meserii', 'title' => 'Meserii', 'param' => 'occupation',
                 'source' => 'column', 'column' => 'occ_canonical', 'limit' => 16],
                ['type' => 'values', 'id' => 'angajatori', 'title' => 'Angajatori', 'param' => 'inst', 'source' => 'labels', 'order' => 'count',
                 'labels' => EMPLOYER_KIND_LABELS],
                ['type' => 'counties', 'id' => 'judete'],
                ['type' => 'values', 'id' => 'studii', 'title' => 'Studii', 'param' => 'studies_level',
                 'source' => 'labels', 'labels' => STUDIES_LABELS],
                ['type' => 'recent', 'id' => 'noi'],
                ['type' => 'follow', 'id' => 'notificari'],
            ],
        ],
    ];
    $extra = getenv('POSTURI_THEMES_EXTRA');
    if (is_string($extra) && $extra !== '' && is_file($extra)) {
        $more = require $extra;
        if (is_array($more)) $themes += $more;
    }
    return $themes;
}

/** The theme of a request path (`/sanatate`, the trailing slash is already trimmed), or null. */
function theme_for_uri(string $uri): ?array {
    if (!preg_match('#^/([a-z0-9-]+)$#', $uri, $m)) return null;
    $t = themes()[$m[1]] ?? null;
    return $t === null ? null : $t + ['slug' => $m[1]];
}

/**
 * The URL that `/{slug}/?…` redirects to: the list `/?…` with the base filter and the given
 * parameters together (a given value of a key of the base filter replaces the base values) (ADR 0004: a filtered list has one URL). `sort`, `page` and `title` stay;
 * blank values go.
 */
function theme_redirect_url(array $theme, array $get): string {
    $keep = array_intersect_key($get, ['sort' => 1, 'page' => 1, 'title' => 1]);
    $given = array_diff_key($get, $keep);
    // A key of the base filter: the given values replace the base values (as in a `values` section), `/meserii/?cor[]=7` is
    // `family=tehnic` and `cor=7`. Blank values are ignored, so a blank value keeps the base values. Other keys merge.
    $base = $theme['base'];
    foreach ($given as $k => $v) {
        if (!array_key_exists($k, $base)) continue;
        $vals = array_values(array_filter((array)$v, fn($x) => is_string($x) && trim($x) !== ''));
        if ($vals) $base[$k] = $vals;
        unset($given[$k]);
    }
    $merged = home_merge_params($base, $given);
    $parts = [search_key($merged)];
    foreach ($keep as $k => $v) {
        if (is_string($v) && trim($v) !== '') $parts[] = rawurlencode((string)$k) . '=' . rawurlencode($v);
    }
    return '/?' . implode('&', array_filter($parts, fn($p) => $p !== ''));
}
