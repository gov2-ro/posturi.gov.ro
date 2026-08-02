#!/usr/bin/env python3
"""Export PostgreSQL data to posturi.sqlite for the PHP shared-hosting webapp.

Usage:
    python export-to-sqlite.py                    # all postings → webapp-php/posturi.sqlite
    python export-to-sqlite.py --active-only      # active postings only (for deployment)
    DATABASE_URL=postgres://... python export-to-sqlite.py --active-only
    python export-to-sqlite.py --out /path/to/posturi.sqlite

Output: posturi.sqlite with tables:
  job_postings   — main posting rows + extracted inferred fields
  employers      — canonical employer names
  judete         — county names
  calendar_events — competition timeline
  job_postings_fts — FTS5 virtual table (rowid = job_posting id)
"""

import argparse
import json
import os
import sqlite3
import sys
from datetime import date
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
        host=r.hostname or "localhost",
        port=r.port or 5432,
        row_factory=dict_row,
    )


def create_schema(con: sqlite3.Connection):
    con.executescript("""
PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;

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
    inf_salary_min          REAL,
    inf_salary_max          REAL
);

CREATE INDEX idx_jp_employer   ON job_postings(employer_id);
CREATE INDEX idx_jp_judet      ON job_postings(judet_id);
CREATE INDEX idx_jp_expires    ON job_postings(expires_at);
CREATE INDEX idx_jp_published  ON job_postings(published_at DESC);
CREATE INDEX idx_jp_level      ON job_postings(job_level);
CREATE INDEX idx_jp_family     ON job_postings(inf_profession_family);
CREATE INDEX idx_jp_seniority  ON job_postings(inf_seniority);

CREATE TABLE calendar_events (
    id          INTEGER PRIMARY KEY,
    posting_id  INTEGER NOT NULL REFERENCES job_postings(id) ON DELETE CASCADE,
    eveniment   TEXT NOT NULL,
    data        TEXT NOT NULL,
    ora         TEXT
);
CREATE INDEX idx_ce_posting ON calendar_events(posting_id);
CREATE INDEX idx_ce_data    ON calendar_events(data);

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

    active_filter = "WHERE jp.expires_at >= CURRENT_DATE" if active_only else ""
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
            jp.detalii_raw, jp.published_at, jp.expires_at, jp.tip,
            jp.job_level, jp.job_type, jp.employer_category, jp.categorie,
            jp.announcement_url, jp.body_markdown, jp.nr_posturi,
            jp.contact_phone, jp.contact_email, jp.contact_person,
            jp.data_limita_depunere, jp.data_proba_scrisa,
            jp.data_interviu, jp.data_rezultate_finale,
            jp.created_at, jp.updated_at, jp.last_seen_at,
            jp.other_links::text AS other_links,
            jp.inferred::text AS inferred,
            jp.schema_json::text AS schema_json
        FROM jobs_jobposting jp
        JOIN jobs_employer e ON e.id = jp.employer_id
        LEFT JOIN jobs_judet j ON j.id = jp.judet_id
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
            r["inferred"] or "{}",
            r["schema_json"],
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
        "WHERE posting_id IN (SELECT id FROM jobs_jobposting WHERE expires_at >= CURRENT_DATE)"
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
        SELECT id, title, employer_name, judet_name,
               COALESCE(body_markdown, '')
        FROM job_postings
    """)
    print("done")


def _insert_postings(con: sqlite3.Connection, batch: list):
    con.executemany("""
        INSERT INTO job_postings(
            id, url, title,
            employer_id, employer_name,
            judet_id, judet_name, judet_slug,
            detalii_raw, published_at, expires_at, tip,
            job_level, job_type, employer_category, categorie,
            announcement_url, body_markdown, nr_posturi,
            contact_phone, contact_email, contact_person,
            data_limita_depunere, data_proba_scrisa,
            data_interviu, data_rezultate_finale,
            created_at, updated_at, last_seen_at,
            other_links, inferred, schema_json,
            inf_profession_family, inf_seniority, inf_anomaly_flags,
            inf_work_type, inf_remote_eligible, inf_requires_computer,
            inf_experience_years, inf_studies_required,
            inf_salary_min, inf_salary_max
        ) VALUES (
            ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
        )
    """, batch)


def main():
    ap = argparse.ArgumentParser(description="Export PostgreSQL → posturi.sqlite")
    ap.add_argument("--out", default="webapp-php/posturi.sqlite", help="Output SQLite file path")
    ap.add_argument("--active-only", action="store_true",
                    help="Only export postings with expires_at >= today")
    args = ap.parse_args()

    out_path = args.out
    print(f"Connecting to PostgreSQL...")
    try:
        pg = pg_connect()
    except Exception as e:
        print(f"ERROR: Cannot connect to PostgreSQL: {e}", file=sys.stderr)
        sys.exit(1)

    print(f"Writing to {out_path}...")
    con = sqlite3.connect(out_path)
    try:
        create_schema(con)
        with con:
            export(pg, con, active_only=args.active_only)
        con.execute("PRAGMA optimize;")
        con.commit()
        print(f"\nDone. SQLite file: {out_path}")

        # Verify
        total = con.execute("SELECT COUNT(*) FROM job_postings").fetchone()[0]
        fts_total = con.execute("SELECT COUNT(*) FROM job_postings_fts").fetchone()[0]
        print(f"  job_postings: {total}")
        print(f"  job_postings_fts: {fts_total}")
        ver = con.execute("SELECT sqlite_version()").fetchone()[0]
        print(f"  SQLite version: {ver}")
    finally:
        con.close()
        pg.close()


if __name__ == "__main__":
    main()
