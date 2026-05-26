from django.contrib.postgres.indexes import GinIndex
from django.contrib.postgres.search import SearchVectorField
from django.db import IntegrityError, models, transaction
from django.utils.text import slugify


class Judet(models.Model):
    name = models.CharField(max_length=120, unique=True)
    slug = models.SlugField(max_length=140, unique=True)

    class Meta:
        ordering = ["name"]
        verbose_name = "Județ"
        verbose_name_plural = "Județe"

    def __str__(self):
        return self.name

    def save(self, *args, **kwargs):
        if not self.slug:
            self.slug = slugify(self.name)[:140]
        super().save(*args, **kwargs)


class Employer(models.Model):
    name = models.CharField(max_length=500, unique=True)
    slug = models.SlugField(max_length=255, unique=True)

    class Meta:
        ordering = ["name"]
        verbose_name = "Angajator"
        verbose_name_plural = "Angajatori"

    def __str__(self):
        return self.name

    def save(self, *args, **kwargs):
        if not self.slug:
            self.slug = slugify(self.name)[:240] or "angajator"
        for attempt in range(1, 100):
            try:
                with transaction.atomic():
                    super().save(*args, **kwargs)
                return
            except IntegrityError:
                base = slugify(self.name)[:235] or "angajator"
                self.slug = f"{base}-{attempt + 1}"[:255]
        super().save(*args, **kwargs)


class EmployerAlias(models.Model):
    """Maps a raw variant employer name to its canonical Employer record.

    Created by the canonicalize_employers management command. After running
    that command, all JobPosting.employer FKs point to the canonical employer,
    so this table is an audit trail of what was merged and why.
    """
    alias_name = models.CharField(max_length=500, unique=True)
    canonical = models.ForeignKey(Employer, on_delete=models.PROTECT, related_name="aliases")
    auto_generated = models.BooleanField(default=True)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        ordering = ["alias_name"]
        verbose_name = "Alias angajator"
        verbose_name_plural = "Aliasuri angajatori"

    def __str__(self):
        return f"{self.alias_name} → {self.canonical.name}"


class JobPosting(models.Model):
    # ---- Index fields (data/posturi_gov_ro.csv) ----
    url = models.URLField(unique=True, max_length=500, help_text="Posting URL — primary key from posturi.gov.ro")
    title = models.CharField(max_length=500, help_text="pozitie")
    employer = models.ForeignKey(Employer, on_delete=models.PROTECT, related_name="postings")
    detalii_raw = models.TextField(blank=True, default="", help_text="detalii — comma-separated tags, kept verbatim")
    published_at = models.DateField(null=True, blank=True, help_text="publicat_in")
    expires_at = models.DateField(null=True, blank=True, help_text="expira_in")
    judet = models.ForeignKey(Judet, on_delete=models.PROTECT, null=True, blank=True, related_name="postings")
    url_judet = models.URLField(blank=True, default="", max_length=500)
    tip = models.CharField(max_length=100, blank=True, default="", help_text="Listing type from index page")
    updates_raw = models.TextField(blank=True, default="", help_text="updates — change log from scraper, parsed later")

    # ---- Detail fields (data/anunturi/anunturi.csv) ----
    job_level = models.CharField(max_length=120, blank=True, default="", help_text="Nivel — execuție / conducere")
    job_type = models.CharField(max_length=120, blank=True, default="", help_text="Tip — Permanent / Temporar")
    employer_category = models.CharField(max_length=200, blank=True, default="")
    categorie = models.CharField(max_length=120, blank=True, default="", help_text="Funcție publică / contractuală")
    announcement_url = models.URLField(blank=True, default="", max_length=500, help_text="Attachment URL (doc/pdf)")
    body_markdown = models.TextField(blank=True, default="")
    attachment_text = models.TextField(blank=True, default="", help_text="Plain text extracted from downloaded attachment (docx/doc/pdf)")
    other_links = models.JSONField(default=list, blank=True)
    nr_posturi = models.IntegerField(null=True, blank=True)
    contact_phone = models.CharField(max_length=40, blank=True, default="")
    contact_email = models.CharField(max_length=200, blank=True, default="")
    contact_person = models.CharField(max_length=200, blank=True, default="")
    data_limita_depunere = models.DateTimeField(null=True, blank=True)
    data_proba_scrisa = models.DateField(null=True, blank=True)
    data_interviu = models.DateField(null=True, blank=True)
    data_rezultate_finale = models.DateField(null=True, blank=True)

    # ---- Bookkeeping ----
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    last_seen_at = models.DateField(null=True, blank=True, help_text="Set by importer; can detect dropped postings")
    inferred = models.JSONField(default=dict, blank=True, help_text="Reserved for v2/v3 derived fields")

    # ---- Full-text search ----
    search_vector = SearchVectorField(null=True, blank=True)

    class Meta:
        ordering = ["-published_at", "-id"]
        verbose_name = "Anunț"
        verbose_name_plural = "Anunțuri"
        indexes = [
            models.Index(fields=["expires_at"]),
            models.Index(fields=["published_at"]),
            models.Index(fields=["employer"]),
            models.Index(fields=["judet"]),
            GinIndex(fields=["search_vector"]),
        ]

    def __str__(self):
        return self.title or self.url


class JobPostingUpdate(models.Model):
    """One parsed segment from JobPosting.updates_raw.

    Populated by the parse_updates management command.  Each segment is one
    scraper-detected change: either the initial "New entry" or a list of
    field names that changed on a given date.
    """

    posting = models.ForeignKey(JobPosting, on_delete=models.CASCADE, related_name="update_log")
    changed_at = models.DateField()
    fields_changed = models.CharField(
        max_length=500,
        help_text='Comma-separated field names that changed, or "New entry"',
    )

    class Meta:
        ordering = ["changed_at"]
        constraints = [
            models.UniqueConstraint(
                fields=["posting", "changed_at", "fields_changed"],
                name="unique_posting_update",
            )
        ]
        verbose_name = "Actualizare anunț"
        verbose_name_plural = "Actualizări anunțuri"

    def __str__(self):
        return f"{self.changed_at:%Y-%m-%d} — {self.fields_changed[:60]}"

    @property
    def is_new_entry(self) -> bool:
        return self.fields_changed == "New entry"


class CalendarEvent(models.Model):
    posting = models.ForeignKey(JobPosting, on_delete=models.CASCADE, related_name="calendar_events")
    eveniment = models.CharField(max_length=500)
    data = models.DateField()
    ora = models.TimeField(null=True, blank=True)

    class Meta:
        ordering = ["data", "id"]
        verbose_name = "Eveniment calendar"
        verbose_name_plural = "Calendar evenimente"
        indexes = [
            models.Index(fields=["data", "posting"]),
        ]

    def __str__(self):
        return f"{self.data:%d.%m.%Y} — {self.eveniment[:60]}"
