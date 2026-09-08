"""Pydantic models for LLM-extracted job posting data (prompt v2).

Single source of truth for the v2 `schema_json` shape. Field keys follow
Schema.org JobPosting property names where they exist; three custom keys
(application_docs, application_fee, application_contact) cover RO-government
specifics with no Schema.org analogue.

The JSON Schema produced by these models is fed to provider-native structured
output APIs (OpenAI strict json_schema, Gemini response_schema, Anthropic
tool-use input_schema).
"""

from __future__ import annotations

import unicodedata
from typing import Literal, Optional

from pydantic import BaseModel, Field, model_validator


SalaryUnit = Literal["HOUR", "DAY", "WEEK", "MONTH", "YEAR"]


class BaseSalary(BaseModel):
    """Schema.org JobPosting.baseSalary — only populated when the posting
    explicitly states a salary amount or range. Never the application fee."""

    minValue: Optional[float] = Field(
        default=None, description="Minimum salary amount; equals maxValue for a fixed amount."
    )
    maxValue: Optional[float] = Field(
        default=None, description="Maximum salary amount; equals minValue for a fixed amount."
    )
    currency: str = Field(default="RON", description="ISO 4217 currency code, default RON.")
    unitText: SalaryUnit = Field(
        default="MONTH", description="Pay period: HOUR/DAY/WEEK/MONTH/YEAR. Default MONTH."
    )


class ApplicationFee(BaseModel):
    """RO-specific: `taxa de concurs` / `taxa de participare`. Never confused
    with baseSalary."""

    amount: Optional[float] = Field(default=None, description="Fee amount.")
    currency: str = Field(default="RON")
    account: Optional[str] = Field(default=None, description="IBAN or bank account where the fee is paid.")
    details: Optional[str] = Field(
        default=None, description="Payment instructions, exemptions, or other notes."
    )


class ApplicationContact(BaseModel):
    """Submission contact extracted from the posting text. Useful when the
    top-level CSV `contact_email`/`contact_phone` are empty or differ."""

    name: Optional[str] = Field(default=None, description="Person, role, or department.")
    phone: Optional[str] = Field(default=None)
    email: Optional[str] = Field(default=None)
    address: Optional[str] = Field(default=None, description="Where the dosar is submitted.")


class JobPostingExtraction(BaseModel):
    """Top-level extraction result. Every field nullable; null = not stated.

    Markdown-string fields use `-`-prefixed bullet lines for multi-item
    content. Plain strings otherwise.
    """

    # Schema.org JobPosting properties
    responsibilities: Optional[str] = Field(
        default=None, description='Job duties / "atribuții" / "sarcini" as markdown bullet list.'
    )
    educationRequirements: Optional[str] = Field(
        default=None,
        description='Required studies (studii superioare/medii/postliceale, specializarea, diploma). Do not include generic "condițiile de studii necesare ocupării postului" boilerplate.',
    )
    experienceRequirements: Optional[str] = Field(
        default=None,
        description='Required work experience ("vechime în specialitate", "experiență minimă"). Do not include generic "condițiile de vechime" boilerplate.',
    )
    qualifications: Optional[str] = Field(
        default=None,
        description='Role-specific eligibility (e.g. avize, autorizații, acces la informații clasificate). Do NOT include the standard HG 1.336/2022 / Codul muncii eligibility list (cetățenia, capacitate de muncă, condamnări, pedepse complementare etc.) — that\'s legal boilerplate already stripped.',
    )
    skills: Optional[str] = Field(
        default=None,
        description='Competențe, abilități, limbi străine, IT skills, certificări — markdown bullet list.',
    )
    baseSalary: Optional[BaseSalary] = Field(
        default=None,
        description='Structured salary if explicitly stated. Never use the application fee (taxa de concurs) here.',
    )
    jobBenefits: Optional[str] = Field(
        default=None, description='Benefits, facilități, sporuri — markdown bullet list.'
    )
    workHours: Optional[str] = Field(
        default=None,
        description='Schedule: "8h/zi", "normă întreagă, 40h/săptămână", "normă parțială 4h/zi", etc.',
    )
    jobLocation: Optional[str] = Field(
        default=None,
        description='Free-text address / place of work, more granular than județ (e.g. "U.M. 02384 București, Șos. de Centură nr. 44, Tunari, Ilfov").',
    )

    # RO-specific custom fields
    application_docs: Optional[str] = Field(
        default=None,
        description='Documents required in the dosar de candidatură — markdown bullet list.',
    )
    application_fee: Optional[ApplicationFee] = Field(
        default=None, description='Taxa de concurs / participare, structured.'
    )
    application_contact: Optional[ApplicationContact] = Field(
        default=None,
        description='Where to submit the dosar — person/department, phone, email, address.',
    )


def openai_json_schema() -> dict:
    """JSON Schema dict for OpenAI `response_format={"type":"json_schema", ...}`.

    OpenAI strict mode requires `additionalProperties: false` everywhere and
    every property listed under `required` (use null for optional).
    """
    schema = JobPostingExtraction.model_json_schema()
    _make_strict(schema)
    return {"name": "JobPostingExtraction", "schema": schema, "strict": True}


def _make_strict(node: dict) -> None:
    """Mutate a Pydantic-generated JSON Schema in place to satisfy OpenAI
    strict mode (additionalProperties=false, required lists every property)."""
    if not isinstance(node, dict):
        return
    if node.get("type") == "object" or "properties" in node:
        props = node.get("properties", {})
        node["additionalProperties"] = False
        node["required"] = list(props.keys())
        for v in props.values():
            _make_strict(v)
    # Recurse into $defs and any nested schemas
    for k in ("$defs", "definitions"):
        if k in node:
            for v in node[k].values():
                _make_strict(v)
    # anyOf / oneOf branches
    for k in ("anyOf", "oneOf", "allOf"):
        if k in node:
            for v in node[k]:
                _make_strict(v)


if __name__ == "__main__":
    import json

    print(json.dumps(JobPostingExtraction.model_json_schema(), indent=2, ensure_ascii=False))


# ===========================================================================
# Prompt v3 — granular, match-oriented extraction
# ===========================================================================
# v2 answers "what does this posting say?". v3 answers "can this candidate
# apply?", which is a different question and needs a different shape.
#
# The rule throughout: anything a filter or a CV match depends on gets a
# *controlled* value, and the original Romanian is kept beside it in a
# `verbatim` field for display. Free text cannot be matched; enums can.
#
# Vocabularies are the ones a Europass CV already carries, so the two sides can
# be compared without a translation layer:
#   EQF 1-8         qualification level        (Europass "Education", ISCED-mapped)
#   ISCED-F 2013    broad field of education   (Europass "Field of study")
#   CEFR A1-C2      language proficiency       (Europass "Language skills")
#   ESCO skill type knowledge / skill / transversal / language
# See docs/metadata-schema-v3.md for the full mapping table.

#: Romanian study levels, ordered, with their EQF equivalents.
StudyLevel = Literal[
    "generala",      # 8 clase                      — EQF 2
    "profesionala",  # școală profesională, calificare — EQF 3
    "liceala",       # bacalaureat / studii medii   — EQF 4
    "postliceala",   # școală postliceală           — EQF 5
    "licenta",       # studii superioare / licență  — EQF 6
    "master",        # master / studii aprofundate  — EQF 7
    "doctorat",      # doctorat                     — EQF 8
]

STUDY_LEVEL_TO_EQF: dict[str, int] = {
    "generala": 2, "profesionala": 3, "liceala": 4,
    "postliceala": 5, "licenta": 6, "master": 7, "doctorat": 8,
}

#: ISCED-F 2013 broad fields. Codes are the standard ones; a Europass CV's
#: field of study resolves to the same list.
IscedField = Literal[
    "00_generale",
    "01_educatie",
    "02_arte_umanioare",
    "03_stiinte_sociale",
    "04_afaceri_administratie_drept",
    "05_stiinte_naturale_matematica",
    "06_tic",
    "07_inginerie_constructii",
    "08_agricultura_silvicultura_veterinara",
    "09_sanatate_asistenta_sociala",
    "10_servicii",
]

#: ESCO skills-pillar concept types.
SkillType = Literal["knowledge", "skill", "transversal", "language"]

Proficiency = Literal["baza", "mediu", "avansat"]

CefrLevel = Literal["A1", "A2", "B1", "B2", "C1", "C2"]

#: Subject-matter domain, distinct from the profession. Adapted from the
#: `Domenii` facet on cariere.gov.md, which separates *what field the work is
#: in* from *what job it is* — a distinction our profession_family alone misses.
PolicyDomain = Literal[
    "achizitii_publice", "administratie_publica", "agricultura_alimentatie",
    "aparare_securitate", "asistenta_sociala", "constructii_urbanism",
    "cultura_patrimoniu", "demografie_migratie", "drept_justitie",
    "economie_finante", "educatie_cercetare", "energie", "mediu",
    "fonduri_europene", "relatii_munca", "resurse_umane", "sanatate_publica",
    "securitate_sanatate_munca", "sport_tineret", "tehnologia_informatiei",
    "comunicare_media", "transporturi", "turism", "altele",
]

ContractDuration = Literal["nedeterminata", "determinata", "sezonier", "proiect"]
WorkSchedule = Literal["norma_intreaga", "norma_partiala", "schimburi", "tura_noapte", "flexibil"]
RemoteMode = Literal["la_sediu", "hibrid", "telemunca"]


class FieldOfStudy(BaseModel):
    """One acceptable field of study. Postings usually list several as
    alternatives — "geografie/silvicultură/geologie" is three entries, not one
    string, so that a CV in any one of them matches."""

    label_ro: str = Field(description="Field as written in the posting, e.g. 'silvicultură'.")
    isced_field: Optional[IscedField] = Field(
        default=None, description="ISCED-F 2013 broad field this belongs to."
    )


class EducationRequirement(BaseModel):
    """Structured form of `educationRequirements`."""

    minimum_level: Optional[StudyLevel] = Field(
        default=None, description="Lowest study level that qualifies."
    )
    eqf_level: Optional[int] = Field(
        default=None, ge=1, le=8,
        description="EQF level matching minimum_level (generala 2 … doctorat 8).",
    )
    fields_of_study: list[FieldOfStudy] = Field(
        default_factory=list,
        description="Acceptable fields, as alternatives. Empty when any field qualifies.",
    )
    specialization: Optional[str] = Field(
        default=None, description="Narrower specialisation if named, e.g. 'medicină de familie'."
    )
    required: bool = Field(
        default=True, description="False when the posting frames this as an advantage, not a condition."
    )
    verbatim: Optional[str] = Field(default=None, description="The original sentence, for display.")

    @model_validator(mode="after")
    def _derive_eqf_level(self):
        """EQF level is a pure function of `minimum_level` — derive it, never trust it.

        The prompt used to ask the model for both and hope they agreed. They are
        the same fact in two notations, so asking twice can only introduce
        disagreement; `STUDY_LEVEL_TO_EQF` is the authority. A model-supplied
        value is overwritten whenever `minimum_level` is set, and kept only when
        it is not (some postings state a level with no Romanian equivalent named).
        """
        if self.minimum_level is not None:
            object.__setattr__(self, "eqf_level", STUDY_LEVEL_TO_EQF[self.minimum_level])
        return self


class SkillRequirement(BaseModel):
    """One competence, normalised to a short tag so it can be matched.

    "Cunoștințe avansate de operare sisteme GIS (lucrul cu baze de date,
    elaborare și actualizare hărți tematice, analiză spațială)" becomes
    label='GIS', type='knowledge', proficiency='avansat', required=True —
    with the sentence kept in `evidence`.
    """

    label: str = Field(
        description="Short canonical tag: 'GIS', 'Microsoft Excel', 'AutoCAD', "
                    "'analiză spațială', 'lucru în echipă'. Not a sentence."
    )
    type: SkillType = Field(
        default="skill",
        description="ESCO pillar: knowledge (a subject), skill (an ability), "
                    "transversal (soft), language.",
    )
    proficiency: Optional[Proficiency] = Field(
        default=None, description="Only when the posting says so ('cunoștințe avansate')."
    )
    required: bool = Field(default=True, description="False for 'constituie un avantaj'.")
    evidence: Optional[str] = Field(default=None, description="Phrase the tag was taken from.")


#: Romanian language names → ISO 639-1. Keys are diacritic-free and lowercased;
#: `_LANGUAGE_ISO` is consulted after the same normalisation is applied to the
#: model's output, so "Engleză", "engleza" and "ENGLEZA" all resolve.
_LANGUAGE_ISO: dict[str, str] = {
    "romana": "ro", "engleza": "en", "franceza": "fr", "germana": "de",
    "italiana": "it", "spaniola": "es", "portugheza": "pt", "rusa": "ru",
    "maghiara": "hu", "ucraineana": "uk", "bulgara": "bg", "sarba": "sr",
    "croata": "hr", "turca": "tr", "greaca": "el", "polona": "pl",
    "poloneza": "pl", "ceha": "cs", "slovaca": "sk", "olandeza": "nl",
    "suedeza": "sv", "norvegiana": "no", "daneza": "da", "finlandeza": "fi",
    "chineza": "zh", "japoneza": "ja", "araba": "ar", "ebraica": "he",
    "rromani": "rom", "romani": "rom", "tiganeasca": "rom",
    "limba semnelor": "sgn", "latina": "la",
}


def _normalise_language(name: str) -> str:
    """Lowercase, strip diacritics and a leading "limba ", for dictionary lookup."""
    folded = unicodedata.normalize("NFKD", name.strip().lower())
    folded = "".join(c for c in folded if not unicodedata.combining(c))
    return folded.removeprefix("limba ").strip()


class LanguageRequirement(BaseModel):
    """Europass-compatible language requirement."""

    language: str = Field(description="Language name in Romanian, e.g. 'engleză'.")
    iso_code: Optional[str] = Field(default=None, description="ISO 639-1, e.g. 'en'.")
    cefr: Optional[CefrLevel] = Field(
        default=None, description="CEFR level if stated or clearly implied."
    )
    required: bool = Field(default=True)

    @model_validator(mode="after")
    def _derive_iso_code(self):
        """Look the ISO 639-1 code up from the language name rather than asking.

        A 30-entry table covers every language these postings mention. Only
        falls back to the model's own value for a name not in the table.
        """
        code = _LANGUAGE_ISO.get(_normalise_language(self.language))
        if code:
            object.__setattr__(self, "iso_code", code)
        return self


class ExperienceRequirement(BaseModel):
    """Structured form of `experienceRequirements`."""

    years_minimum: Optional[float] = Field(
        default=None, ge=0, le=50,
        description="Minimum years. Use 0.5 for '6 luni'. Null if unstated.",
    )
    in_specialty: bool = Field(
        default=False,
        description="True for 'vechime în specialitate', false for general 'vechime în muncă'.",
    )
    domain: Optional[str] = Field(default=None, description="Field the experience must be in.")
    none_required: bool = Field(
        default=False, description="True when the posting explicitly says no experience is needed."
    )
    verbatim: Optional[str] = Field(default=None)


class Credential(BaseModel):
    """Licence, authorisation, certificate or clearance the role requires.

    Kept apart from skills because these are documents a candidate either holds
    or does not — the cheapest, hardest filter there is.
    """

    label: str = Field(description="e.g. 'permis categoria B', 'certificat OAMGMAMR', 'autorizație ISCIR'.")
    kind: Literal["permis_conducere", "certificat_profesional", "autorizatie", "aviz_medical",
                  "acces_informatii_clasificate", "altele"] = Field(default="altele")
    issuer: Optional[str] = Field(default=None, description="Issuing body if named.")
    required: bool = Field(default=True)


class ContractTerms(BaseModel):
    """How the work is organised."""

    duration: Optional[ContractDuration] = Field(default=None)
    duration_months: Optional[int] = Field(
        default=None, ge=1, description="Length when the contract is fixed-term."
    )
    schedule: Optional[WorkSchedule] = Field(default=None)
    hours_per_week: Optional[float] = Field(default=None, ge=1, le=80)
    remote_mode: Optional[RemoteMode] = Field(default=None)
    probation_months: Optional[int] = Field(default=None, ge=0, description="Perioadă de probă.")
    shift_work: bool = Field(default=False, description="Ture / gărzi / weekend work.")


class PositionRequirement(BaseModel):
    """One advertised role, with its own requirements.

    8.4% of active postings advertise several distinct roles at once — "1 post
    de expert IT senior; 2 posturi de expert administrație publică I", each with
    its own studies and tenure (3 years vs 7). A single flat extraction cannot
    hold two contradictory requirement sets, and the first v3 run showed what
    happens when it tries: the model returned null for education, skills,
    occupation and seniority rather than pick one, losing everything.
    """

    title: str = Field(description="Role title as advertised, without the count.")
    count: Optional[int] = Field(default=None, ge=1, description="How many seats for this role.")
    education: Optional[EducationRequirement] = Field(default=None)
    experience: Optional[ExperienceRequirement] = Field(default=None)
    skill_list: list[SkillRequirement] = Field(default_factory=list)
    credentials: list[Credential] = Field(default_factory=list)
    seniority_hint: Optional[str] = Field(default=None)


class JobPostingExtractionV3(JobPostingExtraction):
    """v3 = every v2 field, unchanged, plus the structured layer.

    Inheriting rather than replacing keeps the detail page, the feeds and the
    v2 back-compat renderer working on a v3 row without any change: the
    verbatim strings are still there, and the new fields are additive.
    """

    # --- structured requirements (the match keys) ---
    education: Optional[EducationRequirement] = Field(
        default=None, description="Structured form of educationRequirements."
    )
    experience: Optional[ExperienceRequirement] = Field(
        default=None, description="Structured form of experienceRequirements."
    )
    skill_list: list[SkillRequirement] = Field(
        default_factory=list, description="Normalised competence tags. Structured form of `skills`."
    )
    language_list: list[LanguageRequirement] = Field(
        default_factory=list, description="Language requirements with CEFR levels."
    )
    credentials: list[Credential] = Field(
        default_factory=list, description="Licences, authorisations, certificates, clearances."
    )
    contract: Optional[ContractTerms] = Field(
        default=None, description="Structured form of workHours plus contract terms."
    )

    # --- classification ---
    policy_domains: list[PolicyDomain] = Field(
        default_factory=list,
        description="Subject-matter domains, at most three, most specific first.",
    )
    occupation_title: Optional[str] = Field(
        default=None,
        description="Job title normalised to its common form, without grade, "
                    "count or department: 'Inspector de specialitate', 'Asistent medical generalist'.",
    )
    seniority_hint: Optional[Literal["debutant", "asistent", "practicant", "specialist",
                                     "principal", "superior", "conducere"]] = Field(
        default=None, description="Career stage the title or text implies."
    )

    #: Filled only when the posting advertises MORE THAN ONE distinct role.
    #: The flat fields above always describe the first/primary role, so a
    #: consumer that ignores `positions` still gets usable data.
    positions: list[PositionRequirement] = Field(
        default_factory=list,
        description="One entry per advertised role when the posting covers several. "
                    "Empty for an ordinary single-role posting.",
    )

    # --- selection process ---
    exam_stages: list[Literal["selectie_dosare", "proba_scrisa", "proba_practica",
                              "proba_sportiva", "interviu", "proba_orala", "test_psihologic"]] = Field(
        default_factory=list, description="Competition stages named in the posting."
    )
    bibliography_topics: list[str] = Field(
        default_factory=list,
        description="Subjects from the bibliografie/tematică, as short topic labels "
                    "('Codul administrativ', 'achiziții publice'), not full legal citations.",
    )


#: Prompt version → Pydantic model. `llm-schema.py` looks the model up here
#: rather than hard-coding one, so adding v4 is a one-line change.
EXTRACTION_MODELS: dict[str, type[BaseModel]] = {
    "v2": JobPostingExtraction,
    "v3": JobPostingExtractionV3,
}

#: Versions that use provider-native structured output. v1 is free-form JSON.
STRUCTURED_VERSIONS = frozenset(EXTRACTION_MODELS)


def model_for_version(prompt_version: str) -> type[BaseModel] | None:
    """Return the Pydantic model for a prompt version, or None for free-form."""
    return EXTRACTION_MODELS.get(prompt_version)


def json_schema_for(prompt_version: str) -> dict:
    """OpenAI strict `response_format` payload for a prompt version."""
    model = EXTRACTION_MODELS[prompt_version]
    schema = model.model_json_schema()
    _make_strict(schema)
    return {"name": model.__name__, "schema": schema, "strict": True}
