# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

See README.md for project overview, pipeline, commands, and data structure.

## Key behaviors

**Site redesigned 2026-07** — posturi.gov.ro was rebuilt with WordPress + Astra + Elementor. The pipeline was updated 2026-08-01 to handle both old and new HTML structures. `parse-anunturi.py` auto-detects old vs new HTML and applies the correct selectors.

**New URL scheme**:
- Listing: `/toate-posturile/?pg_page=N` (was `/page/N/`)
- Detail: `/joburi/{slug}/` (was `/anunt/{slug}/`)
- Pagination: `nav.pg-arc-pagi` with `a.page-numbers` (was `div.ast-pagination`)
- Job cards: `article.pg-card` (was `article.box`)

**Two index scripts** — `fetch-index.py` is preferred; the old version has been deleted. It scans all pages, but once a change has been seen it stops after 3 consecutive unchanged pages, and records `complete` or `partial-early-stop` in `data/index-scan.json`.

**`parse-anunturi.py` outputs two files** — `data/anunturi/anunturi.csv` (one row per posting, with structured fields: contact info, competition dates, card fields) and `data/calendar.csv` (flat table: `url, eveniment, data, ora` — all competition timeline events across all postings).

**Incremental saves** — both index scripts save to CSV after each page that has new or updated entries (crash-safe). `fetch-index.py` skips the save on unchanged pages.

**Change tracking** — `compare_and_update()` diffs each scraped listing against the stored row and appends changed field names + date to the `updates` column.

**Extraction prompts live in `models_config.json`** — `prompts.{v1,v2,v3,v4}` (+ `occupation_v1`), loaded by `get_prompt()` in `llm-schema.py` and sent as the *system* instruction; the posting body + attachment text is the *user* message. Each structured version has a Pydantic model in `schema_models.EXTRACTION_MODELS`; never hard-code a model in `llm-schema.py`. The config default is still v2; `ops/run-pipeline.sh` pins `LLM_PROMPT_VERSION` (v4 since 2026-10-05; changing it re-extracts every active posting). v3 adds EQF/ISCED-F/CEFR/ESCO-backed match keys for CV-to-job matching — see `docs/metadata-schema-v3.md`.

**Județe are normalised at import** — the source badge is `"Timiş"` (pre-redesign) or `"TIMIŞOARA, Timiș"` (post-redesign). `webapp/apps/jobs/judete.py` is the only place that interprets it: `normalize_judet()` returns `(county, locality)` against the canonical 42 counties. Never write a raw badge value into `Judet.name` — that is what produced 261 rows and broke the județ facet. Unmatched values land in `JobPosting.judet_raw` and are reported by `judet_sanity_warnings()` on every import; fix by adding to `judete.ALIASES`. See README § Județe.

**The PHP webapp's palette is tokens, not literals** — every colour, radius and font in `tailwind.config.js` resolves to a CSS custom property; the defaults are one `:root` block in `webapp-php/assets/app.css`. Never add a raw Tailwind palette class (`bg-amber-50`, `text-slate-700`) or a hex literal to a template — use the role tokens (`page`, `sunken`, `surface`, `line`, `ink`, `gov`, `gov-bar`) and the five semantic families (`info`, `neutral`, `ok`, `note`, `alert`), each a `bg-x` / `border-x-line` / `text-x-ink` triple. A skin is a file in `webapp-php/static/skins/` that re-declares those variables under `[data-skin="<id>"]`; run `php webapp-php/assets/check-skins.php` after touching one. See README § Skins.

**Rate limiting** — `random.uniform(0.5, 1.1)` sleep between pages, built into all fetch scripts.

## Project tracking

- When detecting things that need to be addressed later, add to `docs/backlog.md`. Use a checkbox `- [ ]` entry with a clear title and enough context to act on it later.
- After completing any meaningful work, add an entry to `docs/activity-log.md` under the relevant section heading with a `### YYYY-MM-DD — Short Title` entry. Include what was done, why, and any non-obvious decisions.

## Design system (gov2 DS)

This project uses the gov2 design system (DS), version 0.4.0.
DS repo: gov2-ro/gov2ro-design-system (local copy: ~/devbox/gov2/gov2ro-design-system).

- Read `COMPONENTS.md` in the DS before you build UI. Use a DS component when one fits.
- Do not edit the copy of the DS in this project: `webapp-php/static/skins/gov2.css`.
- To update the DS, copy `gov2.css` from a DS tag. Then set the version above.
- Before you update, read `CHANGELOG.md` in the DS back to the version above. The DS is 0.x, so a minor version can break. Look for **Breaking**.
- Put the project styles in `webapp-php/assets/app.css` and the Tailwind role tokens (see the palette rule above). Use the DS tokens. Do not change how a `.g2-` class looks.
- After you update the copy, run `php webapp-php/assets/check-skins.php`. On 2026-10-26 it reports 69 problems for `gov2.css`: 23 DS tokens that the skin contract does not know, and 46 `.g2-` rules outside `[data-skin]`. The DS backlog tracks these problems. Do not fix them in the copy.
- When the project needs a component, a variant or a token that other projects can use, open an issue in the DS:
  `gh issue create -R gov2-ro/gov2ro-design-system --label ds-proposal --title "[proposal] …" --body-file <file>`.
  Use the sections of `.github/ISSUE_TEMPLATE/ds-proposal.md`.
- Open an issue for a DS defect too, with the type "fix".
- Do not wait for the DS. Build a local version, and put the issue URL in a comment next to it.
- When the DS has the component, replace the local version.

# AI Context

Generated locally by codesight (`.codesight/` is not in git): read `.codesight/wiki/index.md`
for orientation, `.codesight/CODESIGHT.md` for the full context map. Orientation only; may be stale — source and tests are authoritative.
