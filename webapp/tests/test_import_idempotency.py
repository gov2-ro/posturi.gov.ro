"""
Importer idempotency test: running import_csvs twice must not create
duplicate records for any model (Judet, Employer, JobPosting, CalendarEvent).
"""
import csv
import io
import tempfile
from pathlib import Path

import pytest
from django.core.management import call_command

from apps.jobs.models import CalendarEvent, Employer, JobPosting, Judet


INDEX_ROWS = [
    {
        "pozitie": "Medic specialist",
        "url": "https://posturi.gov.ro/anunt/medic-specialist-1/",
        "angajator": "Spitalul Județean Cluj",
        "detalii": "Funcție contractuală, Instituții județene, Funcții de execuție",
        "publicat_in": "Publicat în: 5 mai,2026",
        "expira_in": "Expiră in  30/06/2026",
        "judet": "Cluj",
        "url_judet": "https://posturi.gov.ro/?judet=Cluj",
        "tip": "Permanent",
        "updates": "",
    },
    {
        "pozitie": "Referent",
        "url": "https://posturi.gov.ro/anunt/referent-primarie-1/",
        "angajator": "Primăria Comunei Test",
        "detalii": "Funcție publică, Primării, Funcții de execuție",
        "publicat_in": "Publicat în: 1 mai,2026",
        "expira_in": "Expiră in  15/06/2026",
        "judet": "Iași",
        "url_judet": "https://posturi.gov.ro/?judet=Iași",
        "tip": "Permanent",
        "updates": "",
    },
]

ANUNTURI_ROWS = [
    {
        "Source URL": "https://posturi.gov.ro/anunt/medic-specialist-1/",
        "Job Title": "Medic specialist",
        "Employer": "Spitalul Județean Cluj",
        "Location": "Cluj",
        "Job Level": "execuție",
        "Job Type": "Permanent",
        "Employer Category": "Instituții județene",
        "Categorie": "Funcție contractuală",
        "Announcement URL": "",
        "Main Body Markdown": "Condiții: studii superioare medicale.",
        "Other Links": "",
        "Nr Posturi": "1",
        "Contact Telefon": "0264123456",
        "Contact Email": "contact@spital.ro",
        "Contact Persoana": "",
        "Data Limita Depunere": "30.06.2026",
        "Data Proba Scrisa": "",
        "Data Interviu": "",
        "Data Rezultate Finale": "",
        "Data Publicare": "2026-05-05",
        "Data Expirare": "2026-06-30",
    },
]

CALENDAR_ROWS = [
    {
        "url": "https://posturi.gov.ro/anunt/medic-specialist-1/",
        "eveniment": "Depunere dosare",
        "data": "30.06.2026",
        "ora": "",
    },
]


def _write_csv(path: Path, rows: list[dict], fieldnames: list[str] | None = None) -> None:
    if not rows:
        return
    keys = fieldnames or list(rows[0].keys())
    with path.open("w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=keys, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


@pytest.fixture
def data_dir(tmp_path):
    (tmp_path / "anunturi").mkdir()
    _write_csv(tmp_path / "posturi_gov_ro.csv", INDEX_ROWS)
    _write_csv(tmp_path / "anunturi" / "anunturi.csv", ANUNTURI_ROWS)
    _write_csv(tmp_path / "calendar.csv", CALENDAR_ROWS)
    return tmp_path


@pytest.mark.django_db(transaction=True)
def test_import_is_idempotent(data_dir):
    """Running import_csvs twice must not create duplicate DB rows."""
    call_command("import_csvs", data_dir=data_dir, verbosity=0)

    counts_after_first = {
        "judet": Judet.objects.count(),
        "employer": Employer.objects.count(),
        "posting": JobPosting.objects.count(),
        "calendar": CalendarEvent.objects.count(),
    }

    call_command("import_csvs", data_dir=data_dir, verbosity=0)

    assert Judet.objects.count() == counts_after_first["judet"], "Judet duplicated on second run"
    assert Employer.objects.count() == counts_after_first["employer"], "Employer duplicated on second run"
    assert JobPosting.objects.count() == counts_after_first["posting"], "JobPosting duplicated on second run"
    assert CalendarEvent.objects.count() == counts_after_first["calendar"], "CalendarEvent duplicated on second run"


@pytest.mark.django_db
def test_import_creates_expected_records(data_dir):
    """Basic sanity: the right records are created."""
    call_command("import_csvs", data_dir=data_dir, verbosity=0)

    assert JobPosting.objects.count() == 2
    posting = JobPosting.objects.get(url="https://posturi.gov.ro/anunt/medic-specialist-1/")
    assert posting.title == "Medic specialist"
    assert posting.employer.name == "Spitalul Județean Cluj"
    assert posting.contact_phone == "0264123456"
    assert CalendarEvent.objects.filter(posting=posting).count() == 1


@pytest.mark.django_db
def test_import_updates_existing_fields(data_dir):
    """Re-importing with updated title/tip must update the existing row, not create a new one."""
    call_command("import_csvs", data_dir=data_dir, verbosity=0)
    assert JobPosting.objects.count() == 2

    # Modify the index CSV: change title of first posting
    updated = [dict(r) for r in INDEX_ROWS]
    updated[0]["pozitie"] = "Medic specialist senior"
    _write_csv(data_dir / "posturi_gov_ro.csv", updated)

    call_command("import_csvs", data_dir=data_dir, verbosity=0)

    assert JobPosting.objects.count() == 2  # no new row
    posting = JobPosting.objects.get(url="https://posturi.gov.ro/anunt/medic-specialist-1/")
    assert posting.title == "Medic specialist senior"
