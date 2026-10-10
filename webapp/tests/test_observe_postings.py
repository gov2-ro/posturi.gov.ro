"""`manage.py observe_postings` — durable observation capture (UX-12, first slice).

Drives the real command against pytest-django's throwaway database. The cases are the
capture-side half of the spec's acceptance list: baseline is not a change, an
unchanged refresh and a failed fetch insert nothing, a source change / model-only
correction / index-level change each insert one row of the right class, a cancellation
is recorded (and is not the same thing as a posting merely absent), and the dry-run
and retention flags do what they say. See docs/specs/2026-10-10-follow-ups/06-saved-job-changes.md.
"""

import io
import sys
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

import pytest
from django.core.management import call_command
from django.core.management.base import CommandError
from django.utils import timezone as dj_tz

from apps.jobs.models import Employer, JobPosting, PostingObservation

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

import posting_facts  # noqa: E402

REV = "ab12cd34ef56" + "0" * 52
REV2 = "99ff88ee77dd" + "1" * 52
FETCHED = datetime(2026, 10, 8, 16, 0, tzinfo=timezone.utc)
EXTRACTED = datetime(2026, 10, 8, 16, 30, tzinfo=timezone.utc)

pytestmark = pytest.mark.django_db


def today() -> date:
    return dj_tz.localdate()


def schema(deadline="2026-10-30", **extra):
    out = {
        "education": {"minimum_level": "licenta", "eqf_level": 6, "verbatim": "Studii superioare."},
        "experience": {"years_minimum": 3, "verbatim": "3 ani."},
        "skill_list": [{"label": "GIS"}], "credentials": [],
        "competition_calendar": {
            "application_deadline": deadline,
            "events": [{"stage": "depunere_dosare", "date": deadline, "verbatim": f"Depunere până la {deadline}"}],
        },
    }
    out.update(extra)
    return out


@pytest.fixture
def employer():
    return Employer.objects.create(name="Primăria Test", slug="primaria-test")


@pytest.fixture
def make(employer):
    counter = iter(range(1, 10_000))

    def _make(**over):
        n = next(counter)
        fields = {
            "url": f"https://posturi.gov.ro/joburi/post-{n}/", "title": f"Post {n}", "employer": employer,
            "expires_at": today() + timedelta(days=20), "cancelled": False,
            "detail_fetched_at": FETCHED, "detail_content_hash": REV,
            "schema_json": schema(), "schema_provider": "deepseek", "schema_model": "deepseek-chat",
            "schema_prompt_version": "v4", "schema_source_revision": REV, "schema_extracted_at": EXTRACTED,
        }
        fields.update(over)
        return JobPosting.objects.create(**fields)

    return _make


def run(*args, expect_error=False):
    out, err = io.StringIO(), io.StringIO()
    if expect_error:
        with pytest.raises(CommandError):
            call_command("observe_postings", *args, stdout=out, stderr=err)
    else:
        call_command("observe_postings", *args, stdout=out, stderr=err)
    return out.getvalue() + err.getvalue()


def summary(text: str) -> dict:
    """The `key=value` pairs of the summary line."""
    line = next(ln for ln in text.splitlines() if "observe_postings:" in ln)
    return dict(p.split("=", 1) for p in line.split("observe_postings:")[1].split())


def observations(url=None):
    qs = PostingObservation.objects.order_by("observed_at", "id")
    return list(qs.filter(url=url) if url else qs)


class TestBaseline:
    def test_the_first_run_records_one_observation_with_full_provenance(self, make):
        p = make()
        s = summary(run())
        assert (s["baseline"], s["inserted"], s["unchanged"]) == ("1", "1", "0")

        (o,) = observations()
        assert (o.url, o.posting_id) == (p.url, p.pk)
        assert o.retrieved_at == FETCHED and o.source_revision == REV
        assert (o.extraction_provider, o.extraction_model, o.extraction_prompt_version) == (
            "deepseek", "deepseek-chat", "v4")
        assert (o.extraction_source_revision, o.extraction_extracted_at) == (REV, EXTRACTED)
        assert o.valid is True and o.facts_format == posting_facts.FACTS_FORMAT == 1
        assert o.facts["deadline"] == "2026-10-30" and o.facts["deadline_source"] == "concurs"
        assert o.facts["cancelled"] is False and o.facts["requirements_available"] is True
        assert o.facts_hash == posting_facts.facts_hash(o.facts)
        assert o.observed_at is not None

    def test_a_second_run_with_nothing_new_inserts_nothing(self, make):
        make()
        run()
        s = summary(run())
        assert (s["baseline"], s["unchanged"], s["inserted"]) == ("0", "1", "0")
        assert PostingObservation.objects.count() == 1

    def test_an_invalid_extraction_is_recorded_as_such(self, make):
        make(schema_source_revision=REV2)                       # made from an older page
        run()
        (o,) = observations()
        assert o.valid is False
        assert o.facts["requirements_available"] is False and o.facts["roles"] is None
        assert o.facts["deadline_source"] == "expirare"          # the stale v4 date is not used

    def test_a_posting_with_no_extraction_at_all_is_observed_without_requirements(self, make):
        make(schema_json=None, schema_provider="", schema_model="", schema_prompt_version="",
             schema_source_revision="", schema_extracted_at=None)
        run()
        (o,) = observations()
        assert o.valid is False and o.facts["roles"] is None and o.facts["deadline_source"] == "expirare"


class TestChanges:
    def test_a_moved_deadline_on_a_new_source_revision_inserts_a_source_change(self, make):
        p = make()
        run()
        JobPosting.objects.filter(pk=p.pk).update(
            detail_content_hash=REV2, schema_source_revision=REV2, schema_json=schema("2026-11-06"),
            detail_fetched_at=FETCHED + timedelta(days=1), schema_extracted_at=EXTRACTED + timedelta(days=1))
        s = summary(run())
        assert (s["source_change"], s["inserted"]) == ("1", "1")

        old, new = observations(p.url)
        assert (old.source_revision, old.facts["deadline"]) == (REV, "2026-10-30")      # immutable
        assert (new.source_revision, new.facts["deadline"]) == (REV2, "2026-11-06")
        assert new.retrieved_at == FETCHED + timedelta(days=1)

    def test_a_model_only_correction_inserts_an_extraction_only_row(self, make):
        p = make()
        run()
        JobPosting.objects.filter(pk=p.pk).update(      # same bytes, re-run on a new model
            schema_model="deepseek-reasoner", schema_extracted_at=EXTRACTED + timedelta(days=2),
            schema_json=schema(education={"minimum_level": "master", "eqf_level": 7, "verbatim": "Master."}))
        s = summary(run())
        assert (s["extraction_only"], s["source_change"], s["inserted"]) == ("1", "0", "1")
        old, new = observations(p.url)
        assert old.source_revision == new.source_revision == REV
        assert (old.extraction_model, new.extraction_model) == ("deepseek-chat", "deepseek-reasoner")
        assert (old.facts["roles"][0]["eqf_level"], new.facts["roles"][0]["eqf_level"]) == (6, 7)

    def test_an_index_level_change_with_no_new_source_inserts_a_facts_only_row(self, make):
        p = make()
        run()
        JobPosting.objects.filter(pk=p.pk).update(expires_at=today() + timedelta(days=45))
        s = summary(run())
        assert (s["facts_only"], s["inserted"]) == ("1", "1")
        old, new = observations(p.url)
        assert (old.facts["expires_at"] != new.facts["expires_at"]
                and old.source_revision == new.source_revision
                and old.extraction_extracted_at == new.extraction_extracted_at)

    def test_a_successful_refresh_with_identical_content_inserts_nothing(self, make):
        """`retrieved_at` moves on the posting; observations are immutable, so the
        verification time of an unchanged refresh lives on the posting, not here."""
        p = make()
        run()
        JobPosting.objects.filter(pk=p.pk).update(detail_fetched_at=FETCHED + timedelta(days=3))
        s = summary(run())
        assert (s["unchanged"], s["inserted"]) == ("1", "0")
        (o,) = observations()
        assert o.retrieved_at == FETCHED

    def test_a_failed_fetch_leaves_the_posting_untouched_and_inserts_nothing(self, make):
        """FIX-03: a failed detail fetch writes nothing — fetched_at and hash stay."""
        p = make()
        run()
        before = JobPosting.objects.values("detail_fetched_at", "detail_content_hash").get(pk=p.pk)
        s = summary(run())          # the next pipeline run, whose fetch failed: same state
        assert JobPosting.objects.values("detail_fetched_at", "detail_content_hash").get(pk=p.pk) == before
        assert (s["unchanged"], s["inserted"]) == ("1", "0")

    def test_a_deadline_that_moves_and_moves_back_is_three_observations(self, make):
        """No unique constraint on the key columns: a revert is a real observation."""
        p = make()
        run()
        for deadline_days in (45, 20):
            JobPosting.objects.filter(pk=p.pk).update(expires_at=today() + timedelta(days=deadline_days))
            run()
        a, b, c = observations(p.url)
        assert a.facts_hash == c.facts_hash != b.facts_hash
        assert (a.source_revision, a.extraction_extracted_at) == (c.source_revision, c.extraction_extracted_at)

    def test_role_reorder_is_recorded_without_claiming_continuity(self, make):
        role_a = {"title": "Expert", "count": 1, "education": {"minimum_level": "licenta", "eqf_level": 6}}
        role_b = {"title": "Referent", "count": 2, "education": {"minimum_level": "liceala", "eqf_level": 4}}
        p = make(schema_json=schema(positions=[role_a, role_b]), schema_source_revision="",
                 schema_extracted_at=None, schema_model="", schema_provider="", schema_prompt_version="")
        run()
        JobPosting.objects.filter(pk=p.pk).update(schema_json=schema(positions=[role_b, role_a]))
        s = summary(run())
        assert s["inserted"] == "1"
        old, new = observations(p.url)
        old_keys = {r["role_key"] for r in old.facts["roles"]}
        new_keys = {r["role_key"] for r in new.facts["roles"]}
        assert not old_keys & new_keys                 # no key survives the restructuring
        assert [r["title"] for r in old.facts["roles"]] == ["Expert", "Referent"]
        assert [r["title"] for r in new.facts["roles"]] == ["Referent", "Expert"]


class TestScope:
    def test_a_posting_without_a_successful_retrieval_is_skipped(self, make):
        make(detail_fetched_at=None, detail_content_hash="")
        s = summary(run())
        assert (s["skipped_no_retrieval"], s["in_scope"], s["inserted"]) == ("1", "0", "0")
        assert PostingObservation.objects.count() == 0

    def test_a_cancelled_posting_without_a_retrieval_is_still_skipped(self, make):
        make(detail_fetched_at=None, cancelled=True)
        assert summary(run())["skipped_no_retrieval"] == "1"
        assert PostingObservation.objects.count() == 0

    def test_the_window_is_expiry_within_n_days(self, make):
        inside = make(expires_at=today() - timedelta(days=29))
        make(expires_at=today() - timedelta(days=45))
        make(expires_at=None)
        run()
        assert [o.url for o in observations()] == [inside.url]
        run("--window-days", "60")
        assert PostingObservation.objects.count() == 2

    def test_window_days_zero_means_only_not_yet_expired(self, make):
        make(expires_at=today() - timedelta(days=1))
        make(expires_at=today())
        run("--window-days", "0")
        assert PostingObservation.objects.count() == 1


class TestCancellation:
    def test_a_cancelled_posting_is_recorded_whatever_its_expiry(self, make):
        p = make(cancelled=True, expires_at=today() - timedelta(days=400))
        run()
        (o,) = observations(p.url)
        assert o.facts["cancelled"] is True

    def test_a_cancellation_after_a_live_observation_is_a_recorded_fact_change(self, make):
        p = make()
        run()
        JobPosting.objects.filter(pk=p.pk).update(cancelled=True)
        s = summary(run())
        assert (s["facts_only"], s["inserted"]) == ("1", "1")
        live, withdrawn = observations(p.url)
        assert (live.facts["cancelled"], withdrawn.facts["cancelled"]) == (False, True)

    def test_a_posting_that_is_merely_absent_from_the_listing_is_not_a_cancellation(self, make):
        """The deploy export drops cancelled rows, so absence there is ambiguous. Here it
        is not: only an observed `cancelled` flag says withdrawn. A posting whose listing
        row vanished keeps its last observation, which still says it was live."""
        p = make(last_seen_at=today() - timedelta(days=12))
        run()
        run()
        (o,) = observations(p.url)
        assert o.facts["cancelled"] is False
        assert PostingObservation.objects.count() == 1

    def test_a_reinstated_posting_is_observed_again(self, make):
        p = make(cancelled=True)
        run()
        JobPosting.objects.filter(pk=p.pk).update(cancelled=False)
        run()
        withdrawn, live = observations(p.url)
        assert (withdrawn.facts["cancelled"], live.facts["cancelled"]) == (True, False)


class TestDryRun:
    def test_dry_run_counts_by_class_and_writes_nothing(self, make):
        make()
        moved = make()
        make(detail_fetched_at=None)
        run()                                           # baselines `fresh` and `moved`
        before = PostingObservation.objects.count()
        JobPosting.objects.filter(pk=moved.pk).update(expires_at=today() + timedelta(days=50))
        newcomer = make()                               # baseline in the dry run

        text = run("--dry-run")
        s = summary(text)
        assert text.startswith("[dry-run]")
        assert (s["baseline"], s["facts_only"], s["unchanged"], s["skipped_no_retrieval"]) == ("1", "1", "1", "1")
        assert s["inserted"] == "0"
        assert PostingObservation.objects.count() == before
        assert not PostingObservation.objects.filter(url=newcomer.url).exists()
        assert len(observations(moved.url)) == 1                # the facts-only row was NOT written

    def test_dry_run_takes_no_advisory_lock_and_survives_an_empty_table(self):
        s = summary(run("--dry-run"))
        assert (s["in_scope"], s["stored"], s["oldest_observed_at"]) == ("0", "0", "none")


class TestRetention:
    def age(self, url, days, n=1, same=False):
        """Insert `n` extra observations of `url` observed `days` ago.

        By default they differ from the current facts (so they are history); with
        `same=True` they are copies of the baseline, i.e. what the posting still says.
        """
        base = observations(url)[0]
        for i in range(n):
            PostingObservation.objects.create(
                url=url, posting_id=base.posting_id, observed_at=dj_tz.now() - timedelta(days=days, minutes=i),
                facts=base.facts, facts_hash=base.facts_hash if same else f"old-{days}-{i}",
                source_revision=base.source_revision,
                extraction_provider=base.extraction_provider, extraction_model=base.extraction_model,
                extraction_prompt_version=base.extraction_prompt_version,
                extraction_source_revision=base.extraction_source_revision,
                extraction_extracted_at=base.extraction_extracted_at)

    def test_default_keeps_everything(self, make):
        p = make()
        run()
        self.age(p.url, 900, n=3)
        s = summary(run())
        assert PostingObservation.objects.count() == 4 and "deleted" not in s

    def test_old_observations_go_but_the_newest_of_each_url_stays(self, make):
        a, b = make(), make()
        run()
        self.age(a.url, 100, n=3)          # a's real baseline is recent → the three old ones are deletable
        self.age(b.url, 100, same=True)
        PostingObservation.objects.filter(url=b.url).exclude(observed_at__lt=dj_tz.now() - timedelta(days=50)).delete()
        # b's only remaining observation is 100 days old and still current — it is the newest, so it stays.
        s = summary(run("--retain-days", "30"))
        assert s["deleted"] == "3"
        assert PostingObservation.objects.filter(url=a.url).count() == 1
        assert not PostingObservation.objects.filter(url=a.url, facts_hash__startswith="old-").exists()
        assert PostingObservation.objects.filter(url=b.url).count() == 1

    def test_an_url_whose_every_observation_is_old_keeps_its_newest(self, make):
        p = make()
        run()
        PostingObservation.objects.filter(url=p.url).update(observed_at=dj_tz.now() - timedelta(days=60))
        self.age(p.url, 90, n=2)
        run("--retain-days", "30")
        (kept,) = observations(p.url)
        assert kept.observed_at > dj_tz.now() - timedelta(days=61)       # the 60-day-old one, not a 90-day one

    def test_the_summary_reports_the_oldest_retained_observation(self, make):
        p = make()
        run()
        self.age(p.url, 10)
        s = summary(run("--retain-days", "30"))
        oldest = datetime.fromisoformat(s["oldest_observed_at"])
        assert abs((dj_tz.now() - timedelta(days=10)) - oldest) < timedelta(minutes=5)
        assert s["deleted"] == "0"

    def test_a_dry_run_only_counts_what_retention_would_delete(self, make):
        p = make()
        run()
        self.age(p.url, 100, n=2)
        s = summary(run("--dry-run", "--retain-days", "30"))
        assert s["would_delete"] == "2" and PostingObservation.objects.count() == 3

    @pytest.mark.parametrize("flags", [["--retain-days", "0"], ["--window-days", "-1"]])
    def test_nonsense_flags_are_errors(self, flags):
        run(*flags, expect_error=True)


class TestRobustness:
    def test_one_unobservable_posting_does_not_stop_the_rest_but_fails_the_run(self, make, monkeypatch):
        good = make()
        bad = make()
        real = posting_facts.tracked_facts

        def flaky(posting, schema):
            if posting["url"] == bad.url:
                raise ValueError("boom")
            return real(posting, schema)

        monkeypatch.setattr(posting_facts, "tracked_facts", flaky)
        text = run(expect_error=True)
        assert bad.url in text
        assert [o.url for o in observations()] == [good.url]

    def test_a_large_run_is_a_handful_of_queries(self, employer, django_assert_max_num_queries):
        JobPosting.objects.bulk_create([
            JobPosting(url=f"https://posturi.gov.ro/joburi/bulk-{i}/", title="t", employer=employer,
                       expires_at=today() + timedelta(days=10), detail_fetched_at=FETCHED,
                       detail_content_hash=REV, schema_json=schema(), schema_source_revision=REV,
                       schema_provider="deepseek", schema_model="deepseek-chat",
                       schema_prompt_version="v4", schema_extracted_at=EXTRACTED)
            for i in range(1100)
        ])
        with django_assert_max_num_queries(25):
            s = summary(run())
        assert (s["baseline"], s["inserted"]) == ("1100", "1100")
        with django_assert_max_num_queries(25):
            assert summary(run())["unchanged"] == "1100"

    def test_the_posting_link_is_cleared_not_the_observation_when_the_posting_is_deleted(self, make):
        p = make()
        run()
        p.delete()
        (o,) = observations()
        assert o.posting_id is None and o.url
