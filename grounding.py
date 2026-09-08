"""Check that an extraction's quoted evidence actually appears in the source.

Every v3 requirement carries the phrase it was taken from — `evidence` on a
skill, `verbatim` on education/experience. That makes a hallucination check
possible with no second LLM call: if the model quotes a sentence the posting
never contained, it invented the requirement.

The check is deliberately lenient. Models paraphrase lightly, normalise
whitespace, expand abbreviations and elide with "…", so exact substring
matching flags far more than it should. Instead a quote counts as grounded
when most of its *content words* appear in the source.
"""

from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass

#: Romanian function words carry no evidence — a quote that only shares these
#: with the source is not grounded by them.
_STOPWORDS = frozenset("""
a al ale ai as au aceasta acest aceste acesta acestei acestor acestui adica
care cat catre ce cel cea cei cele ceea cu cum cand
de din dintre despre dupa doar deci
el ea ei ele este era esti eu
fi fie fost foarte fara
i ii in intr intre iar isi
la le lor lui
mai mea mei mele meu mi mine mod mult multe
ne nu noi nostru
o ori ori pana pe pentru prin printre precum
sa sau se si sunt sub spre
ta tale te ti tu
un una unei unor unui unele unii
va vor vom vei vi voi
ca cã cãtre nivel privind conform potrivit precum astfel orice
""".split())

_WORD_RE = re.compile(r"[0-9a-z]+")


def normalise(text: str) -> str:
    """Lowercase, strip diacritics, collapse everything else to single spaces."""
    folded = unicodedata.normalize("NFKD", text.lower())
    folded = "".join(c for c in folded if not unicodedata.combining(c))
    return " ".join(_WORD_RE.findall(folded))


def _content_words(text: str) -> list[str]:
    """Words that carry evidence: not stopwords, and long enough to be specific.

    The length floor is 3 rather than 4 so that "GIS", "TIC", "PC" and grade
    numerals survive — those are exactly the tokens worth checking.
    """
    return [w for w in normalise(text).split() if len(w) >= 3 and w not in _STOPWORDS]


@dataclass(frozen=True)
class Ungrounded:
    """One quote that could not be found in the source text."""

    path: str        # e.g. "skill_list[2].evidence"
    quote: str
    coverage: float  # fraction of content words located in the source

    def __str__(self) -> str:
        return f"{self.path}: {self.coverage:.0%} — {self.quote[:70]!r}"


#: Length of the contiguous run that counts as a verbatim phrase match.
_PHRASE_N = 4


def _ngrams(words: list[str], n: int) -> set[tuple[str, ...]]:
    return {tuple(words[i:i + n]) for i in range(len(words) - n + 1)}


@dataclass(frozen=True)
class Source:
    """Pre-normalised source text, so a batch of quotes is normalised once."""

    words: set[str]
    phrases: set[tuple[str, ...]]

    @classmethod
    def build(cls, text: str) -> "Source":
        seq = _content_words(text)
        return cls(words=set(seq), phrases=_ngrams(seq, _PHRASE_N))


def is_grounded(quote: str, source: "Source | set[str]", threshold: float) -> tuple[bool, float]:
    """Return (grounded, coverage) for one quote.

    Grounded when *either*
      - `threshold` of the quote's content words appear anywhere in the source, or
      - some run of `_PHRASE_N` consecutive content words appears *consecutively*
        in the source.

    The second rule exists because coverage punishes length asymmetrically: a
    long verbatim span with a short invented lead-in scores the same as a
    fabrication. A four-word phrase reproduced in order is not something a model
    invents by accident.

    Accepts a bare word set for callers that only want the first rule.
    """
    words = _content_words(quote)
    if not words:
        # Nothing checkable (pure punctuation or stopwords) — do not flag.
        return True, 1.0
    source_words = source.words if isinstance(source, Source) else source
    found = sum(1 for w in words if w in source_words)
    coverage = found / len(words)
    if coverage >= threshold:
        return True, coverage
    if isinstance(source, Source) and _ngrams(words, _PHRASE_N) & source.phrases:
        return True, coverage
    return False, coverage


#: Paths to every quote-bearing field in a v3 extraction. Each entry is
#: (container-path, key) where the container path may fan out over a list.
_QUOTE_PATHS: tuple[tuple[str, str], ...] = (
    ("education", "verbatim"),
    ("experience", "verbatim"),
    ("skill_list[]", "evidence"),
    ("positions[].education", "verbatim"),
    ("positions[].experience", "verbatim"),
    ("positions[].skill_list[]", "evidence"),
)


def _resolve(node, path: str):
    """Yield (rendered_path, value) for a dotted path with `[]` list fan-out."""
    if not path:
        yield "", node
        return
    head, _, rest = path.partition(".")
    fan_out = head.endswith("[]")
    key = head[:-2] if fan_out else head
    child = node.get(key) if isinstance(node, dict) else None
    if child is None:
        return
    if fan_out:
        if not isinstance(child, list):
            return
        for i, item in enumerate(child):
            for sub_path, value in _resolve(item, rest):
                rendered = f"{key}[{i}]" + (f".{sub_path}" if sub_path else "")
                yield rendered, value
    else:
        for sub_path, value in _resolve(child, rest):
            rendered = key + (f".{sub_path}" if sub_path else "")
            yield rendered, value


def check_grounding(schema: dict, source: str, threshold: float = 0.6) -> list[Ungrounded]:
    """Return every quote in `schema` that is not supported by `source`.

    `threshold` is the fraction of a quote's content words that must appear in
    the source. 0.6 was calibrated against real extractions: it clears normal
    paraphrase and elision while still catching invented requirements.
    """
    src = Source.build(source)
    findings: list[Ungrounded] = []
    for container_path, key in _QUOTE_PATHS:
        for rendered, container in _resolve(schema, container_path):
            if not isinstance(container, dict):
                continue
            quote = container.get(key)
            if not isinstance(quote, str) or not quote.strip():
                continue
            grounded, coverage = is_grounded(quote, src, threshold)
            if not grounded:
                findings.append(Ungrounded(f"{rendered}.{key}", quote, coverage))
    return findings
