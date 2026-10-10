"""Tracked facts of one posting, for durable observation history (UX-12, capture slice).

A saved announcement can change under a reader: the deadline moves, the competition
is withdrawn, a role's requirements are rewritten. Nothing in the pipeline retains
the old values (every column, `schema_json` and the cached HTML are overwritten in
place), so proving "this changed from X to Y" needs a record taken at the time. This
module defines WHAT is recorded; `manage.py observe_postings` writes it and a later
read slice turns pairs of observations into verified change events.

Pure functions only: no Django, no psycopg, no I/O. `export-to-sqlite.py` cannot be
imported here (it needs psycopg and has a hyphen in its name), so the deadline rule
is duplicated and `webapp/tests/test_posting_facts.py` pins it to the export's own
`_apply_deadline` / `_v3_columns` on many cases. If the export's rule changes, that
test fails; change both.

What a facts dict holds (`FACTS_FORMAT = 1`):

  deadline, deadline_source, deadline_time
      the last day anyone can apply, resolved exactly like the export
      (`concurs` > `anunt` > `expirare`). The `concurs` source needs a VALID current
      extraction: a stale extraction describes the previous source revision and must
      not speak for this one. Without it the chain falls through to `anunt` /
      `expirare`, so `deadline_source` is part of the comparison: a move from
      `concurs` to `anunt` is a change of evidence, not a change of deadline.
  cancelled, expires_at
      index-level facts; they do not depend on the extraction.
  requirements_available, roles
      one entry per role from `posting_roles.role_rows` (requirement fields only).
      `requirements_available` is False when there is no valid current extraction;
      `roles` is then None. "Unavailable" is never "no roles" and never "unchanged".
  evidence
      the source sentences the extraction kept (`verbatim`) for the deadline and for
      each role's education / experience. Reference material for display; NOT part
      of the hash.

`facts_hash` identifies "did a tracked fact change" and is deliberately blind to two
things that move without any fact moving: the revision-scoped `role_key` (it embeds
the source revision, so every new revision would look like a change of every role)
and the evidence prose (a model-only correction can rephrase a sentence).
"""

from __future__ import annotations

import hashlib
import json
import re
from typing import Any, Mapping
from zoneinfo import ZoneInfo

import posting_roles

#: Bump when the meaning of a facts key changes; consumers key behaviour on it.
FACTS_FORMAT = 1

#: Everything the site publishes is in Romanian local time; the database stores
#: instants (same constant as `export-to-sqlite.BUCHAREST`).
BUCHAREST = ZoneInfo("Europe/Bucharest")

#: Key `import_csvs.invalidate_enrichment` sets in `JobPosting.inferred` when the
#: source changed under it. Duplicated (the importer is a Django command); a test
#: pins the two together.
STALE_MARKER = "stale_revision"

#: The five outcomes of `classify`.
BASELINE = "baseline"
SOURCE_CHANGE = "source_change"
EXTRACTION_ONLY = "extraction_only"
FACTS_ONLY = "facts_only"
UNCHANGED = "unchanged"
CLASSES = (BASELINE, SOURCE_CHANGE, EXTRACTION_ONLY, FACTS_ONLY, UNCHANGED)

#: Observation columns that together name the extraction that produced the facts.
EXTRACTION_KEYS = (
    "extraction_provider", "extraction_model", "extraction_prompt_version",
    "extraction_source_revision", "extraction_extracted_at",
)

_HHMM = re.compile(r"^\d{2}:\d{2}$")


# ---------------------------------------------------------------------------
# Deadline — duplicated from export-to-sqlite.py (`_local_date`, `_apply_deadline`)
# ---------------------------------------------------------------------------

def local_date(value) -> str | None:
    """The calendar date a timestamp falls on *in Bucharest* (a date stays as is).

    `data_limita_depunere` is stored at local midnight — 21:00 UTC the previous day
    in summer — so `str(value)[:10]` under a UTC session loses a day.
    """
    if value is None:
        return None
    tzinfo = getattr(value, "tzinfo", None)
    if tzinfo is not None:
        return value.astimezone(BUCHAREST).date().isoformat()
    return str(value)[:10]


def apply_deadline(v4_deadline, data_limita_depunere, expires_at) -> dict:
    """`{"date", "source"}` — best source first: `concurs`, `anunt`, `expirare`."""
    if v4_deadline:
        return {"date": str(v4_deadline)[:10], "source": "concurs"}
    if data_limita_depunere:
        return {"date": local_date(data_limita_depunere), "source": "anunt"}
    if expires_at:
        return {"date": local_date(expires_at), "source": "expirare"}
    return {"date": None, "source": ""}


# ---------------------------------------------------------------------------
# Schema helpers (defensive: schema_json is model output, shapes drift)
# ---------------------------------------------------------------------------

def _dict(value: Any) -> dict:
    return value if isinstance(value, dict) else {}


def _list(value: Any) -> list:
    return value if isinstance(value, list) else []


def _text(value: Any) -> str | None:
    """A non-empty stripped string, else None."""
    if isinstance(value, str):
        value = value.strip()
        return value or None
    return None


def _dumps(seq) -> str:
    """Sorted unique JSON array of the truthy values — the `v3_*` array format."""
    return json.dumps(sorted({x for x in seq if x}), ensure_ascii=False)


def posting_level_columns(schema: dict | None) -> dict:
    """The five `v3_*` values `posting_roles.role_rows` copies into an implicit row.

    Mirrors `export-to-sqlite._v3_columns` (empty for no schema or a v2 payload).
    """
    empty = {"eqf_level": None, "isced_fields": "[]", "skills": "[]",
             "credentials": "[]", "seniority_hint": ""}
    if not isinstance(schema, dict) or "education" not in schema:
        return empty
    edu = _dict(schema.get("education"))
    fields = [f for f in _list(edu.get("fields_of_study")) if isinstance(f, dict)]
    return {
        "eqf_level": edu.get("eqf_level"),
        "isced_fields": _dumps(f.get("isced_field") for f in fields),
        "skills": _dumps(_dict(k).get("label") for k in _list(schema.get("skill_list"))),
        "credentials": _dumps(_dict(c).get("kind") for c in _list(schema.get("credentials"))),
        "seniority_hint": schema.get("seniority_hint") or "",
    }


def extraction_is_valid(posting: Mapping, schema: dict | None) -> bool:
    """Is there an extraction (and inference) that can speak for the current source?

    Invalid when there is no usable `schema_json`, when the extraction's recorded
    source revision and the posting's `detail_content_hash` are both known and
    differ (provably made from an older page), or when the importer marked the
    inference stale. An extraction with NO recorded revision is not provably stale
    and counts as valid — the repo's convention (`llm-schema.py --resume`,
    `import_csvs.invalidate_enrichment`): only a confirmed difference is stale.
    The observation still stores `extraction_source_revision` as it is, so a
    consumer wanting the stricter reading can apply it.
    """
    if not isinstance(schema, dict) or not schema:
        return False
    rev = str(posting.get("schema_source_revision") or "").strip().lower()
    src = str(posting.get("detail_content_hash") or "").strip().lower()
    if rev and src and rev != src:
        return False
    if STALE_MARKER in _dict(posting.get("inferred")):
        return False
    return True


# ---------------------------------------------------------------------------
# Roles and evidence
# ---------------------------------------------------------------------------

#: Requirement columns of a role row that are tracked (the rest — seniority_hint,
#: source_revision, role_format — is metadata or a hint, not a requirement).
ROLE_FACT_COLUMNS = (
    "role_key", "ordinal", "decomposed", "title", "vacancy_count", "study_level",
    "eqf_level", "isced_fields", "exp_years", "exp_in_specialty", "exp_none_required",
    "skills", "credentials",
)
_ARRAY_COLUMNS = ("isced_fields", "skills", "credentials")
_TRI_COLUMNS = ("exp_in_specialty", "exp_none_required")


def _array(value: Any) -> list | None:
    """A `v3_*` JSON-array string as a list; None stays unknown."""
    if value is None:
        return None
    if isinstance(value, list):
        return value
    try:
        parsed = json.loads(value)
    except (TypeError, ValueError):
        return None
    return parsed if isinstance(parsed, list) else None


def _role_fact(row: dict) -> dict:
    out = {}
    for col in ROLE_FACT_COLUMNS:
        v = row.get(col)
        if col in _ARRAY_COLUMNS:
            v = _array(v)
        elif col in _TRI_COLUMNS:
            v = None if v is None else bool(v)
        elif col == "decomposed":
            v = bool(v)
        out[col] = v
    return out


def _role_evidence(row: dict, schema: dict) -> dict:
    """The education / experience sentences behind one role row."""
    if row.get("decomposed"):
        entries = _list(schema.get("positions"))
        idx = row["ordinal"] - 1                 # ordinal = array position + 1
        entry = _dict(entries[idx]) if 0 <= idx < len(entries) else {}
        edu = _text(_dict(entry.get("education")).get("verbatim"))
        exp = _text(_dict(entry.get("experience")).get("verbatim"))
    else:
        edu = (_text(_dict(schema.get("education")).get("verbatim"))
               or _text(schema.get("educationRequirements")))        # v2 payloads
        exp = (_text(_dict(schema.get("experience")).get("verbatim"))
               or _text(schema.get("experienceRequirements")))
    return {"ordinal": row["ordinal"], "education": edu, "experience": exp}


def _calendar(schema: dict | None) -> dict:
    return _dict(_dict(schema).get("competition_calendar"))


def _deadline_evidence(calendar: dict, deadline: dict) -> str | None:
    """The calendar row the v4 deadline came from, when it can be found."""
    if deadline["source"] != "concurs":
        return None
    for event in _list(calendar.get("events")):
        event = _dict(event)
        if event.get("stage") == "depunere_dosare" and event.get("date") == deadline["date"]:
            return _text(event.get("verbatim"))
    return None


# ---------------------------------------------------------------------------
# Public API
# ---------------------------------------------------------------------------

def tracked_facts(posting: Mapping, schema: dict | None) -> dict:
    """The normalized, JSON-serializable facts one observation records.

    `posting` carries `data_limita_depunere`, `expires_at`, `cancelled`,
    `detail_content_hash`, `schema_source_revision` and `inferred` (a dict; its
    `studies_required` / `experience_years` feed an undecomposed posting's single
    implicit role exactly as the export does). `schema` is the parsed `schema_json`.
    """
    if not isinstance(schema, dict):
        schema = None
    valid = extraction_is_valid(posting, schema)
    calendar = _calendar(schema) if valid else {}

    deadline = apply_deadline(calendar.get("application_deadline"),
                              posting.get("data_limita_depunere"), posting.get("expires_at"))
    deadline_time = None
    if deadline["source"] == "concurs":
        t = calendar.get("application_deadline_time")
        deadline_time = t.strip() if isinstance(t, str) and _HHMM.match(t.strip()) else None

    roles = None
    role_evidence = None
    if valid:
        inferred = _dict(posting.get("inferred"))
        v3 = posting_level_columns(schema)
        rows = posting_roles.role_rows({
            "schema_source_revision": posting.get("schema_source_revision"),
            "inf_studies_required": inferred.get("studies_required"),
            "inf_experience_years": inferred.get("experience_years"),
            "v3_eqf_level": v3["eqf_level"],
            "v3_isced_fields": v3["isced_fields"],
            "v3_skills": v3["skills"],
            "v3_credentials": v3["credentials"],
            "v3_seniority_hint": v3["seniority_hint"],
        }, schema)
        rows = sorted(rows, key=lambda r: r["ordinal"])
        roles = [_role_fact(r) for r in rows]
        role_evidence = [_role_evidence(r, schema) for r in rows]

    return {
        "deadline": deadline["date"],
        "deadline_source": deadline["source"],
        "deadline_time": deadline_time,
        "cancelled": bool(posting.get("cancelled")),
        "expires_at": local_date(posting.get("expires_at")),
        "requirements_available": valid,
        "roles": roles,
        "evidence": {"deadline": _deadline_evidence(calendar, deadline) if valid else None,
                     "roles": role_evidence},
    }


def hash_view(facts: Mapping) -> dict:
    """What `facts_hash` hashes: the facts without evidence prose and role keys."""
    view = {k: v for k, v in facts.items() if k != "evidence"}
    if isinstance(view.get("roles"), list):
        view["roles"] = [{k: v for k, v in r.items() if k != "role_key"} for r in view["roles"]]
    return view


def canonical_json(value: Any) -> str:
    return json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":"))


def facts_hash(facts: Mapping) -> str:
    """sha256 hex of the canonical JSON of `hash_view(facts)`."""
    return hashlib.sha256(canonical_json(hash_view(facts)).encode("utf-8")).hexdigest()


def extraction_revision(obs: Mapping) -> tuple:
    """The extraction that produced an observation's facts, as a comparable tuple.

    Distinct from `source_revision`: re-running a model over unchanged source bytes
    changes this and not that, which is what separates a model-only correction from
    a source change.
    """
    return tuple(obs.get(k) or "" for k in EXTRACTION_KEYS)


def classify(prev: Mapping | None, cur: Mapping) -> str:
    """How `cur` relates to the latest stored observation `prev` of the same URL.

    baseline         no earlier observation. Establishing it is NOT a change.
    source_change    the source revision (`detail_content_hash`) differs.
    extraction_only  same source revision, different extraction revision — a
                     model-only correction (new model, prompt version or re-run).
    facts_only       same source and extraction, different tracked facts — an
                     index-level change (cancellation, expiry) with no new source.
    unchanged        nothing to record.

    Both mappings carry `source_revision`, the `EXTRACTION_KEYS` and `facts_hash`.
    A source revision that goes from unknown ('') to known is reported as
    `source_change` here; whether that is a confirmed change (both ends known)
    is for the event derivation to decide.
    """
    if prev is None:
        return BASELINE
    if (prev.get("source_revision") or "") != (cur.get("source_revision") or ""):
        return SOURCE_CHANGE
    if extraction_revision(prev) != extraction_revision(cur):
        return EXTRACTION_ONLY
    if prev.get("facts_hash") != cur.get("facts_hash"):
        return FACTS_ONLY
    return UNCHANGED
