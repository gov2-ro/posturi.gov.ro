#!/usr/bin/env python3
"""Export PostgreSQL data to posturi.sqlite for the PHP shared-hosting webapp.

Usage:
    python export-to-sqlite.py                    # all postings → webapp-php/posturi.sqlite
    python export-to-sqlite.py --active-only      # active postings only (for deployment)
    DATABASE_URL=postgres://... python export-to-sqlite.py --active-only
    python export-to-sqlite.py --out /path/to/posturi.sqlite
    python export-to-sqlite.py --active-only --min-rows 500   # tighter floor for cron

The file is built as <out>.tmp and only replaces <out> once it opens, passes
integrity_check, clears --min-rows, and has not lost half its rows against the file it
would replace. --force skips the row floors. Nothing half-written or newly empty ever
reaches the deploy step — see docs/deploy-vps.md.

Output: posturi.sqlite with tables:
  job_postings   — main posting rows + extracted inferred fields
  employers      — canonical employer names
  judete         — county names
  calendar_events — competition timeline
  job_postings_fts — FTS5 virtual table (rowid = job_posting id)
  build_meta      — one row: when this file was built, from which commit and host
"""

import argparse
import json
import os
import socket
import sqlite3
import subprocess
import sys
from datetime import datetime, timezone
from urllib.parse import urlparse

import psycopg
from psycopg.rows import dict_row


def pg_connect():
    db_url = os.environ.get("DATABASE_URL", "postgres://localhost/posturi_dev")
    r = urlparse(db_url)
    return psycopg.connect(
        dbname=r.path.lstrip("/"),
        user=r.username,
        password=r.password,
        # No host in the URL (postgres://user@/db) means "local socket, peer auth" --
        # same as Django. Forcing "localhost" here turns that into a TCP connection
        # that demands a password. Pass None so libpq falls back to PGHOST / socket.
        host=r.hostname or None,
        port=r.port or None,
        row_factory=dict_row,
    )


def create_schema(con: sqlite3.Connection):
    con.executescript("""
-- DELETE, not WAL. The deployed copy is read-only and lives on shared hosting,
-- where two things bite: PHP opening a WAL database has to create -shm/-wal beside
-- it and the document root may not be writable; and after rsync swaps the file in,
-- a -wal left over from the previous copy describes a database that is no longer
-- there, which SQLite reports as "database disk image is malformed".
PRAGMA journal_mode=DELETE;
PRAGMA foreign_keys=ON;

DROP TABLE IF EXISTS build_meta;
DROP TABLE IF EXISTS calendar_events;
DROP TABLE IF EXISTS job_postings_fts;
DROP TABLE IF EXISTS job_postings;
DROP TABLE IF EXISTS employers;
DROP TABLE IF EXISTS judete;

CREATE TABLE judete (
    id      INTEGER PRIMARY KEY,
    name    TEXT NOT NULL,
    slug    TEXT NOT NULL UNIQUE
);

CREATE TABLE employers (
    id      INTEGER PRIMARY KEY,
    name    TEXT NOT NULL,
    slug    TEXT NOT NULL UNIQUE
);

CREATE TABLE job_postings (
    id                      INTEGER PRIMARY KEY,
    url                     TEXT NOT NULL UNIQUE,
    title                   TEXT NOT NULL DEFAULT '',
    employer_id             INTEGER REFERENCES employers(id),
    employer_name           TEXT NOT NULL DEFAULT '',
    judet_id                INTEGER REFERENCES judete(id),
    judet_name              TEXT NOT NULL DEFAULT '',
    judet_slug              TEXT NOT NULL DEFAULT '',
    locality                TEXT NOT NULL DEFAULT '',
    detalii_raw             TEXT NOT NULL DEFAULT '',
    published_at            TEXT,
    expires_at              TEXT,
    tip                     TEXT NOT NULL DEFAULT '',
    job_level               TEXT NOT NULL DEFAULT '',
    job_type                TEXT NOT NULL DEFAULT '',
    employer_category       TEXT NOT NULL DEFAULT '',
    categorie               TEXT NOT NULL DEFAULT '',
    announcement_url        TEXT NOT NULL DEFAULT '',
    body_markdown           TEXT NOT NULL DEFAULT '',
    nr_posturi              INTEGER,
    contact_phone           TEXT NOT NULL DEFAULT '',
    contact_email           TEXT NOT NULL DEFAULT '',
    contact_person          TEXT NOT NULL DEFAULT '',
    data_limita_depunere    TEXT,
    data_proba_scrisa       TEXT,
    data_interviu           TEXT,
    data_rezultate_finale   TEXT,
    created_at              TEXT,
    updated_at              TEXT,
    last_seen_at            TEXT,
    other_links             TEXT NOT NULL DEFAULT '[]',
    attachment_meta         TEXT NOT NULL DEFAULT '[]',
    inferred                TEXT NOT NULL DEFAULT '{}',
    schema_json             TEXT,
    -- extracted inferred fields for fast filtering
    inf_profession_family   TEXT,
    inf_seniority           TEXT,
    inf_anomaly_flags       TEXT NOT NULL DEFAULT '[]',
    inf_work_type           TEXT,
    inf_remote_eligible     INTEGER NOT NULL DEFAULT 0,
    inf_requires_computer   INTEGER,
    inf_experience_years    REAL,
    inf_studies_required    TEXT,
    -- Prompt v3 structured extraction (empty until llm-schema.py --prompt-version v3 runs).
    -- Scalars get columns; lists are JSON arrays queried with LIKE '%"value"%',
    -- the same shape inf_anomaly_flags already uses.
    v3_eqf_level            INTEGER,
    v3_study_level          TEXT NOT NULL DEFAULT '',
    v3_isced_fields         TEXT NOT NULL DEFAULT '[]',
    v3_study_labels         TEXT NOT NULL DEFAULT '[]',
    v3_skills               TEXT NOT NULL DEFAULT '[]',
    v3_languages            TEXT NOT NULL DEFAULT '[]',
    v3_credentials          TEXT NOT NULL DEFAULT '[]',
    v3_policy_domains       TEXT NOT NULL DEFAULT '[]',
    v3_exam_stages          TEXT NOT NULL DEFAULT '[]',
    v3_positions            INTEGER,
    inf_salary_min          REAL,
    inf_salary_max          REAL,
    -- Occupation, from the title dictionary (normalize-titles.py). Collapses
    -- `Îngrijitor`/`ÎNGRIJITOR`/`îngrijitor` into one facet value instead of three.
    occ_canonical           TEXT NOT NULL DEFAULT '',
    occ_cor_code            TEXT NOT NULL DEFAULT '',
    occ_isco_group          TEXT NOT NULL DEFAULT '',
    occ_confidence          TEXT NOT NULL DEFAULT '',
    -- Estimated gross pay from the 2026 draft salary grid (estimate-salaries.py).
    -- Derived, never scraped: 44 of 9,757 postings state a salary in the text.
    -- `sal_json` carries the coefficient, the grid rows used, the warnings and
    -- the disclaimer, so the detail page can show how the number was reached.
    sal_min                 REAL,
    sal_max                 REAL,
    sal_confidence          TEXT NOT NULL DEFAULT '',
    sal_variant             TEXT NOT NULL DEFAULT '',
    sal_json                TEXT,
    -- Prompt v4 scalars (empty until llm-schema.py --prompt-version v4 runs).
    v4_funding_source       TEXT NOT NULL DEFAULT '',
    v4_funding_programme    TEXT NOT NULL DEFAULT '',
    v4_employer_sector      TEXT NOT NULL DEFAULT '',
    v4_parent_institution   TEXT NOT NULL DEFAULT '',
    v4_application_deadline TEXT
);

CREATE INDEX idx_jp_employer   ON job_postings(employer_id);
CREATE INDEX idx_jp_judet      ON job_postings(judet_id);
CREATE INDEX idx_jp_locality   ON job_postings(locality);
CREATE INDEX idx_jp_eqf        ON job_postings(v3_eqf_level);
CREATE INDEX idx_jp_expires    ON job_postings(expires_at);
CREATE INDEX idx_jp_published  ON job_postings(published_at DESC);
CREATE INDEX idx_jp_level      ON job_postings(job_level);
CREATE INDEX idx_jp_family     ON job_postings(inf_profession_family);
CREATE INDEX idx_jp_seniority  ON job_postings(inf_seniority);
CREATE INDEX idx_jp_salary     ON job_postings(sal_min);
CREATE INDEX idx_jp_occupation ON job_postings(occ_canonical);
CREATE INDEX idx_jp_cor        ON job_postings(occ_cor_code);

CREATE TABLE calendar_events (
    id          INTEGER PRIMARY KEY,
    posting_id  INTEGER NOT NULL REFERENCES job_postings(id) ON DELETE CASCADE,
    eveniment   TEXT NOT NULL,
    data        TEXT NOT NULL,
    ora         TEXT
);
CREATE INDEX idx_ce_posting ON calendar_events(posting_id);
CREATE INDEX idx_ce_data    ON calendar_events(data);

-- One row, written last. Lets the deploy verify on the remote that the file that
-- landed is the file this run built, and gives the site a real "generated at"
-- distinct from MAX(last_seen_at), which is when the source was last scraped.
CREATE TABLE build_meta (
    id              INTEGER PRIMARY KEY CHECK (id = 1),
    built_at        TEXT NOT NULL,
    git_sha         TEXT NOT NULL DEFAULT '',
    source_host     TEXT NOT NULL DEFAULT '',
    active_only     INTEGER NOT NULL DEFAULT 0,
    job_postings    INTEGER NOT NULL DEFAULT 0,
    employers       INTEGER NOT NULL DEFAULT 0,
    calendar_events INTEGER NOT NULL DEFAULT 0
);

CREATE VIRTUAL TABLE job_postings_fts USING fts5(
    title,
    employer_name,
    judet_name,
    body_text,
    tokenize='unicode61 remove_diacritics 2'
);
""")


def fmt_date(v) -> str | None:
    if v is None:
        return None
    if hasattr(v, "isoformat"):
        return v.isoformat()
    return str(v)


def export(pg, con: sqlite3.Connection, active_only: bool = False):
    cur = pg.cursor()

    print("Exporting judete...", end=" ", flush=True)
    cur.execute("SELECT id, name, slug FROM jobs_judet ORDER BY id")
    rows = cur.fetchall()
    con.executemany("INSERT INTO judete(id, name, slug) VALUES (?,?,?)",
                    [(r["id"], r["name"], r["slug"]) for r in rows])
    print(f"{len(rows)} rows")

    print("Exporting employers...", end=" ", flush=True)
    cur.execute("SELECT id, name, slug FROM jobs_employer ORDER BY id")
    rows = cur.fetchall()
    con.executemany("INSERT INTO employers(id, name, slug) VALUES (?,?,?)",
                    [(r["id"], r["name"], r["slug"]) for r in rows])
    print(f"{len(rows)} rows")

    # "Active" means open *and* not withdrawn. A cancelled competition keeps the
    # real dates on its detail page, so filtering on expires_at alone republishes
    # it as an open job — see docs/activity-log.md, 2026-09-08.
    active_filter = (
        "WHERE jp.expires_at >= CURRENT_DATE AND NOT jp.cancelled" if active_only else ""
    )
    label = "active " if active_only else ""
    print(f"Exporting {label}job_postings...", end=" ", flush=True)
    cur.execute(f"""
        SELECT
            jp.id, jp.url, jp.title,
            jp.employer_id,
            e.name AS employer_name,
            jp.judet_id,
            COALESCE(j.name, '') AS judet_name,
            COALESCE(j.slug, '') AS judet_slug,
            jp.locality,
            jp.detalii_raw, jp.published_at, jp.expires_at, jp.tip,
            jp.job_level, jp.job_type, jp.employer_category, jp.categorie,
            jp.announcement_url, jp.body_markdown, jp.nr_posturi,
            jp.contact_phone, jp.contact_email, jp.contact_person,
            jp.data_limita_depunere, jp.data_proba_scrisa,
            jp.data_interviu, jp.data_rezultate_finale,
            jp.created_at, jp.updated_at, jp.last_seen_at,
            jp.other_links::text AS other_links,
            jp.attachment_meta::text AS attachment_meta,
            jp.inferred::text AS inferred,
            jp.schema_json::text AS schema_json,
            jp.salary_estimate::text AS salary_estimate,
            COALESCE(o.canonical, '')        AS occ_canonical,
            COALESCE(o.cor_code, '')         AS occ_cor_code,
            COALESCE(o.isco_group, '')       AS occ_isco_group,
            COALESCE(o.match_confidence, '') AS occ_confidence
        FROM jobs_jobposting jp
        JOIN jobs_employer e ON e.id = jp.employer_id
        LEFT JOIN jobs_judet j ON j.id = jp.judet_id
        LEFT JOIN jobs_occupation o ON o.id = jp.occupation_id
        {active_filter}
        ORDER BY jp.id
    """)

    batch = []
    total = 0
    for r in cur:
        inferred = {}
        try:
            inferred = json.loads(r["inferred"] or "{}")
        except (json.JSONDecodeError, TypeError):
            pass

        v3 = _v3_columns(r["schema_json"])
        v4 = _v4_columns(r["schema_json"])
        est = _salary_columns(r["salary_estimate"])

        anomaly_flags = inferred.get("anomaly_flags") or []
        requires_computer = inferred.get("requires_computer")
        if requires_computer is True:
            req_comp = 1
        elif requires_computer is False:
            req_comp = 0
        else:
            req_comp = None

        batch.append((
            r["id"], r["url"], r["title"] or "",
            r["employer_id"], r["employer_name"],
            r["judet_id"], r["judet_name"], r["judet_slug"],
            r["locality"] or "",
            r["detalii_raw"] or "",
            fmt_date(r["published_at"]), fmt_date(r["expires_at"]),
            r["tip"] or "",
            r["job_level"] or "", r["job_type"] or "",
            r["employer_category"] or "", r["categorie"] or "",
            r["announcement_url"] or "", r["body_markdown"] or "",
            r["nr_posturi"],
            r["contact_phone"] or "", r["contact_email"] or "", r["contact_person"] or "",
            fmt_date(r["data_limita_depunere"]), fmt_date(r["data_proba_scrisa"]),
            fmt_date(r["data_interviu"]), fmt_date(r["data_rezultate_finale"]),
            fmt_date(r["created_at"]), fmt_date(r["updated_at"]), fmt_date(r["last_seen_at"]),
            r["other_links"] or "[]",
            r["attachment_meta"] or "[]",
            r["inferred"] or "{}",
            r["schema_json"],
            v3["eqf_level"], v3["study_level"], v3["isced_fields"], v3["study_labels"],
            v3["skills"], v3["languages"], v3["credentials"],
            v3["policy_domains"], v3["exam_stages"], v3["positions"],
            inferred.get("profession_family"),
            inferred.get("seniority"),
            json.dumps(anomaly_flags, ensure_ascii=False),
            inferred.get("work_type"),
            1 if inferred.get("remote_eligible") else 0,
            req_comp,
            inferred.get("experience_years"),
            inferred.get("studies_required"),
            inferred.get("salary_min"),
            inferred.get("salary_max"),
            r["occ_canonical"], r["occ_cor_code"], r["occ_isco_group"], r["occ_confidence"],
            est["min"], est["max"], est["confidence"], est["variant"], r["salary_estimate"],
            v4["funding_source"], v4["funding_programme"],
            v4["employer_sector"], v4["parent_institution"], v4["application_deadline"],
        ))
        total += 1
        if len(batch) >= 500:
            _insert_postings(con, batch)
            batch = []
            print(".", end="", flush=True)

    if batch:
        _insert_postings(con, batch)
    print(f" {total} rows")

    print("Exporting calendar_events...", end=" ", flush=True)
    ce_filter = (
        "WHERE posting_id IN (SELECT id FROM jobs_jobposting "
        "WHERE expires_at >= CURRENT_DATE AND NOT cancelled)"
        if active_only else ""
    )
    cur.execute(f"""
        SELECT id, posting_id, eveniment, data, ora
        FROM jobs_calendarevent
        {ce_filter}
        ORDER BY id
    """)
    rows = cur.fetchall()
    con.executemany(
        "INSERT INTO calendar_events(id, posting_id, eveniment, data, ora) VALUES (?,?,?,?,?)",
        [(r["id"], r["posting_id"], r["eveniment"], fmt_date(r["data"]),
          str(r["ora"])[:5] if r["ora"] else None) for r in rows]
    )
    print(f"{len(rows)} rows")

    print("Building FTS5 index...", end=" ", flush=True)
    con.execute("""
        INSERT INTO job_postings_fts(rowid, title, employer_name, judet_name, body_text)
        SELECT id, title, employer_name, TRIM(locality || ' ' || judet_name),
               COALESCE(body_markdown, '')
        FROM job_postings
    """)
    print("done")

    write_build_meta(con, active_only=active_only)


def git_sha() -> str:
    """Short HEAD sha of the checkout that produced this file, or "" outside git."""
    try:
        out = subprocess.run(
            ["git", "rev-parse", "--short", "HEAD"],
            cwd=os.path.dirname(os.path.abspath(__file__)),
            capture_output=True, text=True, timeout=10,
        )
    except (OSError, subprocess.SubprocessError):
        return ""
    return out.stdout.strip() if out.returncode == 0 else ""


def write_build_meta(con: sqlite3.Connection, *, active_only: bool):
    counts = {
        t: con.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0]
        for t in ("job_postings", "employers", "calendar_events")
    }
    con.execute(
        "INSERT INTO build_meta(id, built_at, git_sha, source_host, active_only, "
        "job_postings, employers, calendar_events) VALUES (1,?,?,?,?,?,?,?)",
        (
            datetime.now(timezone.utc).isoformat(timespec="seconds"),
            git_sha(),
            socket.gethostname(),
            int(active_only),
            counts["job_postings"],
            counts["employers"],
            counts["calendar_events"],
        ),
    )


def _salary_columns(salary_json_text) -> dict:
    """Flatten `JobPosting.salary_estimate` into sortable columns.

    Empty until `estimate-salaries.py` has run, so the salary facet simply stays
    hidden rather than half-populated.
    """
    empty = {"min": None, "max": None, "confidence": "", "variant": ""}
    if not salary_json_text:
        return empty
    try:
        est = json.loads(salary_json_text)
    except (json.JSONDecodeError, TypeError):
        return empty
    if not isinstance(est, dict):
        return empty
    return {
        "min": est.get("lei_min"), "max": est.get("lei_max"),
        "confidence": est.get("incredere") or "", "variant": est.get("varianta") or "",
    }


def _v4_columns(schema_json_text) -> dict:
    """Flatten the prompt-v4 scalars worth faceting or sorting on.

    A v3 payload has none of these keys and returns empty, exactly as a v2
    payload does for `_v3_columns`.
    """
    empty = {"funding_source": "", "funding_programme": "", "employer_sector": "",
             "parent_institution": "", "application_deadline": None}
    if not schema_json_text:
        return empty
    try:
        s = json.loads(schema_json_text)
    except (json.JSONDecodeError, TypeError):
        return empty
    if not isinstance(s, dict):
        return empty
    funding = s.get("funding") or {}
    employer = s.get("employer_context") or {}
    calendar = s.get("competition_calendar") or {}
    return {
        "funding_source": funding.get("source") or "",
        "funding_programme": funding.get("programme") or "",
        "employer_sector": employer.get("sector") or "",
        "parent_institution": employer.get("parent_institution") or "",
        "application_deadline": calendar.get("application_deadline"),
    }


def _v3_columns(schema_json_text) -> dict:
    """Flatten prompt-v3 `schema_json` into queryable SQLite columns.

    Returns empty defaults for a v2 row or no schema at all, so the browse
    facets simply stay empty until a v3 extraction has run.
    """
    empty = {
        "eqf_level": None, "study_level": "", "isced_fields": "[]", "study_labels": "[]",
        "skills": "[]", "languages": "[]", "credentials": "[]",
        "policy_domains": "[]", "exam_stages": "[]", "positions": None,
    }
    if not schema_json_text:
        return empty
    try:
        s = json.loads(schema_json_text)
    except (json.JSONDecodeError, TypeError):
        return empty
    if not isinstance(s, dict) or "education" not in s:
        return empty  # v2 payload

    def dumps(seq):
        return json.dumps(sorted({x for x in seq if x}), ensure_ascii=False)

    edu = s.get("education") or {}
    fields = edu.get("fields_of_study") or []
    langs = s.get("language_list") or []

    return {
        "eqf_level": edu.get("eqf_level"),
        "study_level": edu.get("minimum_level") or "",
        "isced_fields": dumps(f.get("isced_field") for f in fields),
        "study_labels": dumps(f.get("label_ro") for f in fields),
        "skills": dumps(k.get("label") for k in (s.get("skill_list") or [])),
        # "en:B2" keeps language and level together for a single LIKE probe.
        "languages": dumps(
            f"{l.get('iso_code') or l.get('language')}:{l.get('cefr') or ''}".rstrip(":")
            for l in langs
        ),
        "credentials": dumps(c.get("kind") for c in (s.get("credentials") or [])),
        "policy_domains": dumps(s.get("policy_domains") or []),
        "exam_stages": dumps(s.get("exam_stages") or []),
        "positions": len(s.get("positions") or []) or None,
    }


def _insert_postings(con: sqlite3.Connection, batch: list):
    con.executemany("""
        INSERT INTO job_postings(
            id, url, title,
            employer_id, employer_name,
            judet_id, judet_name, judet_slug, locality,
            detalii_raw, published_at, expires_at, tip,
            job_level, job_type, employer_category, categorie,
            announcement_url, body_markdown, nr_posturi,
            contact_phone, contact_email, contact_person,
            data_limita_depunere, data_proba_scrisa,
            data_interviu, data_rezultate_finale,
            created_at, updated_at, last_seen_at,
            other_links, attachment_meta, inferred, schema_json,
            v3_eqf_level, v3_study_level, v3_isced_fields, v3_study_labels,
            v3_skills, v3_languages, v3_credentials,
            v3_policy_domains, v3_exam_stages, v3_positions,
            inf_profession_family, inf_seniority, inf_anomaly_flags,
            inf_work_type, inf_remote_eligible, inf_requires_computer,
            inf_experience_years, inf_studies_required,
            inf_salary_min, inf_salary_max,
            occ_canonical, occ_cor_code, occ_isco_group, occ_confidence,
            sal_min, sal_max, sal_confidence, sal_variant, sal_json,
            v4_funding_source, v4_funding_programme,
            v4_employer_sector, v4_parent_institution, v4_application_deadline
        ) VALUES (
            ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
            ?,?,?,?,?,?,?,?,?,?,?,?,?,?
        )
    """, batch)


def previous_build(path: str) -> dict | None:
    """Read job_postings count + active_only out of an existing export.

    Returns None when the file is absent, unreadable, or predates build_meta —
    every one of those means "no baseline", not "a failure".
    """
    if not os.path.exists(path):
        return None
    try:
        con = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
    except sqlite3.Error:
        return None
    try:
        rows = con.execute("SELECT COUNT(*) FROM job_postings").fetchone()[0]
        try:
            active_only = con.execute(
                "SELECT active_only FROM build_meta WHERE id = 1"
            ).fetchone()[0]
        except sqlite3.Error:
            active_only = None          # pre-build_meta export
        return {"job_postings": rows, "active_only": active_only}
    except sqlite3.Error:
        return None
    finally:
        con.close()


def verify(path: str, *, min_rows: int, previous: dict | None, active_only: bool) -> int:
    """Fail loudly rather than promote a broken or suspiciously empty export.

    The live site once served three active postings for five weeks because a bad
    export was deployed and nobody read the output — see docs/backlog.md. These are
    the checks that would have stopped it at the door.

    Returns the job_postings count.
    """
    try:
        con = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
    except sqlite3.Error as exc:
        raise SystemExit(f"ERROR: cannot open the export at {path}: {exc}") from exc
    try:
        try:
            integrity = con.execute("PRAGMA integrity_check").fetchone()[0]
            rows = con.execute("SELECT COUNT(*) FROM job_postings").fetchone()[0]
            fts = con.execute("SELECT COUNT(*) FROM job_postings_fts").fetchone()[0]
        except sqlite3.Error as exc:
            # A truncated or scribbled-on file. Report it as a refusal to promote,
            # not as a traceback, so the caller's cleanup runs and cron logs a reason.
            raise SystemExit(f"ERROR: the export at {path} is unreadable: {exc}") from exc

        if integrity != "ok":
            raise SystemExit(f"ERROR: integrity_check on {path} returned {integrity!r}")

        if rows < min_rows:
            raise SystemExit(
                f"ERROR: export has {rows} job_postings, below the --min-rows floor "
                f"of {min_rows}. Not promoting. Re-run with --force to override."
            )
        if fts != rows:
            print(
                f"  WARNING: FTS index has {fts} rows against {rows} postings — "
                f"search will be incomplete.",
                file=sys.stderr,
            )

        # Only compare against a baseline built the same way; an --active-only run
        # is legitimately a fifth the size of a full one.
        if previous and previous["active_only"] in (None, int(active_only)):
            before = previous["job_postings"]
            if before and rows < before * 0.5:
                raise SystemExit(
                    f"ERROR: export has {rows} job_postings, down from {before} in the "
                    f"file it would replace. That is a collapse, not a day's churn — "
                    f"check the scrape and the import, then --force if it is real."
                )
        return rows
    finally:
        con.close()


def main():
    ap = argparse.ArgumentParser(description="Export PostgreSQL → posturi.sqlite")
    ap.add_argument("--out", default="webapp-php/posturi.sqlite", help="Output SQLite file path")
    ap.add_argument("--active-only", action="store_true",
                    help="Only export postings with expires_at >= today")
    ap.add_argument("--min-rows", type=int, default=100, metavar="N",
                    help="Refuse to promote an export with fewer than N job_postings "
                         "(default: 100). A run that also has a previous export to "
                         "compare against additionally refuses a >50%% drop.")
    ap.add_argument("--force", action="store_true",
                    help="Promote even if the row-count floors would refuse. "
                         "Integrity check still applies.")
    args = ap.parse_args()

    out_path = args.out
    # Build beside the target, never onto it: create_schema() DROPs every table, so
    # a crash used to leave the deploy source gutted, ready to ship an empty site.
    tmp_path = out_path + ".tmp"
    previous = previous_build(out_path)

    print("Connecting to PostgreSQL...")
    try:
        pg = pg_connect()
    except Exception as e:
        print(f"ERROR: Cannot connect to PostgreSQL: {e}", file=sys.stderr)
        sys.exit(1)

    for stale in (tmp_path, tmp_path + "-wal", tmp_path + "-shm"):
        if os.path.exists(stale):
            os.remove(stale)

    print(f"Building {tmp_path}...")
    con = sqlite3.connect(tmp_path)
    try:
        create_schema(con)
        with con:
            export(pg, con, active_only=args.active_only)
        con.execute("PRAGMA optimize;")
        con.commit()
        con.execute("VACUUM;")          # compacts the FTS index; smaller rsync delta
        con.commit()
        ver = con.execute("SELECT sqlite_version()").fetchone()[0]
    finally:
        con.close()
        pg.close()

    try:
        rows = verify(
            tmp_path,
            min_rows=0 if args.force else args.min_rows,
            previous=None if args.force else previous,
            active_only=args.active_only,
        )
    except SystemExit:
        os.remove(tmp_path)     # a rejected build is 50 MB of nothing
        raise

    os.replace(tmp_path, out_path)
    # A -wal/-shm pair from a WAL-era build describes the file we just replaced.
    for stale in (out_path + "-wal", out_path + "-shm"):
        if os.path.exists(stale):
            os.remove(stale)

    size_mb = os.path.getsize(out_path) / (1024 * 1024)
    print(f"\nDone. SQLite file: {out_path}  ({size_mb:.1f} MB)")
    print(f"  job_postings: {rows}")
    if previous:
        print(f"  previous:     {previous['job_postings']}")
    print(f"  SQLite version: {ver}")


if __name__ == "__main__":
    main()
