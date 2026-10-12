"""FIX-10 — the `llm_calls` table: one immutable row per provider API attempt.

What is pinned here is the shape the rest of the ledger relies on: a call id that
makes local re-delivery harmless, nullable usage (unknown is not zero), exact money,
and rows that cannot be edited into a different amount of spend.
"""

from datetime import datetime, timedelta, timezone
from decimal import Decimal

import pytest
from django.core.management import call_command
from django.db import IntegrityError, transaction

from apps.jobs.models import LlmCall

pytestmark = pytest.mark.django_db

T0 = datetime(2026, 10, 12, 10, 20, tzinfo=timezone.utc)


def call(**kw):
    base = dict(call_id="run/schema/abc-1", run_id="2026-10-12T10:15:01Z", step="schema",
                subject="medic-specialist", provider="deepseek", model="deepseek-v4-flash",
                prompt_version="v4", started_at=T0, ended_at=T0 + timedelta(seconds=3),
                latency_ms=3000, attempt=1, api_ok=True, outcome="ok")
    base.update(kw)
    return LlmCall(**base)


def test_a_call_keeps_exact_usage_and_money():
    row = call(input_tokens=12_000, output_tokens=2_800, cache_read_tokens=9_000,
               reasoning_tokens=0, cost_usd=Decimal("0.00221700"),
               pricing={"input_per_million": 0.15, "output_per_million": 0.6, "peak": False})
    row.save()
    row.refresh_from_db()
    assert row.cost_usd == Decimal("0.00221700")
    assert row.pricing["peak"] is False
    assert (row.input_tokens, row.output_tokens, row.cache_read_tokens) == (12_000, 2_800, 9_000)


def test_unknown_usage_and_cost_are_null_never_zero():
    row = call(api_ok=False, outcome="transient_error", error_class="APITimeoutError")
    row.save()
    row.refresh_from_db()
    for field in ("input_tokens", "output_tokens", "cache_read_tokens",
                  "cache_write_tokens", "reasoning_tokens", "cost_usd", "pricing"):
        assert getattr(row, field) is None, field


def test_the_same_call_id_cannot_be_stored_twice():
    call().save()
    with pytest.raises(IntegrityError), transaction.atomic():
        call().save()
    assert LlmCall.objects.count() == 1


def test_repeated_calls_for_one_subject_are_separate_rows():
    # Two force re-extractions of one posting are two real payments.
    call(call_id="run1/schema/a-1").save()
    call(call_id="run2/schema/b-1", run_id="2026-10-13T10:15:01Z",
         started_at=T0 + timedelta(days=1), ended_at=T0 + timedelta(days=1, seconds=3)).save()
    assert LlmCall.objects.filter(subject="medic-specialist").count() == 2


def test_a_stored_row_cannot_be_saved_again():
    row = call(cost_usd=Decimal("0.01")).save() or LlmCall.objects.get()
    row.cost_usd = Decimal("0")
    with pytest.raises(RuntimeError, match="append-only"):
        row.save()
    assert LlmCall.objects.get().cost_usd == Decimal("0.01000000")


def test_the_model_and_its_migration_agree():
    # A model change without its migration would abort the next run with exit 78.
    call_command("makemigrations", "jobs", "--check", "--dry-run")
