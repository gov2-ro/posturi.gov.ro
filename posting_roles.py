"""Role-scoped read model for one posting (DATA-04, first slice).

One posting can advertise several roles with different requirements ("1 post de
expert IT senior; 2 posturi de expert administrație publică I"). The extraction
keeps them in `schema_json.positions[]`; every flat `v3_*` search column
describes the *posting* as a whole, so a search for "university + no experience"
could match a posting whose university role needs five years and whose
no-experience role needs only secondary school. This module turns one posting
into the rows of the `posting_roles` table so the search can require that the
SAME role satisfies every role-scoped condition.

Pure functions only: no Django, no psycopg, no I/O. `export-to-sqlite.py` calls
it today; a later management command that records history reuses it unchanged.

Rules (docs/specs/2026-10-10-follow-ups/02-role-requirements.md):

* A posting with one or more `positions[]` entries yields one *decomposed* row
  per entry (ordinal 1..N, array position). Values come from that entry only.
  A fact the entry does not state stays None = unknown. The posting-level
  education/skills/credentials are never copied into a role: sharing a
  requirement across roles needs the source to say so, and the extraction does
  not record that.
* Every other posting (no positions, a v2 payload, no schema at all) yields
  exactly ONE implicit row, ordinal 0, `decomposed = 0`, copying the posting's
  existing search columns. Nothing is fabricated: for these postings a search
  behaves exactly as it did before the table existed, and the row is "the
  posting", not "a role".
* `role_key` is revision-scoped, `"<basis>:<rev12>:<ordinal>"`. Array position is
  not an identity: the same extraction re-run can reorder roles, and two roles
  can share a title. Keys are therefore NOT stable across source revisions and no
  mapping between revisions is attempted — it is unresolved by design until
  evidence of continuity exists (a source-provided role id, say). Never use a key
  to carry progress from one revision to another.
"""

from __future__ import annotations

import hashlib
import json
from typing import Any, Iterable

#: Bump when the meaning of a column changes; consumers key behaviour on it.
ROLE_FORMAT = 1

#: Columns of one role dict, in `posting_roles` order (after `posting_id`).
ROLE_COLUMNS = (
    "ordinal", "role_key", "decomposed", "title", "vacancy_count", "study_level",
    "eqf_level", "isced_fields", "exp_years", "exp_in_specialty", "exp_none_required",
    "skills", "credentials", "seniority_hint", "source_revision", "role_format",
)


def _dumps(seq: Iterable[Any]) -> str:
    """Sorted unique JSON array of the truthy values — the `v3_*` array format."""
    return json.dumps(sorted({x for x in seq if x}), ensure_ascii=False)


def _tri(value: Any) -> int | None:
    """Tri-state boolean for SQLite: None stays unknown, never False."""
    if value is None:
        return None
    return 1 if value else 0


def _dict(value: Any) -> dict:
    return value if isinstance(value, dict) else {}


def _list(value: Any) -> list:
    return value if isinstance(value, list) else []


def _int(value: Any) -> int | None:
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, float) and value.is_integer():
        return int(value)
    return None


def _number(value: Any) -> float | None:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        return None
    return float(value)


def revision_basis(posting: dict, schema: dict | None) -> tuple[str, str]:
    """`(basis, rev12)` that scope a posting's role keys to one source revision.

    `src`  the first 12 characters of `schema_source_revision` — the page hash
           the extraction ran against (llm-schema.py). Preferred: it changes
           exactly when the source changed.
    `pay`  no recorded revision (legacy rows): the first 12 hex of the sha256 of
           the canonical payload. Any change to the extraction, including a role
           reorder, changes it.
    `none` neither exists: the posting has no extraction to be a revision of.
    """
    rev = str(posting.get("schema_source_revision") or "").strip().lower()
    if rev:
        return "src", rev[:12]
    if schema is not None:
        canonical = json.dumps(schema, sort_keys=True, ensure_ascii=False,
                               separators=(",", ":"))
        return "pay", hashlib.sha256(canonical.encode("utf-8")).hexdigest()[:12]
    return "none", ""


def role_key(basis: str, rev12: str, ordinal: int) -> str:
    """`"<basis>:<rev12>:<ordinal>"`; an empty revision is spelled `-`."""
    return f"{basis}:{rev12 or '-'}:{ordinal}"


def _decomposed_row(entry: dict, ordinal: int, key: str, source_revision: str | None) -> dict:
    """One role from one `positions[]` entry. Missing facts stay None."""
    edu = entry.get("education")
    exp = entry.get("experience")
    edu_d = _dict(edu)
    exp_d = _dict(exp)

    # Education: a dict means "the extraction looked and wrote a block", so an
    # empty field list is a known "any field qualifies" ("[]"). No block at all
    # is unknown (None), and so are the scalars inside it that are not set.
    if isinstance(edu, dict):
        fields = [f for f in _list(edu_d.get("fields_of_study")) if isinstance(f, dict)]
        isced = _dumps(f.get("isced_field") for f in fields)
    else:
        isced = None

    years = _number(exp_d.get("years_minimum"))
    if years is None and exp_d.get("none_required") is True:
        years = 0.0   # "fără experiență" stated explicitly — a fact, not a guess

    if isinstance(entry.get("skill_list"), list):
        skills = _dumps(_dict(k).get("label") for k in entry["skill_list"])
    else:
        skills = None
    if isinstance(entry.get("credentials"), list):
        credentials = _dumps(_dict(c).get("kind") for c in entry["credentials"])
    else:
        credentials = None

    title = entry.get("title")
    title = title.strip() or None if isinstance(title, str) else None
    count = _int(entry.get("count"))
    return {
        "ordinal": ordinal,
        "role_key": key,
        "decomposed": 1,
        "title": title,
        "vacancy_count": count if count is not None and count >= 1 else None,
        "study_level": edu_d.get("minimum_level") or None,
        "eqf_level": _int(edu_d.get("eqf_level")),
        "isced_fields": isced,
        "exp_years": years,
        "exp_in_specialty": _tri(exp_d.get("in_specialty")) if exp_d else None,
        "exp_none_required": _tri(exp_d.get("none_required")) if exp_d else None,
        "skills": skills,
        "credentials": credentials,
        "seniority_hint": entry.get("seniority_hint") or None,
        "source_revision": source_revision,
        "role_format": ROLE_FORMAT,
    }


def _implicit_row(posting: dict, schema: dict | None, key: str,
                  source_revision: str | None) -> dict:
    """The posting itself, as one row: today's flat search columns, unchanged.

    `study_level`, `eqf_level`, the three arrays and `exp_years` are the very
    values the posting-level filters read, so a search over an undecomposed
    posting cannot change. `exp_in_specialty` / `exp_none_required` come from the
    posting-level `experience` block when the extraction wrote one (they describe
    the posting, like everything else on this row).
    """
    exp_d = _dict(_dict(schema).get("experience"))
    return {
        "ordinal": 0,
        "role_key": key,
        "decomposed": 0,
        "title": None,
        "vacancy_count": None,
        "study_level": posting.get("inf_studies_required") or None,
        "eqf_level": posting.get("v3_eqf_level"),
        "isced_fields": posting.get("v3_isced_fields") or "[]",
        "exp_years": posting.get("inf_experience_years"),
        "exp_in_specialty": _tri(exp_d.get("in_specialty")) if exp_d else None,
        "exp_none_required": _tri(exp_d.get("none_required")) if exp_d else None,
        "skills": posting.get("v3_skills") or "[]",
        "credentials": posting.get("v3_credentials") or "[]",
        "seniority_hint": posting.get("v3_seniority_hint") or None,
        "source_revision": source_revision,
        "role_format": ROLE_FORMAT,
    }


def role_rows(posting: dict, schema: dict | None) -> list[dict]:
    """The `posting_roles` rows (without `posting_id`) for one posting.

    `posting` carries the posting-level columns the implicit row copies
    (`inf_studies_required`, `v3_eqf_level`, `v3_isced_fields`,
    `inf_experience_years`, `v3_skills`, `v3_credentials`, `v3_seniority_hint`)
    and the provenance (`schema_source_revision`). `schema` is the parsed
    `schema_json`, or None. Always returns at least one row.
    """
    if not isinstance(schema, dict):
        schema = None
    basis, rev12 = revision_basis(posting, schema)
    source_revision = str(posting.get("schema_source_revision") or "").strip() or None

    entries = _list((schema or {}).get("positions"))
    rows = [
        _decomposed_row(entry, i, role_key(basis, rev12, i), source_revision)
        for i, entry in enumerate(entries, start=1)
        if isinstance(entry, dict)
    ]
    if rows:
        return rows
    return [_implicit_row(posting, schema, role_key(basis, rev12, 0), source_revision)]
