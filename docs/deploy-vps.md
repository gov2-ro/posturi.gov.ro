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

> **The live box (`gov2-1`) diverges from §1–§5 below.** It runs as the ordinary
> login user `pax` out of `~/g2-dev/posturi.gov.ro`, connects to Postgres over the
> local socket, and is driven by a user crontab rather than a systemd timer. See
> **§6** for the actual layout, the `~/.ssh/config` block, and the cron lines.
> Sections 1–5 remain the reference for a clean dedicated-user install.

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
DATABASE_URL=postgres://posturi@/posturi   # socket + peer auth, no password.
                                           # @localhost forces TCP and fails with
                                           # "fe_sendauth: no password supplied"
                                           # unless the role has a password and
                                           # pg_hba permits md5/scram on 127.0.0.1
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
| Is the pipeline healthy? | `/pipeline-check` in Claude Code, or `.venv/bin/python ops/check-export.py --no-log` |
| Last few runs, raw | `tail -5 data/pipeline-runs.jsonl \| jq -c '{run_id,kind,exit,status,failed_steps}'` |
| Deploy code after a `git pull` | on the Mac: `./deploy-php.sh --code-only` |
| Update the VPS's own checkout | `sudo -u posturi git pull && .venv/bin/python webapp/manage.py migrate` |

Exit codes from `ops/run-pipeline.sh`: `0` fine, `65` the export failed its hard
checks and was **not** deployed, `75` a previous run still holds the lock, anything
else a failure. Every non-zero exit has already been reported to `HEALTHCHECK_URL`.

### Observability

Three artefacts, in increasing order of how often you need them:

| Where | What it holds |
|---|---|
| `data/pipeline-runs.jsonl` | one record per run (`kind: "run"`) plus one per export check (`kind: "export-check"`), joined by `run_id`. Step timings, exit codes, and 33 data metrics with run-over-run deltas. |
| `logs/pipeline.log` | everything the steps printed. Runs are delimited by `=== posturi pipeline run <id> (<trigger>) — … ===`; grep the run id. Rotate it with `ops/logrotate.posturi`. |
| `HEALTHCHECK_URL` | the dead-man's switch. `/start` at the top of a run, `/fail` on any failure, a bare ping on success. **This is the only signal that can report a run which never happened** — a failure alert cannot. |

`ops/check-export.py` runs between the export and the deploy and is what decides
whether the file ships. It has two severities:

- **hard** — corruption that cannot be a bad day's data: `integrity_check`, broken
  foreign keys, a duplicate URL, a short FTS index, anything other than exactly 42
  județ rows, a collapse to under half the previous run's postings, or a
  `build_meta.built_at` older than six hours (meaning the export never actually ran
  and the deploy is about to ship yesterday's file). **A breach aborts before the
  rsync**, so the shared host keeps serving the previous database.
- **soft** — quality drift: coverage regressions, an `altele` spike, one profession
  family or one skill tag taking an implausible share, an anomaly-flag step change,
  mojibake, cedilla `ş/ţ` in a county name. Printed and recorded, but the deploy
  proceeds. `--strict` makes them fatal; leave it off until the thresholds have a
  few weeks of runs behind them.

It reuses `import_csvs.py`'s `expiry_sanity_warnings()` and `judet_sanity_warnings()`
rather than restating their thresholds, so there is one definition of "too many
counties" in the tree. Those two need Django importable; without it that one check
reports itself skipped and the rest still run — which is what makes the script usable
on a laptop against a copied export:

```bash
scp gov2-1:g2-dev/posturi.gov.ro/webapp-php/posturi.sqlite /tmp/
.venv/bin/python ops/check-export.py --db /tmp/posturi.sqlite --no-log
```

`--no-log` matters: without it an interactive read becomes the baseline the next
real run compares its deltas against.

### What a run does

`pipeline.py --continue-on-error --since 7 --active-only --resume --workers 4
--prompt-version v3`, then `ops/check-export.py`, then — only if the check passes —
`./deploy-php.sh --data-only --no-export`.

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
| `refusing to deploy this export` (exit 65) | a hard check in `ops/check-export.py` failed | read the named check; the previous database is still live, so there is no rush |
| `43 județ rows, expected exactly 42` | a new badge spelling got past `normalize_judet()` | add it to `judete.ALIASES`, re-run `import` and `export-sqlite` |
| `built_at … is 18.4h old` | the `export-sqlite` step failed but the run continued | look upstream in the log for that step; the file on disk is from a previous run |
| A soft check warns every run and nothing is wrong | the threshold was picked before there was any run history | change it in `ops/check-export.py` — `/pipeline-check` will propose a value from the recorded metrics |

The dead-man's-switch is the point of `HEALTHCHECK_URL`: a failure alert cannot tell you
about a run that never happened, which is exactly how the site went five weeks stale.

---

## 6. `gov2-1` — the box this actually runs on

**Status (2026-09-10):** provisioned, Postgres restored from a Mac dump,
`ops/run-pipeline.sh` verified end to end (full pipeline + `deploy-php.sh
--data-only` to `mioritics.ro`, live site HTTP 200), and the crontab installed.
Observability landed the same day: the run log, `ops/check-export.py` as a deploy
gate, and `/pipeline-check`. **Still outstanding: `HEALTHCHECK_URL` is empty**, so
there is no dead-man's switch — see *Alerting* below. `/etc/logrotate.d/posturi` is
not installed either, so `logs/pipeline.log` grows without bound.

§1–§5 describe a dedicated `posturi` service user under `/srv/posturi` with a systemd
timer. The live VPS was set up as the plain login user instead. The deltas:

| §1–§5 reference | `gov2-1` |
|---|---|
| user `posturi`, group `posturi` | `pax` (the login user) |
| `/srv/posturi` | `/home/pax/g2-dev/posturi.gov.ro` |
| DB role `posturi`, `createdb -O posturi` | `pax` is a Postgres `SUPERUSER` role (`sudo -u postgres psql -c "CREATE ROLE pax LOGIN SUPERUSER"`); DB `posturi` recreated and owned by `pax` |
| `DATABASE_URL=postgres://posturi@localhost/posturi` | **`postgres://pax@/posturi`** — empty host = local socket = peer auth, no password. `@localhost` forces TCP and dies with `fe_sendauth: no password supplied`. |
| `systemd` timer (`ops/systemd/`) | user crontab (below). The unit files still hard-code `/srv/posturi` and `User=posturi`; edit both if you ever switch to them. |
| `.venv` via `sudo -u posturi` | `.venv` owned by `pax`, no `sudo` anywhere in the run path |

Rebuild the DB from a Mac dump, as `pax`:

```bash
cd ~/g2-dev/posturi.gov.ro
dropdb --if-exists posturi && createdb posturi
pg_restore -d posturi --no-owner --no-privileges /tmp/posturi.dump
.venv/bin/python webapp/manage.py migrate      # no-op; migrations travel in the dump
```

### SSH to the shared host

`deploy-php.sh` runs a bare `ssh pax@mioritics.ro` / `rsync` (from `DEPLOY_HOST` in
`.env`). Cron has no `ssh-agent`, so the deploy key must be passphrase-less and named
in `~/.ssh/config`. No trailing `#` comments in this file — OpenSSH parses the words
after the value as options and aborts (`Bad configuration option`). `IdentityFile` is
the passphrase-less deploy key:

```
Host mioritics.ro
    HostName mioritics.ro
    User pax
    IdentityFile ~/.ssh/id_ed25519_posturi
    IdentitiesOnly yes
```

Seed `known_hosts` once so a run never blocks on the host-key prompt:

```bash
ssh-keyscan -H mioritics.ro >> ~/.ssh/known_hosts
ssh mioritics.ro 'echo ok && command -v rsync'
```

### Cron

`mkdir -p ~/g2-dev/posturi.gov.ro/logs` first, then `crontab -e` as `pax`:

```cron
CRON_TZ=Europe/Bucharest
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=pax@mioritics.ro

45 11 * * * /home/pax/g2-dev/posturi.gov.ro/ops/run-pipeline.sh >> /home/pax/g2-dev/posturi.gov.ro/logs/pipeline.log 2>&1
33 18 * * * /home/pax/g2-dev/posturi.gov.ro/ops/run-pipeline.sh >> /home/pax/g2-dev/posturi.gov.ro/logs/pipeline.log 2>&1
```

`run-pipeline.sh` `cd`s to the repo itself, re-execs under `flock` (the two slots and
any hand-run cannot overlap), and reads `.env` through `ops/env.sh`. Exit codes: `0`
fine, `75` a run was already going, anything else a failure. `MAILTO` mails any run
that produced output; it cannot report a cron that stopped firing — set
`HEALTHCHECK_URL` in `.env` for that.

Checks:

```bash
timedatectl                       # must say Europe/Bucharest (set once: timedatectl set-timezone …)
crontab -l
./ops/run-pipeline.sh             # hand-run once; watch: tail -f logs/pipeline.log
```

A hand-run is recorded as `trigger: "manual"` rather than `"cron"`, so
`/pipeline-check` will not mistake a debugging session for a slot that fired. To
label one explicitly: `POSTURI_RUN_TRIGGER=backfill ./ops/run-pipeline.sh`.

### Log rotation

```bash
sudo install -m 644 ops/logrotate.posturi /etc/logrotate.d/posturi
sudo logrotate -d /etc/logrotate.d/posturi     # dry run
```

`copytruncate`, not `create`: cron appends through a shell redirect that holds the
fd open for the whole run, so a renamed file would keep receiving the rest of a run
still going at rotation time. `data/pipeline-runs.jsonl` is not rotated — it is one
line per run.

### Alerting

`ops/run-pipeline.sh` already pings `HEALTHCHECK_URL` at `/start`, on `/fail`, and
bare on success. **It is currently empty in `.env`, so none of that happens.** Fix:

```bash
# healthchecks.io → new check → "Period 12 hours, Grace 3 hours" (the slots are
# 11:45 and 18:33, so a 12h period tolerates one late run and catches a missed day).
echo 'HEALTHCHECK_URL=https://hc-ping.com/<uuid>' >> .env
./ops/run-pipeline.sh    # or wait for a slot; the check should go green
```

This is the only signal that reports a run which *never happened*. `MAILTO` in the
crontab mails any run that produced output, but only if the box has an MTA — verify
with `command -v sendmail` before relying on it, because a dead MAILTO is
indistinguishable from a clean run.
