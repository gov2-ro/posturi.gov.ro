from django.contrib.postgres.indexes import GinIndex
from django.contrib.postgres.search import SearchVectorField
from django.db import IntegrityError, models, transaction
from django.utils import timezone
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

    def get_absolute_url(self):
        from django.urls import reverse
        return reverse("employer_profile", kwargs={"slug": self.slug})

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
    locality = models.CharField(
        max_length=160, blank=True, default="",
        help_text="City/commune, split out of the source's 'LOCALITY, County' badge",
    )
    judet_raw = models.CharField(
        max_length=200, blank=True, default="",
        help_text="Source județ string kept verbatim when it could not be matched to a county "
                  "(see apps.jobs.judete). Empty means the county resolved cleanly.",
    )
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
    attachment_meta = models.JSONField(
        default=list, blank=True,
        help_text="Per-file descriptors written by extract_attachments: "
                  "[{url, ext, bytes, kind}]. `kind` is null for an ordinary announcement.",
    )
    nr_posturi = models.IntegerField(null=True, blank=True)
    # 200, not 40: the structured contact row keeps the source text verbatim
    # ("0268-428841 sau 0268-428843, interior 205"), and the old varchar(40)
    # silently dropped whole postings from the detail import (OPS-03).
    contact_phone = models.CharField(max_length=200, blank=True, default="")
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
    # ---- Source retrieval provenance (FIX-03) ----
    # Set ONLY from a real successful detail fetch, never from import/build
    # time. Legacy postings predating this leave both fields empty — unknown,
    # not "fetched now".
    detail_fetched_at = models.DateTimeField(
        null=True, blank=True,
        help_text="When the cached detail page was last successfully retrieved from the source.",
    )
    detail_content_hash = models.CharField(
        max_length=64, blank=True, default="",
        help_text="SHA-256 of the extracted detail content — the source revision identity. "
                  "Enrichment results are only current while they match this hash.",
    )
    cancelled = models.BooleanField(
        default=False,
        help_text=(
            "Competition withdrawn: the index lists 'Anunț anulat' instead of an expiry date. "
            "Distinct from expired — the detail page still carries real dates, so without this "
            "flag a cancelled announcement reads as an open job."
        ),
    )
    inferred = models.JSONField(default=dict, blank=True, help_text="Reserved for v2/v3 derived fields")
    schema_json = models.JSONField(null=True, blank=True, help_text="LLM-extracted structured sections for display (responsibilities, qualifications, skills, etc.)")
    # ---- Production extraction provenance (FIX-05) ----
    # Written atomically with schema_json by the production path of
    # llm-schema.py — never by compare-only runs. `schema_source_revision` is
    # the detail_content_hash the extraction ran against: a revision change
    # (FIX-03) makes the production row stale and eligible for re-extraction
    # even though schema_json is non-null.
    schema_provider = models.CharField(max_length=50, blank=True, default="")
    schema_model = models.CharField(max_length=100, blank=True, default="")
    schema_prompt_version = models.CharField(max_length=20, blank=True, default="")
    schema_source_revision = models.CharField(max_length=64, blank=True, default="")
    schema_extracted_at = models.DateTimeField(null=True, blank=True)

    # ---- Occupation and estimated pay ----
    occupation = models.ForeignKey(
        "Occupation", on_delete=models.SET_NULL, null=True, blank=True,
        related_name="postings",
        help_text="Normalised occupation, resolved from the title by normalize-titles.py.",
    )
    salary_estimate = models.JSONField(
        null=True, blank=True,
        help_text=(
            "Gross monthly estimate from the draft salary grid, written by "
            "estimate-salaries.py. Derived, never scraped: postings almost never "
            "state a salary. Recomputable from occupation + context alone, so a "
            "new variant of the draft costs a script run, not a re-extraction."
        ),
    )

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
            models.Index(fields=["locality"]),
            models.Index(fields=["judet_raw"]),
            models.Index(fields=["job_level"]),
            models.Index(fields=["occupation"]),
            GinIndex(fields=["search_vector"]),
            GinIndex(fields=["inferred"]),
        ]

    def __str__(self):
        return self.title or self.url


class Occupation(models.Model):
    """One normalised occupation, keyed on the parsed job title.

    A dictionary, not a per-posting field. 9,757 postings carry 5,979 distinct
    titles, 3,723 once `ocupatii.parse_title()` strips grade, count and
    department — so normalising the *titles* costs a fraction of normalising
    every posting, and new postings mostly hit rows that already exist.

    Keyed on `title_norm` (diacritic- and punctuation-stripped) so that
    `Îngrijitor`, `ÎNGRIJITOR` and `îngrijitor` collapse to one row instead of
    three facet values.
    """

    title_norm = models.CharField(
        max_length=300, unique=True,
        help_text="ocupatii.parse_title(raw).base_norm — the dictionary key.",
    )
    title_sample = models.CharField(
        max_length=300, blank=True, default="",
        help_text="One raw title that produced this key, for debugging.",
    )
    canonical = models.CharField(
        max_length=300, help_text="Display form, e.g. 'Asistent medical generalist'."
    )

    # ---- COR / ISCO-08 ----
    cor_code = models.CharField(max_length=6, blank=True, default="", db_index=True)
    cor_label = models.CharField(max_length=300, blank=True, default="")
    isco_group = models.CharField(
        max_length=4, blank=True, default="", db_index=True,
        help_text="ISCO-08 unit group, always cor_code[:4]. Derived, never asked of a model.",
    )

    # ---- Salary grid selector ----
    grid_selector = models.JSONField(
        default=dict, blank=True,
        help_text=(
            "Which row of the draft grid this occupation is paid on "
            "{anexa, cod, functie_grila, regim, tip_post, nivel_administrativ}. "
            "A selector, not a salary: the law is an unadopted draft, so pay is "
            "recomputed from here whenever the variant changes."
        ),
    )
    study_level = models.CharField(max_length=20, blank=True, default="")
    match_confidence = models.CharField(
        max_length=12, default="incert",
        help_text="exact | probabil | incert | none — how sure the mapping is.",
    )

    # ---- Provenance ----
    provider = models.CharField(max_length=50, blank=True, default="")
    model = models.CharField(max_length=100, blank=True, default="")
    grid_version = models.CharField(max_length=20, blank=True, default="")
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        ordering = ["canonical"]
        verbose_name = "Ocupație"
        verbose_name_plural = "Ocupații"
        indexes = [
            models.Index(fields=["match_confidence"]),
            models.Index(fields=["canonical"]),
        ]

    def __str__(self):
        return f"{self.canonical} ({self.cor_code or 'fără COR'})"


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


class JobPostingSchemaVariant(models.Model):
    posting = models.ForeignKey(JobPosting, on_delete=models.CASCADE, related_name="schema_variants")
    provider = models.CharField(max_length=50)
    model = models.CharField(max_length=100)
    prompt_version = models.CharField(max_length=20, default="v1")
    schema_json = models.JSONField()
    input_tokens = models.IntegerField(null=True, blank=True)
    output_tokens = models.IntegerField(null=True, blank=True)
    cost_usd = models.DecimalField(max_digits=12, decimal_places=8, null=True, blank=True)
    latency_ms = models.IntegerField(null=True, blank=True)
    #: The posting's detail_content_hash this variant was extracted from (FIX-05).
    #: A compare-only variant whose revision no longer matches the source is
    #: stale evidence, never a candidate for promotion.
    source_revision = models.CharField(max_length=64, blank=True, default="")
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        ordering = ["-created_at"]
        verbose_name = "Variantă schemă LLM"
        verbose_name_plural = "Variante schemă LLM"
        constraints = [
            models.UniqueConstraint(
                fields=["posting", "provider", "model", "prompt_version"],
                name="unique_schema_variant",
            )
        ]

    def __str__(self):
        return f"{self.provider}/{self.model}@{self.prompt_version} — posting {self.posting_id}"


class PostingObservation(models.Model):
    """One immutable record of a posting's tracked facts at a successful observation (UX-12).

    Written by `manage.py observe_postings`, only when something differs from the
    latest observation of the same URL. Nothing here is ever updated: a later run
    that finds a difference inserts another row. The row is the *evidence* for a
    future "this changed from X to Y" notice; change events are derived from pairs
    of rows by the read slice, not stored here.

    Keyed on the source `url`, not on `posting`: the URL is the identity a reader's
    saved job carries (UX-09), and the FK is only a convenience that survives
    nothing (SET_NULL when the posting row is deleted).

    Not a unique constraint on the revision columns: a deadline that moves and then
    moves back, with no new source revision, is two real observations with the same
    key columns. De-duplication is "latest observation of this URL is equal", done
    in the command inside one advisory-locked transaction.
    """

    url = models.TextField(help_text="Source posting URL — the identity of the announcement.")
    posting = models.ForeignKey(
        JobPosting, null=True, blank=True, on_delete=models.SET_NULL, related_name="observations",
    )
    observed_at = models.DateTimeField(
        default=timezone.now,
        help_text="When the observe step recorded this row. Not when the source changed.",
    )
    retrieved_at = models.DateTimeField(
        null=True, blank=True,
        help_text="JobPosting.detail_fetched_at: the last SUCCESSFUL retrieval of the detail page "
                  "this observation rests on. Index-level facts (cancelled, expires_at) come from "
                  "the listing, which is refreshed independently.",
    )
    source_revision = models.CharField(
        max_length=64, blank=True, default="",
        help_text="JobPosting.detail_content_hash — the source revision identity. '' = unknown.",
    )
    # Copied provenance of the extraction the facts were read from. Distinct from
    # `source_revision`: re-running a model over unchanged bytes changes these and
    # not that, which is how a model-only correction is told from a source change.
    extraction_provider = models.CharField(max_length=50, blank=True, default="")
    extraction_model = models.CharField(max_length=100, blank=True, default="")
    extraction_prompt_version = models.CharField(max_length=20, blank=True, default="")
    extraction_source_revision = models.CharField(max_length=64, blank=True, default="")
    extraction_extracted_at = models.DateTimeField(null=True, blank=True)
    facts = models.JSONField(
        help_text="posting_facts.tracked_facts(): deadline, cancellation, expiry, per-role "
                  "requirements and the evidence sentences. Shape pinned by `facts_format`.",
    )
    facts_hash = models.CharField(
        max_length=64,
        help_text="posting_facts.facts_hash(facts): sha256 over the facts without evidence prose "
                  "and revision-scoped role keys.",
    )
    facts_format = models.PositiveSmallIntegerField(default=1)
    valid = models.BooleanField(
        default=True,
        help_text="False when no valid current extraction backed this observation: `facts` then "
                  "holds only non-extraction facts and the requirement part is unavailable "
                  "(never 'no roles', never 'unchanged').",
    )

    class Meta:
        ordering = ["-observed_at", "-id"]
        verbose_name = "Observație anunț"
        verbose_name_plural = "Observații anunțuri"
        indexes = [
            # Serves `WHERE url = …` and "latest observation per URL" alike.
            models.Index(fields=["url", "-observed_at"], name="obs_url_observed_idx"),
        ]

    def __str__(self):
        return f"{self.url} @ {self.observed_at:%Y-%m-%d %H:%M}"


class LlmCall(models.Model):
    """One provider API attempt, as it happened (FIX-10). Append-only.

    The ledger of LLM spend. `JobPostingSchemaVariant.cost_usd` cannot be it: that row
    is upserted per (posting, provider, model, prompt), so a re-extraction overwrites
    the earlier amount and moves the spend to the latest day. Here every attempt --
    retries, repairs, calls that failed before returning any usage -- is a row of its
    own and nothing is ever updated or removed.

    Written by `llm_ledger.py` from the three steps that call a provider (`schema`,
    `infer`, `occupations`). It is a local record of attempts this code made, not the
    provider's invoice: a process that dies between the provider accepting a request
    and the row being written leaves a call that cost money and is not here.

    Unknown is null, never zero: a call that returned no usage has no token counts
    and no cost, and a sum over `cost_usd` therefore under-counts by exactly the calls
    whose `cost_usd` is null. `pricing` is the rate card used at call time (including
    the peak multiplier if one applied), so editing models_config.json later cannot
    restate old spend.

    No prompts, no responses, no keys, no personal data: `subject` is a slug or a job
    title, and `error_class` is an exception class name, not its message.
    """

    #: Identifies the attempt locally so a re-delivered record is a no-op
    #: (`INSERT ... ON CONFLICT DO NOTHING`). Two real calls never share one.
    call_id = models.CharField(max_length=120, unique=True)
    run_id = models.CharField(max_length=80, blank=True, default="", db_index=True,
                              help_text="The pipeline run (POSTURI_RUN_ID); '' for a hand-run.")
    step = models.CharField(max_length=24, db_index=True,
                            help_text="schema | infer | occupations")
    subject = models.CharField(max_length=300, blank=True, default="",
                               help_text="Posting slug, or the job title being classified.")
    posting_id = models.IntegerField(null=True, blank=True, db_index=True)
    provider = models.CharField(max_length=50)
    model = models.CharField(max_length=100)
    prompt_version = models.CharField(max_length=20, blank=True, default="")
    source_revision = models.CharField(max_length=64, blank=True, default="")

    started_at = models.DateTimeField()
    ended_at = models.DateTimeField(db_index=True)
    latency_ms = models.IntegerField(null=True, blank=True)

    attempt = models.PositiveSmallIntegerField(
        default=1, help_text="1-based attempt within one logical request.")
    is_repair = models.BooleanField(
        default=False, help_text="This attempt re-asked with the validation error appended.")
    api_ok = models.BooleanField(
        help_text="The provider returned a response. Says nothing about whether it was valid.")
    outcome = models.CharField(
        max_length=24,
        help_text="ok | validation_failed | truncated | transient_error | fatal_error | error")
    error_class = models.CharField(max_length=80, blank=True, default="")
    request_id = models.CharField(max_length=120, blank=True, default="")

    input_tokens = models.IntegerField(null=True, blank=True,
                                       help_text="Total prompt tokens, cached ones included.")
    output_tokens = models.IntegerField(null=True, blank=True)
    cache_read_tokens = models.IntegerField(null=True, blank=True)
    cache_write_tokens = models.IntegerField(null=True, blank=True)
    reasoning_tokens = models.IntegerField(null=True, blank=True)

    pricing = models.JSONField(null=True, blank=True,
                               help_text="The rates applied: per-million input/output/cache, "
                                         "the peak multiplier, and a hash of the config block.")
    cost_usd = models.DecimalField(max_digits=12, decimal_places=8, null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        ordering = ["-ended_at", "-id"]
        verbose_name = "Apel LLM"
        verbose_name_plural = "Apeluri LLM"
        indexes = [models.Index(fields=["step", "ended_at"], name="llmcall_step_ended_idx")]

    def save(self, *args, **kwargs):
        if not self._state.adding:
            raise RuntimeError("LlmCall is append-only: insert a new row instead of saving this one")
        super().save(*args, **kwargs)

    def __str__(self):
        return f"{self.step} {self.provider}/{self.model} {self.outcome} @ {self.ended_at:%Y-%m-%d %H:%M}"
