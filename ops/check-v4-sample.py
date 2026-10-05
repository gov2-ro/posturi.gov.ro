#!/usr/bin/env python3
"""Report on a prompt-v4 sample, before committing to a corpus-wide run.

A full v4 run is thousands of LLM calls. Almost everything it unlocks rests on
one field — `competition_calendar.application_deadline`, which is what decides
whether a posting is still open — so it is worth a few hundred postings and a
few minutes to check that field holds before paying for all of them.

Reads `jobs_jobpostingschemavariant` rows with `prompt_version = 'v4'`, which is
what `llm-schema.py --compare` writes. `--compare` never touches
`jobs_jobposting.schema_json`, so sampling changes nothing the site serves.

    python llm-schema.py --prompt-version v4 --compare --model-filter deepseek \
        --active-only --limit 200 --workers 4
    python ops/check-v4-sample.py

Exits non-zero if the sample looks unfit to scale up.
"""
from __future__ import annotations

import argparse
import os
import re
import sys
from datetime import date
from pathlib import Path
from urllib.parse import unquote

import psycopg

ROOT = Path(__file__).resolve().parent.parent
try:
    from dotenv import load_dotenv
    load_dotenv(ROOT / ".env", override=False)
except ImportError:
    pass

DATABASE_URL = os.environ.get("DATABASE_URL", "postgres://localhost/posturi_dev")

SUMMARY = """
WITH v AS (
    -- AT TIME ZONE, not a bare ::date. `data_limita_depunere` is stored at
    -- local midnight (21:00 UTC the previous day in summer), so a plain cast
    -- returns a different day depending on the server's TimeZone setting —
    -- Europe/Bucharest on the dev box, UTC on the VPS. That alone produced two
    -- of the four "disagreements" this script was written to investigate.
    SELECT p.id, p.expires_at,
           (p.data_limita_depunere AT TIME ZONE 'Europe/Bucharest')::date AS scraped_dl,
           (s.schema_json->'competition_calendar'->>'application_deadline')::date AS v4_dl,
           s.schema_json->'employer_context'->>'parent_institution' AS parent,
           s.schema_json->'funding'->>'source' AS funding,
           s.schema_json->>'note_suplimentare' AS notes,
           s.output_tokens, s.cost_usd
      FROM jobs_jobpostingschemavariant s
      JOIN jobs_jobposting p ON p.id = s.posting_id
     WHERE s.prompt_version = 'v4'
       -- --since: a paid sample on a box that already holds older v4 rows must
       -- be judged on its own rows only. Always two parameters (NULL = no
       -- bound) so the `%%` in the other queries keeps working either way.
       AND (%s::timestamptz IS NULL OR s.created_at >= %s::timestamptz)
)
SELECT count(*) AS sampled,
       count(v4_dl) AS got_deadline,
       count(*) FILTER (WHERE v4_dl IS NOT NULL AND expires_at > v4_dl) AS expiry_overstates,
       coalesce(max(expires_at - v4_dl), 0) AS worst_days_over,
       coalesce(round(avg(expires_at - v4_dl)), 0) AS avg_days_over,
       count(*) FILTER (WHERE scraped_dl IS NULL AND v4_dl IS NOT NULL) AS recovered_missing,
       count(*) FILTER (WHERE scraped_dl IS NOT NULL AND v4_dl IS NOT NULL) AS both_have_a_date,
       count(*) FILTER (WHERE scraped_dl IS NOT NULL AND v4_dl IS NOT NULL
                          AND v4_dl <> scraped_dl) AS disagrees_with_scraper,
       count(*) FILTER (WHERE scraped_dl IS NOT NULL AND v4_dl IS NOT NULL
                          AND abs(v4_dl - scraped_dl) > 3) AS disagrees_badly,
       count(*) FILTER (WHERE parent IS NOT NULL) AS got_parent_inst,
       count(*) FILTER (WHERE funding IS NOT NULL AND funding <> 'nespecificat') AS got_funding,
       count(*) FILTER (WHERE notes IS NOT NULL) AS got_notes,
       coalesce(max(output_tokens), 0) AS peak_out_tokens,
       coalesce(percentile_disc(0.9) WITHIN GROUP (ORDER BY output_tokens), 0) AS p90_out_tokens,
       coalesce(avg(cost_usd), 0) AS avg_cost,
       coalesce(sum(cost_usd), 0) AS total_cost
  FROM v
"""

#: Rows whose verbatim label and chosen stage disagree — the cheap way to spot a
#: gap in the enum. `rezultate_selectie_dosare` was missing until one of these
#: showed ten "rezultate ... dosare" rows scattered over five stages.
#:
#: "Afişarea rezultatelor contestaţiilor" is deliberately NOT flagged: filing a
#: contestation and publishing its outcome share one stage by design, so a
#: `contestatii_*` row whose label mentions a result is correct, not a gap.
#: Without that exclusion this cried wolf on 31 of 41 rows and would have been
#: ignored the first time it mattered.
STAGE_MISMATCH = """
SELECT e->>'stage' AS stage, count(*) AS n,
       min(e->>'verbatim') AS example
  FROM jobs_jobpostingschemavariant s,
       jsonb_array_elements(coalesce(s.schema_json->'competition_calendar'->'events','[]'::jsonb)) e
 WHERE s.prompt_version = 'v4'
   AND (%s::timestamptz IS NULL OR s.created_at >= %s::timestamptz)
   AND lower(e->>'verbatim') LIKE '%%rezultat%%'
   AND lower(e->>'verbatim') NOT LIKE '%%contesta%%'
   AND e->>'stage' NOT LIKE 'rezultate%%'
 GROUP BY 1 ORDER BY 2 DESC
"""

#: One row per sampled variant, newest published first, with the text the
#: reviewer needs to check the deadline against. Events come back as jsonb and
#: are filtered in Python: it is a handful of rows and the stage name is the
#: only thing the SQL would add.
EVIDENCE = """
SELECT p.url, p.published_at, p.expires_at,
       (p.data_limita_depunere AT TIME ZONE 'Europe/Bucharest')::date AS scraped_dl,
       s.schema_json->'competition_calendar'->>'application_deadline' AS v4_dl,
       s.schema_json->'competition_calendar'->>'application_deadline_time' AS v4_time,
       coalesce(s.schema_json->'competition_calendar'->'events', '[]'::jsonb) AS events,
       s.output_tokens, s.model,
       p.body_markdown || E'\\n' || p.attachment_text AS source_text
  FROM jobs_jobpostingschemavariant s
  JOIN jobs_jobposting p ON p.id = s.posting_id
 WHERE s.prompt_version = 'v4'
   AND (%s::timestamptz IS NULL OR s.created_at >= %s::timestamptz)
 ORDER BY p.published_at DESC NULLS LAST, p.id
"""

#: Romanian month names, without diacritics (matching is done on a folded copy
#: of the text, see `_fold`).
_MONTHS = ["ianuarie", "februarie", "martie", "aprilie", "mai", "iunie",
           "iulie", "august", "septembrie", "octombrie", "noiembrie", "decembrie"]


def _fold(text: str) -> str:
    """Lowercase and strip Romanian diacritics, keeping the length unchanged.

    Both the cedilla (ş ţ) and comma-below (ș ț) forms occur in the scraped
    pages, often in the same posting, so a literal "octombrie"-style search
    must not depend on which one the source used. Every replacement is
    one character for one character, so an index in the folded text is an
    index in the original and the snippet can be cut from the original.
    """
    table = str.maketrans("ăâîșşțţĂÂÎȘŞȚŢ", "aaisstt" "aaisstt")
    return text.translate(table).lower()


def date_patterns(iso: str) -> list[str]:
    """Ways Romanian text writes the date `iso` (YYYY-MM-DD), as regexes.

    Numeric forms are anchored so `5.10.2026` does not match inside
    `15.10.2026` and `15.10.26` does not match inside `15.10.2026` — on digits
    only: a deadline is often the end of a range, `09.09.2026-22.09.2026`. Month-name
    forms are meant for `_fold`ed text. The more specific spellings come
    first; the caller takes whichever match is earliest in the text.
    """
    d = date.fromisoformat(iso)
    dd, mm, yyyy, yy = f"{d.day:02d}", f"{d.month:02d}", f"{d.year}", f"{d.year % 100:02d}"
    day_forms = {dd, str(d.day)}
    pats = []
    for sep in (r"\.", "/", "-"):
        for day in sorted(day_forms, reverse=True):
            pats.append(rf"(?<!\d){day}{sep}{mm}{sep}{yyyy}(?!\d)")
    for day in sorted(day_forms, reverse=True):
        pats.append(rf"(?<!\d){day}\.{mm}\.{yy}(?!\d)")
        pats.append(rf"(?<!\d){day}\s+{_MONTHS[d.month - 1]}\s+{yyyy}(?!\d)")
    return pats


def _clip(text: str, start: int, end: int, width: int) -> str:
    half = max(0, (width - (end - start)) // 2)
    chunk = text[max(0, start - half):end + half]
    return re.sub(r"\s+", " ", chunk).strip()


def find_snippet(text: str, iso: str, width: int = 160) -> str | None:
    """~`width` chars of `text` around the first spelling of `iso`, or None.

    "First" is by position in the text, not by pattern order: the reviewer
    wants the earliest place the posting states that date.
    """
    folded = _fold(text)
    best = None
    for pat in date_patterns(iso):
        m = re.search(pat, folded)
        if m and (best is None or m.start() < best.start()):
            best = m
    if best is None:
        return None
    return _clip(text, best.start(), best.end(), width)


def find_depunere(text: str, width: int = 160) -> str | None:
    """Snippet around the first "depunere" (any case/diacritics), or None."""
    m = re.search("depunere", _fold(text))
    return _clip(text, m.start(), m.end(), width) if m else None


def print_evidence(rows) -> None:
    """One block per sampled variant, for a human to read against the source."""
    print("\nEVIDENCE — each v4 deadline beside the text that should support it\n")
    for (url, published, expires, scraped, v4_dl, v4_time, events,
         out_tokens, model, text) in rows:
        slug = unquote(url.rstrip("/").rsplit("/", 1)[-1])
        print(f"* {slug}  [{model}]")
        print(f"    published {published}   expires_at {expires}   scraped deadline {scraped}")
        print(f"    v4 deadline {v4_dl or 'none'}"
              f"{f' {v4_time}' if v4_time else ''}   output_tokens {out_tokens}")
        for e in events:
            if e.get("stage") == "depunere_dosare":
                print(f"    event depunere_dosare  {e.get('date')} {e.get('time') or ''}"
                      f"  \"{e.get('verbatim')}\"")
        flags = []
        if v4_dl and expires and expires > date.fromisoformat(v4_dl):
            flags.append(f"EXPIRY OVERSTATES (expires_at {expires} > v4 {v4_dl})")
        if v4_dl and scraped and scraped != date.fromisoformat(v4_dl):
            flags.append(f"SCRAPED/V4 DISAGREE ({scraped} vs {v4_dl})")
        if flags:
            print("    !! " + "; ".join(flags))
        if v4_dl:
            snip = find_snippet(text or "", v4_dl)
            if snip is None:
                print(f"    SOURCE SNIPPET: !!! {v4_dl} NOT FOUND in source text !!!")
            else:
                print(f"    SOURCE SNIPPET: ...{snip}...")
        else:
            snip = find_depunere(text or "")
            shown = f"...{snip}..." if snip else "no 'depunere' in source"
            print(f"    SOURCE SNIPPET (v4 gave no deadline, around 'depunere'): {shown}")
        print()


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--min-sample", type=int, default=50,
                    help="below this the numbers are not worth acting on (default 50)")
    ap.add_argument("--min-deadline-rate", type=float, default=0.70,
                    help="fraction of the sample that must yield a deadline (default 0.70)")
    ap.add_argument("--since", metavar="TIMESTAMP",
                    help="only variants created at or after this ISO 8601 time, e.g. "
                         "2026-10-05T09:00Z — so a fresh sample is not mixed with older v4 rows")
    ap.add_argument("--evidence", action="store_true",
                    help="after the summary, print each posting's v4 deadline next to the "
                         "source text around it, for a human to check")
    args = ap.parse_args()
    params = (args.since, args.since)

    with psycopg.connect(DATABASE_URL) as conn:
        cur = conn.execute(SUMMARY, params)
        cols = [d.name for d in cur.description]
        row = dict(zip(cols, cur.fetchone()))
        mismatches = conn.execute(STAGE_MISMATCH, params).fetchall()
        evidence = conn.execute(EVIDENCE, params).fetchall() if args.evidence else []

    n = row["sampled"]
    if n == 0:
        print("No v4 variants found. Run llm-schema.py --prompt-version v4 --compare first.")
        return 1

    print(f"v4 sample: {n} postings\n")
    rate = row["got_deadline"] / n
    print(f"  application deadline extracted   {row['got_deadline']}/{n} ({rate:.0%})")
    print(f"    of those, expires_at is later   {row['expiry_overstates']}"
          f"  (avg {row['avg_days_over']} days, worst {row['worst_days_over']})")
    print(f"    recovered where the scraper had none  {row['recovered_missing']}")
    # The denominator is the point. "4 disagreements" out of 113 deadlines reads
    # as noise; out of the 4 postings where both sources actually had a date it
    # is a 100% disagreement rate. Almost every v4 deadline is a recovery, so
    # the overlap is always small and the rate is what has to be reported.
    both = row["both_have_a_date"]
    if both:
        bad = row["disagrees_badly"]
        print(f"    both sources had a date               {both}")
        print(f"      of those, they disagree             {row['disagrees_with_scraper']}"
              f"/{both} ({row['disagrees_with_scraper'] / both:.0%})"
              f"{f', {bad} by more than 3 days' if bad else ', all within 3 days'}")
    print()
    print(f"  parent institution resolved      {row['got_parent_inst']}/{n}")
    print(f"  funding source identified        {row['got_funding']}/{n}")
    print(f"  note_suplimentare written        {row['got_notes']}/{n}")
    print(f"  peak output tokens               {row['peak_out_tokens']}"
          f" (budget {_budget()}), p90 {row['p90_out_tokens']}")
    print(f"  cost per posting / total         ${row['avg_cost']:.4f} / ${row['total_cost']:.2f}")

    if args.evidence:
        print_evidence(evidence)

    if mismatches:
        print("\n  stage/verbatim mismatches — a results row filed under a non-results stage:")
        for stage, count, example in mismatches:
            print(f"    {count:3d}  {stage:28s} e.g. {(example or '')[:58]}")
        print("    An enum gap looks exactly like this: nothing errors, the model")
        print("    just picks the nearest stage it was offered.")

    problems = []
    if n < args.min_sample:
        problems.append(f"sample is only {n}; --min-sample is {args.min_sample}")
    if rate < args.min_deadline_rate:
        problems.append(f"deadline rate {rate:.0%} is below {args.min_deadline_rate:.0%}")
    budget = _budget()
    # A date nobody can cross-check is the one worth doubting. Small
    # disagreements are the usual off-by-one over which endpoint counts; a large
    # one means the two sources are reading different rows of the table.
    if row["disagrees_badly"]:
        problems.append(
            f"{row['disagrees_badly']} deadline(s) differ from the scraped date by more "
            "than 3 days — read those postings before trusting v4 dates over scraped ones.\n"
            "      If `parse-anunturi.py` has been fixed but `pipeline.py --steps parse,import`\n"
            "      has not been re-run, the scraped side is stale and this is expected:\n"
            "      the known cases are a deadline row read off the selection date, and a\n"
            "      submission window read at its opening instead of its close.")
    if row["peak_out_tokens"] >= budget:
        problems.append(f"output hit the {budget}-token budget — answers are being truncated")
    elif row["peak_out_tokens"] >= budget * 0.9:
        # Not a failure: one long posting near the ceiling is expected. But
        # truncation is silent, and a corpus-wide run will meet postings longer
        # than anything in a 200-row sample.
        print(f"\n  CAUTION: peak output is {row['peak_out_tokens']} of a {budget} budget"
              f" ({row['peak_out_tokens'] / budget:.0%}). A longer posting than any in"
              " this sample will truncate, and truncation is silent.")

    print()
    if problems:
        print("NOT ready to scale up:")
        for p in problems:
            print(f"  - {p}")
        return 1
    print("Sample looks fit to scale up.")
    if mismatches:
        print("Consider the stage mismatches above first — they are cheap to fix"
              " and expensive to re-run.")
    return 0


def _budget() -> int:
    """The v4 output-token budget, read from llm-schema.py rather than copied.

    If the peak output in a sample equals this, answers are being truncated —
    which shows up as "Expected dict, got str", never as a clean error.
    """
    import importlib.util
    # llm-schema.py imports its siblings (boilerplate, grounding) by bare name.
    if str(ROOT) not in sys.path:
        sys.path.insert(0, str(ROOT))
    spec = importlib.util.spec_from_file_location("llm_schema", ROOT / "llm-schema.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.max_output_tokens("v4")


if __name__ == "__main__":
    sys.exit(main())
