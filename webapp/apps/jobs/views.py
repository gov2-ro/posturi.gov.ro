from datetime import date, datetime, time
from datetime import timezone as dt_timezone

import markdown as md
from django.contrib.postgres.search import SearchQuery, SearchRank
from django.contrib.syndication.views import Feed
from django.core.paginator import Paginator
from django.db.models import Count
from django.http import HttpResponse, JsonResponse
from django.shortcuts import get_object_or_404, render
from django.utils.feedgenerator import Atom1Feed
from icalendar import Calendar, Event

from apps.jobs.models import JobPosting

FTS_CONFIG = "romanian_unaccent"
PAGE_SIZE = 25


def _apply_filters(qs, *, q, judet_slugs, levels, types, categories, employer_cats, expires_before, expires_after, families, seniorities, anomaly_flags=None):
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
        "families": families,
        "seniorities": seniorities,
        "anomaly_flags": anomaly_flags,
        "sort": sort,
        "judet_options": judet_options,
        "level_options": level_options,
        "type_options": type_options,
        "categorie_options": categorie_options,
        "employer_cat_options": employer_cat_options,
        "family_options": family_options,
        "seniority_options": seniority_options,
        "anomaly_choices": [
            ("short_deadline", "Termen scurt"),
            ("missing_contact", "Contact lipsă"),
            ("contact_in_attachment", "Contact doar în atașament"),
            ("gender_criteria", "Criteriu de gen"),
            ("no_body", "Fără corp"),
            ("frequent_repost", "Re-publicare frecventă"),
        ],
        "today": date.today(),
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
    anomaly_counts = {
        flag: JobPosting.objects.filter(inferred__anomaly_flags__contains=[flag]).count()
        for flag in ("short_deadline", "missing_contact", "contact_in_attachment", "gender_criteria", "no_body", "frequent_repost")
    }
    inferred_count = JobPosting.objects.exclude(inferred={}).count()
    return {
        "total": total,
        "active": active,
        "inferred": inferred_count,
        "by_family": [{"family": x["inferred__profession_family"], "count": x["count"]} for x in by_family],
        "by_judet": [{"judet": x["judet__name"], "slug": x["judet__slug"], "count": x["count"]} for x in by_judet],
        "anomaly_counts": anomaly_counts,
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
        "inferred": data["inferred"],
        "inferred_pct": round(100 * data["inferred"] / total),
        "by_family": data["by_family"],
        "max_family": max_family,
        "by_judet": data["by_judet"],
        "max_judet": max_judet,
        "anomalies": anomalies,
    })


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
