"""Developer/admin LLM-variant comparison tools.

The public browse/detail/stats/feed pages are served by the PHP app
(``webapp-php/``); the Django UI retired them (FIX-12). What stays here is the
prompt-version comparison dashboard and the per-posting variant matrix, which
have no PHP equivalent. ``/job/<pk>/variants/`` and ``/llm-variante/`` are
operator tools: link to them from the admin, not from the public site.
"""
import json
import re
import unicodedata
from typing import Literal

import markdown as md
from django.db.models import Avg, Count, Sum
from django.shortcuts import get_object_or_404, render

from apps.jobs.models import JobPosting, JobPostingSchemaVariant

# Ordered list of (schema_json key, Romanian display label).
# Covers both v2 keys (Schema.org-aligned: educationRequirements, baseSalary, etc.)
# and the legacy v1 keys (qualifications-bundled, salary as prose, work_conditions).
# Sections render in this order; absent keys are skipped silently, so a single
# list serves both shapes during the v1 → v2 transition.
_SCHEMA_SECTION_LABELS = [
    ("responsibilities",        "Atribuții principale"),
    ("educationRequirements",   "Studii"),                # v2
    ("experienceRequirements",  "Experiență"),            # v2
    ("qualifications",          "Condiții specifice"),    # v2 label; v1 used "Condiții de participare"
    ("skills",                  "Competențe"),
    ("application_docs",        "Dosar de candidatură"),
    ("baseSalary",              "Salarizare"),            # v2 structured
    ("salary",                  "Salarizare"),            # v1 prose (back-compat)
    ("application_fee",         "Taxă de participare"),
    ("application_contact",     "Contact pentru depunere"),  # v2 structured
    ("jobBenefits",             "Beneficii"),             # v2
    ("workHours",               "Program de lucru"),      # v2
    ("jobLocation",             "Locație"),               # v2
    ("work_conditions",         "Condiții de muncă"),     # v1 (back-compat)
]


def _render_base_salary(salary: dict) -> str:
    """Render v2 baseSalary `{minValue, maxValue, currency, unitText}` to a
    plain Romanian sentence. Returns "" when the dict is empty/null."""
    if not isinstance(salary, dict):
        return ""
    mn = salary.get("minValue")
    mx = salary.get("maxValue")
    currency = salary.get("currency") or "RON"
    unit = (salary.get("unitText") or "MONTH").lower()
    unit_ro = {"hour": "/oră", "day": "/zi", "week": "/săptămână", "month": "/lună", "year": "/an"}.get(unit, "")
    if mn is None and mx is None:
        return ""
    if mn is not None and mx is not None and mn != mx:
        return f"{mn:g}–{mx:g} {currency}{unit_ro}"
    return f"{(mn or mx):g} {currency}{unit_ro}"


def _render_application_fee(fee: dict) -> str:
    """Render v2 application_fee `{amount, currency, account, details}`."""
    if not isinstance(fee, dict):
        return ""
    parts: list[str] = []
    amount = fee.get("amount")
    currency = fee.get("currency") or "RON"
    if amount is not None:
        parts.append(f"{amount:g} {currency}")
    if fee.get("account"):
        parts.append(f"Cont: {fee['account']}")
    if fee.get("details"):
        parts.append(str(fee["details"]))
    return ". ".join(parts)


def _render_application_contact(contact: dict) -> str:
    """Render v2 application_contact `{name, phone, email, address}` to a
    markdown bullet list."""
    if not isinstance(contact, dict):
        return ""
    rows: list[str] = []
    if contact.get("name"):
        rows.append(f"- {contact['name']}")
    if contact.get("phone"):
        rows.append(f"- Telefon: {contact['phone']}")
    if contact.get("email"):
        rows.append(f"- Email: {contact['email']}")
    if contact.get("address"):
        rows.append(f"- Adresă: {contact['address']}")
    return "\n".join(rows)


# Per-key rendering hooks for keys whose value is a dict rather than a string.
# Hook returns a markdown string that the standard markdown pipeline then turns
# into HTML. Returning "" suppresses the section.
_STRUCTURED_RENDERERS = {
    "baseSalary":          _render_base_salary,
    "application_fee":     _render_application_fee,
    "application_contact": _render_application_contact,
}

# Sentinel for "null / empty" in section-status normalization.
_NULL = object()

PRODUCTION_PROMPT_VERSION = "v2"


def _normalize_section_value(value):
    """Normalize a section value for diff comparison.

    Returns the _NULL sentinel for None or "". For dicts, returns a
    canonical JSON string (sort_keys=True). For strings, applies NFKC
    normalization, lowercases, collapses whitespace, and strips leading
    bullet markers per line.
    """
    if value is None or value == "":
        return _NULL
    if isinstance(value, dict):
        return json.dumps(value, sort_keys=True, ensure_ascii=False)
    s = unicodedata.normalize("NFKC", str(value)).lower()
    result_lines = []
    for line in s.splitlines():
        line = re.sub(r"^-\s+", "", line)          # strip leading "- " bullets
        line = re.sub(r"\s+", " ", line).strip()   # collapse whitespace
        if line:
            result_lines.append(line)
    return re.sub(r"\s+", " ", " ".join(result_lines)).strip()


def _section_status(
    values: list, key: str
) -> Literal["agree", "partial", "diverge", "all_null"]:
    """Compute the agreement status for one matrix row.

    Args:
        values: raw section values from each included variant, in column order.
        key:    the schema_json key for this row (unused in logic; kept for
                signature compatibility with the spec).

    Returns:
        "all_null"  — every value normalizes to null/empty (row omitted).
        "agree"     — all non-null values normalize equal AND no nulls present.
        "partial"   — all non-null values normalize equal AND ≥1 null present.
        "diverge"   — ≥2 distinct non-null normalized values.
    """
    normalized = [_normalize_section_value(v) for v in values]
    non_null = [n for n in normalized if n is not _NULL]
    if not non_null:
        return "all_null"
    distinct_non_null = set(non_null)
    has_null = any(n is _NULL for n in normalized)
    if len(distinct_non_null) == 1 and not has_null:
        return "agree"
    if len(distinct_non_null) == 1 and has_null:
        return "partial"
    return "diverge"


# Fields we care about for quality scoring (v2 keys; v1 back-compat keys omitted)
_QUALITY_FIELDS = [
    "responsibilities", "educationRequirements", "experienceRequirements",
    "qualifications", "skills", "application_docs", "baseSalary",
    "application_contact", "jobBenefits", "workHours",
]


def _is_field_present(value) -> bool:
    """True when a schema_json field value is non-null and non-empty."""
    if value is None:
        return False
    if isinstance(value, str):
        return bool(value.strip())
    if isinstance(value, dict):
        return any(v not in (None, "", []) for v in value.values())
    return bool(value)


def _compute_variant_quality() -> dict:
    """Return quality stats keyed by (provider, model, prompt_version).

    Stats:
      completeness        – avg % of key fields filled across all runs
      field_fill_rates    – {field: pct} for each key field
      disagreement_rate   – for postings with ≥2 variants at same pv,
                            % of field-comparisons where this model is the minority
      solo_count          – times this model is the *only* one with a value
                            for a field (on multi-variant postings)
      comparable_postings – number of postings used for disagree/solo stats
    """
    all_variants = list(
        JobPostingSchemaVariant.objects.values(
            "id", "posting_id", "provider", "model", "prompt_version", "schema_json"
        )
    )
    if not all_variants:
        return {}

    # ----- completeness per model key -----
    from collections import defaultdict
    field_hits: dict[tuple, dict[str, int]] = defaultdict(lambda: defaultdict(int))
    model_counts: dict[tuple, int] = defaultdict(int)

    for v in all_variants:
        key = (v["provider"], v["model"], v["prompt_version"])
        model_counts[key] += 1
        sj = v["schema_json"] or {}
        for f in _QUALITY_FIELDS:
            if _is_field_present(sj.get(f)):
                field_hits[key][f] += 1

    # ----- disagreement + solo per model key -----
    # group variants by (posting_id, prompt_version)
    posting_groups: dict[tuple, list] = defaultdict(list)
    for v in all_variants:
        posting_groups[(v["posting_id"], v["prompt_version"])].append(v)

    disagree_checks: dict[tuple, int] = defaultdict(int)
    disagree_hits: dict[tuple, int] = defaultdict(int)
    solo_count: dict[tuple, int] = defaultdict(int)
    comparable_postings: dict[tuple, set] = defaultdict(set)

    for (posting_id, pv), group in posting_groups.items():
        if len(group) < 2:
            continue
        for f in _QUALITY_FIELDS:
            presences = {
                (g["provider"], g["model"], g["prompt_version"]): _is_field_present((g["schema_json"] or {}).get(f))
                for g in group
            }
            have_count = sum(presences.values())
            majority_has = have_count > len(group) / 2
            for mkey, has_value in presences.items():
                comparable_postings[mkey].add(posting_id)
                disagree_checks[mkey] += 1
                if has_value != majority_has:
                    disagree_hits[mkey] += 1
                if has_value and have_count == 1:
                    solo_count[mkey] += 1

    # ----- assemble results -----
    result = {}
    for key, total in model_counts.items():
        fills = field_hits[key]
        field_fill_rates = {
            f: round(100 * fills.get(f, 0) / total)
            for f in _QUALITY_FIELDS
        }
        completeness = round(sum(field_fill_rates.values()) / len(_QUALITY_FIELDS))
        checks = disagree_checks[key]
        disagreement_rate = round(100 * disagree_hits[key] / checks) if checks else None
        result[key] = {
            "completeness": completeness,
            "field_fill_rates": field_fill_rates,
            "disagreement_rate": disagreement_rate,
            "solo_count": solo_count.get(key, 0),
            "comparable_postings": len(comparable_postings.get(key, set())),
        }
    return result


def llm_variants_dashboard(request):
    """Per-(provider, model, prompt_version) leaderboard with cost/latency/quality stats."""
    rows = (
        JobPostingSchemaVariant.objects
        .values("provider", "model", "prompt_version")
        .annotate(
            count=Count("id"),
            avg_cost=Avg("cost_usd"),
            total_cost=Sum("cost_usd"),
            avg_latency=Avg("latency_ms"),
            avg_input=Avg("input_tokens"),
            avg_output=Avg("output_tokens"),
        )
        .order_by("prompt_version", "provider", "model")
    )

    total_variants = JobPostingSchemaVariant.objects.count()
    total_postings_with_variants = (
        JobPostingSchemaVariant.objects.values("posting_id").distinct().count()
    )

    quality = _compute_variant_quality()

    leaderboard = []
    for r in rows:
        avg_cost = float(r["avg_cost"] or 0)
        total_cost = float(r["total_cost"] or 0)
        key = (r["provider"], r["model"], r["prompt_version"])
        q = quality.get(key, {})
        leaderboard.append({
            **r,
            "cost_per_1k": f"{avg_cost * 1000:.4f}" if avg_cost else "—",
            "total_cost_display": f"{total_cost:.4f}" if total_cost else "—",
            "avg_latency_display": f"{int(r['avg_latency'] or 0):,}" if r["avg_latency"] else "—",
            "avg_input_display": f"{int(r['avg_input'] or 0):,}" if r["avg_input"] else "—",
            "avg_output_display": f"{int(r['avg_output'] or 0):,}" if r["avg_output"] else "—",
            "completeness": q.get("completeness"),
            "field_fill_rates": q.get("field_fill_rates", {}),
            "disagreement_rate": q.get("disagreement_rate"),
            "solo_count": q.get("solo_count", 0),
            "comparable_postings": q.get("comparable_postings", 0),
        })

    return render(request, "jobs/llm_variants.html", {
        "leaderboard": leaderboard,
        "total_variants": total_variants,
        "total_postings_with_variants": total_postings_with_variants,
        "quality_fields": _QUALITY_FIELDS,
    })


def variant_comparison(request, pk):
    """Field × variant matrix for LLM schema comparison."""
    posting = get_object_or_404(
        JobPosting.objects.select_related("employer", "judet"), pk=pk
    )
    all_variants = list(posting.schema_variants.all())

    # --- Parse toolbar filters from URL query string ---
    prompt_filter = request.GET.getlist("prompt") or [PRODUCTION_PROMPT_VERSION]
    providers_filter = request.GET.getlist("providers")  # empty = all providers
    view_mode = request.GET.get("view", "rendered")

    # --- Filter ---
    included = [v for v in all_variants if v.prompt_version in prompt_filter]
    if providers_filter:
        included = [v for v in included if v.provider in providers_filter]

    # --- Stable column order: (provider, model, prompt_version) ---
    included.sort(key=lambda v: (v.provider, v.model, v.prompt_version))

    # --- Toolbar option sets ---
    all_providers = sorted({v.provider for v in all_variants})
    all_prompts = sorted({v.prompt_version for v in all_variants})

    # --- Min/max for metadata strip highlighting ---
    costs = [v.cost_usd for v in included if v.cost_usd is not None]
    latencies = [v.latency_ms for v in included if v.latency_ms is not None]
    min_cost = min(costs) if costs else None
    max_cost = max(costs) if costs else None
    min_latency = min(latencies) if latencies else None
    max_latency = max(latencies) if latencies else None

    # --- Build comparison matrix ---
    matrix = []
    for key, label in _SCHEMA_SECTION_LABELS:
        if not included:
            break
        values = [
            (v.schema_json.get(key) if isinstance(v.schema_json, dict) else None)
            for v in included
        ]
        status = _section_status(values, key)
        if status == "all_null":
            continue

        cells = []
        for value in values:
            if value is None or value == "":
                html = ""
                raw_json = "null"
            elif isinstance(value, dict) and key in _STRUCTURED_RENDERERS:
                rendered_text = _STRUCTURED_RENDERERS[key](value)
                html = md.markdown(rendered_text, extensions=["nl2br"]) if rendered_text.strip() else ""
                raw_json = json.dumps(value, indent=2, ensure_ascii=False)
            else:
                html = md.markdown(str(value), extensions=["nl2br"])
                raw_json = json.dumps(str(value), ensure_ascii=False)
            cells.append({"html": html, "raw_json": raw_json})

        # merged_html used in diff-only mode for agree rows
        merged_html = ""
        if status == "agree":
            for cell in cells:
                if cell["html"]:
                    merged_html = cell["html"]
                    break

        matrix.append({
            "key": key,
            "label": label,
            "status": status,
            "cells": cells,
            "merged_html": merged_html,
        })

    ctx = {
        "posting": posting,
        "included_variants": included,
        "matrix": matrix,
        "view_mode": view_mode,
        "prompt_filter": prompt_filter,
        "providers_filter": providers_filter,
        "all_providers": all_providers,
        "all_prompts": all_prompts,
        "variant_count": len(included),
        "min_cost": min_cost,
        "max_cost": max_cost,
        "min_latency": min_latency,
        "max_latency": max_latency,
    }

    if request.htmx:
        return render(request, "jobs/partials/_variant_matrix.html", ctx)
    return render(request, "jobs/variant_comparison.html", ctx)
