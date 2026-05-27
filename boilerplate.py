"""Strip HG 1.336/2022 generic eligibility boilerplate from RO government
job posting text before sending to LLMs.

HG 1.336/2022 art. 15 (and art. 542 OUG 57/2019 echoed in many postings)
prescribes a fixed set of generic eligibility conditions that appears almost
verbatim on nearly every posting: cetățenia română, capacitate de muncă /
exercițiu, condamnări, pedepse complementare, condițiile de studii, vechime
etc. On the sampled posting (id=4437) this boilerplate is ~80% of the
`qualifications` field.

We strip these sentences before sending to the LLM. Two wins:
  - Smaller input -> lower per-call cost.
  - LLM does not echo the boilerplate back into `qualifications`,
    so the field becomes meaningfully role-specific signal.

Note: we DO NOT strip the `art. 35 dosar de candidatură` list (formular de
înscriere, copia actului de identitate, etc.) — that's the content that
should populate the `application_docs` field, even though it's also fairly
standard.
"""

from __future__ import annotations

import re
import unicodedata

# Each pattern is a single-line regex (case-insensitive, diacritic-tolerant)
# that, when present in a line/sentence, marks that sentence as HG 1.336/2022
# generic eligibility boilerplate.
#
# Diacritic-tolerant: we strip diacritics from BOTH the patterns and the text
# before matching (via _strip_diacritics). So pattern authors can write
# diacritic-free anchors. We only use these to find spans; we cut the
# original (with-diacritics) text at the matched span.
BOILERPLATE_PATTERNS: list[str] = [
    # cetățenia clause — "persoana să aibă cetățenie română ... domiciliul în România"
    r"cetatenie\s+romana(.{0,160}?domiciliul)?",
    # limba română scris și vorbit
    r"\blimba\s+romana,?\s+scris\s+(s|si|şi)\s+vorbit\b",
    r"cunoaste\s+limba\s+romana",
    # capacitate de muncă / exercițiu
    r"capacitate(\s+deplina)?\s+de\s+(munca|exercitiu)",
    # condițiile de studii necesare ocupării postului (generic)
    r"conditii(le)?\s+de\s+studii\s+necesare\s+ocuparii\s+postului",
    r"indeplineasc?a?\s+conditiile\s+de\s+studii",
    # condițiile de vechime necesare (generic)
    r"conditii(le)?\s+de\s+vechime\s+(necesare|specifice)?\s*(ocuparii)?\s*postului",
    r"indeplineas[cț]a?\s+conditiile\s+de\s+vechime",
    # condamnări infracțiuni
    r"nu\s+(a\s+fost\s+)?condamnat(a|ă)?\s+definitiv",
    r"infractiuni\s+contra\s+(securitatii|autoritatii|umanitatii)",
    r"infractiuni\s+(de\s+coruptie|de\s+fals|contra\s+infaptuirii\s+justitiei)",
    # pedeapsă complementară
    r"pedeapsa\s+complementara",
    r"interzisa\s+exercitarea\s+dreptului\s+de\s+a\s+ocupa",
    # clauze de confidențialitate / neconcurență
    r"clauze\s+de\s+confidentialitate",
    r"clauze\s+de\s+neconcurenta",
    # legea 53/2003 Codul muncii (generic citation, not specific to the role)
    r"legea\s+(nr\.?\s*)?53\s*/?\s*2003\s*[-,]\s*codul\s+muncii",
    r"codul\s+muncii,?\s+republicata,?\s+cu\s+modificarile",
    # OUG 57/2019 Codul administrativ + art. 542 ref (the umbrella citation)
    r"ordonanta\s+de\s+urgenta\s+a\s+guvernului\s+nr\.?\s*57\s*/?\s*2019",
    r"art\.?\s*542.{0,40}(codul\s+administrativ|contractul\s+individual\s+de\s+munca)",
    # HG 1336/2022 direct reference (when it's the legal-citation framing, not the dosar list)
    r"(h\.?g\.?|hotararea\s+guvernului)\s+(nr\.?\s*)?1\.?336\s*/?\s*2022",
    # starea de sănătate corespunzătoare postului (eliberată de medicul de familie)
    r"stare(a)?\s+de\s+sanatate\s+corespunzatoare\s+postului",
    r"adeverint(a|e)\s+medical(a|e)?\s+eliberat(a|e)?\s+de\s+medicul\s+de\s+familie",
    # "Îndeplinirea condițiilor de participare este obligatorie..." preamble
    r"indeplinirea\s+conditiilor\s+de\s+participare\s+este\s+obligatorie",
    # "Poate ocupa un post vacant sau temporar vacant persoana care îndeplinește..."
    r"poate\s+ocupa\s+un\s+post\s+vacant\s+sau\s+temporar\s+vacant",
    # "Contractul individual de muncă se încheie între..."
    r"contractul\s+individual\s+de\s+munca\s+se\s+incheie\s+intre",
]

# Compiled once at import time. We strip diacritics from text before matching
# against these (so patterns are diacritic-free).
_COMPILED = [re.compile(p, re.IGNORECASE) for p in BOILERPLATE_PATTERNS]


def _strip_diacritics(s: str) -> str:
    """Strip Romanian/Latin diacritics: ăâîșşțţ -> aaiiitt (etc)."""
    return "".join(
        c for c in unicodedata.normalize("NFD", s) if not unicodedata.combining(c)
    )


def _is_boilerplate_line(line: str) -> bool:
    """True if `line` matches any of the HG 1.336/2022 boilerplate patterns."""
    normalized = _strip_diacritics(line)
    return any(p.search(normalized) for p in _COMPILED)


# Sentinel marker — we mark removed lines with a single short marker line so
# the LLM still sees that there *was* a boilerplate section and doesn't
# hallucinate continuity across the gap.
_BOILERPLATE_MARKER = "[boilerplate HG 1.336/2022 omis]"


def strip_hg_1336(text: str) -> str:
    """Strip HG 1.336/2022 generic eligibility boilerplate lines from `text`.

    Operates per line. A line is considered boilerplate if any pattern in
    BOILERPLATE_PATTERNS matches its diacritic-stripped form. Consecutive
    boilerplate lines collapse into a single marker line.
    """
    if not text:
        return text
    out: list[str] = []
    last_was_boilerplate = False
    for raw_line in text.splitlines():
        if _is_boilerplate_line(raw_line):
            if not last_was_boilerplate:
                out.append(_BOILERPLATE_MARKER)
            last_was_boilerplate = True
        else:
            out.append(raw_line)
            last_was_boilerplate = False
    return "\n".join(out)


if __name__ == "__main__":
    # Quick smoke test: read a file path or piped stdin, print stripped output
    # and the byte savings.
    import sys

    if len(sys.argv) > 1:
        with open(sys.argv[1], encoding="utf-8") as f:
            src = f.read()
    else:
        src = sys.stdin.read()
    stripped = strip_hg_1336(src)
    print(stripped)
    print(
        f"\n# original: {len(src)} chars  ->  stripped: {len(stripped)} chars  "
        f"({(1 - len(stripped) / len(src)) * 100:.1f}% reduction)",
        file=sys.stderr,
    )
