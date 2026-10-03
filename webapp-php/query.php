<?php
/**
 * The one query-string decoder. index.php validates every request here before
 * any page, filter builder, chip renderer or feed touches $_GET, so a scalar
 * param given as an array (`?q[]=medic`, a 500 before this existed) gets a
 * controlled 400 instead of a TypeError deep in trim(), and facet params are
 * always flat arrays of bounded scalars by the time build_filters() sees them.
 *
 * Unknown parameters pass through for compatibility; recognized ones get one
 * of the two shapes below. Feeds and pages share this decoder — a malformed
 * request can never reach a feed handler in a different state than a page.
 */

/** Scalar params: exactly one string, never an array. */
const QUERY_SCALARS = [
    'q', 'sort', 'status', 'page', 'employer', 'employer_id',
    'remote', 'computer', 'salary_bucket', 'schema', 'shift', 'has_salary',
    'expires_after', 'expires_before', 'title', 'skill_mode',
];

/**
 * Facet params: `judet[]=cluj&judet[]=iasi` (repeated checkboxes) or the
 * legacy scalar form `?eqf=6`. Either way they normalize to a flat array of
 * strings. Derived from MULTI_PARAMS so the form field names and the decoder
 * cannot drift apart; the JSON-array and v4 facets add their own entries.
 */
require_once __DIR__ . '/helpers.php';

const QUERY_FACETS = [
    // MULTI_PARAMS, plus the facets that arrived with prompt v3/v4.
    'isced', 'skill', 'lang', 'credential', 'domain', 'stage', 'eqf',
    'occupation', 'funding', 'sector', 'duration', 'schedule',
];

/** Whether a param accepts multiple values (array syntax in the URL). */
function query_is_facet(string $key): bool {
    static $all = null;
    if ($all === null) {
        $all = array_merge(MULTI_PARAMS, QUERY_FACETS);
    }
    return in_array($key, $all, true);
}

/** Per-value and per-facet bounds, so no input drives unbounded SQL. */
const QUERY_VALUE_MAX = 300;
const QUERY_FACET_MAX = 64;

/**
 * Validate and normalize one query array.
 *
 * Returns [array|null, ?string]: the normalized params, or null plus a
 * human-readable reason (safe to show — it names only the parameter, never
 * the raw input). The caller renders the 400.
 */
function validated_query(array $get): array {
    $out = [];
    foreach ($get as $key => $value) {
        if (!is_string($key) || $key === '') continue;
        if (is_array($value)) {
            if (!query_is_facet($key)) {
                return [null, "parametrul „{$key}” nu acceptă valori multiple"];
            }
            $flat = [];
            foreach ($value as $v) {
                if (is_array($v)) {
                    return [null, "parametrul „{$key}” are o structură invalidă"];
                }
                $v = (string)$v;
                if (strlen($v) > QUERY_VALUE_MAX) {
                    return [null, "parametrul „{$key}” este prea lung"];
                }
                $flat[] = $v;
            }
            if (count($flat) > QUERY_FACET_MAX) {
                return [null, "parametrul „{$key}” are prea multe valori"];
            }
            $out[$key] = $flat;
        } else {
            $s = (string)$value;
            if (strlen($s) > QUERY_VALUE_MAX) {
                return [null, "parametrul „{$key}” este prea lung"];
            }
            // Legacy scalar facet links (?eqf=6) normalize to a one-element
            // array, the shape every downstream reader expects.
            $out[$key] = query_is_facet($key) ? [$s] : $s;
        }
    }
    return [$out, null];
}
