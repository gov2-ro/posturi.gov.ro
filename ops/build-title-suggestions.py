#!/usr/bin/env python3
"""Build the title suggestions for the employer posting assistant (UX-EMP-04).

Source: Postgres (`DATABASE_URL`, default postgres://localhost/posturi_dev), tables jobs_occupation,
jobs_jobposting and jobs_employer, as for normalize-titles.py.
Output: webapp-php/static/ocupatii.json.

    python ops/build-title-suggestions.py

Rules:
- One entry in `occupations` for each distinct `canonical`, sorted by posting count, descending.
  When a canonical has several COR codes, the entry takes the most frequent one (by postings).
  The code must cover at least 50% of the postings that have a code, and have at least 3 postings or be the
  only code of the canonical; else ''.
  Empty codes do not count: 1,738 of 3,767 normalisations have none. The `id` is the index in the sorted list.
- `kind_labels`: `classify.EMPLOYER_KIND_LABELS` for the kinds in `by_kind`.
- `grades`: the `ocupatii.parse_title(title).grade_token` values that occur in at least 2 postings of the
  occupation, most frequent first. The empty token is left out.
- `by_kind`: for each `classify.employer_kind(employer name)`, the 15 most frequent occupations with counts.
  The key "*" holds the top 15 over all postings. The key "" holds the unclassified employers.
- Rebuilding is the refresh. Commit the generated file.
"""
import json
import os
import sys
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

import psycopg

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import classify  # noqa: E402
import ocupatii  # noqa: E402

DATABASE_URL = os.environ.get("DATABASE_URL", "postgres://localhost/posturi_dev")
OUT = ROOT / "webapp-php" / "static" / "ocupatii.json"
TOP = 15
MIN_GRADE_POSTINGS = 2
MIN_COR_POSTINGS = 3


def main():
    with psycopg.connect(DATABASE_URL) as conn, conn.cursor() as cur:
        cur.execute("select id, canonical, cor_code from jobs_occupation order by id")
        occ_rows = cur.fetchall()
        cur.execute(
            "select p.occupation_id, p.title, e.name, p.published_at "
            "from jobs_jobposting p left join jobs_employer e on e.id = p.employer_id "
            "where p.occupation_id is not null"
        )
        postings = cur.fetchall()

    canon_of = {oid: canonical for oid, canonical, _cor in occ_rows}
    cor_of = {oid: cor for oid, _canonical, cor in occ_rows}

    cor_counts = defaultdict(Counter)    # canonical -> Counter(cor_code)
    grade_counts = defaultdict(Counter)  # canonical -> Counter(grade token)
    kind_counts = defaultdict(Counter)   # kind -> Counter(canonical)
    total = Counter()
    latest = None
    for oid, title, emp_name, published in postings:
        canonical = canon_of.get(oid)
        if canonical is None:
            continue
        total[canonical] += 1
        if cor_of[oid]:   # normalize-titles.py stores '' when the model found no COR code
            cor_counts[canonical][cor_of[oid]] += 1
        grade = ocupatii.parse_title(title or "").grade_token
        if grade:
            grade_counts[canonical][grade] += 1
        kind = classify.employer_kind(emp_name or "")
        kind_counts[kind][canonical] += 1
        kind_counts["*"][canonical] += 1
        if published and (latest is None or published > latest):
            latest = published

    # Every canonical is listed, also one with no posting: the search covers all of them.
    canonicals = sorted(set(canon_of.values()), key=lambda c: (-total[c], c))
    index = {c: i for i, c in enumerate(canonicals)}

    occupations = []
    for c in canonicals:
        # Most frequent non-empty COR code; a tie goes to the smaller code, so the build is stable.
        # '' when no normalisation of this canonical found a code (the builder then writes no `occupation` block).
        # It counts only when it covers at least half of the postings that have a code, and has 3 postings or is the only code:
        # a wrong code in the payload is worse than none (a generic title maps to several codes).
        cor = ""
        if cor_counts[c]:
            top, n_top = sorted(cor_counts[c].items(), key=lambda kv: (-kv[1], kv[0]))[0]
            if n_top * 2 >= sum(cor_counts[c].values()) and (n_top >= MIN_COR_POSTINGS or len(cor_counts[c]) == 1):
                cor = top
        grades = [g for g, n in sorted(grade_counts[c].items(), key=lambda kv: (-kv[1], kv[0])) if n >= MIN_GRADE_POSTINGS]
        occupations.append({"id": index[c], "label": c, "cor": cor, "n": total[c], "grades": grades})

    by_kind = {}
    for kind in sorted(kind_counts):
        top = sorted(kind_counts[kind].items(), key=lambda kv: (-kv[1], kv[0]))[:TOP]
        by_kind[kind] = [[index[c], n] for c, n in top]

    when = latest.isoformat()[:10] if latest else ""
    doc = {
        "built_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "source": f"posturi_dev, postings to {when}",
        "kind_labels": {k: classify.EMPLOYER_KIND_LABELS[k] for k in by_kind if k in classify.EMPLOYER_KIND_LABELS},
        "occupations": occupations,
        "by_kind": by_kind,
    }
    text = json.dumps(doc, ensure_ascii=False, separators=(",", ":"))
    OUT.write_text(text, encoding="utf-8")
    print(f"postings with an occupation: {len(postings)}; distinct occupations: {len(occupations)}")
    print("postings by kind: " + ", ".join(f"{k or '(none)'}={sum(kind_counts[k].values())}" for k in by_kind))
    print(f"occupations without a COR code: {sum(1 for o in occupations if not o['cor'])}")
    print(f"occupations with grades: {sum(1 for o in occupations if o['grades'])}")
    print(f"file: {OUT} ({len(text.encode('utf-8'))} bytes)")


if __name__ == "__main__":
    main()
