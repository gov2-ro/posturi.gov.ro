# Continuous deployment — VPS runbook

The pipeline runs unattended on a VPS twice a day and pushes a fresh database to the
shared host. This is how to set that up and how to operate it.

```
Mac (dev) ──git push──> GitHub ──git pull (manual, when you choose)──> VPS
  │                                                                     │
  │  ./deploy-php.sh --code-only        ops/run-pipeline.sh (timer, 2×/day)
  └────────────────> shared host (PHP + posturi.sqlite) <───────────────┘
```

The VPS owns PostgreSQL, the scrape cache and the API keys. The shared host owns the
PHP tree and one read-only SQLite file, and holds no secrets.

**Code deploys are manual and the cron never pulls git.** That is why the two rsyncs
are separate: if the timer pushed the whole directory, the VPS's older checkout would
silently revert templates you pushed from the Mac. `--code-only` excludes `*.sqlite*`,
`--data-only` sends nothing but the database.

Why not GitHub Actions: the pipeline carries ~2.4 GB of persistent state (`data/anunturi`
HTML cache, `data/downloads` attachments) plus a PostgreSQL database that has to survive
between runs. On ephemeral runners all of it would be restored and re-saved every run
against a 10 GB cache quota with 7-day eviction. Secrets and job limits were never the
problem — the state is.

---

## 1. Provision the box

Debian/Ubuntu. Adjust for your distro.

```bash
sudo apt update
sudo apt install -y postgresql python3-venv python3-pip git rsync antiword
sudo timedatectl set-timezone Europe/Bucharest
```

- **`antiword`** matters. Legacy `.doc` attachments are extracted by trying `textutil`
  (macOS only), then `antiword`, then `catdoc` — see `_DOC_CONVERTERS` in
  `webapp/apps/jobs/management/commands/extract_attachments.py`. Without one of the
  Linux converters every `.doc` silently extracts to an empty string.
- **The timezone is load-bearing.** The export filters `expires_at >= CURRENT_DATE` and
  the timer fires on local time; both have to be the Romanian day boundary.
- `poppler-utils tesseract-ocr tesseract-ocr-ron` only if you later want the scanned-PDF
  OCR path (`quality_check.py`); the pipeline does not need them.
- **No Node.** `webapp-php/static/app.css` is committed precisely so no host needs a
  toolchain. Rebuild it on the Mac with `npm run css` and deploy it with `--code-only`.

```bash
sudo adduser --system --group --home /srv/posturi --shell /bin/bash posturi
sudo -u posturi git clone <repo> /srv/posturi
cd /srv/posturi
sudo -u posturi python3 -m venv .venv
sudo -u posturi .venv/bin/pip install -r requirements.txt
```

## 2. Seed the state

The VPS becomes the source of truth. Everything from here is one-time, run from the Mac.

```bash
# PostgreSQL — carries body_markdown, attachment_text, inferred and schema_json, so
# the 2.1 GB of raw PDFs does not have to travel.
pg_dump -Fc posturi_dev > posturi.dump
scp posturi.dump vps:/tmp/

# Scrape cache and CSVs, minus the attachment archive (~180 MB rather than 2.4 GB).
rsync -av --exclude='downloads/' --exclude='_OBSOLETE/' \
    data/ vps:/srv/posturi/data/
```

On the VPS:

```bash
sudo -u postgres createuser posturi
sudo -u postgres createdb -O posturi posturi
sudo -u posturi pg_restore -d posturi --no-owner /tmp/posturi.dump
cd /srv/posturi && sudo -u posturi .venv/bin/python webapp/manage.py migrate
```

`download-attachments.py --since 7` fetches new attachments from here on; the historical
ones stay on the Mac. If you later want the full archive on the VPS, rsync
`data/downloads/` across and re-run `extract_attachments --force`.

## 3. Configure

`/srv/posturi/.env`, owned by `posturi`, mode 600:

```bash
DATABASE_URL=postgres://posturi@localhost/posturi
GOOGLE_API_KEY=...
DEPLOY_HOST=user@sharedhost
DEPLOY_PATH=~/posturi.gov2.ro
SITE_URL=https://posturi.gov2.ro/
HEALTHCHECK_URL=https://hc-ping.com/<uuid>
```

See `.env.example` for the full list. Note `DEPLOY_PATH` is a *remote* path — leave the
`~` unexpanded; `deploy-php.sh` refuses a value that has expanded against the local home.

SSH from the VPS to the shared host, as the `posturi` user:

```bash
sudo -u posturi ssh-keygen -t ed25519 -N '' -f /srv/posturi/.ssh/id_ed25519
# add the .pub to the shared host's ~/.ssh/authorized_keys, then:
sudo -u posturi ssh-keyscan sharedhost >> /srv/posturi/.ssh/known_hosts
sudo -u posturi ssh user@sharedhost 'echo ok && command -v rsync'
```

That last check matters: the data deploy is an rsync, and a host without `rsync` on the
remote end needs a different transport (an `lftp` upload to `posturi.sqlite.new` plus a
rename gives the same atomicity over SFTP).

## 4. Install the timer

```bash
sudo cp ops/systemd/posturi-pipeline.* /etc/systemd/system/
sudo systemctl daemon-reload

# Run once by hand first, and watch it.
sudo systemctl start posturi-pipeline.service
journalctl -fu posturi-pipeline

sudo systemctl enable --now posturi-pipeline.timer
systemctl list-timers 'posturi*'
```

Slots are 11:45 and 18:33 local. `Persistent=true` catches up a slot missed while the
box was down — the export is what drops expired postings off the site, so a skipped day
leaves stale jobs published.

## 5. Operating it

| Task | Command |
|------|---------|
| Run now | `sudo systemctl start posturi-pipeline.service` |
| Watch a run | `journalctl -fu posturi-pipeline` |
| Last run's outcome | `systemctl status posturi-pipeline.service` |
| Next slots | `systemctl list-timers 'posturi*'` |
| Deploy code after a `git pull` | on the Mac: `./deploy-php.sh --code-only` |
| Update the VPS's own checkout | `sudo -u posturi git pull && .venv/bin/python webapp/manage.py migrate` |

Exit codes from `ops/run-pipeline.sh`: `0` fine, `75` a previous run still holds the
lock, anything else a failure already reported to `HEALTHCHECK_URL`.

### What a run does

`pipeline.py --continue-on-error --since 7 --active-only --resume --workers 4
--prompt-version v3`, then `./deploy-php.sh --data-only --no-export`.

- **`--active-only` is the cost guard.** 6,904 postings have no `schema_json` but only
  about 15 of them are active; the rest will never reach the export. Without this flag
  every run would pay for LLM extraction on thousands of expired postings.
- **`--prompt-version v3` is pinned** because `models_config.json` still defaults to v2,
  and a v2 extraction lands with every `v3_*` facet column empty.
- **`--continue-on-error` is deliberate.** A failed `download` or `infer` step still lets
  the export and deploy run: the site staying current matters more than the run being
  clean, and `export-to-sqlite.py`'s floors are what decide whether the data is fit to
  ship. The run still exits non-zero and pings `/fail`, so you hear about it.

### When it goes wrong

| Symptom | Cause | Fix |
|---|---|---|
| `refusing to scrape a truncated listing` | `nav.pg-arc-pagi` stopped exposing the last page number | check the listing by hand, then `FETCH_INDEX_ALLOW_SHRINK=1` |
| `export has N job_postings, down from M` | the scrape or the import lost rows | check the import output; `--force` only once you know the drop is real |
| `below the --min-rows floor` | near-empty export | same — look upstream before forcing |
| `site returned HTTP 5xx after deploy` | the shared host is unhappy with what landed | the previous database is still intact on disk; investigate before re-running |
| Healthcheck silent, no failure mail | the timer is not running | `systemctl list-timers 'posturi*'` |

The dead-man's-switch is the point of `HEALTHCHECK_URL`: a failure alert cannot tell you
about a run that never happened, which is exactly how the site went five weeks stale.
