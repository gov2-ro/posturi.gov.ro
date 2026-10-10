#!/usr/bin/env python3
"""Summarise the calendar stages the extraction model emitted and the schema rejected.

`CalendarStage` is a closed vocabulary. A posting whose calendar names a stage that
is not in it fails validation, is repaired once, and — if the repair repeats the
mistake — is dropped whole (FIX-05-STAGES). Before `data/schema-failures.jsonl`
existed the only trace was the console line of the final attempt, in
logs/pipeline.log. This reads both and answers the policy question: WHICH raw
values are being rejected, how often, in how many postings, and since when.

Read-only: it opens the files it is given and prints. No database, no network, no
API call, nothing written.

Sources (any number, mixed, `.gz` accepted):

  * `data/schema-failures.jsonl` — one `kind: "llm-schema-failure"` record per
    posting that failed for good, and one `kind: "llm-schema-repaired"` record per
    posting that was repaired and then SUCCEEDED, each carrying the rejected value of
    EVERY failed attempt. This is the only source that shows attempt 1, and the only
    one that shows the repaired postings. The report keeps the two outcomes apart
    (FAILED / REPAIRED columns): together they say how often the model emits an
    unlisted stage at all, the failed ones how often that cost a posting its whole
    extraction.
  * raw pipeline logs (`logs/pipeline.log`, rotated `pipeline.log.1`, `*.gz`) — the
    console prints only the FINAL attempt's error, so those values count as
    attempt "final". tqdm's carriage-return redraws are tolerated, and the
    `=== posturi pipeline run <id> ... ===` banner gives each failure its run and
    time. A log has no timestamps otherwise: failures before the first banner are
    reported with no "seen" time.

The same failure in a jsonl and a log of the same run (same run id, posting, event
and value) is counted once, from the jsonl.

Usage:
    ops/stage-failures.py data/schema-failures.jsonl logs/pipeline.log
    ops/stage-failures.py logs/pipeline.log logs/pipeline.log.2.gz --json
    ops/stage-failures.py data/schema-failures.jsonl --examples 8
"""
from __future__ import annotations

import argparse
import ast
import gzip
import json
import re
import sys
from collections import defaultdict
from dataclasses import dataclass, field
from pathlib import Path

#: `competition_calendar.events.<N>.stage`, as pydantic prints it and as the failure
#: log records it (`loc`, dotted).
STAGE_LOC = re.compile(r"(?:^|\.)competition_calendar\.events\.(\d+)\.stage$")

#: One run's banner from ops/run-pipeline.sh.
RUN_BANNER = re.compile(r"posturi pipeline run (?P<run>\S+)")
ISO_TS = re.compile(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z")

#: `  ✗ <slug>: <ErrorClass>: <message start>` — the final line of a failed posting.
#: The two record kinds llm-schema.py writes to its failure log, and the outcome each means.
RECORD_OUTCOMES = {"llm-schema-failure": "failed", "llm-schema-repaired": "repaired"}

FAILURE_LINE = re.compile(r"✗ (?P<slug>[^\s:]+): (?P<cls>[A-Za-z_][\w.]*): ?(?P<rest>.*)$")
#: pydantic's per-error location line: a dotted path alone on its line.
LOC_LINE = re.compile(r"^[A-Za-z_][\w\-]*(?:\.[\w\-]+|\[\d+\])*$")
#: The tail of a pydantic error message.
ERROR_TAIL = re.compile(
    r"\[type=(?P<type>\w+), input_value=(?P<value>.*), input_type=(?P<itype>\w+)\]\s*$")

# Lines that end a failure block: the next posting's marker, a run banner, a step banner.
BLOCK_END = re.compile(r"^\s*(?:✗|⟳|⚠|✓|===|STEP:|CMD:)")


@dataclass
class Observation:
    """One rejected stage value."""
    value: str
    slug: str
    event: int | None
    attempt: str            # "1", "2", ... from a jsonl record; "final" from a log
    final: bool             # the attempt that ended the posting
    run_id: str
    seen: str               # ISO time, or ""
    source: str             # file the observation came from
    origin: str = "log"     # "jsonl" (failure log record) or "log" (raw console text)
    outcome: str = "failed"  # what became of the posting: "failed", or "repaired" (it succeeded)
    truncated: bool = False  # the value looks shortened (pydantic's repr, or the jsonl clip)


@dataclass
class Scan:
    files: list = field(default_factory=list)
    observations: list = field(default_factory=list)
    errors: list = field(default_factory=list)


# ---------------------------------------------------------------------------
# Parsing
# ---------------------------------------------------------------------------

def _open_text(path: Path):
    """Text handle with universal newlines: a bare `\\r` (tqdm) ends a line like `\\n`."""
    if path.suffix == ".gz":
        return gzip.open(path, "rt", encoding="utf-8", errors="replace")
    return path.open("r", encoding="utf-8", errors="replace")


def decode_pydantic_value(text: str) -> tuple[str, bool]:
    """The raw value out of pydantic's `input_value=<repr>`, and whether it was shortened.

    pydantic prints the Python repr, quoted with ' or " as the content demands, and
    elides the middle of anything long (`'abc...xyz'`).
    """
    text = text.strip()
    try:
        value = ast.literal_eval(text)
        if isinstance(value, str):
            return value, "..." in value or "…" in value
    except (ValueError, SyntaxError):
        pass
    if len(text) >= 2 and text[0] == text[-1] and text[0] in "'\"":
        text = text[1:-1]
    return text, "..." in text or "…" in text


def scan_jsonl_record(record: dict, source: str) -> list[Observation]:
    """The stage rejections in one failure-log record, one per attempt and event.

    In a `repaired` record the posting went on to succeed, so none of its attempts
    ended it: nothing there is `final`, and nothing is deduplicated against a log.
    """
    found = []
    outcome = RECORD_OUTCOMES.get(record.get("kind"), "failed")
    attempts = record.get("attempts") or []
    for index, attempt in enumerate(attempts, 1):
        number = attempt.get("attempt") or index
        for err in attempt.get("errors") or []:
            match = STAGE_LOC.search(str(err.get("loc", "")))
            if not match:
                continue
            found.append(Observation(
                value=str(err.get("input", "")),
                slug=str(record.get("slug") or ""),
                event=int(match.group(1)),
                attempt=str(number),
                final=outcome == "failed" and index == len(attempts),
                run_id=str(record.get("run_id") or ""),
                seen=str(record.get("at") or ""),
                source=source,
                origin="jsonl",
                outcome=outcome,
                # The failure log clips each input at 200 characters.
                truncated=len(str(err.get("input", ""))) >= 200,
            ))
    return found


class LogScanner:
    """Line-by-line state machine over a raw pipeline log."""

    def __init__(self, source: str):
        self.source = source
        self.run_id = ""
        self.seen = ""
        self.slug = ""
        self.cls = ""
        self.loc = ""
        self.blocks = 0
        self.observations: list[Observation] = []

    def feed(self, line: str) -> None:
        line = line.rstrip("\n")
        banner = RUN_BANNER.search(line)
        if banner:
            self.run_id = banner.group("run")
            stamps = ISO_TS.findall(line)
            self.seen = stamps[-1] if stamps else (self.run_id if ISO_TS.fullmatch(self.run_id) else "")
            self.slug = self.cls = self.loc = ""
            return

        failure = FAILURE_LINE.search(line)
        if failure:
            self.slug, self.cls, self.loc = failure.group("slug"), failure.group("cls"), ""
            self.blocks += 1
            # A single-line error carries its own tail; the multi-line pydantic
            # form puts the path and the tail on the lines that follow.
            self._tail(failure.group("rest"))
            return

        if not self.slug:
            return
        if BLOCK_END.match(line):
            self.slug = self.cls = self.loc = ""
            return
        stripped = line.strip()
        if self.cls == "ValidationError" and not line[:1].isspace() and LOC_LINE.match(stripped):
            self.loc = stripped
            return
        self._tail(stripped)

    def _tail(self, text: str) -> None:
        tail = ERROR_TAIL.search(text)
        if not tail or not self.loc:
            return
        if not STAGE_LOC.search(self.loc):
            return
        value, truncated = decode_pydantic_value(tail.group("value"))
        self.observations.append(Observation(
            value=value, slug=self.slug, event=int(STAGE_LOC.search(self.loc).group(1)),
            attempt="final", final=True, run_id=self.run_id, seen=self.seen,
            source=self.source, truncated=truncated))


def scan_files(paths: list[Path]) -> Scan:
    scan = Scan()
    for path in paths:
        info = {"path": str(path), "lines": 0, "failure_records": 0, "repaired_records": 0,
                "failure_blocks": 0}
        try:
            handle = _open_text(path)
        except OSError as exc:
            scan.errors.append(f"{path}: {exc}")
            continue
        log = LogScanner(str(path))
        try:
            with handle:
                for line in handle:
                    info["lines"] += 1
                    stripped = line.lstrip()
                    if stripped.startswith("{"):
                        try:
                            record = json.loads(stripped)
                        except ValueError:
                            record = None
                        if isinstance(record, dict):
                            kind = record.get("kind")
                            if kind in RECORD_OUTCOMES:
                                key = ("failure_records" if RECORD_OUTCOMES[kind] == "failed"
                                       else "repaired_records")
                                info[key] += 1
                                scan.observations.extend(scan_jsonl_record(record, str(path)))
                            continue            # any other JSON line (run summaries) is not a log line
                    log.feed(line)
        except (OSError, EOFError, gzip.BadGzipFile) as exc:
            scan.errors.append(f"{path}: {exc}")
        info["failure_blocks"] = log.blocks
        records = info["failure_records"] + info["repaired_records"]
        info["kind"] = ("jsonl" if records and not log.blocks
                        else "log" if log.blocks and not records
                        else "mixed" if records else "empty")
        scan.files.append(info)
        scan.observations.extend(log.observations)
    return scan


# ---------------------------------------------------------------------------
# Aggregation
# ---------------------------------------------------------------------------

def dedupe(observations: list[Observation]) -> list[Observation]:
    """Count one failure once when a jsonl and a log both recorded it.

    A final-attempt observation with a known run id is identified by
    (run, slug, event, value). Observations without a run id are never merged — two
    runs of a bare log cannot be told apart.
    """
    ordered = sorted(observations, key=lambda o: o.origin != "jsonl")   # jsonl first: it is richer
    seen, kept = set(), []
    for obs in ordered:
        if obs.final and obs.run_id:
            key = (obs.run_id, obs.slug, obs.event, obs.value)
            if key in seen:
                continue
            seen.add(key)
        kept.append(obs)
    return kept


def summarise(scan: Scan) -> dict:
    observations = dedupe(scan.observations)
    groups: dict[str, list[Observation]] = defaultdict(list)
    for obs in observations:
        groups[obs.value].append(obs)

    values = []
    for value, group in groups.items():
        attempts: dict[str, int] = defaultdict(int)
        for obs in group:
            attempts[obs.attempt] += 1
        seen = sorted(o.seen for o in group if o.seen)
        # Chronological, unknown times last, so "e.g." names the earliest postings.
        slugs = list(dict.fromkeys(
            o.slug for o in sorted(group, key=lambda o: (o.seen == "", o.seen, o.slug)) if o.slug))
        outcomes = {name: sum(o.outcome == name for o in group) for name in ("failed", "repaired")}
        values.append({
            "value": value,
            "occurrences": len(group),
            "outcomes": outcomes,
            "postings": len(slugs),
            "slugs": slugs,
            "first_seen": seen[0] if seen else "",
            "last_seen": seen[-1] if seen else "",
            "attempts": dict(sorted(attempts.items())),
            "truncated": any(o.truncated for o in group),
        })
    values.sort(key=lambda v: (-v["occurrences"], v["value"]))
    return {
        "format": 1,
        "sources": scan.files,
        "errors": scan.errors,
        "totals": {
            "rejected_values": len(values),
            "occurrences": len(observations),
            "postings": len({o.slug for o in observations if o.slug}),
            # A posting can be in both over time: failed in one run, repaired in another.
            "postings_failed": len({o.slug for o in observations if o.slug and o.outcome == "failed"}),
            "postings_repaired": len({o.slug for o in observations
                                      if o.slug and o.outcome == "repaired"}),
        },
        "values": values,
    }


# ---------------------------------------------------------------------------
# Output
# ---------------------------------------------------------------------------

def render_table(summary: dict, examples: int) -> str:
    out = []
    t = summary["totals"]
    out.append("Rejected competition_calendar.events.N.stage values")
    for src in summary["sources"]:
        out.append(f"  {src['path']}: {src['lines']} lines, {src['failure_records']} failure "
                   f"records, {src['repaired_records']} repaired records, "
                   f"{src['failure_blocks']} log failures")
    out.append(f"  {t['rejected_values']} distinct values, {t['occurrences']} occurrences "
               f"in {t['postings']} postings ({t['postings_failed']} failed, "
               f"{t['postings_repaired']} repaired)")
    out.append("")
    if not summary["values"]:
        out.append("  No rejected stage values found.")
    else:
        attempt_text = {v["value"]: " ".join(f"{k}:{n}" for k, n in v["attempts"].items())
                        for v in summary["values"]}
        width = max([len("ATTEMPT"), *map(len, attempt_text.values())])
        out.append(f"  {'COUNT':>5}  {'FAILED':>6}  {'REPAIRED':>8}  {'POSTINGS':>8}  "
                   f"{'ATTEMPT':<{width}}  {'FIRST SEEN':<20}  {'LAST SEEN':<20}  VALUE")
        for v in summary["values"]:
            attempts = attempt_text[v["value"]]
            shown = json.dumps(v["value"], ensure_ascii=False) + ("*" if v["truncated"] else "")
            out.append(f"  {v['occurrences']:>5}  {v['outcomes']['failed']:>6}  "
                       f"{v['outcomes']['repaired']:>8}  {v['postings']:>8}  {attempts:<{width}}  "
                       f"{v['first_seen'] or '?':<20}  {v['last_seen'] or '?':<20}  {shown}")
            if v["slugs"] and examples:
                more = len(v["slugs"]) - examples
                tail = f" (+{more} more)" if more > 0 else ""
                out.append(f"         e.g. {', '.join(v['slugs'][:examples])}{tail}")
        out.append("")
        out.append("  FAILED = the posting lost its extraction; REPAIRED = the model was rejected "
                   "but the repair attempt succeeded (failure log only).")
        out.append("  ATTEMPT: 1, 2 = which try of the posting (failure log only); "
                   "final = the last try, the only one a raw log prints.")
        if any(v["truncated"] for v in summary["values"]):
            out.append("  * the value looks shortened (pydantic elides long reprs; the jsonl clips at 200).")
    for err in summary["errors"]:
        out.append(f"  ERROR {err}")
    return "\n".join(out)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Summarise rejected competition_calendar stage values from "
                    "schema-failures.jsonl and raw pipeline logs (read-only).")
    parser.add_argument("paths", nargs="+", type=Path, metavar="PATH",
                        help="schema-failures.jsonl and/or pipeline log files (.gz accepted)")
    parser.add_argument("--json", action="store_true", help="print the summary as JSON")
    parser.add_argument("--examples", type=int, default=3, metavar="N",
                        help="example slugs per value in the table (default: %(default)s; "
                             "--json always lists every slug)")
    args = parser.parse_args(argv)

    summary = summarise(scan_files(args.paths))
    if args.json:
        print(json.dumps(summary, ensure_ascii=False, indent=2))
    else:
        print(render_table(summary, args.examples))
    return 2 if summary["errors"] else 0


if __name__ == "__main__":
    sys.exit(main())
