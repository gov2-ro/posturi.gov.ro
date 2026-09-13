#!/usr/bin/env python3
"""Estimate gross monthly pay for every posting, from the draft salary grid.

Deterministic — no LLM. It joins what the other passes already worked out:

    posting title  --ocupatii.parse_title()-->  professional grade
    occupation     --normalize-titles.py-->     which grid function
    employer       --uat.bands_for()-->         population band
    schema_json    --prompt v3-->               minimum study level
                          |
                          v
                   salary_grid.estimate()  ->  JobPosting.salary_estimate

Keeping it separate from the LLM passes is the point. The 2026 salary law is an
unadopted draft with more than one public variant, and when it moves, only this
step reruns: seconds, not a re-extraction of 9,600 postings.

Gradation is always 0. A posting constrains the *minimum* tenure it will accept,
not the tenure of whoever is hired, so a seniority-adjusted figure would be
fiction. The UI offers it as a slider instead.

Usage:
    python estimate-salaries.py --dry-run
    python estimate-salaries.py --grid-version 2026-07-17
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from collections import Counter
from pathlib import Path

import psycopg
from psycopg.rows import dict_row

ROOT = Path(__file__).parent.resolve()
sys.path.insert(0, str(ROOT))

import ocupatii  # noqa: E402
import salary_grid  # noqa: E402
import uat  # noqa: E402

try:
    from dotenv import load_dotenv
    load_dotenv(ROOT / ".env", override=False)
except ImportError:
    pass

DATABASE_URL = os.environ.get("DATABASE_URL", "postgres://localhost/posturi_dev")

#: Employer category -> where in the administrative hierarchy the post sits.
#: Only meaningful for Anexa VIII, which is the only annex split this way.
EMPLOYER_TO_LEVEL = {
    "Primării": "local",
    "Instituții locale": "local",
    "Consilii județene": "local",
    "Instituții județene": "teritorial",
    "Instituții regionale": "teritorial",
    "Prefecturi": "teritorial",
    "Guvern și ministere": "central",
    "Instituții naționale": "central",
}

SELECT_POSTINGS = """
    SELECT p.id, p.title, p.job_level, p.employer_category, p.schema_json,
           COALESCE(e.name, '')      AS employer_name,
           o.grid_selector, o.study_level, o.canonical, o.match_confidence
      FROM jobs_jobposting p
      JOIN jobs_occupation o ON o.id = p.occupation_id
      LEFT JOIN jobs_employer e ON e.id = p.employer_id
     WHERE p.occupation_id IS NOT NULL
"""


def study_level_for(posting: dict) -> str:
    """Prefer what the posting actually demands over what the job usually needs.

    v3 already extracted `education.minimum_level` from the text; the
    occupation's own level is the fallback for the postings v3 has not reached.
    """
    schema = posting.get("schema_json") or {}
    if isinstance(schema, dict):
        education = schema.get("education")
        if isinstance(education, dict) and education.get("minimum_level"):
            return education["minimum_level"]
    return posting.get("study_level") or ""


def build_selector(posting: dict) -> tuple[dict, dict]:
    """Return `(selector, provenance)` — the grid query and how it was decided."""
    selector = salary_grid.selector_to_grid(posting.get("grid_selector") or {})
    why: dict[str, str] = {}

    parsed = ocupatii.parse_title(posting["title"])
    if parsed.grade_token:
        # The posting's own grade always wins: the occupation dictionary is
        # keyed on the grade-stripped title, so its stored code is only ever
        # one arbitrary grade's row.
        selector["grad_treapta"] = parsed.grade_token
        why["grad_treapta"] = "din titlu"

    level = study_level_for(posting)
    if level:
        selector["studii"] = level
        why["studii"] = "din cerințele anunțului" if (posting.get("schema_json") or {}) else "din ocupație"

    if not selector.get("tip_post") and posting.get("job_level"):
        if posting["job_level"] == "Funcții de conducere":
            selector["tip_post"] = "conducere"
            why["tip_post"] = "din nivelul postului"
        elif posting["job_level"] == "Funcții de execuție":
            selector["tip_post"] = "execuție"
            why["tip_post"] = "din nivelul postului"

    level_hint = EMPLOYER_TO_LEVEL.get(posting.get("employer_category") or "")
    if level_hint and not selector.get("nivel_administrativ"):
        selector["nivel_administrativ"] = level_hint
        why["nivel_administrativ"] = "din tipul angajatorului"

    # The band only exists on Anexa VIII local sheets; asking elsewhere just
    # narrows to nothing.
    if selector.get("nivel_administrativ") == "local" or selector.get("anexa") == "VIII":
        bands, basis = uat.bands_for(posting["employer_name"])
        if bands:
            selector["banda_populatie"] = bands
            why["banda_populatie"] = basis
    return selector, why


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--grid-version", default=salary_grid.DEFAULT_VERSION)
    ap.add_argument("--limit", type=int, default=None)
    ap.add_argument("--dry-run", action="store_true", help="compute and report, write nothing")
    ap.add_argument("--clear", action="store_true",
                    help="null every salary_estimate first (use when the grid changed)")
    args = ap.parse_args()

    grid = salary_grid.load_grid(args.grid_version)
    stats = Counter()
    samples: list[str] = []

    with psycopg.connect(DATABASE_URL) as conn:
        if args.clear and not args.dry_run:
            with conn.cursor() as cur:
                cur.execute("UPDATE jobs_jobposting SET salary_estimate = NULL")
            conn.commit()
            print("cleared every existing estimate")

        sql = SELECT_POSTINGS + (f" LIMIT {int(args.limit)}" if args.limit else "")
        with conn.cursor(row_factory=dict_row) as cur:
            cur.execute(sql)
            postings = cur.fetchall()

        print(f"{len(postings)} postings have an occupation | grid {args.grid_version} "
              f"(VR {grid.variant.valoare_referinta_lei:.0f} lei, {grid.variant.status_juridic})")

        updates = []
        for posting in postings:
            selector, why = build_selector(posting)
            estimate = grid.estimate(selector, gradation=0)
            payload = estimate.as_dict()
            # A serialisable copy: the band may be a set of plausible values.
            payload["selector"] = {
                k: sorted(v) if isinstance(v, (set, frozenset)) else v
                for k, v in selector.items()
            }
            payload["selector_provenienta"] = why
            payload["ocupatie"] = posting["canonical"]
            payload["incredere_ocupatie"] = posting["match_confidence"]

            stats[estimate.incredere] += 1
            if estimate.lei_min is not None:
                stats["cu_estimare"] += 1
                if len(samples) < 12:
                    samples.append(
                        f"  {posting['canonical'][:28]:30s} {posting['employer_name'][:30]:32s} "
                        f"{estimate.lei_min}-{estimate.lei_max} lei"
                    )
            updates.append((json.dumps(payload, ensure_ascii=False), posting["id"]))

        if not args.dry_run:
            with conn.cursor() as cur:
                cur.executemany(
                    "UPDATE jobs_jobposting SET salary_estimate = %s::jsonb WHERE id = %s",
                    updates)
            conn.commit()

    print(f"\nestimated {stats['cu_estimare']}/{len(postings)} "
          f"({stats['cu_estimare'] / max(len(postings), 1):.0%} of postings with an occupation)")
    print("  confidence: " + ", ".join(
        f"{k}={stats[k]}" for k in ("exact", "interval", "necunoscut") if stats[k]))
    print("\n".join(samples))
    if args.dry_run:
        print("\n(dry run — nothing written)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
