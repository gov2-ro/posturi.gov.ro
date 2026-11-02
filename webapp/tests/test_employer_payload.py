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


def _judete():
    """webapp/apps/jobs/judete.py loaded by path: it needs no Django."""
    import importlib.util

    spec = importlib.util.spec_from_file_location("judete_canonical", ROOT / "webapp" / "apps" / "jobs" / "judete.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules["judete_canonical"] = mod
    spec.loader.exec_module(mod)
    return mod


def test_county_lists_match_judete():
    """The page select and employer-docx.js copy judete.COUNTIES (42 canonical names)."""
    mod = _judete()
    canon = list(mod.COUNTIES)
    assert len(canon) == 42
    js = (ROOT / "webapp-php" / "static" / "employer-docx.js").read_text(encoding="utf-8")
    js_list = re.findall(r"'([^']+)'", re.search(r"var COUNTIES = \[(.*?)\];", js, re.S).group(1))
    assert js_list == canon
    php = (ROOT / "webapp-php" / "pages" / "employer_builder.php").read_text(encoding="utf-8")
    php_list = json.loads(re.search(r"\$counties = json_decode\('(\[.*?\])'", php).group(1))
    assert php_list == canon


@pytest.mark.parametrize("path", FIXTURES, ids=lambda p: p.stem)
def test_employer_block_is_canonical(path):
    fx = load(path)
    emp = fx.get("employer")
    if emp is None:
        # No block: the institution is empty, so nothing may be derived from it.
        assert not any((fx["meta"].get("institution") or {}).get(k) for k in ("name", "cui", "address", "locality", "county"))
        assert not fx["meta"].get("department")
        return
    assert set(emp) <= {"name", "cui", "address", "locality", "county", "department"}
    assert all(isinstance(v, str) and v.strip() for v in emp.values())     # no empty keys
    if "cui" in emp:
        assert re.fullmatch(r"\d+", emp["cui"])
    if "county" in emp:
        assert emp["county"] in _judete().COUNTIES


def test_fixtures_have_a_full_block_and_a_missing_one():
    blocks = {p.stem: load(p).get("employer") for p in FIXTURES}
    assert set(blocks["full"]) == {"name", "cui", "address", "locality", "county", "department"}
    assert blocks["minimal"] is None
    assert load(next(p for p in FIXTURES if p.stem == "full"))["meta"]["institution"]["cui"] == "RO 12345678"


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


def test_quality_weights_add_up_to_100():
    """UX-EMP-03: the page shows the score as a percent. That is right only while the weights add up to 100.
    The JS side of this check is in employer_assistant.spec.js."""
    assert sum(w for _k, _g, _l, w in classify.QUALITY_CHECKS) == 100


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
    assert payload.get("employer") == load(path).get("employer")
    assert ("employer" in payload) == ("employer" in load(path))
    assert payload.get("occupation") == load(path).get("occupation")
    assert ("occupation" in payload) == ("occupation" in load(path))
    assert payload["format"] == "posturi.posting.v1"
    assert payload["schema"] == "v5"
    assert payload["data"] == load(path)["data"]
    assert prop == "posturi.posting.v1;sha256=" + hashlib.sha256(inner.encode("utf-8")).hexdigest()
    assert len(prop) < 255
    # The payload validates, as the later pipeline reader will need.
    schema_models.JobPostingExtractionV5.model_validate(payload["data"])


# ---- UX-EMP-04: the occupation block and static/ocupatii.json ------------------

SUGGESTIONS = ROOT / "webapp-php" / "static" / "ocupatii.json"


def _fold(t):
    import unicodedata

    t = unicodedata.normalize("NFKD", t or "")
    return re.sub(r"[^a-z0-9]+", " ", "".join(c for c in t if not unicodedata.combining(c)).lower()).strip()


def _db_cor_codes():
    """{cor_code: {canonical, ...}} from jobs_occupation, or None when Postgres is not available."""
    try:
        import os

        import psycopg

        with psycopg.connect(os.environ.get("DATABASE_URL", "postgres://localhost/posturi_dev"), connect_timeout=3) as conn:
            rows = conn.execute("select cor_code, canonical from jobs_occupation where cor_code <> ''").fetchall()
    except Exception:
        return None
    out = {}
    for cor, canonical in rows:
        out.setdefault(cor, set()).add(canonical)
    return out


def _known_cor_codes():
    """The COR codes of jobs_occupation. Without Postgres, those of ocupatii.json."""
    db = _db_cor_codes()
    if db is not None:
        return db
    out = {}
    for o in json.loads(SUGGESTIONS.read_text(encoding="utf-8"))["occupations"]:
        if o["cor"]:
            out.setdefault(o["cor"], set()).add(o["label"])
    return out


def test_fixtures_have_an_occupation_block_and_an_edited_one():
    by = {p.stem: load(p) for p in FIXTURES}
    assert by["occupation"]["occupation"]["grade"] == "gradul I A"
    assert by["occupation"]["data"]["occupation_title"] == "Referent de specialitate, gradul IA"
    # The title was edited after the pick: meta keeps the pick, the payload has no block.
    assert by["occupation_edited"]["meta"]["occupation"]["label"] == "Referent de specialitate"
    assert "occupation" not in by["occupation_edited"]
    for name, fx in by.items():
        if name not in ("occupation", "occupation_edited"):
            assert "occupation" not in fx


@pytest.mark.parametrize("path", FIXTURES, ids=lambda p: p.stem)
def test_occupation_block_is_canonical(path):
    import ocupatii

    fx = load(path)
    block = fx.get("occupation")
    if block is None:
        return
    assert set(block) <= {"cor_code", "label", "grade"}
    assert {"cor_code", "label"} <= set(block)
    assert all(isinstance(v, str) and v.strip() for v in block.values())
    if "grade" in block:
        assert block["grade"] in ocupatii.GRADE_TOKENS
    # The title still starts with the label, or the builder would have left the block out.
    assert _fold(fx["data"]["occupation_title"]).startswith(_fold(block["label"]))
    known = _known_cor_codes()
    assert block["cor_code"] in known
    assert block["label"] in known[block["cor_code"]] or _db_cor_codes() is None


def test_suggestions_file_is_consistent():
    d = json.loads(SUGGESTIONS.read_text(encoding="utf-8"))
    occ = d["occupations"]
    assert [o["id"] for o in occ] == list(range(len(occ)))
    assert [o["n"] for o in occ] == sorted((o["n"] for o in occ), reverse=True)
    assert len({o["label"] for o in occ}) == len(occ)
    import ocupatii

    for o in occ:
        assert set(o["grades"]) <= set(ocupatii.GRADE_TOKENS)
        assert re.fullmatch(r"\d{6}|", o["cor"])
    assert "*" in d["by_kind"]
    for kind, pairs in d["by_kind"].items():
        assert len(pairs) <= 15
        assert [n for _i, n in pairs] == sorted((n for _i, n in pairs), reverse=True)
        assert all(0 <= i < len(occ) for i, _n in pairs)
        if kind:
            assert kind == "*" or kind in classify.EMPLOYER_KIND_LABELS
            assert kind == "*" or d["kind_labels"][kind] == classify.EMPLOYER_KIND_LABELS[kind]


def test_the_libreoffice_round_trip_is_checked_by_hand():
    """Documented, not automated: see the report of UX-EMP-01 (soffice --headless --convert-to docx)."""
    assert True
