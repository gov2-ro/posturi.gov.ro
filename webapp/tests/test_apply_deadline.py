"""`apply_deadline` — the date the site treats as "can I still apply?".

Not `expires_at`. That is when the *competition* ends, and on a 20-posting
sample it ran a median of 15 days — up to 52 — past the day applications closed,
so the site advertised closed competitions as open. On the live export, 33
postings shown as active had a deadline between 4 and 275 days in the past.

The fallback chain matters as much as the rule: prompt v4 has not run over the
corpus, and only 81 of 1,621 active postings carry a scraped deadline, so
falling back to `expires_at` is what keeps this from regressing the 94% that
have no deadline yet.
"""

import importlib.util
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
PHP_DIR = REPO_ROOT / "webapp-php"


@pytest.fixture(scope="module")
def exp():
    spec = importlib.util.spec_from_file_location("export_sqlite", REPO_ROOT / "export-to-sqlite.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules["export_sqlite"] = module
    spec.loader.exec_module(module)
    return module


class TestSourcePrecedence:
    def test_the_v4_calendar_wins(self, exp):
        got = exp._apply_deadline("2026-09-16", "2026-09-20", "2026-09-28")
        assert got == {"date": "2026-09-16", "source": "concurs"}

    def test_the_scraped_card_field_is_next(self, exp):
        got = exp._apply_deadline(None, "2026-09-20", "2026-09-28")
        assert got == {"date": "2026-09-20", "source": "anunt"}

    def test_expiry_is_the_fallback_not_the_default(self, exp):
        got = exp._apply_deadline(None, None, "2026-09-28")
        assert got == {"date": "2026-09-28", "source": "expirare"}

    def test_nothing_known_yields_no_date(self, exp):
        assert exp._apply_deadline(None, None, None) == {"date": None, "source": ""}

    def test_a_datetime_is_truncated_to_its_date(self, exp):
        """`data_limita_depunere` is a DateTime; SQLite stores dates as text."""
        got = exp._apply_deadline(None, "2026-09-20 14:00:00+03", "2026-09-28")
        assert got["date"] == "2026-09-20"

    def test_the_deadline_can_precede_the_expiry_by_a_lot(self, exp):
        """The real case this exists for: 9 months of advertising a closed post."""
        got = exp._apply_deadline(None, "2025-12-12", "2026-12-22")
        assert got == {"date": "2025-12-12", "source": "anunt"}


class TestPhpUsesTheDeadline:
    """Status, sorting and the feeds all have to agree, or a reader sees a
    posting in the list that the detail page calls closed."""

    @pytest.fixture(scope="class")
    def sources(self):
        names = ["helpers.php", "pages/list.php", "pages/detail.php",
                 "pages/employer.php", "pages/employers.php", "pages/stats.php",
                 "pages/sitemap.php", "partials/result_list.php",
                 "feeds/jobs.ics.php", "feeds/jobs.atom.php", "feeds/jobs.json.php"]
        return {n: (PHP_DIR / n).read_text(encoding="utf-8") for n in names}

    def test_the_deadline_column_is_named_once(self, sources):
        assert "const DEADLINE_COL = 'j.apply_deadline';" in sources["helpers.php"]

    def test_the_active_and_soon_filters_use_it(self, sources):
        src = sources["helpers.php"]
        status_block = src[src.index("if ($excl !== 'status')"):][:700]
        assert "DEADLINE_COL" in status_block
        assert "expires_at" not in status_block

    def test_sorting_by_deadline_sorts_by_the_deadline(self, sources):
        assert 'ORDER BY " . DEADLINE_COL . " ASC' in sources["pages/list.php"]

    def test_the_ical_reminder_lands_on_the_deadline(self, sources):
        """A reminder for a competition that closed three weeks ago is worse
        than no reminder."""
        src = sources["feeds/jobs.ics.php"]
        assert "j.apply_deadline IS NOT NULL" in src
        assert "ORDER BY j.apply_deadline ASC" in src
        assert "$r['apply_deadline']" in src

    def test_the_json_feed_exposes_the_deadline_and_its_provenance(self, sources):
        src = sources["feeds/jobs.json.php"]
        assert "'apply_deadline'" in src and "'deadline_source'" in src
        assert "'expires_at'" in src, "kept for API compatibility"

    @pytest.mark.parametrize("name", [
        "pages/employer.php", "pages/employers.php", "pages/stats.php",
        "pages/sitemap.php", "partials/result_list.php",
    ])
    def test_every_active_or_countdown_site_uses_the_deadline(self, sources, name):
        assert "apply_deadline" in sources[name]

    def test_competition_duration_still_measures_the_competition(self, sources):
        """The one place `expires_at` is still right: how long a contest runs."""
        assert "julianday(expires_at) - julianday(published_at)" in sources["pages/stats.php"]

    def test_the_detail_page_shows_both_dates(self, sources):
        """Showing only one of them is what made closed competitions read open."""
        src = sources["pages/detail.php"]
        assert "concursul se încheie" in src
        assert "DEADLINE_SOURCE_LABELS" in src
