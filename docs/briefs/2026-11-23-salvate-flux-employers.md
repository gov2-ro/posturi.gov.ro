# Brief — Followed employers, employer email alerts, and the „Flux” tab on /salvate/

Date: 2026-11-23. Author: Opus. Builder: Sonnet. Backlog: UX-FOLLOW-EMP, UX-SALVATE-FLUX.
Builds on: UX-15 (saved searches, [ADR 0001](../decisions/0001-saved-search-identity.md)), UX-14 (email alerts, [ADR 0003](../decisions/0003-email-alerts-architecture.md)), UX-09 (/salvate/).

## Goal

1. A reader can follow an employer from `/angajator/{slug}/`.
2. A reader can ask for an email alert for that employer.
3. `/salvate/` opens on a new tab „Flux”: one list of open postings from all the reader's sources, with stats above and filter chips by source. The page that exists today becomes the tab „Liste”.

## Decisions (pax, 2026-11-23)

- **Followed employers are a separate browser list**, not a saved search. They do not use the 4 search slots.
- **Flux shows open postings only**, from all sources, without duplicates.
- **Stats:** counts, per source, by county and domain, and the salary range. **Hide a stat block when it tells nothing** (for example, the county block when all postings are in one county).
- **Tabs:** `Flux · Liste · Ascunse`. Flux is the default.

## What exists (read these first)

- `?employer=<slug>` is a list filter (`build_filters()`, `helpers.php` ~2463), a feed scope (`feed_employer()`), and a valid search key: `search_key(['employer'=>'x'])` returns `employer=x`.
- Alerts: `inc/alerts.php` (`alerts_validate_key()` ~213 checks `search_key()` and `validated_query()`), `pages/alerts_subscribe.php`, `ops/alerts.py`, `bin/alerts.php`. The digest asks the host for matches by search key, so an employer key needs no new matching code.
- Browser state: `static/searches.js` (`posturi.searches.v1`, cap 4), `static/saved.js` (saved, hidden, notes; `/preferinte-posturi.json`), `pages/saved.php`.
- Employer page: `pages/employer.php` (already links the RSS/Atom/JSON feeds for `?employer=<slug>`).

## Part A — Follow an employer

1. Add a browser store `posturi.employers.v1`: a list of `{slug, name, addedAt}`. Cap: **10**. Put it in a new file `static/employers.js`, in the style of `searches.js`.
2. On `/angajator/{slug}/`, add a toggle button „Urmărește angajatorul” / „Nu mai urmări”, near the feed links. Use the same button classes as the save toggle in lists. The button needs JavaScript; without it, hide it.
3. Next to the toggle, add a link „Alertă pe email”. It opens the existing subscribe form with the key `employer=<slug>` and the label = the employer name. Use the same URL shape that the „Alertă pe email” link on a saved search uses today.
4. When the cap is full, the toggle shows a short message and a link to `/salvate/?vedere=liste`. It does not drop an old entry.
5. The navbar count (jobs + searches) also adds the followed employers.

### Alerts (Part A, server)

- Confirm that `alerts_validate_key('employer=<slug>')` accepts a slug that exists and refuses one that does not. If it accepts an unknown slug, refuse it (an empty digest forever is worse than an error). Add the cases to `tests/alerts_test.php`.
- The digest label: use the employer name. Do not change the email template unless the employer key renders badly (check one rendered digest with the test fixture `tests/fixtures/alerts_outbox.php`).
- **No change** to ADR 0003, the subscriptions schema or `ops/alerts.py`. If a change seems necessary, stop and ask.

## Part B — /salvate/ tabs

- `/salvate/` → **Flux** (default). `/salvate/?vedere=liste` → **Liste**. `/salvate/?vedere=ascunse` → **Ascunse** (unchanged).
- **Liste** holds everything that the page holds today, in this order: the alerts block, „Căutări salvate”, a new section „Angajatori urmăriți”, „Anunțuri salvate” (with the notes view), and „Gestionare”.
- „Angajatori urmăriți” lists each employer: name (link to its page), „Nu mai urmări”, RSS, „Alertă pe email”. Add „Șterge angajatorii urmăriți” to „Gestionare”, with the same confirm pattern.
- Old links: `/salvate/` without `vedere` now opens Flux. That is intended.

## Part C — The Flux tab

### Endpoint: `/flux.json`

One request returns all rows. Do not call `/posturi.json` once for each source: it lacks salary and domain, and it would be up to 15 requests.

- Input (GET): `s[]` = search keys (max 4), `e[]` = employer slugs (max 10), `id[]` = saved posting ids (max 20). Refuse (400) anything above the caps.
- For each search key and each employer, run the **existing** filter (`build_filters()` on the parsed key, plus `employer=<slug>`). Do not write a second filter. Force open postings (the same open-status rule as the list default).
- For the saved ids, return them only when they are open.
- Output: `{rows: [...], sources: [{kind, ref, total, truncated}]}`. Each row: `id, title, url, employer, employer_slug, judet, locality, domain, salary (the same value and bucket that the list cards show), published_at, apply_deadline, src: [indices into sources]`. A posting that matches several sources appears once, with several indices.
- Cap: 100 rows per source, 300 rows in all, newest first. Set `truncated` when a source has more; the UI says „primele 100 — deschide căutarea pentru toate”.
- No personal data reaches the server except the keys and ids in the URL. Send `Cache-Control: no-store`. If `robots.php` disallows `/salvate/`, disallow `/flux.json` too.

### Page

1. On load, `static/flux.js` reads the three browser stores and calls `/flux.json` once. It removes the postings that the reader hid.
2. **Source chips** above the list: one chip per source with its count (`Salvate 3`, the search label, the employer name). The chips are toggle filters (several at once = union). „Toate” resets. A chip with 0 rows still shows, greyed, so the reader sees that the source works.
3. **Stats** above the chips, computed in the browser from the rows that the chips select. Recompute when a chip changes.
   - Counts: open postings; new in the last 3 days (`published_at`); closing in the next 7 days (`apply_deadline`).
   - By county: top 5 and „alte N”. **Hide when there is one county.**
   - By domain: top 5 and „alte N”. **Hide when there is one domain**, or when no row has a domain.
   - Salary range: min – median – max of the rows that state a salary, with „din N anunțuri”. **Hide when fewer than 3 rows state a salary.**
   - Hide a count that is 0 (for example, „se închid în 7 zile: 0”).
4. **List:** reuse the saved-item card (title, employer, place, deadline, save/hide/note buttons) and add the source tags of the row. Sort by deadline (closest first), with a sort switch „termen / cele mai noi”. Page in batches of 25, like Liste.
5. **Empty states:** no sources at all → a short text and links to „Caută anunțuri” and to the employers index. Sources but no open postings → say so, and link to Liste.
6. Without JavaScript: the same `<noscript>` text as today.

## Design rules

- Use the DS components and role tokens only (CLAUDE.md § palette, § Design system). Read `COMPONENTS.md` for chips, tabs and stat tiles before you build. If the DS lacks a stat tile, build it locally in the `app` layer and open a DS proposal issue.
- Romanian UI copy: short sentences, active voice, one term per concept („urmărește” for employers, „salvează” for postings and searches).

## Tests

- PHP: `tests/flux_test.php` — caps (400), dedupe across sources, open-only, unknown slug, an id that is closed, `truncated`. Extend `alerts_test.php` (employer key). Extend `searches_test.php` only if you touch the key rule (you should not).
- Playwright: follow/unfollow on an employer page; the cap message; the three tabs; chips filter the list and the stats; each hide rule (one county, one domain, < 3 salaries); the empty states. Widths 320, 375, 1280.
- Run the existing suites: `php webapp-php/tests/*_test.php`, the browser specs for saved, searches and alerts.

## Out of scope

- Server-side accounts or sync.
- A merge of duplicate employers (EMPLOYER-DUPLICATES). **Risk to note:** a later merge changes slugs and breaks follows and employer alerts. The merge work must publish an old-to-new slug map; this brief only records the dependency in the backlog.
- Changes to the digest frequency or the email template.

## Report back

- What you measured (row counts, response time of `/flux.json` with 4 searches + 10 employers on the local export).
- What you assumed, and every deviation from this brief.
- Backlog and activity-log entries for UX-FOLLOW-EMP and UX-SALVATE-FLUX.
