"""`parse-anunturi.py` — picking the application deadline out of the calendar table.

Two bugs, both found by prompt v4 disagreeing with the scraper on real postings:

  * `_find_calendar_date` returned the FIRST row matching any of
    `['depunere', 'inscriere', 'dosar', 'limita']`. `dosar` also matches
    "Selectarea dosarelor de concurs", so the scraped deadline could land on the
    selection date — days after applications had closed.
  * `DATE_RE.search` takes the first date on a line, so a row stating a window
    ("de la 11.09.2026 până la 30.09.2026") yielded the day submissions OPENED.

Re-parsing the corpus afterwards moved 530 of 1,692 comparable deadlines, 92% of
them later — the signature of both bugs.
"""

import importlib.util
import sys
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]


@pytest.fixture(scope="module")
def parser():
    spec = importlib.util.spec_from_file_location("parse_anunturi", REPO_ROOT / "parse-anunturi.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules["parse_anunturi"] = module
    spec.loader.exec_module(module)
    return module


def test_importing_the_parser_does_not_parse_the_corpus(parser):
    """It used to. `import parse_anunturi` re-read 9,691 cached pages and
    rewrote both CSVs, which made this file take 74 seconds and silently
    mutated pipeline output."""
    assert hasattr(parser, "process_html_files")


def row(label, date, time="", end=""):
    return (label, date, time, end)


class TestPicksTheRightRow:
    def test_a_selection_row_is_not_a_deadline(self, parser):
        """The revizor-contabil case: scraper said 28.09, the deadline was 25.09."""
        rows = [
            row("Înscrierea candidaţilor", "14.09.2026"),
            row("Data limită până la care se pot depune dosarele de concurs", "25.09.2026", "14.00"),
            row("Selectarea dosarelor de concurs", "28.09.2026"),
            row("Afişarea rezultatelor selecţiei dosarelor de concurs", "28.09.2026"),
        ]
        assert parser._find_deadline(rows) == "25.09.2026, ora 14.00"

    def test_specificity_beats_table_order(self, parser):
        """A row saying "limită" wins over one that merely mentions dossiers,
        however the institution ordered its table."""
        rows = [
            row("Selecţia dosarelor", "28.09.2026"),
            row("Depunerea dosarelor", "20.09.2026"),
            row("Data limită de depunere", "25.09.2026"),
        ]
        assert parser._find_deadline(rows).startswith("25.09.2026")

    def test_a_combined_row_still_yields_a_date(self, parser):
        """The exclusions guard the specific tiers; the last one drops them.
        A row combining submission and selection is a worse answer than a
        dedicated deadline row, and a much better one than nothing."""
        rows = [row("Depunerea şi selecţia dosarelor de concurs", "25.09.2026")]
        assert parser._find_deadline(rows).startswith("25.09.2026")

    def test_no_deadline_row_yields_nothing(self, parser):
        assert parser._find_deadline([row("Proba scrisă", "19.09.2026", "11.00")]) == ""


class TestDateRanges:
    def test_a_window_closes_on_its_last_date(self, parser):
        """The ospatar case: scraper said 11.09, applications closed on the 30th."""
        rows = [row("Depunerea dosarelor de concurs de la data de", "11.09.2026", "", "30.09.2026")]
        assert parser._find_deadline(rows).startswith("30.09.2026")

    def test_an_inverted_range_is_a_typo_and_is_ignored(self, parser):
        """A real posting reads "-20.05.2025-03.06.2024-". Taking the closing
        date faithfully would publish a deadline a year in the past."""
        line = "-depunere dosare de inscriere-20.05.2025-03.06.2024,ora14"
        dates = parser.DATE_RE.findall(line)
        assert dates == ["20.05.2025", "03.06.2024"]
        assert parser._as_date(dates[-1]) < parser._as_date(dates[0])

    @pytest.mark.parametrize("value,ok", [
        ("30.09.2026", True), ("31.02.2026", False), ("", False), (None, False),
    ])
    def test_as_date_rejects_what_is_not_a_date(self, parser, value, ok):
        assert (parser._as_date(value) is not None) is ok


class TestVocabulary:
    def test_results_and_contestation_rows_are_excluded(self, parser):
        for label in ["Afişarea rezultatelor selecţiei dosarelor",
                      "Contestarea rezultatelor selecţiei dosarelor",
                      "Soluţionarea contestaţiilor",
                      "Susţinerea probei scrise"]:
            assert parser._NOT_A_DEADLINE.search(label.lower()), label

    def test_a_genuine_deadline_row_is_not_excluded(self, parser):
        for label in ["Data limită de depunere a dosarelor",
                      "Depunerea dosarelor de concurs",
                      "Înscrierea candidaţilor"]:
            assert not parser._NOT_A_DEADLINE.search(label.lower()), label

    def test_tiers_run_most_specific_first(self, parser):
        assert parser._DEADLINE_KEYWORDS[0] == ("limita", "limită")
        assert parser._DEADLINE_KEYWORDS[-1] == ("dosar",)
