# 0001 — Saved-search identity

Date: 2026-11-02. Status: accepted. Applies to UX-15 (browser-local saved searches) and UX-14 (email alerts).

## Context

A saved search is a list URL that the reader keeps. The same set of postings can have many URLs, because:

- the parameter order is different;
- the URL carries `page`, `sort` or `title`;
- the URL carries empty values (`q=`), or the default `status=active`.

A browser-local saved search and a later email subscription must agree on whether two URLs are the same search. Without one rule, the "Salvează căutarea" toggle shows the wrong state. Without one rule, an email subscription duplicates a saved search.

## Decision

The **search key** is the query string that remains after these steps, in this order:

1. Remove `page`, `sort` and `title`. These change how the reader sees the list, or what a calendar is called. They do not change which postings match. This is the rule of `feed_url()` in `webapp-php/helpers.php`.
2. Remove each value that is empty after trimming, also inside a multi-value parameter. Then remove each parameter that has no values left. (`clean_query()` does the same.)
3. Remove `status` when its value is `DEFAULT_STATUS` (`active`). This is the rule of `clean_query()`.
4. Write a multi-value parameter as `name[]`, whether the URL wrote `name=` or `name[]=`.
5. Sort the values of each multi-value parameter as strings. Remove duplicate values.
6. Sort the parameters by name (byte order).
7. Encode with `encodeURIComponent` semantics: a space becomes `%20`, and `[]` becomes `%5B%5D`.

The empty key (no filters) is not a search. The reader cannot save it.

`search_key()` in `webapp-php/helpers.php` and `searchKey()` in `webapp-php/static/searches.js` implement this rule. The fixture `webapp-php/tests/fixtures/search_keys.json` lists input URLs and their keys. Both test suites read it.

## Consequences

- A search keeps its `sort` value next to the key, not in the key. Opening a saved search restores its sort.
- When a new facet parameter is added, the key rule needs no change.
- Renaming a parameter breaks the saved searches that use it. A rename needs an alias in `validated_query()` and a migration of the stored keys.
- Alternative not taken: keep the raw URL as the identity. Cost: duplicate saves, and the toggle shows "not saved" after a reordering by HTMX.
