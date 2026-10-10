"""FIX-05-STAGES — ops/stage-failures.py, the rejected-stage reporter.

It reads two kinds of evidence: the failure log llm-schema.py writes
(`data/schema-failures.jsonl`) and the raw console text of past runs
(`logs/pipeline.log`, rotated, gzipped). The log fixtures below reproduce what that
text really looks like: tqdm redraws joined by bare `\\r`, then pydantic's
multi-line `ValidationError`. The jsonl fixtures are produced by llm-schema.py's own
writer, so the two programs cannot drift apart unnoticed.
"""

from __future__ import annotations

import gzip
import importlib.util
import json
import sys
from pathlib import Path

import pytest
from pydantic import ValidationError

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

from schema_models import model_for_version  # noqa: E402

ENUM = ("'publicare', 'depunere_dosare', 'selectie_dosare', 'rezultate_selectie_dosare', "
        "'proba_scrisa', 'interviu', 'rezultate_finale' or 'altele'")


def _load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module      # @dataclass resolves the module through sys.modules
    spec.loader.exec_module(module)
    return module


@pytest.fixture(scope="module")
def sf():
    return _load("stage_failures_under_test", REPO_ROOT / "ops" / "stage-failures.py")


@pytest.fixture(scope="module")
def llm():
    return _load("llmschema_stage_failures_under_test", REPO_ROOT / "llm-schema.py")


# ------------------------------------------------------------------ fixtures

BAR = "{slug}: {pct:>3}%|{fill}| {n}/3 [00:{s:02d}<00:30, 12.1s/post]"


def bar(slug, n):
    return BAR.format(slug=slug, pct=n * 33, fill="█" * n + " " * (3 - n), n=n, s=10 * n)


def failure_block(slug, stages, *, cls="ValidationError", other_error=False):
    """The console text of one failed posting, exactly as tqdm.write emits it."""
    lines = [f"  ✗ {slug}: {cls}: {len(stages) + other_error} validation errors for JobPostingExtractionV4"]
    for index, raw in stages:
        shown = f'"{raw}"' if "'" in raw else f"'{raw}'"
        lines += [
            f"competition_calendar.events.{index}.stage",
            f"  Input should be {ENUM} [type=literal_error, input_value={shown}, input_type=str]",
            "    For further information visit https://errors.pydantic.dev/2.13/v/literal_error",
        ]
    if other_error:
        lines += [
            "positions.0.education.minimum_level",
            f"  Input should be 'licenta' or 'master' [type=literal_error, input_value='liceu', input_type=str]",
            "    For further information visit https://errors.pydantic.dev/2.13/v/literal_error",
        ]
    return "\n".join(lines) + "\n"


def tqdm_redraw(slug, n):
    """A progress-bar refresh, then the clear-the-line tqdm.write does before text."""
    return bar(slug, n) + "\r" + " " * 70 + "\r"


RUN_1 = "=== posturi pipeline run 2026-10-07T03:00:00Z (cron) — 2026-10-07T03:00:02Z on vps @ abc1234 ==="
RUN_2 = "=== posturi pipeline run 2026-10-08T03:00:00Z (cron) — 2026-10-08T03:00:03Z on vps @ abc1234 ==="


def synthetic_log() -> str:
    return (
        "ignored prelude\n"
        "failure before any banner is still counted, with no time\n"
        + failure_block("orphan-1", [(0, "fara banner")])
        + RUN_1 + "\n"
        "\n[deepseek/deepseek-v4-flash @ v4]\n"
        + tqdm_redraw("post-a", 0)
        + tqdm_redraw("post-a", 1)
        + "  ⟳ post-a: ValidationError on attempt 1, repairing\n"
        + tqdm_redraw("post-a", 2)
        + failure_block("post-a", [(0, "afisare rezultate"), (3, "d'ordine")])
        + tqdm_redraw("post-b", 2)
        + failure_block("post-b", [(2, "afisare rezultate")], other_error=True)
        + "  ✗ post-c: OutputTruncated: output truncated at the 16000-token cap (16000 output tokens generated)\n"
        + "  ✗ post-d: non-dict: 'prose'\n"
        + failure_block("post-e", [], other_error=True)               # a non-stage reject only
        + tqdm_redraw("post-f", 3)
        + "  3 ok, 4 failed, 0 skipped (empty content), 2 needed a retry, 0 unsupported quote(s)\n"
        + RUN_2 + "\n"
        + failure_block("post-g", [(0, "afisare rezultate")])
        + "  ✗ step: not a posting line\n"
        + "competition_calendar.events.9.stage\n"                    # stray text outside a block
        + "  Input should be 'x' [type=literal_error, input_value='stray', input_type=str]\n"
    )


def jsonl_records(llm) -> list[dict]:
    """Records built by llm-schema.py's own code, for postings and runs that overlap the log."""
    model = model_for_version("v4")

    def attempt(number, *stages):
        with pytest.raises(ValidationError) as caught:
            model.model_validate({"competition_calendar": {"events": [
                {"stage": s, "date": "2024-01-01"} for s in stages]}})
        return llm.describe_attempt(caught.value, number)

    def record(slug, run_id, at, *attempts):
        return llm.build_failure_record(
            run_id=run_id, posting_id=1, url=f"https://posturi.gov.ro/joburi/{slug}/",
            provider="deepseek", model="deepseek-v4-flash", prompt_version="v4",
            source_revision="rev-1", compare=False, error_class="ValidationError",
            message="m", attempts=list(attempts),
            at=__import__("datetime").datetime.fromisoformat(at.replace("Z", "+00:00")))

    return [
        # Same run and posting as post-g in the log: the final value must count once.
        record("post-g", "2026-10-08T03:00:00Z", "2026-10-08T03:05:00Z",
               attempt(1, "afisare rezultate pe site"), attempt(2, "afisare rezultate")),
        record("post-h", "2026-10-09T03:00:00Z", "2026-10-09T03:09:00Z",
               attempt(1, "afisare rezultate"), attempt(2, "comunicare rezultate")),
        # A record whose failure was not a stage at all.
        llm.build_failure_record(
            run_id="2026-10-09T03:00:00Z", posting_id=2, url="https://posturi.gov.ro/joburi/post-i/",
            provider="deepseek", model="m", prompt_version="v4", source_revision="", compare=False,
            error_class="OutputTruncated", message="cut", attempts=[{"attempt": 1, "error_class": "OutputTruncated"}]),
    ]


def repaired_records(llm) -> list[dict]:
    """Postings whose first attempt was rejected and whose repair then succeeded."""
    model = model_for_version("v4")

    def record(slug, run_id, at, *stages):
        with pytest.raises(ValidationError) as caught:
            model.model_validate({"competition_calendar": {"events": [
                {"stage": st, "date": "2024-01-01"} for st in stages]}})
        return llm.build_failure_record(
            kind=llm.REPAIRED_LOG_KIND, run_id=run_id, posting_id=7,
            url=f"https://posturi.gov.ro/joburi/{slug}/", provider="deepseek",
            model="deepseek-v4-flash", prompt_version="v4", source_revision="rev-1", compare=False,
            error_class="ValidationError", message="m",
            attempts=[llm.describe_attempt(caught.value, 1)],
            at=__import__("datetime").datetime.fromisoformat(at.replace("Z", "+00:00")))

    return [
        record("fix-1", "2026-10-09T03:00:00Z", "2026-10-09T03:02:00Z", "afisare rezultate"),
        record("fix-2", "2026-10-09T03:00:00Z", "2026-10-09T03:03:00Z",
               "afisare rezultate", "comunicare rezultate"),
        # The same slug and run as a failed record above, so one posting is in both outcomes' data.
        record("post-h", "2026-10-10T03:00:00Z", "2026-10-10T03:04:00Z", "comunicare rezultate"),
    ]


def by_value(summary):
    return {v["value"]: v for v in summary["values"]}


# --------------------------------------------------------------- raw logs


class TestRawLog:
    def _scan(self, sf, tmp_path, text, name="pipeline.log"):
        path = tmp_path / name
        path.write_text(text, encoding="utf-8")
        return sf.summarise(sf.scan_files([path]))

    def test_it_reads_the_multi_line_message_through_tqdm_carriage_returns(self, sf, tmp_path):
        summary = self._scan(sf, tmp_path, synthetic_log())
        values = by_value(summary)
        assert set(values) == {"afisare rezultate", "d'ordine", "fara banner"}
        assert values["afisare rezultate"]["occurrences"] == 3          # post-a, post-b, post-g
        assert values["afisare rezultate"]["postings"] == 3
        assert values["afisare rezultate"]["slugs"] == ["post-a", "post-b", "post-g"]
        assert values["d'ordine"]["occurrences"] == 1                    # a double-quoted repr decodes
        assert summary["totals"] == {"rejected_values": 3, "occurrences": 5, "postings": 4,
                                     "postings_failed": 4, "postings_repaired": 0}   # a log shows only failures

    def test_only_stage_paths_count(self, sf, tmp_path):
        values = by_value(self._scan(sf, tmp_path, synthetic_log()))
        assert "liceu" not in values and "stray" not in values and "prose" not in values

    def test_first_and_last_seen_come_from_the_run_banners(self, sf, tmp_path):
        v = by_value(self._scan(sf, tmp_path, synthetic_log()))
        assert v["afisare rezultate"]["first_seen"] == "2026-10-07T03:00:02Z"
        assert v["afisare rezultate"]["last_seen"] == "2026-10-08T03:00:03Z"
        assert v["fara banner"]["first_seen"] == ""                      # no banner, no time

    def test_a_log_has_only_the_final_attempt(self, sf, tmp_path):
        v = by_value(self._scan(sf, tmp_path, synthetic_log()))
        assert v["afisare rezultate"]["attempts"] == {"final": 3}

    def test_the_real_pydantic_text_parses(self, sf, tmp_path):
        """Not a hand-written imitation: the message the installed pydantic really prints."""
        with pytest.raises(ValidationError) as caught:
            model_for_version("v4").model_validate({"competition_calendar": {"events": [
                {"stage": "Afișare rezultate selecție"}, {"stage": "d'ordine"}]}})
        text = f"  ✗ real-1: ValidationError: {caught.value}\n"
        v = by_value(self._scan(sf, tmp_path, text))
        assert set(v) == {"Afișare rezultate selecție", "d'ordine"}

    def test_a_long_value_is_flagged_as_shortened(self, sf, tmp_path):
        with pytest.raises(ValidationError) as caught:
            model_for_version("v4").model_validate({"competition_calendar": {"events": [
                {"stage": "Afișarea rezultatelor selecției dosarelor și a probei de testare "
                          "fizică și psihologică de către comisie"}]}})
        summary = self._scan(sf, tmp_path, f"  ✗ long-1: ValidationError: {caught.value}\n")
        (value,) = summary["values"]
        assert "..." in value["value"] and value["truncated"] is True

    def test_gzip_and_plain_files_read_alike(self, sf, tmp_path):
        text = synthetic_log()
        plain = tmp_path / "pipeline.log"
        plain.write_text(text, encoding="utf-8")
        gz = tmp_path / "pipeline.log.1.gz"
        gz.write_bytes(gzip.compress(text.encode("utf-8")))
        assert sf.summarise(sf.scan_files([gz]))["values"] == sf.summarise(sf.scan_files([plain]))["values"]

    def test_crlf_and_bare_cr_separators_both_work(self, sf, tmp_path):
        path = tmp_path / "pipeline.log"
        path.write_bytes(synthetic_log().replace("\n", "\r\n").encode("utf-8"))
        assert by_value(sf.summarise(sf.scan_files([path])))["afisare rezultate"]["occurrences"] == 3


# ------------------------------------------------------------------ jsonl


class TestFailureLog:
    def _write(self, tmp_path, records, name="schema-failures.jsonl"):
        path = tmp_path / name
        path.write_text("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in records),
                        encoding="utf-8")
        return path

    def test_both_attempts_are_counted_with_their_attempt_number(self, sf, llm, tmp_path):
        path = self._write(tmp_path, jsonl_records(llm))
        v = by_value(sf.summarise(sf.scan_files([path])))
        assert set(v) == {"afisare rezultate pe site", "afisare rezultate", "comunicare rezultate"}
        assert v["afisare rezultate pe site"]["attempts"] == {"1": 1}     # a first-attempt value
        assert v["afisare rezultate"]["attempts"] == {"1": 1, "2": 1}     # post-h attempt 1, post-g attempt 2
        assert v["comunicare rezultate"]["attempts"] == {"2": 1}
        assert v["comunicare rezultate"]["first_seen"] == "2026-10-09T03:09:00Z"

    def test_records_without_a_stage_error_are_skipped(self, sf, llm, tmp_path):
        path = self._write(tmp_path, jsonl_records(llm))
        summary = sf.summarise(sf.scan_files([path]))
        assert summary["sources"][0]["failure_records"] == 3
        assert "post-i" not in {s for v in summary["values"] for s in v["slugs"]}

    def test_other_jsonl_kinds_in_the_same_file_are_ignored(self, sf, llm, tmp_path):
        records = [{"kind": "llm-schema", "run_id": "x", "failed": 3}, *jsonl_records(llm)]
        path = self._write(tmp_path, records)
        assert sf.summarise(sf.scan_files([path]))["sources"][0]["failure_records"] == 3

    def test_a_jsonl_gz_reads_too(self, sf, llm, tmp_path):
        path = tmp_path / "schema-failures.jsonl.gz"
        path.write_bytes(gzip.compress("".join(
            json.dumps(r, ensure_ascii=False) + "\n" for r in jsonl_records(llm)).encode("utf-8")))
        assert len(sf.summarise(sf.scan_files([path]))["values"]) == 3


class TestBothSources:
    def test_the_same_failure_in_both_is_counted_once_from_the_jsonl(self, sf, llm, tmp_path):
        log = tmp_path / "pipeline.log"
        log.write_text(synthetic_log(), encoding="utf-8")
        jsonl = tmp_path / "schema-failures.jsonl"
        jsonl.write_text("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in jsonl_records(llm)),
                         encoding="utf-8")
        v = by_value(sf.summarise(sf.scan_files([log, jsonl])))
        # post-g, run 2026-10-08T03:00:00Z, event 0: its final value 'afisare rezultate' is in
        # both sources. Counted once, as the jsonl's attempt 2. The rest: the log's post-a and
        # post-b ("final"), and post-h's FIRST attempt from the jsonl.
        slugs = v["afisare rezultate"]["slugs"]
        assert slugs.count("post-g") == 1
        assert v["afisare rezultate"]["occurrences"] == 4
        assert v["afisare rezultate"]["attempts"] == {"1": 1, "2": 1, "final": 2}

    def test_observations_without_a_run_id_are_never_merged(self, sf, tmp_path):
        text = failure_block("same-1", [(0, "afisare rezultate")]) * 2
        path = tmp_path / "pipeline.log"
        path.write_text(text, encoding="utf-8")
        assert by_value(sf.summarise(sf.scan_files([path])))["afisare rezultate"]["occurrences"] == 2


# --------------------------------------------------------------------- CLI


class TestCommandLine:
    def _files(self, llm, tmp_path):
        log = tmp_path / "pipeline.log"
        log.write_text(synthetic_log(), encoding="utf-8")
        jsonl = tmp_path / "schema-failures.jsonl"
        jsonl.write_text("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in jsonl_records(llm)),
                         encoding="utf-8")
        return log, jsonl

    def test_json_output_is_parseable_and_complete(self, sf, llm, tmp_path, capsys):
        log, jsonl = self._files(llm, tmp_path)
        assert sf.main([str(log), str(jsonl), "--json"]) == 0
        data = json.loads(capsys.readouterr().out)
        assert data["format"] == 1 and data["errors"] == []
        assert [s["path"] for s in data["sources"]] == [str(log), str(jsonl)]
        top = data["values"][0]
        assert top["value"] == "afisare rezultate" and top["occurrences"] == 4
        assert set(top) >= {"occurrences", "postings", "slugs", "first_seen", "last_seen", "attempts"}

    def test_the_table_lists_values_counts_and_example_slugs(self, sf, llm, tmp_path, capsys):
        log, jsonl = self._files(llm, tmp_path)
        assert sf.main([str(log), str(jsonl)]) == 0
        out = capsys.readouterr().out
        assert "COUNT" in out and "POSTINGS" in out and "FIRST SEEN" in out
        assert '"afisare rezultate"' in out and '"d\'ordine"' in out
        assert "e.g. post-a, post-b, post-g" in out

    def test_no_stage_failures_is_a_clean_report(self, sf, tmp_path, capsys):
        log = tmp_path / "pipeline.log"
        log.write_text("=== run ===\n  ✗ x-1: OutputTruncated: cut\n", encoding="utf-8")
        assert sf.main([str(log)]) == 0
        assert "No rejected stage values found." in capsys.readouterr().out

    def test_a_missing_file_is_reported_and_the_exit_is_2(self, sf, tmp_path, capsys):
        log = tmp_path / "pipeline.log"
        log.write_text(synthetic_log(), encoding="utf-8")
        assert sf.main([str(log), str(tmp_path / "gone.log"), "--json"]) == 2
        data = json.loads(capsys.readouterr().out)
        assert any("gone.log" in e for e in data["errors"])
        assert data["values"], "the readable file is still summarised"

    def test_it_never_modifies_its_inputs(self, sf, llm, tmp_path):
        log, jsonl = self._files(llm, tmp_path)
        before = {p: p.read_bytes() for p in (log, jsonl)}
        sf.main([str(log), str(jsonl), "--json"])
        assert {p: p.read_bytes() for p in (log, jsonl)} == before


# ------------------------------------------------------- repaired postings


class TestRepairedRecords:
    def _scan(self, sf, llm, tmp_path, *record_sets):
        path = tmp_path / "schema-failures.jsonl"
        records = [r for rs in record_sets for r in rs]
        path.write_text("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in records),
                        encoding="utf-8")
        return sf.summarise(sf.scan_files([path]))

    def test_repaired_values_are_reported_beside_the_failed_ones(self, sf, llm, tmp_path):
        summary = self._scan(sf, llm, tmp_path, jsonl_records(llm), repaired_records(llm))
        v = by_value(summary)
        # failed: post-g attempt 2, post-h attempt 1; repaired: fix-1, fix-2 (attempt 1 each)
        assert v["afisare rezultate"]["outcomes"] == {"failed": 2, "repaired": 2}
        assert v["afisare rezultate"]["occurrences"] == 4
        assert v["comunicare rezultate"]["outcomes"] == {"failed": 1, "repaired": 2}
        assert v["afisare rezultate pe site"]["outcomes"] == {"failed": 1, "repaired": 0}

    def test_a_repaired_attempt_is_never_final(self, sf, llm, tmp_path):
        summary = self._scan(sf, llm, tmp_path, repaired_records(llm))
        v = by_value(summary)
        assert v["afisare rezultate"]["attempts"] == {"1": 2}
        assert v["comunicare rezultate"]["attempts"] == {"1": 2}

    def test_totals_count_postings_by_outcome(self, sf, llm, tmp_path):
        summary = self._scan(sf, llm, tmp_path, jsonl_records(llm), repaired_records(llm))
        # failed: post-g, post-h; repaired: fix-1, fix-2, post-h (post-h is in both)
        assert summary["totals"]["postings_failed"] == 2
        assert summary["totals"]["postings_repaired"] == 3
        assert summary["totals"]["postings"] == 4
        assert summary["sources"][0]["failure_records"] == 3
        assert summary["sources"][0]["repaired_records"] == 3

    def test_a_repaired_record_is_not_merged_with_a_log_failure(self, sf, llm, tmp_path):
        """Dedupe is for one failure seen twice; a repaired attempt ended nothing."""
        log = tmp_path / "pipeline.log"
        log.write_text(RUN_2 + "\n" + failure_block("fix-1", [(0, "afisare rezultate")]),
                       encoding="utf-8")
        records = [dict(r, run_id="2026-10-08T03:00:00Z") for r in repaired_records(llm)[:1]]
        jsonl = tmp_path / "schema-failures.jsonl"
        jsonl.write_text("".join(json.dumps(r) + "\n" for r in records), encoding="utf-8")
        v = by_value(sf.summarise(sf.scan_files([log, jsonl])))
        assert v["afisare rezultate"]["outcomes"] == {"failed": 1, "repaired": 1}

    def test_the_table_has_failed_and_repaired_columns(self, sf, llm, tmp_path, capsys):
        path = tmp_path / "schema-failures.jsonl"
        path.write_text("".join(json.dumps(r, ensure_ascii=False) + "\n"
                                for r in [*jsonl_records(llm), *repaired_records(llm)]), encoding="utf-8")
        assert sf.main([str(path)]) == 0
        out = capsys.readouterr().out
        assert "FAILED" in out and "REPAIRED" in out
        assert "3 repaired records" in out and "(2 failed, 3 repaired)" in out
        row = next(line for line in out.splitlines() if line.rstrip().endswith('"afisare rezultate"'))
        cells = row.split()
        assert cells[:3] == ["4", "2", "2"]                   # COUNT, FAILED, REPAIRED

    def test_json_carries_outcomes(self, sf, llm, tmp_path, capsys):
        path = tmp_path / "schema-failures.jsonl"
        path.write_text("".join(json.dumps(r, ensure_ascii=False) + "\n"
                                for r in repaired_records(llm)), encoding="utf-8")
        assert sf.main([str(path), "--json"]) == 0
        data = json.loads(capsys.readouterr().out)
        top = {v["value"]: v["outcomes"] for v in data["values"]}
        assert top == {"afisare rezultate": {"failed": 0, "repaired": 2},
                       "comunicare rezultate": {"failed": 0, "repaired": 2}}
        assert data["totals"]["postings_repaired"] == 3 and data["totals"]["postings_failed"] == 0
