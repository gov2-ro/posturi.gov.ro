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

import re
import unicodedata
from typing import Annotated, ClassVar, Literal, Optional

from pydantic import BaseModel, BeforeValidator, Field, model_validator


#: A boolean that tolerates an explicit `null` from the model.
#:
#: Rule 1 of every prompt since v2 is "use `null` for anything not explicitly
#: stated", so a model that cannot tell whether a post involves shift work emits
#: `"shift_work": null` — doing exactly as it was told. A bare `bool` rejects
#: that, and the rejection costs a full repair round-trip (a second copy of a
#: ~5k-token system prompt) on a fifth of all postings. Falling back to the
#: field's own default is both cheaper and what "not stated" already means here.
NullableBool = Annotated[bool, BeforeValidator(lambda v: False if v is None else v)]
NullableTrueBool = Annotated[bool, BeforeValidator(lambda v: True if v is None else v)]


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
    required: NullableTrueBool = Field(
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
    required: NullableTrueBool = Field(default=True, description="False for 'constituie un avantaj'.")
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
    required: NullableTrueBool = Field(default=True)

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
    in_specialty: NullableBool = Field(
        default=False,
        description="True for 'vechime în specialitate', false for general 'vechime în muncă'.",
    )
    domain: Optional[str] = Field(default=None, description="Field the experience must be in.")
    none_required: NullableBool = Field(
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
    required: NullableTrueBool = Field(default=True)


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
    shift_work: NullableBool = Field(default=False, description="Ture / gărzi / weekend work.")


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


# ---------------------------------------------------------------------------
# Prompt v4 — what v3 leaves on the table
# ---------------------------------------------------------------------------
#
# v4 is a strict superset of v3, exactly as v3 is of v2. `education` MUST stay a
# top-level key: `export-to-sqlite.py::_v3_columns` detects a structured payload
# with `"education" in s`, so nesting or renaming it silently empties every v3
# facet on the site.

#: Competition timeline stages. `data/calendar.csv` currently holds 19,335 rows
#: whose *event names* include 863 bare en-dashes, 512 bullets and 186 empty
#: strings — the table parser transcribing layout instead of content. A closed
#: vocabulary is what makes the timeline queryable, and what lets the expiry
#: date be the application deadline rather than the last row of the table.
CalendarStage = Literal[
    "publicare",
    "depunere_dosare",
    "selectie_dosare",
    "contestatii_dosare",
    "proba_scrisa",
    "rezultate_proba_scrisa",
    "contestatii_proba_scrisa",
    "proba_practica",
    "rezultate_proba_practica",
    "proba_sportiva",
    "interviu",
    "rezultate_interviu",
    "contestatii_interviu",
    "test_psihologic",
    "rezultate_finale",
    "altele",
]

FundingSource = Literal[
    "buget_stat", "buget_local", "venituri_proprii",
    "fonduri_europene", "mixt", "nespecificat",
]

#: Which part of the state the employer belongs to. Coarser than the occupation
#: and independent of it: a driver at a hospital works in `sanatate`.
EmployerSector = Literal[
    "administratie_locala", "administratie_centrala", "sanatate", "educatie",
    "cultura", "aparare", "ordine_publica", "justitie", "asistenta_sociala",
    "cercetare", "transport", "mediu", "agricultura", "altele",
]


class CalendarEntry(BaseModel):
    """One dated step of the competition."""

    stage: CalendarStage = Field(description="Which step this is.")
    date: Optional[str] = Field(
        default=None, description="ISO date, YYYY-MM-DD. Null if the posting gives none.")
    time: Optional[str] = Field(default=None, description="HH:MM, 24-hour, when stated.")
    verbatim: Optional[str] = Field(
        default=None,
        description="The row label as written, trimmed to the event itself — "
                    "not the address or instructions that share the cell.")


class CompetitionCalendar(BaseModel):
    """The competition timeline, with the application deadline pulled out.

    The deadline is a separate field on purpose. It is the only date that
    decides whether a reader can still apply, and it is NOT the last row of the
    calendar table — that is the final-results date, weeks later. Treating the
    table's last row as the expiry silently advertises closed competitions.
    """

    application_deadline: Optional[str] = Field(
        default=None,
        description="ISO date by which the dosar must be submitted — the "
                    "'data limită de depunere a dosarelor' row, never the last row.")
    application_deadline_time: Optional[str] = Field(default=None, description="HH:MM if stated.")
    events: list[CalendarEntry] = Field(default_factory=list)

    #: A calendar longer than this is a parsing artefact, not a competition.
    MAX_EVENTS: ClassVar[int] = 24

    @model_validator(mode="after")
    def _derive_deadline(self):
        """Fall back to the `depunere_dosare` event when the deadline is absent.

        The deadline is the one date that decides whether a reader can still
        apply, and the model sometimes files it only as an event. Deriving it is
        strictly better than leaving it null and letting a consumer reach for
        the last row of the table, which is the final-results date.
        """
        if len(self.events) > self.MAX_EVENTS:
            object.__setattr__(self, "events", self.events[: self.MAX_EVENTS])
        if self.application_deadline:
            return self
        dated = [e for e in self.events if e.stage == "depunere_dosare" and e.date]
        if dated:
            # Latest, not earliest: postings state a submission *window* and it
            # is the closing date that matters.
            last = max(dated, key=lambda e: e.date)
            object.__setattr__(self, "application_deadline", last.date)
            if last.time and not self.application_deadline_time:
                object.__setattr__(self, "application_deadline_time", last.time)
        return self


class Funding(BaseModel):
    """Where the post's money comes from."""

    source: FundingSource = Field(default="nespecificat")
    programme: Optional[str] = Field(
        default=None, description="Funding programme, e.g. 'PNRR', 'POCU', 'POEO', 'Interreg'.")
    project_code: Optional[str] = Field(
        default=None, description="Project or contract identifier as written.")
    project_name: Optional[str] = Field(default=None, description="Project title, if named.")


class EmployerContext(BaseModel):
    """Who the employer actually is, beyond the name on the announcement.

    Two things depend on this. `parent_institution` resolves
    'Unitatea Militară 01042 Curtea de Argeș' to Ministerul Apărării Naționale,
    which is how a reader finds every MApN post. And `uat_type`/`uat_name` feed
    the population band that the salary estimate needs — Anexa VIII pays local
    posts on four sheets and the spread across them is over 30%.
    """

    sector: Optional[EmployerSector] = Field(default=None)
    parent_institution: Optional[str] = Field(
        default=None,
        description="The ministry or authority the employer answers to, when it "
                    "can be told from the posting. Null rather than guessed.")
    uat_type: Optional[Literal["comuna", "oras", "municipiu", "sector", "judet"]] = Field(
        default=None, description="Kind of administrative unit, for a local employer.")
    uat_name: Optional[str] = Field(
        default=None, description="Name of that unit, e.g. 'Ciugud', 'Cluj-Napoca'.")


class JobPostingExtractionV4(JobPostingExtractionV3):
    """v4 = every v3 field, unchanged, plus four the site could not answer without.

    Deliberately small. The v3 system prompt is already ~5k tokens and its JSON
    Schema 19.2 KB, which is the real cost driver on the structured-output path,
    and the configured provider has no server-side schema enforcement — so every
    field added here has to earn its place.
    """

    competition_calendar: Optional[CompetitionCalendar] = Field(
        default=None, description="Typed competition timeline and the real application deadline.")
    funding: Optional[Funding] = Field(
        default=None, description="Budget line or EU programme funding the post.")
    employer_context: Optional[EmployerContext] = Field(
        default=None, description="Sector, parent institution and administrative unit.")
    note_suplimentare: Optional[str] = Field(
        default=None,
        description="Anything role-specific of real use to a candidate that none "
                    "of the other fields captured. Markdown bullets. Null when "
                    "the structured fields already cover the posting.")


# ---------------------------------------------------------------------------
# Occupation normalisation (the title-dictionary pass, `normalize-titles.py`)
# ---------------------------------------------------------------------------
#
# This is a different shape of job from the extraction models above: the input
# is a job *title*, not a posting body, and the unit of work is the distinct
# title (3,723 of them across 9,757 postings) rather than the posting. Running
# it separately keeps the v3 body prompt untouched and makes the mapping
# re-runnable when the salary grid changes without paying for extraction again.
#
# The model picks from shortlists of real COR occupations and real grid rows
# supplied in the user message, so it selects rather than invents. Anything
# derivable is derived afterwards and overwrites whatever the model said --
# the same rule `_derive_eqf_level` and `_derive_iso_code` already apply.

MatchConfidence = Literal["exact", "probabil", "incert", "none"]

#: How a post is employed, which decides which half of Anexa VIII applies.
Regim = Literal["functionar_public", "personal_contractual", "militar", "demnitate"]

#: Where in the administrative hierarchy the employer sits.
NivelAdministrativ = Literal["central", "teritorial", "local", "specializat", "transversal"]


class GridSelector(BaseModel):
    """Which row of the draft salary grid this occupation is paid on.

    Deliberately NOT a salary. The law is an unadopted draft with more than one
    public variant, so a number baked in here would have to be re-extracted
    every time the draft moves; a selector is re-costed by a script in a second.
    """

    anexa: Optional[Literal["I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX"]] = Field(
        default=None, description="Annex of the draft law this occupation is paid under.")
    cod: Optional[str] = Field(
        default=None,
        description="Grid function code (e.g. '82.60128002.07.3') copied verbatim "
                    "from one of the supplied candidates. Null if none fits.")
    functie_grila: Optional[str] = Field(
        default=None,
        description="Grid function name, copied verbatim from a supplied candidate.")
    regim: Optional[Regim] = Field(default=None)
    tip_post: Optional[Literal["executie", "conducere"]] = Field(default=None)
    nivel_administrativ: Optional[NivelAdministrativ] = Field(default=None)


class OccupationMapping(BaseModel):
    """One normalised occupation: canonical title, COR code, grid selector."""

    occupation_canonical: str = Field(
        description="The occupation in its common singular form, without grade, "
                    "count, department or post number: 'Îngrijitor', "
                    "'Asistent medical generalist', 'Referent de specialitate'.")
    cor_code: Optional[str] = Field(
        default=None, pattern=r"^\d{6}$",
        description="6-digit COR code, copied verbatim from a supplied candidate. "
                    "Null when none of them is the same occupation.")
    cor_label: Optional[str] = Field(
        default=None,
        description="Ignored on input — the caller fills it from the COR table.")
    isco_group: Optional[str] = Field(
        default=None, description="Ignored on input — derived from cor_code[:4].")
    grade_token: Optional[str] = Field(
        default=None,
        description="Professional grade as the grid spells it ('gradul II', "
                    "'debutant', 'treapta I'). Null when the title states none.")
    study_level: Optional[StudyLevel] = Field(
        default=None, description="Lowest study level the occupation implies.")
    # No bounds: the value is always overwritten from `study_level`, so a
    # nonsense number from the model must not fail validation and burn a repair.
    eqf_level: Optional[int] = Field(
        default=None, description="Ignored on input — derived from study_level.")
    grid_selector: Optional[GridSelector] = Field(default=None)
    match_confidence: MatchConfidence = Field(
        default="incert",
        description="'exact' when a candidate names this same occupation, "
                    "'probabil' for a close relative, 'incert' for a guess, "
                    "'none' when nothing supplied fits.")

    @model_validator(mode="after")
    def _derive(self):
        """Never trust the model for anything that can be computed.

        `cor_label` and `isco_group` follow from `cor_code`, and `eqf_level`
        from `study_level`. Asking for them is useful -- it makes the model
        commit -- but the derived value always wins.
        """
        # `cor_label` is always cleared: only the COR table may set it, and
        # this module stays dependency-free so `normalize-titles.py` fills it in.
        object.__setattr__(self, "cor_label", None)
        object.__setattr__(
            self, "isco_group", self.cor_code[:4] if self.cor_code else None
        )
        if self.study_level is not None:
            object.__setattr__(self, "eqf_level", STUDY_LEVEL_TO_EQF[self.study_level])
        return self


#: Prompt version → Pydantic model. `llm-schema.py` looks the model up here
#: rather than hard-coding one, so adding v4 is a one-line change.
EXTRACTION_MODELS: dict[str, type[BaseModel]] = {
    "v2": JobPostingExtraction,
    "v3": JobPostingExtractionV3,
    "v4": JobPostingExtractionV4,
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
