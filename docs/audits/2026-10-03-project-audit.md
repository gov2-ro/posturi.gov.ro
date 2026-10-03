# Project audit — 2026-10-03

Audit baseline: commit `2c5e480`. Live site: <https://posturi.gov2.ro/>.
This document preserves the initial audit delivered in chat. Findings describe
the observed state on October 3; they are not a claim about subsequent deployments.
Implementation work is tracked in [the consolidated backlog](../backlog.md) and
[the technical specifications](../specs/2026-10-03-project-audit/README.md).

## Initial assessment

My view: **this is a useful product with a sensible production architecture and substantial engineering behind it. Its biggest weakness is confidence in the data—especially whether a job is still open—not a lack of features.** I would spend the next iteration on reliability and clarity before expanding CV matching, maps or accounts.

I reviewed the documentation and backlog, traced the main ingestion, enrichment, export and PHP serving paths, ran the tests, and inspected the live site with Playwright on desktop and mobile. I made no project changes during the audit.

## What is working well

- Python/Django → PostgreSQL → SQLite → PHP is a practical architecture for this project. It keeps expensive processing away from public requests and makes hosting simple.
- Atomic exports, row-collapse guards, build provenance, structured extraction schemas and provider comparison tooling are good foundations.
- **446 tests passed.** All 22 PHP files passed syntax checks.
- The live interface is readable and restrained. Search, county filtering through HTMX, navigation and mobile layout worked in my checks, with no browser errors or horizontal overflow.
- The recent outage has at least partly recovered: the live homepage showed **1,875 active announcements**, including postings published October 2. The statistics page showed 821 extractions on October 3.

## Findings to prioritize

1. **High: the production Markdown sanitizer permits script-bearing attributes and links.** Parsedown has safe mode disabled, and `strip_tags()` preserves attributes on allowed tags. I reproduced this locally: an `<a>` with `onclick` and a `javascript:` URL survives rendering. Imported announcement text and LLM output should be treated as untrusted. Replace this with sanitization that validates attributes and URL schemes, with a focused regression test. I did not inject anything into the live site. Source: [helpers.php](../../webapp-php/helpers.php), `markdown_to_html()`, `sanitize_html()` and `render_markdown()`.

2. **High: “active” still frequently means the announcement has not expired, rather than applications remain open.** In the newest 200 results from the live JSON feed, **194 deadlines came from announcement expiry and six from the scraper**. That is a sample, not a whole-dataset measurement, but it shows the limitation is widespread among recent postings. The detail page discloses the fallback; the prominent countdown and “active” label still communicate more certainty than the data supports. Prioritize reliable application deadlines and distinguish confirmed deadlines from expiry estimates. Source: [export-to-sqlite.py](../../export-to-sqlite.py), `_apply_deadline()`.

3. **High: cached detail pages never get refreshed.** `fetch-anunturi.py` skips any page whose cache file exists. Corrections, changed deadlines, new attachments and cancellations can therefore remain invisible indefinitely. Cancellation detection also still depends on the old index expiry marker. Add a bounded refresh policy for open competitions, read status from the detail page, and invalidate enrichment when source content changes. This deserves priority over a scraper rewrite. Source: [fetch-anunturi.py](../../fetch-anunturi.py), `process_csv()` and `is_cancelled()`.

4. **High: extraction failures can still look like successful runs.** `llm-schema.py` counts failed calls and prints a summary, but finishes successfully even if every extraction fails. Coverage checks compare against the previous run and only warn, so gradual deterioration can evade the gate. The backlog already documents exactly this failure. Add meaningful exit status, early handling of account-wide errors such as insufficient balance, absolute coverage thresholds and a longer-term baseline. Sources: [llm-schema.py](../../llm-schema.py), its main processing loop; [check-export.py](../../ops/check-export.py), `evaluate()` and `load_previous()`.

5. **Medium: result rows display two different deadline dates.** The countdown uses `apply_deadline`, but the date underneath uses `expires_at`. I saw a live row displaying **“13 zile” beside “10.11.2026”** on October 3. Render both from the same chosen deadline and show its provenance. This is a small fix with a direct benefit to readers. Source: [result_list.php](../../webapp-php/partials/result_list.php).

6. **Medium: the freshness statement overclaims what was checked.** Import assigns `last_seen_at = today` to cached CSV rows. The About page nevertheless describes the timestamp as the last verification at the source. A new export does not prove a successful scrape or a refreshed detail page. Track scrape success, detail retrieval and export time separately. Sources: [import_csvs.py](../../webapp/apps/jobs/management/commands/import_csvs.py), [header.php](../../webapp-php/inc/header.php) and [about.php](../../webapp-php/pages/about.php).

7. **Medium: statistics need an explicit dataset scope.** The public database contains the expiry-selected slice, while graphs describe monthly trends and employer distributions. Older postings disappear as they expire, so the monthly graph cannot represent historical hiring volume. Label these as statistics of the current export, or export historical aggregates from PostgreSQL. “Tip angajator” also currently shows contract categories rather than institution types. Source: [stats.php](../../webapp-php/pages/stats.php).

8. **Medium: malformed query parameters cause server errors.** I confirmed `?q[]=medic` returns HTTP 500: scalar parameters reach `trim()` without type validation. Validate parameter shapes centrally and return a controlled response. Public PHP request handling needs regression coverage alongside the Python suite. Sources: [list.php](../../webapp-php/pages/list.php) and [helpers.php](../../webapp-php/helpers.php), `build_filters()`.

9. **Medium: interrupted attachment downloads can become permanent cache entries.** Downloads write directly to the final filename; subsequent runs skip any existing file. A failed transfer can leave a partial document that is never retried. Download to a temporary file, validate it, then rename atomically. Source: [download-attachments.py](../../download-attachments.py), `download_file()`.

## Documentation and backlog assessment

The activity log is valuable: it records measurements, failures and reasons for decisions. Keep it as history.

The backlog is much less dependable as an action list. It mixes incidents, completed work, proposals and old measurements. Open entries still say that nothing schedules the pipeline, all providers are unusable, counties remain fragmented and `llm-schema.py` lacks `--limit`; current code or live behavior contradicts those statements. The generated context map also presents Django as the app and quotes “25% coverage,” which should not be treated as measured test coverage.

I would consolidate the backlog into a short, verified queue:

- **First:** sanitizer, deadline display, extraction failure reporting.
- **Next:** detail refresh and cancellation detection, deadline backfill, truthful freshness indicators.
- **Then:** statistics scope, append-only LLM cost accounting, PHP browser regression checks and deployment version visibility.
- **Afterward:** simplify overlapping filters, normalize skill/language labels and improve employer discovery.

The interface already offers a lot of filtering power. It now needs fewer ambiguous choices: duplicated study/program controls, internal labels such as `norma_intreaga`, and salary estimates derived from an unadopted draft all require careful presentation. I would keep those salary estimates clearly secondary to verified announcement information.

I would also retire the duplicated Django public frontend while retaining Django’s models, admin and processing commands. I would **not prioritize removing CSVs or optimizing facet queries yet**; neither addresses the most consequential current failures.

## Evidence and limits

- Test command: `webapp/.venv/bin/python -m pytest webapp/tests -q --tb=short`, with local PostgreSQL access: **446 passed, 15 warnings** about the missing collected staticfiles directory. The sandbox-only attempt had 420 passes and 26 PostgreSQL connection setup errors; those disappeared with database access.
- PHP syntax checks: `php -l` on every PHP file under `webapp-php/`: **22 checked, zero errors**.
- Playwright: Chromium at 1280×900 and 375×812; homepage, search, county-filtered browse, employers, stats, About and a detail page returned 200. Mobile county selection updated results through HTMX to 108 Cluj postings. No page/console errors or horizontal overflow in the checked views.
- JSON, Atom, iCal, sitemap and robots endpoints returned 200. Direct `/posturi.sqlite` returned 404 and did not expose the database in this check. A malformed scalar query (`/?q%5B%5D=medic`) returned 500.
- Local sanitizer reproduction: `render_markdown('<a href="javascript:alert(1)" onclick="alert(2)">test</a>')` retained both unsafe attributes. This establishes the unsafe rendering path; it is not evidence of an existing production exploit.
- Live build shown on About: `2c5e480`; update stamp: `03.10.2026, 12:59`. Stats: 2,246 rows in the export, 1,875 application-active rows. The distinction matters when interpreting counts.
- Attempted comparison of the Paznic deadline against the official source did not yield usable text. No individual application deadline was independently certified in this audit.

This was a broad repository and live-site audit, not a production-host inspection or an exhaustive review of every historical artifact. I did not inspect current VPS scheduling, backups or logs, so live recovery does not establish that unattended operation is healthy. Screenshots and browser scripts were temporary audit aids, not committed test fixtures. No paid LLM calls or deployments were made.
