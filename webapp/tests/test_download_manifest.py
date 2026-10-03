"""FIX-08 — atomic download cache: identity, validation, resolution.

Exercises download_manifest against the acceptance matrix from
docs/specs/2026-10-03-project-audit/08-attachment-downloads.md: interrupted
streams, timeouts, empty files, length mismatches, HTML-with-200, malformed
DOCX, valid chunked PDF, duplicate basenames, query-string URLs, replacement
of existing files and legacy compatibility. No network — the session is a
fake with a scripted body.
"""

from __future__ import annotations

import io
import sys
import zipfile
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

from download_manifest import (  # noqa: E402
    DownloadResult,
    adopt_legacy_files,
    cache_key,
    download_file,
    hashed_path,
    legacy_path,
    normalize_url,
    resolve_local_path,
    validate_bytes,
    validate_file,
)

# ─── fixture documents ───────────────────────────────────────────────────────


def make_docx() -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        zf.writestr("[Content_Types].xml", "<Types/>")
        zf.writestr("word/document.xml", "<w:document/>")
    return buf.getvalue()


def make_pdf() -> bytes:
    return b"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n"


def make_doc() -> bytes:
    return b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" + b"\x00" * 128


def make_broken_docx() -> bytes:
    return b"PK\x03\x04" + b"not a real zip archive"


class FakeResponse:
    def __init__(self, body=b"", headers=None, chunks=None, status=200):
        self._chunks = chunks if chunks is not None else [body]
        self.headers = {"Content-Type": "application/octet-stream", **(headers or {})}
        self.status_code = status

    def raise_for_status(self):
        if self.status_code >= 400:
            raise RuntimeError(f"HTTP {self.status_code}")

    def iter_content(self, chunk_size=65536):
        yield from self._chunks


class FakeSession:
    def __init__(self, response):
        self.response = response
        self.calls = []

    def get(self, url, **kwargs):
        self.calls.append((url, kwargs))
        return self.response


# ─── identity ────────────────────────────────────────────────────────────────


class TestIdentity:
    def test_normalize_lowercases_scheme_host_and_drops_fragment(self):
        assert normalize_url("HTTPS://Posturi.GOV.ro/Doc.PDF#x") == "https://posturi.gov.ro/Doc.PDF"

    def test_query_string_is_part_of_identity(self):
        assert cache_key("http://x.ro/a.doc?download=1") != cache_key("http://x.ro/a.doc")

    def test_duplicate_basenames_get_distinct_paths(self):
        a, b = "http://host.ro/one/a.doc", "http://other.ro/two/a.doc"
        assert hashed_path(Path("/tmp"), a) != hashed_path(Path("/tmp"), b)
        assert legacy_path(Path("/tmp"), a) == legacy_path(Path("/tmp"), b) == Path("/tmp/a.doc")


# ─── validation ──────────────────────────────────────────────────────────────


class TestValidation:
    @pytest.mark.parametrize("body", [make_docx(), make_pdf(), make_doc()])
    def test_valid_documents_pass(self, body):
        ok, reason = validate_bytes(body)
        assert ok, reason

    def test_empty_body_fails(self):
        ok, reason = validate_bytes(b"")
        assert not ok and reason == "empty file"

    def test_broken_docx_fails(self):
        ok, reason = validate_bytes(make_broken_docx())
        assert not ok and "docx" in reason

    def test_truncated_pdf_fails(self):
        ok, reason = validate_bytes(b"%PDF-1.4\nno end marker")
        assert not ok and "pdf" in reason

    @pytest.mark.parametrize("html", [b"<!doctype html><html>login</html>", b"<HTML><body>portal</body></HTML>"])
    def test_html_error_pages_fail(self, html):
        ok, reason = validate_bytes(html)
        assert not ok and "HTML" in reason

    def test_random_bytes_fail_with_unsupported_format(self):
        ok, reason = validate_bytes(b"\x01\x02\x03 binary blob")
        assert not ok and "unsupported" in reason

    def test_validate_file_agrees_with_validate_bytes(self, tmp_path):
        f = tmp_path / "a.docx"
        f.write_bytes(make_docx())
        ok, _ = validate_file(f)
        assert ok
        f.write_bytes(b"")
        ok, reason = validate_file(f)
        assert not ok and reason == "empty file"


# ─── resolution ──────────────────────────────────────────────────────────────


class TestResolution:
    def test_missing_reports_reason(self, tmp_path):
        r = resolve_local_path(tmp_path, "http://x.ro/absent.docx")
        assert r.path is None and r.status == "missing" and "downloaded" in r.reason

    def test_legacy_valid_file_is_a_hit(self, tmp_path):
        legacy_path(tmp_path, "http://x.ro/doc.docx").write_bytes(make_docx())
        r = resolve_local_path(tmp_path, "http://x.ro/doc.docx")
        assert r.path is not None and r.status == "cached-legacy"

    def test_legacy_invalid_file_reports_reason(self, tmp_path):
        legacy_path(tmp_path, "http://x.ro/doc.docx").write_bytes(b"truncated")
        r = resolve_local_path(tmp_path, "http://x.ro/doc.docx")
        assert r.path is not None and r.status == "invalid" and "invalid" in r.reason

    def test_hashed_entry_with_manifest_wins(self, tmp_path):
        url = "http://x.ro/doc.docx"
        target = hashed_path(tmp_path, url)
        body = make_docx()
        target.write_bytes(body)
        (tmp_path / (cache_key(url) + ".json")).write_text(
            '{"format": 1, "key": "%s", "url": "%s", "ext": "docx", "bytes": %d, '
            '"sha256": "x", "retrieved_at": "2026-10-03T00:00:00+00:00", "content_type": ""}'
            % (cache_key(url), url, len(body))
        )
        r = resolve_local_path(tmp_path, url)
        assert r.status == "cached" and r.path == target


# ─── download_file ───────────────────────────────────────────────────────────


def run_download(tmp_path, url, response):
    return download_file(url, tmp_path, session=FakeSession(response))


class TestDownload:
    def test_success_lands_atomically_with_manifest(self, tmp_path):
        url = "http://x.ro/anunt.docx"
        result = run_download(tmp_path, url, FakeResponse(make_docx(), {"Content-Length": str(len(make_docx()))}))
        assert result.status == "downloaded" and result.path.exists()
        assert (tmp_path / (cache_key(url) + ".json")).exists()
        assert not list(tmp_path.glob(".tmp-*")), "no temp files left behind"

    def test_interrupted_stream_leaves_no_final_file(self, tmp_path):
        url = "http://x.ro/big.pdf"
        body = make_pdf()

        def cut_short():
            yield body[:20]
            raise ConnectionError("connection dropped")

        result = run_download(tmp_path, url, FakeResponse(chunks=cut_short()))
        assert result.status == "failed"
        assert not hashed_path(tmp_path, url).exists()
        assert not list(tmp_path.glob(".tmp-*")), "temp file cleaned up"

    def test_failed_replacement_keeps_existing_bytes(self, tmp_path):
        """An invalid cached file (a legacy-era partial that landed under the
        hashed name, say) is the only thing a download replaces — and a failed
        download must leave even those bytes untouched."""
        url = "http://x.ro/doc.pdf"
        target = hashed_path(tmp_path, url)
        target.write_bytes(b"truncated partial")
        (tmp_path / (cache_key(url) + ".json")).write_text(
            '{"format": 1, "key": "%s", "url": "%s", "ext": "pdf", "bytes": 17, '
            '"sha256": "x", "retrieved_at": "2026-10-03T00:00:00+00:00", "content_type": ""}'
            % (cache_key(url), url)
        )

        def cut_short():
            yield make_pdf()[:10]
            raise ConnectionError("connection dropped")

        result = run_download(tmp_path, url, FakeResponse(chunks=cut_short()))
        assert result.status == "failed"
        assert target.read_bytes() == b"truncated partial"

    def test_successful_replacement_of_invalid_file(self, tmp_path):
        url = "http://x.ro/doc.docx"
        target = hashed_path(tmp_path, url)
        target.write_bytes(b"partial leftover")
        good = run_download(tmp_path, url, FakeResponse(make_docx(), {"Content-Length": str(len(make_docx()))}))
        assert good.status == "replaced"
        ok, _ = validate_file(good.path)
        assert ok
        assert good.path == target

    def test_cached_file_is_not_refetched(self, tmp_path):
        url = "http://x.ro/doc.pdf"
        run_download(tmp_path, url, FakeResponse(make_pdf()))
        result = run_download(tmp_path, url, FakeResponse(b""))
        assert result.status == "cached" and result.bytes > 0

    def test_empty_body_is_invalid(self, tmp_path):
        url = "http://x.ro/doc.docx"
        result = run_download(tmp_path, url, FakeResponse(b"", {"Content-Length": "0"}))
        assert result.status == "invalid" and "empty" in result.reason

    def test_length_mismatch_is_invalid(self, tmp_path):
        url = "http://x.ro/doc.pdf"
        body = make_pdf()
        result = run_download(tmp_path, url, FakeResponse(body, {"Content-Length": str(len(body) + 500)}))
        assert result.status == "invalid" and "length mismatch" in result.reason

    def test_valid_chunked_response_without_length_succeeds(self, tmp_path):
        url = "http://x.ro/doc.pdf"
        body = make_pdf()
        result = run_download(tmp_path, url, FakeResponse(chunks=[body[:9], body[9:]], headers={}))
        assert result.status == "downloaded"
        assert result.path.read_bytes() == body

    def test_html_with_200_is_rejected(self, tmp_path):
        url = "http://x.ro/portal.docx"
        html = b"<!doctype html><html><body>Please log in</body></html>"
        result = run_download(tmp_path, url, FakeResponse(html, {"Content-Type": "text/html"}))
        assert result.status == "invalid" and "HTML" in result.reason

    def test_query_string_url_keeps_path_extension(self, tmp_path):
        url = "http://x.ro/download?file=anunt.docx&token=1"
        result = run_download(tmp_path, url, FakeResponse(make_docx()))
        assert result.status == "downloaded"
        assert result.path.suffix == ".docx"

    def test_extensionless_url_gets_sniffed_suffix(self, tmp_path):
        url = "http://x.ro/download"
        result = run_download(tmp_path, url, FakeResponse(make_pdf()))
        assert result.status == "downloaded"
        assert result.path.suffix == ".pdf"

    def test_size_bound_is_enforced(self, tmp_path):
        url = "http://x.ro/huge.pdf"
        body = make_pdf() * 1000
        result = download_file(url, tmp_path, session=FakeSession(FakeResponse(body)), max_bytes=128)
        assert result.status == "invalid" and "limit" in result.reason
        assert not hashed_path(tmp_path, url).exists()

    def test_http_error_is_failed(self, tmp_path):
        url = "http://x.ro/gone.docx"
        result = run_download(tmp_path, url, FakeResponse(status=404))
        assert result.status == "failed"


# ─── legacy adoption ─────────────────────────────────────────────────────────


class TestLegacyAdoption:
    def test_adopts_valid_and_records_invalid_without_deleting(self, tmp_path):
        good_url = "http://x.ro/good.docx"
        bad_url = "http://x.ro/bad.docx"
        legacy_path(tmp_path, good_url).write_bytes(make_docx())
        legacy_path(tmp_path, bad_url).write_bytes(b"partial")

        counts = adopt_legacy_files(tmp_path, [good_url, bad_url, "http://x.ro/absent.docx"])

        assert counts["adopted"] == 1 and counts["invalid"] == 1 and counts["missing"] == 1
        # Valid file moved into the hashed cache with a manifest…
        r = resolve_local_path(tmp_path, good_url)
        assert r.status == "cached"
        assert not legacy_path(tmp_path, good_url).exists()
        # …the invalid one is untouched and recorded.
        assert legacy_path(tmp_path, bad_url).exists()
        import json

        report = json.loads((tmp_path / "legacy-invalid.json").read_text())
        assert "bad.docx" in report


# ─── summary semantics ───────────────────────────────────────────────────────


class TestSummary:
    def test_mostly_failed_batch_is_unusable(self):
        from download_manifest import DownloadSummary

        s = DownloadSummary()
        for i, status in enumerate(["failed", "failed", "downloaded"]):
            s.record(DownloadResult(status), f"url-{i}")
        assert s.unusable()

    def test_all_successful_batch_is_usable(self):
        from download_manifest import DownloadSummary

        s = DownloadSummary()
        s.record(DownloadResult("cached"), "u1")
        s.record(DownloadResult("downloaded"), "u2")
        assert not s.unusable()
