# Brief — UX-15 Saved searches in the browser

Owner: Sonnet builds; Opus reviews. Date: 2026-11-02. Baseline: `7cbe836`.
Identity rule: [ADR 0001](../decisions/0001-saved-search-identity.md). Read it first.

## Goal

The reader saves a search: the filters and the text query of the list page. The saved searches stay in the browser. When the reader comes back to `/`, the site opens their default saved search, once per browser session.

The default search is the search with the newest `savedAt`. The store has a `defaultId` field for a later "set as default" control. Do not build that control now.

## Constraints

- HTML pages are cached publicly (`s-maxage=3600`). The server must not know about the reader. All state is in `localStorage` and `sessionStorage`.
- JS has no build step. Add a plain file in `webapp-php/static/` and load it like `saved.js`.
- Use the role tokens and the button classes that exist. Do not add raw palette classes or hex values (CLAUDE.md).
- Do not add markup inside a result row. `tests/deadline_test.php` checks a 3000-byte window per row.
- Romanian UI copy: short sentences, active voice, one term per concept. The term is **„căutare salvată”**.
- Do not change `posturi.preferences.v1` or `saved.js` behaviour. A fault in the new store must not damage saved jobs.

## Work

### 1. Identity — `search_key()` and `searchKey()`

- Add `search_key(array $params): string` to `webapp-php/helpers.php`, next to `feed_url()`. Implement ADR 0001.
- Add `webapp-php/tests/fixtures/search_keys.json`: at least 12 cases. Include reordered params, `judet=` vs `judet[]=`, duplicate values, `page`/`sort`/`title`, `q=`, `status=active`, `status=all`, diacritics and spaces in `q`, and an all-empty URL (key `""`).
- `searchKey(search)` in `searches.js` takes a `location.search` string and returns the same key.

### 2. Store — `webapp-php/static/searches.js` (new)

- Key: `posturi.searches.v1`. Shape:
  `{version:1, defaultId:null, searches:{<key>:{key, qs, sort, label, savedAt, openedAt}}}`.
  `qs` equals `key`. `sort` is `''` or a valid sort value. Times are ISO strings. `openedAt` may be null.
- Copy the defensive pattern of `saved.js` (`readStore` `:117`, verified write `:148`):
  - Validate each record. Drop invalid records on read.
  - When the stored value is corrupt or has another version, do not write over it. Show the feature as unavailable.
  - Read the value back after each write.
- Limit: 50 searches. At the limit, the save button shows the message „Ai deja 50 de căutări salvate. Șterge una din Salvate → Căutări.”
- Expose a small API on `window.PosturiSearches` (`list`, `get`, `save`, `remove`, `rename`, `defaultSearch`, `markOpened`) for `saved.php` and tests.
- Load the file from `inc/header.php` after `saved.js`: `defer`, `?v=filemtime`.

### 3. Redirect on return — `inc/header.php`

Add one inline script in `<head>`, after the `posturi.lastVisit` snippet. Keep it small and in a `try`.

- Run only if `location.pathname === '/'`, `location.search === ''` and `sessionStorage['posturi.searchRedirect']` is not set.
- Always set `sessionStorage['posturi.searchRedirect'] = '1'` when the path is `/`, even with no saved search. A search saved later in the session must not move the reader away from the page that they are on.
- Read `posturi.searches.v1`. Find the default search: `defaultId` if that record exists, else the newest `savedAt`.
- If a default search exists: set `sessionStorage['posturi.searchApplied'] = key`, then call `location.replace('/?' + qs + (sort ? '&sort=' + encodeURIComponent(sort) : ''))`.
- Before paint, hide the page while the redirect runs (for example `document.documentElement.style.visibility='hidden'`), so the home page does not flash.

### 4. List page — `pages/list.php`

- **Toggle.** Put a „Salvează căutarea” button next to the „Urmărește această căutare” disclosure (`#urmareste`, about `:860`). It is outside `#results`, so HTMX does not rebuild it.
  - Use the bookmark icon `#i-bookmark` and the `.pref-btn` look, with a text label.
  - Pressed (`aria-pressed="true"`, label „Căutare salvată”) when the current key is saved. A click toggles it.
  - Hidden when the key is `""` (no filters) and when JS or storage is not available. Render it with `hidden`; JS shows it.
  - Update the button from `syncFeedLinks()` (`:1293`), which runs after each HTMX push, history restore and `popstate`.
- **Label.** Build the default label from the active-filter chips: the text of each chip's `.font-medium` span, joined with „ · ”, cut at 80 characters. Add a `data-active-chips` attribute to the chip container in `partials/result_list.php:42` and read only that container. The zero-result „relaxation” chips also carry `data-chip-param`. When no chip text exists, use „Căutare din {zi lună}”.
- **Notice.** When `sessionStorage['posturi.searchApplied']` equals the current key, show a notice above the results:
  „Afișăm căutarea ta salvată «{label}». [Vezi toate posturile](/)”.
  Use the `info` token triple. Remove `posturi.searchApplied` when the reader changes a filter, so the notice goes away. Call `markOpened(key)`.

### 5. Saved page — `pages/saved.php` and `saved.js`/`searches.js`

- Add a fourth tab, **Căutări** (`?vedere=cautari`), with a count. Follow the pattern of the tabs that exist.
- Each row shows:
  - The label, as a link to `/?{qs}` (plus `sort`).
  - „salvată {dată}”, and „implicită” on the default search.
  - Buttons „Redenumește” (inline input; Enter saves, Esc cancels) and „Șterge”.
  - An „RSS” link to `/posturi.rss?{qs}`.
- Empty state: „Nu ai căutări salvate. Filtrează lista și apasă «Salvează căutarea».”
- Add „Șterge căutările salvate” to the „Gestionare” buttons. Use the in-page confirmation pattern that the page already uses. Do not use `confirm()`.
- Update the info box: say that saved searches stay in this browser too.

### 6. Tests

- `webapp-php/tests/searches_test.php`: `search_key()` against every case in the fixture.
- `webapp-php/tests/browser/searches.spec.js` (Playwright):
  - The keys from `searchKey()` match the fixture.
  - Filter by two județe, then save: the button shows pressed. A reload keeps it pressed.
  - Change the order of the parameters by hand: the button still shows pressed.
  - In a new page with the same storage and a fresh session, open `/`: the URL becomes the saved search and the notice shows.
  - In the same session, open `/` again: the home page shows and the URL stays `/`.
  - On `/salvate/?vedere=cautari`: rename, open and delete work.
  - A corrupt value in `posturi.searches.v1`: no error, the button stays hidden, and the value is not overwritten. Saved jobs still work.
- Run the suites that exist: `request_test`, `feeds_test`, `preferences_test`, `deadline_test`, `discovery_test`, and the browser `saved.spec.js` and `feeds.spec.js`. Add `searches_test.php` to the `php-tests` job in `.github/workflows/ci.yml`.

## Files you may change

`webapp-php/helpers.php`, `webapp-php/static/searches.js` (new), `webapp-php/inc/header.php`, `webapp-php/pages/list.php`, `webapp-php/partials/result_list.php` (only the `data-active-chips` attribute), `webapp-php/pages/saved.php`, `webapp-php/static/saved.js` (only for the new tab), `webapp-php/assets/app.css` and `webapp-php/static/app.css` (rebuilt with `npm run css`), the new test files and fixture, `.github/workflows/ci.yml`, `docs/activity-log.md` and `docs/backlog.md` (the UX-15 line).

Stop and ask before you change any other file, a token, a `.g2-` class or ADR 0001.

## Done when

- Every check in §6 passes. Report the commands and their output.
- `docs/activity-log.md` has a dated entry with **Done / Hard / Decided**. Write what you measured and what you assumed.
- The UX-15 line in `docs/backlog.md` says „Code ready 2026-11-02, not deployed”, and lists what you did not check (Safari/Firefox, screen readers).
- Do not commit. Opus reviews first.

---

## Follow-up 1 (2026-11-02, pax): one page, one count, lower caps

The first part of this brief is built and reviewed. These changes come on top of it.

### Requirements

1. **Header count.** The „Salvate” count in the navbar (`inc/header.php:140`, `[data-saved-count]`, drawn by `renderNav()` in `saved.js`) is **saved jobs + saved searches**.
   - It updates when a search is saved, renamed or deleted, in this tab and in other tabs (the `storage` event).
   - Mechanism: `searches.js` dispatches `posturi:searches-changed` on `document` after each write. `saved.js` listens and calls `renderNav()`. `renderNav()` adds `window.PosturiSearches.list().length` when the API exists, else 0.
   - If one store is blocked or corrupt, count only the other one. Never show `NaN`.
2. **One page.** `/salvate/` (the default view) shows two sections, in this order:
   - „Căutări salvate”: the rows that the Căutări tab shows today.
   - „Anunțuri salvate”: the saved-job list that exists.
   - Each section has an `h2` and its own empty-state text.
   - Remove the „Căutări” tab. `?vedere=cautari` (old links) shows the default view.
   - The „Notițe” and „Ascunse” tabs do not change.
   - The „Salvate” tab count counts saved jobs only. The section headings show their own counts.
3. **Cap: 4 saved searches.** Change the limit in `searches.js` from 50 to 4.
   - At the limit, a new save is refused. The store does not change.
   - Show the message with `showNotice` from `saved.js` (the `#pref-notice` pattern), not with `[data-search-msg]`. Expose it as `window.PosturiSaved.showNotice` (or extend an object that already exists).
   - Text: „Ai atins limita de 4 căutări salvate. Șterge una din Salvate, apoi încearcă din nou.”
   - Delete and rename stay possible at the limit.
4. **Cap: 20 saved jobs.** Add `SAVE_MAX = 20` in `saved.js`. Keep `MAX = 500`: it is the cap on all stored records (saved, hidden, noted).
   - Setting `savedAt` is refused when 20 records already have `savedAt`. This applies to a new record and also to a hidden or noted record that is saved later.
   - Hide, note and unsave are never blocked by `SAVE_MAX`.
   - Text: „Ai atins limita de 20 de anunțuri salvate. Elimină câteva din Salvate, apoi încearcă din nou.” Keep the `MAX` message for the 500-record case.
   - **Existing readers above the cap:** UX-09 is live with a cap of 500. Never delete or trim records that already exist. A reader with more than 20 saved jobs keeps them all and can only unsave until they are below 20. Test this case.
5. **Info box** on `/salvate/`: state both limits in one short sentence.

### Tests
- Update `tests/browser/saved.spec.js`:
  - The 500-record test stays for `MAX`.
  - Add: the 21st save is refused with the notice, and nothing is evicted.
  - Add: a stored set with 25 saved jobs loads and shows all 25; a save is refused; an unsave works.
  - Add: hide still works at 20 saved.
  - Adjust every test that assumed the „Căutări” tab or the old count.
- Update `tests/browser/searches.spec.js`: the 5th save is refused with `#pref-notice`; the header count is jobs + searches and changes after a save and a delete of a search; `?vedere=cautari` shows the one-page view with Căutări first.
- Run the PHP suites (`searches`, `preferences`, `request`, `feeds`, `deadline`, `discovery`) and `searches.spec.js`, `saved.spec.js`, `feeds.spec.js`.
- The 320/375 px overflow failures that exist on the baseline (BUG-MOBILE-OVERFLOW) are not in scope. Report the failure set and compare it with the 20 known failures.

### Files you may change
`webapp-php/static/saved.js`, `webapp-php/static/searches.js`, `webapp-php/pages/saved.php`, `webapp-php/static/app.css` (rebuilt only), `webapp-php/tests/browser/saved.spec.js`, `webapp-php/tests/browser/searches.spec.js`, `docs/activity-log.md` (add to the UX-15 entry of 2026-11-02, a „Follow-up 1” paragraph), `docs/backlog.md` (UX-15 line).
`inc/header.php` only if the count markup must change. Stop and ask for any other file.
