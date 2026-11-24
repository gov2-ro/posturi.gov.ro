# Brief — a public JSON API, a bulk download and the API documentation (API-01)

Date: 2026-11-24. Author: Opus. Builder: Sonnet. The owner approved the approach on 2026-11-24: a public API without tokens, a fixed page size, a daily bulk download, a Cloudflare rate-limit rule, and updated documentation.

## Goal

1. A versioned, paginated JSON API under `/api/v1/`, with a list endpoint and one endpoint for each posting.
2. The posting endpoint gives the structured data that the detail page shows: the extraction, the salary, the calendar and the documents.
3. A bulk download of the whole export as one gzip JSON file, so that bulk users do not page through the API.
4. A documentation page `/api/` in Romanian, and updated docs everywhere that names the feeds.
5. Cloudflare can cache every API answer. An unknown parameter does not create a new cache key.

`/posturi.json`, `/posturi.atom`, `/posturi.rss` and `/posturi.ics` stay as they are. They are feeds, and people use them. Do not change their output.

## Read first

- `CLAUDE.md` of the project: the palette rule, the DS rules, the cascade layers and the tone of voice.
- `~/devbox/gov2/gov2ro-design-system/COMPONENTS.md`. Use DS components for the `/api/` page (tables, code blocks, notes).
- `webapp-php/index.php`: the routing, `validated_query()` and the cache policy at shutdown (BETA-CACHE).
- `webapp-php/query.php`: `MULTI_PARAMS`, `QUERY_SCALARS`, `QUERY_FACETS` and `validated_query()`.
- `webapp-php/helpers.php`: `build_filters()`, `fts_query()`, `feed_employer()`, `build_meta()`.
- `webapp-php/feeds/jobs.json.php`: the shape of the current feed row. Reuse its field names.
- `webapp-php/pages/detail.php`: what the detail page reads for one posting (extraction, salary, calendar, attachments, v4/v5 columns).
- `export-to-sqlite.py`: the current columns of `job_postings`, `posting_text`, `calendar_events` and `build_meta`. The bundled local `webapp-php/posturi.sqlite` is old. Use a fresh export or the test fixtures.
- `webapp-php/inc/alerts.php`, lines ~25–35: how the app finds `posturi-private/` outside the web root.

## Decisions (Opus, with the owner)

- **No tokens.** The API is public. The cap is the same for everybody.
- **Fixed page size: 100.** There is no `per_page` parameter. Fewer distinct URLs give more cache hits.
- **Strict parameters on `/api/*`.** An unknown parameter gives HTTP 400 with a JSON error. The HTML pages and the old feeds keep their current rule (unknown parameters are ignored).
- **The bulk download is built on the host, not on the VPS.** The VPS has no PHP (checked 2026-11-24: no `php` binary). One serializer in PHP serves the API and the download, so the two cannot drift. Cost of the alternative: a Python dump in `export-to-sqlite.py` is a second serializer and needs a cross-language test; installing PHP on the VPS adds a dependency outside this brief.
- **The order is `published_at DESC, id DESC`.** It is stable between two data deploys. The data changes 2–3 times a day, so a walk through the pages can skip or repeat a row. The docs say so and point to the download.

## Steps

### 1. One serializer

Add `webapp-php/inc/api.php` with two functions:

- `api_list_row(array $row): array` — the fields of the current `/posturi.json` row, with the same names, plus `detail_url` (the `/api/v1/posturi/<id>` URL) and `page_url` (the HTML detail page).
- `api_posting(array $row, …): array` — everything in `api_list_row()`, plus the structured data of the detail page: the extraction (parsed `schema_json`, with its prompt version), the salary estimate and pay range, the calendar events, the attachment list (names and URLs), the contact person, and the v3/v4/v5 columns that the detail page shows. Include `body_markdown` as `body` (the text of the source announcement).

Do not return internal fields: IDs of the selector, `inf_*` duplicates of the extraction, provenance hashes, or `source_host`. When you are not sure whether a field is internal, list it in your report and leave it out.

### 2. Routes

Add the routes to `index.php`, next to the feeds:

| Route | Answer |
|---|---|
| `GET /api/v1/` | An index: `api_version`, `data` (from `build_meta()`: `built_at`, `postings`), the links to the endpoints, the download and the docs page. |
| `GET /api/v1/posturi` | The list. The same filters as the list page (`build_filters()`, the FTS `q`, `employer`). Add `page` (1, 2, …). The answer is `{api_version, data_built_at, count, page, pages, per_page, next, prev, results}`. `next` and `prev` are full URLs or `null`. |
| `GET /api/v1/posturi/<id>` | One posting: `{api_version, data_built_at, posting}`. Return 404 with a JSON error when the ID is not in the export. |
| `GET /api/v1/descarcare` | The bulk download (step 3). |

Rules for every `/api/*` route:

- `Content-Type: application/json; charset=utf-8` and `Access-Control-Allow-Origin: *`.
- Every error is JSON: `{"error": "…"}`, with `Cache-Control: no-store`. Use 400, 404 and 405 (with `Allow: GET, HEAD`).
- A `page` beyond `pages` gives 404. `page=0`, a negative page or a page that is not a number gives 400.
- A success uses the default cache policy of `index.php` (`s-maxage=3600`). Do not add a new policy.
- Strict parameters: write an allow-list for each route, from `MULTI_PARAMS`, `QUERY_SCALARS` and `QUERY_FACETS` plus `page`. Do not change `validated_query()` for the other routes.
- `org.htmx.cache-buster` is not an API parameter. Give 400 for it like any unknown parameter.
- Use `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`. Do not pretty-print.

### 3. The bulk download

`GET /api/v1/descarcare` gives every posting of the export as `api_posting()` objects, gzip-compressed: `{api_version, data_built_at, count, postings: [...]}`.

- Build the file the first time it is asked for after a new data deploy. Key the file on `build_meta.built_at`: `posturi-private/api/posturi-<built_at, safe for a filename>.json.gz`.
- Build under an exclusive `flock` on a lock file in the same folder. Write to a temp file, then `rename()`. A second request that waits on the lock reads the finished file.
- After a successful build, delete the older `posturi-*.json.gz` files in that folder.
- Serve the file with `readfile()`. Send `Content-Type: application/gzip`, `Content-Disposition: attachment; filename="posturi-gov2-<date>.json.gz"` and `Content-Length`. Do **not** send `Content-Encoding: gzip`. The user must get the `.gz` file, not a decompressed stream.
- Build row by row. Do not hold all postings in memory as one array: write each JSON object to a `gzopen()` stream.
- When `posturi-private/` cannot be written, answer 503 with a JSON error. Do not build in the web root.
- Measure on a full export: the build time, the peak memory and the file size. Report the three numbers. **Stop and ask** if the build takes more than 20 s or the file is larger than 25 MB.
- Check the headers on the live host after the deploy: `curl -sI` twice. Report `cf-cache-status` and whether Apache or Cloudflare adds `Content-Encoding`.

### 4. The documentation page `/api/`

A new HTML page `/api/` (`pages/api_docs.php`), in Romanian. Follow the tone of voice of `CLAUDE.md` for Romanian copy: short sentences, active voice, one term for each concept. Use the terms „API”, „parametru”, „pagină” and „descărcare” the same way every time.

The page has these sections:

1. What the API gives, and that it is free and has no key.
2. The endpoints, with one example request and one shortened example answer each.
3. The parameters of `/api/v1/posturi`. Generate this table from the allow-list of step 2, not by hand, so the docs cannot drift. Give each parameter a short Romanian description.
4. The pagination: 100 rows for each page, the order, and the note that the data changes 2–3 times a day.
5. The bulk download: what it holds, how often it changes, and that bulk users must use it instead of walking the pages.
6. The limits: the cache (up to 1 h), the rate limit at the edge (step 6) and the HTTP codes.
7. The fields: a table of the fields of `api_posting()` with their type and meaning.
8. The source and the license notice. Copy the wording that `/despre/` uses for the source data. Do not invent a license. If `/despre/` has no license text, write „Datele provin de pe posturi.gov.ro” and list the license as an open question in your report.
9. The feeds (`/posturi.json`, Atom, RSS, iCal): one paragraph that says they stay and how they differ from the API.

### 5. Update the other docs

- `webapp-php/pages/about.php`, line ~48: link the API page and the download next to the feeds.
- `webapp-php/inc/footer.php`: add „API” to the footer links.
- `webapp-php/pages/llms.php`: add the API index, the docs page and the download.
- `webapp-php/pages/sitemap.php`: add `/api/`.
- `README.md`: add a section „Public API” (routes, the serializer in `inc/api.php`, the download cache in `posturi-private/api/`, the strict parameters, the tests). Update the `feeds/` row of the table at line ~826 so that it does not call `/posturi.json` „the JSON API”.
- `webapp-php/inc/header.php`, line 55: keep the `alternate` link to `/posturi.json`.

### 6. The Cloudflare rate-limit rule (owner step)

The owner adds the rule in the Cloudflare dashboard. Sonnet writes the exact rule into the README section and into the report:

- Expression: `starts_with(http.request.uri.path, "/api/")`.
- A first proposal: 60 requests for each 10 s for each IP, then block for 10 s. The rule must not count requests that Cloudflare answers from its cache, if the plan allows that.

Open points for the owner:
- Does the free plan allow this rule on this zone? The Web Analytics note of 2026-11-18 says that the zone is not on the account that the owner uses.
- `CF_ZONE_ID` is empty on the Mac (BETA-CACHE). So a deploy from the Mac does not purge the edge, and the cached API answers can be up to 1 h old.

### 7. Tests

- A new `webapp-php/tests/api_test.php` with the fixture of the other tests:
  - The list: the count, the pages, `next` and `prev`, a filter, `q`, `employer`, the last page and the page after it (404).
  - The parameters: an unknown parameter (400), `page=0` and `page=x` (400), `q[]=x` (400), and `org.htmx.cache-buster` (400).
  - The posting: a known ID, an unknown ID (404) and a non-numeric ID (404).
  - Every error has a JSON body and `Cache-Control: no-store`.
  - The download: one build, a second request that reuses the file, a new `built_at` that builds a new file and deletes the old one, and a gzip that decodes to valid JSON with `count` equal to the rows of the export. Use a temp folder for `posturi-private/`.
  - The list row of the API has every field of the `/posturi.json` row, with the same values.
- Update `llms_test.php` for the new links.
- `compat_test.php`: add the four `/api/v1/` routes to the routes that it runs against the old exports.
- Run every PHP suite. Run the Playwright specs that cover the footer and `/despre/`, and add one check that `/api/` renders at 375 px with no sideways scroll.

## Out of scope

- Tokens, API keys and counting requests on the host.
- An endpoint for employers or for the archive. Write them in the backlog as API-02 if you see the need.
- Changes to the old feeds.
- Changes to the export, `export-to-sqlite.py` or the VPS.

## Stop and ask

- When a step needs a change to `export-to-sqlite.py`, `deploy-php.sh` or a file outside this brief.
- When the download build is above the limits of step 3.
- When a field might be personal data beyond what `/posturi.json` already gives (the contact email and phone of the institution are already public).

## Done when

- The four routes work locally against a fresh export, and every PHP suite passes.
- `/api/` renders, and the footer, `/despre/`, `llms.txt`, the sitemap and the README link it.
- The activity log has an entry with the measured numbers (the download build time, memory and size, and the time of the list and posting answers from `Server-Timing`).
- The backlog item API-01 is `[x]` for the code, and a follow-up item names the two owner steps: the deploy and the Cloudflare rule.
- Commit the work on main in one or more commits. Do not deploy.
