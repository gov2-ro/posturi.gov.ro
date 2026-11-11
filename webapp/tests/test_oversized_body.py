"""A posting with a pasted inline image must not stop the pipeline.

2026-11-10: a school pasted an image into its announcement. The editor stored it
as `<img src="image/emf;base64,…">`, so the page was 733 KB and the body field
in `anunturi.csv` was 728,713 characters. `parse-anunturi.py` raised the `csv`
field limit, `import_csvs` did not, and `import` failed on three runs in a row.
"""

import csv
import importlib
import importlib.util
import io
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_LIMIT = 131072


def _parser():
    spec = importlib.util.spec_from_file_location("parse_anunturi_body", REPO_ROOT / "parse-anunturi.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def test_import_csvs_reads_a_field_over_the_default_limit():
    from apps.jobs.management.commands import import_csvs

    csv.field_size_limit(DEFAULT_LIMIT)
    importlib.reload(import_csvs)
    big = "x" * (DEFAULT_LIMIT * 5)
    rows = list(csv.DictReader(io.StringIO(f"url,body\nu,{big}\n")))
    assert len(rows[0]["body"]) == len(big)


def test_parser_drops_inline_base64_payload():
    html = '<p>Text</p><img height="751" src="image/emf;base64,' + "QUJD" * 5000 + '"><p>Mai mult</p>'
    out = _parser().strip_inline_payloads(html)
    assert "QUJD" not in out
    assert "Text" in out and "Mai mult" in out
    assert len(out) < 200


def test_parser_drops_data_uri_payload():
    html = '<img src="data:image/png;base64,' + "iVBO" * 3000 + '" alt="x">'
    assert "iVBO" not in _parser().strip_inline_payloads(html)


def test_parser_keeps_short_base64_text():
    html = "<p>codul base64,QUJD este scurt</p>"
    assert _parser().strip_inline_payloads(html) == html
