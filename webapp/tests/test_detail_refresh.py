"""FIX-03 — detail refresh, cancellation from the detail page, source provenance.

The fetcher side is exercised with a fake HTTP layer against old/new markup
fixtures; the parser side checks status extraction and the new CSV columns;
the import side checks the status-over-index precedence and the provenance
fields. See docs/specs/2026-10-03-project-audit/03-source-refresh.md.
"""

from __future__ import annotations

import csv
import importlib.util
import json
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

from apps.jobs.models import JobPosting

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))


def _load_module(name, path):
    spec = importlib.util.spec_from_file_location(name, REPO_ROOT / path)
    mod = importlib.util.module_from_spec(spec)
    sys.modules[name] = mod
    spec.loader.exec_module(mod)
    return mod


NEW_PAGE = """
<html><body>
  <div class="pg-wrap pg-job-single">
    <h1 class="pg-title">Referent</h1>
    <div class="pg-subline"><span>Angajator</span><span>Primăria Cluj</span></div>
    <div class="pg-prose"><p>Concurs pentru postul de referent.</p></div>
  </div>
</body></html>
"""

NEW_PAGE_CANCELLED = NEW_PAGE.replace(
    '<div class="pg-wrap pg-job-single">',
    '<div class="pg-status is-off">Anulat</div>\n<div class="pg-wrap pg-job-single">',
)
NEW_PAGE_LIVE = NEW_PAGE.replace(
    '<div class="pg-wrap pg-job-single">',
    '<div class="pg-status is-live">Anunț activ</div>\n<div class="pg-wrap pg-job-single">',
)

OLD_PAGE = '<html><body><main id="main" class="site-main"><p>Anunț vechi.</p></main></body></html>'

INDEX_HEADERS = ["url", "pozitie", "angajator", "detalii", "publicat_in", "expira_in",
                 "judet", "url_judet", "tip", "updates"]


def write_index(path: Path, rows: list[dict]) -> None:
    with path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=INDEX_HEADERS)
        w.writeheader()
        w.writerows(rows)


def row(url: str, expira_in: str = "Expiră in  30/12/2026") -> dict:
    return {"url": url, "pozitie": "Post", "angajator": "Instituție",
            "detalii": "", "publicat_in": "Data publicării: 01.10.2026",
            "expira_in": expira_in, "judet": "Cluj", "url_judet": "",
            "tip": "Permanent", "updates": ""}


class FakeResponse:
    def __init__(self, text: str = NEW_PAGE, status_code: int = 200):
        self.text = text
        self.status_code = status_code

    def raise_for_status(self):
        if self.status_code >= 400:
            raise RuntimeError(f"HTTP {self.status_code}")


@pytest.fixture(scope="module")
def fetchmod():
    return _load_module("fetch_anunturi_under_test", "fetch-anunturi.py")


@pytest.fixture
def fetch_env(fetchmod, tmp_path, monkeypatch):
    """Isolate the fetcher in a temp cwd with a fake HTTP layer and no sleeps."""
    monkeypatch.chdir(tmp_path)
    monkeypatch.setattr(fetchmod.time, "sleep", lambda s: None)
    monkeypatch.setattr(fetchmod.random, "uniform", lambda a, b: 0)
    calls = []

    def fake_get(url, headers=None, timeout=None):
        calls.append(url)
        return FakeResponse(NEW_PAGE)

    monkeypatch.setattr(fetchmod.requests, "get", fake_get)
    return {"calls": calls, "get": fake_get}


class TestFetchRefresh:
    def test_first_fetch_writes_html_and_sidecar(self, fetchmod, fetch_env, tmp_path):
        csvp = tmp_path / "index.csv"
        write_index(csvp, [row("https://posturi.gov.ro/joburi/referent-cluj/")])

        summary = fetchmod.process_csv(str(csvp), refresh_hours=24, max_refresh=200)

        assert summary["fetched_new"] == 1 and summary["total"] == 1
        html = list(tmp_path.rglob("*.html"))
        metas = list(tmp_path.rglob("*.meta.json"))
        assert len(html) == 1 and len(metas) == 1
        meta = json.loads(metas[0].read_text())
        assert meta["url"].startswith("https://posturi.gov.ro/")
        assert len(meta["content_hash"]) == 64
        assert meta["status"] == ""  # no .pg-status in the plain fixture
        assert not list(tmp_path.rglob("*.tmp")), "no temp files left"

    def test_fresh_cache_is_skipped_without_http(self, fetchmod, fetch_env, tmp_path):
        csvp = tmp_path / "index.csv"
        write_index(csvp, [row("https://posturi.gov.ro/joburi/referent-cluj/")])
        fetchmod.process_csv(str(csvp))

        summary = fetchmod.process_csv(str(csvp))
        assert summary["skipped_fresh"] == 1
        assert len(fetch_env["calls"]) == 1  # only the first run fetched

    def test_due_cache_is_refreshed_and_unchanged_detected(self, fetchmod, fetch_env, tmp_path):
        csvp = tmp_path / "index.csv"
        write_index(csvp, [row("https://posturi.gov.ro/joburi/referent-cluj/")])
        fetchmod.process_csv(str(csvp))

        # Age the sidecar past the refresh window.
        metas = list(tmp_path.rglob("*.meta.json"))
        meta = json.loads(metas[0].read_text())
        old = (datetime.now(timezone.utc) - timedelta(hours=30)).isoformat()
        meta["fetched_at"] = old
        metas[0].write_text(json.dumps(meta))

        summary = fetchmod.process_csv(str(csvp))
        assert summary["refreshed"] == 1 and summary["unchanged"] == 1
        assert len(fetch_env["calls"]) == 2

    def test_changed_content_produces_a_new_hash(self, fetchmod, fetch_env, tmp_path):
        csvp = tmp_path / "index.csv"
        write_index(csvp, [row("https://posturi.gov.ro/joburi/referent-cluj/")])
        fetchmod.process_csv(str(csvp))
        metas = list(tmp_path.rglob("*.meta.json"))
        meta = json.loads(metas[0].read_text())
        first_hash = meta["content_hash"]
        meta["fetched_at"] = (datetime.now(timezone.utc) - timedelta(hours=30)).isoformat()
        metas[0].write_text(json.dumps(meta))

        def revised_get(url, headers=None, timeout=None):
            return FakeResponse(NEW_PAGE.replace("referent", "REFERENT REVIZUIT"))

        fetch_env["get"] = revised_get
        fetchmod.requests.get = revised_get

        summary = fetchmod.process_csv(str(csvp))
        assert summary["changed"] == 1
        meta = json.loads(metas[0].read_text())
        assert meta["content_hash"] != first_hash

    def test_cancellation_markup_is_recorded(self, fetchmod, fetch_env, tmp_path):
        csvp = tmp_path / "index.csv"
        write_index(csvp, [row("https://posturi.gov.ro/joburi/anulat-test/")])
        fetch_env["get"] = lambda url, headers=None, timeout=None: FakeResponse(NEW_PAGE_CANCELLED)
        fetchmod.requests.get = fetch_env["get"]

        fetchmod.process_csv(str(csvp))
        meta = json.loads(list(tmp_path.rglob("*.meta.json"))[0].read_text())
        assert meta["status"] == "anulat"

    def test_live_markup_is_recorded(self, fetchmod, fetch_env, tmp_path):
        csvp = tmp_path / "index.csv"
        write_index(csvp, [row("https://posturi.gov.ro/joburi/live-test/")])
        fetch_env["get"] = lambda url, headers=None, timeout=None: FakeResponse(NEW_PAGE_LIVE)
        fetchmod.requests.get = fetch_env["get"]

        fetchmod.process_csv(str(csvp))
        meta = json.loads(list(tmp_path.rglob("*.meta.json"))[0].read_text())
        assert meta["status"] == "live"

    def test_malformed_200_keeps_the_previous_cache(self, fetchmod, fetch_env, tmp_path):
        csvp = tmp_path / "index.csv"
        url = "https://posturi.gov.ro/joburi/referent-cluj/"
        write_index(csvp, [row(url)])
        fetchmod.process_csv(str(csvp))
        html_path = list(tmp_path.rglob("*.html"))[0]
        good_bytes = html_path.read_bytes()

        # Age it, then serve a portal page with 200.
        metas = list(tmp_path.rglob("*.meta.json"))
        meta = json.loads(metas[0].read_text())
        meta["fetched_at"] = (datetime.now(timezone.utc) - timedelta(hours=30)).isoformat()
        metas[0].write_text(json.dumps(meta))
        fetch_env["get"] = lambda u, headers=None, timeout=None: FakeResponse("<html><body>login</body></html>")
        fetchmod.requests.get = fetch_env["get"]

        summary = fetchmod.process_csv(str(csvp))
        assert summary["failed"] == 1
        assert html_path.read_bytes() == good_bytes, "previous good cache preserved"

    def test_404_is_terminal_and_preserves_cache(self, fetchmod, fetch_env, tmp_path):
        csvp = tmp_path / "index.csv"
        write_index(csvp, [row("https://posturi.gov.ro/joburi/referent-cluj/")])
        fetchmod.process_csv(str(csvp))
        html_path = list(tmp_path.rglob("*.html"))[0]
        good_bytes = html_path.read_bytes()

        metas = list(tmp_path.rglob("*.meta.json"))
        meta = json.loads(metas[0].read_text())
        meta["fetched_at"] = (datetime.now(timezone.utc) - timedelta(hours=30)).isoformat()
        metas[0].write_text(json.dumps(meta))
        fetch_env["get"] = lambda u, headers=None, timeout=None: FakeResponse(status_code=404)
        fetchmod.requests.get = fetch_env["get"]

        summary = fetchmod.process_csv(str(csvp))
        assert summary["failed"] == 1
        assert html_path.read_bytes() == good_bytes

    def test_refresh_cap_bounds_one_run(self, fetchmod, fetch_env, tmp_path):
        csvp = tmp_path / "index.csv"
        write_index(csvp, [
            row(f"https://posturi.gov.ro/joburi/post-{i}/") for i in range(3)
        ])
        fetchmod.process_csv(str(csvp))
        # Age every sidecar, then cap the run at one refresh.
        for m in tmp_path.rglob("*.meta.json"):
            meta = json.loads(m.read_text())
            meta["fetched_at"] = (datetime.now(timezone.utc) - timedelta(hours=30)).isoformat()
            m.write_text(json.dumps(meta))

        summary = fetchmod.process_csv(str(csvp), max_refresh=1)
        assert summary["refreshed"] + summary["failed"] == 1
        assert summary["skipped_cap"] == 2

    def test_legacy_cache_without_sidecar_is_due_and_stamped_only_on_fetch(self, fetchmod, fetch_env, tmp_path):
        csvp = tmp_path / "index.csv"
        write_index(csvp, [row("https://posturi.gov.ro/joburi/legacy-test/")])
        # Pre-place a legacy cache file (no sidecar) — the pre-FIX-03 layout.
        legacy_dir = tmp_path / "data" / "anunturi" / "2026" / "10" / "01"
        legacy_dir.mkdir(parents=True)
        legacy_html = legacy_dir / "legacy-test.html"
        legacy_html.write_text(NEW_PAGE, encoding="utf-8")
        # But the fetcher computes the date dir from publicat_in (01.10.2026 → 2026/10/01)
        # — the above is that dir, so the legacy file IS found.

        summary = fetchmod.process_csv(str(csvp))
        assert summary["refreshed"] == 1, "legacy cache (age unknown) counts as due"
        meta_path = legacy_dir / "legacy-test.meta.json"
        assert meta_path.exists()
        fetched = datetime.fromisoformat(json.loads(meta_path.read_text())["fetched_at"])
        # The stamp is the real fetch time, not a backfill of "now" on a skip.
        assert (datetime.now(timezone.utc) - fetched).total_seconds() < 60

    def test_index_cancelled_uncached_row_is_skipped(self, fetchmod, fetch_env, tmp_path):
        csvp = tmp_path / "index.csv"
        write_index(csvp, [row("https://posturi.gov.ro/?post_type=pg_job&p=99999", "Anunț anulat")])
        summary = fetchmod.process_csv(str(csvp))
        assert summary["skipped_cancelled"] == 1 and not fetch_env["calls"]


# ---------------------------------------------------------------- parser side


@pytest.fixture(scope="module")
def parsemod():
    return _load_module("parse_anunturi_under_test", "parse-anunturi.py")


class TestParseProvenance:
    def test_status_from_sidecar_reaches_the_csv(self, parsemod, tmp_path, monkeypatch):
        monkeypatch.chdir(tmp_path)
        html = tmp_path / "joburi" / "cancelled.html"
        html.parent.mkdir(parents=True)
        html.write_text(NEW_PAGE_CANCELLED, encoding="utf-8")
        (tmp_path / "joburi" / "cancelled.meta.json").write_text(json.dumps({
            "url": "https://posturi.gov.ro/joburi/cancelled/",
            "fetched_at": "2026-10-04T10:00:00+00:00",
            "content_hash": "a" * 64,
            "status": "anulat",
        }), encoding="utf-8")

        # The module reads the index CSV at import time; give it one that
        # resolves the slug (the parse module was imported before chdir, so
        # patch its index lookup instead).
        monkeypatch.setattr(parsemod, "_SLUG_INDEX", {"cancelled": "https://posturi.gov.ro/joburi/cancelled/"})
        monkeypatch.setattr(parsemod, "_INDEX_DATES", {})

        details = parsemod.extract_job_details(str(html))
        assert details["detail_status"] == "anulat"
        assert details["detail_fetched_at"] == "2026-10-04T10:00:00+00:00"
        assert details["detail_content_hash"] == "a" * 64

    def test_status_falls_back_to_the_cached_html(self, parsemod, tmp_path, monkeypatch):
        monkeypatch.chdir(tmp_path)
        html = tmp_path / "joburi" / "live-no-sidecar.html"
        html.parent.mkdir(parents=True)
        html.write_text(NEW_PAGE_LIVE, encoding="utf-8")
        monkeypatch.setattr(parsemod, "_SLUG_INDEX", {"live-no-sidecar": "https://posturi.gov.ro/joburi/live-no-sidecar/"})
        monkeypatch.setattr(parsemod, "_INDEX_DATES", {})

        details = parsemod.extract_job_details(str(html))
        assert details["detail_status"] == "live"
        assert details["detail_fetched_at"] == ""  # legacy: unknown, not now
        assert details["detail_content_hash"] == ""

    def test_unknown_markup_stays_unknown(self, parsemod, tmp_path, monkeypatch):
        monkeypatch.chdir(tmp_path)
        html = tmp_path / "joburi" / "plain.html"
        html.parent.mkdir(parents=True)
        html.write_text(NEW_PAGE, encoding="utf-8")
        monkeypatch.setattr(parsemod, "_SLUG_INDEX", {"plain": "https://posturi.gov.ro/joburi/plain/"})
        monkeypatch.setattr(parsemod, "_INDEX_DATES", {})

        details = parsemod.extract_job_details(str(html))
        assert details["detail_status"] == ""

    def test_csv_writer_carries_the_new_columns(self, parsemod, tmp_path):
        out = tmp_path / "anunturi.csv"
        parsemod.save_to_csv([{
            "source_url": "https://posturi.gov.ro/joburi/x/", "job_title": "T",
            "employer": "E", "location": "", "job_level": "", "job_type": "",
            "employer_category": "", "categorie": "", "announcement_url": "",
            "main_body_markdown": "body", "other_links": [], "nr_posturi": "1",
            "contact_telefon": "", "contact_email": "", "contact_persoana": "",
            "data_limita_depunere": "", "data_proba_scrisa": "", "data_interviu": "",
            "data_rezultate_finale": "", "data_publicare": "", "data_expirare": "",
            "detail_status": "anulat", "detail_fetched_at": "2026-10-04T10:00:00+00:00",
            "detail_content_hash": "b" * 64, "_calendar_rows": [],
        }], str(out))
        with out.open(newline="", encoding="utf-8") as f:
            rows = list(csv.DictReader(f))
        assert rows[0]["Status"] == "Anulat"
        assert rows[0]["Detail Fetched At"] == "2026-10-04T10:00:00+00:00"
        assert rows[0]["Detail Content Hash"] == "b" * 64


# ---------------------------------------------------------------- FIX-06 pieces


@pytest.fixture(scope="module")
def indexmod():
    return _load_module("fetch_index_under_test", "fetch-index.py")


@pytest.fixture(scope="module")
def exportmod():
    return _load_module("export_sqlite_fix06_under_test", "export-to-sqlite.py")


class TestScanStamp:
    def test_stamp_written_atomically_with_outcome(self, indexmod, tmp_path, monkeypatch):
        monkeypatch.chdir(tmp_path)
        indexmod.write_scan_stamp(pages_scanned=9, pages_total=12, early_stopped=True,
                                  cards=40, new=2, updated=1, outcome="partial-early-stop")
        stamp = json.loads((tmp_path / "data" / "index-scan.json").read_text())
        assert stamp["outcome"] == "partial-early-stop"
        assert stamp["early_stopped"] is True
        assert stamp["checked_at"]  # a real UTC stamp
        assert not list(tmp_path.rglob("*.tmp")), "atomic write leaves no temp"

    def test_complete_scan_stamps_complete(self, indexmod, tmp_path, monkeypatch):
        monkeypatch.chdir(tmp_path)
        indexmod.write_scan_stamp(pages_scanned=12, pages_total=12, early_stopped=False,
                                  cards=40, new=2, updated=1, outcome="complete")
        stamp = json.loads((tmp_path / "data" / "index-scan.json").read_text())
        assert stamp["outcome"] == "complete"


class TestBuildMetaProvenance:
    def test_read_index_scan_tolerates_absence_and_garbage(self, exportmod, tmp_path, monkeypatch):
        monkeypatch.chdir(tmp_path)
        assert exportmod.read_index_scan() == {}
        (tmp_path / "data").mkdir()
        (tmp_path / "data" / "index-scan.json").write_text("not json", encoding="utf-8")
        assert exportmod.read_index_scan() == {}

    def test_write_build_meta_records_run_and_source_observations(self, exportmod, tmp_path, monkeypatch):
        import sqlite3

        monkeypatch.chdir(tmp_path)
        (tmp_path / "data").mkdir()
        (tmp_path / "data" / "index-scan.json").write_text(json.dumps({
            "checked_at": "2026-10-04T06:00:00+00:00", "pages_scanned": 12,
            "early_stopped": False, "cards": 40, "new": 2, "updated": 1,
            "outcome": "complete",
        }), encoding="utf-8")

        con = sqlite3.connect(":memory:")
        con.executescript("""
            CREATE TABLE build_meta (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                built_at TEXT NOT NULL, git_sha TEXT NOT NULL DEFAULT '',
                source_host TEXT NOT NULL DEFAULT '', active_only INTEGER NOT NULL DEFAULT 0,
                job_postings INTEGER NOT NULL DEFAULT 0, employers INTEGER NOT NULL DEFAULT 0,
                calendar_events INTEGER NOT NULL DEFAULT 0,
                run_id TEXT NOT NULL DEFAULT '', index_checked_at TEXT,
                index_scan_pages INTEGER, index_scan_complete INTEGER NOT NULL DEFAULT 0,
                detail_fetched_at_max TEXT, detail_fetched_rows INTEGER
            );
            CREATE TABLE job_postings (id INTEGER PRIMARY KEY);
            CREATE TABLE employers (id INTEGER PRIMARY KEY);
            CREATE TABLE calendar_events (id INTEGER PRIMARY KEY);
        """)
        exportmod.write_build_meta(
            con, active_only=True, run_id="run-42",
            detail_fetched_at_max="2026-10-04T05:00:00+00:00", detail_fetched_rows=37,
        )
        row = con.execute("SELECT * FROM build_meta WHERE id = 1").fetchone()
        cols = [d[0] for d in con.execute("SELECT * FROM build_meta LIMIT 0").description]
        meta = dict(zip(cols, row))
        assert meta["run_id"] == "run-42"
        assert meta["index_checked_at"] == "2026-10-04T06:00:00+00:00"
        assert meta["index_scan_complete"] == 1
        assert meta["detail_fetched_at_max"] == "2026-10-04T05:00:00+00:00"
        assert meta["detail_fetched_rows"] == 37


# ---------------------------------------------------------------- import side


INDEX_ROWS = [row("https://posturi.gov.ro/anunt/medic-specialist-1/",
                  "Expiră in  30/06/2026")]


def anunturi_row(status="", fetched_at="", content_hash=""):
    base = {
        "Source URL": "https://posturi.gov.ro/anunt/medic-specialist-1/",
        "Job Title": "Medic specialist", "Employer": "Spitalul Județean Cluj",
        "Location": "Cluj", "Job Level": "execuție", "Job Type": "Permanent",
        "Employer Category": "Instituții județene", "Categorie": "Funcție contractuală",
        "Announcement URL": "", "Main Body Markdown": "Condiții: studii superioare.",
        "Other Links": "", "Nr Posturi": "1", "Contact Telefon": "0264123456",
        "Contact Email": "contact@spital.ro", "Contact Persoana": "",
        "Data Limita Depunere": "30.06.2026", "Data Proba Scrisa": "",
        "Data Interviu": "", "Data Rezultate Finale": "",
        "Data Publicare": "2026-05-05", "Data Expirare": "2026-06-30",
        "Status": status, "Detail Fetched At": fetched_at, "Detail Content Hash": content_hash,
    }
    return base


def write_csv(path: Path, rows: list[dict], fieldnames: list[str] | None = None) -> None:
    keys = fieldnames or list(rows[0].keys())
    with path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=keys, extrasaction="ignore")
        w.writeheader()
        w.writerows(rows)


@pytest.fixture
def import_env(tmp_path):
    from django.core.management import call_command

    (tmp_path / "anunturi").mkdir()
    write_csv(tmp_path / "posturi_gov_ro.csv", INDEX_ROWS)
    return tmp_path, call_command


class TestImportProvenance:
    @pytest.mark.django_db(transaction=True)
    def test_detail_status_anulat_cancels_an_uncancelled_index_row(self, import_env):
        tmp_path, call_command = import_env
        write_csv(tmp_path / "anunturi" / "anunturi.csv", [anunturi_row(status="Anulat")])
        call_command("import_csvs", data_dir=tmp_path, verbosity=0)
        posting = JobPosting.objects.get(url=INDEX_ROWS[0]["url"])
        assert posting.cancelled is True

    @pytest.mark.django_db(transaction=True)
    def test_detail_status_live_reinstates_an_index_cancelled_row(self, import_env):
        tmp_path, call_command = import_env
        rows = [row(INDEX_ROWS[0]["url"], "Anunț anulat")]
        write_csv(tmp_path / "posturi_gov_ro.csv", rows)
        write_csv(tmp_path / "anunturi" / "anunturi.csv", [anunturi_row(status="Live")])
        call_command("import_csvs", data_dir=tmp_path, verbosity=0)
        posting = JobPosting.objects.get(url=INDEX_ROWS[0]["url"])
        assert posting.cancelled is False

    @pytest.mark.django_db(transaction=True)
    def test_unknown_status_keeps_the_index_marker(self, import_env):
        tmp_path, call_command = import_env
        rows = [row(INDEX_ROWS[0]["url"], "Anunț anulat")]
        write_csv(tmp_path / "posturi_gov_ro.csv", rows)
        write_csv(tmp_path / "anunturi" / "anunturi.csv", [anunturi_row(status="")])
        call_command("import_csvs", data_dir=tmp_path, verbosity=0)
        posting = JobPosting.objects.get(url=INDEX_ROWS[0]["url"])
        assert posting.cancelled is True

    @pytest.mark.django_db(transaction=True)
    def test_provenance_fields_import_and_legacy_stays_unknown(self, import_env):
        tmp_path, call_command = import_env
        write_csv(tmp_path / "anunturi" / "anunturi.csv", [
            anunturi_row(status="Live", fetched_at="2026-10-04T10:00:00+00:00", content_hash="c" * 64),
        ])
        call_command("import_csvs", data_dir=tmp_path, verbosity=0)
        posting = JobPosting.objects.get(url=INDEX_ROWS[0]["url"])
        assert posting.detail_fetched_at is not None
        assert posting.detail_fetched_at.tzinfo is not None
        assert posting.detail_content_hash == "c" * 64

        # Re-import a legacy row (empty provenance): unknown, not erased to now.
        write_csv(tmp_path / "anunturi" / "anunturi.csv", [anunturi_row()])
        call_command("import_csvs", data_dir=tmp_path, verbosity=0)
        posting.refresh_from_db()
        assert posting.detail_fetched_at is None
        assert posting.detail_content_hash == ""
