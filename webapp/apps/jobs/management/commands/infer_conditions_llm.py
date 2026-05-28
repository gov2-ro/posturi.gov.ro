"""LLM queue for low-confidence work-condition inference.

Processes postings where work_type could not be determined by local regex
(work_type_confidence == 0.0 and body >= 250 chars), asking a cheap model
for just 3 fields: work_type, remote_eligible, requires_computer.

Usage:
    python manage.py infer_conditions_llm --limit 100   # process N postings
    python manage.py infer_conditions_llm               # process all queued
"""
from __future__ import annotations

import os
import time
from datetime import datetime, timezone

from django.core.management.base import BaseCommand
from django.db.models import Q

from apps.jobs.models import JobPosting

_PROMPT = """Analizează textul unui anunț de angajare din administrația publică română.
Răspunde EXACT cu 3 linii în format "cheie: valoare":

work_type: norma_intreaga | norma_partiala | schimburi | nespecificat
remote_eligible: da | nu | nespecificat
requires_computer: da | nu | nespecificat

Text anunț (primele 1500 caractere):
{body}
"""

_WORK_TYPE_MAP = {
    "norma_intreaga": "norma_intreaga",
    "norma_partiala": "norma_partiala",
    "schimburi": "schimburi",
    "nespecificat": None,
}


def _parse_llm_response(raw: str) -> dict:
    result: dict = {}
    for line in raw.strip().splitlines():
        if ":" not in line:
            continue
        key, _, val = line.partition(":")
        key = key.strip().lower()
        val = val.strip().lower()
        if key == "work_type":
            result["work_type"] = _WORK_TYPE_MAP.get(val)
            result["work_type_confidence"] = 0.7 if val != "nespecificat" else 0.0
        elif key == "remote_eligible":
            result["remote_eligible"] = True if val == "da" else None
        elif key == "requires_computer":
            if val == "da":
                result["requires_computer"] = True
                if "computer_level" not in result:
                    result["computer_level"] = "basic"
            elif val == "nu":
                result["requires_computer"] = False
            else:
                result["requires_computer"] = None
    return result


def _call_llm(body: str, provider: str) -> str:
    prompt = _PROMPT.format(body=body[:1500])
    if provider == "gemini":
        from google import genai
        client = genai.Client(api_key=os.environ["GOOGLE_API_KEY"])
        return client.models.generate_content(
            model="gemini-2.0-flash-lite", contents=prompt
        ).text.strip()
    elif provider == "openai":
        import openai
        client = openai.OpenAI(api_key=os.environ["OPENAI_API_KEY"])
        return client.chat.completions.create(
            model="gpt-4o-mini",
            messages=[{"role": "user", "content": prompt}],
            max_tokens=60,
        ).choices[0].message.content.strip()
    elif provider == "anthropic":
        import anthropic
        client = anthropic.Anthropic(api_key=os.environ["ANTHROPIC_API_KEY"])
        return client.messages.create(
            model="claude-haiku-4-5-20251001",
            max_tokens=60,
            messages=[{"role": "user", "content": prompt}],
        ).content[0].text.strip()
    raise ValueError(f"Unknown provider: {provider}")


class Command(BaseCommand):
    help = "LLM queue for low-confidence work-condition inference."

    def add_arguments(self, parser):
        default_provider = os.environ.get("LLM_PROVIDER", "gemini")
        parser.add_argument(
            "--provider",
            choices=["gemini", "openai", "anthropic"],
            default=default_provider,
        )
        parser.add_argument("--limit", type=int, default=None)

    def handle(self, *args, **opts):
        provider = opts["provider"]

        # Queue: explicitly confidence=0.0 OR work_type null + body >= 250 chars
        qs = JobPosting.objects.filter(
            Q(inferred__work_type_confidence=0.0) |
            Q(inferred__work_type__isnull=True)
        ).exclude(body_markdown__isnull=True).order_by("id")

        if opts["limit"]:
            qs = qs[: opts["limit"]]

        # Filter to postings with enough body text (can't do len() in ORM easily;
        # we filter in Python after fetching the small set)
        candidates = [p for p in qs.iterator(chunk_size=200)
                      if len((p.body_markdown or "").strip()) >= 250]

        total = len(candidates)
        self.stdout.write(f"LLM conditions queue: {total} postings (provider={provider})…")

        done = errors = 0
        for posting in candidates:
            body = (posting.body_markdown or "") + "\n\n" + (posting.attachment_text or "")
            try:
                raw = _call_llm(body, provider)
                updates = _parse_llm_response(raw)
                if not updates:
                    continue
                existing = posting.inferred or {}
                merged = {
                    **existing,
                    **updates,
                    "conditions_inferred_at": datetime.now(tz=timezone.utc).isoformat(),
                }
                JobPosting.objects.filter(pk=posting.pk).update(inferred=merged)
                done += 1
                time.sleep(0.3)
            except Exception as exc:
                self.stderr.write(f"  Error pk={posting.pk}: {exc}")
                errors += 1

            if done % 100 == 0 and done:
                self.stdout.write(f"  {done}/{total} done, {errors} errors")

        self.stdout.write(self.style.SUCCESS(f"Done. {done} updated, {errors} errors."))
