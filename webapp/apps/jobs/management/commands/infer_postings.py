"""Infer metadata from JobPosting title + body and write to the `inferred` JSONField.

Layers:
  1. Profession family — keyword dictionary over normalised title; LLM fallback when confidence < 0.5
  2. Seniority + grade — regex over title
  3. Studies, experience, skills, languages, certifications — regex + keyword scan over body
  4. Anomaly flags — rule engine over structured fields + body

Usage:
    python manage.py infer_postings [--provider gemini|openai|anthropic]
                                    [--force] [--no-llm] [--limit N]
"""
from __future__ import annotations

import os
import re
import time
import unicodedata
from datetime import datetime, timezone

from django.core.management.base import BaseCommand
from django.db.models import Q

from apps.jobs.models import JobPosting


# ---------------------------------------------------------------------------
# Profession family dictionary
# ---------------------------------------------------------------------------

FAMILIES: dict[str, list[str]] = {
    "administrație": [
        "referent", "secretar", "registrator", "casier", "arhivist",
        "resurse umane", "urbanism", "amenajare", "registratura", "arhiva",
        "relatii publice", "protocol", "achizitii", "achizitie",
        "inspector", "consilier", "administrator", "manager", "director", "expert",
    ],
    "IT": [
        "informatician", "programator", "analist programator", "administrator retea",
        "sistem informatic", "securitate informatica", "helpdesk", "hardware",
        "software", "baze de date", "digital",
    ],
    "sănătate": [
        "medic", "asistent medical", "infirmier", "infirmiera", "ingrijitor",
        "farmacist", "kinetoterapeut", "stomatolog", "psiholog", "moasa",
        "labrant", "brancardier", "ambulantier", "biolog", "chimist",
        "fizician", "biochimist", "radiolog", "fizio", "balneolog", "ergoterapeut",
    ],
    "educație": [
        "profesor", "invatator", "educator", "pedagog", "psihopedagog",
        "instructor", "maiestru", "logoped", "consilier scolar",
    ],
    "juridic": [
        "jurist", "consilier juridic", "avocat", "executor judecatoresc", "notar",
        "grefier", "procuror", "judecator",
    ],
    "financiar": [
        "contabil", "economist", "auditor", "inspector fiscal", "analist financiar",
        "buget", "trezorerie", "finante", "financiar", "control financiar",
        "control intern",
    ],
    "tehnic": [
        "inginer", "tehnician", "electrician", "instalator", "mecanic", "sudor",
        "operator", "conducator auto", "sofer", "laborant tehnic", "topograf",
        "geolog", "desenator", "proiectant", "constructor",
        "buldoexcavatorist", "excavatorist", "utilajist", "macaragiu",
        "stivuitorist", "fochist", "lacatus", "tamplar", "zidar", "zugrav",
        "pavator", "dulgher", "vopsitor", "timonist",
        "muncitor", "muncitor necalificat", "muncitor calificat",
        "ingrijitor cladiri", "paznic", "portar",
    ],
    "social": [
        "asistent social", "inspector social", "mediator", "ingrijitor domiciliu",
        "ingrijitor la domiciliu", "monitor", "insotitor",
        "psiholog", "psiholog practicant", "psiholog specialist",
        "logoped social", "educator specializat", "terapeut",
    ],
    "cultură": [
        "muzeograf", "arhivar", "documentarist", "etnolog", "restaurator",
        "conservator", "bibliotecar", "redactor", "consultant cultural",
    ],
    "ordine publică": [
        "politist", "pompier", "jandarm", "ofiter", "subofiter", "agent paza",
        "inspector isu", "inspector protectia muncii", "protectie civila",
        "svsu", "situatii urgenta", "aparare civila", "psi",
    ],
}

PROFESSION_FAMILIES = sorted(FAMILIES.keys()) + ["altele"]


def _normalize(text: str) -> str:
    """Lowercase + strip diacritics for robust keyword matching."""
    text = text.lower()
    return "".join(
        c for c in unicodedata.normalize("NFKD", text)
        if not unicodedata.combining(c)
    )


def _kw_match(keyword: str, text: str) -> bool:
    """Return True if keyword appears as a whole word (or phrase) in text."""
    pattern = r"(?<!\w)" + re.escape(keyword) + r"(?!\w)"
    return bool(re.search(pattern, text))


def _infer_profession_family(title: str) -> tuple[str, float]:
    """Return (family, confidence) using keyword dictionary. Confidence in [0, 1]."""
    norm = _normalize(title)
    scores: dict[str, int] = {}
    for family, keywords in FAMILIES.items():
        count = sum(1 for kw in keywords if _kw_match(kw, norm))
        if count:
            scores[family] = count

    if not scores:
        return "altele", 0.0

    sorted_scores = sorted(scores.items(), key=lambda x: -x[1])
    top_family, top_score = sorted_scores[0]
    second_score = sorted_scores[1][1] if len(sorted_scores) > 1 else 0
    confidence = top_score / (top_score + second_score + 1)
    return top_family, round(confidence, 3)


# ---------------------------------------------------------------------------
# Seniority + grade
# ---------------------------------------------------------------------------

_SENIORITY_PATTERNS: list[tuple[str, str]] = [
    ("conducere_superioara", r"\bdirector\s+general\b|\bsecretar\s+general\b|\bviceprimar\b|\bprimar\b"),
    ("director",             r"\bdirector\b|\bsef\s+(?:serviciu|birou|sectie|departament|compartiment)\b"),
    ("expert",               r"\bexpert\b"),
    ("consilier",            r"\bconsilier\b"),
    ("inspector",            r"\binspector\b"),
    ("referent",             r"\breferent\b"),
    ("asistent",             r"\basistent\b(?!\s+medical)"),
    ("debutant",             r"\bdebutant\b"),
]

_GRADE_RE = re.compile(
    r"gradul?\s*(?P<roman>I{1,3}A?|IV)|"
    r"\b(?P<word>superior|principal|debutant)\b",
    re.IGNORECASE,
)


def _infer_seniority(title: str) -> str | None:
    norm = _normalize(title)
    for seniority, pattern in _SENIORITY_PATTERNS:
        if re.search(pattern, norm):
            return seniority
    return None


def _infer_grade(title: str) -> str | None:
    m = _GRADE_RE.search(title)
    if not m:
        return None
    if m.group("roman"):
        return m.group("roman").upper()
    if m.group("word"):
        return m.group("word").lower()
    return None


# ---------------------------------------------------------------------------
# Studies + experience
# ---------------------------------------------------------------------------

_STUDIES_LEVELS = [
    ("doctorat",     r"\bdoctorat\b|\bdoctor\b"),
    ("master",       r"\bmaster\b|\bmagistru\b|\bmagistratura\b"),
    ("licenta",      r"\blicenta\b|\blicen[tț][aă]\b|\babsolvent\b|\bstudii\s+superioare\b|\bfacultate\b|\bstudii\s+universitare\b"),
    ("postliceala",  r"\bpostliceala\b|\bpostliceal[aă]\b|\bscoala\s+postliceala\b"),
    ("liceala",      r"\bliceala\b|\bliceal[aă]\b|\bbacalaureat\b|\bstudii\s+medii\b"),
    ("generala",     r"\bgenerala\b|\bgeneral[aă]\b|\bstudii\s+generale\b|\b[48]\s*clase\b"),
]

_EXPERIENCE_RE = re.compile(
    r"(\d+)\s*ani?\s*(?:de\s*)?(?:vechime|experienta|experien[tț][aă]|munca|munc[aă]|activitate)",
    re.IGNORECASE,
)


def _infer_studies(body: str) -> str | None:
    norm = _normalize(body)
    for level, pattern in _STUDIES_LEVELS:
        if re.search(pattern, norm):
            return level
    return None


def _infer_experience(body: str) -> int | None:
    m = _EXPERIENCE_RE.search(body)
    if m:
        return int(m.group(1))
    return None


# ---------------------------------------------------------------------------
# Skills / languages / certifications
# ---------------------------------------------------------------------------

_SKILLS_KW = [
    "Microsoft Office", "Excel", "Word", "PowerPoint", "Outlook",
    "SAR", "FOREXEBUG", "SEAP", "SICAP", "ECDL",
    "permis auto", "permis de conducere",
    "curs de formare", "atestat",
]

_LANGUAGE_RE = re.compile(
    r"\b(englez[aă]|francez[aă]|german[aă]|italian[aă]|spaniol[aă]|rus[aă]|maghiar[aă])\b",
    re.IGNORECASE,
)

_CERT_RE = re.compile(
    r"\b(autorizatie\s+ORNISS|certificat\s+CNSAS|aviz\s+PSI|autorizatie\s+ISC|"
    r"autorizatie\s+ISCIR|autorizatie\s+ANRE|certificat\s+de\s+absolvire)\b",
    re.IGNORECASE,
)


def _infer_skills(body: str) -> list[str]:
    norm_body = body.lower()
    return [kw for kw in _SKILLS_KW if kw.lower() in norm_body]


def _infer_languages(body: str) -> list[str]:
    seen: dict[str, str] = {}
    for m in _LANGUAGE_RE.finditer(body):
        norm = _normalize(m.group(1))
        seen[norm] = m.group(1).lower()
    return list(seen.values())


def _infer_certifications(body: str) -> list[str]:
    return [m.group(0) for m in _CERT_RE.finditer(body)]


# ---------------------------------------------------------------------------
# Anomaly flags
# ---------------------------------------------------------------------------

_GENDER_RE = re.compile(r"\b(masculin|feminin)\b", re.IGNORECASE)

_CONTACT_IN_TEXT_RE = re.compile(
    r"\b0[\d\s.\-–/]{9,14}\d\b"       # Romanian phone (formatted)
    r"|[\w.+-]+@[\w.-]+\.[a-z]{2,}",   # email
    re.IGNORECASE,
)


def _infer_anomaly_flags(posting: JobPosting) -> list[str]:
    flags: list[str] = []

    # Short deadline: < 7 days from publish to submission deadline
    if posting.data_limita_depunere and posting.published_at:
        deadline_date = posting.data_limita_depunere.date()
        delta = (deadline_date - posting.published_at).days
        if delta < 7:
            flags.append("short_deadline")

    # Missing contact — distinguish card-empty from attachment-found
    has_contact_fields = (
        posting.contact_phone or posting.contact_email or posting.contact_person
    )
    if not has_contact_fields:
        combined = (posting.body_markdown or "") + " " + (posting.attachment_text or "")
        if _CONTACT_IN_TEXT_RE.search(combined):
            flags.append("contact_in_attachment")
        else:
            flags.append("missing_contact")

    # Gender-specific requirement in body
    if posting.body_markdown and _GENDER_RE.search(posting.body_markdown):
        flags.append("gender_criteria")

    # No body
    if not posting.body_markdown or len(posting.body_markdown.strip()) < 100:
        flags.append("no_body")

    return flags


# ---------------------------------------------------------------------------
# LLM fallback
# ---------------------------------------------------------------------------

_LLM_PROMPT = (
    "Clasifică postul din administrația publică română.\n"
    'Titlu: "{title}"\n'
    "Alege EXACT una din: administrație, IT, sănătate, educație, juridic, "
    "financiar, tehnic, social, cultură, ordine publică, altele\n"
    "Răspunde cu un singur cuvânt din lista de mai sus."
)


def _llm_classify(title: str, provider: str) -> str:
    prompt = _LLM_PROMPT.format(title=title)
    raw = ""
    try:
        if provider == "gemini":
            from google import genai
            client = genai.Client(api_key=os.environ["GOOGLE_API_KEY"])
            raw = client.models.generate_content(
                model="gemini-2.5-flash", contents=prompt
            ).text.strip()
        elif provider == "openai":
            import openai
            client = openai.OpenAI(api_key=os.environ["OPENAI_API_KEY"])
            raw = client.chat.completions.create(
                model="gpt-4o-mini",
                messages=[{"role": "user", "content": prompt}],
                max_tokens=20,
            ).choices[0].message.content.strip()
        elif provider == "anthropic":
            import anthropic
            client = anthropic.Anthropic(api_key=os.environ["ANTHROPIC_API_KEY"])
            raw = client.messages.create(
                model="claude-haiku-4-5-20251001",
                max_tokens=20,
                messages=[{"role": "user", "content": prompt}],
            ).content[0].text.strip()
    except Exception as exc:
        raise RuntimeError(f"LLM call failed: {exc}") from exc

    # Validate response
    norm = _normalize(raw.split()[0] if raw else "")
    for fam in PROFESSION_FAMILIES:
        if _normalize(fam) == norm:
            return fam
    # Partial match
    for fam in PROFESSION_FAMILIES:
        if _normalize(fam) in norm or norm in _normalize(fam):
            return fam
    return "altele"


# ---------------------------------------------------------------------------
# Main inference function
# ---------------------------------------------------------------------------

def infer_posting(posting: JobPosting, *, provider: str, use_llm: bool) -> dict:
    body = (posting.body_markdown or "") + "\n\n" + (posting.attachment_text or "")
    title = posting.title or ""

    # Layer 1: profession family
    family, confidence = _infer_profession_family(title)
    source = "dict"

    if use_llm and confidence < 0.5:
        try:
            family = _llm_classify(title, provider)
            confidence = 0.7
            source = "llm"
            time.sleep(0.5)
        except RuntimeError:
            family = "altele"
            confidence = 0.0
            source = "error"

    # Layer 2: seniority + grade
    seniority = _infer_seniority(title)
    grade = _infer_grade(title)

    # Layer 3: studies, experience, skills, languages, certifications
    studies = _infer_studies(body)
    experience = _infer_experience(body)
    skills = _infer_skills(body)
    languages = _infer_languages(body)
    certifications = _infer_certifications(body)

    # Layer 4: anomaly flags
    anomaly_flags = _infer_anomaly_flags(posting)
    anomaly_score = round(len(anomaly_flags) / 4, 3)

    return {
        "profession_family": family,
        "profession_family_confidence": confidence,
        "profession_family_source": source,
        "seniority": seniority,
        "grade": grade,
        "studies_required": studies,
        "experience_years": experience,
        "skills": skills,
        "languages": languages,
        "certifications": certifications,
        "anomaly_flags": anomaly_flags,
        "anomaly_score": anomaly_score,
        "inferred_at": datetime.now(tz=timezone.utc).isoformat(),
    }


# ---------------------------------------------------------------------------
# Management command
# ---------------------------------------------------------------------------

class Command(BaseCommand):
    help = "Infer metadata from title + body and populate JobPosting.inferred."

    def add_arguments(self, parser):
        default_provider = os.environ.get("LLM_PROVIDER", "gemini")
        parser.add_argument(
            "--provider",
            choices=["gemini", "openai", "anthropic"],
            default=default_provider,
            help=f"LLM provider for profession-family fallback (default: {default_provider})",
        )
        parser.add_argument(
            "--force",
            action="store_true",
            help="Re-run postings that already have inferred data.",
        )
        parser.add_argument(
            "--no-llm",
            action="store_true",
            help="Skip LLM fallback; unmatched titles get family='altele', confidence=0.0.",
        )
        parser.add_argument(
            "--limit",
            type=int,
            default=None,
            help="Process at most N postings (useful for testing).",
        )

    def handle(self, *args, **opts):
        use_llm = not opts["no_llm"]
        provider = opts["provider"]

        qs = JobPosting.objects.all().order_by("id")
        if not opts["force"]:
            qs = qs.filter(Q(inferred={}) | Q(inferred__isnull=True))
        if opts["limit"]:
            qs = qs[: opts["limit"]]

        total = qs.count()
        self.stdout.write(f"Processing {total} postings (provider={provider}, llm={'yes' if use_llm else 'no'})…")

        done = llm_calls = errors = 0

        for posting in qs.iterator(chunk_size=200):
            try:
                inferred = infer_posting(posting, provider=provider, use_llm=use_llm)
                if inferred["profession_family_source"] == "llm":
                    llm_calls += 1
                elif inferred["profession_family_source"] == "error":
                    errors += 1
                JobPosting.objects.filter(pk=posting.pk).update(inferred=inferred)
                done += 1
            except Exception as exc:
                self.stderr.write(f"  Error on pk={posting.pk} '{posting.title[:60]}': {exc}")
                errors += 1

            if done % 500 == 0:
                self.stdout.write(f"  {done}/{total} done, {llm_calls} LLM calls, {errors} errors")

        self.stdout.write(
            self.style.SUCCESS(
                f"Done. {done} updated, {llm_calls} LLM calls, {errors} errors."
            )
        )
