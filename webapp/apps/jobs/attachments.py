"""Describing a posting's attached documents.

The source names every file opaquely — `j_11414_c_9189_anunt_688423.docx`, or an
8-character hex hash — so the filename is worthless as a label.

An attempt to derive a *document kind* from the text instead ran into the shape
of the data: measured over 899 downloaded files, ~89% are simply the competition
announcement, and their headings are all some spelling of "ANUNȚ". The things a
reader might hope to pick out — bibliografie, fișa postului, cerere de înscriere,
calendarul — are almost never separate files; they are *sections inside* that one
announcement, so matching on them mislabels the announcement itself. Every
classifier that reached for them scored worse than saying nothing.

What survives is the narrow, high-precision case: a document that opens by
declaring itself an erratum or a results notice. Those matter — an erratum can
move a deadline — and they announce themselves on the first meaningful line,
before the word "anunț" appears. Everything else keeps the role it already has
from the field it came from (announcement_url vs other_links).
"""

from __future__ import annotations

import re
import unicodedata

#: Kinds worth calling out. Deliberately short: see the module docstring for why
#: `Bibliografie` / `Fișa postului` / `Cerere de înscriere` are not here.
ATTACHMENT_KINDS: tuple[tuple[str, str], ...] = (
    ("erata",     r"^(?:erat[ăa]|anulare|anularea|amanare|amanarea|prelungire|revocare|suspendare)\b"),
    ("rezultate", r"^(?:rezultat|proces[- ]verbal|centralizator|tabel nominal)"),
)

KIND_LABELS = {
    "erata": "Erată / modificare",
    "rezultate": "Rezultate",
}

#: Letterhead lines to step over while looking for the document's own opening.
_LETTERHEAD = re.compile(
    r"^(?:nr\.?|romania|judet|judetul|municipiul|comuna|orasul|primaria|str\.|strada|"
    r"cod|cui|cif|tel|fax|e-?mail|www|http|pagina|pagin[ăa]|exemplar|nesecret|aprob|"
    r"catre|anexa|ministerul|inspectoratul)\b|^[\W\d_]+$"
)

_MAX_LINES_CHECKED = 6
_HEAD_CHARS = 3000


def _normalize(text: str) -> str:
    """Lowercase, strip diacritics, and un-space letter-spaced headings.

    These documents love `A N U N Ț` and `E R A T Ă` as display headings.
    """
    text = unicodedata.normalize("NFKD", text.lower())
    text = "".join(c for c in text if not unicodedata.combining(c))
    text = re.sub(r"\b(?:[a-z]\s){2,}[a-z]\b", lambda m: m.group(0).replace(" ", ""), text)
    return re.sub(r"[ \t]+", " ", text).strip(" \t.:—-")


def classify_attachment(text: str) -> str | None:
    """Return an ATTACHMENT_KINDS key, or None for an ordinary announcement."""
    if not text:
        return None

    checked = 0
    for raw_line in text[:_HEAD_CHARS].split("\n"):
        line = _normalize(raw_line)
        if len(line) < 3 or _LETTERHEAD.match(line):
            continue
        checked += 1
        if checked > _MAX_LINES_CHECKED:
            break
        # Once the document has called itself an announcement, anything that
        # follows is one of its sections rather than its identity.
        if re.search(r"\banunt", line):
            return None
        for kind, pattern in ATTACHMENT_KINDS:
            if re.match(pattern, line):
                return kind
    return None


def human_size(num_bytes: int | None) -> str:
    """Render a byte count the way a download link should: 12 KB, 1.4 MB."""
    if not num_bytes or num_bytes < 0:
        return ""
    if num_bytes < 1024:
        return f"{num_bytes} B"
    kb = num_bytes / 1024
    if kb < 1024:
        return f"{kb:.0f} KB"
    return f"{kb / 1024:.1f} MB"
