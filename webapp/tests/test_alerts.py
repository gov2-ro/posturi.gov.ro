"""UX-14 — ops/alerts.py: the VPS side of the email alerts.

No network, no SSH, no email: a fake Brevo and a fake host (the `bin/alerts.php`
of the shared host) stand in. What is pinned:

  * the daily cap, and that confirmation and manage emails go before digests;
  * when a subscription is due, and the order (oldest first);
  * a blocked address: its subscriptions are deleted by id, and it gets no digest;
  * --dry-run changes nothing and prints ids and counts only;
  * a failed pull, or a failed send, stops the run: nothing more is sent;
  * the content of the emails, and no plain address in any output or log;
  * that Python opens what the host's sodium_crypto_box_seal sealed.
"""

from __future__ import annotations

import base64
import importlib.util
import json
import shutil
import sqlite3
import subprocess
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

nacl_public = pytest.importorskip("nacl.public")

REPO_ROOT = Path(__file__).resolve().parents[2]
NOW = datetime(2026, 11, 20, 16, 30, tzinfo=timezone.utc)
SITE = "https://posturi.example"
SECRET_ADDRESS = "secret.person@example.com"


@pytest.fixture(scope="module")
def alerts():
    path = REPO_ROOT / "ops" / "alerts.py"
    spec = importlib.util.spec_from_file_location("alerts_under_test", path)
    module = importlib.util.module_from_spec(spec)
    sys.modules["alerts_under_test"] = module
    spec.loader.exec_module(module)
    return module


@pytest.fixture
def keys(tmp_path):
    """A key pair: the private key in a mode-600 file, the public key for sealing."""
    private = nacl_public.PrivateKey.generate()
    key_file = tmp_path / "alerts.key"
    key_file.write_text(base64.b64encode(bytes(private)).decode() + "\n")
    key_file.chmod(0o600)
    return str(key_file), nacl_public.SealedBox(private.public_key)


def seal(box, text: str) -> str:
    return base64.b64encode(box.encrypt(text.encode())).decode()


class FakeBrevo:
    def __init__(self, blocked=()):
        self.sent: list[tuple[str, str, str]] = []
        self.unblocked: list[str] = []
        self.blocked_set = {b.lower() for b in blocked}
        self.fail_on: int | None = None       # raise BrevoError on this send (1-based)
        self.reject: set[str] = set()         # addresses Brevo answers 400 for
        self.error = None

    def send(self, to, subject, html_body):
        from alerts_under_test import BrevoError, Rejected
        if to in self.reject:
            raise Rejected("400")
        if self.fail_on is not None and len(self.sent) + 1 == self.fail_on:
            raise BrevoError("Brevo send failed (HTTP 503)")
        self.sent.append((to, subject, html_body))

    def blocked(self):
        return set(self.blocked_set)

    def unblock(self, email):
        self.unblocked.append(email)


class FakeHost:
    """The shared host: holds rows, records every call that would change it."""

    def __init__(self):
        self.subs: list[dict] = []
        self.outbox: list[dict] = []
        self.news: dict[int, dict] = {}       # subscription id -> {total, items}
        self.calls: list[tuple] = []
        self.fail_pull = False

    def pull(self):
        if self.fail_pull:
            from alerts_under_test import HostError
            raise HostError("pull: ssh failed: TimeoutExpired")
        return {"subscriptions": [dict(s) for s in self.subs], "outbox": [dict(o) for o in self.outbox]}

    def outbox_done(self, ids):
        self.calls.append(("outbox-done", sorted(ids)))

    def delete(self, ids):
        self.calls.append(("delete", sorted(ids)))

    def candidates(self, asked):
        self.calls.append(("candidates", [a["id"] for a in asked]))
        return [{"id": a["id"], **self.news.get(a["id"], {"total": 0, "items": []})} for a in asked]

    def digest_done(self, sub_id, when_iso):
        self.calls.append(("digest-done", sub_id, when_iso))

    def mutations(self):
        return [c for c in self.calls if c[0] != "candidates"]


def iso(delta: timedelta) -> str:
    return (NOW - delta).strftime("%Y-%m-%dT%H:%M:%SZ")


def make_sub(box, sub_id, email=None, *, age=timedelta(days=5), digest_age=None, label="Cluj", sort=""):
    return {"id": sub_id, "email_sealed": seal(box, email or f"user{sub_id}@example.com"),
            "token_sealed": seal(box, f"token{sub_id}" + "x" * 20), "search_key": "judet%5B%5D=cluj",
            "sort": sort, "label": label, "confirmed_at": iso(age),
            "last_digest_at": iso(digest_age) if digest_age is not None else None}


def news(n, first=100):
    return {"total": n, "items": [
        {"id": first + i, "title": f"Post {i}", "employer": "Spital", "place": "Cluj-Napoca, Cluj",
         "deadline": "2026-12-01", "url": f"{SITE}/job/{first + i}-post/"} for i in range(min(n, 50))]}


def outbox_row(box, row_id, kind="confirm", email=None, sub_id=None):
    payload = {"email": email or f"new{row_id}@example.com", "token": "t" * 30 + str(row_id),
               "subscription_id": sub_id if sub_id is not None else row_id}
    return {"id": row_id, "kind": kind, "payload_sealed": seal(box, json.dumps(payload)), "created_at": iso(timedelta(minutes=1))}


@pytest.fixture
def world(alerts, keys, tmp_path):
    key_file, box = keys
    cfg = alerts.Config(brevo_key="k", key_file=key_file, cap=280, host="host", path="~/x",
                        site_url=SITE, state_db=str(tmp_path / "alerts.sqlite"))
    class W:
        pass
    w = W()
    w.alerts, w.cfg, w.box = alerts, cfg, box
    w.host, w.brevo = FakeHost(), FakeBrevo()
    w.opener = alerts.Opener(key_file)
    w.log = alerts.SendLog(cfg.state_db)
    def run(job, dry_run=False, cfg=None):
        fn = alerts.run_digest if job == "digest" else alerts.run_outbox
        return fn(cfg or w.cfg, w.host, w.brevo, w.opener, w.log, NOW, dry_run)
    w.run = run
    return w


# ---- The cap ----

def test_cap_stops_the_digest_and_confirmations_go_first(world):
    w = world
    w.host.outbox = [outbox_row(w.box, 1), outbox_row(w.box, 2, "manage")]
    w.host.subs = [make_sub(w.box, 10, digest_age=timedelta(days=9)),
                   make_sub(w.box, 11, digest_age=timedelta(days=7)),
                   make_sub(w.box, 12, digest_age=timedelta(days=5))]
    w.host.news = {10: news(2), 11: news(2), 12: news(2)}
    cfg = w.alerts.Config(**{**w.cfg.__dict__, "cap": 3, "reserve": 0})
    report = w.run("digest", cfg=cfg)
    assert [s[1].split(":")[0] for s in w.brevo.sent][:2] == ["Confirmă alerta pe email", "Abonamentele mele"]
    assert len(w.brevo.sent) == 3                      # the cap
    assert [c[1] for c in w.host.calls if c[0] == "digest-done"] == [10]   # the oldest digest only
    assert report.skipped_cap == 2
    assert w.log.sent_today(NOW) == 3


def test_the_digest_leaves_the_reserve_for_confirmations(world):
    w = world
    w.host.subs = [make_sub(w.box, i, digest_age=timedelta(days=4)) for i in (10, 11, 12)]
    w.host.news = {10: news(1), 11: news(1), 12: news(1)}
    cfg = w.alerts.Config(**{**w.cfg.__dict__, "cap": 3, "reserve": 1})
    w.run("digest", cfg=cfg)
    assert len(w.brevo.sent) == 2                      # 3 - 1 reserved
    w.host.outbox = [outbox_row(w.box, 1)]
    w.run("outbox", cfg=cfg)
    assert len(w.brevo.sent) == 3                      # the confirmation still goes out


def test_the_cap_counts_the_whole_utc_day_across_runs(world):
    w = world
    cfg = w.alerts.Config(**{**w.cfg.__dict__, "cap": 2})
    w.host.outbox = [outbox_row(w.box, i) for i in (1, 2, 3)]
    w.run("outbox", cfg=cfg)
    assert len(w.brevo.sent) == 2
    w.host.outbox = [outbox_row(w.box, 3)]
    w.run("outbox", cfg=cfg)
    assert len(w.brevo.sent) == 2                      # still the same day: nothing more
    assert ("outbox-done", [3]) not in w.host.calls   # row 3 waits in the outbox
    tomorrow = NOW + timedelta(days=1)
    w.alerts.run_outbox(cfg, w.host, w.brevo, w.opener, w.log, tomorrow)
    assert len(w.brevo.sent) == 3                      # a new UTC day


# ---- The due rule ----

def test_due_rule_and_order(alerts, keys):
    _, box = keys
    subs = [make_sub(box, 1, digest_age=timedelta(days=2)),                        # not due
            make_sub(box, 2, digest_age=timedelta(days=3)),                        # due
            make_sub(box, 3, digest_age=timedelta(days=2, hours=23, minutes=30)),  # due: the tolerance
            make_sub(box, 4, age=timedelta(days=6)),                               # never sent: confirmed_at counts
            make_sub(box, 5, age=timedelta(days=1)),                               # confirmed yesterday: not due
            make_sub(box, 6, digest_age=timedelta(days=8))]                        # due, the oldest
    due = alerts.due_subscriptions(subs, NOW)
    assert [s["id"] for s in due] == [6, 4, 2, 3]


def test_nothing_new_sends_nothing_and_keeps_the_subscription_due(world):
    w = world
    w.host.subs = [make_sub(w.box, 10, digest_age=timedelta(days=4))]
    w.host.news = {10: {"total": 0, "items": []}}
    w.run("digest")
    assert w.brevo.sent == []
    assert w.host.mutations() == []                    # no digest-done: `since` stays


# ---- Blocked addresses ----

def test_blocked_addresses_are_deleted_by_id_and_get_no_digest(world):
    w = world
    w.host.subs = [make_sub(w.box, 10, email="Blocked.One@Example.com", digest_age=timedelta(days=4)),
                   make_sub(w.box, 11, email="blocked.one@example.com", digest_age=timedelta(days=4), label="Alt"),
                   make_sub(w.box, 12, email="fine@example.com", digest_age=timedelta(days=4))]
    w.host.news = {10: news(1), 11: news(1), 12: news(1)}
    w.brevo.blocked_set = {"blocked.one@example.com"}
    report = w.run("digest")
    assert ("delete", [10, 11]) in w.host.calls
    assert [s[0] for s in w.brevo.sent] == ["fine@example.com"]
    assert report.deleted_blocked == 2


def test_a_confirmation_unblocks_the_address_first(world):
    w = world
    w.host.outbox = [outbox_row(w.box, 1, "confirm", email="again@example.com"),
                     outbox_row(w.box, 2, "manage", email="m@example.com")]
    w.run("outbox")
    assert w.brevo.unblocked == ["again@example.com"]  # not for the manage email
    assert len(w.brevo.sent) == 2
    assert w.host.calls == [("outbox-done", [1, 2])]


# ---- Dry run ----

def test_dry_run_sends_and_changes_nothing_and_prints_no_address(world, capsys):
    w = world
    w.host.outbox = [outbox_row(w.box, 1, email=SECRET_ADDRESS)]
    w.host.subs = [make_sub(w.box, 10, email=SECRET_ADDRESS, digest_age=timedelta(days=4)),
                   make_sub(w.box, 11, email="blocked@example.com", digest_age=timedelta(days=4))]
    w.host.news = {10: news(3), 11: news(1)}
    w.brevo.blocked_set = {"blocked@example.com"}
    w.run("digest", dry_run=True)
    out = capsys.readouterr().out
    assert w.brevo.sent == [] and w.brevo.unblocked == []
    assert w.host.mutations() == []
    assert w.log.sent_today(NOW) == 0
    assert "would send confirm" in out and "would send digest (subscription 10" in out
    assert "@" not in out                              # ids and counts only
    w.run("outbox", dry_run=True)
    assert w.host.mutations() == []


# ---- Failures ----

def test_no_send_after_a_failed_pull(alerts, world, monkeypatch, tmp_path):
    w = world
    w.host.fail_pull = True
    pushed = []
    monkeypatch.setattr(alerts, "push_failure", lambda title, reason: pushed.append((title, reason)))
    for key, value in {"BREVO_API_KEY": "k", "ALERTS_KEY_FILE": w.cfg.key_file, "DEPLOY_HOST": "h",
                       "DEPLOY_PATH": "~/x", "SITE_URL": SITE, "ALERTS_STATE_DB": w.cfg.state_db}.items():
        monkeypatch.setenv(key, value)
    for job in ("outbox", "digest"):
        code = alerts.main([job], make_host=lambda c: w.host, make_brevo=lambda c: w.brevo, now=NOW)
        assert code == 1
    assert w.brevo.sent == [] and w.brevo.unblocked == []
    assert len(pushed) == 2 and "pull" in pushed[0][1]


def test_a_brevo_failure_stops_the_run_and_keeps_what_was_sent(alerts, world):
    w = world
    w.host.outbox = [outbox_row(w.box, 1), outbox_row(w.box, 2), outbox_row(w.box, 3)]
    w.brevo.fail_on = 2
    with pytest.raises(alerts.BrevoError):
        w.run("outbox")
    assert len(w.brevo.sent) == 1
    assert ("outbox-done", [1]) in w.host.calls        # the first is not sent twice; 2 and 3 stay


def test_a_digest_failure_stops_before_digest_done(alerts, world):
    w = world
    w.host.subs = [make_sub(w.box, 10, digest_age=timedelta(days=9)), make_sub(w.box, 11, digest_age=timedelta(days=8))]
    w.host.news = {10: news(1), 11: news(1)}
    w.brevo.fail_on = 2
    with pytest.raises(alerts.BrevoError):
        w.run("digest")
    done = [c[1] for c in w.host.calls if c[0] == "digest-done"]
    assert done == [10]


def test_a_refused_message_is_dropped_not_retried_forever(world):
    w = world
    w.host.outbox = [outbox_row(w.box, 1, email="bad@example.com"), outbox_row(w.box, 2, email="good@example.com")]
    w.brevo.reject = {"bad@example.com"}
    report = w.run("outbox")
    assert [s[0] for s in w.brevo.sent] == ["good@example.com"]
    assert ("outbox-done", [1, 2]) in w.host.calls
    assert report.rejected == 1
    assert w.log.sent_today(NOW) == 1                  # a refusal does not use the cap


def test_many_refusals_stop_the_run(world):
    w = world
    bad = [f"bad{i}@example.com" for i in range(4)]
    w.host.outbox = [outbox_row(w.box, i + 1, email=e) for i, e in enumerate(bad)]
    w.brevo.reject = set(bad)
    with pytest.raises(w.alerts.AlertsError):
        w.run("outbox")
    assert ("outbox-done", [1, 2, 3]) in w.host.calls   # row 4 stays for the next run


def test_digest_done_carries_the_time_of_the_search(world):
    w = world
    w.host.subs = [make_sub(w.box, 10, digest_age=timedelta(days=4))]
    w.host.news = {10: news(1)}
    w.run("digest")
    assert ("digest-done", 10, NOW.strftime("%Y-%m-%dT%H:%M:%SZ")) in w.host.calls


# ---- Content and privacy ----

def test_digest_content(alerts):
    sub = {"id": 7, "label": "Cluj <b>medici</b>", "search_key": "judet%5B%5D=cluj", "sort": "deadline"}
    subject, body = alerts.build_digest(SITE, sub, "TOKEN", {"total": 27, **news(27)})
    assert subject == "27 de anunțuri noi: Cluj <b>medici</b>"
    assert body.count("<li>") == 20                    # at most 20 listed
    assert "și încă 7" in body
    assert f"{SITE}/alerte/sterge/7/TOKEN" in body and "Șterge această abonare" in body
    assert f"{SITE}/alerte/abonari/7/TOKEN" in body and "Toate abonările mele" in body
    assert f"{SITE}/?judet%5B%5D=cluj&amp;sort=deadline" in body
    assert "<b>medici</b>" not in body                 # the label is escaped
    assert "numără deschiderile și clicurile" in body
    assert "<img" not in body
    assert alerts.plural_anunturi(1) == "1 anunț nou"
    assert alerts.plural_anunturi(5) == "5 anunțuri noi"
    assert alerts.plural_anunturi(20) == "20 de anunțuri noi"
    assert alerts.plural_anunturi(112) == "112 anunțuri noi"


def test_confirm_and_manage_content(alerts):
    subject, body = alerts.build_confirm(SITE, 5, "TOK")
    assert f"{SITE}/alerte/confirma/5/TOK" in body and "48 de ore" in body
    subject, body = alerts.build_manage(SITE, "TOK")
    assert f"{SITE}/alerte/intra/TOK" in body and "o oră" in body


def test_no_plain_address_in_the_log_or_the_output(world, capsys):
    w = world
    w.host.outbox = [outbox_row(w.box, 1, email=SECRET_ADDRESS)]
    w.host.subs = [make_sub(w.box, 10, email=SECRET_ADDRESS, digest_age=timedelta(days=4))]
    w.host.news = {10: news(2)}
    w.run("digest")
    assert [s[0] for s in w.brevo.sent] == [SECRET_ADDRESS, SECRET_ADDRESS]
    assert "@" not in capsys.readouterr().out
    rows = sqlite3.connect(w.cfg.state_db).execute("SELECT * FROM sends").fetchall()
    assert len(rows) == 2 and "example.com" not in json.dumps(rows)


# ---- Brevo: the endpoints used ----

class FakeResponse:
    def __init__(self, status=200, body=b"{}"):
        self.status, self._body = status, body

    def read(self):
        return self._body

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False


def test_brevo_calls_only_the_allowed_endpoints(alerts, monkeypatch):
    seen = []
    pages = [{"contacts": [{"email": f"B{i}@Example.com"} for i in range(100)]}, {"contacts": [{"email": "last@example.com"}]}]

    def fake_urlopen(request, timeout=None):
        seen.append((request.get_method(), request.full_url.replace(alerts.BREVO_URL, "")))
        assert request.headers["Api-key"] == "SECRET-KEY"
        if request.get_method() == "GET":
            return FakeResponse(200, json.dumps(pages.pop(0)).encode())
        return FakeResponse(201 if request.get_method() == "POST" else 204, b"{}")

    monkeypatch.setattr(alerts.urllib.request, "urlopen", fake_urlopen)
    brevo = alerts.Brevo("SECRET-KEY", "alerte@dispecerat.gov2.ro")
    brevo.send("a@example.com", "S", "<p>x</p>")
    blocked = brevo.blocked()
    brevo.unblock("a+b@example.com")
    assert len(blocked) == 101 and "b0@example.com" in blocked
    assert seen == [("POST", "/smtp/email"), ("GET", "/smtp/blockedContacts?limit=100&offset=0"),
                    ("GET", "/smtp/blockedContacts?limit=100&offset=100"),
                    ("DELETE", "/smtp/blockedContacts/a%2Bb%40example.com")]
    assert not any("contacts/" in p.replace("blockedContacts", "") and "smtp" not in p for _, p in seen)


def test_brevo_error_statuses(alerts, monkeypatch):
    def fake(status):
        def urlopen(request, timeout=None):
            raise alerts.urllib.error.HTTPError(request.full_url, status, "x", {}, None)
        return urlopen
    brevo = alerts.Brevo("k", "s@example.com")
    monkeypatch.setattr(alerts.urllib.request, "urlopen", fake(400))
    with pytest.raises(alerts.Rejected):
        brevo.send("a@example.com", "S", "x")
    monkeypatch.setattr(alerts.urllib.request, "urlopen", fake(401))
    with pytest.raises(alerts.BrevoError) as info:
        brevo.send("a@example.com", "S", "x")
    assert not isinstance(info.value, alerts.Rejected)
    monkeypatch.setattr(alerts.urllib.request, "urlopen", fake(404))
    brevo.unblock("a@example.com")                      # 404: it was not blocked
    monkeypatch.setattr(alerts.urllib.request, "urlopen", fake(500))
    with pytest.raises(alerts.BrevoError):
        brevo.blocked()


def test_host_remote_command(alerts):
    assert alerts.Host("u@h", "~/posturi.gov2.ro")._remote_command("outbox-done", ["1,2"]) == \
        "php ~/posturi.gov2.ro/bin/alerts.php outbox-done 1,2"
    assert alerts.Host("u@h", "/srv/my site")._remote_command("pull", []) == "php '/srv/my site'/bin/alerts.php pull"


# ---- Keys ----

def test_keygen_writes_a_600_file_and_never_overwrites(alerts, tmp_path):
    target = tmp_path / "new.key"
    public = alerts.keygen(str(target))
    assert len(base64.b64decode(public)) == 32
    assert (target.stat().st_mode & 0o777) == 0o600
    with pytest.raises(FileExistsError):
        alerts.keygen(str(target))
    opener = alerts.Opener(str(target))
    sealed = nacl_public.SealedBox(nacl_public.PublicKey(base64.b64decode(public))).encrypt(b"x")
    assert opener.open(base64.b64encode(sealed).decode()) == "x"


def test_opener_refuses_an_open_key_file(alerts, keys):
    key_file, _ = keys
    Path(key_file).chmod(0o644)
    with pytest.raises(alerts.ConfigError):
        alerts.Opener(key_file)


def test_python_opens_what_php_sodium_seals(alerts, tmp_path):
    php = shutil.which("php")
    if not php or subprocess.run([php, "-r", "exit(function_exists('sodium_crypto_box_seal') ? 0 : 1);"]).returncode != 0:
        pytest.skip("php with sodium is not available")
    key_file = tmp_path / "k"
    public = alerts.keygen(str(key_file))
    done = subprocess.run(
        [php, "-r", "echo base64_encode(sodium_crypto_box_seal($argv[1], base64_decode($argv[2])));",
         "--", "Ana.Pop@example.com", public], capture_output=True, text=True, check=True)
    assert alerts.Opener(str(key_file)).open(done.stdout) == "Ana.Pop@example.com"
