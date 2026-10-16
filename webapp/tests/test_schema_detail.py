"""Unit tests for the structured-field renderers used by the variant matrix.

``baseSalary`` / ``application_fee`` / ``application_contact`` are dicts in the
v2 schema; the variant-comparison view renders them to plain text before the
markdown pass. (The public detail page that also used them is the PHP app.)
"""

def test_render_base_salary_range():
    from apps.jobs.views import _render_base_salary
    out = _render_base_salary({"minValue": 4500, "maxValue": 6000, "currency": "RON", "unitText": "MONTH"})
    assert out == "4500–6000 RON/lună"


def test_render_base_salary_single_value():
    from apps.jobs.views import _render_base_salary
    out = _render_base_salary({"minValue": 4500, "maxValue": 4500, "currency": "RON", "unitText": "MONTH"})
    assert out == "4500 RON/lună"


def test_render_base_salary_null_returns_empty():
    from apps.jobs.views import _render_base_salary
    assert _render_base_salary({"minValue": None, "maxValue": None, "currency": "RON", "unitText": "MONTH"}) == ""
    assert _render_base_salary(None) == ""


def test_render_application_fee_full():
    from apps.jobs.views import _render_application_fee
    out = _render_application_fee({"amount": 50, "currency": "RON", "account": "RO12TREZ000", "details": "achitat în avans"})
    assert "50 RON" in out
    assert "RO12TREZ000" in out
    assert "achitat în avans" in out


def test_render_application_fee_amount_only():
    from apps.jobs.views import _render_application_fee
    out = _render_application_fee({"amount": 50, "currency": "RON", "account": None, "details": None})
    assert out == "50 RON"


def test_render_application_contact_partial():
    from apps.jobs.views import _render_application_contact
    out = _render_application_contact({"name": "Biroul RU", "phone": None, "email": "ru@x.ro", "address": None})
    assert "Biroul RU" in out
    assert "ru@x.ro" in out
    assert "Telefon" not in out
    assert "Adresă" not in out
