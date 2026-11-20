#!/usr/bin/env python3
"""Dump the holders' salary lines from the monitorul.ai asset declarations (ANI).

Source: the Kibana of monitorul.ai/integritate (kibana.sicap.ai, space
"integritate", index "integritate"). The script calls the Kibana internal search
endpoint, which a user with the role "integritate" can use. The Dev Tools
console proxy is not available to that role.

The output is one CSV row per income line where the category is "salarii" and
the beneficiary is the holder ("titular"). The script does not choose the line
that is the pay of the declared post; a later step does that. The output has no
names. `an` is the filing year; the income is for the year `an - 1`.

`person` is a scrambled key: SHA-256 of a random salt, the sorted name tokens
and `institutie_id`. The salt is made at the start of each run and is never
written, so a key links one person at one institution inside one dump only.

ops/integritate-dump.js is the browser version, with the same query and the same
CSV. See docs/audits/2026-11-20-salary-declaratii-kibana.md.

Authentication: the owner sets ONE of these environment variables. The script
never prints them.
    KIBANA_API_KEY      an Elasticsearch API key (header "Authorization: ApiKey ...")
    KIBANA_USER + KIBANA_PASSWORD   the Kibana user (HTTP basic)
    KIBANA_COOKIE       the "Cookie" header of a signed-in browser tab (sid=...)

Usage:
    .venv/bin/python ops/integritate-dump.py [--out data/integritate/salarii-raw.csv.gz]
        [--max-pages N] [--after AID,SOURCE]

About 700 pages of 2,000 declarations, about 10 minutes.
"""
from __future__ import annotations

import argparse
import base64
import csv
import gzip
import hashlib
import json
import os
import secrets
import sys
import time
import urllib.error
import urllib.request

BASE = "https://kibana.sicap.ai"
SPACE = "integritate"
INDEX = "integritate"
PAGE = 2000

# Keep in step with COLS in ops/integritate-dump.js.
COLS = [
    "aid", "an", "data_completare_iso", "source", "person", "functie", "institutie_id",
    "institutie", "institutie_norm", "judet_norm", "localitate", "tip_institutie",
    "n_lines", "sursa", "serviciul_prestat", "suma", "valuta",
]

QUERY = {
    "bool": {
        "filter": [
            {"term": {"tip.keyword": "AVERE"}},
            {"term": {"extraction_meta.status.keyword": "ok"}},
            {"term": {"extraction.venituri.categorie.keyword": "salarii"}},
        ]
    }
}

SOURCE = [
    "aid", "an", "data_completare_iso", "source", "functie", "institutie",
    "institutie_id", "institutie_norm", "judet_norm", "localitate", "tip_institutie",
    "extraction.venituri.categorie", "extraction.venituri.sursa",
    "extraction.venituri.serviciul_prestat", "extraction.venituri.beneficiar_tip",
    "extraction.venituri.venit_anual", "nume_norm",
]

SALT = secrets.token_hex(16)


def person_key(src: dict) -> str:
    """Scrambled key of one person at one institution, for this run only."""
    name = " ".join(sorted((src.get("nume_norm") or "").split()))
    if not name:
        return ""
    data = f"{SALT}|{name}|{src.get('institutie_id') or ''}".encode()
    return hashlib.sha256(data).hexdigest()[:16]


def auth_headers() -> dict[str, str]:
    if os.environ.get("KIBANA_API_KEY"):
        return {"Authorization": "ApiKey " + os.environ["KIBANA_API_KEY"]}
    if os.environ.get("KIBANA_USER") and os.environ.get("KIBANA_PASSWORD"):
        token = f'{os.environ["KIBANA_USER"]}:{os.environ["KIBANA_PASSWORD"]}'
        return {"Authorization": "Basic " + base64.b64encode(token.encode()).decode()}
    if os.environ.get("KIBANA_COOKIE"):
        return {"Cookie": os.environ["KIBANA_COOKIE"]}
    sys.exit("Set KIBANA_API_KEY, or KIBANA_USER and KIBANA_PASSWORD, or KIBANA_COOKIE.")


def search(headers: dict[str, str], body: dict) -> dict:
    """POST one query to the Kibana internal search. Retry 5 times."""
    url = f"{BASE}/s/{SPACE}/internal/search/es"
    data = json.dumps({"params": {"index": INDEX, "body": body}}).encode()
    for attempt in range(1, 6):
        req = urllib.request.Request(url, data=data, method="POST", headers={
            **headers,
            "kbn-xsrf": "x",
            "x-elastic-internal-origin": "Kibana",
            "elastic-api-version": "1",
            "Content-Type": "application/json",
        })
        try:
            with urllib.request.urlopen(req, timeout=120) as r:
                return json.load(r)["rawResponse"]
        except urllib.error.HTTPError as e:
            if e.code in (401, 403):
                sys.exit(f"Kibana refused the credentials (HTTP {e.code}).")
            err = f"HTTP {e.code}"
        except (urllib.error.URLError, TimeoutError) as e:
            err = str(e)
        if attempt == 5:
            sys.exit(f"Kibana search failed 5 times: {err}")
        print(f"  retry {attempt}: {err}", file=sys.stderr)
        time.sleep(2 * attempt)
    raise AssertionError("unreachable")


def holder_salary_rows(src: dict) -> list[dict]:
    """One row per "salarii" line whose beneficiary is the holder."""
    lines = [
        v for v in (src.get("extraction") or {}).get("venituri") or []
        if v.get("categorie") == "salarii"
        and (v.get("beneficiar_tip") or "").lower() == "titular"
    ]
    rows = []
    for v in lines:
        va = v.get("venit_anual") or {}
        row = {c: src.get(c) for c in COLS}
        row.update(person=person_key(src), n_lines=len(lines), sursa=v.get("sursa"),
                   serviciul_prestat=v.get("serviciul_prestat"),
                   suma=va.get("suma"), valuta=va.get("valuta"))
        rows.append(row)
    return rows


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--out", default="data/integritate/salarii-raw.csv.gz")
    ap.add_argument("--max-pages", type=int, default=0, help="stop after N pages (0 = all)")
    ap.add_argument("--after", default=None,
                    help="start after this sort key, \"AID,SOURCE\" (from the last progress line)")
    args = ap.parse_args()

    headers = auth_headers()
    os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
    after = None
    if args.after:
        aid, src = args.after.split(",", 1)
        after = [int(aid), src]
    pages = docs = rows = no_line = 0
    total = None
    t0 = time.time()

    with gzip.open(args.out, "wt", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=COLS)
        w.writeheader()
        while True:
            # `aid` is unique only within a portal: sort on (aid, source).
            body = {"size": PAGE, "query": QUERY, "_source": SOURCE,
                    "sort": [{"aid": "asc"}, {"source.keyword": "asc"}]}
            if total is None:
                body["track_total_hits"] = True
            if after:
                body["search_after"] = after
            res = search(headers, body)
            if total is None:
                # The internal search returns the total as an int, not {"value": n}.
                t = res["hits"]["total"]
                total = t["value"] if isinstance(t, dict) else int(t)
                print(f"{total:,} declarations match.", file=sys.stderr)
            hits = res["hits"]["hits"]
            if not hits:
                break
            for h in hits:
                r = holder_salary_rows(h["_source"])
                if not r:
                    no_line += 1
                w.writerows(r)
                rows += len(r)
            docs += len(hits)
            pages += 1
            after = hits[-1]["sort"]
            if pages % 25 == 0:
                print(f"  {docs:,}/{total:,} docs, {rows:,} rows, last key {after[0]},{after[1]}, "
                      f"{time.time() - t0:.0f}s", file=sys.stderr)
            if args.max_pages and pages >= args.max_pages:
                break
            time.sleep(0.15)

    print(f"Done: {docs:,} declarations, {rows:,} rows, {no_line:,} with no holder line, "
          f"last key {','.join(map(str, after)) if after else '-'}. Wrote {args.out}.", file=sys.stderr)


if __name__ == "__main__":
    main()
