# 0004 — Landing page route

Date: 2026-11-16. Status: accepted (owner, 2026-11-16). Applies to UX-05 (landing page) and to every list URL.

## Context

Until now `/` renders the job list (`webapp-php/pages/list.php`). The list adds shortcuts and two short lists when no filter is set. The owner wants a landing page with an explainer, the domains, starting searches, statistics and the follow features.

Every list URL has the form `/?…`. Saved searches (ADR 0001), email subscriptions (ADR 0003), feed URLs and shared links depend on that form. The HTML is cached at the edge for one hour, so the server cannot choose a page for each reader.

## Decision

1. **`/` with an empty query string renders the landing page** (`pages/home.php`).
2. **`/` with one or more parameters renders the list**, as before. This includes `/?sort=deadline`, `/?status=active` and `/?page=2`. The rule is the rule that `list.php` already uses for the home lists: a parameter means that the reader wants a list.
3. **`/posturi/` renders the unfiltered list.** It is the URL of the empty search key.
4. **`/posturi/?…` redirects (301) to `/?…`.** Each filtered list keeps one URL.
5. **An HTMX request always renders the list fragment.** HTMX adds the `org_htmx_cache-buster` parameter to each request, so the query string is not empty. When the request has no other parameter, the response tells HTMX to push `/posturi/` and not `/`.
6. Parameter names, the search key and the feed URLs do not change.

## Consequences

- A saved search, an email subscription, a feed URL and a shared link continue to work. No stored key needs a migration.
- "Șterge tot" and each link to "all postings" point to `/posturi/`.
- The canonical URL of the landing page is `/`. The canonical URL of the unfiltered list is `/posturi/`. The sitemap gets `/posturi/`.
- The landing shortcuts and the lists "Noi azi" and "Se închid în 3 zile" move from `list.php` to `home.php`. The list page loses its `$is_unfiltered` extras.
- The tests that expect shortcuts on `/` change: the `discovery` suite and spec, and each spec that opens `/` to get the list.
- A reader who reloads the page after they cleared all filters stays on the list, because the URL is `/posturi/`.
- Alternative not taken: move the list to `/posturi/?…`. Cost: new URLs for each saved search, subscription and feed; a migration of the stored keys; redirects for an unknown time.
- Alternative not taken: keep one page and put the landing sections above the list. Cost: a long page, and the list starts below the first screen for each reader who has no filter.

## Redirect to the default saved search

`inc/header.php` has a script (UX-15) that runs on the first visit to bare `/` in a browser session. The script sends the reader to the default saved search. With a landing page on `/`, the script hides the landing page from each reader who has a saved search.

**Decided (owner, 2026-11-16): remove the redirect.** The landing page gets no saved-searches row. A saved search stays one click away, on `/salvate/`. Cost: the reader needs one more click to open the search. Cost of the alternative (keep the redirect): a returning reader does not see the landing page, and the logo link behaves differently on the first click of a session.
