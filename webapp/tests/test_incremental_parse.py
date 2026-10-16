"""`parse-anunturi.py` — the incremental parse (LATER-05).

A page whose sidecar `content_hash` (plus the other inputs of its row) is
unchanged since the last run is not parsed again: its previous `anunturi.csv`
row and `calendar.csv` rows are copied. Every test below runs the real
`process_html_files` over a small cache directory in `tmp_path`.
"""

from __future__ import annotations

import hashlib
import importlib.util
import json
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]

PAGE = """<html><body><div class="pg-wrap">
<h1 class="pg-title">{title}</h1>
<div class="pg-subline"><span>Primăria Cluj</span></div>
<div class="pg-prose"><p>Concurs pentru postul de referent.</p>
<p>CALENDAR CONCURS<br/>1. Data limită de depunere 25.09.2026, ora 14.00<br/>2. Proba scrisă 05.10.2026, ora 10.00</p></div>
</div></body></html>"""


@pytest.fixture(scope="module")
def pm():
    spec = importlib.util.spec_from_file_location("parse_anunturi_incremental", REPO_ROOT / "parse-anunturi.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules["parse_anunturi_incremental"] = module
    spec.loader.exec_module(module)
    return module


@pytest.fixture
def env(pm, tmp_path, monkeypatch):
    """A cache dir, an output dir, and the index lookups pinned to nothing."""
    monkeypatch.setattr(pm, "_SLUG_INDEX", {})
    monkeypatch.setattr(pm, "_INDEX_DATES", {})
    cache = tmp_path / "cache"
    cache.mkdir()
    return type("Env", (), {
        "cache": cache, "out": tmp_path / "out",
        "csv": tmp_path / "out" / "anunturi.csv", "cal": tmp_path / "out" / "calendar.csv",
    })


def put(env, slug, title="Referent", sub="2026/10", sidecar=True, fetched_at="2026-10-01T00:00:00+00:00",
        status="live", content=None):
    """Write a cached page (+ sidecar the way fetch-anunturi.py does)."""
    d = env.cache / sub
    d.mkdir(parents=True, exist_ok=True)
    html = content if content is not None else PAGE.format(title=title)
    raw = html.encode("utf-8")
    (d / f"{slug}.html").write_bytes(raw)
    if sidecar:
        (d / f"{slug}.meta.json").write_text(json.dumps({
            "url": f"https://posturi.gov.ro/joburi/{slug}/", "fetched_at": fetched_at,
            "content_hash": hashlib.sha256(raw).hexdigest(), "bytes": len(raw), "status": status,
        }), encoding="utf-8")
    return d / f"{slug}.html"


def run(pm, env, **kw):
    kw.setdefault("progress_every", 0)
    return pm.process_html_files(str(env.cache), str(env.csv), str(env.cal), log=lambda *_: None, **kw)


def rows(path):
    import csv
    with open(path, newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f))


class TestReuse:
    def test_first_run_parses_everything_and_writes_the_cache(self, pm, env):
        put(env, "a"), put(env, "b")
        s = run(pm, env)
        assert (s["parsed"], s["reused"], s["removed"]) == (2, 0, 0)
        assert (env.out / pm.CACHE_NAME).exists()

    def test_unchanged_hash_is_reused_not_parsed(self, pm, env):
        page = put(env, "a", title="Referent")
        put(env, "b")
        run(pm, env)
        before = env.csv.read_bytes(), env.cal.read_bytes()
        # Same length, same hash recorded in the sidecar: if this were parsed
        # again the title in the CSV would change. It must be the old row.
        page.write_text(page.read_text(encoding="utf-8").replace("Referent", "Rexerent"), encoding="utf-8")
        s = run(pm, env)
        assert (s["parsed"], s["reused"]) == (0, 2)
        assert (env.csv.read_bytes(), env.cal.read_bytes()) == before
        assert [r["Job Title"] for r in rows(env.csv)] == ["Referent", "Referent"]

    def test_reuse_carries_the_calendar_rows(self, pm, env):
        put(env, "a")
        run(pm, env)
        first = rows(env.cal)
        assert len(first) == 2 and first[0]["data"] == "25.09.2026"
        s = run(pm, env)
        assert s["reused"] == 1 and rows(env.cal) == first

    def test_incremental_output_is_byte_identical_to_a_full_run(self, pm, env, tmp_path):
        for i in range(5):
            put(env, f"p{i}", title=f"Post {i}")
        put(env, "legacy", sidecar=False)
        run(pm, env)
        put(env, "p2", title="Post 2 revised")       # changed
        put(env, "p9", title="New post")             # added
        run(pm, env)
        inc = (env.csv.read_bytes(), env.cal.read_bytes(), (env.out / pm.CACHE_NAME).read_bytes())
        run(pm, env, full=True)
        assert inc == (env.csv.read_bytes(), env.cal.read_bytes(), (env.out / pm.CACHE_NAME).read_bytes())

    def test_refreshed_fetch_time_is_overlaid_without_a_reparse(self, pm, env):
        page = put(env, "a")
        run(pm, env)
        meta = page.with_suffix(".meta.json")
        data = json.loads(meta.read_text())
        data["fetched_at"] = "2026-10-16T08:00:00+00:00"   # refreshed, same content
        meta.write_text(json.dumps(data))
        s = run(pm, env)
        assert (s["parsed"], s["reused"]) == (0, 1)
        assert rows(env.csv)[0]["Detail Fetched At"] == "2026-10-16T08:00:00+00:00"


class TestReparse:
    def test_changed_hash_reparses_only_that_page(self, pm, env):
        put(env, "a", title="Old title")
        put(env, "b", title="Other")
        run(pm, env)
        put(env, "a", title="New title")
        s = run(pm, env)
        assert (s["parsed"], s["reused"]) == (1, 1)
        assert {r["Job Title"] for r in rows(env.csv)} == {"New title", "Other"}

    def test_parser_version_bump_reparses_everything(self, pm, env, monkeypatch):
        page = put(env, "a", title="Referent")
        put(env, "b")
        run(pm, env)
        page.write_text(page.read_text(encoding="utf-8").replace("Referent", "Rexerent"), encoding="utf-8")
        monkeypatch.setattr(pm, "PARSER_VERSION", pm.PARSER_VERSION + 1)
        s = run(pm, env)
        assert (s["parsed"], s["reused"]) == (2, 0)
        assert "Rexerent" in {r["Job Title"] for r in rows(env.csv)}

    def test_full_flag_ignores_the_cache(self, pm, env):
        page = put(env, "a", title="Referent")
        run(pm, env)
        page.write_text(page.read_text(encoding="utf-8").replace("Referent", "Rexerent"), encoding="utf-8")
        s = run(pm, env, full=True)
        assert (s["parsed"], s["reused"], s["full"]) == (1, 0, True)
        assert rows(env.csv)[0]["Job Title"] == "Rexerent"

    def test_cli_full_flag(self, pm, env):
        put(env, "a")
        argv = ["--input-dir", str(env.cache), "--out-dir", str(env.out), "--progress-every", "0"]
        pm.main(argv)
        page = next(env.cache.rglob("a.html"))
        page.write_text(page.read_text(encoding="utf-8").replace("Referent", "Rexerent"), encoding="utf-8")
        pm.main(argv)
        assert rows(env.csv)[0]["Job Title"] == "Referent"      # reused
        pm.main(argv + ["--full"])
        assert rows(env.csv)[0]["Job Title"] == "Rexerent"      # re-parsed

    def test_legacy_page_without_sidecar_is_always_parsed(self, pm, env):
        put(env, "old", sidecar=False)
        put(env, "new")
        run(pm, env)
        s = run(pm, env)
        assert (s["parsed"], s["reused"]) == (1, 1)

    def test_torn_page_and_sidecar_pair_is_parsed(self, pm, env):
        """Sidecar `bytes` no longer matching the file (crash between the two
        writes) must not be trusted."""
        page = put(env, "a")
        run(pm, env)
        page.write_text(page.read_text(encoding="utf-8").replace("Referent", "Referent plus"), encoding="utf-8")
        s = run(pm, env)
        assert (s["parsed"], s["reused"]) == (1, 0)

    def test_index_dates_changing_reparses(self, pm, env, monkeypatch):
        put(env, "a")
        run(pm, env)
        url = "https://posturi.gov.ro/joburi/a/"
        monkeypatch.setattr(pm, "_INDEX_DATES", {url: ("2026-09-01", "2026-12-31")})
        monkeypatch.setattr(pm, "_SLUG_INDEX", {"a": url})
        s = run(pm, env)
        assert (s["parsed"], s["reused"]) == (1, 0)
        assert rows(env.csv)[0]["Data Expirare"] == "2026-12-31"

    def test_edited_output_invalidates_the_cache(self, pm, env):
        put(env, "a")
        run(pm, env)
        env.csv.write_text(env.csv.read_text(encoding="utf-8") + "\n", encoding="utf-8")
        s = run(pm, env)
        assert (s["parsed"], s["reused"]) == (1, 0)

    def test_missing_cache_file_parses_everything(self, pm, env):
        put(env, "a")
        run(pm, env)
        (env.out / pm.CACHE_NAME).unlink()
        assert run(pm, env)["reused"] == 0


class TestRemoved:
    def test_pages_gone_from_the_cache_dir_are_dropped_from_both_outputs(self, pm, env):
        keep, gone = put(env, "keep", title="Keep"), put(env, "gone", title="Gone")
        run(pm, env)
        assert len(rows(env.csv)) == 2 and len(rows(env.cal)) == 4
        gone.unlink()
        gone.with_suffix(".meta.json").unlink()
        s = run(pm, env)
        assert (s["parsed"], s["reused"], s["removed"]) == (0, 1, 1)
        assert [r["Job Title"] for r in rows(env.csv)] == ["Keep"]
        assert len(rows(env.cal)) == 2
        assert keep.exists()

    def test_same_slug_in_two_dated_folders_keeps_both_rows(self, pm, env):
        put(env, "dup", title="First", sub="2026/09")
        put(env, "dup", title="Second", sub="2026/10")
        run(pm, env)
        s = run(pm, env)
        assert (s["parsed"], s["reused"]) == (0, 2)
        assert [r["Job Title"] for r in rows(env.csv)] == ["First", "Second"]


def test_progress_lines_and_summary(pm, env):
    for i in range(5):
        put(env, f"p{i}")
    lines = []
    pm.process_html_files(str(env.cache), str(env.csv), str(env.cal), progress_every=2, log=lines.append)
    assert any(ln.startswith("parse: 2/5 files") for ln in lines)
    assert "parsed 5, reused 0, removed 0" in lines[-1]
