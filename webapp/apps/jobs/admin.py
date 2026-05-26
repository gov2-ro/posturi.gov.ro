from django.contrib import admin
from django.utils.html import format_html

from .models import CalendarEvent, Employer, EmployerAlias, JobPosting, Judet


@admin.register(Judet)
class JudetAdmin(admin.ModelAdmin):
    list_display = ("name", "slug")
    search_fields = ("name",)
    prepopulated_fields = {"slug": ("name",)}


@admin.register(Employer)
class EmployerAdmin(admin.ModelAdmin):
    list_display = ("name", "slug", "posting_count", "alias_count")
    search_fields = ("name",)
    prepopulated_fields = {"slug": ("name",)}

    def posting_count(self, obj):
        return obj.postings.count()
    posting_count.short_description = "Anunțuri"

    def alias_count(self, obj):
        return obj.aliases.count()
    alias_count.short_description = "Aliasuri"


@admin.register(EmployerAlias)
class EmployerAliasAdmin(admin.ModelAdmin):
    list_display = ("alias_name", "canonical", "auto_generated", "created_at")
    search_fields = ("alias_name", "canonical__name")
    list_filter = ("auto_generated",)
    raw_id_fields = ("canonical",)


class CalendarEventInline(admin.TabularInline):
    model = CalendarEvent
    extra = 0
    fields = ("data", "ora", "eveniment")
    ordering = ("data", "id")


class InferenceConfidenceFilter(admin.SimpleListFilter):
    title = "Confidence inferență"
    parameter_name = "inf_confidence"

    def lookups(self, request, model_admin):
        return [
            ("none",   "Neinferat"),
            ("low",    "Scăzut < 0.5"),
            ("medium", "Mediu 0.5–0.8"),
            ("high",   "Ridicat > 0.8"),
        ]

    def queryset(self, request, queryset):
        v = self.value()
        if v == "none":
            return queryset.filter(inferred={})
        if v == "low":
            return queryset.filter(
                inferred__profession_family__isnull=False,
                inferred__profession_family_confidence__lt=0.5,
            )
        if v == "medium":
            return queryset.filter(
                inferred__profession_family_confidence__gte=0.5,
                inferred__profession_family_confidence__lte=0.8,
            )
        if v == "high":
            return queryset.filter(inferred__profession_family_confidence__gt=0.8)
        return queryset


class AnomalyFilter(admin.SimpleListFilter):
    title = "Anomalii"
    parameter_name = "anomaly"

    def lookups(self, request, model_admin):
        return [
            ("any",             "Orice anomalie"),
            ("short_deadline",  "Termen scurt"),
            ("missing_contact", "Contact lipsă"),
            ("gender_criteria", "Criteriu de gen"),
            ("no_body",         "Fără corp"),
        ]

    def queryset(self, request, queryset):
        v = self.value()
        if v == "any":
            return queryset.exclude(inferred__anomaly_flags=[])
        if v in ("short_deadline", "missing_contact", "gender_criteria", "no_body"):
            return queryset.filter(inferred__anomaly_flags__contains=[v])
        return queryset


@admin.action(description="Șterge datele inferred (reprogramează inferența)")
def reset_inferred(modeladmin, request, queryset):
    queryset.update(inferred={})


@admin.register(JobPosting)
class JobPostingAdmin(admin.ModelAdmin):
    list_display = (
        "title",
        "employer",
        "judet",
        "published_at",
        "expires_at",
        "nr_posturi",
        "has_body",
        "profession_family_display",
        "confidence_display",
        "anomaly_display",
    )
    list_select_related = ("employer", "judet")
    list_filter = (
        "judet",
        "job_level",
        "job_type",
        "categorie",
        "employer_category",
        "tip",
        InferenceConfidenceFilter,
        AnomalyFilter,
    )
    search_fields = ("title", "employer__name", "url")
    date_hierarchy = "published_at"
    readonly_fields = ("created_at", "updated_at", "last_seen_at", "search_vector")
    inlines = [CalendarEventInline]
    actions = [reset_inferred]
    fieldsets = (
        (None, {"fields": ("url", "title", "employer", "judet", "tip", "detalii_raw")}),
        ("Date publicare / expirare", {"fields": ("published_at", "expires_at")}),
        (
            "Detalii anunț",
            {
                "fields": (
                    "job_level",
                    "job_type",
                    "employer_category",
                    "categorie",
                    "nr_posturi",
                    "announcement_url",
                    "other_links",
                )
            },
        ),
        (
            "Contact + date concurs",
            {
                "fields": (
                    "contact_person",
                    "contact_phone",
                    "contact_email",
                    "data_limita_depunere",
                    "data_proba_scrisa",
                    "data_interviu",
                    "data_rezultate_finale",
                )
            },
        ),
        ("Body", {"fields": ("body_markdown",), "classes": ("collapse",)}),
        ("Updates / inferred", {"fields": ("updates_raw", "inferred"), "classes": ("collapse",)}),
        ("Bookkeeping", {"fields": ("created_at", "updated_at", "last_seen_at", "search_vector"), "classes": ("collapse",)}),
    )

    @admin.display(boolean=True, description="Body")
    def has_body(self, obj):
        return bool(obj.body_markdown)

    @admin.display(description="Familie")
    def profession_family_display(self, obj):
        family = obj.inferred.get("profession_family") if obj.inferred else None
        if not family:
            return "—"
        source = obj.inferred.get("profession_family_source", "")
        color = "#999" if source == "error" else ("royalblue" if source == "llm" else "inherit")
        return format_html('<span style="color:{}">{}</span>', color, family)

    @admin.display(description="Conf.")
    def confidence_display(self, obj):
        conf = obj.inferred.get("profession_family_confidence") if obj.inferred else None
        if conf is None:
            return "—"
        pct = int(conf * 100)
        color = "#d00" if pct < 50 else ("#e80" if pct < 80 else "#080")
        return format_html('<span style="color:{};font-weight:600">{} %</span>', color, pct)

    @admin.display(description="Anomalii")
    def anomaly_display(self, obj):
        flags = obj.inferred.get("anomaly_flags", []) if obj.inferred else []
        if not flags:
            return "—"
        labels = {"short_deadline": "⏰", "missing_contact": "📵", "gender_criteria": "⚠️", "no_body": "📄"}
        icons = " ".join(labels.get(f, f) for f in flags)
        return format_html('<span title="{}">{}</span>', ", ".join(flags), icons)
