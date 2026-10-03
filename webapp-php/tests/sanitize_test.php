<?php
/**
 * FIX-01 — sanitizer fixture tests.
 *
 * Runs `render_markdown()`/`sanitize_html()` against the audit payload plus
 * encoded, mixed-case and malformed variants. The assertions check rendered
 * output, not merely the absence of one spelling, and every output must parse
 * as well-formed HTML. See docs/specs/2026-10-03-project-audit/01-markdown-sanitization.md.
 *
 * Usage: php tests/sanitize_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
// Production (index.php) loads Parsedown before helpers render anything; the
// tests must exercise that same path, not the minimal fallback.
require_once __DIR__ . '/../Parsedown.php';

// An executable anchor is the audit's reproduction: both the handler and the
// scheme must be gone, while the link text itself is harmless to keep.
run_suite('audit payload: onclick + javascript: href', function () {
    $out = render_markdown('<a href="javascript:alert(1)" onclick="alert(2)">test</a>');
    assert_not_contains('onclick', $out);
    assert_not_contains_ci('javascript:', $out);
    assert_not_contains('alert(', $out);
    assert_well_formed($out);
});

// Same payload through the Markdown link syntax, so the test covers the
// converter path as well as raw HTML passthrough.
run_suite('markdown link syntax with javascript: href', function () {
    $out = render_markdown('[candidat](javascript:alert(document.cookie))');
    assert_not_contains_ci('javascript:', $out);
    assert_not_contains('alert(', $out);
    assert_well_formed($out);
});

run_suite('encoded and mixed-case javascript: schemes', function () {
    $cases = [
        'javascript&#58;alert(1)',
        'javascript&#x3A;alert(1)',
        'JaVaScRiPt:alert(1)',
        "java\nscript:alert(1)",
        "java\tscript:alert(1)",
        '&#106;&#97;vascript:alert(1)',
        '&#x6a;&#x61;vascript:alert(1)',
        '%6a%61vascript:alert(1)',
        'vbscript:msgbox(1)',
        'data:text/html,<script>alert(1)</script>',
        'data:text/html;base64,PHNjcmlwdD4=',
    ];
    foreach ($cases as $url) {
        $out = sanitize_html("<a href=\"$url\">x</a>");
        assert_not_contains('on', $out, "href=$url");
        // The unsafe URL itself must never survive, in any spelling.
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $probe = preg_replace('/\s+/', '', $decoded);
        $normalized_out = preg_replace('/\s+/', '', $out);
        _assert(
            stripos($normalized_out, $probe) === false || stripos($probe, ':') === false,
            "unsafe URL survived sanitization: $url",
            ['actual: ' . var_export(substr($out, 0, 300), true)]
        );
        assert_well_formed($out, "href=$url");
    }
    // And each spelled as markdown, through the full render path.
    foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)'] as $url) {
        $out = render_markdown("[x]($url)");
        assert_not_contains_ci('javascript:', $out);
        assert_well_formed($out);
    }
});

run_suite('event attributes on allowed tags are dropped', function () {
    foreach ([
        '<p onclick="x">text</p>',
        '<strong onmouseover="x">bold</strong>',
        '<table onload="x"><tr><td>c</td></tr></table>',
        '<a onfocus="x" href="/ok">link</a>',
        '<li onerror="x">item</li>',
        '<h2 ondblclick="x">heading</h2>',
    ] as $html) {
        $out = sanitize_html($html);
        assert_not_contains('onclick', $out);
        assert_not_contains('onmouseover', $out);
        assert_not_contains('onload', $out);
        assert_not_contains('onfocus', $out);
        assert_not_contains('onerror', $out);
        assert_not_contains('ondblclick', $out);
        assert_well_formed($out, $html);
    }
});

run_suite('script, svg and other embedding is removed', function () {
    $out = sanitize_html(
        '<p>before</p><script>alert(1)</script>'
        . '<svg><script>alert(2)</script><a xlink:href="javascript:alert(3)">s</a></svg>'
        . '<img src="x" onerror="alert(4)">'
        . '<iframe src="https://evil.example"></iframe>'
        . '<object data="https://evil.example"></object><embed src="x">'
        . '<style>body{display:none}</style>'
        . '<p>after</p>'
    );
    assert_not_contains('script', $out);
    assert_not_contains('svg', $out);
    assert_not_contains('iframe', $out);
    assert_not_contains('object', $out);
    assert_not_contains('embed', $out);
    assert_not_contains('style', $out);
    assert_not_contains('alert(', $out);
    assert_contains('<p>before</p>', $out);
    assert_contains('<p>after</p>', $out);
    assert_well_formed($out);
});

run_suite('malformed HTML is repaired, not passed through', function () {
    // Unclosed elements must be closed by the sanitizer's parser.
    $out = sanitize_html('<p>unclosed paragraph');
    assert_contains('<p>unclosed paragraph</p>', $out);
    assert_well_formed($out);

    $out = sanitize_html('<b><i>nested emphasis');
    assert_contains('<b><i>nested emphasis</i></b>', $out);
    assert_well_formed($out);

    // A stray closing tag for an element that was never opened disappears.
    $out = sanitize_html('stray closing </div> and </table>');
    assert_not_contains('</div>', $out);
    assert_not_contains('</table>', $out);
    assert_well_formed($out);

    // Unclosed cell inside a table is closed, cell content survives.
    $out = sanitize_html('<table><tr><td>cell');
    assert_contains('<td>cell</td>', $out);
    assert_well_formed($out);

    // Unterminated attribute value must not swallow the rest of the document.
    $out = sanitize_html('<p>start</p><a href="https://ok.example" title="unterminated<p>end</p>');
    assert_contains('start', $out);
    assert_contains('end', $out);
    assert_well_formed($out);
});

run_suite('fallback renderer (Parsedown absent) still escapes and sanitizes', function () {
    $out = sanitize_html(markdown_fallback_to_html('<a href="javascript:alert(1)" onclick="alert(2)">test</a>'));
    // Everything is escaped text — no element, no attribute, no executable URL.
    assert_not_contains('<a', $out);
    assert_contains('&lt;a href="javascript:alert(1)" onclick="alert(2)"&gt;test&lt;/a&gt;', $out);
    assert_well_formed($out);

    $out = sanitize_html(markdown_fallback_to_html("linia unu\n\nlinia doi **bold**"));
    assert_contains('<p>linia unu</p>', $out);
    assert_contains('<strong>bold</strong>', $out);
    assert_well_formed($out);
});

run_suite('ordinary document links survive', function () {
    $out = sanitize_html(
        '<p><a href="https://posturi.gov.ro/anunt.pdf">oficial</a> '
        . '<a href="/doc/relativ.pdf">relativ</a> '
        . '<a href="#calendar">fragment</a> '
        . '<a href="mailto:contact@example.ro">email</a> '
        . '<a href="tel:+40700123456">telefon</a></p>'
    );
    assert_contains('href="https://posturi.gov.ro/anunt.pdf"', $out);
    assert_contains('href="/doc/relativ.pdf"', $out);
    assert_contains('href="#calendar"', $out);
    assert_contains('href="mailto:contact@example.ro"', $out);
    assert_contains('href="tel:+40700123456"', $out);
    assert_well_formed($out);
});

run_suite('target=_blank keeps rel noopener', function () {
    $out = sanitize_html('<a href="https://ok.example" target="_blank">x</a>');
    assert_contains('target="_blank"', $out);
    assert_contains('noopener', $out);
    assert_not_contains('target="_parent"', $out);
    assert_well_formed($out);
});

run_suite('tables, code and lists keep their structure', function () {
    $md = "| Zi | Probă |\n|----|-------|\n| 3 | scrisă |\n| 4 | orală |\n\n"
        . "1. primul\n2. al doilea\n   - sub-punct\n   - alt sub-punct\n\n"
        . "```\n<script>alert(1)</script>\n```\n";
    $out = render_markdown($md);
    assert_contains('<table>', $out);
    assert_contains('<th>', $out);
    assert_contains('<td>', $out);
    assert_contains('<ol>', $out);
    assert_contains('<ul>', $out);
    assert_contains('&lt;script&gt;', $out);
    assert_not_contains('<script>', $out);
    assert_well_formed($out);

    // Raw table markup with the attributes real announcements use.
    $raw = '<table><thead><tr><th scope="col">A</th><th scope="col">B</th></tr></thead>'
        . '<tbody><tr><td colspan="2">lată</td></tr><tr><td rowspan="2">înaltă</td><td>x</td></tr>'
        . '<tr><td>y</td></tr></tbody></table>';
    $out = sanitize_html($raw);
    assert_contains('scope="col"', $out);
    assert_contains('colspan="2"', $out);
    assert_contains('rowspan="2"', $out);
    assert_well_formed($out);
});

run_suite('diacritice și text românesc rămân intacte', function () {
    $out = render_markdown("Atribuții: **șef serviciu** — ă, â, î, ș, ț, Ș, Ț.\n\nȘedințele au loc la *ora 10:00*.");
    assert_contains('șef serviciu', $out);
    assert_contains('ă, â, î, ș, ț, Ș, Ț', $out);
    assert_contains('Ședințele', $out);
    assert_well_formed($out);
});

run_suite('realistic announcement formatting survives intact', function () {
    $md = "# Anunț concurs\n\n"
        . "Primăria comunei X organizează concurs pentru ocuparea postului de **consilier**, gradul II.\n\n"
        . "## Condiții\n\n- studii superioare;\n- vechime minim 2 ani;\n- cunoașterea limbii române.\n\n"
        . "Dosarele se depun la sediul instituției, [vezi site-ul oficial](https://example.ro/concurs).\n\n"
        . "| Document | Format |\n|---|---|\n| cerere | PDF |\n| copie CI | PDF |\n";
    $out = render_markdown($md);
    assert_contains('<h1>', $out);
    assert_contains('<strong>consilier</strong>', $out);
    assert_contains('<li>studii superioare;</li>', $out);
    assert_contains('href="https://example.ro/concurs"', $out);
    assert_contains('<table>', $out);
    assert_not_contains('onclick', $out);
    assert_well_formed($out);
});

run_suite('style, class and id attributes are dropped', function () {
    $out = sanitize_html('<p style="color:red" class="x" id="y">text</p>');
    assert_not_contains('style=', $out);
    assert_not_contains('class=', $out);
    assert_not_contains('id=', $out);
    assert_contains('<p>text</p>', $out);
    assert_well_formed($out);
});

run_suite('empty and whitespace-only input', function () {
    assert_same('', render_markdown(''), 'empty markdown');
    assert_same('', render_markdown("  \n \n "), 'whitespace markdown');
    assert_same('', sanitize_html('   '), 'whitespace html');
});

finish();
