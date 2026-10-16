<?php
declare(strict_types=1);

/**
 * UX-10 — data for the "Pot aplica?" preparation panel (docs/specs/2026-10-10-follow-ups/04-eligibility-panel.md).
 *
 * The comparison itself runs in the browser (static/eligibility.js) so that the reader's
 * answers never reach the server. This file only prepares what the browser compares
 * AGAINST: per role, the stated requirements and where their evidence stands. It reads
 * `posting_roles` (role scope, DATA-04) and `requirement_evidence` (UX-13, through
 * load_requirement_evidence()); the labels the tables do not carry (credential labels,
 * field-of-study labels, the model's verbatim sentence) come from `schema_json`, from the
 * SAME role's entry only. Nothing is ever read from another role or from the posting-level
 * blocks of a decomposed posting.
 *
 * First-party include, like skins.php (lib/ holds only vendored libraries).
 */

/**
 * Release gate. Shipped OFF until 2026-10-16, when pax decided to release it for the beta
 * (D18: test experimental features with the small early audience) ahead of a formal review
 * of the comparison rules. Set it back to false to withdraw the panel.
 * `POSTURI_ELIGIBILITY_PANEL=1` (or `=0`) overrides it per process.
 */
// 261016 (pax): "Pot aplica?" is a tool (/pot-aplica/), not a panel on every
// posting. Off; the panel code stays, one constant away.
const ELIGIBILITY_PANEL_ENABLED = false;

function eligibility_panel_enabled(): bool {
    $env = getenv('POSTURI_ELIGIBILITY_PANEL');
    if ($env === '1') return true;
    if ($env === '0') return false;
    return ELIGIBILITY_PANEL_ENABLED;
}

/** The study levels in rank order (lowest first). The panel's select and the JS comparison both follow this order. */
const ELIGIBILITY_STUDY_LEVELS = [
    'generala'     => 'Generală',
    'profesionala' => 'Profesională',
    'liceala'      => 'Liceală',
    'postliceala'  => 'Postliceală',
    'licenta'      => 'Licență',
    'master'       => 'Master',
    'doctorat'     => 'Doctorat',
];

/**
 * True when any of the texts contains a standalone "sau" or "ori" (whole word, any case):
 * the sentence offers alternatives ("licență sau echivalent", "în specialitate ori în
 * domeniul conex"). The extraction keeps one level / one number per block, so the AND/OR
 * grouping of such a sentence is lost, and the comparison must say "unknown". Deliberately
 * coarse: "de două ori pe săptămână" also trips it, which only ever makes the answer
 * "De verificat", never a false match.
 */
function eligibility_has_alternatives(?string ...$texts): bool {
    foreach ($texts as $t) {
        if ($t !== null && preg_match('/(?<![\p{L}\p{N}])(?:sau|ori)(?![\p{L}\p{N}])/iu', $t)) return true;
    }
    return false;
}

/** True when a text speaks of specialty or field ("în specialitate", "specializare", "în domeniul ..."). */
function eligibility_mentions_specialty(?string ...$texts): bool {
    foreach ($texts as $t) {
        if ($t !== null && preg_match('/speciali[tz]|domeniu/iu', $t)) return true;
    }
    return false;
}

/**
 * What the comparison may rely on for one requirement: the evidence row's status and
 * reason, and the id of the disclosure on the page that shows it.
 *
 *   verified    the quote was located in the source (and the row says where);
 *   unverified  a quote exists and was not located;
 *   absent      no quote;
 *   none        no row at all (old export, or the extraction stated no such fact).
 *
 * A "verified" row without a quote or a known part is malformed (evidence_disclosure()
 * renders nothing for it); it must not count as verified, so it reads as "none".
 * `anchor` is set only when the page really renders a disclosure for the row.
 *
 * @param array<string,mixed>|null $row
 * @return array{status:string,reason:?string,anchor:?string}
 */
function eligibility_evidence_state(?array $row): array {
    $none = ['status' => 'none', 'reason' => null, 'anchor' => null];
    if (!$row) return $none;
    $status = (string)($row['status'] ?? '');
    if (!in_array($status, ['verified', 'unverified', 'absent'], true)) return $none;
    if ($status === 'verified'
        && (trim((string)($row['quote'] ?? '')) === '' || !in_array((string)($row['part'] ?? ''), ['anunt', 'anexe'], true))) {
        return $none;
    }
    $anchor = evidence_anchor_id($row);
    $reason = isset($row['reason']) && $row['reason'] !== '' ? (string)$row['reason'] : null;
    return ['status' => $status, 'reason' => $reason, 'anchor' => $anchor !== '' ? $anchor : null];
}

/**
 * The per-role requirement data the browser compares against, or [] when the panel
 * cannot honestly be offered: no `posting_roles` table, or only the stand-in rows an old
 * export gets (role_format 0: the posting's flat columns, which describe the posting, not a
 * role, and no evidence), or no role stating anything comparable.
 *
 * @param array<string,mixed> $schema    the decoded `schema_json` of the posting ([] when none)
 * @param array<int,array<string,array<int,array<string,mixed>>>> $evidence  load_requirement_evidence()
 * @return list<array<string,mixed>>
 */
function eligibility_roles(PDO $pdo, int $posting_id, array $schema, array $evidence): array {
    try {
        $st = $pdo->prepare('SELECT ordinal, decomposed, title, study_level, isced_fields, exp_years,
                                    exp_in_specialty, exp_none_required, role_format
                             FROM posting_roles WHERE posting_id = ? ORDER BY ordinal');
        $st->execute([$posting_id]);
        $rows = $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    if (!$rows) return [];
    foreach ($rows as $r) {
        if ((int)($r['role_format'] ?? 0) < 1) return [];       // a stand-in, not a role
    }

    $entries = is_array($schema['positions'] ?? null) ? array_values($schema['positions']) : [];
    $roles = [];
    $comparable = false;
    foreach ($rows as $r) {
        $ordinal = (int)$r['ordinal'];
        $decomposed = (int)$r['decomposed'] === 1;
        // The ONE container this role's facts come from: its own positions[] entry, or the flat
        // schema for the implicit row. Never the posting-level blocks of a decomposed posting.
        $box = $decomposed ? ($entries[$ordinal - 1] ?? []) : $schema;
        $box = is_array($box) ? $box : [];
        $by = $evidence[$ordinal] ?? [];

        $edu = is_array($box['education'] ?? null) ? $box['education'] : [];
        $exp = is_array($box['experience'] ?? null) ? $box['experience'] : [];

        $level = (string)($r['study_level'] ?? '');
        $level = isset(ELIGIBILITY_STUDY_LEVELS[$level]) ? $level : null;

        $edu_ev  = eligibility_evidence_state($by['education'][0] ?? null);
        $exp_ev  = eligibility_evidence_state($by['experience'][0] ?? null);
        $edu_txt = [trim((string)($by['education'][0]['quote'] ?? '')), (string)($edu['verbatim'] ?? '')];
        $exp_txt = [trim((string)($by['experience'][0]['quote'] ?? '')), (string)($exp['verbatim'] ?? '')];

        $years   = $r['exp_years'] !== null ? (float)$r['exp_years'] : null;
        $in_spec = $r['exp_in_specialty'] !== null ? (int)$r['exp_in_specialty'] : null;
        $none    = $r['exp_none_required'] !== null ? (int)$r['exp_none_required'] : null;

        $role = [
            'ordinal'           => $ordinal,
            'implicit'          => !$decomposed,
            'title'             => $decomposed ? (trim((string)($r['title'] ?? '')) ?: 'Postul ' . $ordinal) : 'Postul',
            'study_level'       => $level,
            'isced'             => eligibility_field_labels($r['isced_fields'] ?? null, $edu),
            'exp_years'         => $years,
            'exp_in_specialty'  => $in_spec,
            'exp_none_required' => $none,
            'evidence'          => ['education' => $edu_ev, 'experience' => $exp_ev],
            'alternatives'      => [
                'education'  => eligibility_has_alternatives(...$edu_txt),
                'experience' => eligibility_has_alternatives(...$exp_txt),
            ],
            // "required": false is how the extraction marks an advantage rather than a condition.
            'advantage'         => ['education' => ($edu['required'] ?? null) === false],
            // The model flagged the experience as NOT in the specialty (its default when the sentence is
            // silent) yet the sentence itself speaks of specialty or field: the flag cannot be trusted.
            'exp_specialty_conflict' => $in_spec === 0 && eligibility_mentions_specialty(...$exp_txt),
            'credentials'       => eligibility_credentials($box, $by['credential'] ?? []),
        ];
        $roles[] = $role;
        if ($level !== null || $years !== null || $none === 1 || $role['isced'] || $role['credentials']) {
            $comparable = true;
        }
    }
    return $comparable ? $roles : [];
}

/**
 * The field-of-study labels of one role: the codes `posting_roles.isced_fields` lists
 * (the authority for WHICH fields), labelled with the schema's own `label_ro` where the
 * same entry has one, else with the ISCED broad-field name. Unique, in listed order.
 *
 * @param array<string,mixed> $edu
 * @return list<string>
 */
function eligibility_field_labels(mixed $isced_json, array $edu): array {
    $codes = is_string($isced_json) ? (json_decode($isced_json, true) ?: []) : [];
    if (!is_array($codes)) return [];
    $own = [];
    foreach ((array)($edu['fields_of_study'] ?? []) as $f) {
        if (is_array($f) && is_string($f['isced_field'] ?? null) && trim((string)($f['label_ro'] ?? '')) !== '') {
            $own[$f['isced_field']] ??= trim((string)$f['label_ro']);
        }
    }
    $labels = [];
    foreach ($codes as $code) {
        if (!is_string($code) || $code === '') continue;
        $label = $own[$code] ?? isced_label($code);
        $label = mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
        if (!in_array($label, $labels, true)) $labels[] = $label;
    }
    return $labels;
}

/**
 * The credentials of one role, each with the EXACT label the source extraction gave it
 * (the reader answers about that label, not about an alias), its kind, its position `index`
 * in the role's list (the evidence row's fact_index) and its evidence state. Credentials
 * have no evidence field in the schema, so today every one of them reads "absent".
 *
 * @param array<string,mixed> $box
 * @param array<int,array<string,mixed>> $evidence_rows  this role's credential evidence rows by fact_index
 * @return list<array<string,mixed>>
 */
function eligibility_credentials(array $box, array $evidence_rows): array {
    $out = [];
    $list = is_array($box['credentials'] ?? null) ? $box['credentials'] : [];
    foreach ($list as $i => $c) {
        if (!is_array($c)) continue;
        $kind  = is_string($c['kind'] ?? null) && $c['kind'] !== '' ? $c['kind'] : 'altele';
        $label = trim((string)($c['label'] ?? ''));
        if ($label === '') $label = credential_kind_label($kind);      // an unnamed credential is described by its kind
        $out[] = [
            'index'        => (int)$i,
            'label'        => $label,
            'kind'         => $kind,
            'evidence'     => eligibility_evidence_state($evidence_rows[(int)$i] ?? null),
            'alternatives' => eligibility_has_alternatives($label),
            'advantage'    => ($c['required'] ?? null) === false,
        ];
    }
    return $out;
}

/**
 * Everything the panel partial needs, or null when no panel should render.
 *
 * @param array<string,mixed> $schema
 * @param array<int,array<string,array<int,array<string,mixed>>>> $evidence
 * @return array{roles:list<array<string,mixed>>,json:string}|null
 */
function eligibility_panel_data(PDO $pdo, int $posting_id, array $schema, array $evidence): ?array {
    $roles = eligibility_roles($pdo, $posting_id, $schema, $evidence);
    if (!$roles) return null;
    $levels = [];
    foreach (ELIGIBILITY_STUDY_LEVELS as $value => $label) $levels[] = ['value' => $value, 'label' => $label];
    $json = json_encode(['v' => 1, 'levels' => $levels, 'roles' => $roles],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    return $json === false ? null : ['roles' => $roles, 'json' => $json];
}
