<?php
/**
 * Helpers of the server-rendered page tests (home_test.php, theme_test.php): fetch a page without following
 * redirects, query its DOM, read the numbers of the counts, and the result count of a list URL.
 */

/** GET a path; returns [status, body, headers]. Redirects are NOT followed. */
function http_page(string $base, string $path, array $req_headers = []): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0,
        'header' => implode("\r\n", $req_headers)]]);
    $body = (string)@file_get_contents($base . $path, false, $ctx);
    $status = 500;
    $headers = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
        if (preg_match('#^([^:]+):\s*(.*)$#', $h, $m)) $headers[strtolower($m[1])] = $m[2];
    }
    return [$status, $body, $headers];
}

function xp(string $html): DOMXPath {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($dom);
}

/** The number at the start of "1.197 de posturi", "1 post", "37". */
function lead_number(string $text): int {
    return preg_match('/^[\s\x{a0}]*([\d.]+)/u', $text, $m) ? (int)str_replace('.', '', $m[1]) : -1;
}

/** The number in "(1.197)" or "Alba (15)": the last bracket of the text. */
function paren_number(string $text): int {
    return preg_match('/\(([\d.]+)\)\s*$/u', trim($text), $m) ? (int)str_replace('.', '', $m[1]) : -1;
}

/** How many postings a list URL shows (the count above the results). */
function list_count(string $base, string $href): int {
    [, $body] = http_page($base, $href);
    if (preg_match('#<span class="font-mono font-medium text-ink">([\d.]+)</span>\s*anun#u', $body, $m)) return (int)str_replace('.', '', $m[1]);
    // An empty result has no such count: the status line says "0 anunțuri găsite".
    return preg_match('#id="results-status"[^>]*>0 anun#u', $body) ? 0 : -1;
}
