"""Attachment download cache: identity, validation, resolution.

One module owns the download cache so the downloader, the extraction command
and the quality checker agree on where a URL's file lives and whether it is
usable. Before this existed, each of them took ``os.path.basename(url)`` —
which breaks on query strings and lets two URLs sharing a basename silently
overwrite each other — and every existing file was trusted as a cache hit, so
an interrupted transfer became a permanent partial file.

Layout
------
New downloads land as ``<key>.<ext>`` where ``key`` is a short hash of the
normalized URL (scheme/host lowercased, fragment dropped, query kept) and
``ext`` comes from the URL path — or, when the path has none, from the sniffed
format. A ``<key>.json`` manifest records the source URL, content hash, size,
retrieval time and content type, so cache identity is explicit rather than
encoded in a filename.

Legacy files (``<basename>`` straight from the URL, no manifest) are still
resolved for compatibility; ``adopt_legacy_files()`` is the bounded migration
that validates them, moves the valid ones into the hashed cache and records
the invalid ones in ``legacy-invalid.json`` — it never deletes anything.

Validation
----------
A cache hit is a file that exists *and* passes ``validate_file``: non-empty,
magic bytes matching one of the supported formats (ZIP/DOCX, OLE2/DOC, PDF)
and — for DOCX — an intact ZIP container. Downloads get the same checks plus a
Content-Length comparison where the server sends one, and HTML error pages
served with HTTP 200 are rejected by sniffing, not by trusting Content-Type.

Changed remote content is FIX-03's revision handling; this module only
guarantees that what sits in the cache is a complete, correctly-shaped file.
"""

from __future__ import annotations

import hashlib
import io
import json
import os
import time
import zipfile
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

#: Hard bounds for one transfer. Chunked responses without Content-Length are
#: still bounded by these.
MAX_BYTES = 100 * 1024 * 1024      # 100 MB
STREAM_BUDGET_SECONDS = 300        # total stream time
REQUEST_TIMEOUT_SECONDS = 30       # per-request connect/read timeout

#: Formats the pipeline can extract. Anything else gets an explicit reason
#: instead of being fetched and then ignored.
SUPPORTED_FORMATS = {"docx", "doc", "pdf"}

MANIFEST_FORMAT = 1


# ─── identity ────────────────────────────────────────────────────────────────


def normalize_url(url: str) -> str:
    """One canonical spelling per URL: lowercased scheme/host, default ports
    stripped, fragment dropped, query kept. The path stays verbatim — some
    hosts are case-sensitive in practice."""
    parts = urlsplit(url.strip())
    host = (parts.hostname or "").lower()
    try:
        port = parts.port
    except ValueError:
        port = None  # unparsable port: keep the host as-is below
    netloc = host
    if port is not None and not ((parts.scheme == "http" and port == 80) or (parts.scheme == "https" and port == 443)):
        netloc = f"{host}:{port}"
    return urlunsplit((parts.scheme.lower(), netloc, parts.path, parts.query, ""))


def cache_key(url: str) -> str:
    """Short stable identity for a normalized URL (96 bits)."""
    return hashlib.sha256(normalize_url(url).encode("utf-8")).hexdigest()[:24]


def extension_from_url(url: str) -> str:
    """Lowercased, sanitized suffix of the URL path — '' when there is none."""
    path = urlsplit(url).path
    suffix = Path(path).suffix.lower().lstrip(".")
    return "".join(ch for ch in suffix if ch.isalnum()) or ""


def hashed_path(downloads_dir: Path, url: str, ext: str | None = None) -> Path:
    ext = ext if ext is not None else extension_from_url(url)
    return downloads_dir / (f"{cache_key(url)}.{ext}" if ext else cache_key(url))


def legacy_path(downloads_dir: Path, url: str) -> Path:
    """Where the pre-cache downloader put this URL's file: bare basename."""
    return downloads_dir / Path(urlsplit(url).path).name


def manifest_path(downloads_dir: Path, url: str) -> Path:
    return downloads_dir / (cache_key(url) + ".json")


# ─── sniffing and validation ─────────────────────────────────────────────────


def sniff_kind(head: bytes) -> str | None:
    """Format from magic bytes: 'docx' | 'doc' | 'pdf' | None."""
    if head.startswith(b"PK\x03\x04"):
        return "docx"
    if head.startswith(b"%PDF-"):
        return "pdf"
    if head.startswith(b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1"):
        return "doc"
    return None


def validate_bytes(data: bytes) -> tuple[bool, str]:
    """Validate a fully received body. Returns (ok, reason)."""
    if len(data) == 0:
        return False, "empty file"
    kind = sniff_kind(data[:8])
    if kind is None:
        stripped = data[:512].lstrip(b"\xef\xbb\xbf \t\r\n").lower()
        if stripped.startswith(b"<!doctype html") or stripped.startswith(b"<html"):
            return False, "HTML error page served as the document"
        return False, "unsupported format"
    if kind == "docx":
        try:
            with zipfile.ZipFile(io.BytesIO(data)) as zf:
                bad = zf.testzip()
        except Exception as exc:
            return False, f"corrupt docx: {exc}"
        if bad is not None:
            return False, f"corrupt docx member: {bad}"
    if kind == "pdf":
        if b"%%EOF" not in data[-1024:].rstrip():
            return False, "truncated pdf (no %%EOF)"
    return True, kind


def validate_file(path: Path, deep: bool = False) -> tuple[bool, str]:
    """Validate a file already on disk. Returns (ok, reason).

    Shallow (the hot path — every cache resolution): non-empty, magic bytes
    match a supported format, HTML error pages are rejected. Deep adds DOCX
    ZIP-container integrity (reads every member) for audits and repairs.
    """
    try:
        size = path.stat().st_size
    except OSError as exc:
        return False, f"unreadable: {exc}"
    if size == 0:
        return False, "empty file"
    try:
        with open(path, "rb") as fh:
            head = fh.read(512)
    except OSError as exc:
        return False, f"unreadable: {exc}"
    kind = sniff_kind(head[:8])
    if kind is None:
        stripped = head.lstrip(b"\xef\xbb\xbf \t\r\n").lower()
        if stripped.startswith(b"<!doctype html") or stripped.startswith(b"<html"):
            return False, "HTML error page served as the document"
        return False, "unsupported format"
    if kind == "docx" and deep:
        try:
            with zipfile.ZipFile(path) as zf:
                bad = zf.testzip()
        except Exception as exc:
            return False, f"corrupt docx: {exc}"
        if bad is not None:
            return False, f"corrupt docx member: {bad}"
    return True, kind


# ─── manifest ────────────────────────────────────────────────────────────────


def _manifest_record(url: str, path: Path, sha256: str, content_type: str) -> dict:
    return {
        "format": MANIFEST_FORMAT,
        "key": cache_key(url),
        "url": normalize_url(url),
        "ext": path.suffix.lstrip("."),
        "bytes": path.stat().st_size,
        "sha256": sha256,
        "retrieved_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "content_type": content_type,
    }


def write_manifest(downloads_dir: Path, url: str, path: Path, sha256: str, content_type: str) -> Path:
    """Atomic manifest write: temp file + rename, like the download itself."""
    target = manifest_path(downloads_dir, url)
    tmp = target.with_name(target.name + ".tmp")
    tmp.write_text(
        json.dumps(_manifest_record(url, path, sha256, content_type), ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    os.replace(tmp, target)
    return target


def read_manifest(downloads_dir: Path, url: str) -> dict | None:
    try:
        data = json.loads(manifest_path(downloads_dir, url).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return None
    return data if data.get("format") == MANIFEST_FORMAT else None


# ─── resolution ──────────────────────────────────────────────────────────────


@dataclass
class Resolved:
    path: Path | None
    status: str = ""      # "cached" | "cached-legacy" | "invalid" | "missing"
    reason: str = ""      # why, when not a clean hit


def resolve_local_path(downloads_dir: Path, url: str) -> Resolved:
    """Where the cache holds this URL, and whether that file is usable.

    Hashed-cache entries with a manifest and a valid file win; a hashed file
    that fails validation reports `invalid` so callers can re-download instead
    of silently trusting it. Legacy basename files keep working for
    compatibility and count as hits when they validate.
    """
    if not url:
        return Resolved(None, "missing", "empty url")

    target = hashed_path(downloads_dir, url)
    if target.exists():
        manifest = read_manifest(downloads_dir, url)
        if manifest is not None:
            ok, reason = validate_file(target)
            if ok:
                # Truncation after the manifest was written shows up here —
                # the magic bytes can survive a partial overwrite.
                if manifest.get("bytes") is not None and target.stat().st_size != manifest["bytes"]:
                    return Resolved(target, "invalid", "cached file size differs from manifest")
                return Resolved(target, "cached")
            return Resolved(target, "invalid", f"cached file invalid: {reason}")
        # No manifest: pre-cache copy of the same name — judge it by content.
        ok, reason = validate_file(target)
        if ok:
            return Resolved(target, "cached-legacy")
        return Resolved(target, "invalid", f"invalid: {reason}")

    legacy = legacy_path(downloads_dir, url)
    if legacy.exists() and legacy != target:
        ok, reason = validate_file(legacy)
        if ok:
            return Resolved(legacy, "cached-legacy")
        return Resolved(legacy, "invalid", f"legacy file invalid: {reason}")

    return Resolved(None, "missing", "not downloaded")


# ─── legacy adoption ─────────────────────────────────────────────────────────


def adopt_legacy_files(downloads_dir: Path, urls: list[str]) -> dict:
    """Bounded migration: validate legacy basename files against their URLs.

    Valid files move into the hashed cache with a manifest; invalid ones stay
    in place and are recorded in ``legacy-invalid.json`` so the reason is
    durable. Nothing is deleted. Returns the counts.
    """
    counts: dict[str, int] = {"adopted": 0, "invalid": 0, "missing": 0, "unrelated": 0}
    invalid_records: dict[str, str] = {}

    for url in urls:
        legacy = legacy_path(downloads_dir, url)
        if not legacy.exists():
            counts["missing"] += 1
            continue
        if hashed_path(downloads_dir, url).exists() and read_manifest(downloads_dir, url) is not None:
            counts["unrelated"] += 1  # a hashed entry already exists; leave the legacy file alone
            continue
        ok, reason = validate_file(legacy, deep=True)   # full container check for a one-off migration
        if not ok:
            counts["invalid"] += 1
            invalid_records[legacy.name] = reason
            continue
        target = hashed_path(downloads_dir, url, ext=legacy.suffix.lstrip("."))
        if target.exists():
            counts["unrelated"] += 1
            continue
        sha = hashlib.sha256(legacy.read_bytes()).hexdigest()
        os.replace(legacy, target)
        write_manifest(downloads_dir, url, target, sha, "")
        counts["adopted"] += 1

    if invalid_records:
        report = downloads_dir / "legacy-invalid.json"
        try:
            existing: dict = json.loads(report.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            existing = {}
        existing.update(invalid_records)
        report.write_text(json.dumps(existing, ensure_ascii=False, indent=2), encoding="utf-8")

    return counts


# ─── download ────────────────────────────────────────────────────────────────


@dataclass
class DownloadResult:
    status: str                      # downloaded | replaced | cached | cached-legacy | invalid | failed
    path: Path | None = None
    reason: str = ""
    bytes: int = 0


def download_file(
    url: str,
    downloads_dir: Path,
    session=None,
    max_bytes: int = MAX_BYTES,
    stream_budget: float = STREAM_BUDGET_SECONDS,
    timeout: float = REQUEST_TIMEOUT_SECONDS,
) -> DownloadResult:
    """Fetch one URL into the cache atomically.

    Streams to a unique temp file beside the target and promotes it with a
    rename only after the transfer completed within the size/time bounds and
    the body validated. A failed replacement leaves any existing valid file
    untouched; the final path never describes an incomplete transfer.
    """
    downloads_dir.mkdir(parents=True, exist_ok=True)

    existing = resolve_local_path(downloads_dir, url)
    if existing.status == "cached":
        return DownloadResult("cached", existing.path, bytes=existing.path.stat().st_size)

    ext = extension_from_url(url)
    target = hashed_path(downloads_dir, url)
    tmp = downloads_dir / f".tmp-{cache_key(url)}-{os.getpid()}"

    try:
        if session is None:
            import requests

            session = requests.Session()
        response = session.get(
            url,
            timeout=timeout,
            stream=True,
            headers={
                "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36",
                "Accept": "*/*",
                "Referer": "https://posturi.gov.ro/",
            },
        )
        response.raise_for_status()

        declared = response.headers.get("Content-Length")
        if declared is not None:
            try:
                declared = int(declared)
            except ValueError:
                declared = None

        received = 0
        deadline = time.monotonic() + stream_budget
        with open(tmp, "wb") as fh:
            for chunk in response.iter_content(chunk_size=65536):
                if not chunk:
                    continue
                received += len(chunk)
                if received > max_bytes:
                    return DownloadResult("invalid", reason=f"exceeds {max_bytes // (1024 * 1024)} MB limit")
                if time.monotonic() > deadline:
                    return DownloadResult("invalid", reason=f"stream exceeded {int(stream_budget)}s budget")
                fh.write(chunk)

        if declared is not None and received != declared:
            return DownloadResult("invalid", reason=f"length mismatch: got {received}, expected {declared}")
        if received == 0:
            return DownloadResult("invalid", reason="empty file")

        data = tmp.read_bytes()
        ok, verdict = validate_bytes(data)
        if not ok:
            return DownloadResult("invalid", reason=verdict)

        # The URL path may carry no extension (download endpoints); the sniffed
        # format names the file so extraction still knows what it is.
        kind = verdict
        if ext == "" or ext not in SUPPORTED_FORMATS:
            ext = kind
            target = hashed_path(downloads_dir, url, ext=ext)

        sha = hashlib.sha256(data).hexdigest()
        os.replace(tmp, target)   # atomic: tmp is a temp file, rename promotes it
        write_manifest(downloads_dir, url, target, sha, response.headers.get("Content-Type", ""))
        # "replaced" only when an invalid file sat at this URL's *hashed*
        # target and its bytes were swapped for a validated body. An invalid
        # legacy file is left in place and the fresh copy counts as downloaded.
        status = "replaced" if existing.path == target else "downloaded"
        return DownloadResult(status, target, bytes=received)
    except Exception as exc:  # requests exceptions, disk errors — never a partial final file
        return DownloadResult("failed", reason=f"{type(exc).__name__}: {exc}")
    finally:
        try:
            tmp.unlink()
        except OSError:
            pass


# ─── summary helper ──────────────────────────────────────────────────────────


@dataclass
class DownloadSummary:
    counts: dict[str, int] = field(default_factory=dict)
    failures: list[tuple[str, str]] = field(default_factory=list)

    def record(self, result: DownloadResult, url: str) -> None:
        self.counts[result.status] = self.counts.get(result.status, 0) + 1
        if result.status in ("invalid", "failed"):
            self.failures.append((url, result.reason))

    def unusable(self) -> bool:
        """FIX-04 convention: a batch where most attempts failed is not a
        successful run, even though individual failures are expected."""
        attempted = self.counts.get("downloaded", 0) + self.counts.get("replaced", 0) \
            + self.counts.get("invalid", 0) + self.counts.get("failed", 0)
        if attempted == 0:
            return False  # nothing to do is healthy only when selection succeeded
        bad = self.counts.get("invalid", 0) + self.counts.get("failed", 0)
        return bad > attempted / 2

    def lines(self) -> list[str]:
        total = sum(self.counts.values())
        parts = [f"{n} {status}" for status, n in sorted(self.counts.items())]
        return [f"Attachments: {', '.join(parts) or 'nothing to do'} ({total} URLs total)."]
