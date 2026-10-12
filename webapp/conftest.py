import os
import sys
from pathlib import Path

import pytest

os.environ.setdefault("DJANGO_SETTINGS_MODULE", "posturi.settings")

# llm_config's load_dotenv() puts .env's NTFY_TOPIC into the environment the moment it
# is first imported. Import it now, once, so the per-test cleanup below is the last word:
# a test that imports it later would otherwise re-add the real topic after the cleanup.
_ROOT = str(Path(__file__).resolve().parents[1])
if _ROOT not in sys.path:
    sys.path.append(_ROOT)
try:
    import llm_config  # noqa: F401
except Exception:      # a test that needs it will say so
    pass


@pytest.fixture(autouse=True)
def _never_push_to_the_real_topic(monkeypatch):
    """No test may publish to the topic in .env (OPS-BALANCE); tests set their own."""
    for name in ("NTFY_TOPIC", "NTFY_URL", "NTFY_SUMMARY"):
        monkeypatch.delenv(name, raising=False)


@pytest.fixture(autouse=True)
def _never_write_to_the_real_llm_ledger(monkeypatch):
    """No test may insert into the real `jobs_llmcall` (FIX-10); tests pass their own sink."""
    monkeypatch.setenv("POSTURI_LEDGER", "off")


class _FakeNtfyResponse:
    status = 200

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def read(self):
        return b'{"id":"x","event":"message"}'


@pytest.fixture
def posts(monkeypatch):
    """Replace urlopen: each request it is given is appended, decoded, to this list.

    The returned list holds {url, method, headers, body (the parsed JSON), timeout}.
    Patch urlopen yourself instead to make a push fail.
    """
    import json
    sent: list[dict] = []

    def fake(request, timeout=None, **_):
        sent.append({"url": request.full_url, "method": request.get_method(),
                     "headers": {k.lower(): v for k, v in request.header_items()},
                     "body": json.loads(request.data.decode("utf-8")),
                     "timeout": timeout})
        return _FakeNtfyResponse()

    monkeypatch.setattr("urllib.request.urlopen", fake)
    return sent
