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
import sys
from pathlib import Path

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
    SELECT p.id, p.expires_at,
           p.data_limita_depunere::date AS scraped_dl,
           (s.schema_json->'competition_calendar'->>'application_deadline')::date AS v4_dl,
           s.schema_json->'employer_context'->>'parent_institution' AS parent,
           s.schema_json->'funding'->>'source' AS funding,
           s.schema_json->>'note_suplimentare' AS notes,
           s.output_tokens
      FROM jobs_jobpostingschemavariant s
      JOIN jobs_jobposting p ON p.id = s.posting_id
     WHERE s.prompt_version = 'v4'
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
       coalesce(max(output_tokens), 0) AS peak_out_tokens
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
   AND lower(e->>'verbatim') LIKE '%%rezultat%%'
   AND lower(e->>'verbatim') NOT LIKE '%%contesta%%'
   AND e->>'stage' NOT LIKE 'rezultate%%'
 GROUP BY 1 ORDER BY 2 DESC
"""


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--min-sample", type=int, default=50,
                    help="below this the numbers are not worth acting on (default 50)")
    ap.add_argument("--min-deadline-rate", type=float, default=0.70,
                    help="fraction of the sample that must yield a deadline (default 0.70)")
    args = ap.parse_args()

    with psycopg.connect(DATABASE_URL) as conn:
        cur = conn.execute(SUMMARY)
        cols = [d.name for d in cur.description]
        row = dict(zip(cols, cur.fetchone()))
        mismatches = conn.execute(STAGE_MISMATCH).fetchall()

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
          f" (budget {_budget()})")

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
            "than 3 days — read those postings before trusting v4 dates over scraped ones")
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
