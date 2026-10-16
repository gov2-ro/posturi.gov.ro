# Beta rollout — order of operations (2026-10-16 weekend work)

Everything from the beta weekend is committed on `main` and **not deployed**. Code and data
travel separately (code from the Mac, data from the VPS), and three changes only work in one
order. Do the steps in this order; each says how to tell it worked.

## 0. Before anything

- `git push` from the Mac. CI now runs on every push (CI-01): the first green run on GitHub
  closes CI-01.

## 1. Code to the shared host (Mac)

```
./deploy-php.sh --code-only
```

- The PHP reads both export layouts (PERF-SPLIT shims `posting_text`/`has_schema`,
  FIX-09 shims `publication_monthly`, the v5 columns are shimmed too), so it is safe against
  today's export. **It must land before step 3**: old PHP against a split export renders
  empty posting bodies.
- Check: `/versiuni.json` `code` = the pushed commit (no `-dirty`);
  `curl -sD - -o /dev/null https://posturi.gov2.ro/ | grep -i 'server-timing\|cache-control'`
  shows `cache-control: public, max-age=300, s-maxage=3600` and a `total;dur=` — the
  uncached list time on the real host, still against the old export layout.

## 2. Cloudflare (dashboard, pax)

1. Caching → Cache Rules → new rule: *Hostname equals `posturi.gov2.ro`* → **Eligible for
   cache**; Edge TTL **Use cache-control header if present, bypass cache if not**; Browser
   TTL **Respect origin**. (Only after step 1: before it, the origin still sends one URL for
   both the page and the HTMX fragment.)
2. My Profile → API Tokens → Create Token → Custom: *Zone → Cache Purge → Purge*, zone
   `gov2.ro`. Put `CF_ZONE_ID` and `CF_API_TOKEN` in `.env` on the Mac **and** the VPS.
3. Analytics & Logs → Web Analytics → enable for the site (automatic setup).
- Check: the second request for `/` answers `cf-cache-status: HIT`; a deploy prints
  "Cloudflare cache purged for posturi.gov2.ro".

## 3. VPS pull (posturi-vps session; pax approves the changes there)

```
cd ~/g2-dev/posturi.gov.ro
git pull
.venv/bin/pip install -r requirements.txt      # nh3/icalendar removed; harmless if skipped
.venv/bin/python webapp/manage.py migrate      # no new migrations expected; confirm "No migrations to apply"
crontab -l > ~/crontab.bak.$(date +%F-%H%M)
crontab -e   # (a) remove the MAILTO= line (D7, OPS-MAIL)
             # (b) add POSTURI_RUN_TRIGGER=cron to both posturi lines, exactly as
             #     docs/deploy-vps.md shows (OPS-TRIGGER: the default is now "manual")
```

The next scheduled run then:
- parses incrementally (LATER-05; the first run is a full parse that builds the cache);
- exports the split layout + `publication_monthly` (PERF-SPLIT, FIX-09);
- passes `--schema-floor 95` to check-export (OPS-FLOORS);
- deploys the data and purges Cloudflare (if `CF_*` are in the VPS `.env`).

Check after that run: `/pipeline-check`; `/versiuni.json` `data.built_at` is new; the live
`Server-Timing` `total` for `/` against the split export (expect well under a second);
`/statistici` shows the "Istoric" section.

## 4. Salary bands from town populations (DATA-06)

`data/uat-populatie.csv` arrives with the pull. Re-run the salary estimate the way the
pipeline does (step `estimate-salaries` in `pipeline.py`) — the next scheduled run does it.
Spot-check a town posting (e.g. a Primăria Orașului … in a small town) for a single band.

## 5. v5 extraction (BETA-V5) — details in docs/specs/2026-10-16-v5-extraction/README.md § 6–7

After steps 1 and 3 (the code knows the v5 columns, the VPS export writes them), off-peak
(weekends are off-peak all day; weekdays avoid 01–04 and 06–10 UTC):

```
.venv/bin/python llm-schema.py --active-only --force --prompt-version v5 --limit 200 --dry-run
.venv/bin/python llm-schema.py --active-only --force --prompt-version v5 --limit 200 --workers 4   # ≈ $0.45
```

Then make v5 the intake version without re-extracting the v4 rows — in the VPS `.env`
(`ops/run-pipeline.sh` reads `LLM_PROMPT_VERSION` from `.env` since this weekend; first
check with `grep LLM_PROMPT_VERSION .env` that it is not already set to something else —
it was empty on 2026-10-05):

```
LLM_PROMPT_VERSION=v5
LLM_ACCEPT_PROMPT_VERSIONS=v4,v5
```

and check `.venv/bin/python llm-schema.py --active-only --resume --prompt-version v5 --dry-run`
selects only new or changed postings. The four v5 filters (Tip concurs, Normă parțială,
Post temporar vacant, Depunere fără deplasare) appear on the site once v5 rows are exported.

## 6. Archive pages (UX-03)

Nothing to do: the next `--active-only` export writes `archived_postings` (≈ the last 90
days of expired/cancelled postings, a few MB). Check a recently expired posting's old URL:
"Anunț expirat" and similar open jobs, `noindex`.

## Deferred by pax

Backblaze backups (BETA-BACKUP), OpenRouter model comparison, OCR (DATA-01).
