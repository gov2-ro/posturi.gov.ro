from datetime import date

import markdown as md
from django.contrib.postgres.search import SearchQuery, SearchRank
from django.core.paginator import Paginator
from django.db.models import Count
from django.shortcuts import get_object_or_404, render

from apps.jobs.models import JobPosting

FTS_CONFIG = "romanian_unaccent"
PAGE_SIZE = 25


def _apply_filters(qs, *, q, judet_slugs, levels, types, categories, employer_cats, expires_before, expires_after):
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
    sort = request.GET.get("sort", "")

    filter_kwargs = dict(
        q=q,
        judet_slugs=judet_slugs,
        levels=levels,
        types=types,
        categories=categories,
        employer_cats=employer_cats,
        expires_before=expires_before,
        expires_after=expires_after,
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
        return _apply_filters(base_qs, **{**filter_kwargs, exclude_key: []})

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

    paginator = Paginator(qs, PAGE_SIZE)
    page_obj = paginator.get_page(request.GET.get("page", 1))

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
        "sort": sort,
        "judet_options": judet_options,
        "level_options": level_options,
        "type_options": type_options,
        "categorie_options": categorie_options,
        "employer_cat_options": employer_cat_options,
        "today": date.today(),
    }

    if request.htmx:
        return render(request, "jobs/partials/result_list.html", ctx)
    return render(request, "jobs/list.html", ctx)


def job_detail(request, pk):
    posting = get_object_or_404(
        JobPosting.objects.select_related("employer", "judet").prefetch_related("calendar_events"),
        pk=pk,
    )
    body_html = ""
    if posting.body_markdown:
        body_html = md.markdown(posting.body_markdown, extensions=["nl2br", "tables"])
    return render(request, "jobs/detail.html", {
        "posting": posting,
        "body_html": body_html,
        "today": date.today(),
    })
