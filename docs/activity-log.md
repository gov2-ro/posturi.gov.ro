# Activity Log

## 2026

### 2026-09-09 — Live facet counts: the sidebar now updates with the results

**What was actually wrong.** The counts were already dynamic server-side —
every facet calls `facet_scope($own_key)` (`pages/list.php:150`), which rebuilds
the WHERE clause with all *other* active filters applied. The problem was
delivery: `hx-target="#results"` swaps only `partials/result_list.php` plus three
OOB spans, and the sidebar sat inside `if (!$is_htmx)`, so after ticking a
checkbox the numbers on screen were whatever the last full page load produced.

And the facet blocks (lines 184–330) run *before* the `$is_htmx` check, so every
HTMX request already computed all of them and threw them away — the partial cost
108 ms of the full page's 138 ms. Shipping the counts costs **no extra queries**.

**Change:** extracted the sidebar to `partials/facets.php`, wrapped in
`<div id="facet-list">`, and re-included it on HTMX responses with
`hx-swap-oob="true"`. Verified the OOB fragment is byte-identical to what a full
page load renders, for four different filter combinations, and that it sits at
nesting depth 0 in the response so htmx lifts it out before the main swap.

**Whole groups, not individual count spans.** The per-span idea does not work:
narrowing one filter drops values out of a sibling group's list and widening
brings them back, so there is no fixed set of spans to address — stale values
would keep their old numbers and new ones would have no target to swap into.

**Client state a swap destroys, and where it is put back** (`list.php` script):
- focus on the control just clicked — restored by `name`+`value` at
  `htmx:afterSettle`, `preventScroll` so a sticky column does not jump. Only
  when focus was inside `#facet-list`, so typing in the search box is untouched.
- `<details>` open/closed — `applyFacetState()` re-applies localStorage. The
  per-element `toggle` listener became one capture-phase listener on `document`
  (`toggle` does not bubble, and a per-element listener dies with the swap).
- scroll offset of the panel and of each `max-h-56` group.

**Paging is excluded.** The filter form carries no `page` field, so `page` in the
query string means a pagination link — same filters, same counts. Skipping it
avoids re-shipping the sidebar to redraw identical numbers.

**Cost.** No new queries; server time unchanged (102 ms vs 108 ms unfiltered,
5 ms with a filter on). Payload grew 79 KB → 200 KB raw, but `.htaccess` has
`mod_deflate` on `text/html`: **10.3 KB gzipped unfiltered, 6.0 KB filtered.**

Profiled the count queries at both scales — 16 facet queries, warm:

| | 1,848 active rows (51 MB) | 9,756 rows, full archive (174 MB) |
|---|---|---|
| all facet queries | 72 ms | 183 ms |

Sub-linear at 5.3× the data. The one line item that scales badly is
`v3_facet()` (`list.php:242`) — six queries that read every matching row's JSON
column and tally in PHP, 110 of those 183 ms. If the corpus grows 10×, replace
it with a normalised `(posting_id, kind, value)` table and a real `GROUP BY`.

**Not changed:** a group still excludes its own selection from its counts. Drop
that and picking IT shows every other domeniu as `0`, so a second value can never
be added to an OR group. Those numbers read as "how many this would *add*".

**Verification gap:** the Chrome connection was unavailable this session, so the
focus/scroll/`<details>` restoration is reviewed and reasoned about but not
click-tested in a browser.

---

### 2026-09-09 — Two facet bugs: `IT` absorbed every failed LLM classification, EQF counted one thing and filtered another

Both found from the UI: selecting **Domeniu = IT** listed îngrijitoare and
consilieri școlari, and **Nivel studii (EQF) = Doctorat**, labelled `12`,
returned 1,437 rows headed by *Conducător auto*.

**Bug 1 — `IT` was the dumping ground for unparseable LLM answers.**
`_llm_classify`'s response validator fell back to a substring test:

```python
norm = _normalize(raw.split()[0] if raw else "")   # empty answer -> ""
for fam in PROFESSION_FAMILIES:
    if _normalize(fam) in norm or norm in _normalize(fam):   # "" in "it" -> True
        return fam
```

`PROFESSION_FAMILIES` is `sorted(FAMILIES.keys())` and `"IT"` is uppercase, so
it sorts first — every empty answer landed there. The same test also matched the
letters `it` inside a word, so `sanitar` and `ingrijitoare` would have gone to IT
too. Confirmed against the API: `deepseek-v4-flash` with reasoning on returns
`''` for this prompt (it is the pre-`f403acf` state that wrote these rows), and
`altele` with `thinking: disabled`. 57 of 120 Postgres `IT` rows (50 of 60 active)
were llm-sourced junk.

`quality_check.py::_llm_classify` had the same validator **plus** no `deepseek`
branch at all and no `else: raise` — so under the configured default provider it
left `raw = ""` and silently classified everything as IT.

**Fix:** new `_match_family()` in both files — empty/unrecognised is `altele`,
never a family; exact match first, then a whole-word scan of the answer (longest
family name first, so `ordine publică` beats a stray `ordine` and `IT` cannot
match inside `sanitar`), then a ≥4-char prefix match for an answer truncated at
`max_tokens`. `quality_check.py` gained the deepseek branch (thinking disabled,
matching `infer_postings.py`) and raises on an unknown provider.

**Repair:** new `infer_postings --requeue-llm-family FAMILY` flag re-runs only the
postings the LLM put in one family — repairing a bad batch without paying for a
full `--force` pass over 9,756 postings. `--requeue-llm-family IT --provider
deepseek`: 57 updated, 57 LLM calls, 0 errors. Active `IT` 60 → 11 (10 dictionary
hits plus one *Economist specialist IA (cu atributii de administrator)*); the
other 49 redistributed to sănătate, administrație, educație, social. Re-exported
to `webapp-php/posturi.sqlite`.

**Bug 2 — EQF facet counts and filter answered different questions.**
`build_filters()` filtered `v3_eqf_level <= ?` on the "a candidate above the
minimum still qualifies" reading, while the facet counts in `pages/list.php` are
a plain `GROUP BY v3_eqf_level` — exact per level. Clicking *Licență 587* gave
1,411 rows; *Doctorat 12* gave 1,437, i.e. every posting with any EQF level.

**Fix:** exact match, `v3_eqf_level IN (…)`, like every other sidebar facet, and
`eqf` added to `MULTI_PARAMS` so it multi-selects (`eqf[]`) instead of collapsing
to a single scalar. `(array)` in `pages/list.php` keeps detail.php's legacy
scalar `/?eqf=6` links working. Verified: Doctorat → 12 (all *Cercetător
științific*), Master → 14, both → 26. The "everything I qualify for" semantics
belongs to CV matching, not to a browse facet; if it comes back it needs
cumulative counts and labels that say "cel mult".

**Tests:** `tests/test_infer_llm.py::TestResponseValidation` — empty/whitespace/
`i`/`???` → `altele`, `sanitar`/`ingrijitoare` → `altele`, and the recognised
shapes (`**IT**`, `Sănătate.`, `Îngrijitoare → sănătate`, truncated `ordine`).
331 webapp tests pass; `check-skins.php` clean.

**Outcome:** `export-to-sqlite.py --active-only` rebuilt `posturi.sqlite` at 1,848
active postings. Domeniu facet now reads sănătate 511, tehnic 499, administrație
382, altele 240, social 84, financiar 59, educație 34, cultură 21, IT 11, ordine
publică 6, juridic 1.

**Follow-ups filed:** the FAMILIES dict is whole-word only, so `îngrijitoare`,
`infirmiere` and `asistenti` never match their masculine-singular keywords and
fall through to the paid LLM on every run — that is *why* these titles reached
the broken matcher at all. And `quality_check.py` duplicates the entire inference
layer from `infer_postings.py`; this is the fifth fix that had to be applied
twice, and the `textutil` one was missed outright.

---

### 2026-09-09 — DeepSeek schema step returned empty content: reasoning ate the token budget

**Symptom:** `pipeline.py --active-only --since 7` finished the schema step with
`9 ok, 154 failed`, every failure `ValueError: Expected dict, got str: ''`. Recent
postings on the live site kept `schema_json IS NULL`.

**Cause:** `.env` selects `LLM_PROVIDER=deepseek` / `LLM_MODEL=deepseek-v4-flash`.
The `deepseek-v4-*` models reason by default. Probed against the API: on a real
posting they spend 1,400–2,600 completion tokens on hidden reasoning before
emitting any JSON, so the `max_tokens=2000` cap in `llm-schema.py`'s deepseek
branch is hit mid-think (`finish_reason: length`) and `message.content` comes back
`''`. `_validate_extraction('')` then raises `Expected dict, got str: ''`
(`llm-schema.py:279`). The 9 successes were short postings that fit under 2000.
Raising `max_tokens` does not help — the model just reasons longer to fill it.

**Fix:** pass `extra_body={"thinking": {"type": "disabled"}}` on the DeepSeek
calls. Field extraction needs no chain-of-thought; disabled, the same posting
returns valid JSON in ~300 completion tokens and validates against the v2 model.
Applied in two places:
- `llm-schema.py` deepseek branch (the schema step — the actual failure).
- `infer_postings.py::_llm_classify` deepseek branch (uncommitted WIP; its
  `max_tokens=20` could not fit a single reasoning token, so it would have failed
  100% the same way).

Re-run: `python pipeline.py --steps schema --active-only --resume --workers 8`.
61 LLM tests pass.

**Outcome:** the re-run completed clean. Active postings with `schema_json` in
Postgres went 359 → 1,812 of 1,848. Note the schema step writes Postgres only —
the PHP site kept showing "nu există încă o versiune structurată" until
`export-to-sqlite.py --active-only` rebuilt `webapp-php/posturi.sqlite`; the
`--steps schema` invocation does not include the export.

---

### 2026-09-09 — `fetch-anunturi.py` skips cancelled competitions

**What:** `process_csv()` now drops any index row whose `expira_in` contains
`anulat` before building the fetch list, via a new `is_cancelled()` helper.

**Why:** when posturi.gov.ro withdraws a competition it swaps the pretty
`/joburi/{slug}/` card link for the bare `/?post_type=pg_job&p=N` permalink and
writes `Anunț anulat` in the expiry slot. All 80 such rows in the CSV are
cancelled (the correlation is exact — see 2026-09-08 "Recovered the 80 lost
postings"). 78 were cached back then; 2 (`p=8208`, `p=8915`) were already 404 and
still are. With no cache file they were treated as new on every run and burned
~35 s each on 3 retries with 5/10/20 s backoff. Nothing was lost by skipping:
`import_csvs.py` sets `JobPosting.cancelled` from the same `"anulat"` marker and
never needed the detail page, and `--active-only` already excludes cancelled rows
from the export.

**Scope:** one guard in the first loop of `process_csv()` is enough — the second
loop only iterates the already-filtered `new_rows`. Added
`test_fetch_anunturi_skips_the_romanian_marker` in `webapp/tests/test_posting_urls.py`
alongside the existing `import_csvs` source assertion; 20 pass.

---

### 2026-09-09 — `infer_postings` supports deepseek, so pipeline.py runs end-to-end

`python pipeline.py` died at the infer step with argparse `invalid choice:
'deepseek'`. The `.env` sets `LLM_PROVIDER=deepseek`, and `pipeline.py` accepted
and forwarded it (deepseek had been added to `_PIPELINE_PROVIDERS` in 23c0c94),
but the management command's `--provider` only allowed gemini/openai/anthropic
and its `_llm_classify()` had no deepseek branch — the one dispatch site missed
in the May sweep that wired deepseek into `llm-schema.py`.

**Fix:** added deepseek to the argparse choices and a deepseek branch to
`_llm_classify()` mirroring the openai branch — OpenAI SDK,
`DEEPSEEK_API_KEY`, `base_url="https://api.deepseek.com"`, model
`deepseek-v4-flash` (hardcoded, matching the file's per-branch idiom and
`models_config.json` defaults). Also added an else-raise so an unknown provider
fails loudly instead of silently classifying `altele` — the bug class
`quality_check.py::_llm_classify` already has. The stale "infer only implements
gemini/openai/anthropic" comment in `pipeline.py` was removed.

**Decisions:** deferred deepseek support in `quality_check.py::_llm_classify`
(CLI accepts it but silently falls back to `altele`) and `infer_conditions_llm.py`
(same hardcoded choices) to the backlog — neither is on the pipeline.py path.

**Verified:** live smoke call classified "Informatician grad I" → `IT`;
`pipeline.py --steps infer --provider deepseek --limit 5 --force` completes
cleanly; 2 new tests in `webapp/tests/test_infer_llm.py`, full suite 313 pass.

---

### 2026-09-08 — `build_meta` surfaced: two timestamps, told apart

The export now records its own provenance, so the site could stop implying that one date
answers two questions. `MAX(last_seen_at)` is when the source was last scraped;
`built_at` is when the file being served was generated. They coincide on a healthy run,
and the gap between them is exactly what a partial failure looks like.

**The visible stamp did not change meaning.** "actualizat 08.09.2026" in the header and
"Date actualizate la…" in the footer still report the scrape date, because that is the
question a visitor is asking. Both became `<time datetime>` elements carrying a title
with the build behind them — generated at, active postings, commit. Swapping them to
`built_at` would have been the wrong fix: an export can run when a scrape did not, and
the stamp would then overstate freshness.

**`/despre/` is where the detail belongs.** A "Versiunea acestor date" block lists the
build time in Romanian local time (`built_at` is stored UTC — 21:46 in Bucharest must not
read as 18:46 to someone checking how fresh the site is), the three row counts, and the
commit linked to GitHub. Followed by a sentence explaining the two timestamps, since a
reader who notices them differing deserves the reason rather than a support ticket.

**`source_host` is recorded but never rendered.** It names infrastructure; it exists for
the deploy to verify on the remote that the file that landed is the file it built.

`build_meta()` in `helpers.php` returns null for an export predating the table — which is
what is on the live site right now — and every consumer renders without it: no tooltip, no
`cursor-help`, and a plain sentence in place of the block. Verified by serving a copy with
the table dropped: all routes 200, no notices.

Files: `webapp-php/helpers.php` (`build_meta()`, `build_time()`, `build_tooltip()`),
`inc/header.php`, `inc/footer.php`, `pages/about.php`, rebuilt `static/app.css`.

### 2026-09-08 — Continuous deployment: twice-daily pipeline on a VPS, and the guards that make it safe to leave alone

Answered the open "Daily fetch cron — design" backlog item, then built it.

**GitHub Actions was the first question, and the answer is no — for the pipeline.** It
carries ~2.4 GB of persistent state (`data/anunturi` 169 MB of cached HTML, `data/downloads`
2.1 GB of attachments) plus a PostgreSQL database that has to survive between runs. On
ephemeral runners every run would restore and re-save all of it against a 10 GB cache quota
with 7-day eviction. Secrets, cost and the 6h job limit were never the obstacle; the state is.
The VPS already holds it. Actions was declined for CI too, by choice.

**Topology.** The VPS owns Postgres, the scrape cache and the API keys and runs the pipeline
at 11:45 and 18:33 Europe/Bucharest; the shared host owns the PHP tree and one read-only
SQLite file. Code deploys stay manual from the Mac. That last decision is what forced
`deploy-php.sh` to split: a cron pushing the whole directory would silently revert templates
pushed from the Mac, using the VPS's older checkout. `--code-only` excludes `*.sqlite*`,
`--data-only` sends nothing else.

**`--active-only` is the cost guard, not `--resume`.** The plan had this wrong. `iter_postings`
already skips rows with a non-null `schema_json`, so a re-run does not re-extract what is done
— `--resume` is the finer per-variant filter. The actual trap is scope: 6,904 postings have no
`schema_json` and only **15** of them are active. A run without `--active-only` would pay for
LLM extraction on ~6,900 expired postings that can never reach the export. `llm-schema.py`'s
flag defaults were left alone once that was clear.

**`--prompt-version v3` is pinned in the cron.** `models_config.json` still defaults to v2, and
a v2 extraction lands with every `v3_*` facet column empty — the site's filters read those.

**The export stopped being able to publish an empty site.** `create_schema()` DROPs every table
before writing, so a crash used to leave the deploy source gutted and ready to ship. It now
builds `<out>.tmp` and promotes with `os.replace()` only after `integrity_check`, a `--min-rows`
floor (default 100), and a refusal to lose half the rows against the file it would replace —
compared only against a baseline built the same way, since an `--active-only` file is
legitimately a fifth of a full one. A rejected build is deleted rather than left as 50 MB of
nothing. This is the check that was missing when the live site served three active postings for
five weeks.

**WAL was actively dangerous on a read-only replica.** The export set `journal_mode=WAL` and
`db.php` set it again. Two failures follow: PHP opening a WAL database has to create `-shm`/`-wal`
beside it, and a shared host's document root may not be writable; and a `-wal` surviving an rsync
swap describes a database that is no longer there, which SQLite reports as *database disk image is
malformed*. Now `journal_mode=DELETE` in the export, `PRAGMA query_only=1` in `db.php` so the
read-only intent is enforced rather than assumed, and the deploy removes the remote sidecars once.
Every route was re-checked under `query_only` — list, detail, employers, stats, sitemap and all
three feeds return 200 and nothing creates a sidecar.

**`expira_in` was making every run look like a change.** The redesigned cards render a relative
countdown, so a verbatim diff logged an `expira_in` change on all ~9,600 rows twice a day: the
`updates` column growing without bound, a full 4.1 MB CSV rewrite on every page, and the
three-unchanged-pages early stop permanently defeated. `values_differ()` now treats two members
of the countdown family as equal while still recording a move to `Anunț anulat`. `Data Expirare`
comes from the detail page's `.pg-meta-deadline`, so nothing downstream loses information.

**Smaller things the unattended path needed.** A pagination floor in `fetch-index.py`, because
`get_total_pages()` reads the largest numeric link out of a *windowed* pagination and would
silently truncate the scrape if WordPress ever stopped rendering the last page; `flock` inside
`run-pipeline.sh` rather than in the unit, so a hand-run cannot overlap the timer either; a
`DEPLOY_PATH` guard, because `rsync --delete` plus an unquoted `~` that expanded against the
*local* home is one keystroke from emptying a directory; and a dead-man's-switch
`HEALTHCHECK_URL`, since a failure alert cannot tell you about a run that never happened —
which is exactly how the site went five weeks stale.

**`--continue-on-error` with a deploy anyway.** A failed `download` or `infer` step still lets
the export and deploy run — a current site matters more than a clean run, and the export floors
decide whether the data is fit to ship. The run still exits non-zero and pings `/fail`.

Files: `ops/run-pipeline.sh`, `ops/env.sh`, `ops/systemd/posturi-pipeline.{service,timer}`,
`docs/deploy-vps.md` (provisioning runbook + failure table), and changes to `fetch-index.py`,
`export-to-sqlite.py`, `deploy-php.sh`, `webapp-php/db.php`, `.env.example`, README.
28 new tests in `webapp/tests/test_index_diff.py` and `test_export_guards.py`; 310 pass.

`webapp-php/posturi.sqlite` was re-exported through the new path as the end-to-end check —
1,762 active postings, `journal_mode=delete`, `integrity_check` ok, `build_meta` stamped
`e156190`, and all ten routes served 200 against it under `query_only`. It is the first
export that carries provenance; nothing has been pushed to the shared host yet.

Not done: the VPS itself. Provisioning, seeding and the first run are the runbook's job.

### 2026-09-08 — Compact landing: filter-first two-column layout, shortcut chips, live export links

Five backlog UI items, all on the PHP webapp's list page.

**The landing was selling itself instead of being used.** An `<h1>` restating the masthead, a
"N anunțuri indexate · sursă" line repeating the footer, and four stat cards pushed the filters
— the actual tool — below the fold. The heading survives as `sr-only`, because SEO and screen
readers were the only things it was genuinely doing; the rest is gone.

**Two columns on `lg`.** Search now sits at the top of the sticky filter column rather than
spanning the page, so the sidebar reads as the primary exploration tool. The search input stays
a *single* DOM node — two inputs sharing a name would double-submit — which is why it lives in
the column wrapper rather than inside `<aside>`: on mobile there is no column, the search renders
inline at the top and the facets stay the slide-over drawer. Cleaning this up also removed a
latent conflict where the aside carried both `lg:static` and `lg:sticky` and the winner depended
on Tailwind's emission order.

**Stats became shortcuts.** Județe and domenii counts were dropped — as numbers they said nothing
a visitor could act on, and both are reachable as facets. Active + angajatori stayed, as one line
of mono text rather than four cards. In their place, a row of clickable entry points with counts:
top three domenii, Temporar, Funcție publică, Funcție contractuală, Telemuncă. They are plain
links, not filter controls, since they only appear when nothing is selected and a normal
navigation re-renders the form with the right boxes ticked. Counts are computed independently of
`$..._options` so facet capping and ordering cannot quietly change what the landing offers; each
was verified to equal its own filtered result count (464, 452, 365, 298, 13, 1718, 3).

**"Toate" removed from the status tabs.** On the deployed SQLite — exported `--active-only` —
it counted the same 1,799 rows as "Active", so it spent a third of the control's width saying
nothing. Old `?status=all` URLs fall back to `active` through the existing validation.

**The export links were quietly wrong.** The feeds always honoured `$_GET`; the bug was that the
sidebar renders once, server-side, and HTMX only swaps `#results`. Narrow the filters, then
subscribe, and you got a feed of whatever you were looking at *before* — with a link that still
worked, so nothing gave it away. Now re-synced from `location.search` on `htmx:pushedIntoHistory`
and `popstate`. Both `feed_url()` and the JS drop `page` and `sort`, which describe how the HTML
list is being read rather than which postings it holds.

Also removed `$corpus_count`: dead once the sub-header went, and it had been running a
`COUNT(*)` on every request, HTMX swaps included.

Two things logged rather than fixed. The "Funcție contractuală" chip matches 1,718 of 1,799
active postings, so it narrows almost nothing — it was asked for, and it is built, but it does
the opposite job of the other six. And building it surfaced that `categorie` and
`employer_category` look crossed for post-redesign rows: the column named for the employer holds
position categories for 96% of rows, while the one the sidebar labels "Categorie" is empty for
99%. The sidebar's "Angajator" facet is really a position-type facet. Worth tracing before either
is trusted.

### 2026-09-08 — Asked whether `webapp/` could be deleted; found it is the ETL, not a web app

Prompted by the skin work above: the Django app still carries its own hardcoded palette and a
`cdn.tailwindcss.com` script, so the two frontends had visibly diverged, and the folder looked
stale apart from two recently-touched tests.

It cannot be deleted. `webapp/` is the database layer the PHP app stands on: the 12 migrations
own the `jobs_*` tables that `export-to-sqlite.py` reads by name, `pipeline.py:133` shells out
to `manage.py` for three of its stages, `apps/jobs/judete.py` is the canonical județ
interpreter, and `tests/` is the project's only suite (282 passing).

But about half of it is dead weight. `views.py`, `base.html` and six templates duplicate PHP
pages one-for-one — ~2,634 lines, slightly more than the load-bearing model and command code —
and the JSON/Atom/iCal feed views are duplicated too. That duplication is exactly what produced
the styling divergence: two frontends, only one of which got the token layer.

The two recently-updated tests turned out to be the misleading signal. `test_llm_runner.py` and
`test_posting_urls.py` import `grounding`, `schema_models` and `posting_urls` from the repo root
and touch Django not at all; they live in `webapp/tests/` only because that is where the suite
happens to be. The folder reads as an actively developed web app because it is an actively
developed *test directory*.

Logged rather than acted on — see the backlog entry under Cross-cutting. The shape of the fix:
delete the duplicated frontend, first moving the six helpers the tests exercise (and that
`helpers.php` reimplemented) out of `views.py`; keep models, migrations, commands, `judete.py`,
`admin.py`, the tests and the LLM-variants dashboard; then rename the folder to `etl/` or `db/`,
which is what actually stops it drifting back. `pipeline.py:44`, `deploy-php.sh`, `conftest.py`,
README and CLAUDE.md all reference the path.

### 2026-09-08 — Skins: the palette became tokens, and GOV.UK / posturi.gov.ro skins on top

The PHP webapp's colours were literals in `tailwind.config.js` and ~90 raw Tailwind palette
classes (`bg-amber-50`, `text-slate-700`, …) scattered through the templates. Restyling meant
editing templates, so there was no way to try a different look.

**The token layer.** Every colour, radius and font in the Tailwind theme now resolves to a CSS
custom property — `page: "rgb(var(--c-page) / <alpha-value>)"` — with the defaults in one
`:root` block in `assets/app.css`. Re-declaring those variables under `[data-skin="<id>"]`
restyles the whole site without touching a utility class. Values are space-separated RGB
channels rather than hex because that is the only form `<alpha-value>` composes with; hex
would silently break every `bg-surface/70`.

**Renames.** Token names now describe a role, not a colour: `parchment`→`page`,
`parchment-dark`→`sunken`, `border-warm`→`line`, `border-input`→`line-strong`,
`bg-white`→`bg-surface`. A name like `bg-parchment` becomes a lie the moment a skin is not
beige. The ~90 raw palette classes collapsed into five semantic families — `info`, `neutral`,
`ok`, `note`, `alert` — each a fill / border / label triple.

**The brand split, found by the validator.** `--c-gov` was serving as both the masthead fill
and the link colour. Both new skins need a masthead that is *not* their link colour (GOV.UK:
black bar, blue links; posturi.gov.ro: navy bar, lighter blue links), and both had started out
overriding `header` by hand to get it. Splitting into `--c-gov` (actions, with `--c-on-gov`)
and `--c-gov-bar` (masthead, with the `--c-on-bar` ramp) means no skin hardcodes the header,
and it makes the on-fill contrast pairs measurable.

**Discovery, not a registry.** `inc/skins.php` globs `static/skins/*.css`; drop a file in and
it appears in the footer picker on the next request. The display name comes from an `@skin`
comment in the file, ids are validated against `^[a-z0-9][a-z0-9_-]*$` before reaching a
`data-` attribute, and `_`-prefixed files are skipped. The choice lives in `localStorage` and
is applied by a pre-paint inline script, so there is no flash of the default palette. The
valid ids are baked into that script so a deleted skin falls back to the default instead of
pointing at a stylesheet that no longer exists.

Skins sit in `static/` rather than `assets/` for two reasons: Tailwind would strip them (nothing
in the PHP references their selectors), and `deploy-php.sh` excludes `assets/`.

**The two skins.** `govuk.css` is the GOV.UK Design System — white ground, zero radius
everywhere including `--radius-pill`, Arial (GDS Transport is licensed to gov.uk domains; Arial
is the Design System's own documented substitute), yellow `#ffdd00` focus with the black
underline, the semantic families mapped onto `govuk-tag`. It has no cards: `--c-page` and
`--c-surface` are both white and grouping is carried by a visible grey rule, which is how
GOV.UK actually works. `posturi.css` mirrors the official site, with values read off its live
inline styles rather than guessed — `#0f2742` masthead navy, `#0f4c81` links, `#d4af6a` gold,
the slate text ramp, `0 6px 18px rgba(15,39,66,.09)` card shadow, 10–12px radii. Manrope is
self-hosted (latin + latin-ext; ș and ț live in latin-ext) via an `@font-face` inside the skin
file itself, so the browser only fetches it while that skin is active.

**The validator.** `assets/check-skins.php` reads the token contract out of `app.css` and flags
the four failures that are silent in a browser: an unscoped rule, a token name that does not
exist, a colour written as hex, and any text/background pair under WCAG AA. It earned its keep
immediately — besides the brand split above it caught two sub-AA values in the posturi skin
(`#64748b` at 4.36:1 on the `#f5f5f3` ground, `#9ca3af` at 2.54:1 where 1.4.11 wants 3:1),
both replaced with computed rather than eyeballed values. Both skins now override 32/32 colour
tokens and all palettes pass.

Known cost: the two `<link rel=preload>` font hints follow the default skin, so a returning
visitor on `govuk` or `posturi` preloads two faces it will not use. Fixing it needs a cookie
round-trip, which would break shared-host page caching for ~90KB on one request.

### 2026-09-08 — Recovered the 80 lost postings, and found they were all cancelled competitions

**What:** Repaired the 80 postings the `get_slug` collision had left empty, which
promptly exposed a second, worse problem.

**The repair:** backed up and deleted the 28 colliding `data/anunturi/**/index.html`
files, re-fetched, re-parsed, re-imported. 78 of 80 recovered — the other two return
**404 on posturi.gov.ro**, deleted upstream. Result: 78 postings gained a body
(avg 5,746 chars), an expiry date and 233 calendar events between them; the importer
went from silently dropping them to `Detail: matched=9604 unmatched=0`.

**The second half of the bug.** Fixing `get_slug` (the encoder) was not enough:
`parse-anunturi.py::source_url_from_path()` (the decoder) rebuilt every URL as
`/joburi/{slug}/`, so the recovered files still failed to join back to their index
row. Rather than write a second regex to invert the first — two regexes kept in sync
by hand is what caused this — both scripts now share `posting_urls.py`, and the
decoder resolves through a slug→URL map built by running the *encoder* over every URL
in the index CSV. The two directions agree by construction. `/anunt/` URLs round-trip
properly now too, where before they relied on a fallback in `_try_index_lookup`.

**What the recovered data revealed:** all 80 carry `expira_in = "Anunț anulat"` in the
index. The correlation is exact and three-way — 80 raw-permalink URLs, 80 cancelled
announcements, 80 rows that had NULL expiry, no overlap in any direction. When
posturi.gov.ro withdraws a competition it drops the pretty permalink *and* replaces
the expiry with that phrase.

**Which made the repair actively dangerous.** A cancelled announcement's detail page
still carries its original dates, so successfully fetching these turned **15 withdrawn
competitions into apparently open jobs** — worse than the invisibility they replaced.
The site had been protected only by the accident that they never fetched.

Fixed properly rather than papered over: `JobPosting.cancelled` (migration 0011), set
by the importer from the index marker, and `--active-only` in `export-to-sqlite.py` now
means open **and** not withdrawn, for calendar events as well as postings. The active
export drops from 1,777 to 1,762. `import_csvs` reports "80 marked cancelled".

**Not done:** the deployed SQLite has not been rebuilt or redeployed, and the 78
recovered postings have no LLM extraction. Both wait on the interrupted v3 backfill.

---

### 2026-09-08 — Pipeline audit: the schema step could not be driven, and 80% of the live site has no extraction

**What:** Audited the update pipeline against the live database rather than the code.

**The finding:** **1,440 of 1,799 active postings have `schema_json IS NULL`.** The
deployed `webapp-php/posturi.sqlite` carries structured sections for 359 of its 1,799
rows — four out of five detail pages on the live site render with no responsibilities,
no requirements, no skills. 9,603 postings total, 647 published in the last 7 days, so
this is not a stale-data problem; the extraction step simply never got through.

**Why it never got through:** `pipeline.py::_build_cmd()` passed the schema step only
`--force` and `--provider`. Not `--active-only`, not `--limit`, and (until today) there
was no `--resume` or `--workers` to pass. So the step ran one call at a time over every
posting ever scraped — ~40 h with no resume, restarting from zero after any
interruption. Fixed: all five now pass through, opt-in, so existing invocations are
unchanged. The documented backfill is now

    python pipeline.py --steps schema --active-only --resume --workers 8 --prompt-version v3

which is ~1,440 calls, roughly 2 h and ~$2.

**Run it as v3, not v2** — checked rather than assumed. `JobPostingExtractionV3`
inherits every v2 field; `_render_schema_sections()` in `views.py` reads `schema_json`
by key, so the detail page is unaffected; and `_v3_columns()` in `export-to-sqlite.py`
already flattens the v3 keys into the `v3_*` SQLite columns the new filters query.
`PRODUCTION_PROMPT_VERSION = "v2"` only sets the default filter on the LLM-variants
comparison page — I initially wrote that it gated the backfill, which was wrong.

**Second finding — 80 postings were never fetched at all.** 81 postings have
`expires_at IS NULL`, 80 of them published within 60 days (one today), and
`export-to-sqlite.py` filters `WHERE jp.expires_at >= CURRENT_DATE`, which drops NULLs,
so they are invisible on the live site.

I first concluded this was not a parsing bug, on the grounds that none of the 80 mention
a deadline anywhere in `body_markdown`. That reasoning was worthless: **they have no
`body_markdown` at all.** Absence of evidence, read as evidence of absence. Looking at
the rows rather than grepping them showed every one carries a raw WordPress permalink —
`https://posturi.gov.ro/?post_type=pg_job&p=26404` — instead of `/joburi/{slug}/`. The
correlation is exact: 80 query-string URLs in the database, 80 rows with NULL expiry,
zero overlap either way, and all 80 have no body, no attachment, no card deadline and
no schema_json.

**Root cause:** `fetch-anunturi.py::get_slug()` was
`path.split('/')[-1] if path else 'index'`. A query-string URL has an *empty* path, so
every one of them returned the constant `'index'` and shared a single cache filename per
date directory. 28 `index.html` files exist on disk, each holding whichever posting was
fetched first that day; `file_exists()` then skipped all the others as already
processed. `get_slug` now falls back to a sanitised query string
(`post_type-pg_job-p-26404`), with regression tests covering collision, filename safety,
stability across runs, and the unchanged path-URL behaviour.

The fix stops it recurring but does not repair the rows — those 28 cache files need
deleting and the 80 URLs re-fetching, re-parsing and re-importing. Backlogged.

**What let it hide for two months:** the export filter silently drops NULL `expires_at`.
A posting that fails to fetch loses its deadline, and losing its deadline removes it
from the live site — so the failure mode erases its own evidence.

**Also noted, not fixed:** a stale 73 MB `posturi.sqlite` (9 June) in the repo root left
over from before `export-to-sqlite.py` grew `--out`; 88 active postings with empty
`inferred`; and nothing in the repo that actually schedules `deploy-php.sh`, which is
what the recurring "live site is N weeks stale" entries in this log keep describing.
All in `docs/backlog.md` § "Update pipeline".

---

### 2026-09-08 — The extraction round made survivable: retry, concurrency, resume, grounding

**What:** Five of the levers identified in `docs/llm-extraction-round.md` (new, and the
place to read for the measurements behind all of this).

**Retry — the real gap.** There was none. `llm-schema.py` caught every exception,
printed `✗` and moved to the next posting, so a single 429 lost that posting
permanently. At ~9,600 calls per model, rate limits and 5xx are certainties.
`generate_with_retry()` now splits failures in two, because they need opposite
responses: a *transient* error (429/5xx/timeout) is retried with the same input and
exponential backoff with jitter, while *invalid output* gets exactly one repair
attempt that appends the validation error to the user message. Only one repair — if
showing the model its own error does not fix it, a third full prompt will not either.

Classifying the transient ones is the fiddly part: no two provider SDKs share a base
class, and google-genai exposes `.code` where openai and anthropic expose
`.status_code`. `_is_transient()` reads whichever numeric status it can find and
falls back to matching the exception class name.

**Concurrency.** `--workers` (default 4). Only the LLM call runs in the pool; every
database write stays on the calling thread, since one psycopg connection is not safe
to share and `write_variant` commits per row. `imap_unordered()` keeps a bounded
number in flight rather than using `executor.map`, so the 9,600-row cursor is not
materialised to start work and an abort does not strand thousands of queued calls.
Measured on 8 real postings: **5.13 s/post at 4 workers** against 15-20 s each
sequentially — about 14 h for the full corpus instead of 40.

**Resume.** `--resume` excludes postings that already have a variant row for the exact
provider/model/prompt-version. Done as a `NOT EXISTS` clause inside
`_selection_where()` rather than by filtering the generator, because that function's
whole contract is that `count_postings` and `iter_postings` agree — filtering in
Python would have made the progress bar lie. Verified over two consecutive runs: 27
variants across 27 distinct postings, nothing reprocessed.

**Grounding check (`grounding.py`).** Every v3 requirement already carries the phrase
it came from, so hallucination detection needs no second LLM call — just compare the
quotes to the source. Calibrated against the real extractions rather than guessed:
a quote passes if 60% of its content words appear in the source **or** any four
consecutive content words appear consecutively. The second rule was added after a
negative control showed the first one alone is unfair to long quotes — coverage
punishes length asymmetrically, so a genuine 20-word span with a 3-word invented
lead-in scored 50%, the same range as a pure fabrication. All 30 quotes in the real
v3 extractions pass; injected fabrications score 12-14%. Findings are printed and
counted per run; storing them needs a column on the variant table.

**Derived fields.** `eqf_level` is a pure function of `minimum_level` — and
`STUDY_LEVEL_TO_EQF` had been sitting in `schema_models.py` unused since v3 was
written, while the prompt asked the model to do the lookup. Both it and `iso_code`
are now derived in `model_validator`s, overwriting whatever the model returned.
Asking for the same fact in two notations can only introduce disagreement.

**Tests:** `webapp/tests/test_llm_runner.py`, 32 cases over error triage, backoff,
the repair path, pool completeness under failure, lazy iteration, grounding and the
derived fields — all with fake `generate` callables, so none of them call a provider.

---

### 2026-09-08 — One place to choose the LLM: `llm_config.py`, env vars, and honest cache pricing

**What:** Provider/model/prompt selection was scattered across four files with three
different answers. `llm-schema.py` and `quality_check.py` each carried their own
`DEFAULTS` dict — and they disagreed (`openai` meant `gpt-4o-mini` in one,
`gpt-4o` in the other). `pipeline.py` and the two `infer_*` management commands
read `$LLM_PROVIDER`; the two scraper scripts did not, so setting it in `.env`
changed half the pipeline.

New `llm_config.py` is the single source of truth, with one precedence rule
everywhere: **CLI flag > env var > `models_config.json` "defaults"**.

- `models_config.json` grew a `defaults` block (`provider`, `prompt_version`,
  `model` per provider) next to the existing catalogue.
- `.env` / `.env.example`: `LLM_PROVIDER`, `LLM_MODEL`, `LLM_PROMPT_VERSION`.
- `llm-schema.py`, `quality_check.py`, `pipeline.py` all resolve through it.
  `--provider` now defaults to `None` so "not passed" is distinguishable from
  "passed the same value as the default".

**Non-obvious decisions:**
- *An env var set to the empty string counts as unset.* `.env.example` ships
  `LLM_PROVIDER=`, and `load_dotenv()` turns that into `""` — which would
  otherwise beat the config default and blow up on the provider lookup.
- *`$LLM_MODEL` is ignored when it names a model of a different provider.* Otherwise
  `LLM_MODEL=gpt-4o-mini` in `.env` plus `--provider anthropic` on the command line
  sends an OpenAI model id to Anthropic. This way the env var is a preference, not a trap.
- *`pipeline.py` keeps a narrower provider list.* `llm-schema.py` supports deepseek;
  the `infer` management command has no deepseek branch. Rather than silently
  dropping the flag, pipeline errors out with a message naming the three it can drive.
- *`quality_check._llm_classify` hard-coded a model per provider* (including a
  `claude-haiku-4-5` that `DEFAULTS` never mentioned). Now resolved through the same path.

**Cache pricing fixed:** `compute_cost()` bills cached tokens at
`cache_input_cost_per_million`, falling back to the full input rate when the key is
missing — and it was missing on both Anthropic models and `gpt-4o`. The Anthropic
branch has been setting `cache_control` on the system block all along, so the reads
were real and the recorded `cost_usd` simply never showed the discount. Added
0.1x for the Claude models, 0.5x for `gpt-4o`.

**Measured, not assumed — Gemini implicit caching fires, but rarely.** The v3 system
prompt is ~6,960 tokens, i.e. **~81% of a typical request's input**, and when it is
cached it bills at 10% of the input rate: `in=8909 cached=7092 out=1467 $0.000839`
versus ~$0.0015 cold. But across a 6-posting sequential run only **one call hit it**
— implicit caching is best-effort and a ~15 s gap between calls is evidently enough
to lose it. Do not budget for it. If the prefix discount matters for the full v3
backfill, use Gemini *explicit* caching (`client.caches.create`, TTL'd), which
guarantees the hit; Anthropic's `cache_control` breakpoint (already wired in the
Anthropic branch) is the equivalent there.

Either way the absolute numbers are small: at ~$0.0015 per posting the whole
9,603-posting v3 run is ~$15, and 1,500 extra tokens of static prompt costs ~$1.4
across the corpus even with **zero** cache hits. Prompt size is not the constraint —
the ~40 h of sequential wall-clock is. See `docs/backlog.md` § "LLM round".

---

### 2026-09-08 — v3 filters and detail UI, built ahead of the data and verified against the 5 real extractions

**Asked:** are we extracting more fields now — if so, sync the filters and the UI.

**Measured first: no.** Zero active postings carry `education`, `skill_list` or `positions`. The five v3 rows live in `JobPostingSchemaVariant` as compare-only output and were never promoted to `schema_json`; production is still 380 v2 rows out of 1,793 active. Building the filters against that would have shipped eight empty facets on 100% of postings — the same trap that caught the inferred metadata, where the display was worth nothing until the backfill ran.

So the pipeline was built end to end and **verified against the five real extractions** rather than against nothing.

**Export.** `_v3_columns()` flattens a v3 `schema_json` into queryable SQLite columns: `v3_eqf_level` and `v3_study_level` as scalars, and `v3_isced_fields` / `v3_study_labels` / `v3_skills` / `v3_languages` / `v3_credentials` / `v3_policy_domains` / `v3_exam_stages` as JSON arrays probed with `LIKE '%"value"%'`, the shape `inf_anomaly_flags` already uses. A v2 payload or no schema returns empty defaults, so nothing half-populates. Languages pack level and code into one token (`en:B2`) so a single probe filters on both.

**Filters.** Seven new facets — Domeniu de studii (ISCED-F), Competențe, Domeniu activitate, Nivel studii (EQF), Limbi străine, Documente necesare, Etape concurs — plus chips, labels and multi-value handling. `facet_group()` already omits an empty group, so **all seven are invisible today and appear on their own once a v3 extraction has run**. EQF filters as "requires at most this level", since someone with a licență also qualifies for a post asking postliceală.

**Detail page** gains a "Cerințe structurate" block: field-of-study chips each linking into the ISCED facet, with "Oricare dintre aceste domenii este acceptat" spelled out; competence chips carrying proficiency, with advantages dashed rather than solid; languages with CEFR; credentials; policy domains and exam stages; and a **per-role breakdown for multi-role postings**, each with its own studies, experience and competences.

**Verification.** Copied the export, promoted the five real v3 payloads into the copy (production untouched — `db.php` grew a `POSTURI_DB` override for exactly this), and ran the app against it. Every facet populated with real values, and all twelve filter results were checked against SQLite ground truth: isced 3/3, credential 1/1, domain 3/3, skill 2/2, stage 5/5, EQF inclusive-of-lower-levels 3, two v3 facets AND-ing to 1, a v3 facet combining with județ, HTMX filtering, chip labels in Romanian, chip removal restoring 1,710. Production run afterwards to confirm all routes still 200 with zero v3 facets rendered.

**One bug caught by that verification.** Four of the seven facets returned nothing while `skill` and `eqf` worked. The difference was underscores: these vocabularies are full of them (`09_sanatate_asistenta_sociala`), `_` is a LIKE wildcard, and I escaped it without declaring `ESCAPE '\'` — so the pattern matched literally nothing. Fixed, and the ground-truth comparison is what surfaced it; the facet counts alone looked perfectly healthy.

225 tests pass (16 new for `_v3_columns`, including that a v2 payload must not half-populate the v3 columns).

**Still true:** none of this shows anything until `llm-schema.py --prompt-version v3` runs at scale, which needs provider credits.

### 2026-09-08 — First v3 run: three bugs of mine, four prompt defects, and a structural gap

User ran `--compare --prompt-version v3 --active-only --limit 5`. Gemini completed all five; OpenAI returned 429 (no credits). `schema_json` stayed at 1,278 rows, so `--compare` honoured its promise not to touch production data.

**Three bugs of mine, all from yesterday's v3 wiring:**

1. **DeepSeek died with `name '_validate_v2' is not defined`.** Renaming that helper to `_validate_extraction`, I used a regex matching only the call taking `raw` and left three behind — OpenAI, Anthropic, DeepSeek. Gemini happened to be the one I did fix, which is why my own verification passed. I should have grepped after renaming instead of trusting the substitution. Fixed, plus two tests that would have caught it: one asserting the old name appears nowhere, and an AST check that every name loaded inside a `make_generator` provider branch resolves to a local or a module global. Both were confirmed to fail against the broken code before being kept.
2. **`llm-schema.py` only imported from the repo root** — `open("models_config.json")` by relative path. Now resolved against `__file__`.
3. Cost figures in the design doc were stale after the prompt grew; corrected.

**Four prompt defects the output showed, all fixed:**

| Defect | Frequency | Fix |
|---|---|---|
| Multi-role postings returned all-nulls | 2 of 5 | new `positions[]` array + a third few-shot |
| `fields_of_study` labels were adjectives or filler — `juridic`, `medical`, `specialitate`, `sanitară` | 3 of 5 | "normalise to the name of the field, not the adjective", explicit ban on filler words |
| `bibliography_topics` over-extracted — 37 entries, some genitive fragments | 2 of 5 | cap at 12, nominative form |
| `credential.kind` fell back to `altele` for things with a home | 1 of 5 | per-kind guidance with examples |

**The structural finding.** Two of the five postings advertise *several distinct roles* — "1 post de expert IT senior; 2 posturi de expert administrație publică I" — each with its own requirements (3 years of tenure vs 7). A flat extraction cannot hold two contradictory requirement sets, and rather than choose, the model returned null for education, skills, occupation *and* seniority. That threw away a posting which plainly stated "Cerințe specifice: Cunoașterea procesului de utilizare a fondurilor europene". Measured across the corpus: **143 of 1,710 active postings (8.4%)** are multi-role. Added `PositionRequirement` and a `positions[]` array — one entry per role with its own education/experience/skills/credentials — while the flat fields keep describing the first role so a consumer that ignores `positions` still gets something real. Filed a separate item for the downstream consequence: the list row, the detail page and every filter still treat a posting as one job.

**A correction to my own reading.** `skill_list` looked under-extracted (1, 1, 0, 0, 0) and I said so. It was not. My evidence was a substring count of "abilit" — 21 hits in one posting — but every one was inside *dizabilități*, *reabilitarea* or *unitățile sanitare abilitate*. That is the same substring trap I fixed in `_infer_skills` yesterday, and I walked straight into it while checking someone else's work. Counted on word boundaries, three of the five postings contain no competence list at all and the empty result is correct; the one real miss was the multi-role posting.

**What worked, and these were the open questions:** `fields_of_study` genuinely splits — one posting yielded seven alternatives across three ISCED fields (psihologie → 03, asistență socială → 09, științe administrative → 04). `credentials` is the strongest field in the schema: "certificat de membru OAMGMAMR", "aviz anual pentru autorizarea exercitării profesiei", "permis categoria B", "asigurare malpraxis" — exactly the hard yes/no filters. `contract` came back richer than expected (`duration_months: 33`, `shift_work: true`). `experience` read "6 luni" as `0.5` with `in_specialty: true`.

**Still unmeasured:** cross-provider agreement, especially on `isced_field` — "inginerie geodezică" is defensibly 07 or 05, and how much providers disagree decides whether ISCED is trustworthy enough to filter on. The revised prompt (17.3k chars, ~5k tokens; JSON Schema 19.2 KB) has not been run at all.

209 tests pass.

### 2026-09-08 — Prompt v3: granular extraction designed for CV-to-job matching

**Ask:** extract more semantics — study fields, competencies — from lines like *"Studii … în domeniul geografie/silvicultură/geologie/biologie/știința mediului/inginerie geodezică. Cunoștințe avansate de operare sisteme GIS (…)"*; how does Schema.org JobPosting relate to Europass; can we build a v3 that makes the UI filter as expressive as possible, with Europass CV upload as the end goal.

**Researched first.** [cariere.gov.md](https://cariere.gov.md/ro/search?active=1) filters on **Domenii** (33 subject-matter domains: achiziții publice, securitate energetică, protecția consumatorului …), plus function type, authority type, locality and employment type. The important idea there is that *subject matter* is a separate axis from *profession* — a lawyer in an environment agency is both, and our `profession_family` only captures the second. The [Europass XML v3.0 page](https://interoperable-europe.ec.europa.eu/collection/employment-and-working-conditions/solution/europass-xml-schema-v30/release/no-version) does not publish field names but does name the vocabularies it imports: ISCO, ISCED, EQF. [ESCO](https://esco.ec.europa.eu/en/classification/skill_main) supplies the modern skills pillar — knowledge / language / skills / transversal, 13,939 concepts — and [Europass CVs](https://esco.ec.europa.eu/en/about-esco/escopedia/escopedia/cv-creation) carry EQF levels, ISCED-F fields of study, CEFR language levels and DigComp digital skills.

**The answer on Schema.org vs Europass:** they are mirror images and neither replaces the other. Schema.org describes the *vacancy* and is deliberately loose (`educationRequirements` is free text) because its consumer is a search engine. Europass describes the *person* and is deliberately strict, because its whole point is that a diploma from one member state is legible in another. A matching system needs both — Schema.org so the posting stays Google-Jobs-eligible, Europass vocabularies so posting and CV become comparable. v3 emits Schema.org property names at the top level and hangs EU-vocabulary values underneath.

**What v3 adds.** Keeps all twelve v2 fields verbatim (`JobPostingExtractionV3` *inherits* `JobPostingExtraction`, so every page, feed and renderer works unchanged on a v3 row) and adds a structured layer beside them:

- `education` — `minimum_level` + **EQF 1–8** + `fields_of_study[]` with **ISCED-F 2013** codes. The alternatives in the example become six separate matchable entries instead of one unmatchable string, spanning three ISCED fields.
- `skill_list[]` — short tags, not sentences, with the **ESCO pillar** and a proficiency: `{label: "GIS", type: "knowledge", proficiency: "avansat", evidence: "Cunoștințe avansate de operare sisteme GIS"}`. The parenthesised list expands into four more entries.
- `language_list[]` — **CEFR** A1–C2. `credentials[]` — licences and authorisations, deliberately separate from skills because they are the hardest filter there is: you hold the document or you do not. `contract` — duration, schedule, hours, remote mode, probation, shifts. `policy_domains[]` — 24 subject-matter domains adapted from cariere.gov.md. Plus `occupation_title`, `seniority_hint`, `exam_stages[]`, `bibliography_topics[]`.

Throughout: anything a filter depends on is a controlled value, with the original Romanian kept in `verbatim` / `evidence` for display. Free text cannot be matched; enums can.

**Plumbing.** `llm-schema.py` no longer hard-codes `JobPostingExtraction` — it looks the model up in `schema_models.EXTRACTION_MODELS`, so all four provider paths (Gemini `response_schema`, OpenAI strict `json_schema`, Anthropic tool `input_schema`, DeepSeek) pick up v3 automatically and v4 is a one-line change. Verified that every provider's schema payload builds for both versions.

**Not run.** All four providers remain unusable, so v3 is designed, wired and tested but has extracted nothing. The honest state: 26 tests cover the vocabularies, the v2-superset guarantee and the OpenAI strict-mode schema; the GIS example validates end to end; nothing has been through an actual model. First thing to do when a provider works is `--compare --prompt-version v3 --active-only --limit 5`, which writes only to `JobPostingSchemaVariant` and never touches `schema_json`.

**Cost:** v3's system prompt is 12,377 chars (~3.6k tokens) against v2's 6,355 (~1.9k), and the structured-output JSON Schema grows 6.2 KB → 17.4 KB. Against a 6,000–9,000-token posting body that is under ~15% per posting.

**Written up** in `docs/metadata-schema-v3.md` — the rationale, the Schema.org↔Europass comparison, the full field map with the filter each unlocks, and four open questions (ESCO URI resolution, whether `policy_domains` should be a standard, ISCED-F assignment being the model's judgement, and the lack of a migration path for the 380 v2 rows).

### 2026-09-07 — Attachment descriptors: dropped the filename, tried document titles, kept what survived

**Ask:** the filenames are useless — use the document's own title instead, in a standardized way.

**What the data allowed.** Measured over 899 downloaded files with four successive classifier designs:

1. Keyword match anywhere in the first 2,500 chars → 91% labelled, but "Cerere de înscriere" took 24% because announcements *quote* the enrolment requirement before they say "anunț".
2. Earliest-marker-wins → collapsed to 88.6% "Anunț de concurs", which was the honest answer and showed the problem.
3. Heading-like lines only → the same false positives, since section headings are also short lines.
4. First meaningful line, letterheads skipped, abandoning once the document calls itself an announcement → **only "Erată" survived with real precision.**

The shape of the data is the finding: **~89% of these files simply are the competition announcement**, one per posting, and their headings are all some spelling of "ANUNȚ" (often letter-spaced, `A N U N Ț`). Bibliografie, fișa postului, cererea de înscriere and the calendar are almost never separate documents — they are *sections inside* that one file, so every classifier that reached for them mislabelled the announcement itself and scored worse than saying nothing. A free-form "title" is no better: the first lines are letterheads, registry numbers and OCR noise.

**So the standardized label is deliberately small.** New `webapp/apps/jobs/attachments.py` recognises exactly two kinds — **Erată / modificare** and **Rezultate** — both of which declare themselves on the opening line and both of which materially change what a reader should expect (an erratum can move a deadline). Everything else keeps the role it already has reliably from the field it came from: *Anunț oficial* vs *Document anexat*.

**What replaced the filename.** `extract_attachments` now writes `JobPosting.attachment_meta` — `[{url, ext, bytes, kind, role}]` — computed per file while it already has each one open. The detail page renders a format badge, the standardized label and the file size (median 163 KB, largest 9.2 MB), with an erratum picked out in amber. Size is the genuinely new information: it was nowhere on the site, and it is what a reader on mobile data wants before tapping a 9 MB scan. Active postings: 990 DOCX, 637 PDF, 167 DOC; one erratum found in the current batch.

**Also this session:**

- Ported the **LLM-parsed filter** from the Django "Dev" panel (commit `c9be4ce`, never in the deployed app) to `webapp-php/` as a public facet — *Descriere → Structurată (LLM) 380 / Doar text brut 1,413*. Dropped the sibling `inferred` filter (100% populated, always a no-op) and `variants` (not exported).
- Made the **Schema.org structure visible**: the JSON-LD now emits all seven text properties the prompt fills (was two), plus `occupationalCategory` and `directApply`; each rendered heading shows its property name linked to schema.org/JobPosting; and the 79% with no structured extraction now say so rather than silently presenting a wall of scraped text.
- **Prompt location, for the record:** `models_config.json` → `prompts.v2` (6,355 chars), loaded by `get_prompt()` at `llm-schema.py:52`, sent as the *system* instruction — `system_instruction` for Gemini, `role: system` for OpenAI/DeepSeek, a cached `system` block for Anthropic. The posting content (body + attachment text, boilerplate-stripped, 100k cap) is the *user* message.

**Backlog:** filed employer search + institution-type detection. Checked two assumptions while writing it — `employer_category` turns out to hold the *contract* type ("Funcție contractuală" on 1,710 of 1,793), not the institution type, so it does not already cover this; and a prototype keyword pass over the 1,103 active employers classified **89%** (primărie 32%, educație 24%, spital 19%, …), so the `judete.py` treatment is the right model.

**Tests:** 22 new in `webapp/tests/test_attachments.py`, including the exact failure mode that killed the richer taxonomies (an announcement containing BIBLIOGRAFIE / CERERE DE ÎNSCRIERE headings must stay unlabelled). Full suite: 171 passed.

### 2026-09-07 — Ported the LLM-parsed filter to the public app; Schema.org made visible; attachment filenames

**The filter existed, in the other app.** Commit `c9be4ce` (2026-05-28) added a dashed amber "Dev" panel to the *Django* browse sidebar with three radio filters — Schema JSON / Inferred meta / LLM variants. It was never ported to `webapp-php/`, which is what is deployed. And no, they are not all LLM-parsed: **380 of 1,793 active postings (21%)**.

Ported it as a real facet rather than a debug panel, since the distinction is useful to readers too: **Descriere → Structurată (LLM) 380 / Doar text brut 1,413**, under "Mai multe filtre", with live counts, a removable chip and full HTMX behaviour. Dropped the `inferred` yes/no filter (100% populated since this morning's backfill, so it would always be a no-op) and the variants filter (variant rows are not in the SQLite export).

While testing it: the groups inside "Mai multe filtre" were themselves collapsed, so reaching one checkbox took two disclosures. Anomalii and Descriere now default open inside the panel, like Salariu and Angajator already did.

**Schema.org, made visible.** The extraction already produces Schema.org JobPosting property names and the renderer maps them to Romanian labels — but nothing said so, and the JSON-LD was throwing most of it away.

- The JSON-LD now emits every text property the prompt fills: `responsibilities`, `educationRequirements`, `experienceRequirements`, `qualifications`, `skills`, `jobBenefits`, `workHours` — previously only two of the seven. Added `occupationalCategory` from the inferred profession family (only above the confidence floor) and `directApply: false`, since applications go through the source site.
- Each rendered section heading now carries its property name in small mono type, linked to schema.org/JobPosting — *Studii · educationRequirements*. The three RO-specific keys (`application_docs`, `application_fee`, `application_contact`) are deliberately unlabelled, because they are ours and not part of the vocabulary.
- The 79% with no structured version now say so instead of silently presenting a wall of scraped text: a short note above the body explaining that no section-level extraction exists yet, linking to the methodology.

**Attachments.** Was "Anunț oficial ↗" / "Document 1 ↗" — no indication of what the file is. Now shows the filename with a file-type badge and the role beneath, full name on hover (`title`) and in `aria-label`. Worth recording that the filenames are useless as names: all 1,794 attachment URLs in the export are opaque (`j_11414_c_9189_anunt_688423.docx`, or an 8-char hex hash) — not one contains a real word. So the display leans on the type badge (DOCX/PDF/DOC), which is the only signal actually present, and middle-truncates the name (`j_11414_c_9189_anun…88423.docx`) so the extension survives the ellipsis.

**Verified:** facet counts match SQLite exactly (380/1,413), chip add/remove keeps the sidebar in sync, both page variants render correctly, all routes and feeds 200, no JS errors, 149 tests pass.

### 2026-09-07 — Traced the detail-page body; found .doc extraction broken since May

**Question asked:** where does the posting body come from, is it the LLM version with standard sections, and does the LLM get both the web body and the attachments?

**Answers, in order.**

`pages/detail.php` prefers the LLM's structured sections (`schema_json` → `render_schema_sections()` → `SCHEMA_SECTION_LABELS`) and falls back to `render_markdown($p['body_markdown'])` — the raw scraped page. **Only 21% of active postings (380/1,793) have `schema_json`**, so 79% render unstructured text. That is the stalled prompt-v2 backfill, blocked on all four LLM providers being unusable.

The v2 prompt does define clear standard sections and the renderer displays every field it asks for. Fill rates on the 380 that have it: jobLocation 96%, educationRequirements 95%, application_contact 95%, application_docs 83%, workHours 71%, experienceRequirements 70%, qualifications 64%, skills 42% — and **responsibilities only 18%**, which is the section a job seeker most wants. (`work_conditions` is in the renderer's label map but the prompt never emits it — dead entry.)

Yes, `llm-schema.py::iter_postings` concatenates `body_markdown` + `attachment_text` with a `---` separator, strips the HG 1.336 boilerplate and truncates at 100k. And it demonstrably matters — comparing the 380 postings with schema, split by whether attachment text existed at extraction time:

| section | with attachment | without |
|---|---|---|
| responsibilities | 22% | 8% |
| skills | 47% | 25% |
| application_docs | 96% | 40% |

**Which is what turned up the real bug.** `extract_attachments.py::_extract_doc` still used `docx2txt`, which only understands the ZIP-based .docx container and raises `KeyError`/`BadZipFile` on every genuine legacy .doc. `extract_text()` swallowed all exceptions into `""`, so an extractor failing on 100% of its input was indistinguishable from a pile of empty documents. The switch to `textutil` was made in `quality_check.py` (a reporting script) in May 2026 and **never ported to the management command that actually writes `attachment_text`**. Measured on four failing files: docx2txt 0 chars every time, textutil 9,031–15,862 chars.

Fixed with a portable converter chain — `textutil` (macOS) → `antiword` → `catdoc` (both Debian-installable), which also closes the "textutil is macOS-only, add a fallback before deploying to Linux" note from May. Plus a ZIP-magic sniff, because some servers serve a real .docx under a .doc name. `extract_text()` now returns `(text, reason)` instead of swallowing failures, and the command prints a by-reason summary, so the next systematic breakage is visible on the first run rather than four months later.

**Result:** re-running extraction recovered text for **2,082 postings**. Active coverage 77% → **86%**; postings with an attachment link but no text 23% → 14%. The 980 remaining are "no text layer" — scanned PDFs needing OCR, already a known backlog item. Re-ran `infer_postings --no-llm --force` over the richer text (studies 1,491 → 1,502, experience 952 → 956, work_type 846 → 856) and re-exported.

**Not fixed:** the 79% without `schema_json` still need a working LLM provider. That gap is now the single biggest quality lever on the site — and every posting fixed above will produce a better extraction when it finally runs.

### 2026-09-07 — Inference backfill + inferred metadata shown on every posting

**Why:** the request was "we extracted structured data for filters — show it next to each job post". Measuring first: **1,768 of 1,793 active postings had `inferred = {}`** (99% empty), exactly as the backlog predicted. The display would have rendered blank on almost everything that ships, so the backfill came first.

**Backfill.** `infer_postings --no-llm` runs the keyword-dictionary pass entirely offline — no provider needed, ~50 postings/sec. Coverage on active postings went 1% → 100% on the core fields: profession_family 1,545 (86% non-`altele`), studies 1,491 (83%), experience 952 (53%), work_type 846 (47%), seniority 461 (26%).

**Three inference bugs found by looking at the rendered output**, each of which would have shipped wrong data to users:

1. **Age read as work experience.** `_EXPERIENCE_RE` only matched "N ani … vechime", and `_normalize()` collapses newlines, so "Să aibă vârsta de minim 21 ani" running into the next line's "Vechime in munca: minim 1 ani" yielded *21 years of experience* for a driver's post. Romanian postings overwhelmingly put the label first, so the regex now prefers "Vechime în muncă: minim 3 ani" and keeps the number-first form as a fallback guarded against a nearby "vârsta". Effect: 2,619 postings newly detected, none lost, and the bogus 21 corrected to 1.

2. **`_infer_skills` matched substrings, not words.** "SAR" fired on **8,183 of 9,425 postings (87%)** because it is inside *nece**sar**e*, *comi**sar***, ***sar**cini*; "atestat" fired on 36% via the boilerplate "starea de sănătate atestată". A "skill" present on 87% of postings is noise wearing a data costume. Switched to the file's existing word-boundary helper: SAR 8,183 → 1, atestat 3,437 → 620, and every genuine skill (Excel, Word, permis de conducere, Microsoft Office) unchanged. Also de-duplicated certifications on case/NBSP and canonicalised language spellings ("engleza"/"engleză" → one tag).

3. **`--force --no-llm` silently destroyed paid LLM work.** 598 postings had an LLM-derived `profession_family`; re-running the dictionary pass to pick up fix #1 would have rewritten them all to `altele`. `--no-llm` now means "don't call the LLM on this run", not "discard what a previous run established" — an existing LLM classification is preserved when the dictionary is not confident. The run summary also stopped counting preserved classifications as "LLM calls", which is what would have hidden it.

**The display.** New `inferred_meta()` / `inferred_tags()` in `webapp-php/helpers.php` are the single source for derived attributes, so list and detail cannot drift.

- **Result rows** get one compact line — *Domeniu · Funcție · Grad · Studii · Experiență · Normă · Telemuncă · Calculator* — visually separated from the badges above it, which come straight from the source. A dotted-underline `auto` marker carries a tooltip explaining these are derived and may be wrong. All 25 rows on page 1 now render a populated line.
- **Detail page**: the conditions grid is now explicitly headed "Condiții deduse automat" with a link to the methodology, and gained skills / languages / certifications chips — extracted since May and never displayed anywhere. The sidebar's inferred block uses proper labels and links Domeniu and Funcție into the corresponding filtered browse.
- Labels: `conducere_superioara` was leaking raw into the UI; added `SENIORITY_LABELS` and `grade_label()`. A low-confidence family (< 0.5) or the `altele` catch-all is shown as nothing rather than as a wrong guess. Romanian year pluralisation via `years_label()` ("Min. 1 an", not "1 ani").

**Tests:** 24 new in `webapp/tests/test_experience_inference.py` — label-first and number-first phrasings, the age-vs-tenure guard with the exact SOFER body, explicit "nu este cazul", SAR/atestat word-boundary cases, tag de-duplication, and three DB-backed tests that `--no-llm` preserves LLM families while a confident dictionary still wins. Full suite: 149 passed.

**Left open:** the `altele` bucket is 14% of active postings and only an LLM pass can shrink it (all four providers are still dead). `skills` now covers 30% of postings rather than 99% of nothing — real, but thin; the keyword list is 14 entries and worth growing.

### 2026-09-07 — Canonical județe: 261 → 42, locality split out, fragmentation guarded

**What:** Fixed the county data rather than working around it in the UI. `judet_name` arrived in two shapes — bare `Timiş` before the 2026-07 redesign, `TIMIŞOARA, Timiș` after — and `import_csvs` stored both verbatim into a unique-by-name `Judet` table. Result: **261 rows for a country with 42 counties**, Brașov spread across 11 of them, and a browse facet where picking "Cluj" missed every posting filed under `CLUJ-NAPOCA, Cluj`. The home page tile read "197 JUDEȚE".

**`webapp/apps/jobs/judete.py`** is now the single place that interprets a raw county string. It holds the canonical 42 (comma-below diacritics), folds the Turkish cedilla ş/ţ that Windows-era text uses onto the correct ș/ț, and `normalize_judet()` returns `(county, locality)` — so the city half that used to be welded into the county name is kept rather than discarded. Localities are title-cased Romanian-aware ("BAIA DE ARAMĂ" → "Baia de Aramă", particles stay lowercase; already-mixed-case input is left alone). All 261 raw values resolve; none needed an alias.

**Where it runs:**
- `import_csvs` normalises on the way in, so new data can no longer fragment. It reports canonical-vs-source counts and warns loudly on any value it cannot match.
- `manage.py normalize_judete` repairs what was stored: 261 → 42 rows, 4,940 postings re-pointed, 219 stale rows deleted, 17 clean slugs reclaimed from rows that had been squatting on them. `--dry-run` shows the split first.

**A bug I introduced and caught:** the first backfill run reported "1,838 localities backfilled" and the second reported "0 changed" — while silently erasing all 1,838. The locality is derived from the *pre-merge* `Judet.name`, which the command itself deletes, so on a second run every posting resolved to `locality=None` and got written back as empty. The counters only tracked *gains*, so nothing showed it. Fixed two ways: the command now only ever fills a locality in, never clears one (corrections belong to `import_csvs`, which reads the authoritative CSV), and the summary reports rows actually written, not just rows improved. Verified idempotent.

**New fields.** `JobPosting.locality` (1,838 postings, 198 distinct localities) and `JobPosting.judet_raw`, which holds the source string *only* when the county could not be matched — so `judet_raw != ""` is the "needs attention" filter. Migration `0009`, both indexed.

**Flagging, as asked.** Three layers, because a silent county failure is exactly the class of bug that went unnoticed for five weeks with `expires_at`:
1. `import_csvs` prints a warning listing the unmatched values.
2. `judet_sanity_warnings()` runs on every import next to the expiry check — fires if the Judet table exceeds 42 rows, or if any posting has an unresolved county — and honours `--strict` for cron.
3. Admin gained a *Județ — rezolvare* filter (Nerecunoscut / Lipsă / Cu localitate / Fără localitate), plus `locality` in the list display and both fields in the edit form. The usual fix for an unmatched value is one line in `judete.ALIASES`.

**Downstream.** `export-to-sqlite.py` exports `locality` (indexed), and the FTS location column is now `locality || ' ' || judet_name`, so "cluj napoca" finds 102 postings that county-only search missed. In `webapp-php/`, a new `place_label()` renders "Cluj-Napoca, Cluj" on result rows, the detail header and employer profiles, and the JSON-LD carries a real `addressLocality` — which is a genuine Google Jobs improvement, not just cosmetics. The județ facet lists all 42 counties by proper name (`București`, not `bucuresti`) and is no longer capped at 25, since the cap was what made a selected value outside the top window disappear.

**Tests:** 31 in `webapp/tests/test_judete.py` (county list integrity, diacritic repair, folding collisions, title-casing, both badge shapes, county capitals keeping their locality, unknown values staying unresolved, idempotency) and 6 more for `judet_sanity_warnings`. Full suite: 125 passed.

**Also filed:** a backlog item for rendering expired postings as a real "anunț expirat" page with a similar-jobs block instead of a 404 — blocked on the `--active-only` export decision, which this work makes more pressing now that the status control has a "Toate" option and the sitemap points crawlers at postings that will expire.

### 2026-09-07 — UX pass on the PHP webapp: mobile, search, filter chips, SEO, accessibility

**What:** Worked the `docs/backlog.md` "UX / UI" section against `webapp-php/` (the deployed app). Django templates under `webapp/templates/jobs/` were deliberately left alone this pass — they now diverge, and porting is a separate task.

**Two pre-existing bugs found while implementing, both worse than anything on the backlog list:**

1. **Multi-select facets only ever applied their last value.** Sidebar checkboxes were named `judet`, not `judet[]`. A browser serialises repeated checkboxes as `judet=cluj&judet=iasi`, which PHP collapses to `'iasi'` — so the moment any filter change went through HTMX, a three-county selection silently became one. Only the initial page load (where links were written with `judet[]=`) ever filtered correctly. Fixed with `param_field()` + a `MULTI_PARAMS` list in `helpers.php`; the chip-removal JS matches both the bare and bracketed name.

2. **Search was phrase-only, so most multi-word queries returned nothing.** `fts_escape()` wrapped the whole query in double quotes, making it an FTS5 phrase match: `inspector primărie` returned 0 where the AND-ed terms return 22. Replaced with `fts_query()` — tokenises on non-alphanumerics, quotes each term, ANDs them, and adds a prefix wildcard to the trailing term so the 400 ms live search doesn't flash "no results" mid-word. Ranking moved from bare `rank` to `bm25(job_postings_fts, 10, 3, 1, 1)` so a title hit outranks a passing body mention. Returns `''` for term-less input, and `build_filters()` now refuses to claim FTS in that case (an empty MATCH expression is a SQLite error).

   A third, smaller one: the județ facet lists the top 25 of ~197 slugs, so a selected value outside that window had no checkbox and was dropped on the next form submit. `facet_group()` now pins any active-but-unlisted value to the top of its group.

**Mobile.** The search input moved out of the sidebar into a full-width bar that renders at every width. The facet column became one element that is a sticky sidebar at `lg` and a right-hand slide-over drawer below it — backdrop, Escape, focus return, scroll lock, an "Arată N rezultate" footer button, and a badge on the trigger showing the active-filter count. The count, the badge and the screen-reader live region update through `hx-swap-oob` rather than JS. Inner per-facet scrollers are now `lg:`-only: nested scrolling inside a drawer is hard to aim at. On phones the KPI tiles reorder below the search form (CSS `order`) so search is reachable without scrolling. Job detail's structured column no longer hides below 640px — it stacks above the body as a two-column card, with the deadline and contact first, and the duplicated `sm:hidden` fallback block is gone.

**Filters.** Chips are generated from one `FILTER_CHIP_GROUPS` map covering all 18 params instead of 3, with per-key value labels (județ slug → county name, bucket keys → their labels). `qs_without($key, $value)` drops a single value where `qs_with($key, null)` dropped the whole key. Chips are HTMX now, not full page loads: a click handler unchecks the matching control and re-fires the form, so the sidebar and the URL stay in agreement; the `href` remains the no-JS fallback. Facet groups became `<details>` (native keyboard + AT behaviour), open for the top four, with Salariu / Angajator / Anomalii / exact dates behind a "Mai multe filtre" disclosure and open state persisted in `localStorage`. A segmented **Active / Expiră în 7 zile / Toate** control with live counts replaces the date pickers as the primary deadline control; `active` is the new default, which is a no-op on the active-only deploy but matters once the archive ships.

**Empty state.** On zero results the page now runs one COUNT per active filter and offers the two or three whose removal recovers the most postings ("Încearcă fără — Caută: zzzz +6"). Only computed on the zero-results branch.

**SEO.** `JobPosting` JSON-LD on every detail page, built from the columns plus the Schema.org-shaped `schema_json` the v2 prompt already produces — all six Google Jobs required properties present. Added `/robots.txt` and `/sitemap.xml` (2,900 URLs: statics, every posting newest-first, and only employers that actually have a posting in the export). Canonical, description, and OpenGraph tags in `inc/header.php`, with canonical stripping the query string so filter permutations fold into one indexable URL. Detail URLs are now `/job/1234-slug/`; `/job/1234/` and stale slugs 301 to the canonical form, and the id stays in front so lookup is still a primary-key hit. `javascript:history.back()` is gone — the back link points at the referring filtered list when there is one, else `/`.

**Assets.** Replaced the `cdn.tailwindcss.com` play script with a real build: `package.json` + `tailwind.config.js` at the repo root, source in `webapp-php/assets/app.css`, output committed to `webapp-php/static/app.css` (27 KB minified) so the shared host needs no toolchain. Fonts and htmx are self-hosted; DM Sans italic was dropped (114 KB for the odd `<em>` — synthetic oblique covers it). `.htaccess` gained immutable cache headers, deflate, a woff2 mime type, and a deny for the sqlite journal sidecars. `deploy-php.sh` refuses to run if `static/app.css` is missing and excludes `assets/` and the dev-only `router.php`.

**Accessibility.** `ink-muted` and `ink-faint` were 4.23:1 and **2.22:1** on parchment — both under AA. Darkened to #625C56 / #6F6963 (5.81 / 4.78) and added a separate `border-input` at 3.12:1 for form controls, since WCAG 1.4.11 applies to controls but not to decorative hairlines. Plus: skip link, labelled search input, `role="status"` live region announcing the result count on every swap, `aria-label` on the pagination nav and `aria-current` on the active page, `<ul>/<li>` for the result list, `<time datetime>` on dates, and tap targets raised to ≥24 px on everything that isn't an inline prose link. Romanian day pluralisation is correct now (`1 zi` / `7 zile` / `32 de zile`), and the h1 subtitle shows the corpus total instead of the filtered count, which used to read "0 anunțuri indexate" on a failed search.

**Verified** with Playwright at 375 / 768 / 1280 / 1440 px: no horizontal overflow anywhere, drawer open/close/escape/backdrop/scroll-lock, multi-select surviving a round-trip, per-value chip removal, out-of-window value pinning, status control by mouse and keyboard, HTMX pagination, `localStorage` persistence across reloads, slug redirects, JSON-LD completeness, and zero JS errors. All routes and all three feeds return 200; no PHP warnings in the log.

**Not done:** dark mode and RO/EN remain open, as does the landing page rework — the domain tiles it needs are blocked on the inference backfill. The Django templates now lag the PHP app.

### 2026-09-06 — Re-exported the deploy SQLite; live site found five weeks stale; UX audit

**What:** Regenerated `webapp-php/posturi.sqlite` after the expiry fix, checked what posturi.gov2.ro is actually serving, and audited the PHP webapp for UX gaps. No application code changed — findings went to `docs/backlog.md`.

**Re-export:** the deploy artifact still held the pre-fix export (3 postings, 1.1 MB, written 2026-09-06 01:16 — the expiry fix landed after it and the export was never re-run). `export-to-sqlite.py --active-only` now writes 1,656 postings / 559 calendar events / 1,038 employers / 257 judete, 40 MB, FTS rebuilt. Verified every exported row has `expires_at` between 2026-09-07 and 2026-12-22 — no expired rows leaked through.

**Live site:** `https://posturi.gov2.ro/` returns 200 but serves **14 postings, 3 active**, header "actualizat 02.08.2026". It is not even running the 3-row September export — it is on an August 2nd database. Nothing was deployed; the target host is not in the repo (no `DEPLOY_HOST` in `.env`, no match in `~/.ssh/config`). Left as a backlog item at the user's request.

**Two problems the fresh export surfaced:**

1. **Inferred facets are empty on live data.** 2,290 postings have `inferred = {}`, and since the deploy is active-only those are almost exactly the shipped rows. On the 1,656 active postings: profession_family 23, studii 22, work_type 4, seniority 3, experiență 3, telemuncă 1, anomalii 0. Seven of the sidebar's 13 facet groups are `inf_*`-driven, so the live sidebar would collapse to five working facets with "Domeniu" near-empty at the top. `facet_group()` skips empty groups, so it disappears silently rather than rendering broken. Not blocked by the dead LLM providers — `infer_postings --no-llm` runs the dictionary pass offline; the caveat is that it writes `altele`/0.0 for unmatched titles, taking them out of `inferred = {}` so a later LLM pass needs `--force`.

2. **`judet_name` mixes two formats, splitting every county in the Județ facet.** Post-redesign rows store `"CITY, Județ"`, pre-redesign rows the bare county, and they become separate `judete` rows with separate slugs. Cluj is listed as 7 separate facet entries. 976 active rows are city-comma format, 680 bare — 257 `judete` rows for a country with 42 counties. This makes one of the five *still-working* facets wrong.

**UX audit (`webapp-php/`, all findings also present in `webapp/templates/jobs/` since the PHP app was ported from them):** the headline is that below 1024px there is no search box and no filters at all — the sidebar is `hidden lg:flex` and the `q` input lives inside it, with a mobile fallback that reads "Deschide pe desktop pentru filtre complete." Job detail hides its whole structured column (deadline, contact, attachments) below 640px. Filter chips cover 3 of 15 params and their remove links drop every value of a key rather than the clicked one. Plus: Tailwind Play CDN in production, `javascript:history.back()` as the detail back link, no `aria-live` on the HTMX-swapped results, and no dark mode or RO/EN despite both being in the v1 spec. Written up as a new "UX / UI" section in the backlog with file:line references.

**Not done (deferred by the user):** the deploy, and the `infer_postings --no-llm` run.

---

### 2026-09-06 — Post-import sanity check for expiry parsing

**What:** Added a guard so a repeat of the expiry outage above surfaces on the next import instead of five weeks later.

**Changes:**
- `expiry_sanity_warnings(active, recent_total, recent_without_expiry)` in `import_csvs.py` — a pure function (hence testable without a DB) returning one warning per problem. Two checks, both **ratios rather than absolute floors** so they stay meaningful as the dataset grows: (1) >50% of postings published in the last 30 days missing `expires_at` → date parsing is probably broken; (2) zero active postings while recent postings exist → an active-only export would ship an empty site.
- Called at the end of `handle()`; prints a `Sanity:` line every run, writes warnings to stderr, and with the new `--strict` flag raises `CommandError` so cron/CI fails loudly.
- `webapp/tests/test_expiry_sanity.py` — 11 tests. Includes a regression test pinning the real outage numbers (`active=3, 2295/2295 missing`), the countdown strings that caused it, the ISO parse branch, and the `2046` typo rejection. Also asserts the checks stay silent on an empty DB and at 50% missing, so they don't cry wolf.

**Verification:** 88 tests pass (77 + 11 new). Against live data the guard is silent (`active=1656, 21/1917 missing`); replaying the pre-fix numbers fires the expected warning. Not exercised via a full `import_csvs` run — the v2 backfill was mid-flight and the `search_vector` rebuild would contend with it; the function was validated directly against live counts instead.

---

### 2026-09-06 — Fix: `expires_at` NULL for every post-redesign posting (live site showed 3 jobs)

**What:** `expires_at` had been NULL for 3,103 of 9,377 postings — including all 2,295 published on/after 2026-08-01 — leaving `export-to-sqlite.py --active-only` (`WHERE jp.expires_at >= CURRENT_DATE`) with 3 rows to deploy.

**Root cause:** the redesigned site shows a *relative countdown* on index cards instead of a date. `expira_in` now holds `"1 zi rămasă"` (238), `"N zile rămase"` (766), `"Ultima zi"` (293) or `"Anunț anulat"` (79); only the 6,274 pre-redesign `/anunt/` rows still carry `Expiră in DD/MM/YYYY`. `import_csvs.py` fed that straight into `parse_date()`, which returned `None`. The Aug 1 scraper rewrite fixed the fetch/parse side but this mapping was never revisited — and the detail page's absolute date was already being captured as `Data Expirare` in `anunturi.csv` (9,243 usable values), just ignored by the importer.

**Changes** (`webapp/apps/jobs/management/commands/import_csvs.py`):
- `parse_date()` gained a `YYYY-MM-DD` branch — it handled `DD.MM.YYYY`, `DD/MM/YYYY` and `9 septembrie, 2024`, but not the ISO dates `parse-anunturi.py` writes, so `Data Expirare` was unparseable even where read.
- New `expires_by_url` map built from `anunturi.csv` before Pass 1; `expires_at` now resolves detail-first, index-`expira_in` second. The join is on `Source URL`, so it covers the 3,000 `/joburi/` rows and leaves the old `/anunt/` rows on the index path.
- New `plausible_expiry()` rejects years outside `2000..today.year + 2`, applied to **both** sources. Catches upstream typos like `Expiră in 14/01/2046` on a posting published 2025-12-23, which would otherwise read as permanently open. Rejections are reported in the command output rather than dropped silently.

**Result:** NULL `expires_at` 3,103 → 80 (79 `Anunț anulat`, correctly excluded, + 1 typo'd row); active postings 3 → 1,656; `max(expires_at)` 2046-01-14 → 2026-12-22. `export-to-sqlite.py --active-only` now exports 1,656 postings / 559 calendar events. 77 tests pass; two consecutive imports produce identical counts.

**Not done:** the same countdown string still makes `compare_and_update()` in `fetch-index.py` log a spurious `expira_in` change for every posting every day (12 entries on one sampled row in 18 days), inflating `updates_raw`, feeding noise into `JobPostingUpdate`, and defeating the skip-unchanged-page save optimisation. Left as an open backlog item.

---

### 2026-08-02 — PHP webapp packaging: active-only SQLite export + streamlined deploy

**What:** Refactored the `export-to-sqlite.py` → `deploy-php.sh` packaging so the PHP webapp folder is fully self-contained with an active-only database.

**Changes:**
- `export-to-sqlite.py`: Added `--active-only` flag that filters `WHERE expires_at >= CURRENT_DATE` on both `job_postings` and `calendar_events` queries in PostgreSQL, keeping the exported SQLite small and focused. Default `--out` path changed to `webapp-php/posturi.sqlite`.
- `deploy-php.sh`: Uses `--active-only` and explicit `--out` path; removed separate DB rsync step (DB is inside `webapp-php/` now). Added `--no-perms --no-owner --no-group --omit-dir-times` flags to rsync for shared-hosting compatibility.
- `webapp-php/db.php`: Removed parent-directory fallback — DB lives at `__DIR__/posturi.sqlite` only.
- `.gitignore`: Added `webapp-php/posturi.sqlite`.

**Why:** The full data archive stays in PostgreSQL; the deployed webapp only needs active postings. Packaging the SQLite inside `webapp-php/` makes the folder self-contained — one rsync deploys everything.

---

### 2026-08-02 — Rebrand to posturi.gov2.ro, WIP/alpha banners, last-updated date

**What:** Updated all PHP webapp branding from "posturi.gov.ro" to "posturi.gov2.ro" with alpha/WIP disclaimers throughout.

**Changes:**
- Header: logo reads `posturi.gov2.ro`, tagline `alpha · WIP`
- Yellow dismissable banner on every page: "WIP / MVP — versiune în lucru. Nu este un proiect oficial al Guvernului României." with link to Google Form feedback
- Footer: same WIP disclaimer + feedback link
- `MAX(last_seen_at)` queried once in header.php, displayed in both header and footer as "actualizat DD.MM.YYYY"
- Page titles, feed metadata (Atom, iCal, JSON) updated throughout
- about.php: simplified for alpha launch, added feedback link

**Why:** Preparing for public alpha. Clear disclaimers that this is unofficial, with a feedback channel.

---

### 2026-08-02 — Fixed export-to-sqlite.py: psycopg2 → psycopg3

**What:** `export-to-sqlite.py` imported `psycopg2` but the project venv only has `psycopg` v3 (3.3.4). The import error caused the export step to fail silently, leaving a stale SQLite.

**Fix:** Switched imports and API calls:
- `import psycopg2` / `psycopg2.extras` → `import psycopg` / `psycopg.rows.dict_row`
- `psycopg2.connect(...)` → `psycopg.connect(..., row_factory=dict_row)`
- `pg.cursor(cursor_factory=DictCursor)` → `pg.cursor()` (row_factory set on connection)

---

### 2026-08-01 — Rewrote scraping pipeline for redesigned posturi.gov.ro

**What:** Rewrote `fetch-index.py`, `fetch-anunturi.py`, and `parse-anunturi.py` to work with the completely rebuilt posturi.gov.ro website (WordPress + Astra 4.12.3 + Elementor + custom "PG" plugin).

**Changes:**
- `fetch-index.py`: New base URL, listing URL (`/toate-posturile/?pg_page=N`), pagination (`nav.pg-arc-pagi`), and all selectors rewritten for `article.pg-card` → `div.pg-card-h`, `div.pg-card-inst`, `a.pg-card-link`, `span.pg-tag`, `div.pg-card-deadline`, `div.pg-card-published`, `div.pg-card-city span`.
- `fetch-anunturi.py`: Updated `base_url` to HTTPS, `parse_romanian_date()` now handles the new `Data publicării: DD.MM.YYYY` format, `extract_main_content()` targets `div.pg-wrap.pg-job-single`.
- `parse-anunturi.py`: Dual support for old (`/anunt/`, `.titlu h1`, `.caseta .ang`) and new (`/joburi/`, `h1.pg-title`, `.pg-contact-row`, `.pg-prose`, `a.pg-btn-pill`) HTML structures. Auto-detection via `.pg-title`/`.pg-jobcard` presence. Structured contact extraction from `.pg-contact-row` elements. `_try_index_lookup()` falls back between old/new URL schemes for index CSV date matching.

**Why:** The site was rebuilt entirely — every selector, URL pattern, and data structure changed. The old `/page/N/` listing, `article.box` cards, and `/anunt/{slug}/` detail pages no longer exist.

**Backward compatibility:** `parse-anunturi.py` handles both old and new HTML, so the existing 6,275 cached detail pages don't need re-fetching.

---

### 2026-05-28 — Survey: scanned-PDF frequency in attachments

**What:** Ran a pypdf-based survey across all 750 PDFs in `data/downloads/`. For each file, extracted text from the first 3 pages and classified as "text-extractable" (≥50 chars), "likely scanned" (< 50 chars but file > 30 KB), or other.

**Results:**
- Text-extractable: **361 / 750 (48.1%)**
- Likely scanned (image-only): **389 / 750 (51.9%)**
- Parse errors: 0 (pypdf handled all files, though many had malformed xref tables)

**Why:** Quality check showed that the OCR fallback (poppler + tesseract) in `quality_check.py` fired correctly on at least one known scanned PDF. This survey quantifies the scope — just over half the attachments need OCR to be readable.

**Next step (backlog):** Run OCR backfill on the 389 scanned PDFs, store extracted text in `data/attachments_text/<slug>.txt`, and feed into quality_check.py and potentially the LLM extraction pipeline.

---

### 2026-05-28 — LLM variants dashboard: quality metrics added

**What:** Extended `/llm-variante/` with three quality signals per (provider, model, prompt_version):

- **Completitudine** — avg % of 10 key fields filled across all runs. Color-coded: green ≥70%, amber ≥50%, red <50%.
- **Dezacord** — for postings with ≥2 variants at the same prompt_version, % of field-comparisons where this model is the minority (has value when others don't, or null when others do). Computed on 6 multi-model postings at v2.
- **Solo** — count of times this model is the *only* one with a value for a field (hallucination proxy).

A collapsible **Completitudine per câmp** section shows a per-field heatmap (each cell = % fill rate for that field/model combination).

**Notable findings from live data (v2, 6 comparable postings):**
- `gpt-5-nano`: highest completeness (63%) but also highest disagreement (18%) and 7 solo values — most likely to hallucinate
- `gemini-2.5-flash` (production, 380 runs): 46% completeness, 5% disagreement, 1 solo — consistent but often leaves `responsibilities` and `skills` empty
- `deepseek-v4-flash`: 55% completeness, 0% disagreement, 0 solos — most conservative
- `baseSalary`: 0% across all models (good — models aren't guessing salary when absent)
- `responsibilities`: 0% for deepseek, 0% for gpt-4o-mini, 10% for gemini, 83% for gpt-5-nano

**New filter `dict_get`** added to `jobs_extras.py` for dynamic dict key lookup in templates.

---

### 2026-05-28 — LLM variants dashboard (/llm-variante/)

**What:** New page at `/llm-variante/` showing a per-(provider, model, prompt_version) leaderboard table with: run count, average cost (¢/post), total cost ($), average latency (ms), average input/output token counts. Rows are grouped by prompt version. Nav link "LLM" added to the site header.

**Why:** After running llm-schema.py `--compare` across multiple models, there was no quick way to see relative cost/speed without querying the database manually. The dashboard shows what data exists and serves as a backfill progress indicator.

**Implementation:** `llm_variants_dashboard` view aggregates `JobPostingSchemaVariant` with `values("provider", "model", "prompt_version").annotate(count, avg_cost, total_cost, avg_latency, avg_input, avg_output)`. Template uses `regroup` tag to group by prompt version. Renders gracefully with a "no variants yet" message when the table is empty.

---

### 2026-05-28 — "Nu este cazul" experience flag in inference pipeline

**What:** `_infer_experience` in `infer_postings.py` now returns a `(experience_years, no_experience_required)` tuple. When the posting body explicitly states that no experience is required ("nu este cazul", "nu se solicită experiență", "fara experienta in munca", etc.), it returns `(0, True)` instead of `(None, False)`. A numeric year count always takes precedence over the no-experience phrases. The `no_experience_required` flag is stored in the `inferred` JSONB column alongside `experience_years`.

**Why:** Postings with "nu este cazul" were previously indistinguishable from postings that simply didn't mention experience. The flag enables filtering for true entry-level positions.

**Tests:** 7 new tests in `TestInferExperience` class (77 total, all passing).

---

### 2026-05-28 — HTML sanitization of rendered Markdown output

**What:** Added `nh3` (Rust-backed HTML sanitizer) to the webapp. All Markdown-rendered HTML now passes through `_sanitize()` before reaching templates, covering both `body_html` in `job_detail` and all `schema_json` sections in `_render_schema_sections`.

**Why:** The `body_markdown` field and `schema_json` section values both originate from external data (scraped HTML converted to Markdown, then LLM-transformed). Passing rendered output through a tight allowlist (block/inline/table/link tags; no iframes, scripts, forms, event handlers) eliminates the XSS vector if either source is ever compromised or contains injected content.

**Allowlist:** `_ALLOWED_TAGS` covers `p br ul ol li strong b em i h1–h6 table thead tbody tr th td blockquote pre code a`; `_ALLOWED_ATTRS` limits `a[href, title]`, `td/th[colspan, rowspan]`. `nh3` added to `requirements.txt`.

---

### 2026-05-28 — Job condition attributes: extraction, badges, detail grid, browse facets

**What:** Added a new Layer 3 extension to the inference pipeline and surfaced the extracted data across the UI.

**Inference (`infer_postings.py`):**
- Four new helpers: `_infer_work_type` (norma_intreaga / norma_partiala / schimburi, with confidence scoring), `_infer_remote` (telemuncă/remote/hibrid → `true` or `null`), `_infer_computer` (regex + skill cross-check → `true/false/null` + `basic/advanced`), `_extract_salary_range` (denormalizes `schema_json.baseSalary.minValue/maxValue`).
- New `--conditions-only` flag re-runs just these fields without touching `profession_family`, `seniority`, or anomaly flags — safe for backfill.
- All fields stored in existing `inferred` JSONB column (no migration). `conditions_inferred_at` timestamp marks which postings have been processed.

**New management command (`infer_conditions_llm.py`):** LLM queue for postings where `work_type` confidence is 0.0 and body ≥ 250 chars. Sends a minimal 3-field prompt (work_type / remote_eligible / requires_computer), updates `inferred` in-place.

**Browse view (`views.py`):** `_apply_filters` extended with 6 new params (`work_type`, `remote`, `computer`, `exp_level`, `studies_level`, `salary_bucket`). Matching facet count queries added. Salary facet conditionally shown only when ≥ 100 postings have salary data. Salary and experience are bucketed.

**Result row badges:** New condition pill row below existing badge row — shows normă, telemuncă, calculator, experience when non-null.

**Detail page grid:** "Condiții la locul de muncă" 6-tile grid (program / telemuncă / calculator / experiență / studii / salariu) inserted before the schema sections. Renders only when ≥ 2 attributes are non-null; null attributes show `—`.

**Sidebar facets (list.html):** 6 new facet groups in order: Tip normă, Experiență minimă, Studii minime, Calculator, Telemuncă, Salariu (conditional).

**Tests:** 32 new tests in `test_condition_inference.py` covering all 4 helpers + 5 view integration tests. Full suite: 70/70 passing.

**Why:** Users had no way to filter by work schedule, remote eligibility, or computer requirements without reading every posting in full. The hybrid approach (local regex for ~85% coverage, LLM queue for the rest) keeps cost near zero for the bulk of postings.

---

### 2026-05-27 — LLM comparison analysis document

**What:** Wrote `docs/llm-comparison-analysis.md` — a standalone analysis of the 4-model benchmark (GPT-5 Nano, Gemini 2.5 Flash, DeepSeek-V4-Flash, GPT-4o Mini) run with prompt v2 on 5 postings.

Covers: cost per 1,000 posts, cache hit rates, structured-output API differences per provider, quality delta v1→v2, and a ranked recommendation (GPT-5 Nano first, Gemini fallback).

---

### 2026-05-27 — quality_check.py: OCR fallback, attachment-title mismatch detection, body-duplication splitter fix

**What:** Three targeted fixes to `quality_check.py` following a `/quality-review` session.

1. **OCR fallback for scanned PDFs** (`_extract_pdf`): if `pypdf` returns empty text and the file is > 50 KB, falls back to `pdftoppm -r 150 -png` (poppler) + `tesseract -l ron`. Tested: `eb59d7d2.pdf` (Consilier IA, Casa de Pensii Bucuresti) went from 0 → 9,878 extracted chars; content confirmed correct (Romanian-language OCR via `ron` model).

2. **Attachment-title consistency check** (`_attachment_title_mismatch` + `_infer_anomaly_flags`): compares ≥ 2 meaningful title words (len ≥ 4, filtered by stop-word list) against the first 2,000 chars of the attachment text. If < 2 hit, emits `attachment_title_mismatch` anomaly flag. Correctly fires on `79a59ec1.doc` (nursing exam curriculum attached to an inspector/fochist posting). Verified live via Playwright that the wrong file is served by posturi.gov.ro itself — not a pipeline bug. `anomaly_score` denominator raised from 4 → 5.

3. **Body duplication splitter** (`_check_body_duplication`): added a pre-normalisation step that converts Markdown trailing-space hard-breaks (`  \n`) to double-newlines before paragraph splitting. Previously the regex `\n{2,}` couldn't split Markdown-formatted bodies, leaving the entire body as one paragraph and making the check unable to fire.

**Why:** The quality report showed `body_duplication_rate = 0.0` for all postings despite visible repeated paragraphs in several bodies; `eb59d7d2.pdf` was reported as `empty` despite being a real 742 KB document; and the attachment-mismatch bug (wrong file at source) had no detection path.

### 2026-05-27 — Prompt v2: Schema.org-aligned extraction with caching, structured output, and boilerplate stripping

**What:** Rewrote the LLM-extraction pipeline for higher quality + lower cost. Output is now a superset of Schema.org JobPosting properties (flat keys named after JobPosting.responsibilities/educationRequirements/experienceRequirements/qualifications/skills/baseSalary/jobBenefits/workHours/jobLocation) plus three RO-specific custom keys (application_docs, application_fee, application_contact). Education and experience are now split out from the bundled-in-v1 `qualifications`; `baseSalary` and `application_fee` are structured `{minValue, maxValue, currency, unitText}` / `{amount, currency, account, details}` objects; `application_contact` captures the submission contact separately from the top-level CSV contact.

Three new pieces, plus a restructure of `llm-schema.py`:
- **`schema_models.py`**: Pydantic `JobPostingExtraction` model. Single source of truth fed to each provider's native structured-output API (OpenAI strict json_schema, Gemini response_schema, Anthropic tool-use input_schema, DeepSeek loose json_object). `openai_json_schema()` mutates the Pydantic-generated schema to satisfy OpenAI strict mode (additionalProperties=false, required-lists-every-property).
- **`boilerplate.py`**: `strip_hg_1336(text)` removes lines matching ~25 patterns covering the generic HG 1.336/2022 / Codul muncii / OUG 57/2019 art. 542 eligibility text that appears on nearly every posting (cetățenia română, capacitate de muncă, condamnări, pedepse complementare, clauze de confidențialitate, condițiile generice de studii/vechime etc.). Diacritic-tolerant; preserves the art. 35 dosar list (which IS the `application_docs` content). Measured 13–25% input-length reduction on 6 sampled postings; quality benefit (qualifications no longer drowning in boilerplate) is the bigger win.
- **Prompt v2** in `models_config.json`: hybrid English imperative + Romanian vocabulary anchors (`atribuții`, `vechime în specialitate`, `taxa de concurs`, `dosar de candidatură` etc.), 2 few-shot examples (clean salary + attachment-only contact), explicit boilerplate-skip rule, field-by-field guidance with anti-patterns (e.g. "never set baseSalary for taxa de concurs").
- **`llm-schema.py` refactor**: `make_generator(provider, model, system_prefix, prompt_version)` splits the static prompt prefix (cacheable) from the per-posting content (variable). Provider-side caching wired up: Anthropic explicit `cache_control:{type:ephemeral}` on the system block, OpenAI/DeepSeek implicit (consistent system message + >1024 tokens), Gemini via `system_instruction`. Structured output via each provider's native API for v2; legacy ad-hoc JSON parsing kept for v1. `compute_cost()` now accepts `cached_input_tokens` and uses the model's `cache_input_cost_per_million` rate when defined.

Also fixed: `models_config.json` Gemini model IDs (dots not dashes — `gemini-2.5-flash` not `gemini-2-5-flash`); GPT-5 family needs `max_completion_tokens` and `reasoning_effort=minimal` (default reasoning eats the completion budget on extraction tasks).

**Detail page (`webapp/apps/jobs/views.py`)**: `_SCHEMA_SECTION_LABELS` extended with the new v2 keys (+ corresponding Romanian labels: Studii, Experiență, Beneficii, Program de lucru, Locație, Contact pentru depunere). Three small render helpers `_render_base_salary`, `_render_application_fee`, `_render_application_contact` convert the structured dicts to markdown. Back-compat: v1 string-shaped values fall through the markdown path unchanged, so the existing 22 postings with v1 `schema_json` keep rendering.

**Verification:**
- 14/14 renderer tests pass (`webapp/tests/test_schema_detail.py`); covers v1 back-compat, v2 structured fields, and the three helper functions.
- `boilerplate.py` smoke-tested against 6 real postings (ids 4437, 4509, 4590, 4897, 6717, 7519, 8268, 8694): role-specific content survives, HG 1.336 boilerplate removed, dosar list (application_docs) preserved.
- `llm-schema.py --compare --prompt-version v2 --limit 5 --force` on all 4 enabled models (gemini-2.5-flash, gpt-5-nano, gpt-4o-mini, deepseek-v4-flash): all outputs validate against the Pydantic schema; cache hits visible from the second call (~94% on gpt-4o-mini, ~99% on deepseek). Per-1000-postings cost: gpt-5-nano $0.36, gemini-2.5-flash $0.61, deepseek $0.70, gpt-4o-mini $0.88 — so the full 4357-posting backfill costs under $4 on any provider. Sample output for posting 4437 (muncitor calificat) shows `qualifications` shrunk from ~2300 chars of HG 1.336 boilerplate (v1) to a single 76–295-char role-specific line (v2: "Nivel de acces la informații clasificate: Secret"), with `educationRequirements`/`experienceRequirements`/`application_contact` cleanly populated.

**Why:** v1's `qualifications` was 80% legal-citation boilerplate on every posting — inflating output cost, hurting search relevance, and obscuring the role-specific signal in the UI. Splitting education + experience and structuring `baseSalary` unlocks future filters (salary range, min-experience). The Schema.org-aligned key names mean we can wrap the JSON in `@context/@type` at render time for JSON-LD / Google for Jobs SEO without changing the LLM pipeline.

**Next:** promote v2 to default once spot-checked in the UI: change `PROMPT_VERSION = "v2"` in `llm-schema.py`, run `python llm-schema.py --provider gemini --prompt-version v2 --force` to backfill the remaining 4357 postings.

### 2026-05-27 — LLM provider comparison infrastructure (with model enable/disable + prompt versioning)

**What:** Built end-to-end framework for comparing LLM providers and prompts on the same job postings without overwriting production schema:
- **Config-driven models + prompts** (`models_config.json`): 
  - Defined 6 enabled models across 4 providers (Gemini 3.1/2.5 Flash, GPT-5 Nano, GPT-4o Mini, Claude 3.5 Haiku, DeepSeek-V4-Flash) with input/output costs per million tokens
  - 2 disabled models (Claude 3 Haiku, GPT-4o) kept in config for reference
  - Prompts versioned (`v1`, etc.) centralized in config; loaded per-run via `--prompt-version`
- **Variant storage** (`JobPostingSchemaVariant` model, migration 0007): Captures provider, model, prompt_version, schema output, token counts, cost (computed), latency_ms, created_at. Unique constraint on (posting, provider, model, prompt_version) for A/B testing multiple prompt versions.
- **Refactored llm-schema.py**: 
  - Token capture from each provider's SDK response; `compute_cost()` for real USD calculation
  - `get_enabled_models()` to respect enabled flag; skip disabled models in `--compare`
  - `get_prompt(version)` to load prompts from config dynamically
  - New flags: `--model-filter <regex>` for testing subsets (e.g., `gemini-.*`, `gpt-.*`), `--prompt-version <v>` for prompt A/B testing
- **Variant comparison view** (`/job/<id>/variants/`): Side-by-side display with cost/latency highlighting, full schema preview, prompt version visible.
- **Admin + UI**: JobPostingAdmin inline display; standalone variant browser; job detail "Dev → Comparație LLM" link.

**Usage examples:**
```
python llm-schema.py --compare --limit 10                              # all enabled models, prompt v1
python llm-schema.py --compare --model-filter "gemini-.*" --limit 5    # test only Gemini
python llm-schema.py --compare --prompt-version v2                     # compare all with prompt v2 (when v2 exists)
python llm-schema.py --model-filter "gpt-.*" --prompt-version v2 --limit 3  # GPT models + prompt v2
python llm-schema.py --provider anthropic                              # single provider (production mode)
```

**Why:** (1) Choose best LLM provider by cost/quality on real postings. (2) Test prompt improvements without running all providers. (3) Disable expensive models (GPT-4o) or older ones (Claude 3) without deletion. (4) A/B test prompt v1 vs v2 across providers.

**Next:** Run `python llm-schema.py --compare --limit 100` to benchmark, then consider adding prompt v2 (stricter/more detailed) to config for comparative testing.

### 2026-05-27 — Structured job detail display from schema_json

Added `schema_json` JSONField to `JobPosting` (migration 0006). Rewrote `llm-schema.py` to extract 7 structured display sections (responsibilities, qualifications, skills, application_docs, salary, application_fee, work_conditions) from `body_markdown + attachment_text` via LLM, storing results in `jobs_jobposting.schema_json` directly via psycopg. Added `schema` pipeline step. Detail page now renders named structured sections instead of raw markdown blob when `schema_json` is populated, with fallback to `body_html`. Calendar reordered to appear after body content.

### 2026-05-27 — Stats dashboard at /statistici/

**What:** Added a server-rendered stats page accessible from the nav:
- KPI tiles: total postings, active count (14%), auto-classified count (61%)
- Horizontal bar charts for top 10 profession families and top 10 județe; each bar label links to the browse view pre-filtered
- Anomaly table: all 6 flags with count, % of total, and a "filtrează →" link
- Refactored `stats_json` logic into `_build_stats()` shared helper; `stats_json` now calls it; `stats_dashboard` view also calls it and enriches the data for display
- `by_judet` query now also returns `judet__slug` so the judet links in both dashboard and JSON are correct
- "Statistici" nav link added to `base.html` header

### 2026-05-27 — FTS search_vector extended with attachment_text (weight D)

**What:** Extended the full-text search index to include attachment content:
- `import_csvs.py` `search_vector` SQL now includes `coalesce(j.attachment_text, '')` at weight D (lowest weight, after title A, employer B, body C).
- Rebuilt `search_vector` for all 4,379 postings in-place; 2,630 postings (60%) had non-empty `attachment_text` incorporated into their index.
- A small number of NOTICE warnings from PostgreSQL about words > 2,047 chars (e.g. base64 blobs in raw attachment text) are expected and benign — those tokens are simply skipped.

**Why:** After boosting attachment coverage from 25% → 60% via `extract_attachments --force`, the search index still didn't see that text. Searching for contact info or role-specific keywords buried in a .docx now works.

### 2026-05-27 — Production deploy: Dockerfile + fly.toml + whitenoise

**What:** Full production deployment configuration for Fly.io:
- `webapp/Dockerfile`: `python:3.13-slim`, installs `libpq-dev` for psycopg, runs `collectstatic` at build time, starts gunicorn with 2 workers.
- `webapp/fly.toml`: App name `posturi-gov-ro`, primary region `waw` (Warsaw, closest to Romania), `shared-cpu-1x` + 512 MB RAM, `auto_stop_machines=stop` (free-tier friendly), `release_command = python manage.py migrate --noinput`.
- `webapp/.dockerignore`: excludes `.venv/`, `.env` files, `staticfiles/`, `__pycache__`.
- `requirements.txt`: added `gunicorn>=23.0` and `whitenoise[brotli]>=6.8`.
- `settings.py`: `WhiteNoiseMiddleware` inserted after `SecurityMiddleware`; `STORAGES` key set to `CompressedManifestStaticFilesStorage`.

**Deploy steps:** `fly launch` (first deploy, provisions Postgres), then `fly secrets set SECRET_KEY=... DATABASE_URL=... GOOGLE_API_KEY=...`, then `fly deploy` for updates. The `release_command` applies migrations atomically before traffic switches.

### 2026-05-27 — Browse UI: anomaly flags filter + feed autodiscovery

**What:**
- Browse sidebar: new "Anomalii" section with 5 checkboxes (short_deadline, missing_contact, gender_criteria, no_body, frequent_repost). Uses HTMX-wired checkboxes; each flag ANDs with the others in `_apply_filters`. Reset button condition updated.
- `base.html`: added `<link rel="alternate">` for Atom and JSON feeds — standard feed autodiscovery that browsers and RSS readers pick up automatically.
- `detail.html`: inferred data section in sidebar showing `profession_family`, `seniority`, and `anomaly_flags` (amber badges). Only rendered when `posting.inferred` is non-empty.
- `list.html`: Export section at bottom of facet sidebar with Atom/JSON/iCal links that carry the current filter querystring (via `feed_url` template tag).
- `jobs_extras.py`: new `feed_url` template tag returns `/filename?<current_qs>`.

### 2026-05-27 — JSON API and Atom feed endpoints

**What:** Added two feed endpoints that mirror the Browse UI filters:
- `/posturi.json` — `JsonResponse` up to 200 results; all 10 browse filter params accepted (`q`, `judet`, `level`, `type`, `categorie`, `employer_cat`, `expires_before`, `expires_after`, `family`, `seniority`). Returns `{count, results[]}` with full field set including `profession_family`, `seniority`, and `anomaly_flags` from `inferred`.
- `/posturi.atom` — Atom 1.0 feed via `django.contrib.syndication.views.Feed`, 50 most recent items with title, employer as `author`, and structured description (județ/tip/categorie/termen).

**Architecture:** Extracted `_filter_kwargs_from_request(request)` helper that parses GET params into the `filter_kwargs` dict; both feeds and the browse view's `job_list` share `_apply_filters()`. Feed class uses `get_object()` override to capture request context before `items()` is called.

### 2026-05-27 — Docker Compose for dev, admin slug cleanup, canonical fields decision

**What:**
- `docker-compose.yml` at repo root: `postgres:17` service with named `pgdata` volume and health check. Port 5433 avoids conflict with local brew Postgres on 5432. `webapp/.env.example` updated: added Docker DATABASE_URL comment, renamed `GEMINI_API_KEY` → `GOOGLE_API_KEY`.
- `JudetAdmin` and `EmployerAdmin`: replaced `prepopulated_fields = {"slug": ("name",)}` with `readonly_fields = ("slug",)`. Model `save()` owns slug generation via `slugify()` + unique suffix loop; the admin JS `prepopulated_fields` was redundant and visually misleading (it suggested the admin form controlled the slug, when it doesn't).
- Canonical fields decision: `job_type` (from detail/announcement page) is canonical for job permanency over `tip` (index tag). `categorie`/`job_level`/`employer_category` are canonical over `detalii_raw` for display/filtering; `detalii_raw` retained for FTS.

### 2026-05-27 — Anomaly heuristics: frequent_repost flag

**What:** Added `frequent_repost` as the 5th anomaly flag in `infer_postings.py`. Added `build_frequent_repost_ids()`: one O(n) pre-pass before the main inference loop that groups all 4,379 postings by `(employer_id, normalized_title)` using NFKD/strip-diacritics normalization. Any group with 3+ members is a frequent-repost cluster; the function returns a `frozenset[int]` of those posting IDs. Each posting's `_infer_anomaly_flags()` call checks `posting.pk in frequent_repost_ids` — avoiding per-posting cross-queries entirely.

**Result:** 80 postings flagged on current dataset. Top offenders: MINISTERUL AFACERILOR EXTERNE (8× "Referent relații"), Universitatea Dunărea de Jos (5× "Administrator patrimoniu"), Agenția Națională de Îmbunătățiri Funciare (5× "Consilier IA"). These are real re-posting cases where institutions repeatedly fill unfilled vacancies.

**Also updated:** `anomaly_score` denominator bumped from 4 → 5 (now covers all v1 flag types). Admin `AnomalyFilter` and display icon dict updated. `narrow_criteria` flag deferred — would need LLM to detect tailored requirements (too vague for regex).

### 2026-05-27 — JobPostingUpdate model + parse_updates management command

**What:** Added `JobPostingUpdate` (migration 0005) to store parsed change-log segments from `JobPosting.updates_raw`. The `parse_updates` management command reads all non-empty `updates_raw` values, splits on `"; "` to get individual event segments, parses each as `(YYYY-MM-DD, fields_changed)`, and bulk-creates records idempotently. Registered in admin with `date_hierarchy` and `is_new_entry` boolean display.

**Current result:** 2,529 "New entry" records (all postings have only their initial first-seen date; no field-change events exist yet in current data). The model and command are ready for when incremental scrapes begin producing actual change records, which will power the "modificat recent" browse badge and job-detail change history.

**Format:** `"YYYY-MM-DD: New entry"` or `"YYYY-MM-DD: field1, field2; YYYY-MM-DD: field3"` — semicolons separate segments, colon+space separates date from fields. Regex: `(\d{4}-\d{2}-\d{2}):\s*(.+?)(?=;\s*\d{4}-\d{2}-\d{2}:|$)`.

### 2026-05-27 — CI: GitHub Actions workflow (ruff + pytest + migrations check)

**What:** Added `.github/workflows/ci.yml` — Python 3.13, Postgres 17 service (health-checked), runs: `ruff check .`, `python manage.py makemigrations --check --dry-run`, `pytest`. Added `webapp/pyproject.toml` with ruff config (E+F+I rules, line-length 120, migrations excluded from E501). Added `ruff` to `webapp/requirements.txt`. Fixed 3 unused imports and 6 unsorted import blocks flagged by ruff across `import_csvs.py`, `infer_postings.py`, `templatetags/jobs_extras.py`, `urls.py`, `settings.py`, and `tests/`.

**Also:** `llm-schema.py` prompt updated with baseSalary suppression guard (same text as `quality_check.py::SCHEMA_PROMPT`). FAMILIES dict and `black` backlog items closed (both were already done in prior session).

### 2026-05-27 — Fix: conftest.py `django_db_setup` override wiped production DB

**What:** The original `conftest.py` included a session-scoped `django_db_setup` fixture that was a no-op (`pass`). This bypassed pytest-django's built-in database isolation, causing the `@pytest.mark.django_db(transaction=True)` test to run against the real `posturi_dev` database. After the test completed, pytest-django flushed all tables (standard `TransactionTestCase` teardown), deleting all 4,379 postings and associated data.

**Fix:** Removed the `django_db_setup` override from `conftest.py`; pytest-django's default fixture now creates an isolated `test_posturi_dev` database. The `pytest_configure` function was also removed since `pytest.ini` already sets `DJANGO_SETTINGS_MODULE`. `conftest.py` is now a 2-line file that just sets the env var at module level.

**Recovery:** Re-ran `import_csvs --data-dir ../data` (4,379 postings, 2,955 employers, 43 județe, 11,567 calendar events), then `canonicalize_employers --apply` (257 aliases, 521 FK reassignments), then `infer_postings` (dict + LLM pass on all postings).

**Non-obvious:** `@pytest.mark.django_db(transaction=True)` uses Django's `TransactionTestCase` semantics which does NOT roll back via savepoint — it calls `flush` on teardown. Overriding `django_db_setup` to skip test-DB creation is only safe if you also ensure post-test cleanup; without it, the production DB is trashed. The default pytest-django `django_db_setup` correctly wraps everything in a `test_` prefixed database.

### 2026-05-27 — Test suite: importer idempotency (pytest-django)

**What:** Added pytest-django + factory-boy; wrote `tests/test_import_idempotency.py` with 3 tests covering: (1) running `import_csvs` twice doesn't duplicate Judet/Employer/JobPosting/CalendarEvent rows; (2) expected records are created with correct field values; (3) re-importing with updated title updates the existing row instead of creating a new one. All 3 pass in 0.25s against the real test database. Added `pytest.ini` and `conftest.py`.

**Non-obvious:** `call_command("import_csvs", data_dir=data_dir)` must pass a `Path` object, not `str` — the argparse `type=Path` conversion is bypassed when calling programmatically.

### 2026-05-27 — Browse UI: profession_family and seniority facets (Slice 2b)

**What:** Extended the Browse sidebar with two inferred-data facets: "Domeniu" (profession_family) and "Grad/funcție" (seniority). Both backed by JSONB field lookups on `JobPosting.inferred`.

**Changes:** `_apply_filters` in `views.py` extended with `families`/`seniorities` params; two new `.values().annotate(count=Count('id'))` queries on `inferred__profession_family` and `inferred__seniority`; `list.html` updated with two new `{% include facet_group %}` calls and updated reset-filter condition.

**Facet counts (current data):** sănătate 1164, administrație 983, tehnic 938, financiar 189, social 123, cultură 84 + 4 more families; seniority: referent 224, debutant 200, consilier 186, inspector 146, asistent 137, director 124.

**Note:** anomaly_flags facet skipped — it's a JSONB array and needs PostgreSQL `UNNEST` or a separate annotated queryset; deferred to a future pass.

### 2026-05-27 — Employer canonicalization (EmployerAlias model + management command)

**What:** 2,955 raw employer names contained 205 normalized-duplicate groups (462 employers, 257 that were pure variants). Added `EmployerAlias` model and `canonicalize_employers` management command.

**Algorithm:** NFKD normalize → strip combining diacritics → lowercase → punctuation→space → collapse whitespace. Canonical selection scored: +2 per Romanian diacritic (ș/ț/ă/î/â), +10 for title-case (not ALL CAPS). Picks the most "proper" looking variant as canonical.

**Result after `--apply`:** 257 EmployerAlias records created, 521 JobPosting.employer FKs reassigned to canonical employers. All 257 merged variants now have 0 postings. Admin updated: EmployerAdmin shows posting_count/alias_count; EmployerAliasAdmin for auditing.

**Why it matters:** Before this fix, "Administrația Bazinală de Apă Jiu" and "ADMINISTRATIA BAZINALĂ DE APĂ JIU" were separate employer records with 1 and 8 postings respectively. Now they're unified (10 postings) under the canonical. v2 employer-profile pages will be accurate.

**Non-obvious:** Aliased Employer records are kept in the DB with 0 postings (not deleted) as a safety measure — can be cleaned up once the merge is confirmed correct.

### 2026-05-27 — Full LLM inference pass on 4 379 postings

**What:** Ran `infer_postings --provider gemini --force` on the full dataset after dict refresh brought low-confidence count from 1,608 → 1,007. Discovered the webapp Django process wasn't loading `GOOGLE_API_KEY` because `settings.py` only called `load_dotenv(BASE_DIR / ".env")` (= `webapp/.env`, which doesn't exist) and never reached the repo-root `.env`. Fixed by adding `load_dotenv(REPO_ROOT / ".env")` as a fallback immediately after — webapp-level `.env` still takes precedence for production overrides.

**Non-obvious:** The 1,007 "errors" in the first run were silent `KeyError: 'GOOGLE_API_KEY'` inside `_llm_classify`, caught as `RuntimeError` and mapped to `source="error"` — counted in the error tally but not printed. All 1,007 now processed successfully with actual LLM calls after the settings fix.

### 2026-05-26 — FAMILIES sync, google-genai migration, contact_in_attachment refinement

**What:** Three follow-up improvements after the datePosted fix.

**1 — FAMILIES dict sync (webapp/infer_postings.py):** The webapp's `FAMILIES` dict was ~2 releases behind `quality_check.py`. Synced to full parity: `administrație` += manager/director/expert; `sănătate` += balneolog/ergoterapeut; `tehnic` += full construction/equipment operator vocabulary including `muncitor`, `muncitor necalificat`, `muncitor calificat`; `social` += `psiholog practicant`, `psiholog specialist`, `educator specializat`, `terapeut`; `ordine publică` += svsu/situatii urgenta/aparare civila/psi. Also fixed the webapp's env var to `GOOGLE_API_KEY` and bumped model to `gemini-2.5-flash`.

**2 — Migrate google.generativeai → google.genai:** Deprecated package was EOL with a FutureWarning on every run. Installed `google-genai`, updated both `quality_check.py` and `webapp/infer_postings.py` to use `google.genai.Client.models.generate_content`. Updated `requirements.txt`. FutureWarning confirmed gone.

**3 — `contact_in_attachment` anomaly flag:** `_infer_anomaly_flags` previously emitted `missing_contact` for any posting without a phone/email in the CSV card fields — including postings where the contact was clearly in the `.docx` attachment. Fixed: when CSV contact fields are empty, scan `combined_body` (card body + attachment text) for Romanian phone/email patterns. Emit `contact_in_attachment` (CSV extraction gap — not a real problem) vs `missing_contact` (truly absent everywhere). Applied to both `quality_check.py` and `webapp/infer_postings.py`. Verified: seed 7 sample correctly fires `contact_in_attachment` instead of `missing_contact` for a posting whose attachment contained a phone number.

**Re-ran parse-anunturi.py** locally to regenerate `anunturi.csv` with `Data Publicare`/`Data Expirare` columns baked in (data/ is gitignored).

### 2026-05-26 — Schema valid rate 0.5 → 1.0: join publicat_in/expira_in into schema context

**What:** The schema.org generator was failing `datePosted` and `validThrough` for 4–5/10 postings every run because `anunturi.csv` has no publication/expiry dates — those are only on the listing archive pages and were only stored in `posturi_gov_ro.csv` (`publicat_in` / `expira_in` columns scraped by `fetch-index.py`).

**Fix in `quality_check.py`:** Added `_parse_index_date()` (parses both `"Publicat în: D luna,YYYY"` and `"Expiră in  DD/MM/YYYY"` → ISO `YYYY-MM-DD`), `_load_index_dates()` (loads the index CSV into a URL-keyed dict once per run), and passed `date_posted`/`valid_through` kwargs into `check_schema`, where they are injected into the `fields_text` given to the LLM. The schema prompt now explicitly labels these as `datePosted` and `validThrough`.

**Fix in `parse-anunturi.py`:** Added `Data Publicare` and `Data Expirare` columns to `anunturi.csv` output by joining `posturi_gov_ro.csv` at parse time (same URL key). After re-running `parse-anunturi.py`, downstream consumers (webapp, quality checker) will find the dates in the CSV row directly.

**Result (seed 99, 10 postings):** `schema_valid_rate: 1.0` (was 0.5 with the same seed). All 10 schemas generated valid datePosted and validThrough.

**Non-obvious:** `quality_check.py` now checks `row.get("Data Publicare")` first (from a regenerated `anunturi.csv`) and falls back to the index lookup — so it degrades gracefully on an old CSV.

### 2026-05-26 — Quality review #1: pipeline fixes (attachment extraction, parse fallbacks, FAMILIES dict)

**What:** Ran `/quality-review` on the first 8-posting quality report and fixed the four systemic issues it surfaced.

**1 — `.doc` extraction via `textutil`:** `docx2txt` silently crashed on binary Word97 `.doc` files (they're OLE2, not ZIP — it tried to open `word/document.xml` in a zip archive). Replaced `_extract_doc` in `quality_check.py` with a `subprocess.run(["textutil", "-convert", "txt", "-stdout", ...])` call using macOS's built-in converter. All 8 `.doc` attachments in the sample now yield full text (8 KB+). Note: `textutil` is macOS-only; add a fallback if the pipeline moves to Linux.

**2 — FAMILIES dict expansion:** Added missing keywords that caused 5/8 postings to fall through to LLM fallback (which then failed on auth): `manager`, `director`, `expert` → `administrație`; `buldoexcavatorist`, `excavatorist`, `utilajist`, `macaragiu`, `stivuitorist`, `fochist`, `lacatus`, `tamplar`, `zidar`, `zugrav`, `pavator`, `dulgher`, `vopsitor`, `timonist` → `tehnic`; `svsu`, `situatii urgenta`, `aparare civila`, `psi` → `ordine publică`; `balneolog`, `ergoterapeut` → `sănătate`. All 8 postings now classify correctly via dict (no LLM needed for this sample).

**3 — Phone extraction fallback (`parse-anunturi.py`):** Existing `PHONE_RE` required 10 consecutive digits — missed formatted landlines like `0265 – 587.014` and mobile numbers written as `0722.256.558`. Added `PHONE_CANDIDATE_RE` that matches any 0-prefixed digit-with-separators sequence, then strips non-digits and keeps 10-digit results. Handles dash, dot, slash, en-dash, and mixed formats.

**4 — Deadline body fallback (`parse-anunturi.py`):** `_find_calendar_date` only searched the HTML calendar table; institutions sometimes put the deadline only in free-text body paragraphs (confirmed on live page for the Epidemiologie posting). Added `DEADLINE_BODY_RE` fallback that scans `body_text` for `data limita de depunere dosare : DD.MM.YYYY` when the calendar lookup returns empty.

**5 — Rectification detection (`parse-anunturi.py`):** When an institution corrects an announcement, they publish a new `.doc` and add a "Document atașat corectat" link in the body — but the original `<a>Anunt</a>` card link still points to the old file. Added a check that looks for this link pattern and promotes `announcement_url` to the corrected document. Verified against the live Epidemiologie posting via Playwright: `3dcc6ebe-1.doc` is the actual current document, `20ce1ed7-2.doc` is the superseded original.

**Non-obvious:** LLM schema generation had a 100% failure rate not because of code bugs but because `--provider anthropic` was used without `ANTHROPIC_API_KEY` set. The `anthropic` Python SDK makes direct REST calls — separate from Claude Code's claude.ai session auth. Gemini API key is available; use `--provider gemini` for LLM features going forward.

### 2026-05-26 — Data quality test suite (`quality_check.py` + `/quality-review` skill)

**What:** Added a standalone `quality_check.py` script that samples 5–10 diverse job postings (stratified by job type, level, county, attachment presence, body length) and runs all four pipeline layers through automated quality checks: CSV field completeness, attachment text readability, metadata inference (profession family confidence, skills, anomaly flags), and LLM schema.org/JobPosting generation. Produces `data/quality_report.json` and a console summary table. Also added `.claude/commands/quality-review.md` — a project-scoped Claude Code slash command (`/quality-review`) that reads the report, deep-reads source files, and produces a qualitative narrative with root-cause analysis and recommended fixes. Can optionally open source URLs in Playwright for visual scraping verification.

**Key findings from first run (--no-llm, 8 postings):**
- `.doc` files return empty text — `docx2txt` throws `KeyError` on old binary Word format (only handles DOCX/ZIP). 6/8 `.doc` attachment files affected. Added to backlog.
- `Data Limita Depunere` empty for most postings — structured deadline field is often unpopulated; dates appear only in body markdown. Added to backlog.
- Avg CSV completeness: 81.4%; avg profession-family confidence (dict-only): 0.19 — many titles need LLM fallback.
- `.docx` files (Buldoexcavatorist: 7k chars, ȘEF SVSU: 15k chars) extract correctly.

**New files:** `quality_check.py`, `.claude/commands/quality-review.md`, `docs/superpowers/specs/2026-05-26-data-quality-test-design.md`

**Usage:** `webapp/.venv/bin/python3 quality_check.py --no-llm` (fast) or `--provider anthropic` (full LLM pass). Then `/quality-review` in Claude Code for narrative assessment.

### 2026-05-26 — Attachment text extraction (`extract_attachments` command + `attachment_text` field)

**What:** Added `JobPosting.attachment_text` (TextField) + `extract_attachments` management command that reads `data/downloads/`, extracts plain text from linked DOCX/DOC/PDF files, and stores the result. Updated `infer_postings` to combine `body_markdown + attachment_text` for all Layer 3 inference.

**New files:** `webapp/apps/jobs/management/commands/extract_attachments.py`, migration `0003_jobposting_attachment_text`.

**Extraction stack:** `python-docx` for `.docx`, `docx2txt` for `.doc`, `pypdf` for text-native `.pdf`. Malformed/scanned files return empty string and are skipped silently.

**Results:** 1,113/4,379 postings extracted (25% — limited by download coverage: only 1,896 of ~4,363 attachment URLs have local files). 0 errors.

**Fill-rate improvement after re-running inference with attachment text:**

| Field | Before | After |
|---|---|---|
| studies_required | thin | 67.5% (2,958) |
| skills | thin | 88.1% (3,856) |
| experience_years | thin | 6.6% (291) |
| seniority | 26.1% | 26.1% (title-only, unchanged) |

**Next:** run `download-attachments.py` again to fill the remaining ~2,467 missing files, then re-run `extract_attachments --force`.

---

### 2026-05-26 — Inference pipeline: `infer_postings` management command

**What:** Built a three-layer metadata inference pipeline that populates `JobPosting.inferred` (JSONField) for all 4,379 postings.

**Inferred fields:** `profession_family` (+ confidence + source), `seniority`, `grade`, `studies_required`, `experience_years`, `skills`, `languages`, `certifications`, `anomaly_flags`, `anomaly_score`, `inferred_at`.

**Layer 1 — Profession family (keyword dictionary):** normalise title (lowercase + strip diacritics), word-boundary match against a curated dictionary of 10 families. Confidence = top_score / (top_score + second_score + 1). If confidence < 0.5 and `--no-llm` not set → LLM fallback.

**Layer 1b — LLM fallback:** short single-turn Romanian prompt; dispatches to gemini-2.0-flash / gpt-4o-mini / claude-haiku via `--provider`. API keys read from `.env`. 0.5s rate-limit between calls.

**Layer 2 — Seniority + grade:** regex over title for seniority levels (debutant → conducere_superioara) and grade (gradul I/IA/II/III, principal, superior).

**Layer 3 — Studies, experience, skills, anomaly:** regex over body_markdown. Anomaly flags: `short_deadline` (< 7 days publish → deadline), `missing_contact`, `gender_criteria`, `no_body`.

**Admin additions:** `InferenceConfidenceFilter`, `AnomalyFilter`, `reset_inferred` bulk action; list columns for family, confidence (color-coded), anomaly icons.

**Full-dataset run results (dict-only, no LLM):**
- sănătate 28.7%, administrație 24.0%, tehnic 8.7%, altele 29.1%
- Confidence: 63.3% medium (0.5–0.8), 36.7% low (< 0.5) → LLM fallback candidates
- Anomaly flags: missing_contact 33%, short_deadline 7%, gender_criteria 0.1%

**Non-obvious decision:** Switched keyword matching from `kw in norm` (substring) to word-boundary regex `(?<!\w)kw(?!\w)` — plain substring caused "it" to match inside "ingrijitor", misclassifying care workers as IT.

---

### 2026-05-26 — Slice 2: Browse/Search UI (HTMX + Tailwind + faceted search)

**What:** Built the first public-facing UI — a faceted browse/search page over all 4,379 job postings, plus a detail page.

**Stack wired in:** `django-htmx` middleware + `markdown` for body rendering + Tailwind CDN play script + Google Fonts (Fraunces + DM Sans).

**New files:**
- `apps/jobs/views.py` — `job_list` (faceted browse) + `job_detail`
- `apps/jobs/urls.py` — routes `/` and `/job/<pk>/`
- `apps/jobs/templatetags/jobs_extras.py` — `days_until` filter + `querystring` tag
- `templates/base.html`, `templates/jobs/list.html`, `templates/jobs/detail.html`
- `templates/jobs/partials/result_list.html`, `result_row.html`, `facet_group.html`

**Facets:** keyword FTS (`romanian_unaccent` config), județ (slug), job_level, job_type, categorie, employer_category, expires_at range. Each facet computes counts with all other filters applied ("sticky facets"). Sort: relevance (default when keyword), newest, deadline, employer A-Z.

**HTMX:** facet form submits to `/` with `hx-target="#results"` and `hx-push-url="true"` so URL stays shareable. Partial returns `result_list.html` fragment only. Pagination links inside the partial carry the same HTMX attributes.

**Aesthetic:** "Romanian State Archive" — warm parchment background (#F5F0E8), deep gov-blue header (#1B3A6B), Fraunces variable serif for titles, DM Sans for body, hairline borders instead of cards. Color-coded deadline countdown (green → amber → red).

**Verified:** `manage.py check` clean, root 200, detail 200, HTMX partial returns fragment (no `<html>`), keyword search for `conditii` == `condiții` (unaccent), visual review in Playwright.

---

### 2026-05-26 — Slice 1: Django webapp scaffold + data model + CSV importer

**What:** Stood up the Django project under `webapp/`, defined models mirroring the scraper CSVs, wrote an idempotent `import_csvs` management command, and verified end-to-end import into Postgres.

**Stack chosen:** Django 5.2 + HTMX (UI slice next) + React islands (map/calendar slice later) over PostgreSQL 14 with `tsvector` FTS. See `docs/ui-spec.md` "Stack" section for full rationale.

**Pre-work — `parse-anunturi.py` patched to record `Source URL`:** the CSV had no join key back to `posturi_gov_ro.csv`. Added `source_url_from_path()` that reconstructs the posting URL from the cached HTML file path (`data/anunturi/YYYY/MM/DD/<slug>.html` → `https://posturi.gov.ro/anunt/<slug>/`). Output CSV gained a `Source URL` column; `calendar.csv` `url` column now holds the posting URL instead of the (often missing) attachment URL. Re-ran parse-anunturi.py to regenerate both files.

**Webapp layout:**
```
webapp/
  manage.py, requirements.txt, .env.example
  posturi/{settings.py,urls.py,wsgi.py,asgi.py}
  apps/jobs/{models.py,admin.py,migrations/,management/commands/import_csvs.py}
```

**Models:** `Judet`, `Employer`, `JobPosting` (URL-keyed; index + detail fields + `inferred` JSONB reserved for v2/v3 + `SearchVectorField` + GinIndex), `CalendarEvent`.

**Importer:** idempotent `update_or_create` keyed by URL; date parsing handles `DD.MM.YYYY`, `DD/MM/YYYY`, and Romanian month names ("9 septembrie, 2024"); `parse_datetime_with_time()` for `Data Limita Depunere` with `ora HH:MM`. `search_vector` populated in a single SQL pass weighted A=title, B=employer, C=body.

**Verified end-to-end:** Postgres started, `posturi_dev` created, migrations applied, importer reported `created=4379, updated=0, errors=0` on first run and `created=0, updated=4379, errors=0` on re-run. Detail join: matched=4379/4379. Calendar: created=11567, unmatched=0. FTS query for "expert" returns expected rows.

**Out of scope (next slices):** public UI templates, HTMX wiring, Tailwind, React islands for map/calendar, taxonomy inference, accounts/feeds, scraper refactor.

---

### 2026-05-26 — parse-anunturi.py: structured field extraction + calendar CSV

**What:** Enhanced `parse-anunturi.py` to extract structured fields from cached HTML and generate a separate `data/calendar.csv`.

**New columns in `anunturi.csv`:** `Job Level` (Nivel), `Job Type` (Tip), `Employer Category` (Angajator type), `Categorie` (contractuală/publică), `Nr Posturi`, `Contact Telefon`, `Contact Email`, `Contact Persoana`, `Data Limita Depunere`, `Data Proba Scrisa`, `Data Interviu`, `Data Rezultate Finale`.

**New output `data/calendar.csv`:** flat table (`url, eveniment, data, ora`) with all competition timeline events. 11,567 rows across 1,140 postings (from 4,379 total).

**Approach:** Card wrapper fields extracted by label text (robust vs. positional nth-child). Calendar parsed from the `<p>` block containing "CALENDARUL / Nr. crt.", split on `<br/>` into individual events with date regex + `ora` extraction.

**Fill rates on 4,379 postings:** Job Level 99%, Categorie 99%, Nr Posturi 58%, phone 37%, email 39%, Data Limita 21%, Data Proba Scrisa 16%, Data Interviu 13%.

---

### 2026-05-26 — Repo cleanup, bug fixes, LLM pipeline wiring

**What:** Extracted `posturi.gov.ro/` from the `scrapers2` monorepo into a standalone repo using `git filter-repo`, then audited and fixed the codebase before going live.

**Bug fixes:**
- `fetch-index.py`: fixed `'poziție'` → `'pozitie'` fieldname mismatch (DictWriter was silently dropping the field), added `timeout=30` to all `requests.get()` calls, removed unused `checkback=2` parameter, fixed output path to `data/posturi_gov_ro.csv`, applied incremental per-page saves (crash-safe)
- `download-attachments.py`: fixed CSV path (`data/anunturi.csv` → `data/anunturi/anunturi.csv`), added `timeout=30`

**LLM scripts wired to pipeline:** `llm-schema-posts.py`, `oai-api.py`, `gemini-api.py` now all iterate over `data/anunturi/anunturi.csv`, take `Main Body Markdown` as input, and write JSON-LD output to `data/schema/<slug>.json`. Updated to current SDK APIs (openai v1+, gemini `GenerativeModel.generate_content`).

**Housekeeping:** added `.env.example`, system deps note in README, deleted `fetch-index.py` (superseded by `fetch-index.py`) and `doc2md0.py` (superseded by `dox2md.py`).
