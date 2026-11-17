# Brief — Footer details and `/llms.txt`

Owner: Sonnet builds; Opus reviews. Date: 2026-11-17. Baseline: branch `ux-05-landing-page` with its uncommitted files. Do not revert them. `webapp-php/inc/footer.php` has a change of today in its last paragraph (inline blocks); keep it.

Read first: `docs/decisions/0005-gov2-ds-only.md`, `docs/decisions/0006-icon-set.md`, `webapp-php/inc/footer.php`, `webapp-php/pages/robots.php`, `webapp-php/pages/sitemap.php`, `webapp-php/inc/themes.php`.

## Work

### 1. Footer link lists — `inc/footer.php`

1. Reduce the vertical distance between the links of a group. Now each link is a 44 px box, so the rows are 44 px apart. New: each link target is at least 24 px high, two targets do not touch (WCAG 2.5.8), and the rows are about 28 to 32 px apart. Report the row distance before and after.
2. The link „Cod sursă” gets the GitHub mark before its text: Tabler `brand-github` (`@tabler/icons` 3.52.0, outline, not modified), as a symbol in the sprite of `inc/header.php`, with its source in `webapp-php/assets/icons/tabler/`. The icon is decoration (`aria-hidden`), in the text colour, about 18 px. The other links get no icon and no gap.
3. `tests/browser/home.spec.js` has a check that each footer link is at least 44 px high. Change it: the links of the footer lists are at least 24 px high and do not overlap. Do not weaken the check for the other links.

### 2. `/llms.txt` — new `pages/llms.php`, route in `index.php`

A plain-text Markdown file for language models, in the form of llmstxt.org: one `# ` title, one `> ` summary, then `## ` sections with link lists (`- [name](absolute URL): note`).

- Served at `/llms.txt`, `Content-Type: text/plain; charset=utf-8`, with a cache header like `robots.txt`. Absolute URLs from the same base-URL helper that the sitemap uses.
- Language: Romanian, short sentences. Content, built from the code so that it cannot go stale:
  1. Title „posturi.gov2.ro”. Summary: what the site is (the job postings of the Romanian public sector, taken from posturi.gov.ro, structured and searchable), that it is an independent project and not an official site of the Government of Romania, and that the official posting is the authority.
  2. „Pagini”: the landing page, the list `/posturi/`, each themed page (`themes()`), `/statistici/`, `/angajatori/`, `/despre/`, `/pot-aplica/`.
  3. „Date și fluxuri”: the RSS feed, the calendar feed, the sitemap. Add the Atom feed if a route exists.
  4. „Căutare prin URL”: how a search is a URL: `/?q=…` and the main filter parameters with one example each. Take the parameter names from the code (`build_filters()`); do not invent one. At most 10 parameters.
  5. „Despre date”: the date of the database (the build stamp), the number of active postings, the source, and that the salary figures are estimates where the page says so.
  6. „Cod sursă”: the GitHub repository.
- `robots.txt`: do not change its rules. Add no line unless a convention for `llms.txt` exists there (none does; so no change).
- Add `/llms.txt` to the route list in `README.md`.

## Tests

- PHP (`request_test.php` or a new `llms_test.php`; add a new suite to `.github/workflows/ci.yml`): status 200 and the content type; the first line is the `# ` title; a `> ` summary exists; each link is an absolute URL of the site base or the GitHub repository; each internal link returns 200 or a redirect on the fixture; each themed page is listed; each parameter named in „Căutare prin URL” is a key that `build_filters()` reads; the number of active postings equals the SQL count.
- Playwright: the footer row distance and the 24 px rule at 320 and 1280 px; the GitHub icon is before „Cod sursă”, `aria-hidden`, and it renders (non-empty box).
- Each new check must fail against a named mutation.

## Files you may change

`webapp-php/inc/footer.php`, `webapp-php/inc/header.php` (one sprite symbol), `webapp-php/assets/icons/tabler/`, `webapp-php/pages/llms.php` (new), `webapp-php/index.php` (one route), `webapp-php/assets/app.css` and the built `webapp-php/static/app.css`, `webapp-php/tests/**`, `.github/workflows/ci.yml`, `README.md`, `docs/backlog.md`, `docs/activity-log.md`.

Stop and ask before you change any other file.

## Done when

- All checks pass, and the report gives each command and its result, what you measured and what you assumed, and the full text of `/llms.txt` on real data.
- `docs/activity-log.md` has an entry with **Done / Hard / Decided**.
- Do not commit. Do not deploy. Opus reviews first.
