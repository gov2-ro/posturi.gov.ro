"""UX-EMP-01 — the employer posting builder: payload fixtures, the .docx carrier, the completeness parity.

The fixtures are in webapp-php/tests/fixtures/employer_payloads/. The Playwright suite
(webapp-php/tests/browser/employer_builder.spec.js) downloads a .docx for each fixture and
writes the JS completeness result. Run it first:

    npx playwright test --config webapp-php/tests/browser/playwright.config.js employer_builder

The two tests that read its output are skipped when it has not run.
"""
import hashlib
import json
import re
import sys
import zipfile
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
import classify  # noqa: E402
import schema_models  # noqa: E402

FIXTURES = sorted((ROOT / "webapp-php" / "tests" / "fixtures" / "employer_payloads").glob("*.json"))
OUT = ROOT / "test-results"
NS = "https://posturi.gov2.ro/ns/posting/v1"


def load(path):
    return json.loads(path.read_text(encoding="utf-8"))


def test_there_are_at_least_four_fixtures():
    assert len(FIXTURES) >= 4
    assert {"full", "minimal", "temporar_vacant", "diacritics"} <= {p.stem for p in FIXTURES}


@pytest.mark.parametrize("path", FIXTURES, ids=lambda p: p.stem)
def test_fixture_validates_as_v5(path):
    data = load(path)["data"]
    model = schema_models.JobPostingExtractionV5.model_validate(data)
    # Nothing in `data` is silently dropped: every key is a v5 field.
    assert set(data) <= set(schema_models.JobPostingExtractionV5.model_fields)
    # The model keeps what the employer typed.
    assert model.occupation_title == data["occupation_title"]


def test_document_labels_match_the_site():
    """employer-docx.js copies DOCUMENT_CODE_LABELS of inc/v5.php. The two lists must be equal."""
    php = (ROOT / "webapp-php" / "inc" / "v5.php").read_text(encoding="utf-8")
    block = re.search(r"const DOCUMENT_CODE_LABELS = \[(.*?)\];", php, re.S).group(1)
    php_labels = dict(re.findall(r"'([a-z_]+)' => '([^']*)'", block))
    js = (ROOT / "webapp-php" / "static" / "employer-docx.js").read_text(encoding="utf-8")
    jblock = re.search(r"var DOCUMENT_LABELS = \{(.*?)\};", js, re.S).group(1)
    js_labels = dict(re.findall(r"([a-z_]+): '([^']*)'", jblock))
    assert php_labels == js_labels
    assert list(php_labels) == list(schema_models.DOCUMENT_CODES)


def _quality_json():
    f = OUT / "employer_quality.json"
    if not f.exists():
        pytest.skip("run the Playwright suite employer_builder first (it writes test-results/employer_quality.json)")
    return json.loads(f.read_text(encoding="utf-8"))


def test_quality_keys_labels_and_weights_match_classify():
    js = _quality_json()["weights"]
    assert [tuple(x) for x in js] == [(k, label, w) for k, _g, label, w in classify.QUALITY_CHECKS]


def quality_input(data):
    """The `p` of classify.quality() for a builder payload (brief UX-EMP-01 § 3)."""
    cal = data.get("competition_calendar") or {}
    deadline = cal.get("application_deadline")
    contact = data.get("application_contact") or {}
    contract = data.get("contract") or {}
    return {
        "schema": data,
        "body": "x" * 800,                                        # text and attachment are 1 for builder output
        "contact_phone": contact.get("phone", ""),
        "contact_email": contact.get("email", ""),
        "published_at": (data.get("application") or {}).get("window_start"),   # the publication date
        "apply_deadline": deadline,
        "deadline_source": "anunt" if deadline else "",           # confirmed = the calendar has a deadline
        "v3_contract_duration": contract.get("duration", ""),
        "v3_schedule": contract.get("schedule", ""),
        "calendar_events": 0,
    }


@pytest.mark.parametrize("path", FIXTURES, ids=lambda p: p.stem)
def test_quality_parity_with_the_browser(path):
    js = _quality_json()["fixtures"][path.stem]
    py = classify.quality(quality_input(load(path)["data"]))
    assert js["checks"] == py["checks"]
    assert js["score"] == py["score"]


def test_the_window_check_has_a_half_step():
    """The temporar_vacant fixture has 8 days to apply: window = 0.5 in both implementations."""
    py = classify.quality(quality_input(load(next(p for p in FIXTURES if p.stem == "temporar_vacant"))["data"]))
    assert py["checks"]["window"] == 0.5


def read_back(docx):
    """(payload JSON text, property value) from a .docx. The part is found by its namespace, not by its name."""
    from xml.etree import ElementTree as ET

    with zipfile.ZipFile(docx) as z:
        found = None
        for name in z.namelist():
            if name.startswith("customXml/") and name.endswith(".xml") and "_rels" not in name:
                root = ET.fromstring(z.read(name))
                if root.tag == "{%s}posting" % NS:
                    found = "".join(root.itertext())
        custom = z.read("docProps/custom.xml").decode("utf-8")
    assert found is not None, "no Custom XML part with the posting namespace"
    prop = re.search(r"posturi\.posting.*?<vt:lpwstr>([^<]+)</vt:lpwstr>", custom, re.S).group(1)
    return found, prop


@pytest.mark.parametrize("path", FIXTURES, ids=lambda p: p.stem)
def test_payload_read_back_from_the_downloaded_docx(path):
    docx = OUT / "employer_docx" / f"{path.stem}.docx"
    if not docx.exists():
        pytest.skip("run the Playwright suite employer_builder first (it writes test-results/employer_docx/)")
    inner, prop = read_back(docx)
    payload = json.loads(inner)
    assert payload["format"] == "posturi.posting.v1"
    assert payload["schema"] == "v5"
    assert payload["data"] == load(path)["data"]
    assert prop == "posturi.posting.v1;sha256=" + hashlib.sha256(inner.encode("utf-8")).hexdigest()
    assert len(prop) < 255
    # The payload validates, as the later pipeline reader will need.
    schema_models.JobPostingExtractionV5.model_validate(payload["data"])


def test_the_libreoffice_round_trip_is_checked_by_hand():
    """Documented, not automated: see the report of UX-EMP-01 (soffice --headless --convert-to docx)."""
    assert True
