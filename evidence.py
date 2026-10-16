"""Source evidence for extracted requirements (UX-13, first slice).

The v3 extraction keeps the sentence a requirement came from: `education.verbatim`,
`experience.verbatim` and `skill_list[].evidence`, at posting level and inside each
`positions[]` entry. That sentence is the model's output, not the source: it can be
paraphrased, truncated, or invented. This module decides, with no LLM call, whether
a quote can be *located* in the posting text the export holds, and says where.

Pure functions only: no Django, no psycopg, no I/O. `export-to-sqlite.py` calls
`evidence_rows()` to fill the `requirement_evidence` table; the PHP detail page
renders it. Contract and limits: docs/specs/2026-10-10-follow-ups/03-requirement-evidence.md.

Normalization rule (`NORMALIZATION = "fold-alnum-v1"`)
------------------------------------------------------
Both the quote and the source are folded the same way, then compared as plain
substrings on whole words: NFKD, combining marks dropped (so `ş ș ţ ț ă â î` equal
`s s t t a a i`), lowercased, everything that is not `[0-9a-z]` is a separator, runs
of separators collapse to one space. Whitespace, markdown (`**`, `#`, `-`, `|`),
punctuation, hyphenation style and case therefore never decide the result. This is
exactly `grounding.normalise()`, reused on purpose so there is one definition.

What a match is, and is not
---------------------------
* `verified` = the quote's folded text, split on ellipses, occurs in ONE part of the
  source with its segments in order and on word boundaries. It is a real quotation up
  to the normalization above, which is much stricter than grounding.py's lenient
  word-coverage screen (that one only flags hallucinations).
* `unverified` = the quote exists but could not be located (or is too short for a
  match to mean anything). It is shown, labelled, as unverified model output.
* `absent` = there is no quote to check. That says nothing about whether the
  requirement exists in the source.
* No page number is ever produced: the stored attachment text is the concatenation of
  every attachment with page breaks discarded. For the announcement text a nearest
  markdown heading is reported, because the markdown keeps its headings.
"""

from __future__ import annotations

import bisect
import hashlib
import re
import unicodedata
from dataclasses import dataclass, field
from typing import Any, Iterable

import grounding

#: Names the folding rule. Stored on every row so a later rule change is visible.
NORMALIZATION = "fold-alnum-v1"

#: Shorter than this (after folding) a quote matches by accident, so it cannot verify.
MIN_CHARS = 12
MIN_WORDS = 3

#: Stored quote and section are display text: bounded so a rogue extraction cannot bloat the file.
QUOTE_MAX = 600
SECTION_MAX = 120

PART_ANUNT = "anunt"      # the announcement page (body_markdown)
PART_ANEXE = "anexe"      # all attachments, concatenated (attachment_text)

VERIFIED, UNVERIFIED, ABSENT = "verified", "unverified", "absent"
TOO_SHORT, NOT_FOUND = "too_short", "not_found"
NO_EVIDENCE_FIELD, STALE_REVISION, NO_SOURCE_TEXT = "no_evidence_field", "stale_revision", "no_source_text"

FACT_KINDS = ("education", "experience", "skill", "credential")

#: Columns of one `requirement_evidence` row, in table order.
ROW_COLUMNS = (
    "posting_id", "role_key", "ordinal", "fact_kind", "fact_index", "fact_label", "basis",
    "quote", "status", "reason", "part", "section", "occurrences",
    "source_revision", "extraction_revision", "text_hash", "normalization",
)


# ---- normalisation -------------------------------------------------------------------------

def fold(text: str) -> str:
    """The folded form used for every comparison — `grounding.normalise`, by definition."""
    return grounding.normalise(text)


_FOLD_CHAR_CACHE: dict[str, str] = {}


def _fold_char(ch: str) -> str:
    """One raw character's contribution: its `[0-9a-z]` letters, `" "` for a separator.

    A combining mark contributes nothing and, unlike a separator, does not end a word —
    that is how grounding.normalise() treats it (marks are dropped before words are cut).
    """
    got = _FOLD_CHAR_CACHE.get(ch)
    if got is None:
        out = []
        for x in unicodedata.normalize("NFKD", ch.lower()):
            if unicodedata.combining(x):
                continue
            out.append(x if ("0" <= x <= "9" or "a" <= x <= "z") else " ")
        got = "".join(out)
        if len(_FOLD_CHAR_CACHE) < 50_000:
            _FOLD_CHAR_CACHE[ch] = got
    return got


def fold_with_map(text: str) -> tuple[str, list[int]]:
    """`fold(text)` plus, for every folded character, the raw index it came from.

    Slower than `fold` (a Python loop), so it is only used to map a match back to the
    raw announcement text for the section lookup. The tests assert that the string it
    returns equals `grounding.normalise` on varied input.
    """
    out: list[str] = []
    offsets: list[int] = []
    pending_space = False
    for i, ch in enumerate(text):
        for x in _fold_char(ch):
            if x == " ":
                pending_space = True
                continue
            if pending_space and out:
                out.append(" ")
                offsets.append(i)
            pending_space = False
            out.append(x)
            offsets.append(i)
    return "".join(out), offsets


# ---- source ----------------------------------------------------------------------------------

def source_hash(parts: Iterable[tuple[str, str | None]]) -> str:
    """sha256 of the exact raw texts, in order, joined with NUL.

    This identifies the text revision a verification ran against: change one byte of
    the announcement or of any attachment and the hash changes.
    """
    joined = "\x00".join(text or "" for _, text in parts)
    return hashlib.sha256(joined.encode("utf-8", "surrogatepass")).hexdigest()


@dataclass(frozen=True)
class _Part:
    part_id: str
    text: str          # raw
    padded: str        # " " + fold(text) + " ", so whole-word search is a plain find()
    # Filled on first use and then shared by every quote of the posting: the raw-offset map is
    # a per-character Python loop, far too slow to rebuild for each of a posting's quotes.
    _cache: dict = field(default_factory=dict, repr=False, compare=False)


@dataclass(frozen=True)
class Source:
    """The texts a quote may be found in, folded once (a posting has many quotes)."""

    parts: tuple[_Part, ...]
    text_hash: str

    @classmethod
    def build(cls, parts: Iterable[tuple[str, str | None]]) -> "Source":
        parts = [(pid, text or "") for pid, text in parts]
        return cls(
            parts=tuple(_Part(pid, text, " " + fold(text) + " ") for pid, text in parts),
            text_hash=source_hash(parts),
        )

    @property
    def has_text(self) -> bool:
        return any(p.padded.strip() for p in self.parts)


# ---- the announcement's section ------------------------------------------------------------

_ATX_HEADING = re.compile(r"^[ \t]{0,3}#{1,6}[ \t]+(.+?)[ \t]*#*[ \t]*$", re.MULTILINE)
_BOLD_LINE = re.compile(r"^[ \t]*(?:\*\*|__)(.+?)(?:\*\*|__)[ \t]*:?[ \t]*$", re.MULTILINE)
_MD_NOISE = re.compile(r"[*_#`]+")


def _clean_heading(raw: str) -> str:
    text = " ".join(_MD_NOISE.sub(" ", raw).split())
    return text[:SECTION_MAX].strip()


def headings(text: str) -> list[tuple[int, str]]:
    """`(raw offset of the line, heading text)` for every markdown heading, in order.

    A heading is a line starting with `#`, or a line that is nothing but bold text
    (`**Condiții de participare**`), which is how the page's markdown spells most of
    its section titles.
    """
    found: list[tuple[int, str]] = [
        (m.start(), _clean_heading(m.group(1))) for m in _ATX_HEADING.finditer(text)
    ]
    for m in _BOLD_LINE.finditer(text):
        inner = m.group(1)
        if "**" in inner or "__" in inner:      # `**a** and **b**` is a sentence, not a title
            continue
        found.append((m.start(), _clean_heading(inner)))
    return sorted((off, title) for off, title in found if title)


def _anchors(part: "_Part") -> tuple[list[tuple[int, str]], list[int]] | None:
    """`(headings, folded-index -> raw-offset map)` of a part, or None when it has no headings
    or the two folds disagree (say nothing rather than guess). Computed once per part."""
    if "anchors" not in part._cache:
        heads = headings(part.text)
        value = None
        if heads:
            mapped, offsets = fold_with_map(part.text)
            if mapped == part.padded[1:-1]:
                value = (heads, offsets)
        part._cache["anchors"] = value
    return part._cache["anchors"]


def _section_for(part: "_Part", norm_starts: list[int]) -> str | None:
    """The heading above every one of the matches, or None when they disagree/none exist.

    Duplicates make the first occurrence ambiguous: if the same sentence sits under
    two different headings, naming either would be a guess, so name none.
    """
    anchors = _anchors(part)
    if anchors is None:
        return None
    heads, offsets = anchors
    offs = [h[0] for h in heads]
    titles = set()
    for ns in norm_starts:
        k = bisect.bisect_right(offs, offsets[ns]) - 1
        titles.add(heads[k][1] if k >= 0 else None)
    return titles.pop() if len(titles) == 1 else None


# ---- locating one quote ----------------------------------------------------------------------

_ELLIPSIS = re.compile(r"\s*(?:…|\.{3,})\s*")


@dataclass(frozen=True)
class Located:
    status: str                       # verified | unverified | absent
    reason: str | None = None         # too_short | not_found | no_source_text
    part: str | None = None           # anunt | anexe, verified only
    section: str | None = None        # nearest heading, announcement text only
    occurrences: int | None = None    # how many times the whole quote occurs in that part


def quote_segments(quote: str) -> list[str]:
    """The folded pieces of a quote, split on ellipses; empty pieces dropped."""
    return [s for s in (fold(piece) for piece in _ELLIPSIS.split(quote)) if s]


def _too_short(segment: str) -> bool:
    return len(segment) < MIN_CHARS or len(segment.split()) < MIN_WORDS


def _chain_starts(padded: str, segments: list[str]) -> list[int]:
    """Folded-text indexes where the whole quote occurs, segments in order, non-overlapping.

    `padded` is `" " + folded + " "`, so searching `" seg "` matches whole words only.
    Consecutive matches may share the single separating space.
    """
    first = " " + segments[0] + " "
    starts: list[int] = []
    pos = 0
    while True:
        i = padded.find(first, pos)
        if i < 0:
            return starts
        cur = i + len(first) - 1                      # the space after the first segment
        ok = True
        for seg in segments[1:]:
            j = padded.find(" " + seg + " ", cur)
            if j < 0:
                ok = False
                break
            cur = j + len(seg) + 1
        if not ok:
            # A later start only leaves less text to search, so it cannot succeed either.
            return starts
        starts.append(i)                              # == folded index of the first character
        pos = i + len(first) - 1


def locate(quote: Any, source: "Source | Iterable[tuple[str, str | None]]") -> Located:
    """Where (if anywhere) `quote` occurs in the source.

    `source` is a `Source` or the ordered `(part_id, raw_text)` pairs to build one from,
    e.g. `[("anunt", body_markdown), ("anexe", attachment_text)]`. Every segment of the
    quote must occur in the SAME part, in order. The first part that contains it wins.
    """
    if not isinstance(quote, str) or not quote.strip():
        return Located(ABSENT)
    if not isinstance(source, Source):
        source = Source.build(source)
    segments = quote_segments(quote)
    if not segments or any(_too_short(s) for s in segments):
        return Located(UNVERIFIED, TOO_SHORT)
    if not source.has_text:
        return Located(UNVERIFIED, NO_SOURCE_TEXT)
    for part in source.parts:
        starts = _chain_starts(part.padded, segments)
        if not starts:
            continue
        section = None
        if part.part_id == PART_ANUNT:
            section = _section_for(part, starts)
        return Located(VERIFIED, None, part.part_id, section, len(starts))
    return Located(UNVERIFIED, NOT_FOUND)


# ---- rows for the export ---------------------------------------------------------------------

def _clip(quote: str) -> str:
    return quote if len(quote) <= QUOTE_MAX else quote[:QUOTE_MAX - 1].rstrip() + "…"


def _years_label(years: float) -> str:
    if 0 < years < 1:
        months = max(1, round(years * 12))
        return "1 lună" if months == 1 else f"{months} luni"
    if years == int(years):
        n = int(years)
        return "1 an" if n == 1 else (f"{n} ani" if n < 20 else f"{n} de ani")
    return f"{years:.1f}".rstrip("0").rstrip(".").replace(".", ",") + " ani"


def _dict(value: Any) -> dict:
    return value if isinstance(value, dict) else {}


def _education_fact(edu: dict) -> str | None:
    """A short label for the education fact, or None when the block states nothing."""
    level = edu.get("minimum_level")
    fields = [f for f in (edu.get("fields_of_study") or []) if isinstance(f, dict)]
    if level:
        return str(level)
    if fields and fields[0].get("label_ro"):
        return str(fields[0]["label_ro"])
    if edu.get("eqf_level") is not None or _has_text(edu.get("verbatim")):
        return "studii"
    return None


def _experience_fact(exp: dict) -> str | None:
    years = exp.get("years_minimum")
    years = float(years) if isinstance(years, (int, float)) and not isinstance(years, bool) else None
    if years is not None and years > 0:
        return _years_label(years) + (" în specialitate" if exp.get("in_specialty") is True else "")
    if exp.get("none_required") is True or years == 0:
        return "fără experiență"
    if _has_text(exp.get("verbatim")):
        return "experiență"
    return None


def _has_text(value: Any) -> bool:
    return isinstance(value, str) and bool(value.strip())


def _containers(schema: dict | None, role: dict) -> dict | None:
    """The dict holding this role's facts: its positions[] entry, or the flat payload.

    A decomposed role reads ONLY its own entry — nothing is inherited from the posting
    (the extraction does not record that a requirement applies to every role).
    """
    if not isinstance(schema, dict):
        return None
    if role.get("decomposed"):
        entries = schema.get("positions")
        i = int(role["ordinal"]) - 1
        if isinstance(entries, list) and 0 <= i < len(entries) and isinstance(entries[i], dict):
            return entries[i]
        return None
    return schema


def evidence_rows(posting: dict, schema: dict | None, roles: list[dict],
                  body: str | None, attachments: str | None) -> list[dict]:
    """The `requirement_evidence` rows of one posting (columns: `ROW_COLUMNS`).

    `posting` needs `id`, `detail_content_hash` (the revision of the page now held)
    and `schema_source_revision` (the revision the extraction ran against); `roles` is
    `posting_roles.role_rows(...)`. One row per education/experience block (when it
    states a fact), per skill and per credential, per role.

    Every fact is a model extraction (`basis = "extracted"`); this slice derives
    nothing. Credentials have no evidence field in the schema, so they are always
    `absent` with reason `no_evidence_field`.

    Verification is always against the CURRENT text. When the extraction ran against a
    different (older) revision of the page, a quote that is still found is verified, and
    one that is not is `unverified` with reason `stale_revision` instead of `not_found`.
    """
    parts = [(PART_ANUNT, body), (PART_ANEXE, attachments)]
    text_hash = source_hash(parts)
    folded: list[Source] = []           # folding the texts is the expensive part: only when a quote needs it

    def source() -> Source:
        if not folded:
            folded.append(Source.build(parts))
        return folded[0]

    source_rev = str(posting.get("detail_content_hash") or "").strip() or None
    extraction_rev = str(posting.get("schema_source_revision") or "").strip() or None
    stale = bool(source_rev and extraction_rev and source_rev != extraction_rev)

    rows: list[dict] = []

    def add(role: dict, kind: str, index: int, label: str, quote: Any, *, has_field: bool = True):
        text = quote.strip() if isinstance(quote, str) else ""
        if not has_field:
            loc, text = Located(ABSENT, NO_EVIDENCE_FIELD), ""
        elif not text:
            loc = Located(ABSENT)
        else:
            loc = locate(text, source())
            if loc.status == UNVERIFIED and loc.reason == NOT_FOUND and stale:
                loc = Located(UNVERIFIED, STALE_REVISION)
        rows.append({
            "posting_id": posting["id"], "role_key": role["role_key"], "ordinal": role["ordinal"],
            "fact_kind": kind, "fact_index": index, "fact_label": label[:200], "basis": "extracted",
            "quote": _clip(text) if text else None,
            "status": loc.status, "reason": loc.reason, "part": loc.part,
            "section": loc.section, "occurrences": loc.occurrences,
            "source_revision": source_rev, "extraction_revision": extraction_rev,
            "text_hash": text_hash, "normalization": NORMALIZATION,
        })

    credential_quotes = isinstance(schema, dict) and "recruitment" in schema
    for role in roles:
        box = _containers(schema, role)
        if box is None:
            continue
        edu = box.get("education")
        if isinstance(edu, dict) and (label := _education_fact(edu)):
            add(role, "education", 0, label, edu.get("verbatim"))
        exp = box.get("experience")
        if isinstance(exp, dict) and (label := _experience_fact(exp)):
            add(role, "experience", 0, label, exp.get("verbatim"))
        skills = box.get("skill_list")
        for i, skill in enumerate(skills if isinstance(skills, list) else []):
            label = str(_dict(skill).get("label") or "").strip()
            if label:
                add(role, "skill", i, label, skill.get("evidence"))
        credentials = box.get("credentials")
        for i, cred in enumerate(credentials if isinstance(credentials, list) else []):
            c = _dict(cred)
            label = str(c.get("label") or c.get("kind") or "").strip()
            if label:
                # v5 credentials carry their quote (BETA-V5); a v4 credential has no
                # evidence field at all, which is a different fact from an empty one.
                # A v5 answer is told by its own keys (model_dump always writes
                # `recruitment`), never by a stray key on a v4 credential.
                if credential_quotes:
                    add(role, "credential", i, label, c.get("evidence"))
                else:
                    add(role, "credential", i, label, None, has_field=False)
    return rows


# ---- coverage ----------------------------------------------------------------------------------

def coverage_lines(counts: Iterable[tuple[str, str, str | None, int]]) -> list[str]:
    """Human-readable coverage from `(fact_kind, status, reason, n)` tuples.

    One line per fact kind: `verified N/T, unverified N/T (reasons), absent N/T`, with the
    denominator spelled out, because a bare percentage hides how few facts back it.
    """
    by_kind: dict[str, dict[str, int]] = {}
    reasons: dict[str, dict[str, int]] = {}
    for kind, status, reason, n in counts:
        by_kind.setdefault(kind, {}).setdefault(status, 0)
        by_kind[kind][status] += n
        if status == UNVERIFIED:
            reasons.setdefault(kind, {})
            reasons[kind][reason or "-"] = reasons[kind].get(reason or "-", 0) + n
    lines = []
    for kind in FACT_KINDS:
        got = by_kind.get(kind)
        if not got:
            continue
        total = sum(got.values())
        parts = [f"verified {got.get(VERIFIED, 0)}/{total}"]
        unv = got.get(UNVERIFIED, 0)
        why = ", ".join(f"{r} {n}" for r, n in sorted(reasons.get(kind, {}).items()))
        parts.append(f"unverified {unv}/{total}" + (f" ({why})" if why else ""))
        parts.append(f"absent {got.get(ABSENT, 0)}/{total}")
        lines.append(f"    {kind:<10} " + ", ".join(parts))
    return lines
