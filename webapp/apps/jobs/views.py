import json
import re
import unicodedata
from datetime import date, datetime, timedelta, time
from datetime import timezone as dt_timezone
from typing import Literal

import markdown as md
import nh3
from django.contrib.postgres.search import SearchQuery, SearchRank
from django.contrib.syndication.views import Feed
from django.core.paginator import Paginator
from django.db.models import Avg, Count, Exists, ExpressionWrapper, F, OuterRef, Q, Subquery, Sum
from django.db.models.functions import TruncMonth
from django.http import HttpResponse, JsonResponse
from django.shortcuts import get_object_or_404, render
from django.utils.feedgenerator import Atom1Feed
from icalendar import Calendar, Event

from apps.jobs.models import Employer, JobPosting, JobPostingSchemaVariant, Judet

FTS_CONFIG = "romanian_unaccent"

# Safe HTML tags/attrs produced by markdown rendering (no iframes, scripts, etc.)
_ALLOWED_TAGS = {
    "p", "br", "ul", "ol", "li", "strong", "b", "em", "i",
    "h1", "h2", "h3", "h4", "h5", "h6",
    "table", "thead", "tbody", "tr", "th", "td",
    "blockquote", "pre", "code", "a",
}
_ALLOWED_ATTRS = {"a": {"href", "title"}, "td": {"colspan", "rowspan"}, "th": {"colspan", "rowspan"}}


def _sanitize(html: str) -> str:
    return nh3.clean(html, tags=_ALLOWED_TAGS, attributes=_ALLOWED_ATTRS)
PAGE_SIZE = 25

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


def _render_schema_sections(schema_json: dict) -> list[dict] | None:
    """Convert schema_json dict to a list of {label, html} dicts for the template.

    Sections whose value is None or empty string are omitted.
    Returns None if no sections have content (template falls back to body_html).
    Handles both v1 (flat strings) and v2 (mixed strings + structured dicts) shapes.
    """
    sections = []
    for key, label in _SCHEMA_SECTION_LABELS:
        value = schema_json.get(key)
        if value is None:
            continue
        # v1 used plain strings for some keys that v2 stores as dicts (e.g.
        # `application_fee`). Dispatch only when the value is actually a dict;
        # otherwise treat as markdown for back-compat.
        if isinstance(value, dict) and key in _STRUCTURED_RENDERERS:
            rendered = _STRUCTURED_RENDERERS[key](value)
        else:
            rendered = str(value)
        if not rendered.strip():
            continue
        html = _sanitize(md.markdown(rendered, extensions=["nl2br"]))
        sections.append({"label": label, "html": html})
    return sections or None


# Salary bucket definitions: (label, min_value, max_value_exclusive)
_SALARY_BUCKETS = [
    ("sub-3000",    0,    3000),
    ("3000-4000",   3000, 4000),
    ("4000-5000",   4000, 5000),
    ("5000-7000",   5000, 7000),
    ("peste-7000",  7000, None),
]

# Experience bucket definitions: (label, min_years, max_years_exclusive)
_EXP_BUCKETS = [
    ("fara",  0,  1),
    ("1-2",   1,  3),
    ("3-5",   3,  6),
    ("5plus", 5,  None),
]


def _apply_filters(
    qs, *, q, judet_slugs, levels, types, categories, employer_cats,
    expires_before, expires_after, families, seniorities, anomaly_flags=None,
    work_types=None, remote=None, computer=None, exp_levels=None,
    studies_levels=None, salary_bucket=None,
    dev_schema=None, dev_inferred=None, dev_variants=None,
):
    if q:
        qs = qs.filter(search_vector=SearchQuery(q, config=FTS_CONFIG, search_type="plain"))
    if judet_slugs:
        qs = qs.filter(judet__slug__in=judet_slugs)
    if levels:
        qs = qs.filter(job_level__in=levels)
    if types:
        qs = qs.filter(job_type__in=types)
    if categories:
        qs = qs.filter(categorie__in=categories)
    if employer_cats:
        qs = qs.filter(employer_category__in=employer_cats)
    if expires_before:
        try:
            qs = qs.filter(expires_at__lte=date.fromisoformat(expires_before))
        except (ValueError, AttributeError):
            pass
    if expires_after:
        try:
            qs = qs.filter(expires_at__gte=date.fromisoformat(expires_after))
        except (ValueError, AttributeError):
            pass
    if families:
        qs = qs.filter(inferred__profession_family__in=families)
    if seniorities:
        qs = qs.filter(inferred__seniority__in=seniorities)
    if anomaly_flags:
        for flag in anomaly_flags:
            qs = qs.filter(inferred__anomaly_flags__contains=[flag])
    if work_types:
        qs = qs.filter(inferred__work_type__in=work_types)
    if remote:
        qs = qs.filter(inferred__remote_eligible=True)
    if computer == "solicitat":
        qs = qs.filter(inferred__requires_computer=True)
    elif computer == "nesolicitat":
        qs = qs.exclude(inferred__requires_computer=True)
    if exp_levels:
        exp_q = None
        for bucket_key in exp_levels:
            for label, lo, hi in _EXP_BUCKETS:
                if label == bucket_key:
                    if hi is None:
                        clause = Q(inferred__experience_years__gte=lo)
                    elif lo == 0:
                        clause = Q(inferred__experience_years__isnull=True) | Q(inferred__experience_years__lt=hi)
                    else:
                        clause = Q(inferred__experience_years__gte=lo, inferred__experience_years__lt=hi)
                    exp_q = clause if exp_q is None else exp_q | clause
        if exp_q:
            qs = qs.filter(exp_q)
    if studies_levels:
        qs = qs.filter(inferred__studies_required__in=studies_levels)
    if salary_bucket:
        for label, lo, hi in _SALARY_BUCKETS:
            if label == salary_bucket:
                qs = qs.filter(inferred__salary_min__isnull=False, inferred__salary_min__gte=lo)
                if hi is not None:
                    qs = qs.filter(inferred__salary_min__lt=hi)
                break
    if dev_schema == "yes":
        qs = qs.filter(schema_json__isnull=False)
    elif dev_schema == "no":
        qs = qs.filter(schema_json__isnull=True)
    if dev_inferred == "yes":
        qs = qs.exclude(Q(inferred={}) | Q(inferred__isnull=True))
    elif dev_inferred == "no":
        qs = qs.filter(Q(inferred={}) | Q(inferred__isnull=True))
    _has_variant = Exists(JobPostingSchemaVariant.objects.filter(posting=OuterRef("pk")))
    _variant_count = JobPostingSchemaVariant.objects.filter(posting=OuterRef("pk")).values("posting").annotate(n=Count("id")).values("n")
    if dev_variants == "yes":
        qs = qs.annotate(_vc=Subquery(_variant_count)).filter(_vc__gte=2)
    elif dev_variants == "no":
        qs = qs.exclude(_has_variant)
    return qs


def job_list(request):
    q = request.GET.get("q", "").strip()
    judet_slugs = request.GET.getlist("judet")
    levels = request.GET.getlist("level")
    types = request.GET.getlist("type")
    categories = request.GET.getlist("categorie")
    employer_cats = request.GET.getlist("employer_cat")
    expires_before = request.GET.get("expires_before", "")
    expires_after = request.GET.get("expires_after", "")
    families = request.GET.getlist("family")
    seniorities = request.GET.getlist("seniority")
    anomaly_flags = request.GET.getlist("anomaly")
    sort = request.GET.get("sort", "")
    work_types = request.GET.getlist("work_type")
    remote = request.GET.get("remote", "")
    computer = request.GET.get("computer", "")
    exp_levels = request.GET.getlist("exp_level")
    studies_levels = request.GET.getlist("studies_level")
    salary_bucket = request.GET.get("salary_bucket", "")
    dev_schema = request.GET.get("dev_schema", "")
    dev_inferred = request.GET.get("dev_inferred", "")
    dev_variants = request.GET.get("dev_variants", "")

    filter_kwargs = dict(
        q=q,
        judet_slugs=judet_slugs,
        levels=levels,
        types=types,
        categories=categories,
        employer_cats=employer_cats,
        expires_before=expires_before,
        expires_after=expires_after,
        families=families,
        seniorities=seniorities,
        anomaly_flags=anomaly_flags,
        work_types=work_types,
        remote=remote,
        computer=computer,
        exp_levels=exp_levels,
        studies_levels=studies_levels,
        salary_bucket=salary_bucket,
        dev_schema=dev_schema,
        dev_inferred=dev_inferred,
        dev_variants=dev_variants,
    )

    base_qs = JobPosting.objects.all()
    qs = _apply_filters(base_qs.select_related("employer", "judet"), **filter_kwargs)

    if q and not sort:
        query_obj = SearchQuery(q, config=FTS_CONFIG, search_type="plain")
        qs = qs.annotate(rank=SearchRank("search_vector", query_obj)).order_by("-rank", "-published_at")
    elif sort == "deadline":
        qs = qs.order_by("expires_at", "-published_at")
    elif sort == "employer":
        qs = qs.order_by("employer__name", "-published_at")
    else:
        qs = qs.order_by("-published_at")

    def facet_qs(exclude_key):
        overrides = {exclude_key: []} if exclude_key in filter_kwargs else {}
        return _apply_filters(base_qs, **{**filter_kwargs, **overrides})

    judet_options = [
        {"value": x["judet__slug"], "label": x["judet__name"], "count": x["count"]}
        for x in facet_qs("judet_slugs")
        .values("judet__name", "judet__slug")
        .annotate(count=Count("id"))
        .filter(judet__isnull=False)
        .order_by("-count")[:25]
    ]
    level_options = [
        {"value": x["job_level"], "label": x["job_level"], "count": x["count"]}
        for x in facet_qs("levels")
        .values("job_level")
        .annotate(count=Count("id"))
        .exclude(job_level="")
        .order_by("-count")
    ]
    type_options = [
        {"value": x["job_type"], "label": x["job_type"], "count": x["count"]}
        for x in facet_qs("types")
        .values("job_type")
        .annotate(count=Count("id"))
        .exclude(job_type="")
        .order_by("-count")
    ]
    categorie_options = [
        {"value": x["categorie"], "label": x["categorie"], "count": x["count"]}
        for x in facet_qs("categories")
        .values("categorie")
        .annotate(count=Count("id"))
        .exclude(categorie="")
        .order_by("-count")
    ]
    employer_cat_options = [
        {"value": x["employer_category"], "label": x["employer_category"], "count": x["count"]}
        for x in facet_qs("employer_cats")
        .values("employer_category")
        .annotate(count=Count("id"))
        .exclude(employer_category="")
        .order_by("-count")[:20]
    ]
    family_options = [
        {"value": x["inferred__profession_family"], "label": x["inferred__profession_family"], "count": x["count"]}
        for x in facet_qs("families")
        .values("inferred__profession_family")
        .annotate(count=Count("id"))
        .exclude(inferred__profession_family=None)
        .exclude(inferred__profession_family="altele")
        .order_by("-count")
    ]
    seniority_options = [
        {"value": x["inferred__seniority"], "label": x["inferred__seniority"], "count": x["count"]}
        for x in facet_qs("seniorities")
        .values("inferred__seniority")
        .annotate(count=Count("id"))
        .exclude(inferred__seniority=None)
        .order_by("-count")
    ]

    _WORK_TYPE_LABELS = {
        "norma_intreaga": "Normă întreagă",
        "norma_partiala": "Normă parțială",
        "schimburi": "Schimburi",
    }
    work_type_options = [
        {"value": x["inferred__work_type"], "label": _WORK_TYPE_LABELS.get(x["inferred__work_type"], x["inferred__work_type"]), "count": x["count"]}
        for x in facet_qs("work_types")
        .values("inferred__work_type")
        .annotate(count=Count("id"))
        .exclude(inferred__work_type=None)
        .order_by("-count")
    ]

    _EXP_LABELS = {"fara": "Fără experiență", "1-2": "1–2 ani", "3-5": "3–5 ani", "5plus": "5+ ani"}
    exp_level_options = []
    _exp_facet_qs = facet_qs("exp_levels")
    for bucket_key, lo, hi in _EXP_BUCKETS:
        if hi is None:
            count = _exp_facet_qs.filter(inferred__experience_years__gte=lo).count()
        elif lo == 0:
            count = _exp_facet_qs.filter(
                Q(inferred__experience_years__isnull=True) | Q(inferred__experience_years__lt=hi)
            ).count()
        else:
            count = _exp_facet_qs.filter(
                inferred__experience_years__gte=lo, inferred__experience_years__lt=hi
            ).count()
        if count:
            exp_level_options.append({"value": bucket_key, "label": _EXP_LABELS[bucket_key], "count": count})

    _STUDIES_LABELS = {
        "doctorat": "Doctorat",
        "master": "Master / Magistru",
        "licenta": "Licență",
        "postliceala": "Postliceală",
        "liceala": "Liceală",
        "generala": "Generală",
    }
    studies_level_options = [
        {"value": x["inferred__studies_required"], "label": _STUDIES_LABELS.get(x["inferred__studies_required"], x["inferred__studies_required"]), "count": x["count"]}
        for x in facet_qs("studies_levels")
        .values("inferred__studies_required")
        .annotate(count=Count("id"))
        .exclude(inferred__studies_required=None)
        .order_by("-count")
    ]

    _computer_facet_qs = facet_qs("computer")
    computer_options = []
    solicitat_count = _computer_facet_qs.filter(inferred__requires_computer=True).count()
    nesolicitat_count = _computer_facet_qs.exclude(inferred__requires_computer=True).count()
    if solicitat_count:
        computer_options.append({"value": "solicitat", "label": "Solicitat", "count": solicitat_count})
    if nesolicitat_count:
        computer_options.append({"value": "nesolicitat", "label": "Nesolicitat", "count": nesolicitat_count})

    remote_count = facet_qs("remote").filter(inferred__remote_eligible=True).count()

    # Salary facet — only show if enough postings have salary data
    salary_options = []
    salary_with_data = JobPosting.objects.filter(inferred__salary_min__isnull=False).count()
    if salary_with_data >= 100:
        _salary_facet_qs = facet_qs("salary_bucket")
        for label, lo, hi in _SALARY_BUCKETS:
            q_filter = Q(inferred__salary_min__isnull=False, inferred__salary_min__gte=lo)
            if hi is not None:
                q_filter &= Q(inferred__salary_min__lt=hi)
            count = _salary_facet_qs.filter(q_filter).count()
            if count:
                salary_options.append({"value": label, "label": label.replace("-", " – ").replace("sub", "sub ").replace("peste", "peste "), "count": count})

    paginator = Paginator(qs, PAGE_SIZE)
    page_obj = paginator.get_page(request.GET.get("page", 1))

    page_num = int(request.GET.get("page", 1))
    active_filters = [q, judet_slugs, levels, types, categories, employer_cats,
                      expires_before, expires_after, families, seniorities,
                      anomaly_flags, work_types, remote, computer, exp_levels,
                      studies_levels, salary_bucket, dev_schema, dev_inferred, dev_variants]
    is_unfiltered = page_num == 1 and not any(active_filters)
    quick_stats = _build_quick_stats() if is_unfiltered else None

    ctx = {
        "page_obj": page_obj,
        "total_count": paginator.count,
        "q": q,
        "judet_slugs": judet_slugs,
        "levels": levels,
        "types": types,
        "categories": categories,
        "employer_cats": employer_cats,
        "expires_before": expires_before,
        "expires_after": expires_after,
        "families": families,
        "seniorities": seniorities,
        "anomaly_flags": anomaly_flags,
        "sort": sort,
        "work_types": work_types,
        "remote": remote,
        "computer": computer,
        "exp_levels": exp_levels,
        "studies_levels": studies_levels,
        "salary_bucket": salary_bucket,
        "dev_schema": dev_schema,
        "dev_inferred": dev_inferred,
        "dev_variants": dev_variants,
        "dev_filters": [
            ("dev_schema",   "Schema JSON",      dev_schema),
            ("dev_inferred", "Inferred meta",    dev_inferred),
            ("dev_variants", "LLM variants",     dev_variants),
        ],
        "dev_filter_options": [("", "—"), ("yes", "Da"), ("no", "Nu")],
        "judet_options": judet_options,
        "level_options": level_options,
        "type_options": type_options,
        "categorie_options": categorie_options,
        "employer_cat_options": employer_cat_options,
        "family_options": family_options,
        "seniority_options": seniority_options,
        "work_type_options": work_type_options,
        "exp_level_options": exp_level_options,
        "studies_level_options": studies_level_options,
        "computer_options": computer_options,
        "remote_count": remote_count,
        "salary_options": salary_options,
        "anomaly_choices": [
            ("short_deadline", "Termen scurt"),
            ("missing_contact", "Contact lipsă"),
            ("contact_in_attachment", "Contact doar în atașament"),
            ("gender_criteria", "Criteriu de gen"),
            ("no_body", "Fără corp"),
            ("frequent_repost", "Re-publicare frecventă"),
        ],
        "today": date.today(),
        "is_unfiltered": is_unfiltered,
        "quick_stats": quick_stats,
    }

    if request.htmx:
        return render(request, "jobs/partials/result_list.html", ctx)
    return render(request, "jobs/list.html", ctx)


def _filter_kwargs_from_request(request):
    return dict(
        q=request.GET.get("q", "").strip(),
        judet_slugs=request.GET.getlist("judet"),
        levels=request.GET.getlist("level"),
        types=request.GET.getlist("type"),
        categories=request.GET.getlist("categorie"),
        employer_cats=request.GET.getlist("employer_cat"),
        expires_before=request.GET.get("expires_before", ""),
        expires_after=request.GET.get("expires_after", ""),
        families=request.GET.getlist("family"),
        seniorities=request.GET.getlist("seniority"),
        anomaly_flags=request.GET.getlist("anomaly"),
        work_types=request.GET.getlist("work_type"),
        remote=request.GET.get("remote", ""),
        computer=request.GET.get("computer", ""),
        exp_levels=request.GET.getlist("exp_level"),
        studies_levels=request.GET.getlist("studies_level"),
        salary_bucket=request.GET.get("salary_bucket", ""),
        dev_schema=request.GET.get("dev_schema", ""),
        dev_inferred=request.GET.get("dev_inferred", ""),
        dev_variants=request.GET.get("dev_variants", ""),
    )


def job_json(request):
    filter_kwargs = _filter_kwargs_from_request(request)
    qs = _apply_filters(
        JobPosting.objects.select_related("employer", "judet"),
        **filter_kwargs,
    ).order_by("-published_at")[:200]

    results = []
    for p in qs:
        inferred = p.inferred or {}
        results.append({
            "id": p.pk,
            "title": p.title,
            "url": p.url,
            "employer": p.employer.name if p.employer else None,
            "judet": p.judet.name if p.judet else None,
            "published_at": p.published_at.isoformat() if p.published_at else None,
            "expires_at": p.expires_at.isoformat() if p.expires_at else None,
            "job_level": p.job_level,
            "job_type": p.job_type,
            "categorie": p.categorie,
            "employer_category": p.employer_category,
            "nr_posturi": p.nr_posturi,
            "contact_phone": p.contact_phone,
            "contact_email": p.contact_email,
            "profession_family": inferred.get("profession_family"),
            "seniority": inferred.get("seniority"),
            "anomaly_flags": inferred.get("anomaly_flags", []),
        })

    total = _apply_filters(
        JobPosting.objects.all(), **filter_kwargs
    ).count()

    return JsonResponse({"count": total, "results": results})


class JobPostingFeed(Feed):
    title = "Posturi publice — posturi.gov.ro"
    link = "/"
    description = "Anunțuri de angajare în sectorul public din România"
    feed_type = Atom1Feed

    def get_object(self, request, *args, **kwargs):
        self._filter_kwargs = _filter_kwargs_from_request(request)
        return None

    def items(self, obj):
        return _apply_filters(
            JobPosting.objects.select_related("employer", "judet"),
            **self._filter_kwargs,
        ).order_by("-published_at")[:50]

    def item_title(self, item):
        employer = item.employer.name if item.employer else ""
        return f"{item.title} — {employer}" if employer else item.title

    def item_description(self, item):
        parts = []
        if item.judet:
            parts.append(f"Județ: {item.judet.name}")
        if item.job_type:
            parts.append(f"Tip: {item.job_type}")
        if item.categorie:
            parts.append(f"Categorie: {item.categorie}")
        if item.expires_at:
            parts.append(f"Termen depunere: {item.expires_at:%d.%m.%Y}")
        return " | ".join(parts)

    def item_link(self, item):
        return item.url

    def item_pubdate(self, item):
        if item.published_at:
            return datetime.combine(item.published_at, time.min)
        return None

    def item_guid(self, item):
        return item.url

    def item_author_name(self, item):
        return item.employer.name if item.employer else None


def job_ical(request):
    """iCal feed: one VEVENT per active job posting (deadline as DTSTART/DTEND).

    Each event represents the application deadline for a posting so subscribers
    can see upcoming deadlines in their calendar app. Same filter params as the
    browse view.
    """
    filter_kwargs = _filter_kwargs_from_request(request)
    qs = _apply_filters(
        JobPosting.objects.select_related("employer", "judet"),
        **filter_kwargs,
    ).filter(expires_at__isnull=False).order_by("expires_at")[:200]

    cal = Calendar()
    cal.add("prodid", "-//posturi.gov.ro//Posturi Publice//RO")
    cal.add("version", "2.0")
    cal.add("x-wr-calname", "Posturi publice Romania")
    cal.add("x-wr-caldesc", "Termene de depunere — posturi.gov.ro")

    for posting in qs:
        ev = Event()
        ev.add("uid", f"posturi-gov-ro-{posting.pk}@posturi.gov.ro")
        title = posting.title or "Anunț"
        employer = posting.employer.name if posting.employer else ""
        ev.add("summary", f"{title} — {employer}" if employer else title)

        deadline_dt = datetime.combine(posting.expires_at, time(23, 59, 59), tzinfo=dt_timezone.utc)
        ev.add("dtstart", deadline_dt.date())
        ev.add("dtend", deadline_dt.date())

        desc_parts = []
        if posting.judet:
            desc_parts.append(f"Județ: {posting.judet.name}")
        if posting.categorie:
            desc_parts.append(f"Categorie: {posting.categorie}")
        if posting.contact_phone:
            desc_parts.append(f"Tel: {posting.contact_phone}")
        if posting.contact_email:
            desc_parts.append(f"Email: {posting.contact_email}")
        desc_parts.append(f"URL: {posting.url}")
        ev.add("description", "\n".join(desc_parts))
        ev.add("url", posting.url)

        if posting.published_at:
            ev.add("dtstamp", datetime.combine(posting.published_at, time.min, tzinfo=dt_timezone.utc))

        cal.add_component(ev)

    return HttpResponse(cal.to_ical(), content_type="text/calendar; charset=utf-8")


def about(request):
    return render(request, "jobs/about.html", {})


def _build_stats():
    today = date.today()
    total = JobPosting.objects.count()
    active = JobPosting.objects.filter(expires_at__gte=today).count()
    by_family = list(
        JobPosting.objects.exclude(inferred__profession_family=None)
        .exclude(inferred__profession_family="altele")
        .values("inferred__profession_family")
        .annotate(count=Count("id"))
        .order_by("-count")[:10]
    )
    by_judet = list(
        JobPosting.objects.filter(judet__isnull=False)
        .values("judet__name", "judet__slug")
        .annotate(count=Count("id"))
        .order_by("-count")[:10]
    )
    by_seniority = list(
        JobPosting.objects.exclude(inferred__seniority=None)
        .exclude(inferred__seniority="")
        .values("inferred__seniority")
        .annotate(count=Count("id"))
        .order_by("-count")[:10]
    )
    by_studies = list(
        JobPosting.objects.exclude(inferred__studies_required=None)
        .exclude(inferred__studies_required="")
        .values("inferred__studies_required")
        .annotate(count=Count("id"))
        .order_by("-count")[:10]
    )
    by_employer_cat = list(
        JobPosting.objects.exclude(employer_category="")
        .exclude(employer_category=None)
        .values("employer_category")
        .annotate(count=Count("id"))
        .order_by("-count")[:10]
    )
    monthly_trends = list(
        JobPosting.objects.annotate(month=TruncMonth("published_at"))
        .filter(month__isnull=False)
        .values("month")
        .annotate(count=Count("id"))
        .order_by("-month")[:12]
    )
    monthly_trends.reverse()

    anomaly_counts = {
        flag: JobPosting.objects.filter(inferred__anomaly_flags__contains=[flag]).count()
        for flag in ("short_deadline", "missing_contact", "contact_in_attachment", "gender_criteria", "no_body", "frequent_repost")
    }
    inferred_count = JobPosting.objects.exclude(inferred={}).count()

    unique_employers = Employer.objects.filter(postings__isnull=False).distinct().count()
    unique_judete = Judet.objects.filter(postings__isnull=False).distinct().count()
    new_7days = JobPosting.objects.filter(published_at__gte=today - timedelta(days=7)).count()
    with_salary = JobPosting.objects.filter(inferred__salary_min__isnull=False).count()

    avg_deadline_days = None
    duration_stats = JobPosting.objects.filter(expires_at__isnull=False)
    if duration_stats.exists():
        from django.db.models import DurationField
        duration = Avg(ExpressionWrapper(F("expires_at") - F("published_at"), output_field=DurationField()))
        result = duration_stats.aggregate(avg_duration=duration)
        if result["avg_duration"]:
            avg_deadline_days = int(result["avg_duration"].total_seconds() / 86400)

    return {
        "total": total,
        "active": active,
        "inferred": inferred_count,
        "by_family": [{"family": x["inferred__profession_family"], "count": x["count"]} for x in by_family],
        "by_judet": [{"judet": x["judet__name"], "slug": x["judet__slug"], "count": x["count"]} for x in by_judet],
        "by_seniority": [{"seniority": x["inferred__seniority"], "count": x["count"]} for x in by_seniority],
        "by_studies": [{"studies": x["inferred__studies_required"], "count": x["count"]} for x in by_studies],
        "by_employer_cat": [{"category": x["employer_category"], "count": x["count"]} for x in by_employer_cat],
        "monthly_trends": [{"month": x["month"], "count": x["count"]} for x in monthly_trends],
        "anomaly_counts": anomaly_counts,
        "unique_employers": unique_employers,
        "unique_judete": unique_judete,
        "new_7days": new_7days,
        "with_salary": with_salary,
        "avg_deadline_days": avg_deadline_days,
    }


def _build_quick_stats():
    today = date.today()
    return {
        "active": JobPosting.objects.filter(expires_at__gte=today).count(),
        "judete": Judet.objects.filter(postings__isnull=False).distinct().count(),
        "employers": Employer.objects.filter(postings__isnull=False).distinct().count(),
        "families": JobPosting.objects.exclude(inferred__profession_family=None)
                    .exclude(inferred__profession_family="")
                    .values("inferred__profession_family").distinct().count(),
    }


def stats_json(request):
    return JsonResponse(_build_stats())


_ANOMALY_LABELS = {
    "short_deadline": "Termen scurt",
    "missing_contact": "Contact lipsă",
    "contact_in_attachment": "Contact în atașament",
    "gender_criteria": "Criteriu de gen",
    "no_body": "Fără corp",
    "frequent_repost": "Re-publicare frecventă",
}


def stats_dashboard(request):
    data = _build_stats()
    total = data["total"] or 1
    max_family = data["by_family"][0]["count"] if data["by_family"] else 1
    max_judet = data["by_judet"][0]["count"] if data["by_judet"] else 1
    max_seniority = data["by_seniority"][0]["count"] if data["by_seniority"] else 1
    max_studies = data["by_studies"][0]["count"] if data["by_studies"] else 1
    max_employer_cat = data["by_employer_cat"][0]["count"] if data["by_employer_cat"] else 1
    max_monthly = max((x["count"] for x in data["monthly_trends"]), default=1)

    anomalies = [
        {
            "flag": flag,
            "label": _ANOMALY_LABELS.get(flag, flag),
            "count": count,
            "pct": round(100 * count / total),
        }
        for flag, count in data["anomaly_counts"].items()
    ]

    return render(request, "jobs/stats.html", {
        "total": data["total"],
        "active": data["active"],
        "active_pct": round(100 * data["active"] / total),
        "avg_deadline_days": data["avg_deadline_days"],
        "inferred": data["inferred"],
        "inferred_pct": round(100 * data["inferred"] / total),
        "unique_employers": data["unique_employers"],
        "unique_judete": data["unique_judete"],
        "new_7days": data["new_7days"],
        "new_7days_pct": round(100 * data["new_7days"] / total) if total else 0,
        "with_salary": data["with_salary"],
        "with_salary_pct": round(100 * data["with_salary"] / total) if total else 0,
        "by_family": data["by_family"],
        "max_family": max_family,
        "by_judet": data["by_judet"],
        "max_judet": max_judet,
        "by_seniority": data["by_seniority"],
        "max_seniority": max_seniority,
        "by_studies": data["by_studies"],
        "max_studies": max_studies,
        "by_employer_cat": data["by_employer_cat"],
        "max_employer_cat": max_employer_cat,
        "monthly_trends": data["monthly_trends"],
        "max_monthly": max_monthly,
        "anomalies": anomalies,
    })


def employer_profile(request, slug):
    employer = get_object_or_404(Employer, slug=slug)
    today = date.today()

    all_postings = employer.postings.select_related("judet").order_by("-published_at")
    active = all_postings.filter(expires_at__gte=today)
    expired = all_postings.filter(expires_at__lt=today)

    by_judet = list(
        employer.postings.filter(judet__isnull=False)
        .values("judet__name", "judet__slug")
        .annotate(count=Count("id"))
        .order_by("-count")
    )

    top_category = (
        employer.postings.exclude(employer_category="")
        .values("employer_category")
        .annotate(n=Count("id"))
        .order_by("-n")
        .values_list("employer_category", flat=True)
        .first()
    )

    aliases = list(employer.aliases.values_list("alias_name", flat=True))
    max_judet = max((x["count"] for x in by_judet), default=1)

    return render(request, "jobs/employer_profile.html", {
        "employer": employer,
        "top_category": top_category,
        "aliases": aliases,
        "total": all_postings.count(),
        "active_count": active.count(),
        "expired_count": expired.count(),
        "unique_judete": len(by_judet),
        "by_judet": by_judet,
        "max_judet": max_judet,
        "active_postings": active[:50],
        "recent_expired": expired[:25],
        "today": today,
    })


def _build_employer_stats():
    today = date.today()
    total_employers = Employer.objects.count()
    active_employers = Employer.objects.filter(
        postings__expires_at__gte=today
    ).distinct().count()

    top_employers = list(
        Employer.objects.annotate(posting_count=Count('postings'))
        .filter(posting_count__gt=0)
        .values('id', 'name', 'slug', 'posting_count')
        .order_by('-posting_count')[:20]
    )

    top_active = list(
        Employer.objects.annotate(
            active_count=Count('postings', filter=Q(postings__expires_at__gte=today))
        )
        .filter(active_count__gt=0)
        .values('id', 'name', 'slug', 'active_count')
        .order_by('-active_count')[:20]
    )

    employers_with_counts = Employer.objects.annotate(
        posting_count=Count('postings')
    ).filter(posting_count__gt=0)

    total_with_postings = employers_with_counts.count()
    one_post = employers_with_counts.filter(posting_count=1).count()
    two_five = employers_with_counts.filter(posting_count__gte=2, posting_count__lte=5).count()
    six_ten = employers_with_counts.filter(posting_count__gte=6, posting_count__lte=10).count()
    ten_plus = employers_with_counts.filter(posting_count__gt=10).count()

    total_postings = JobPosting.objects.count()
    top_ten_count = JobPosting.objects.filter(
        employer__in=[e['id'] for e in Employer.objects.annotate(posting_count=Count('postings')).filter(posting_count__gt=0).order_by('-posting_count')[:10].values('id')]
    ).count()

    distribution = [
        {'range': '1', 'label': '1 anunț', 'count': one_post, 'pct': round(100 * one_post / total_with_postings) if total_with_postings else 0},
        {'range': '2-5', 'label': '2–5 anunțuri', 'count': two_five, 'pct': round(100 * two_five / total_with_postings) if total_with_postings else 0},
        {'range': '6-10', 'label': '6–10 anunțuri', 'count': six_ten, 'pct': round(100 * six_ten / total_with_postings) if total_with_postings else 0},
        {'range': '10+', 'label': '10+ anunțuri', 'count': ten_plus, 'pct': round(100 * ten_plus / total_with_postings) if total_with_postings else 0},
    ]

    avg_postings = round(total_postings / total_with_postings, 2) if total_with_postings else 0

    top_employers_by_judet = {}
    judete = JobPosting.objects.filter(judet__isnull=False).values_list('judet__slug', 'judet__name').distinct().order_by('judet__name')
    for judet_slug, judet_name in judete:
        top_five = list(
            JobPosting.objects.filter(judet__slug=judet_slug)
            .values('employer__id', 'employer__name', 'employer__slug')
            .annotate(count=Count('id'))
            .order_by('-count')[:5]
        )
        if top_five:
            top_employers_by_judet[judet_name] = top_five

    top_employers_by_family = {}
    families = JobPosting.objects.exclude(inferred__profession_family=None).exclude(inferred__profession_family='altele').values_list('inferred__profession_family', flat=True).distinct().order_by('inferred__profession_family')
    for family in families:
        top_five = list(
            JobPosting.objects.filter(inferred__profession_family=family)
            .values('employer__id', 'employer__name', 'employer__slug')
            .annotate(count=Count('id'))
            .order_by('-count')[:5]
        )
        if top_five:
            top_employers_by_family[family] = top_five

    top_employers_by_nivel = {}
    niveluri = JobPosting.objects.exclude(job_level='').values_list('job_level', flat=True).distinct().order_by('job_level')
    for nivel in niveluri:
        top_five = list(
            JobPosting.objects.filter(job_level=nivel)
            .values('employer__id', 'employer__name', 'employer__slug')
            .annotate(count=Count('id'))
            .order_by('-count')[:5]
        )
        if top_five:
            top_employers_by_nivel[nivel] = top_five

    top_employers_by_seniority = {}
    seniorities = JobPosting.objects.exclude(inferred__seniority=None).values_list('inferred__seniority', flat=True).distinct().order_by('inferred__seniority')
    for seniority in seniorities:
        top_five = list(
            JobPosting.objects.filter(inferred__seniority=seniority)
            .values('employer__id', 'employer__name', 'employer__slug')
            .annotate(count=Count('id'))
            .order_by('-count')[:5]
        )
        if top_five:
            top_employers_by_seniority[seniority] = top_five

    return {
        'total_employers': total_employers,
        'active_employers': active_employers,
        'total_postings': total_postings,
        'avg_postings': avg_postings,
        'top_employers': top_employers,
        'top_active': top_active,
        'distribution': distribution,
        'top_ten_count': top_ten_count,
        'concentration_pct': round(100 * top_ten_count / total_postings) if total_postings else 0,
        'top_employers_by_judet': top_employers_by_judet,
        'top_employers_by_family': top_employers_by_family,
        'top_employers_by_nivel': top_employers_by_nivel,
        'top_employers_by_seniority': top_employers_by_seniority,
    }


def employers_dashboard(request):
    data = _build_employer_stats()
    max_employer = max((x.get('posting_count') or x.get('active_count') for x in data['top_employers']), default=1)
    max_active = max((x['active_count'] for x in data['top_active']), default=1)
    max_distribution = max((x['count'] for x in data['distribution']), default=1)

    max_by_judet = {}
    for judet, employers in data['top_employers_by_judet'].items():
        max_by_judet[judet] = max((x['count'] for x in employers), default=1)

    max_by_family = {}
    for family, employers in data['top_employers_by_family'].items():
        max_by_family[family] = max((x['count'] for x in employers), default=1)

    max_by_nivel = {}
    for nivel, employers in data['top_employers_by_nivel'].items():
        max_by_nivel[nivel] = max((x['count'] for x in employers), default=1)

    max_by_seniority = {}
    for seniority, employers in data['top_employers_by_seniority'].items():
        max_by_seniority[seniority] = max((x['count'] for x in employers), default=1)

    return render(request, 'jobs/employers_dashboard.html', {
        'total_employers': data['total_employers'],
        'active_employers': data['active_employers'],
        'total_postings': data['total_postings'],
        'avg_postings': data['avg_postings'],
        'top_employers': data['top_employers'],
        'max_employer': max_employer,
        'top_active': data['top_active'],
        'max_active': max_active,
        'distribution': data['distribution'],
        'max_distribution': max_distribution,
        'concentration_pct': data['concentration_pct'],
        'top_ten_count': data['top_ten_count'],
        'top_employers_by_judet': data['top_employers_by_judet'],
        'max_by_judet': max_by_judet,
        'top_employers_by_family': data['top_employers_by_family'],
        'max_by_family': max_by_family,
        'top_employers_by_nivel': data['top_employers_by_nivel'],
        'max_by_nivel': max_by_nivel,
        'top_employers_by_seniority': data['top_employers_by_seniority'],
        'max_by_seniority': max_by_seniority,
    })


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
    from itertools import groupby
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
        model_keys_in_group = [(g["provider"], g["model"], g["prompt_version"]) for g in group]
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


def job_detail(request, pk):
    posting = get_object_or_404(
        JobPosting.objects.select_related("employer", "judet").prefetch_related("calendar_events"),
        pk=pk,
    )
    body_html = ""
    if posting.body_markdown:
        body_html = _sanitize(md.markdown(posting.body_markdown, extensions=["nl2br", "tables"]))

    schema_sections = None
    if posting.schema_json:
        schema_sections = _render_schema_sections(posting.schema_json)

    return render(request, "jobs/detail.html", {
        "posting": posting,
        "body_html": body_html,
        "schema_sections": schema_sections,
        "today": date.today(),
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
