"""URL ↔ cache-filename round-trip, and the cancelled-announcement guard.

Both exist because of the same 2026-09-08 incident. `fetch-anunturi.py` names
each cached page after its URL and `parse-anunturi.py` reconstructs the URL from
that name; the two disagreed for raw WordPress permalinks
(`/?post_type=pg_job&p=26404`), which have an empty path. 80 postings collapsed
onto a single `index.html` per date directory and reached the database with no
body, no attachment and no expiry — invisible, because the SQLite export drops
NULL expiries.

Recovering them then exposed the second half: those 80 are all *cancelled*
competitions ("Anunț anulat" in the index), whose detail pages still carry real
dates. Fetching them successfully turned 15 withdrawn competitions into
apparently open jobs, so "active" has to mean open *and* not withdrawn.
"""

import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

from posting_urls import build_slug_index, slug_for_url, url_from_slug  # noqa: E402

REAL_URLS = [
    "https://posturi.gov.ro/joburi/economist-1/",
    "https://posturi.gov.ro/anunt/asistent-medical-pl-58/",
    "https://posturi.gov.ro/?post_type=pg_job&p=26404",
    "https://posturi.gov.ro/?post_type=pg_job&p=23782",
]


class TestRoundTrip:
    @pytest.mark.parametrize("url", REAL_URLS)
    def test_every_url_shape_round_trips(self, url):
        index = build_slug_index(REAL_URLS)
        assert url_from_slug(slug_for_url(url), index) == url

    def test_permalinks_get_distinct_slugs(self):
        """The whole bug: these two used to share one cache file."""
        assert slug_for_url(REAL_URLS[2]) != slug_for_url(REAL_URLS[3])

    def test_slugs_are_filename_safe(self):
        for url in REAL_URLS:
            slug = slug_for_url(url)
            assert not (set(slug) & set('/?&=:%\\')), slug

    def test_unknown_slug_falls_back_to_the_joburi_shape(self):
        """A cached file the index has never seen still resolves to something."""
        assert url_from_slug("ceva-nou", {}) == "https://posturi.gov.ro/joburi/ceva-nou/"

    def test_index_wins_over_the_fallback(self):
        """/anunt/ URLs would otherwise be rebuilt as /joburi/ and mis-join."""
        url = "https://posturi.gov.ro/anunt/asistent-medical-pl-58/"
        index = build_slug_index([url])
        assert url_from_slug("asistent-medical-pl-58", index) == url

    def test_first_url_owns_a_colliding_slug(self):
        """Two URLs mapping to one slug share a cache file; the first owns it."""
        index = build_slug_index([
            "https://posturi.gov.ro/joburi/paznic/",
            "https://posturi.gov.ro/anunt/paznic/",
        ])
        assert index["paznic"] == "https://posturi.gov.ro/joburi/paznic/"

    def test_bare_root_is_not_mistaken_for_a_posting(self):
        assert slug_for_url("https://posturi.gov.ro/") == "index"


class TestCancelledIsExcludedFromActive:
    """`--active-only` must mean open AND not withdrawn."""

    @staticmethod
    def _export_source():
        return (REPO_ROOT / "export-to-sqlite.py").read_text(encoding="utf-8")

    def test_posting_filter_checks_cancelled(self):
        src = self._export_source()
        assert "jp.expires_at >= CURRENT_DATE AND NOT jp.cancelled" in src

    def test_calendar_filter_checks_cancelled_too(self):
        """Events for a withdrawn competition must not ship either."""
        src = self._export_source()
        assert "expires_at >= CURRENT_DATE AND NOT cancelled" in src

    def test_importer_flags_the_romanian_marker(self):
        src = (REPO_ROOT / "webapp/apps/jobs/management/commands/import_csvs.py").read_text(
            encoding="utf-8"
        )
        assert '"anulat" in' in src

    def test_fetch_anunturi_skips_the_romanian_marker(self):
        """A withdrawn competition's detail page 404s; don't retry it every run."""
        src = (REPO_ROOT / "fetch-anunturi.py").read_text(encoding="utf-8")
        assert '"anulat" in' in src

    @pytest.mark.parametrize("raw,expected", [
        ("Anunț anulat", True), ("ANUNȚ ANULAT", True), ("anulat", True),
        ("Expiră in  13/09/2026", False), ("Ultima zi", False), ("", False),
    ])
    def test_marker_detection_is_case_insensitive(self, raw, expected):
        assert ("anulat" in raw.lower()) is expected


class TestReusedSlugs:
    """2026-10-17: posturi.gov.ro reuses slugs — /anunt/gipsar-debutant/ (May 2025)
    and /joburi/gipsar-debutant/ (Sept 2026). The cache keeps them apart by date
    directory; the parser must too, or the new page is parsed as the old URL and
    the new posting reaches the database with no dates (123 open postings)."""

    OLD = "https://posturi.gov.ro/anunt/gipsar-debutant/"
    NEW = "https://posturi.gov.ro/joburi/gipsar-debutant/"

    def test_the_date_tells_reused_slugs_apart(self):
        from posting_urls import build_dated_slug_index
        index = build_dated_slug_index([(self.OLD, "2025/05/27"), (self.NEW, "2026/09/09")])
        assert index[("2025/05/27", "gipsar-debutant")] == self.OLD
        assert index[("2026/09/09", "gipsar-debutant")] == self.NEW

    @pytest.fixture
    def parser(self, monkeypatch):
        import importlib.util
        spec = importlib.util.spec_from_file_location("parse_anunturi_urls", REPO_ROOT / "parse-anunturi.py")
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        from posting_urls import build_dated_slug_index
        monkeypatch.setattr(mod, "_SLUG_INDEX", build_slug_index([self.OLD, self.NEW]))
        monkeypatch.setattr(mod, "_DATED_SLUG_INDEX",
                            build_dated_slug_index([(self.OLD, "2025/05/27"), (self.NEW, "2026/09/09")]))
        return mod

    def test_parser_resolves_the_page_by_its_date_directory(self, parser, tmp_path):
        new_page = tmp_path / "anunturi" / "2026" / "09" / "09" / "gipsar-debutant.html"
        old_page = tmp_path / "anunturi" / "2025" / "05" / "27" / "gipsar-debutant.html"
        for p in (new_page, old_page):
            p.parent.mkdir(parents=True)
            p.write_text("<html></html>", encoding="utf-8")
        assert parser.source_url_from_path(str(new_page)) == self.NEW
        assert parser.source_url_from_path(str(old_page)) == self.OLD

    def test_the_sidecar_url_wins(self, parser, tmp_path):
        page = tmp_path / "2026" / "01" / "01" / "gipsar-debutant.html"
        page.parent.mkdir(parents=True)
        page.write_text("<html></html>", encoding="utf-8")
        (page.parent / "gipsar-debutant.meta.json").write_text('{"url": "%s"}' % self.NEW, encoding="utf-8")
        assert parser.source_url_from_path(str(page)) == self.NEW
