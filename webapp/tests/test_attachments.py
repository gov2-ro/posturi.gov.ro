"""Attachment descriptors: kind classification and size formatting."""

import pytest

from apps.jobs.attachments import (
    ATTACHMENT_KINDS,
    KIND_LABELS,
    _normalize,
    classify_attachment,
    human_size,
)


def test_every_kind_has_a_label():
    assert {k for k, _ in ATTACHMENT_KINDS} == set(KIND_LABELS)


class TestNormalize:
    def test_letter_spaced_headings_collapse(self):
        """These documents render display headings as "E R A T Ă"."""
        assert "erata" in _normalize("E R A T Ă")
        assert "anunt" in _normalize("A N U N Ț")

    def test_diacritics_are_stripped(self):
        assert _normalize("ERATĂ") == "erata"


class TestClassifyAttachment:
    @pytest.mark.parametrize(
        "opening,kind",
        [
            ("ERATĂ\nla anunțul de concurs publicat în data de 28.05.2026", "erata"),
            ("Nr. 1626/02.06.2026\nERATA\nla anunt nr. 9747", "erata"),
            ("ANULARE concurs", "erata"),
            ("REZULTATE selecție dosare", "rezultate"),
            ("PROCES-VERBAL de selecție a dosarelor", "rezultate"),
        ],
    )
    def test_documents_that_declare_themselves(self, opening, kind):
        assert classify_attachment(opening) == kind

    def test_letterhead_is_skipped_before_the_real_opening(self):
        text = (
            "ROMÂNIA\nJUDEŢUL CLUJ\nPRIMĂRIA COMUNEI X\n"
            "Str. Principală nr. 1\nCUI: 1234567\n"
            "ERATĂ\nla anunțul publicat anterior"
        )
        assert classify_attachment(text) == "erata"

    def test_an_announcement_is_not_a_kind(self):
        """~89% of files are the announcement; it must stay unlabelled."""
        text = "Nr. 6923/11.06.2026\nANUNȚ\nSpitalul Municipal Mangalia organizează concurs"
        assert classify_attachment(text) is None

    def test_sections_inside_an_announcement_do_not_win(self):
        """Bibliografie / cerere / calendar are sections, not separate documents.

        This is the failure mode that made every richer taxonomy score worse
        than saying nothing.
        """
        text = (
            "ANUNȚ CONCURS\n"
            "Primăria organizează concurs.\n"
            "BIBLIOGRAFIE\n- Legea 53/2003\n"
            "CERERE DE ÎNSCRIERE\nCalendar de desfășurare\n"
        )
        assert classify_attachment(text) is None

    @pytest.mark.parametrize("text", ["", "   ", "\n\n"])
    def test_empty_input(self, text):
        assert classify_attachment(text) is None

    def test_scanned_pdf_garbage_yields_nothing(self):
        assert classify_attachment("tI( ,'\\1. RETIC l-.ll1.103. t.x:0251") is None


class TestHumanSize:
    @pytest.mark.parametrize(
        "num,expected",
        [(0, ""), (None, ""), (-5, ""), (512, "512 B"), (2048, "2 KB"),
         (167_000, "163 KB"), (9_650_000, "9.2 MB")],
    )
    def test_formatting(self, num, expected):
        assert human_size(num) == expected
