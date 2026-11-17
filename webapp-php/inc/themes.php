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
 *                optional `columns` (3: three columns from lg; default: as many as fit) and, with the theme key `icon_file`: `icons`
 *                value => symbol id, `icon_default`, `icon_size` (classes, default `h-6 w-6`); a value with none of them has no icon
 *     counties   the county map, in the first screen beside the title and the search (not in the section flow; `id` is
 *                not shown). The map is the control that selects a county; the search form keeps its own select
 *     stats      the statistics panel, in the first screen below the link to all postings (not in the section flow):
 *                with the theme key `history` the weekly chart with its occupation buttons
 *     recent     the two short lists, "Noi azi" and "Se închid în 3 zile"
 *     follow     the notification cards (calendar and RSS of the base filter)
 *   icon_file    optional: the file in static/ with the symbols of the item icons (no `icon_file` = no icon, no reference)
 *   history      optional: the part of the archive that belongs to the theme: `family` (a profession family) and `groups`,
 *                the buttons of the chart after "Toate": each has `label` and any of `values` (exact occupations), `prefixes`
 *                and `except` (exact occupations that a prefix does not take); an occupation is in the first group that holds it.
 *                No `history` = no chart; no `groups` = a chart with no buttons
 *   search_selects optional: selects in the search form, in place of the text field: a list of `param`, `label`, `all` (the
 *                text of the empty option), `source` (`json` or `column`) and `column`; each lists the values that have a posting
 *                inside the theme. The county select stays. No `search_selects` = the text field
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
                                  ['label' => 'Îngrijitor', 'values' => ['Îngrijitor', 'Infirmier', 'Infirmieră', 'Brancardier']],
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
 * parameters together (ADR 0004: a filtered list has one URL). `sort`, `page` and `title` stay;
 * blank values go.
 */
function theme_redirect_url(array $theme, array $get): string {
    $keep = array_intersect_key($get, ['sort' => 1, 'page' => 1, 'title' => 1]);
    $merged = home_merge_params($theme['base'], array_diff_key($get, $keep));
    $parts = [search_key($merged)];
    foreach ($keep as $k => $v) {
        if (is_string($v) && trim($v) !== '') $parts[] = rawurlencode((string)$k) . '=' . rawurlencode($v);
    }
    return '/?' . implode('&', array_filter($parts, fn($p) => $p !== ''));
}
