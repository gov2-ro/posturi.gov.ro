# FIX-08 — Make attachment downloads atomic

Priority: P1. Dependencies: none; coordinate asset identity/revisions with FIX-03.
Audit finding: 9.

## Problem and scope

`download_file()` writes straight to its final filename and skips every existing
file. A transfer failure can leave a truncated final file forever. URL basenames
also need review for query strings and collisions. Own download/cache behavior
and its lookup integration with attachment extraction/metadata.

## Required behavior

- Stream to a unique temporary file beside the target and promote with an atomic
  rename only after successful transfer and validation. Bound time and file size.
  Clean temporary files on failure; retain an existing valid final file during
  replacement. The final path must never describe an incomplete transfer.
- Verify nonzero byte count, `Content-Length` where applicable, expected format
  signatures and basic container integrity (e.g. DOCX ZIP). Handle valid chunked
  responses with no length; reject HTML error pages returned with 200. MIME alone
  is not proof. Give unsupported documents an explicit reason.
- Track normalized source URL, content hash, successful retrieval time and cache
  identity. Distinguish two URLs sharing a basename and URLs carrying queries.
  Retain lookup compatibility with legacy files or provide a migration map used
  by `extract_attachments` and metadata rendering.
- Existing files require validity checks before a cache hit. Provide a bounded
  repair/audit option for legacy partials; do not delete the whole downloads
  directory. Changed files invalidate extracted text/schema via FIX-03 revision
  handling, not just filename existence.
- Summarize downloaded/cached/replaced/invalid/failed counts and nonzero outcome
  for unusable batches using FIX-04 conventions. Preserve source request pacing.

## Acceptance and tests

Mock streaming interruption, timeout, empty file, length mismatch, 200 HTML,
malformed DOCX, valid chunked PDF, duplicate basename, query-string URL and
replacement of an existing good file. No invalid final file appears. A second
run repairs a previously partial file. Failed replacement leaves old bytes
intact; successful replacement triggers only the necessary extraction work.

## Rollout and completion

Inventory existing files and report validation reasons before repairing them.
Ship lookup compatibility first; use a bounded legacy repair with metrics. Keep
the mapping and old valid assets until migration is verified. Operational repair
is distinct from code/test completion and must record its request volume.
