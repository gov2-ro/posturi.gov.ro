"""Extract plain text from downloaded attachment files and store in JobPosting.attachment_text.

Reads announcement_url and other_links for each posting, locates the corresponding file
in data/downloads/, extracts text based on extension, and saves the result.

Usage:
    python manage.py extract_attachments [--data-dir ../data] [--force] [--limit N]
"""
from __future__ import annotations

import os
import subprocess
from collections import Counter
from pathlib import Path

from django.conf import settings
from django.core.management.base import BaseCommand
from django.db.models import Q

from apps.jobs.attachments import classify_attachment
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


#: Converters for legacy .doc, tried in order. The first one present on the
#: machine that produces output wins.
_DOC_CONVERTERS = (
    ["textutil", "-convert", "txt", "-stdout"],  # macOS, built in
    ["antiword"],                                # Debian/Ubuntu: apt install antiword
    ["catdoc"],                                  # ditto: apt install catdoc
)


def _extract_doc(path: Path) -> str:
    """Legacy Word .doc (OLE2 compound file) -> plain text.

    `docx2txt` only understands the ZIP-based .docx container and raises
    KeyError/BadZipFile on a real .doc, which `extract_text` swallowed into an
    empty string -- so 167 active postings silently had no attachment text at
    all, and the LLM extraction ran on the web body alone. `quality_check.py`
    was switched to `textutil` in May 2026; this command was not.

    Some servers hand out a genuine .docx under a .doc name, so sniff the
    container first rather than trusting the extension.
    """
    with open(path, "rb") as fh:
        if fh.read(2) == b"PK":          # ZIP magic -> actually a .docx
            return _extract_docx(path)

    for argv in _DOC_CONVERTERS:
        try:
            result = subprocess.run(
                [*argv, str(path)], capture_output=True, text=True, timeout=60,
            )
        except (FileNotFoundError, subprocess.TimeoutExpired):
            continue  # converter not installed, or a pathological file
        if result.returncode == 0 and result.stdout.strip():
            return result.stdout
    return ""


def _extract_pdf(path: Path) -> str:
    from pypdf import PdfReader
    reader = PdfReader(str(path))
    pages = [page.extract_text() or "" for page in reader.pages]
    return "\n".join(pages).strip()


def extract_text(path: Path) -> tuple[str, str]:
    """Return (text, reason). `reason` is empty on success, else why it failed.

    Previously this swallowed every exception into "", so a systematically
    broken extractor was indistinguishable from a genuinely empty document --
    which is how a .doc extractor that failed on 100% of its input went
    unnoticed.
    """
    suffix = path.suffix.lower()
    extractor = {".docx": _extract_docx, ".doc": _extract_doc, ".pdf": _extract_pdf}.get(suffix)
    if extractor is None:
        return "", f"unsupported extension {suffix or '(none)'}"
    try:
        text = extractor(path)
    except Exception as exc:
        return "", f"{type(exc).__name__}: {exc}"
    if not text.strip():
        # Almost always a scanned PDF with no text layer.
        return "", "no text layer / empty document"
    return text, ""


def extract_for_posting(posting: JobPosting, downloads_dir: Path) -> tuple[str, list[str], list[dict]]:
    """Collect text from every attachment linked to a posting.

    Returns (combined_text, failures) where each failure is
    "<filename>: <reason>", so a systematic extractor breakage shows up in the
    run summary instead of looking like a pile of empty documents.
    """
    urls = []
    if posting.announcement_url:
        urls.append(posting.announcement_url)
    if posting.other_links:
        urls.extend(posting.other_links)

    parts: list[str] = []
    failures: list[str] = []
    meta: list[dict] = []
    for i, url in enumerate(urls):
        role = "announcement" if (i == 0 and posting.announcement_url) else "other"
        path = _local_path(url, downloads_dir)
        if path is None:
            failures.append(f"{url.rstrip('/').split('/')[-1]}: not downloaded")
            meta.append({"url": url, "ext": "", "bytes": None, "kind": None, "role": role})
            continue
        text, reason = extract_text(path)
        if text.strip():
            parts.append(text.strip())
        else:
            failures.append(f"{path.name}: {reason}")
        meta.append({
            "url": url,
            "ext": path.suffix.lstrip(".").upper(),
            "bytes": path.stat().st_size,
            # None for an ordinary announcement, which is ~89% of them.
            "kind": classify_attachment(text),
            "role": role,
        })

    return "\n\n".join(parts), failures, meta


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
        failure_reasons: Counter[str] = Counter()

        for posting in qs.iterator(chunk_size=200):
            try:
                text, failures, meta = extract_for_posting(posting, downloads_dir)
                JobPosting.objects.filter(pk=posting.pk).update(
                    attachment_text=text, attachment_meta=meta,
                )
                done += 1
                for f in failures:
                    # Group by reason, not by file, so "docx2txt fails on every
                    # .doc" reads as one line rather than 167.
                    failure_reasons[f.split(": ", 1)[-1].split(":")[0]] += 1
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
            f"Done. {extracted} extracted, {skipped} with no usable text, {errors} errors."
        ))
        if failure_reasons:
            self.stdout.write("Attachments that yielded no text, by reason:")
            for reason, count in failure_reasons.most_common():
                self.stdout.write(f"  {count:6}  {reason}")
