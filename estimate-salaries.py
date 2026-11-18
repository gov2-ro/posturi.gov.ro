#!/usr/bin/env python3
"""Estimate gross monthly pay for every posting, under two baselines.

`lege_in_vigoare` is Legea 153/2017 as it applies in 2026 (`lege153-2026`); `proiect`
is the July 2026 draft law. The law in force is the figure at the top level of
`salary_estimate`; the draft figure sits under the key `proiect`. When the law in
force has no row for a posting, the draft figure takes the top level (ADR 0008,
rule 4). A row with no `baza` is an old row and counts as a draft figure.

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
import re
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
           COALESCE(j.name, '')      AS judet_name,
           o.grid_selector, o.study_level, o.canonical, o.match_confidence
      FROM jobs_jobposting p
      JOIN jobs_occupation o ON o.id = p.occupation_id
      LEFT JOIN jobs_employer e ON e.id = p.employer_id
      LEFT JOIN jobs_judet j ON j.id = p.judet_id
     WHERE p.occupation_id IS NOT NULL
"""


#: The rule for the type of hospital (OUG 19/2024, Anexa 1: a.1 clinical units, a.4
#: other units). A name with "Clinic", "Universitar" or "Institut" is clinical.
#: Everything else, and every doubt, is a.4. This is the only place for the rule.
_CLINICAL_NAME = re.compile(r"\b(clinic\w*|universitar\w*|institut\w*)\b")


def hospital_type(employer_name: str) -> str:
    """`a.1` for a clinical unit, `a.4` for any other. `a.4` when the name is empty."""
    return "a.1" if _CLINICAL_NAME.search(salary_grid.norm(employer_name)) else "a.4"


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
        bands, basis = uat.bands_for(posting["employer_name"], posting.get("judet_name"))
        if bands:
            selector["banda_populatie"] = bands
            why["banda_populatie"] = basis
    return selector, why


#: Employers whose council (HCL) sets the pay, up to the cap of Art. 11 alin. (4): the
#: primării, the county councils and the local public services. Schools, hospitals and
#: universities of the local budget pay the annex values and get no range.
_COUNCIL_NAME = re.compile(
    r"^(primari|comuna|orasul|municipiul|consiliul|serviciul public|politia locala|"
    r"directia (generala de )?asistenta sociala)")


def council_employer(posting: dict) -> bool:
    if posting.get("employer_category") in ("Primării", "Consilii județene"):
        return True
    return bool(_COUNCIL_NAME.match(salary_grid.norm(posting["employer_name"])))


def law_selector(selector: dict, posting: dict, draft_grid) -> dict:
    """The selector for the law-in-force grid: the draft one, mapped, plus what only the law needs."""
    sel = salary_grid.selector_for_law(selector, draft_grid)
    if sel.get("anexa") == "II":
        sel["tip_unitate"] = hospital_type(posting["employer_name"])
    if sel.get("nivel_administrativ") == "local":
        if council_employer(posting):
            sel["uat_unitati"] = uat.units_for(posting["employer_name"], posting.get("judet_name"))
        else:
            sel["nivel_administrativ"] = "teritorial"      # fixed annex value, no range
    return sel


def serialisable(selector: dict) -> dict:
    """A copy for JSON: the band may be a set of plausible values."""
    return {k: sorted(v) if isinstance(v, (set, frozenset)) else v for k, v in selector.items()}


def assemble(law, draft, law_sel: dict, draft_sel: dict) -> dict:
    """The JSON of one posting (ADR 0008, rule 4): the law in force when it has a figure.

    The top level keeps the keys of the old format, so `sal_min`, `sal_max` and the
    filter read it unchanged. The draft figure sits under `proiect`. When the law in
    force has no row, the draft figure takes the top level, with `baza` = `proiect`.
    """
    use_law = law.lei_min is not None
    primary, sel = (law, law_sel) if use_law else (draft, draft_sel)
    payload = primary.as_dict()
    payload["baza"] = "lege_in_vigoare" if use_law else "proiect"
    payload["selector"] = serialisable({k: v for k, v in sel.items() if k != "functie_alt"})
    if use_law and draft.lei_min is not None:
        payload["proiect"] = dict(draft.as_dict(), baza="proiect")
    return payload


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--grid-version", default=salary_grid.DEFAULT_VERSION,
                    help="the draft variant (the `proiect` baseline)")
    ap.add_argument("--law-version", default=salary_grid.LAW_VERSION,
                    help="the law-in-force grid (the `lege_in_vigoare` baseline)")
    ap.add_argument("--limit", type=int, default=None)
    ap.add_argument("--dry-run", action="store_true", help="compute and report, write nothing")
    ap.add_argument("--clear", action="store_true",
                    help="null every salary_estimate first (use when the grid changed)")
    ap.add_argument("--hospital-names", action="store_true",
                    help="print 20 employer names of each hospital class")
    args = ap.parse_args()

    draft_grid = salary_grid.load_grid(args.grid_version)
    law_grid = salary_grid.load_grid(args.law_version)
    stats = Counter()
    by_annex: dict[str, Counter] = {}
    hospital_names: dict[str, set] = {"a.1": set(), "a.4": set()}
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

        print(f"{len(postings)} postings have an occupation | law {args.law_version} "
              f"({law_grid.variant.status_juridic}) | draft {args.grid_version} "
              f"(VR {draft_grid.variant.valoare_referinta_lei:.0f} lei, {draft_grid.variant.status_juridic})")

        updates = []
        for posting in postings:
            selector, why = build_selector(posting)
            draft = draft_grid.estimate(selector, gradation=0)
            lsel = law_selector(selector, posting, draft_grid)
            law = law_grid.estimate(lsel, gradation=0)
            if lsel.get("tip_unitate"):
                why["tip_unitate"] = "din numele angajatorului"
                hospital_names[lsel["tip_unitate"]].add(posting["employer_name"])

            use_law = law.lei_min is not None
            primary = law if use_law else draft
            payload = assemble(law, draft, lsel, selector)
            payload["selector_provenienta"] = why
            payload["ocupatie"] = posting["canonical"]
            payload["incredere_ocupatie"] = posting["match_confidence"]

            stats[f"baza:{payload['baza']}"] += 1
            stats[primary.incredere] += 1
            annex = selector.get("anexa") or "-"
            ac = by_annex.setdefault(annex, Counter())
            ac["posturi"] += 1
            ac["cu_proiect"] += draft.lei_min is not None
            ac["cu_lege"] += use_law
            ac["numai_proiect"] += (draft.lei_min is not None and not use_law)
            ac["local_interval"] += bool(use_law and primary.motiv.startswith("Consiliul local"))
            if primary.lei_min is not None:
                stats["cu_estimare"] += 1
                if len(samples) < 12:
                    samples.append(
                        f"  {posting['canonical'][:28]:30s} {posting['employer_name'][:30]:32s} "
                        f"{primary.lei_min}-{primary.lei_max} lei ({payload['baza']})")
            updates.append((json.dumps(payload, ensure_ascii=False), posting["id"]))

        if not args.dry_run:
            with conn.cursor() as cur:
                cur.executemany(
                    "UPDATE jobs_jobposting SET salary_estimate = %s::jsonb WHERE id = %s",
                    updates)
            conn.commit()

    print(f"\nestimated {stats['cu_estimare']}/{len(postings)} "
          f"({stats['cu_estimare'] / max(len(postings), 1):.0%} of postings with an occupation)")
    print("  baza: " + ", ".join(f"{k[5:]}={v}" for k, v in sorted(stats.items()) if k.startswith("baza:")))
    print("  confidence: " + ", ".join(
        f"{k}={stats[k]}" for k in ("exact", "interval", "necunoscut") if stats[k]))
    print("  per anexă (draft figure / law figure / draft only / local ranges):")
    for annex, c in sorted(by_annex.items()):
        print(f"    {annex:>3}: postings={c['posturi']:5d} draft={c['cu_proiect']:5d} "
              f"law={c['cu_lege']:5d} draft-only={c['numai_proiect']:4d} local-range={c['local_interval']:4d}")
    print("\n".join(samples))
    if args.hospital_names:
        for cls in ("a.1", "a.4"):
            names = sorted(hospital_names[cls])
            print(f"\n{cls}: {len(names)} employer names; first 20:")
            print("\n".join(f"  {n}" for n in names[:20]))
    if args.dry_run:
        print("\n(dry run — nothing written)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
