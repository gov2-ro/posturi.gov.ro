"""FIX-05 — version-aware production extraction and the status vocabulary.

Covers the selection/promotion plumbing (revision-aware resume, atomic
production provenance, compare-only non-mutation), the calendar-keyword
hardening and the export's application_status mapping. No provider is called.
"""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))


@pytest.fixture(scope="module")
def llm():
    spec = importlib.util.spec_from_file_location(
        "llmschema_fix05_under_test", REPO_ROOT / "llm-schema.py"
    )
    module = importlib.util.module_from_spec(spec)
    sys.modules["llmschema_fix05_under_test"] = module
    spec.loader.exec_module(module)
    return module


@pytest.fixture(scope="module")
def exp():
    spec = importlib.util.spec_from_file_location(
        "export_sqlite_fix05_under_test", REPO_ROOT / "export-to-sqlite.py"
    )
    module = importlib.util.module_from_spec(spec)
    sys.modules["export_sqlite_fix05_under_test"] = module
    spec.loader.exec_module(module)
    return module


@pytest.fixture(scope="module")
def parsemod():
    spec = importlib.util.spec_from_file_location(
        "parse_anunturi_fix05_under_test", REPO_ROOT / "parse-anunturi.py"
    )
    module = importlib.util.module_from_spec(spec)
    sys.modules["parse_anunturi_fix05_under_test"] = module
    spec.loader.exec_module(module)
    return module


# ---------------------------------------------------------- selection (resume)


class TestRevisionAwareSelection:
    def test_resume_skips_only_current_production_rows(self, llm):
        where, params = llm._selection_where(resume_key=("p", "m", "v3"))
        sql = where.lower()
        assert "schema_provider" in sql and "schema_prompt_version" in sql
        assert "schema_source_revision" in sql and "detail_content_hash" in sql
        assert params == ["p", "m", "v3"]

    def test_plain_selection_keeps_the_old_shape(self, llm):
        where, params = llm._selection_where(slug_filter="medic", active_only=True)
        assert "url like" in where.lower()
        assert "expires_at >= current_date" in where.lower()
        assert params == ["%medic%"]

    def test_no_resume_means_no_revision_clause(self, llm):
        where, _ = llm._selection_where()
        assert "schema_provider" not in where


# ------------------------------------------------------- production provenance


class FakeCursor:
    def __init__(self):
        self.executed = []

    def execute(self, sql, params):
        self.executed.append((sql, params))

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


class FakeConn:
    def __init__(self):
        self.commits = 0
        self._cursor = FakeCursor()

    def cursor(self):
        return self._cursor

    def commit(self):
        self.commits += 1


class TestProductionWrite:
    def test_write_schema_stores_provenance_atomically(self, llm):
        conn = FakeConn()
        llm.write_schema(
            conn, 42, {"responsibilities": "x"},
            provider="p", model="m", prompt_version="v4",
            source_revision="abc123", extracted_at="2026-10-04T00:00:00+00:00",
        )
        sql = conn.cursor().executed[0][0]
        assert "schema_provider" in sql and "schema_source_revision" in sql
        assert conn.commits == 1, "one commit — provenance and schema cannot diverge"

    def test_write_variant_records_the_revision(self, llm):
        conn = FakeConn()
        llm.write_variant(
            conn, 42, "p", "m", {"a": 1}, 10, 20, 0.001, 500,
            prompt_version="v3", source_revision="abc123",
        )
        _, params = conn.cursor().executed[0]
        assert "abc123" in params, "the revision the variant saw is recorded"


# ----------------------------------------------------------- status vocabulary


class TestApplicationStatus:
    @pytest.mark.parametrize("deadline,status", [
        ({"date": "2026-10-16", "source": "concurs"}, "confirmed_open"),
        ({"date": "2026-10-16", "source": "anunt"}, "confirmed_open"),
        ({"date": "2026-10-20", "source": "expirare"}, "unconfirmed"),
        ({"date": "2026-09-30", "source": "anunt"}, "closed"),
        ({"date": "2026-09-30", "source": "expirare"}, "closed"),
        ({"date": None, "source": ""}, "unknown"),
    ])
    def test_status_mapping(self, exp, deadline, status):
        assert exp._application_status(deadline, "2026-10-04") == status

    def test_today_is_still_open(self, exp):
        assert exp._application_status({"date": "2026-10-04", "source": "anunt"},
                                       "2026-10-04") == "confirmed_open"


# --------------------------------------------------------- calendar keywords


CAL_ROWS = [
    ("Data limită de depunere", "2026-10-16", "", ""),
    ("Înscrierea candidaților înscriși", "2026-10-10", "", ""),
    ("Probă scrisă", "2026-10-28", "10:00", ""),
    ("Data finală de depunere", "2026-10-16", "", ""),
    ("Rezultate finale", "2026-11-10", "", ""),
]


class TestCalendarKeywordHardening:
    def test_scris_does_not_match_inscrisi(self, parsemod):
        """'înscriși' contains 'scris' as a substring — the written-test probe
        must not land on a registration row."""
        got = parsemod._find_calendar_date(CAL_ROWS, ['scrisa', 'scrisă', 'scris'])
        assert got.startswith("2026-10-28"), got

    def test_final_does_not_match_a_deadline_row(self, parsemod):
        """'Data finală de depunere' is a deadline, not the results date."""
        got = parsemod._find_calendar_date(
            CAL_ROWS, ['final', 'rezultat final', 'rezultate finale'],
            exclude_deadline_rows=True)
        assert got.startswith("2026-11-10"), got

    def test_interviu_still_matches_directly(self, parsemod):
        rows = CAL_ROWS + [("Interviu", "2026-11-03", "09:00", "")]
        got = parsemod._find_calendar_date(rows, ['interviu'])
        assert got.startswith("2026-11-03"), got