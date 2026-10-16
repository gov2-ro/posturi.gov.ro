<?php
declare(strict_types=1);
/**
 * BETA-V5: reading a v5 extraction on the detail page, and the parts of v4 a page
 * can now show that it never did (the typed competition calendar).
 *
 * v4 and v5 rows coexist (docs/specs/2026-10-16-v5-extraction/README.md §6): every
 * function here accepts either shape and returns nothing for keys a row lacks.
 * First-party include, like checklist.php and eligibility.php.
 */

/** The 32 calendar stages of schema_models.CalendarStage, as a candidate reads them. */
const CALENDAR_STAGE_LABELS = [
    'publicare' => 'Publicarea anunțului',
    'depunere_dosare' => 'Depunerea dosarelor',
    'selectie_dosare' => 'Selecția dosarelor',
    'rezultate_selectie_dosare' => 'Rezultatele selecției dosarelor',
    'contestatii_selectie_dosare' => 'Contestații la selecția dosarelor',
    'rezultate_contestatii_selectie_dosare' => 'Rezultatele contestațiilor la selecția dosarelor',
    'proba_scrisa' => 'Proba scrisă',
    'rezultate_proba_scrisa' => 'Rezultatele probei scrise',
    'contestatii_proba_scrisa' => 'Contestații la proba scrisă',
    'rezultate_contestatii_proba_scrisa' => 'Rezultatele contestațiilor la proba scrisă',
    'proba_practica' => 'Proba practică',
    'rezultate_proba_practica' => 'Rezultatele probei practice',
    'contestatii_proba_practica' => 'Contestații la proba practică',
    'rezultate_contestatii_proba_practica' => 'Rezultatele contestațiilor la proba practică',
    'proba_sportiva' => 'Proba sportivă',
    'rezultate_proba_sportiva' => 'Rezultatele probei sportive',
    'contestatii_proba_sportiva' => 'Contestații la proba sportivă',
    'rezultate_contestatii_proba_sportiva' => 'Rezultatele contestațiilor la proba sportivă',
    'interviu' => 'Interviul',
    'rezultate_interviu' => 'Rezultatele interviului',
    'contestatii_interviu' => 'Contestații la interviu',
    'rezultate_contestatii_interviu' => 'Rezultatele contestațiilor la interviu',
    'proba_orala' => 'Proba orală',
    'rezultate_proba_orala' => 'Rezultatele probei orale',
    'contestatii_proba_orala' => 'Contestații la proba orală',
    'rezultate_contestatii_proba_orala' => 'Rezultatele contestațiilor la proba orală',
    'test_psihologic' => 'Testul psihologic',
    'rezultate_test_psihologic' => 'Rezultatele testului psihologic',
    'contestatii_test_psihologic' => 'Contestații la testul psihologic',
    'rezultate_contestatii_test_psihologic' => 'Rezultatele contestațiilor la testul psihologic',
    'rezultate_finale' => 'Rezultatele finale',
    'altele' => 'Altă etapă',
];

/** schema_models.DOCUMENT_CODES — the standard dosar items, with a fixed Romanian label. */
const DOCUMENT_CODE_LABELS = [
    'formular_inscriere' => 'Formular de înscriere',
    'act_identitate' => 'Copia actului de identitate',
    'certificat_casatorie' => 'Copia certificatului de căsătorie (dacă e cazul)',
    'acte_studii' => 'Copii ale actelor de studii',
    'carnet_munca_adeverinta_vechime' => 'Carnetul de muncă sau adeverința de vechime',
    'cazier_judiciar' => 'Cazier judiciar',
    'adeverinta_medicala' => 'Adeverință medicală',
    'certificat_integritate' => 'Certificat de integritate comportamentală',
    'cv' => 'Curriculum vitae',
    'declaratie_conformitate' => 'Declarație privind conformitatea copiilor',
    'dosar_plic' => 'Dosar plic',
];

const RECRUITMENT_KIND_LABELS = [
    'recrutare' => 'Concurs de recrutare',
    'promovare' => 'Examen de promovare (doar pentru angajații instituției)',
    'management' => 'Concurs de management',
    'rezidentiat' => 'Rezidențiat',
    'altele' => 'Altă procedură',
];

/** The same kinds, short, for a filter option or a chip. */
const RECRUITMENT_KIND_SHORT = [
    'recrutare' => 'Recrutare', 'promovare' => 'Promovare (internă)', 'management' => 'Management',
    'rezidentiat' => 'Rezidențiat', 'altele' => 'Altă procedură',
];

const APPLICATION_CHANNEL_LABELS = [
    'sediu' => 'la sediu', 'posta' => 'prin poștă / curier', 'email' => 'prin e-mail', 'online' => 'online',
];

/** Decoded `schema_json` of a fetched row, [] when absent or not an object. */
function schema_of(array $p): array {
    $s = $p['schema_json'] ?? null;
    if (is_array($s)) return $s;
    $d = is_string($s) && $s !== '' ? json_decode($s, true) : null;
    return is_array($d) ? $d : [];
}

/** True for a v5 answer: model_dump always writes `recruitment` (evidence.py uses the same test). */
function schema_is_v5(array $schema): bool {
    return array_key_exists('recruitment', $schema);
}

/**
 * The free-text keys v5 no longer emits, rebuilt from the structured ones so the
 * description sections and the JSON-LD read the same for a v4 and a v5 row. A value the
 * row already has is never replaced.
 */
function schema_text_fallbacks(array $schema): array {
    $edu = $schema['education']['verbatim'] ?? null;
    if (empty($schema['educationRequirements']) && is_string($edu) && trim($edu) !== '') {
        $schema['educationRequirements'] = $edu;
    }
    $exp = $schema['experience']['verbatim'] ?? null;
    if (empty($schema['experienceRequirements']) && is_string($exp) && trim($exp) !== '') {
        $schema['experienceRequirements'] = $exp;
    }
    if (empty($schema['skills']) && !empty($schema['skill_list']) && is_array($schema['skill_list'])) {
        $labels = [];
        foreach ($schema['skill_list'] as $s) {
            $l = is_array($s) ? trim((string)($s['label'] ?? '')) : '';
            if ($l !== '') $labels[] = '- ' . $l . (($s['required'] ?? true) === false ? ' (avantaj)' : '');
        }
        if ($labels) $schema['skills'] = implode("\n", $labels);
    }
    if (empty($schema['application_docs']) && ($docs = v5_document_items($schema))) {
        $schema['application_docs'] = implode("\n", array_map(fn($d) => '- ' . $d['label'], $docs));
    }
    return $schema;
}

/**
 * The v5 dosar as items: the standard codes with their fixed labels, then the specific
 * documents with their quote and roles. [] for a v4 row (its `application_docs` text is
 * read by checklist_docs() as before).
 *
 * @return array<int,array{label:string,evidence:?string,roles:array<int,string>,standard:bool}>
 */
function v5_document_items(array $schema): array {
    $docs = $schema['documents'] ?? null;
    if (!is_array($docs)) return [];
    $out = [];
    foreach ((array)($docs['standard'] ?? []) as $code) {
        $code = (string)$code;
        if (isset(DOCUMENT_CODE_LABELS[$code])) {
            $out[] = ['label' => DOCUMENT_CODE_LABELS[$code], 'evidence' => null, 'roles' => [], 'standard' => true];
        }
    }
    foreach ((array)($docs['specific'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $label = trim((string)($item['label'] ?? ''));
        if ($label === '') continue;
        $ev = trim((string)($item['evidence'] ?? ''));
        $roles = array_values(array_filter(array_map(fn($r) => trim((string)$r), (array)($item['roles'] ?? [])), fn($r) => $r !== ''));
        $out[] = ['label' => $label, 'evidence' => $ev !== '' ? $ev : null, 'roles' => $roles, 'standard' => false];
    }
    return $out;
}

/**
 * The extracted competition calendar (v4 and v5) as one normalised table: stage label,
 * date, time, and what the posting said. Dated rows first, in date order; a row stated only
 * relatively (v5 `relative`) keeps its rule instead of a date. [] when the row has no
 * extracted calendar — the page then falls back to the scraped calendar_events table.
 *
 * @return array<int,array{stage:string,label:string,date:string,time:?string,detail:?string}>
 */
function llm_calendar_rows(array $schema): array {
    $events = $schema['competition_calendar']['events'] ?? null;
    if (!is_array($events)) return [];
    $rows = [];
    foreach ($events as $ev) {
        if (!is_array($ev)) continue;
        $stage = (string)($ev['stage'] ?? '');
        $date = (string)($ev['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
        $time = preg_match('/^\d{2}:\d{2}$/', (string)($ev['time'] ?? '')) ? (string)$ev['time'] : null;
        $verbatim = trim((string)($ev['verbatim'] ?? ''));
        $relative = trim((string)($ev['relative'] ?? ''));
        $label = CALENDAR_STAGE_LABELS[$stage] ?? ($verbatim !== '' ? $verbatim : $stage);
        if ($label === '') continue;
        $detail = $date === '' && $relative !== '' ? $relative : ($verbatim !== '' && $verbatim !== $label ? $verbatim : null);
        $rows[] = ['stage' => $stage, 'label' => $label, 'date' => $date, 'time' => $time, 'detail' => $detail];
    }
    usort($rows, static fn($a, $b) => ($a['date'] === '' ? 1 : 0) <=> ($b['date'] === '' ? 1 : 0)
        ?: strcmp($a['date'], $b['date']));
    return $rows;
}

/** "0,75 normă" from a fraction; '' for a full norm or nothing stated. */
function fte_label($fte): string {
    if (!is_numeric($fte)) return '';
    $f = (float)$fte;
    if ($f <= 0 || $f >= 1) return '';
    return rtrim(rtrim(number_format($f, 2, ',', ''), '0'), ',') . ' normă';
}

/**
 * The who-can-apply-and-how facts of a v5 row, in reading order, as label => text.
 * [] for a v4 row.
 *
 * @return array<string,string>
 */
function v5_facts(array $schema): array {
    if (!schema_is_v5($schema)) return [];
    $out = [];
    $r = $schema['recruitment'] ?? null;
    if (is_array($r) && !empty($r['kind']) && $r['kind'] !== 'recrutare') {
        $out['Tip concurs'] = RECRUITMENT_KIND_LABELS[$r['kind']] ?? (string)$r['kind'];
    }
    if (is_array($r) && ($r['internal_only'] ?? null) === true && ($r['kind'] ?? '') !== 'promovare') {
        $out['Cine poate participa'] = 'Doar angajații instituției';
    }
    if (is_numeric($schema['vacancies_total'] ?? null) && (int)$schema['vacancies_total'] > 1) {
        $out['Posturi scoase la concurs'] = (string)(int)$schema['vacancies_total'];
    }
    $c = is_array($schema['contract'] ?? null) ? $schema['contract'] : [];
    if (($c['post_status'] ?? '') === 'temporar_vacant') $out['Statutul postului'] = 'Temporar vacant (până la revenirea titularului)';
    if (($fl = fte_label($c['fte'] ?? null)) !== '') $out['Normă'] = $fl;
    if (!empty($c['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$c['end_date'])) {
        $out['Contract până la'] = fmt_date((string)$c['end_date']);
    }
    $a = is_array($schema['application'] ?? null) ? $schema['application'] : [];
    $ch = array_values(array_filter(array_map(fn($x) => APPLICATION_CHANNEL_LABELS[(string)$x] ?? null, (array)($a['channels'] ?? []))));
    if ($ch) $out['Depunerea dosarului'] = implode(', ', $ch);
    if (!empty($a['window_start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$a['window_start'])) {
        $out['Depunere începând cu'] = fmt_date((string)$a['window_start']);
    }
    $age = is_array($schema['age_limit'] ?? null) ? $schema['age_limit'] : null;
    if ($age) {
        $parts = [];
        if (is_numeric($age['min_years'] ?? null)) $parts[] = 'minimum ' . (int)$age['min_years'] . ' ani';
        if (is_numeric($age['max_years'] ?? null)) $parts[] = 'maximum ' . (int)$age['max_years'] . ' ani';
        $txt = $parts ? implode(', ', $parts) : trim((string)($age['verbatim'] ?? ''));
        if ($txt !== '') $out['Limită de vârstă'] = $txt;
    }
    $locs = array_values(array_filter(array_map(fn($x) => trim((string)$x), (array)($schema['work_localities'] ?? [])), fn($x) => $x !== ''));
    if ($locs) $out['Locuri de muncă'] = implode(', ', $locs);
    $sub = trim((string)($schema['employer_context']['subordinated_to'] ?? ''));
    if ($sub !== '') $out['În subordinea'] = $sub;
    return $out;
}
