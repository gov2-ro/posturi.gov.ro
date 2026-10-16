"""FIX-05-STAGES — per-posting failure diagnostics, the affected-ID retry and --dry-run.

What is pinned:

  * a posting that fails for good leaves one `kind: "llm-schema-failure"` line in
    the failure log with EVERY failed attempt — including the first, repaired one —
    and the rejected raw values; a failed attempt changes nothing in the database;
  * a posting repaired after a validation error and then SUCCEEDED leaves a
    `kind: "llm-schema-repaired"` line (the failed attempts only), and its database
    writes are exactly what they were;
  * the lines stay whole JSON under --workers > 1;
  * --no-failure-log writes nothing, and a test run never writes into data/;
  * --urls-file selects exact URLs / slugs only (`...-10` is not `...-100`) and
    reports entries that match nothing;
  * --dry-run computes the real selection and then touches nothing.

No provider is called: `make_generator` is replaced, and the selection SQL runs
against pytest-django's throwaway PostgreSQL database, never the development one.
"""

from __future__ import annotations

import datetime as dt
import importlib.util
import json
import re
import sys
import threading
from pathlib import Path

import pytest
from pydantic import ValidationError

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

NOW = dt.date.today()
RUN_ID = "2026-10-10T03:00:00Z"
STAGE_LOC = "competition_calendar.events.0.stage"


@pytest.fixture(scope="module")
def llm():
    spec = importlib.util.spec_from_file_location(
        "llmschema_failure_log_under_test", REPO_ROOT / "llm-schema.py"
    )
    module = importlib.util.module_from_spec(spec)
    sys.modules["llmschema_failure_log_under_test"] = module
    spec.loader.exec_module(module)
    return module


# ------------------------------------------------------------------ helpers


def stage_error(llm, stage="afisare rezultate", **extra) -> ValidationError:
    """The real error v4 raises for an unlisted calendar stage."""
    with pytest.raises(ValidationError) as caught:
        llm._validate_extraction(
            {"competition_calendar": {"events": [{"stage": stage, "date": "2024-01-01", **extra}]}},
            "v4",
        )
    return caught.value


class _RecordingCursor:
    def __init__(self, cursor, executed):
        self._cursor, self._executed = cursor, executed

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def execute(self, sql, params=None):
        self._executed.append(sql)
        return self._cursor.execute(sql, params)

    def fetchone(self):
        return self._cursor.fetchone()

    def fetchall(self):
        return self._cursor.fetchall()

    def __iter__(self):
        return iter(self._cursor)


class DjangoConn:
    """llm-schema's connection, backed by the Django test database.

    `commit` is a no-op inside the test transaction, and every statement is
    recorded so a test can prove a path ran SELECTs only.
    """

    def __init__(self):
        self.executed: list[str] = []
        self.commits = 0

    def cursor(self):
        from django.db import connection
        return _RecordingCursor(connection.cursor(), self.executed)

    def commit(self):
        self.commits += 1

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    @property
    def writes(self):
        return [s for s in self.executed if not s.lstrip().upper().startswith("SELECT")]


def make_posting(slug, *, schema=None, active=True, published=None, **fields):
    from apps.jobs.models import Employer, JobPosting
    employer, _ = Employer.objects.get_or_create(name="Primăria Test", defaults={"slug": "primaria-test"})
    defaults = dict(
        url=f"https://posturi.gov.ro/joburi/{slug}/", title=f"Titlu {slug}", employer=employer,
        body_markdown=f"Corp anunț {slug} cerințe", schema_json=schema,
        expires_at=NOW + dt.timedelta(days=30 if active else -30),
        published_at=published, detail_content_hash="rev-1",
    )
    defaults.update(fields)
    return JobPosting.objects.create(**defaults)


def run_main(llm, monkeypatch, tmp_path, *argv, conn=None, generate=None):
    """`llm.main` in a scratch cwd, against `conn`, with `generate` as the model.

    The scratch cwd is the guard: the log defaults (`data/...`) are relative, so
    an unflagged write lands in tmp_path, never in the repository's data/.
    """
    monkeypatch.chdir(tmp_path)
    monkeypatch.setenv("POSTURI_RUN_ID", RUN_ID)
    conn = conn or DjangoConn()
    monkeypatch.setattr(llm.psycopg, "connect", lambda *a, **k: conn)

    def fail_if_built(*a, **k):
        raise AssertionError("make_generator must not be reached")

    monkeypatch.setattr(llm, "make_generator",
                        (lambda *a, **k: generate) if generate else fail_if_built)
    code = llm.main(["--provider", "deepseek", "--prompt-version", "v4", "--no-strip", *argv])
    return code, conn


def two_bad_stages(llm):
    """A model that is wrong twice, differently — once per attempt."""
    def generate(content):
        slug = content.split()[2]
        repaired = "ATENȚIE" in content
        stage = f"Y-{slug}" if repaired else f"X-{slug}"
        return llm._validate_extraction(
            {"competition_calendar": {"events": [{"stage": stage, "date": "2024-01-01"}]}}, "v4")
    return generate


def read_log(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines()]


# ---------------------------------------------------------- describe_attempt


class TestDescribeAttempt:
    def test_a_validation_error_keeps_path_type_and_the_raw_input(self, llm):
        entry = llm.describe_attempt(stage_error(llm, "Afișare rezultate"), 1)
        assert entry == {
            "attempt": 1,
            "error_class": "ValidationError",
            "error_count": 1,
            "errors": [{"loc": STAGE_LOC, "type": "literal_error", "input": "Afișare rezultate"}],
        }

    def test_the_record_is_json_safe(self, llm):
        """pydantic's `ctx` holds objects json cannot dump; only loc/type/input are kept."""
        json.dumps(llm.describe_attempt(stage_error(llm), 2))

    def test_a_long_input_is_clipped(self, llm):
        entry = llm.describe_attempt(stage_error(llm, "z" * 5000), 1)
        assert entry["errors"][0]["input"] == "z" * llm.MAX_LOGGED_INPUT

    def test_a_non_string_input_is_stringified(self, llm):
        entry = llm.describe_attempt(stage_error(llm, 7), 1)
        assert entry["errors"][0]["input"] == "7"

    def test_errors_are_capped_but_counted(self, llm):
        events = [{"stage": f"bad-{i}"} for i in range(llm.MAX_LOGGED_ERRORS + 10)]
        with pytest.raises(ValidationError) as caught:
            llm._validate_extraction({"competition_calendar": {"events": events}}, "v4")
        entry = llm.describe_attempt(caught.value, 1)
        assert len(entry["errors"]) == llm.MAX_LOGGED_ERRORS
        assert entry["error_count"] >= llm.MAX_LOGGED_ERRORS + 10

    def test_other_errors_carry_a_short_message_and_no_errors_list(self, llm):
        entry = llm.describe_attempt(ValueError("Expected dict, got str: ''"), 1)
        assert entry == {"attempt": 1, "error_class": "ValueError",
                         "message": "Expected dict, got str: ''"}

    def test_an_http_status_is_the_class(self, llm):
        class Boom(Exception):
            status_code = 429
        assert llm.describe_attempt(Boom("slow down"), 3)["error_class"] == "HTTP_429"


# ------------------------------------------------------ append_failure_record


class TestAppendFailureRecord:
    def test_concurrent_writers_never_interleave_a_line(self, llm, tmp_path):
        """Records here are ~6 KB — past the 4 KB a stdio buffer would write whole."""
        path = tmp_path / "failures.jsonl"

        def writer(n):
            for i in range(40):
                llm.append_failure_record(path, {"writer": n, "i": i, "pad": "é" * 3000})

        threads = [threading.Thread(target=writer, args=(n,)) for n in range(8)]
        [t.start() for t in threads]
        [t.join() for t in threads]
        records = read_log(path)
        assert len(records) == 8 * 40
        assert {(r["writer"], r["i"]) for r in records} == {(n, i) for n in range(8) for i in range(40)}

    def test_an_unwritable_path_is_reported_not_raised(self, llm, tmp_path, capsys):
        blocker = tmp_path / "blocker"
        blocker.write_text("a file, not a directory")
        assert llm.append_failure_record(blocker / "failures.jsonl", {"a": 1}) is False
        assert "could not write the failure log" in capsys.readouterr().err


# ------------------------------------------------------ the failure log, e2e


@pytest.mark.django_db
class TestFailureLogThroughMain:
    def test_a_failed_posting_records_both_attempts_and_changes_nothing_stored(
            self, llm, monkeypatch, tmp_path):
        posting = make_posting(
            "alpha-1", schema={"previous": True}, detail_content_hash="rev-2",
            schema_provider="prior-provider", schema_model="prior-model",
            schema_prompt_version="v3", schema_source_revision="rev-1",
        )
        log = tmp_path / "failures.jsonl"
        code, conn = run_main(llm, monkeypatch, tmp_path, "--force", "--workers", "1",
                              "--failure-log", str(log), generate=two_bad_stages(llm))

        (rec,) = read_log(log)
        assert rec["kind"] == "llm-schema-failure" and rec["format"] == 1
        assert rec["run_id"] == RUN_ID
        assert re.fullmatch(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ", rec["at"])
        assert (rec["posting_id"], rec["slug"], rec["url"]) == (
            posting.id, "alpha-1", "https://posturi.gov.ro/joburi/alpha-1/")
        assert rec["provider"] == "deepseek"
        assert rec["model"] == llm.resolve_model("deepseek", None)
        assert rec["prompt_version"] == "v4"
        assert rec["source_revision"] == "rev-2"          # the posting's current revision
        assert rec["compare"] is False
        assert rec["error_class"] == "ValidationError"
        assert STAGE_LOC in rec["message"] and len(rec["message"]) <= 800

        # BOTH attempts, with the raw stage each one produced.
        first, second = rec["attempts"]
        assert (first["attempt"], second["attempt"]) == (1, 2)
        assert first["error_class"] == second["error_class"] == "ValidationError"
        assert first["errors"] == [{"loc": STAGE_LOC, "type": "literal_error", "input": "X-alpha-1"}]
        assert second["errors"] == [{"loc": STAGE_LOC, "type": "literal_error", "input": "Y-alpha-1"}]

        # A failed attempt stores nothing: schema, provenance and variants untouched.
        from apps.jobs.models import JobPosting, JobPostingSchemaVariant
        posting.refresh_from_db()
        assert posting.schema_json == {"previous": True}
        assert (posting.schema_provider, posting.schema_model, posting.schema_prompt_version,
                posting.schema_source_revision) == ("prior-provider", "prior-model", "v3", "rev-1")
        assert JobPostingSchemaVariant.objects.count() == 0
        assert conn.writes == [] and conn.commits == 0
        assert JobPosting.objects.get(pk=posting.pk).schema_json == {"previous": True}

    def test_workers_above_one_still_write_whole_json_lines(self, llm, monkeypatch, tmp_path):
        slugs = [f"post-{i}" for i in range(12)]
        for slug in slugs:
            make_posting(slug)
        log = tmp_path / "failures.jsonl"
        run_main(llm, monkeypatch, tmp_path, "--workers", "4", "--failure-log", str(log),
                 generate=two_bad_stages(llm))
        records = read_log(log)                           # every line parses
        assert sorted(r["slug"] for r in records) == sorted(slugs)
        assert len({r["posting_id"] for r in records}) == 12
        for rec in records:                               # no cross-talk between workers
            inputs = [a["errors"][0]["input"] for a in rec["attempts"]]
            assert inputs == [f"X-{rec['slug']}", f"Y-{rec['slug']}"]

    def test_no_failure_log_writes_nothing_anywhere(self, llm, monkeypatch, tmp_path):
        make_posting("quiet-1")
        elsewhere = tmp_path / "elsewhere.jsonl"
        run_main(llm, monkeypatch, tmp_path, "--no-failure-log", "--no-run-log",
                 "--failure-log", str(elsewhere), generate=two_bad_stages(llm))
        assert not elsewhere.exists()
        assert not (tmp_path / "data").exists()

    def test_the_default_path_is_data_schema_failures_jsonl(self, llm, monkeypatch, tmp_path):
        """Relative to the cwd, like --run-log — which is why the helper runs in tmp_path."""
        make_posting("default-1")
        run_main(llm, monkeypatch, tmp_path, "--no-run-log", generate=two_bad_stages(llm))
        assert len(read_log(tmp_path / "data" / "schema-failures.jsonl")) == 1

    def test_a_repaired_posting_is_logged_as_repaired_and_still_stored(self, llm, monkeypatch, tmp_path):
        """Nothing is lost, but the rejected first value is evidence — so it is recorded."""
        posting = make_posting("repaired-1", detail_content_hash="rev-7")
        calls = []

        def generate(content):
            calls.append(content)
            if len(calls) == 1:
                return llm._validate_extraction(
                    {"competition_calendar": {"events": [{"stage": "afisare rezultate"}]}}, "v4")
            return llm._validate_extraction({}, "v4"), 10, 20, 0

        log = tmp_path / "failures.jsonl"
        _, conn = run_main(llm, monkeypatch, tmp_path, "--failure-log", str(log), generate=generate)
        assert len(calls) == 2

        (rec,) = read_log(log)
        assert rec["kind"] == "llm-schema-repaired" and rec["format"] == 1
        assert (rec["run_id"], rec["slug"], rec["posting_id"]) == (RUN_ID, "repaired-1", posting.id)
        assert rec["source_revision"] == "rev-7" and rec["compare"] is False
        assert rec["error_class"] == "ValidationError"
        assert STAGE_LOC in rec["message"]
        # The FAILED attempts only — the one that succeeded is not in the list.
        assert [a["attempt"] for a in rec["attempts"]] == [1]
        assert rec["attempts"][0]["errors"] == [
            {"loc": STAGE_LOC, "type": "literal_error", "input": "afisare rezultate"}]

        # Database behaviour is exactly what a repaired success did before: stored, stamped.
        posting.refresh_from_db()
        assert posting.schema_json is not None and posting.schema_prompt_version == "v4"
        assert posting.schema_source_revision == "rev-7"
        assert [s.split()[0] for s in conn.writes] == ["INSERT", "UPDATE"]

    def test_a_clean_first_attempt_is_not_logged(self, llm, monkeypatch, tmp_path):
        make_posting("clean-1")
        log = tmp_path / "failures.jsonl"
        run_main(llm, monkeypatch, tmp_path, "--failure-log", str(log),
                 generate=lambda content: (llm._validate_extraction({}, "v4"), 10, 20, 0))
        assert not log.exists()

    def test_a_transient_retry_alone_is_not_a_repair(self, llm, monkeypatch, tmp_path):
        class Throttled(Exception):
            status_code = 429

        make_posting("slow-1")
        monkeypatch.setattr(llm.time, "sleep", lambda s: None)
        calls = []

        def generate(content):
            calls.append(content)
            if len(calls) == 1:
                raise Throttled("slow down")
            return llm._validate_extraction({}, "v4"), 10, 20, 0

        log = tmp_path / "failures.jsonl"
        run_main(llm, monkeypatch, tmp_path, "--failure-log", str(log), generate=generate)
        assert len(calls) == 2 and not log.exists()

    def test_the_repaired_record_describes_the_last_failed_attempt(self, llm, monkeypatch, tmp_path):
        """validation error, then a 429, then success: all failed attempts, the last one named."""
        class Throttled(Exception):
            status_code = 429

        make_posting("mixed-1")
        monkeypatch.setattr(llm.time, "sleep", lambda s: None)
        calls = []

        def generate(content):
            calls.append(content)
            if len(calls) == 1:
                raise stage_error(llm, "afisare rezultate")
            if len(calls) == 2:
                raise Throttled("slow down")
            return llm._validate_extraction({}, "v4"), 10, 20, 0

        log = tmp_path / "failures.jsonl"
        run_main(llm, monkeypatch, tmp_path, "--failure-log", str(log), generate=generate)
        (rec,) = read_log(log)
        assert rec["kind"] == "llm-schema-repaired"
        assert [(a["attempt"], a["error_class"]) for a in rec["attempts"]] == [
            (1, "ValidationError"), (2, "HTTP_429")]
        assert rec["error_class"] == "HTTP_429" and rec["message"] == "slow down"

    def test_failed_and_repaired_postings_share_one_log(self, llm, monkeypatch, tmp_path):
        for slug in ("ok-1", "bad-1", "fix-1", "fix-2"):
            make_posting(slug)

        def generate(content):
            slug = content.split()[2]
            repaired = "ATENȚIE" in content
            if slug.startswith("ok") or (slug.startswith("fix") and repaired):
                return llm._validate_extraction({}, "v4"), 1, 1, 0
            return two_bad_stages(llm)(content)

        log = tmp_path / "failures.jsonl"
        run_main(llm, monkeypatch, tmp_path, "--workers", "3", "--failure-log", str(log),
                 generate=generate)
        kinds = {r["slug"]: r["kind"] for r in read_log(log)}
        assert kinds == {"bad-1": "llm-schema-failure",
                         "fix-1": "llm-schema-repaired", "fix-2": "llm-schema-repaired"}

    def test_no_failure_log_also_silences_repaired_records(self, llm, monkeypatch, tmp_path):
        make_posting("fixed-1")
        calls = []

        def generate(content):
            calls.append(content)
            if len(calls) == 1:
                raise stage_error(llm)
            return llm._validate_extraction({}, "v4"), 10, 20, 0

        run_main(llm, monkeypatch, tmp_path, "--no-failure-log", "--no-run-log", generate=generate)
        assert len(calls) == 2 and not (tmp_path / "data").exists()

    def test_a_repaired_compare_run_is_marked_and_stores_no_production_row(self, llm, monkeypatch, tmp_path):
        posting = make_posting("cmp-fix-1")
        monkeypatch.setattr(llm, "get_enabled_models", lambda: [("deepseek", "deepseek-v4-flash")])
        calls = []

        def generate(content):
            calls.append(content)
            if len(calls) == 1:
                raise stage_error(llm)
            return llm._validate_extraction({}, "v4"), 10, 20, 0

        log = tmp_path / "failures.jsonl"
        run_main(llm, monkeypatch, tmp_path, "--compare", "--failure-log", str(log), generate=generate)
        (rec,) = read_log(log)
        assert rec["kind"] == "llm-schema-repaired" and rec["compare"] is True
        posting.refresh_from_db()
        assert posting.schema_json is None                    # compare never promotes

    def test_compare_runs_are_marked(self, llm, monkeypatch, tmp_path):
        make_posting("compared-1")
        monkeypatch.setattr(llm, "get_enabled_models", lambda: [("deepseek", "deepseek-v4-flash")])
        log = tmp_path / "failures.jsonl"
        run_main(llm, monkeypatch, tmp_path, "--compare", "--failure-log", str(log),
                 generate=two_bad_stages(llm))
        assert read_log(log)[0]["compare"] is True

    def test_a_transient_failure_chain_lists_every_attempt(self, llm, monkeypatch, tmp_path):
        class Throttled(Exception):
            status_code = 429

        make_posting("throttled-1")
        monkeypatch.setattr(llm.time, "sleep", lambda s: None)

        def generate(content):
            raise Throttled("slow down")

        log = tmp_path / "failures.jsonl"
        run_main(llm, monkeypatch, tmp_path, "--max-attempts", "3", "--failure-log", str(log),
                 generate=generate)
        (rec,) = read_log(log)
        assert rec["error_class"] == "HTTP_429"
        assert [(a["attempt"], a["error_class"]) for a in rec["attempts"]] == [
            (1, "HTTP_429"), (2, "HTTP_429"), (3, "HTTP_429")]

    def test_a_truncated_answer_is_a_single_attempt(self, llm, monkeypatch, tmp_path):
        make_posting("long-1")

        def generate(content):
            raise llm.OutputTruncated(16000, 1000, 16000)

        log = tmp_path / "failures.jsonl"
        run_main(llm, monkeypatch, tmp_path, "--failure-log", str(log), generate=generate)
        (rec,) = read_log(log)
        assert rec["error_class"] == "OutputTruncated"
        assert [a["error_class"] for a in rec["attempts"]] == ["OutputTruncated"]

    def test_a_non_dict_answer_is_logged_too(self, llm, monkeypatch, tmp_path):
        make_posting("loose-1")
        log = tmp_path / "failures.jsonl"
        run_main(llm, monkeypatch, tmp_path, "--prompt-version", "v1", "--failure-log", str(log),
                 generate=lambda content: ("just prose", 1, 1, 0))
        (rec,) = read_log(log)
        assert rec["error_class"] == "non_dict" and rec["prompt_version"] == "v1"
        assert rec["attempts"][-1]["error_class"] == "non_dict"


# ------------------------------------------------------------- --urls-file


class TestParseUrlEntries:
    def test_blank_and_comment_lines_are_ignored_and_duplicates_collapse(self, llm):
        text = (
            "\n# the affected postings\n"
            "  https://posturi.gov.ro/joburi/a-10/  \n"
            "slug-b\r\n"
            "slug-b\n"
            "\n   \n#slug-c\n"
            "https://posturi.gov.ro/joburi/a-10\n"
        )
        entries = llm.parse_url_entries(text)
        assert [(e.value, e.is_url) for e in entries] == [
            ("https://posturi.gov.ro/joburi/a-10", True), ("slug-b", False)]
        assert entries[0].raw == "https://posturi.gov.ro/joburi/a-10/"

    def test_nothing_but_comments_parses_to_no_entries(self, llm):
        assert llm.parse_url_entries("# nothing here\n\n") == []

    def test_empty_restriction_selects_nothing_rather_than_everything(self, llm):
        where, params = llm._selection_where(url_entries=[])
        assert where == " WHERE FALSE" and params == []

    def test_parameters_follow_the_placeholders(self, llm):
        entries = llm.parse_url_entries("slug-a\nhttps://posturi.gov.ro/joburi/b/\n")
        where, params = llm._selection_where(
            slug_filter="s", active_only=True, resume_key=("p", "m", "v3"), url_entries=entries)
        assert where.count("%s") == len(params) == 6
        assert params == ["%s%", ["https://posturi.gov.ro/joburi/b"], ["slug-a"], "p", "m", "v3"]


@pytest.mark.django_db
class TestUrlsFileSelection:
    def _slugs(self, llm, **selection):
        return llm.selection_report(DjangoConn(), preview=50, **selection)["slugs"]

    def test_a_slug_matches_the_last_segment_exactly(self, llm):
        """The case the substring --slug gets wrong."""
        for slug in ("economist-10", "economist-100", "xeconomist-10", "economist-10-bis"):
            make_posting(slug)
        entries = llm.parse_url_entries("economist-10\n")
        assert self._slugs(llm, url_entries=entries) == ["economist-10"]
        assert llm.count_postings(DjangoConn(), url_entries=entries) == 1
        # ...whereas the substring selector takes all four.
        assert len(self._slugs(llm, slug_filter="economist-10")) == 4

    def test_a_url_matches_whole_with_or_without_the_trailing_slash(self, llm):
        for slug in ("economist-10", "economist-100"):
            make_posting(slug)
        for line in ("https://posturi.gov.ro/joburi/economist-10/",
                     "https://posturi.gov.ro/joburi/economist-10"):
            assert self._slugs(llm, url_entries=llm.parse_url_entries(line)) == ["economist-10"]

    @pytest.mark.parametrize("line", [
        "https://posturi.gov.ro/joburi/economist-1",          # a prefix of the real URL
        "https://posturi.gov.ro/joburi/economist",
        "https://other.example/joburi/economist-10/",         # same path, another host
        "joburi/economist-10",                                 # a path is not a slug
        "economist-1",
        "economist-_0",                                        # LIKE wildcards are literals
        "%economist%",
    ])
    def test_near_misses_select_nothing(self, llm, line):
        make_posting("economist-10")
        assert self._slugs(llm, url_entries=llm.parse_url_entries(line)) == []

    def test_it_is_anded_with_the_other_selectors(self, llm):
        make_posting("kept-1")
        make_posting("done-1", schema={"already": True})
        make_posting("old-1", active=False)
        entries = llm.parse_url_entries("kept-1\ndone-1\nold-1\n")
        assert sorted(self._slugs(llm, url_entries=entries)) == ["kept-1", "old-1"]        # done-1 has a schema
        assert sorted(self._slugs(llm, url_entries=entries, force=True)) == ["done-1", "kept-1", "old-1"]
        assert sorted(self._slugs(llm, url_entries=entries, force=True, active_only=True)) == ["done-1", "kept-1"]
        assert self._slugs(llm, url_entries=entries, slug_filter="kept") == ["kept-1"]

    def test_it_combines_with_resume(self, llm):
        key = ("deepseek", "deepseek-v4-flash", "v4")
        current = dict(schema={"x": 1}, schema_provider=key[0], schema_model=key[1],
                       schema_prompt_version="v4", schema_source_revision="rev-1")
        make_posting("current-1", **current)
        make_posting("older-1", **{**current, "schema_prompt_version": "v3"})
        entries = llm.parse_url_entries("current-1\nolder-1\n")
        assert self._slugs(llm, url_entries=entries, resume_key=key) == ["older-1"]

    def test_an_empty_restriction_selects_nothing_even_with_force(self, llm):
        make_posting("anything-1")
        assert llm.count_postings(DjangoConn(), url_entries=[], force=True) == 0

    def test_the_report_separates_unknown_from_left_out(self, llm):
        make_posting("fresh-1")
        make_posting("done-1", schema={"already": True})
        make_posting("old-1", active=False)
        entries = llm.parse_url_entries(
            "fresh-1\ndone-1\nold-1\nmissing-9\nhttps://posturi.gov.ro/joburi/gone-3/\n")
        report = llm.url_entry_report(DjangoConn(), url_entries=entries, active_only=True)
        assert report == {
            "entries": 5,
            "selected_entries": 1,
            "not_found": ["missing-9", "https://posturi.gov.ro/joburi/gone-3/"],
            "not_selected": ["done-1", "old-1"],
        }


@pytest.mark.django_db
class TestUrlsFileThroughMain:
    def test_only_the_named_postings_are_called(self, llm, monkeypatch, tmp_path, capsys):
        for slug in ("job-10", "job-100", "job-11"):
            make_posting(slug)
        urls = tmp_path / "ids.txt"
        urls.write_text("# retry\njob-10\nnot-there-1\n", encoding="utf-8")
        seen = []

        def generate(content):
            seen.append(content.split()[2])
            return two_bad_stages(llm)(content)

        log = tmp_path / "failures.jsonl"
        run_main(llm, monkeypatch, tmp_path, "--urls-file", str(urls), "--failure-log", str(log),
                 generate=generate)
        assert set(seen) == {"job-10"}
        assert [r["slug"] for r in read_log(log)] == ["job-10"]
        out = capsys.readouterr().out
        assert "1 entries match no posting" in out and "not-there-1" in out

    @pytest.mark.parametrize("content", ["", "# only a comment\n\n"])
    def test_an_empty_file_is_refused_not_widened_to_everything(self, llm, monkeypatch, tmp_path, content):
        make_posting("job-1")
        urls = tmp_path / "ids.txt"
        urls.write_text(content, encoding="utf-8")
        with pytest.raises(SystemExit) as exit_:
            run_main(llm, monkeypatch, tmp_path, "--urls-file", str(urls))
        assert exit_.value.code == 2

    def test_a_missing_file_is_an_error(self, llm, monkeypatch, tmp_path):
        with pytest.raises(SystemExit) as exit_:
            run_main(llm, monkeypatch, tmp_path, "--urls-file", str(tmp_path / "absent.txt"))
        assert exit_.value.code == 2


# ----------------------------------------------------------------- --dry-run


@pytest.mark.django_db
class TestDryRun:
    def _corpus(self):
        """25 active unextracted (newest = dry-24), 2 expired unextracted, 3 active extracted."""
        base = dt.date(2026, 1, 1)
        for i in range(25):
            make_posting(f"dry-{i}", published=base + dt.timedelta(days=i))
        for i in range(2):
            make_posting(f"expired-{i}", active=False, published=base - dt.timedelta(days=30 + i))
        for i in range(3):
            make_posting(f"extracted-{i}", schema={"done": True}, published=base - dt.timedelta(days=i + 1))

    def _dry(self, llm, monkeypatch, tmp_path, *argv):
        code, conn = run_main(llm, monkeypatch, tmp_path, "--dry-run", *argv)   # generator unreachable
        assert code == 0
        return conn

    def test_it_reports_the_real_selection_and_stops(self, llm, monkeypatch, tmp_path, capsys):
        self._corpus()
        self._dry(llm, monkeypatch, tmp_path)
        out = capsys.readouterr().out
        assert "no API calls, no database writes" in out
        assert "selected:       27 postings (25 of them active)" in out
        assert "active corpus:  28 postings" in out
        assert "ENTIRE active corpus" not in out              # the 3 extracted ones are left out
        # The first 20, newest first — not all 27.
        listed = re.findall(r"^    (\S+)$", out, flags=re.M)
        assert len(listed) == 20 and listed[0] == "dry-24" and listed[1] == "dry-23"
        assert "first 20 selected" in out

    def test_it_touches_nothing(self, llm, monkeypatch, tmp_path):
        self._corpus()
        conn = self._dry(llm, monkeypatch, tmp_path)          # make_generator would raise
        assert conn.executed and conn.writes == [] and conn.commits == 0
        assert not (tmp_path / "data").exists(), "no run-log or failure-log record"
        from apps.jobs.models import JobPosting, JobPostingSchemaVariant
        assert JobPosting.objects.filter(schema_json__isnull=True).count() == 27
        assert JobPostingSchemaVariant.objects.count() == 0

    def test_it_uses_the_same_where_as_the_run(self, llm, monkeypatch, tmp_path):
        self._corpus()
        conn = self._dry(llm, monkeypatch, tmp_path, "--active-only")
        where, _ = llm._full_where(active_only=True)
        counts = [s for s in conn.executed if s.startswith("SELECT COUNT(*)") and where in s]
        assert counts, "the selected-count query carries the run's own WHERE"
        assert any("ORDER BY published_at DESC NULLS LAST, id DESC LIMIT" in s for s in conn.executed)

    def test_selecting_every_active_posting_is_called_out(self, llm, monkeypatch, tmp_path, capsys):
        self._corpus()
        self._dry(llm, monkeypatch, tmp_path, "--force", "--active-only")
        out = capsys.readouterr().out
        assert "selected:       28 postings (28 of them active)" in out
        assert "ENTIRE active corpus" in out

    def test_a_partial_selection_shows_its_coverage(self, llm, monkeypatch, tmp_path, capsys):
        self._corpus()
        self._dry(llm, monkeypatch, tmp_path, "--active-only")
        assert "coverage:       89.3% of the active corpus" in capsys.readouterr().out

    def test_limit_bounds_the_preview(self, llm, monkeypatch, tmp_path, capsys):
        self._corpus()
        self._dry(llm, monkeypatch, tmp_path, "--limit", "3")
        out = capsys.readouterr().out
        assert "would process:  3 (--limit 3)" in out
        assert re.findall(r"^    (\S+)$", out, flags=re.M) == ["dry-24", "dry-23", "dry-22"]

    def test_with_urls_file_it_previews_the_affected_ids(self, llm, monkeypatch, tmp_path, capsys):
        self._corpus()
        urls = tmp_path / "ids.txt"
        urls.write_text("dry-3\nextracted-0\nghost-1\n", encoding="utf-8")
        conn = self._dry(llm, monkeypatch, tmp_path, "--urls-file", str(urls), "--force")
        out = capsys.readouterr().out
        assert "selected:       2 postings" in out
        assert "1 entries match no posting in the database: ghost-1" in out
        assert re.findall(r"^    (\S+)$", out, flags=re.M) == ["dry-3", "extracted-0"]
        assert conn.writes == []

    def test_a_real_run_does_not_print_the_preview(self, llm, monkeypatch, tmp_path, capsys):
        make_posting("live-1")
        run_main(llm, monkeypatch, tmp_path, "--no-failure-log", "--no-run-log",
                 generate=two_bad_stages(llm))
        assert "dry run" not in capsys.readouterr().out
