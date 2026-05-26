"""Extract plain text from downloaded attachment files and store in JobPosting.attachment_text.

Reads announcement_url and other_links for each posting, locates the corresponding file
in data/downloads/, extracts text based on extension, and saves the result.

Usage:
    python manage.py extract_attachments [--data-dir ../data] [--force] [--limit N]
"""
from __future__ import annotations

import os
from pathlib import Path

from django.conf import settings
from django.core.management.base import BaseCommand
from django.db.models import Q

from apps.jobs.models import JobPosting


def _local_path(url: str, downloads_dir: Path) -> Path | None:
    if not url:
        return None
    name = os.path.basename(url)
    p = downloads_dir / name
    return p if p.exists() else None


def _extract_docx(path: Path) -> str:
    from docx import Document
    doc = Document(path)
    return "\n".join(p.text for p in doc.paragraphs if p.text.strip())


def _extract_doc(path: Path) -> str:
    import docx2txt
    return docx2txt.process(str(path)) or ""


def _extract_pdf(path: Path) -> str:
    from pypdf import PdfReader
    reader = PdfReader(str(path))
    pages = [page.extract_text() or "" for page in reader.pages]
    return "\n".join(pages).strip()


def extract_text(path: Path) -> str:
    """Return extracted plain text from path, or empty string on failure."""
    suffix = path.suffix.lower()
    try:
        if suffix == ".docx":
            return _extract_docx(path)
        if suffix == ".doc":
            return _extract_doc(path)
        if suffix == ".pdf":
            return _extract_pdf(path)
    except Exception:
        return ""
    return ""


def extract_for_posting(posting: JobPosting, downloads_dir: Path) -> str:
    """Collect text from all attachment files linked to a posting."""
    urls = []
    if posting.announcement_url:
        urls.append(posting.announcement_url)
    if posting.other_links:
        urls.extend(posting.other_links)

    parts: list[str] = []
    for url in urls:
        path = _local_path(url, downloads_dir)
        if path is None:
            continue
        text = extract_text(path)
        if text.strip():
            parts.append(text.strip())

    return "\n\n".join(parts)


class Command(BaseCommand):
    help = "Extract text from downloaded attachments and store in JobPosting.attachment_text."

    def add_arguments(self, parser):
        parser.add_argument(
            "--data-dir",
            type=Path,
            default=settings.DATA_DIR,
            help="Path to the data/ directory (default: settings.DATA_DIR).",
        )
        parser.add_argument(
            "--force",
            action="store_true",
            help="Re-extract postings that already have attachment_text.",
        )
        parser.add_argument(
            "--limit",
            type=int,
            default=None,
            help="Process at most N postings.",
        )

    def handle(self, *args, **opts):
        downloads_dir = opts["data_dir"] / "downloads"
        if not downloads_dir.exists():
            self.stderr.write(self.style.ERROR(f"Downloads directory not found: {downloads_dir}"))
            return

        qs = JobPosting.objects.exclude(
            Q(announcement_url="") & Q(other_links=[])
        ).order_by("id")

        if not opts["force"]:
            qs = qs.filter(attachment_text="")

        if opts["limit"]:
            qs = qs[: opts["limit"]]

        total = qs.count()
        self.stdout.write(f"Processing {total} postings (downloads: {downloads_dir})…")

        done = extracted = skipped = errors = 0

        for posting in qs.iterator(chunk_size=200):
            try:
                text = extract_for_posting(posting, downloads_dir)
                JobPosting.objects.filter(pk=posting.pk).update(attachment_text=text)
                done += 1
                if text.strip():
                    extracted += 1
                else:
                    skipped += 1
            except Exception as exc:
                self.stderr.write(f"  Error pk={posting.pk}: {exc}")
                errors += 1
                done += 1

            if done % 200 == 0:
                self.stdout.write(
                    f"  {done}/{total} done — {extracted} extracted, {skipped} no file, {errors} errors"
                )

        self.stdout.write(self.style.SUCCESS(
            f"Done. {extracted} extracted, {skipped} no file found, {errors} errors."
        ))
