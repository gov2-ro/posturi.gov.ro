# FIX-01 — Sanitize PHP-rendered Markdown

Priority: P0. Dependencies: none. Audit finding: 1.

## Problem and scope

`webapp-php/helpers.php::markdown_to_html()` disables Parsedown safe mode;
`sanitize_html()` only calls `strip_tags()`. Allowed elements retain event
attributes and unsafe URL schemes. The local reproduction in the audit survives
as an executable anchor. Scraped and model-produced content are untrusted.

Own `helpers.php`, any new sanitizer dependency/runtime file, and focused PHP
tests. Inspect every `render_markdown()`/schema renderer caller and raw HTML
output path. Do not change the site's template-owned markup or remove useful
tables, lists and document links as a shortcut.

## Required behavior

- Sanitize the HTML after Markdown conversion with a parser-based allowlist that
  validates elements, attributes and URL protocols. Regex replacements or
  `strip_tags()` alone are insufficient. Choose a maintained PHP sanitizer that
  can be shipped with the existing shared-host deployment; document licensing,
  version and how dependencies reach the host.
- Permit only explicitly needed attributes. Drop all `on*` handlers, styles,
  forms, frames, executable elements and unsafe namespaces. Permit document
  links with approved schemes (`http`, `https`, `mailto`, `tel`) and intentional
  relative/fragment URLs; reject script/data schemes, including encoded forms.
- Enable Parsedown safe mode as defense in depth if compatible, but do not treat
  it as a substitute for output sanitization. Use the same sanitizer for all
  source/LLM Markdown sections, including fallback rendering and feed HTML.
- Preserve escaped code examples, Romanian diacritics, nested lists and tables.
  Apply safe external-link attributes where opening new tabs is supported.

## Acceptance and tests

Fixture tests must cover the audit payload, encoded/mixed-case `javascript:`
URLs, event attributes on allowed tags, malformed HTML, SVG/script embedding,
ordinary links, tables, code and real announcement formatting. Verify rendered
DOM behavior, not just the absence of one spelling. A browser fixture must show
that malicious content cannot execute and a valid document link still works.
No malicious payload is sent to production. Lint changed PHP files and run the
new PHP checks through FIX-07 once that harness exists.

## Rollout and completion

Test representative raw-body and structured-section pages against a copied
export. Ship the sanitizer with the code deployment; no re-extraction/backfill
is necessary. Keep a previous release artifact for emergency rollback, but do
not silently revert to the unsafe renderer. Record the library/version,
deployment compatibility and regression results in the activity log.
