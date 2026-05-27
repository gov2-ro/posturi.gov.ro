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

from typing import Literal, Optional

from pydantic import BaseModel, Field


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
