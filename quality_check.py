"""
Data quality check for the posturi.gov.ro pipeline.

Samples 5-10 diverse job postings from data/anunturi/anunturi.csv, runs all four
pipeline layers (CSV fields, attachment extraction, metadata inference, LLM schema),
and produces:
  - data/quality_report.json   (machine-readable scores)
  - console table              (human-readable summary)

Usage:
    python quality_check.py
    python quality_check.py --n 10
    python quality_check.py --provider gemini|openai|anthropic|deepseek
    python quality_check.py --no-llm
    python quality_check.py --slugs slug1,slug2,...
"""

import argparse
import csv
import json
import os
import re
import unicodedata
from datetime import datetime, timezone
from pathlib import Path

from dotenv import load_dotenv

from boilerplate import strip_hg_1336

load_dotenv()

DATA_DIR = Path("data")
CSV_PATH = DATA_DIR / "anunturi" / "anunturi.csv"
INDEX_CSV_PATH = DATA_DIR / "posturi_gov_ro.csv"
DOWNLOADS_DIR = DATA_DIR / "downloads"
SCHEMA_DIR = DATA_DIR / "schema"
REPORT_PATH = DATA_DIR / "quality_report.json"

DEFAULTS = {
    "gemini": "gemini-2.5-flash",
    "openai": "gpt-4o",
    "anthropic": "claude-3-5-haiku-20241022",
    "deepseek": "deepseek-v4-flash",
}


# ─── helpers ──────────────────────────────────────────────────────────────────


def slug_from_url(url: str) -> str:
    return url.rstrip("/").split("/")[-1] or "unknown"


_RO_MONTHS = {
    "ianuarie": 1, "februarie": 2, "martie": 3, "aprilie": 4,
    "mai": 5, "iunie": 6, "iulie": 7, "august": 8,
    "septembrie": 9, "octombrie": 10, "noiembrie": 11, "decembrie": 12,
}


def _parse_index_date(raw: str) -> str:
    """Parse index CSV date strings to YYYY-MM-DD.

    Handles:
      "Publicat în: 9 septembrie,2024"  →  "2024-09-09"
      "Expiră in  16/09/2024"           →  "2024-09-16"
    Returns "" if unparseable.
    """
    m = re.search(r"(\d{1,2})/(\d{2})/(\d{4})", raw)
    if m:
        return f"{m.group(3)}-{int(m.group(2)):02d}-{int(m.group(1)):02d}"
    m = re.search(r"(\d{1,2})\s+(\w+),\s*(\d{4})", raw)
    if m:
        month_num = _RO_MONTHS.get(m.group(2).lower())
        if month_num:
            return f"{m.group(3)}-{month_num:02d}-{int(m.group(1)):02d}"
    return ""


def _load_index_dates() -> dict[str, tuple[str, str]]:
    """Return {url: (date_posted_iso, valid_through_iso)} from posturi_gov_ro.csv."""
    result: dict[str, tuple[str, str]] = {}
    if not INDEX_CSV_PATH.exists():
        return result
    with open(INDEX_CSV_PATH, newline="", encoding="utf-8") as f:
        for row in csv.DictReader(f):
            url = row.get("url", "").strip()
            if url:
                result[url] = (
                    _parse_index_date(row.get("publicat_in", "")),
                    _parse_index_date(row.get("expira_in", "")),
                )
    return result


# ─── step 1: stratified sampling ──────────────────────────────────────────────


def sample_diverse(rows: list, n: int, seed: int | None = None) -> list:
    import random
    rng = random.Random(seed)
    candidates = [r for r in rows if len(r.get("Main Body Markdown", "")) > 100]
    rng.shuffle(candidates)

    bins: dict[str, list] = {
        "Temporar": [],
        "Permanent": [],
        "conducere": [],
        "executie": [],
        "publica": [],
        "contractuala": [],
        "has_attachment": [],
        "no_attachment": [],
        "short_body": [],
        "long_body": [],
    }

    for r in candidates:
        job_type = r.get("Job Type", "")
        job_level = r.get("Job Level", "").lower()
        categorie = r.get("Categorie", "").lower()
        announce_url = r.get("Announcement URL", "")
        body_len = len(r.get("Main Body Markdown", ""))

        if "Temporar" in job_type:
            bins["Temporar"].append(r)
        if "Permanent" in job_type:
            bins["Permanent"].append(r)
        if "conducere" in job_level:
            bins["conducere"].append(r)
        else:
            bins["executie"].append(r)
        if "public" in categorie:
            bins["publica"].append(r)
        else:
            bins["contractuala"].append(r)
        if announce_url.strip():
            bins["has_attachment"].append(r)
        else:
            bins["no_attachment"].append(r)
        if body_len < 500:
            bins["short_body"].append(r)
        if body_len > 3000:
            bins["long_body"].append(r)

    selected: dict[str, dict] = {}
    bin_order = [
        "Temporar", "conducere", "no_attachment", "short_body", "publica",
        "Permanent", "executie", "has_attachment", "long_body", "contractuala",
    ]

    for bin_key in bin_order:
        if len(selected) >= n:
            break
        for row in bins[bin_key]:
            url = row.get("Source URL", "")
            if url not in selected:
                selected[url] = row
                break

    judets_seen = {r.get("Location", "") for r in selected.values()}
    for row in candidates:
        if len(selected) >= n:
            break
        url = row.get("Source URL", "")
        judet = row.get("Location", "")
        if url not in selected and judet not in judets_seen:
            selected[url] = row
            judets_seen.add(judet)

    for row in candidates:
        if len(selected) >= n:
            break
        url = row.get("Source URL", "")
        if url not in selected:
            selected[url] = row

    return list(selected.values())[:n]


# ─── step 2: CSV field quality ────────────────────────────────────────────────


def _check_body_duplication(body: str) -> bool:
    """Return True if >35% of paragraphs appear to be duplicated.

    The CSV stores newlines as literal backslash-n, so we unescape before
    splitting into paragraphs to get meaningful boundaries.

    The scraper emits Markdown trailing-space line breaks ("  \\n"), which
    produce single newlines separated by two spaces after unescaping — not
    double newlines.  We normalise those as paragraph boundaries too so that
    the paragraph-level dedup check fires on that format.
    """
    if len(body) < 200:
        return False
    unescaped = body.replace("\\n", "\n")
    # Normalise "  \n" Markdown hard-breaks → paragraph boundary
    normalised = re.sub(r"  \n", "\n\n", unescaped)
    paragraphs = [p.strip() for p in re.split(r"\n{2,}", normalised) if p.strip() and len(p.strip()) > 20]
    if len(paragraphs) < 4:
        return False
    seen: dict[str, int] = {}
    for p in paragraphs:
        key = p[:120]
        seen[key] = seen.get(key, 0) + 1
    duplicated = sum(count - 1 for count in seen.values() if count > 1)
    return (duplicated / len(paragraphs)) > 0.35


def check_csv_fields(row: dict) -> dict:
    issues = []

    for field in ("Job Title", "Employer", "Location"):
        if not row.get(field, "").strip():
            issues.append(f"missing_{field.lower().replace(' ', '_')}")

    deadline = row.get("Data Limita Depunere", "").strip()
    if not deadline:
        issues.append("missing_deadline")
    else:
        # Accept "DD.MM.YYYY" or "DD.MM.YYYY, ora HH.MM"
        date_part = deadline.split(",")[0].strip()
        try:
            datetime.strptime(date_part, "%d.%m.%Y")
        except ValueError:
            issues.append("invalid_deadline_format")

    has_contact = any(
        row.get(f, "").strip()
        for f in ("Contact Telefon", "Contact Email", "Contact Persoana")
    )
    if not has_contact:
        issues.append("missing_contact")

    nr = row.get("Nr Posturi", "").strip()
    if nr:
        try:
            if int(nr) < 1:
                issues.append("invalid_nr_posturi")
        except ValueError:
            issues.append("invalid_nr_posturi")
    else:
        issues.append("missing_nr_posturi")

    body = row.get("Main Body Markdown", "")
    if len(body) < 100:
        issues.append("short_body")

    total_checks = 8
    score = round(max(0.0, (total_checks - len(issues)) / total_checks), 2)

    return {
        "completeness_score": score,
        "issues": issues,
        "body_duplication": _check_body_duplication(body),
        "body_length": len(body),
    }


# ─── step 3: attachment extraction ───────────────────────────────────────────


def _extract_docx(path: Path) -> str:
    from docx import Document
    doc = Document(path)
    return "\n".join(p.text for p in doc.paragraphs if p.text.strip())


def _extract_doc(path: Path) -> str:
    import subprocess
    result = subprocess.run(
        ["textutil", "-convert", "txt", "-stdout", str(path)],
        capture_output=True, text=True,
    )
    return result.stdout if result.returncode == 0 else ""


def _extract_pdf(path: Path) -> str:
    from pypdf import PdfReader
    reader = PdfReader(str(path))
    text = "\n".join(page.extract_text() or "" for page in reader.pages).strip()
    if text:
        return text
    # Empty — may be a scanned/image-only PDF.  Try pdftoppm + tesseract OCR
    # if the file is large enough to plausibly contain real content.
    if path.stat().st_size < 50_000:
        return text
    return _ocr_pdf(path)


def _ocr_pdf(path: Path) -> str:
    """Convert PDF pages to PNG via pdftoppm then OCR each with tesseract."""
    import subprocess
    import tempfile
    with tempfile.TemporaryDirectory() as tmpdir:
        prefix = os.path.join(tmpdir, "page")
        r = subprocess.run(
            ["pdftoppm", "-r", "150", "-png", str(path), prefix],
            capture_output=True,
        )
        if r.returncode != 0:
            return ""
        pages = sorted(Path(tmpdir).glob("page-*.png"))
        texts = []
        for pg in pages:
            result = subprocess.run(
                ["tesseract", str(pg), "stdout", "-l", "ron"],
                capture_output=True, text=True, encoding="utf-8",
            )
            if result.returncode == 0 and result.stdout.strip():
                texts.append(result.stdout.strip())
        return "\n\n".join(texts)


def extract_text(path: Path) -> str:
    suffix = path.suffix.lower()
    try:
        if suffix == ".docx":
            return _extract_docx(path)
        if suffix == ".doc":
            return _extract_doc(path)
        if suffix == ".pdf":
            return _extract_pdf(path)
    except Exception:
        return ""
    return ""


def check_attachment(row: dict) -> dict:
    announce_url = row.get("Announcement URL", "").strip()
    if not announce_url:
        return {"readability": "no_attachment", "file": None, "text_length": 0, "alpha_ratio": 0.0, "text": ""}

    filename = os.path.basename(announce_url)
    path = DOWNLOADS_DIR / filename

    if not path.exists():
        return {"readability": "missing_file", "file": filename, "text_length": 0, "alpha_ratio": 0.0, "text": ""}

    text = extract_text(path)

    if not text.strip():
        return {"readability": "empty", "file": filename, "text_length": 0, "alpha_ratio": 0.0, "text": ""}

    text_len = len(text)
    alpha_ratio = round(sum(c.isalpha() for c in text) / max(text_len, 1), 3)
    encoding_ok = "\x00" not in text and "�" not in text

    readability = "garbled" if (alpha_ratio < 0.4 or not encoding_ok) else "ok"

    return {
        "readability": readability,
        "file": filename,
        "text_length": text_len,
        "alpha_ratio": alpha_ratio,
        "text": text,
    }


# ─── step 4: metadata inference ───────────────────────────────────────────────
# Pure functions copied from webapp/apps/jobs/management/commands/infer_postings.py

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
        "psiholog", "psiholog practicant", "psiholog specialist", "logoped social",
        "educator specializat", "terapeut",
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
    return "".join(
        c for c in unicodedata.normalize("NFKD", text.lower())
        if not unicodedata.combining(c)
    )


def _kw_match(keyword: str, text: str) -> bool:
    return bool(re.search(r"(?<!\w)" + re.escape(keyword) + r"(?!\w)", text))


def _infer_profession_family(title: str) -> tuple[str, float]:
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
    return top_family, round(top_score / (top_score + second_score + 1), 3)


_SENIORITY_PATTERNS = [
    ("conducere_superioara", r"\bdirector\s+general\b|\bsecretar\s+general\b|\bviceprimar\b|\bprimar\b"),
    ("director", r"\bdirector\b|\bsef\s+(?:serviciu|birou|sectie|departament|compartiment)\b"),
    ("expert", r"\bexpert\b"),
    ("consilier", r"\bconsilier\b"),
    ("inspector", r"\binspector\b"),
    ("referent", r"\breferent\b"),
    ("asistent", r"\basistent\b(?!\s+medical)"),
    ("debutant", r"\bdebutant\b"),
]

_GRADE_RE = re.compile(
    r"gradul?\s*(?P<roman>I{1,3}A?|IV)|\b(?P<word>superior|principal|debutant)\b",
    re.IGNORECASE,
)

_STUDIES_LEVELS = [
    ("doctorat", r"\bdoctorat\b|\bdoctor\b"),
    ("master", r"\bmaster\b|\bmagistru\b|\bmagistratura\b"),
    ("licenta", r"\blicenta\b|\blicen[tț][aă]\b|\babsolvent\b|\bstudii\s+superioare\b|\bfacultate\b|\bstudii\s+universitare\b"),
    ("postliceala", r"\bpostliceala\b|\bpostliceal[aă]\b|\bscoala\s+postliceala\b"),
    ("liceala", r"\bliceala\b|\bliceal[aă]\b|\bbacalaureat\b|\bstudii\s+medii\b"),
    ("generala", r"\bgenerala\b|\bgeneral[aă]\b|\bstudii\s+generale\b|\b[48]\s*clase\b"),
]

_EXPERIENCE_RE = re.compile(
    r"(\d+)\s*ani?\s*(?:de\s*)?(?:vechime|experienta|experien[tț][aă]|munca|munc[aă]|activitate)",
    re.IGNORECASE,
)

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

_GENDER_RE = re.compile(r"\b(masculin|feminin)\b", re.IGNORECASE)


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


def _infer_studies(body: str) -> str | None:
    norm = _normalize(body)
    for level, pattern in _STUDIES_LEVELS:
        if re.search(pattern, norm):
            return level
    return None


def _infer_experience(body: str) -> int | None:
    m = _EXPERIENCE_RE.search(body)
    return int(m.group(1)) if m else None


def _infer_skills(body: str) -> list[str]:
    norm_body = body.lower()
    return [kw for kw in _SKILLS_KW if kw.lower() in norm_body]


def _infer_languages(body: str) -> list[str]:
    seen: dict[str, str] = {}
    for m in _LANGUAGE_RE.finditer(body):
        seen[_normalize(m.group(1))] = m.group(1).lower()
    return list(seen.values())


def _infer_certifications(body: str) -> list[str]:
    return [m.group(0) for m in _CERT_RE.finditer(body)]


_CONTACT_IN_TEXT_RE = re.compile(
    r"\b0[\d\s.\-–/]{9,14}\d\b"          # Romanian phone (formatted)
    r"|[\w.+-]+@[\w.-]+\.[a-z]{2,}",      # email
    re.IGNORECASE,
)

# Words too generic/short to be meaningful for title-vs-attachment matching
_TITLE_STOP_WORDS = frozenset(
    "de la a al ale în din cu și sau pentru pe prin privind conform "
    "i ia ib ii iii iv v vi nr post posturi un o specialist".split()
)


def _attachment_title_mismatch(title: str, attachment_text: str) -> bool:
    """Return True if the attachment text does not mention the job title keywords.

    Checks whether at least 2 meaningful words from the posting title appear
    anywhere in the first 800 characters of the attachment text.  A low overlap
    suggests the wrong file was linked to this posting.

    Only fires when the attachment is long enough to be a real document (> 200
    chars), to avoid false positives on stub files.
    """
    if not attachment_text or len(attachment_text.strip()) < 200:
        return False
    norm_title = _normalize(title)
    title_words = [
        w for w in re.split(r"\W+", norm_title)
        if len(w) >= 4 and w not in _TITLE_STOP_WORDS
    ]
    if len(title_words) < 2:
        return False
    norm_att = _normalize(attachment_text[:2000])
    hits = sum(1 for w in title_words if w in norm_att)
    return hits < 2


def _infer_anomaly_flags(row: dict, body: str, *, attachment_text: str = "") -> list[str]:
    flags = []

    has_contact_csv = any(
        row.get(f, "").strip()
        for f in ("Contact Telefon", "Contact Email", "Contact Persoana")
    )
    if not has_contact_csv:
        if body and _CONTACT_IN_TEXT_RE.search(body):
            # Phone/email present in body or attachment but not extracted to CSV fields
            flags.append("contact_in_attachment")
        else:
            flags.append("missing_contact")

    if body and _GENDER_RE.search(body):
        flags.append("gender_criteria")

    if not body or len(body.strip()) < 100:
        flags.append("no_body")

    title = row.get("Job Title", "")
    if attachment_text and _attachment_title_mismatch(title, attachment_text):
        flags.append("attachment_title_mismatch")

    return flags


def _llm_classify(title: str, provider: str) -> str:
    prompt = (
        "Clasifică postul din administrația publică română.\n"
        f'Titlu: "{title}"\n'
        "Alege EXACT una din: administrație, IT, sănătate, educație, juridic, "
        "financiar, tehnic, social, cultură, ordine publică, altele\n"
        "Răspunde cu un singur cuvânt din lista de mai sus."
    )
    raw = ""
    if provider == "gemini":
        from google import genai
        client = genai.Client(api_key=os.environ["GOOGLE_API_KEY"])
        raw = client.models.generate_content(
            model=DEFAULTS["gemini"], contents=prompt
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

    norm = _normalize(raw.split()[0] if raw else "")
    for fam in PROFESSION_FAMILIES:
        if _normalize(fam) == norm:
            return fam
    for fam in PROFESSION_FAMILIES:
        if _normalize(fam) in norm or norm in _normalize(fam):
            return fam
    return "altele"


def check_infer(row: dict, body: str, *, provider: str, use_llm: bool, attachment_text: str = "") -> dict:
    title = row.get("Job Title", "")
    family, confidence = _infer_profession_family(title)
    source = "dict"

    if use_llm and confidence < 0.5:
        try:
            family = _llm_classify(title, provider)
            confidence = 0.7
            source = "llm"
        except Exception:
            family = "altele"
            confidence = 0.0
            source = "error"

    anomaly_flags = _infer_anomaly_flags(row, body, attachment_text=attachment_text)

    return {
        "profession_family": family,
        "profession_family_confidence": confidence,
        "profession_family_source": source,
        "seniority": _infer_seniority(title),
        "grade": _infer_grade(title),
        "studies_required": _infer_studies(body),
        "experience_years": _infer_experience(body),
        "skills": _infer_skills(body),
        "languages": _infer_languages(body),
        "certifications": _infer_certifications(body),
        "anomaly_flags": anomaly_flags,
        "anomaly_score": round(len(anomaly_flags) / 5, 3),
    }


# ─── step 5: LLM schema.org generation ───────────────────────────────────────

SCHEMA_PROMPT = (
    "Convert this Romanian public sector job posting to schema.org/JobPosting JSON-LD format. "
    "Return only valid JSON with no markdown fences. "
    "Do not include taxa de concurs or application fees as baseSalary — omit baseSalary entirely "
    "if no actual salary range is explicitly stated."
)

SCHEMA_REQUIRED = [
    "@type", "title", "hiringOrganization", "jobLocation",
    "datePosted", "validThrough", "description",
]


def _parse_json_response(text: str) -> dict | None:
    text = text.strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[1].rsplit("```", 1)[0].strip()
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        m = re.search(r"(\{.*\})", text, re.DOTALL)
        if m:
            try:
                return json.loads(m.group(1))
            except json.JSONDecodeError:
                pass
    return None


def make_schema_generator(provider: str, model: str):
    if provider == "gemini":
        from google import genai
        client = genai.Client(api_key=os.environ["GOOGLE_API_KEY"])
        def generate(content: str):
            resp = client.models.generate_content(
                model=model, contents=f"{SCHEMA_PROMPT}\n\n{content}"
            )
            return _parse_json_response(resp.text)

    elif provider == "openai":
        import openai
        client = openai.OpenAI()
        def generate(content: str):
            kwargs = {
                "model": model,
                "messages": [
                    {"role": "system", "content": "Convert job postings to schema.org/JobPosting JSON-LD. Return only valid JSON."},
                    {"role": "user", "content": f"{SCHEMA_PROMPT}\n\n{content}"},
                ],
                # GPT-5 rejects `max_tokens`; GPT-4o accepts both. Use the new one uniformly.
                "max_completion_tokens": 2000,
            }
            # GPT-5 reasoning models consume the completion budget with internal
            # reasoning tokens by default — force minimal for this extraction task.
            # They also reject custom temperature.
            if model.startswith("gpt-5"):
                kwargs["reasoning_effort"] = "minimal"
            else:
                kwargs["temperature"] = 0.3
            resp = client.chat.completions.create(**kwargs)
            return _parse_json_response(resp.choices[0].message.content)

    elif provider == "anthropic":
        import anthropic
        client = anthropic.Anthropic()
        def generate(content: str):
            msg = client.messages.create(
                model=model,
                max_tokens=2000,
                messages=[{"role": "user", "content": f"{SCHEMA_PROMPT}\n\n{content}"}],
            )
            return _parse_json_response(msg.content[0].text)

    elif provider == "deepseek":
        import openai
        client = openai.OpenAI(api_key=os.getenv("DEEPSEEK_API_KEY"), base_url="https://api.deepseek.com")
        def generate(content: str):
            resp = client.chat.completions.create(
                model=model,
                messages=[
                    {"role": "system", "content": "Convert job postings to schema.org/JobPosting JSON-LD. Return only valid JSON."},
                    {"role": "user", "content": f"{SCHEMA_PROMPT}\n\n{content}"},
                ],
                max_tokens=2000,
                temperature=0.3,
                response_format={"type": "json_object"},
            )
            return _parse_json_response(resp.choices[0].message.content)

    else:
        raise ValueError(f"Unknown provider: {provider}")

    return generate


def _deduplicate_body(body: str) -> str:
    """Remove obviously repeated paragraphs (a known parsing artifact)."""
    paragraphs = re.split(r"\n{2,}", body)
    seen: set[str] = set()
    unique = []
    for p in paragraphs:
        key = p.strip()[:200]
        if key and key not in seen:
            seen.add(key)
            unique.append(p)
    return "\n\n".join(unique)


def _validate_schema(schema_json: dict) -> tuple[bool, list[str]]:
    if not isinstance(schema_json, dict):
        return False, ["not_a_dict"]
    missing = [f for f in SCHEMA_REQUIRED if not schema_json.get(f)]
    desc = schema_json.get("description", "")
    if isinstance(desc, str) and 0 < len(desc) < 50:
        missing.append("description_too_short")
    return len(missing) == 0, missing


def _unwrap_schema(parsed) -> dict | None:
    """If the LLM returned a list (multi-role posting), validate the first item
    and keep the full list for saving — one file per posting URL is fine."""
    if isinstance(parsed, list):
        if parsed and isinstance(parsed[0], dict):
            return parsed  # caller will use [0] for validation, save whole list
        return None
    return parsed


def check_schema(
    row: dict,
    body: str,
    attachment_text: str,
    generate_fn,
    *,
    date_posted: str = "",
    valid_through: str = "",
) -> dict:
    # Prefer columns from anunturi.csv if present; fall back to index lookup args
    date_posted = row.get("Data Publicare", "").strip() or date_posted
    valid_through = row.get("Data Expirare", "").strip() or valid_through

    fields_text = (
        f"Titlu: {row.get('Job Title', '')}\n"
        f"Angajator: {row.get('Employer', '')}\n"
        f"Localitate: {row.get('Location', '')}\n"
        f"Tip: {row.get('Job Type', '')} / {row.get('Categorie', '')}\n"
        f"Data publicare (datePosted): {date_posted}\n"
        f"Data expirare (validThrough): {valid_through}\n"
        f"Data limita depunere: {row.get('Data Limita Depunere', '')}\n"
        f"Nr posturi: {row.get('Nr Posturi', '')}\n"
        f"Telefon: {row.get('Contact Telefon', '')}\n"
        f"Email: {row.get('Contact Email', '')}\n"
    )

    deduped_body = _deduplicate_body(body)
    content = f"{fields_text}\n\n{deduped_body}"
    if attachment_text:
        content += f"\n\n--- Document atașat ---\n{attachment_text[:2000]}"
    # Strip HG 1.336/2022 generic eligibility boilerplate before sending — same
    # treatment as the production llm-schema.py pipeline so the JSON-LD output
    # is grounded in role-specific signal, not legal citations.
    content = strip_hg_1336(content)

    try:
        schema_json = generate_fn(content)
    except Exception as exc:
        return {"valid": False, "missing_fields": ["generation_error"], "error": str(exc), "schema_json": None}

    if schema_json is None:
        return {"valid": False, "missing_fields": ["parse_error"], "schema_json": None}

    schema_json = _unwrap_schema(schema_json)
    if schema_json is None:
        return {"valid": False, "missing_fields": ["parse_error"], "schema_json": None}

    # For multi-role arrays, validate against the first item
    validate_target = schema_json[0] if isinstance(schema_json, list) else schema_json
    valid, missing = _validate_schema(validate_target)

    announce_url = row.get("Announcement URL", "") or row.get("Source URL", "")
    slug = slug_from_url(announce_url)
    out_path = SCHEMA_DIR / f"{slug}.json"
    SCHEMA_DIR.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(schema_json, indent=2, ensure_ascii=False), encoding="utf-8")

    result = {
        "valid": valid,
        "missing_fields": missing,
        "schema_json": schema_json,
        "saved_to": str(out_path),
    }
    if isinstance(schema_json, list):
        result["multi_role"] = len(schema_json)
    return result


# ─── reporting ────────────────────────────────────────────────────────────────


def print_table(results: list) -> None:
    col_widths = (26, 24, 5, 14, 24)
    headers = ("slug", "title", "csv%", "attachment", "family (conf)")
    header_row = "  ".join(h.ljust(w) for h, w in zip(headers, col_widths)) + "  schema"
    print()
    print(header_row)
    print("-" * len(header_row))

    for r in results:
        slug = r["slug"][:25]
        title = r["title"][:23]
        csv_pct = f"{int(r['csv']['completeness_score'] * 100)}%"

        att = r["attachment"]
        if att["readability"] == "no_attachment":
            attach_str = "n/a"
        elif att["readability"] == "missing_file":
            attach_str = "MISSING!"
        elif att["readability"] == "empty":
            attach_str = "empty"
        elif att["readability"] == "garbled":
            attach_str = f"garbled {att['text_length'] // 1000}k"
        else:
            attach_str = f"ok {att['text_length'] // 1000}k"

        inf = r["inferred"]
        family_str = f"{inf['profession_family']} ({inf['profession_family_confidence']:.2f})"

        schema = r.get("schema")
        if schema is None:
            schema_str = "skipped"
        elif schema["valid"]:
            schema_str = "ok"
        else:
            schema_str = "FAIL: " + ",".join(schema["missing_fields"][:3])

        dup_flag = " [DUP]" if r["csv"]["body_duplication"] else ""

        row_parts = [
            slug.ljust(col_widths[0]),
            title.ljust(col_widths[1]),
            csv_pct.ljust(col_widths[2]),
            attach_str.ljust(col_widths[3]),
            family_str.ljust(col_widths[4]),
            schema_str + dup_flag,
        ]
        print("  ".join(row_parts))

    print()


def compute_aggregate(results: list) -> dict:
    n = len(results)
    if n == 0:
        return {}

    avg_completeness = round(sum(r["csv"]["completeness_score"] for r in results) / n, 3)
    dup_count = sum(1 for r in results if r["csv"]["body_duplication"])

    with_attach = [r for r in results if r["attachment"]["readability"] not in ("no_attachment", "missing_file")]
    attach_ok = sum(1 for r in with_attach if r["attachment"]["readability"] == "ok")

    confidences = [r["inferred"]["profession_family_confidence"] for r in results]
    avg_confidence = round(sum(confidences) / n, 3)

    schemas = [r["schema"] for r in results if r.get("schema")]
    schema_valid_rate = round(sum(1 for s in schemas if s["valid"]) / max(len(schemas), 1), 3) if schemas else None

    return {
        "n": n,
        "avg_completeness": avg_completeness,
        "body_duplication_rate": round(dup_count / n, 3),
        "attachment_with_file": len(with_attach),
        "attachment_ok_rate": round(attach_ok / max(len(with_attach), 1), 3) if with_attach else None,
        "avg_infer_confidence": avg_confidence,
        "schema_valid_rate": schema_valid_rate,
    }


# ─── main ─────────────────────────────────────────────────────────────────────


def main() -> None:
    parser = argparse.ArgumentParser(description="Data quality check for posturi.gov.ro pipeline")
    parser.add_argument("--n", type=int, default=8, help="Number of postings to sample (default: 8)")
    parser.add_argument("--provider", choices=["gemini", "openai", "anthropic", "deepseek"], default="anthropic")
    parser.add_argument("--model", default=None, help="Override default model for the provider")
    parser.add_argument("--no-llm", action="store_true", help="Skip LLM steps (infer fallback + schema generation)")
    parser.add_argument("--slugs", default=None, help="Comma-separated slugs to force-select (e.g. 2a66f376.doc)")
    parser.add_argument("--seed", type=int, default=None, help="Random seed for sampling (omit for different sample each run)")
    args = parser.parse_args()

    model = args.model or DEFAULTS[args.provider]
    use_llm = not args.no_llm

    print(f"Loading {CSV_PATH}...")
    with open(CSV_PATH, newline="", encoding="utf-8") as f:
        all_rows = list(csv.DictReader(f))
    print(f"  {len(all_rows)} rows loaded")

    if args.slugs:
        slugs_wanted = set(args.slugs.split(","))
        rows = [
            r for r in all_rows
            if slug_from_url(r.get("Announcement URL", "") or r.get("Source URL", "")) in slugs_wanted
        ]
        if not rows:
            rows = [r for r in all_rows if any(s in r.get("Source URL", "") for s in slugs_wanted)]
    else:
        rows = sample_diverse(all_rows, args.n, seed=args.seed)

    print(f"  Selected {len(rows)} postings")
    if not use_llm:
        print("  LLM disabled (--no-llm): skipping infer fallback and schema generation")
    else:
        print(f"  LLM provider: {args.provider} / {model}")

    schema_gen = None
    if use_llm:
        try:
            schema_gen = make_schema_generator(args.provider, model)
        except Exception as e:
            print(f"  WARNING: Could not initialise LLM generator: {e}")

    index_dates = _load_index_dates()

    results = []

    for i, row in enumerate(rows, 1):
        title = row.get("Job Title", "unknown")
        source_url = row.get("Source URL", "")
        announce_url = row.get("Announcement URL", "")
        slug = slug_from_url(announce_url or source_url)

        print(f"\n[{i}/{len(rows)}] {slug}  —  {title[:55]}")

        csv_result = check_csv_fields(row)
        dup_note = " [body duplicated]" if csv_result["body_duplication"] else ""
        print(f"  CSV       {int(csv_result['completeness_score'] * 100)}% complete{dup_note}  issues: {csv_result['issues'] or 'none'}")

        att_result = check_attachment(row)
        print(f"  Attach    {att_result['readability']}  {att_result['text_length']} chars")

        body = row.get("Main Body Markdown", "").replace("\\n", "\n")
        attachment_text = att_result.get("text", "")
        combined_body = body + ("\n\n" + attachment_text if attachment_text else "")

        infer_result = check_infer(row, combined_body, provider=args.provider, use_llm=use_llm, attachment_text=attachment_text)
        print(
            f"  Infer     {infer_result['profession_family']} "
            f"({infer_result['profession_family_confidence']:.2f}, {infer_result['profession_family_source']})  "
            f"seniority={infer_result['seniority']}  "
            f"flags={infer_result['anomaly_flags'] or 'none'}"
        )

        idx_date_posted, idx_valid_through = index_dates.get(source_url, ("", ""))

        schema_result = None
        if schema_gen:
            print("  Schema    generating...", end="", flush=True)
            schema_result = check_schema(
                row, body, attachment_text, schema_gen,
                date_posted=idx_date_posted,
                valid_through=idx_valid_through,
            )
            status = "ok" if schema_result["valid"] else f"FAIL {schema_result['missing_fields']}"
            print(f"\r  Schema    {status}                    ")
        else:
            print("  Schema    skipped")

        results.append({
            "slug": slug,
            "source_url": source_url,
            "title": title,
            "employer": row.get("Employer", ""),
            "location": row.get("Location", ""),
            "job_type": row.get("Job Type", ""),
            "categorie": row.get("Categorie", ""),
            "csv": csv_result,
            "attachment": {k: v for k, v in att_result.items() if k != "text"},
            "inferred": infer_result,
            "schema": schema_result,
        })

    print_table(results)

    agg = compute_aggregate(results)
    print("Aggregate:")
    for k, v in agg.items():
        print(f"  {k}: {v}")

    report = {
        "generated_at": datetime.now(tz=timezone.utc).isoformat(),
        "provider": args.provider if use_llm else "none",
        "model": model if use_llm else None,
        "postings": results,
        "aggregate": agg,
    }

    REPORT_PATH.write_text(
        json.dumps(report, indent=2, ensure_ascii=False, default=str),
        encoding="utf-8",
    )
    print(f"\nReport saved to {REPORT_PATH}")


if __name__ == "__main__":
    main()
