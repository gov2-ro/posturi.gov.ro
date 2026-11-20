#!/usr/bin/env python3
"""Estimate gross monthly pay for every posting, under two baselines.

`lege_in_vigoare` is Legea 153/2017 as it applies in 2026 (`lege153-2026`); `proiect`
is the July 2026 draft law. The law in force is the figure at the top level of
`salary_estimate`; the draft figure sits under the key `proiect`. When the law in
force has no row for a posting, the draft figure takes the top level (ADR 0008,
rule 4). A row with no `baza` is an old row and counts as a draft figure.

Step (a2) of rule 4: when the posting matches a group of the asset declarations
(`declaratii.py`, `data/salarii/declaratii-grupuri.csv`), the declared figure takes the top
level (`baza` = `declaratii`). The law figure then sits under `lege_in_vigoare`.

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
from dataclasses import replace
from collections import Counter
from pathlib import Path

import psycopg
from psycopg.rows import dict_row

ROOT = Path(__file__).parent.resolve()
sys.path.insert(0, str(ROOT))

import declaratii  # noqa: E402
import ocupatii  # noqa: E402
import salary_grid  # noqa: E402
import salary_net  # noqa: E402
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
           o.grid_selector, o.provider, o.study_level, o.canonical, o.match_confidence
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
    # Art. 11 covers the whole Anexa VIII ("Administrație") staff of a council employer,
    # also when the draft selector names no level (workers, drivers, asistent social).
    if sel.get("anexa") == "VIII" and council_employer(posting):
        sel["nivel_administrativ"] = "local"
        sel["uat_unitati"] = uat.units_for(posting["employer_name"], posting.get("judet_name"))
    elif sel.get("nivel_administrativ") == "local":
        sel["nivel_administrativ"] = "teritorial"          # fixed annex value, no range
    return sel


def serialisable(selector: dict) -> dict:
    """A copy for JSON: the band may be a set of plausible values."""
    return {k: sorted(v) if isinstance(v, (set, frozenset)) else v for k, v in selector.items()}


#: The annex of an employer, decided on its name and category (SALARY-UNMATCHED). The
#: one place for the rule. Order matters: health, social care, education, culture,
#: then public administration. A name that fits none of them gives "" (not sure: no guess).
_HEALTH_NAME = re.compile(
    r"\b(spital\w*|sanatoriu\w*|policlinic\w*|ambulator\w*|dispensar\w*|ambulanta|"
    r"centrul medical|centrul de sanatate|centrul de diagnostic|directia de sanatate publica)\b")
_SOCIAL_NAME = re.compile(
    r"\b(asistenta sociala|protectia copilului|protectia persoanelor|"
    r"centrul (de|pentru) (ingrijire|asistenta|plasament|zi|recuperare|primire|servicii sociale)|"
    r"camin(ul)? (pentru|de) (persoane|batrani|varstnici))\b")
_EDUCATION_NAME = re.compile(
    r"\b(scoala|scolii|gradinita|liceul|colegiul|universitatea|academia|casa corpului didactic|"
    r"inspectoratul scolar|palatul copiilor|clubul copiilor|clubul sportiv scolar|"
    r"centrul (judetean )?de resurse|centrul scolar)\b")
_CULTURE_NAME = re.compile(
    r"\b(muzeul|muzeu|biblioteca|teatrul|filarmonica|opera|centrul cultural|casa de cultura|"
    r"complexul muzeal|ansamblul)\b")
_ADMIN_CATEGORY = {"Primării", "Consilii județene", "Prefecturi", "Guvern și ministere"}


def employer_annex(posting: dict) -> str:
    """The annex that pays this employer's staff, or "" when the type is not clear.

    II health and social care (OUG 19/2024), I education, III culture, VIII public
    administration (a council employer, a prefecture, a ministry). A military unit,
    a generic "Instituții naționale" with no telling name, or an empty name give "".
    """
    name = salary_grid.norm(posting.get("employer_name") or "")
    if _HEALTH_NAME.search(name) or _SOCIAL_NAME.search(name):
        return "II"
    if _EDUCATION_NAME.search(name):
        return "I"
    if _CULTURE_NAME.search(name):
        return "III"
    if council_employer(posting) or posting.get("employer_category") in _ADMIN_CATEGORY:
        return "VIII"
    return ""


def estimate_in_annex(grid, selector: dict, posting: dict, finalize) -> tuple:
    """Estimate one posting on one grid, choosing the annex when the selector has none.

    `finalize` turns the selector into what the grid reads (identity for the draft,
    `law_selector()` for the law). Returns `(estimate, selector_used, why)`.

    - A selector with an `anexa` does not change, except an `anexa` that the name fill
      (`provider` = `rule-name-lookup`) wrote: the employer's annex replaces it when the
      employer type is clear and the function has rows there.
    - With no `anexa`: a function in one annex only is paid under that annex. A function in
      more than one annex: the employer's annex when the function has rows there; else the range over all the annexes that have it
      (`incredere` = `interval`, with a warning).
    - Anything that would give no figure falls back to the unchanged selector, so no
      posting loses a figure.
    """
    plain = finalize(selector)
    base = grid.estimate(plain, gradation=0)
    stored = selector.get("anexa")
    if stored and posting.get("provider") != "rule-name-lookup":
        return base, plain, {}
    annexes = grid.function_annexes(selector)
    employer = employer_annex(posting)
    if stored:
        if not employer or employer == stored or employer not in annexes:
            return base, plain, {}
    elif not annexes:
        return base, plain, {}

    def in_annex(annex: str):
        sel = finalize(dict(selector, anexa=annex, nume_cu_grad=True))
        return grid.estimate(sel, gradation=0), sel

    if len(annexes) == 1:
        # One annex has the function: it is that annex whoever the employer is.
        only = next(iter(annexes))
        est, sel = in_annex(only)
        if est.lei_min is not None:
            return est, sel, {"anexa": "singura anexă a funcției"}
        return base, plain, {}
    if employer in annexes:
        est, sel = in_annex(employer)
        if est.lei_min is not None:
            return est, sel, {"anexa": "din tipul angajatorului"}
        return base, plain, {}
    found = []
    for annex in sorted(annexes):
        e, sel = in_annex(annex)
        if e.lei_min is not None:
            found.append((annex, e, sel))
    if not found:
        return base, plain, {}
    listed = ", ".join(a for a, _e, _s in found)
    first = found[0][1]
    merged = replace(
        first,
        lei_min=min(e.lei_min for _a, e, _s in found),
        lei_max=max(e.lei_max for _a, e, _s in found),
        coef_min=None, coef_max=None, incredere="interval",
        motiv=f"Funcția există în anexele {listed}; angajatorul nu decide anexa. {first.motiv}",
        randuri=[r for _a, e, _s in found for r in e.randuri][:12],
        avertismente=list(first.avertismente) + [
            f"Funcția există în mai multe anexe ({listed}), iar tipul angajatorului nu a putut fi "
            "determinat; intervalul acoperă toate anexele."],
    )
    return merged, dict(plain, anexa=None), {"anexa": f"interval peste anexele {listed}"}


def assemble(law, draft, law_sel: dict, draft_sel: dict, group: dict | None = None) -> dict:
    """The JSON of one posting (ADR 0008, rule 4): a declared figure, else the law in force.

    The top level keeps the keys of the old format, so `sal_min`, `sal_max` and the
    filter read it unchanged. With a `group` (rule 4, step a2), the declared figure takes the
    top level, with `baza` = `declaratii`; the law figure sits under `lege_in_vigoare`.
    Else the law figure takes it when the law has a row. The draft figure sits under `proiect`.
    When neither the group nor the law has a figure, the draft figure takes the top level,
    with `baza` = `proiect`.
    """
    use_law = law.lei_min is not None
    primary, sel = (law, law_sel) if use_law else (draft, draft_sel)
    if group is not None:
        payload = declaratii.estimate_dict(group)
        if use_law:
            payload["lege_in_vigoare"] = dict(law.as_dict(), baza="lege_in_vigoare")
    else:
        payload = primary.as_dict()
        payload["baza"] = "lege_in_vigoare" if use_law else "proiect"
    payload["selector"] = serialisable({k: v for k, v in sel.items() if k not in ("functie_alt", "nume_cu_grad")})
    if (use_law or group is not None) and draft.lei_min is not None:
        payload["proiect"] = dict(draft.as_dict(), baza="proiect")
    return payload


#: The filter keys of `salary_bucket` (webapp-php/helpers.php, SALARY_NET_BUCKETS): net pay of the lower figure.
_NET_BUCKETS = (("net-sub-3000", None, 3000), ("net-3000-4000", 3000, 4000), ("net-4000-5000", 4000, 5000),
                ("net-5000-7000", 5000, 7000), ("net-peste-7000", 7000, None))


def salary_bucket(lei_min) -> str:
    """The filter value of a lower gross figure, as the site computes it."""
    if not lei_min or lei_min <= 0:
        return "fara-estimare"
    net = salary_net.net_from_gross(lei_min)
    for key, lo, hi in _NET_BUCKETS:
        if (lo is None or net >= lo) and (hi is None or net < hi):
            return key
    return "fara-estimare"


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
    ap.add_argument("--match-samples", type=int, default=0, metavar="N",
                    help="print N random postings that match a declared-pay group (for review)")
    ap.add_argument("--groups", type=Path, default=declaratii.GROUPS_CSV,
                    help="the group table of declared pay (ADR 0008 rule 9)")
    args = ap.parse_args()

    draft_grid = salary_grid.load_grid(args.grid_version)
    law_grid = salary_grid.load_grid(args.law_version)
    stats = Counter()
    by_annex: dict[str, Counter] = {}
    hospital_names: dict[str, set] = {"a.1": set(), "a.4": set()}
    samples: list[str] = []
    groups = declaratii.Groups.load(args.groups)
    matches: list[tuple] = []
    bucket_moves: Counter = Counter()

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
            draft, dsel, why_d = estimate_in_annex(
                draft_grid, selector, posting, lambda s: s)
            law, lsel, why_l = estimate_in_annex(
                law_grid, selector, posting, lambda s: law_selector(s, posting, draft_grid))
            why.update(why_l or why_d)
            if lsel.get("tip_unitate"):
                why["tip_unitate"] = "din numele angajatorului"
                hospital_names[lsel["tip_unitate"]].add(posting["employer_name"])

            use_law = law.lei_min is not None
            primary = law if use_law else draft
            before = assemble(law, draft, lsel, selector)
            inst = groups.employer(posting["employer_name"], posting["judet_name"])
            group = groups.match(posting["employer_name"], posting["judet_name"], posting["title"]) if inst else None
            payload = assemble(law, draft, lsel, selector, group)
            stats["angajator_potrivit"] += bool(inst)
            if group:
                stats["grup_potrivit"] += 1
                stats[f"familie:{group['familie']}"] += 1
                matches.append((posting["title"], posting["employer_name"], group["institutie_id"], group["functie"], group["n"]))
            stats[f"baza_inainte:{before['baza']}"] += 1
            b0, b1 = salary_bucket(before["lei_min"]), salary_bucket(payload["lei_min"])
            if b0 != b1:
                bucket_moves[(b0, b1)] += 1
            primary_min, primary_max, primary_label = payload["lei_min"], payload["lei_max"], payload["baza"]
            payload["selector_provenienta"] = why
            payload["ocupatie"] = posting["canonical"]
            payload["incredere_ocupatie"] = posting["match_confidence"]

            stats[f"baza:{payload['baza']}"] += 1
            stats[primary.incredere] += 1
            annex = (lsel if use_law else dsel).get("anexa") or "-"
            ac = by_annex.setdefault(annex, Counter())
            ac["posturi"] += 1
            ac["cu_proiect"] += draft.lei_min is not None
            ac["cu_lege"] += use_law
            ac["numai_proiect"] += (draft.lei_min is not None and not use_law)
            ac["local_interval"] += bool(use_law and primary.motiv.startswith("Consiliul local"))
            if primary_min is not None:
                stats["cu_estimare"] += 1
                if len(samples) < 12:
                    samples.append(
                        f"  {posting['canonical'][:28]:30s} {posting['employer_name'][:30]:32s} "
                        f"{primary_min}-{primary_max} lei ({primary_label})")
            updates.append((json.dumps(payload, ensure_ascii=False), posting["id"]))

        if not args.dry_run:
            with conn.cursor() as cur:
                cur.executemany(
                    "UPDATE jobs_jobposting SET salary_estimate = %s::jsonb WHERE id = %s",
                    updates)
            conn.commit()

    print(f"\nestimated {stats['cu_estimare']}/{len(postings)} "
          f"({stats['cu_estimare'] / max(len(postings), 1):.0%} of postings with an occupation)")
    print("  baza before the declared step: " + ", ".join(
        f"{k[13:]}={v}" for k, v in sorted(stats.items()) if k.startswith("baza_inainte:")))
    print("  baza after:                    " + ", ".join(
        f"{k[5:]}={v}" for k, v in sorted(stats.items()) if k.startswith("baza:")))
    print(f"  declared pay ({len(groups.rows)} groups): employer match {stats['angajator_potrivit']}/{len(postings)}, "
          f"group match {stats['grup_potrivit']}/{len(postings)}; by family: "
          + (", ".join(f"{k[8:]}={v}" for k, v in sorted(stats.items()) if k.startswith("familie:")) or "-"))
    print(f"  salary_bucket changes: {sum(bucket_moves.values())} postings")
    for (b0, b1), n in bucket_moves.most_common():
        print(f"    {b0} -> {b1}: {n}")
    if args.match_samples and matches:
        import random
        print(f"\n{min(args.match_samples, len(matches))} random matches (title | employer -> institutie_id | functie | n):")
        for m in random.Random(1).sample(matches, min(args.match_samples, len(matches))):
            print(f"  {m[0]} | {m[1]} -> {m[2]} | {m[3]} | {m[4]}")
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
