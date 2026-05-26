"""
Canonicalize employer names across the 2,955 Employer records.

Groups employers by a normalized key (NFKD, strip diacritics, lowercase,
collapse punctuation/whitespace) and picks the "best" name in each group
as the canonical record. All JobPosting.employer FKs are repointed to the
canonical, and an EmployerAlias row is written for each merged variant.

Usage:
    python manage.py canonicalize_employers          # dry-run, show report
    python manage.py canonicalize_employers --apply  # write to DB
"""

import re
import unicodedata

from django.core.management.base import BaseCommand
from django.db import transaction

from apps.jobs.models import Employer, EmployerAlias, JobPosting


def _normalize(name: str) -> str:
    """NFKD → strip combining chars → lowercase → punctuation→space → collapse ws."""
    n = "".join(c for c in unicodedata.normalize("NFKD", name) if not unicodedata.combining(c))
    n = n.lower()
    n = re.sub(r"[^\w\s]", " ", n)
    return re.sub(r"\s+", " ", n).strip()


def _quality_score(name: str) -> int:
    """Higher = better canonical candidate.

    Prefer names that:
    - Have Romanian diacritics (ș ț ă î â) — +2 each
    - Are title-cased (not ALL CAPS) — +10
    - Are shorter (fewer redundant words) — tiny penalty for length
    """
    diacritics = "șțăîâșțăîâ"
    score = sum(2 for c in name if c in diacritics)
    if name != name.upper():
        score += 10
    score -= len(name) // 100  # slight length penalty
    return score


def _pick_canonical(variants: list[Employer]) -> Employer:
    return max(variants, key=lambda e: _quality_score(e.name))


class Command(BaseCommand):
    help = "Group duplicate employer names and optionally merge them."

    def add_arguments(self, parser):
        parser.add_argument(
            "--apply",
            action="store_true",
            help="Write EmployerAlias records and reassign JobPosting FKs (default: dry-run).",
        )

    def handle(self, *args, **opts):
        apply = opts["apply"]

        employers = list(Employer.objects.all())
        self.stdout.write(f"Loaded {len(employers)} employers.")

        # Group by normalized key
        groups: dict[str, list[Employer]] = {}
        for emp in employers:
            key = _normalize(emp.name)
            groups.setdefault(key, []).append(emp)

        duplicate_groups = {k: v for k, v in groups.items() if len(v) > 1}
        all_variants = sum(len(v) for v in duplicate_groups.values())
        canonical_count = len(duplicate_groups)
        merged_count = all_variants - canonical_count

        self.stdout.write(
            f"Found {len(duplicate_groups)} duplicate groups "
            f"({all_variants} employers, {merged_count} to merge)."
        )

        if not apply:
            self.stdout.write("\nDry-run — top 20 groups (use --apply to commit):\n")
            for key, variants in list(duplicate_groups.items())[:20]:
                canonical = _pick_canonical(variants)
                self.stdout.write(f"  canonical: {canonical.name}")
                for v in variants:
                    if v.pk != canonical.pk:
                        count = v.postings.count()
                        self.stdout.write(f"    alias ({count} postings): {v.name}")
            return

        # --- Apply ---
        alias_created = reassigned_postings = 0

        with transaction.atomic():
            for key, variants in duplicate_groups.items():
                canonical = _pick_canonical(variants)
                non_canonical = [v for v in variants if v.pk != canonical.pk]

                for variant in non_canonical:
                    # Reassign all postings from this variant to canonical
                    n = JobPosting.objects.filter(employer=variant).update(employer=canonical)
                    reassigned_postings += n

                    # Record the alias (skip if already exists)
                    _, created = EmployerAlias.objects.get_or_create(
                        alias_name=variant.name,
                        defaults={"canonical": canonical, "auto_generated": True},
                    )
                    if created:
                        alias_created += 1

        self.stdout.write(
            self.style.SUCCESS(
                f"Done. {alias_created} aliases created, "
                f"{reassigned_postings} posting FKs reassigned."
            )
        )
