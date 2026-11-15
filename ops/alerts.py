#!/usr/bin/env python3
"""UX-14 — email alerts: the VPS side (ADR 0003, docs/briefs/2026-11-15-email-alerts.md).

The shared host owns the subscriptions and cannot send email. This script runs on the
VPS. It pulls the queue over SSH (`php <path>/bin/alerts.php ...`), opens the sealed
addresses with the private key, and sends through the Brevo transactional API.

    ops/alerts.py keygen              make the key pair; print the public key for the host
    ops/alerts.py outbox              cron, every 5 minutes: confirmation and manage emails
    ops/alerts.py digest              cron, 19:30 Europe/Bucharest: the digest of new postings
    add --dry-run to outbox or digest to print what would happen (ids and counts only)

Rules this script keeps:
  * It never prints or logs a plain address. It logs subscription ids.
  * It uses only POST /v3/smtp/email and the blocked-contacts endpoints of Brevo.
    It never calls a contacts or lists endpoint.
  * It never sends more than ALERTS_DAILY_CAP emails in one UTC day.
  * When the pull, the blocked list or a send fails, it sends nothing more, pushes a
    message through ops/ntfy.py and exits 1. It never sends from a list older than the run.

Settings (environment, else .env):
    BREVO_API_KEY      the transactional API key
    ALERTS_KEY_FILE    the private key (mode 600, outside the checkout)
    ALERTS_SENDER      default alerte@dispecerat.gov2.ro
    ALERTS_DAILY_CAP   default 280 (Brevo's free plan allows 300)
    DEPLOY_HOST, DEPLOY_PATH   the shared host, as in deploy-php.sh
    SITE_URL           public address of the site, for the links in the emails
    ALERTS_STATE_DB    the send log (default data/alerts.sqlite)
"""
from __future__ import annotations

import argparse
import base64
import fcntl
import html
import json
import os
import re
import shlex
import sqlite3
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Callable, Mapping

ROOT = Path(__file__).resolve().parent.parent

BREVO_URL = "https://api.brevo.com/v3"
DEFAULT_SENDER = "alerte@dispecerat.gov2.ro"
SENDER_NAME = "posturi.gov2.ro"
DEFAULT_CAP = 280
#: A digest is due 3 days after the last one. The cron fires at the same minute each
#: day, but the time stored is the moment of the search, a few seconds earlier. One
#: hour of tolerance keeps a subscription on the 3-day beat.
DUE_AFTER = timedelta(days=3) - timedelta(hours=1)
MAX_ITEMS = 20          # postings listed in one digest; the rest is "și încă N"
CANDIDATE_CHUNK = 50    # subscriptions per `candidates` call
TIMEOUT_S = 60.0

_ENV_LINE = re.compile(r"^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$")


class AlertsError(Exception):
    """A step failed in a way that stops the run: no more sends, ntfy, exit 1."""


class ConfigError(AlertsError):
    """A setting is missing."""


class HostError(AlertsError):
    """The SSH call, or the PHP command on the host, failed."""


class BrevoError(AlertsError):
    """Brevo refused or could not be reached."""


class Rejected(BrevoError):
    """Brevo refused this one message (HTTP 400). The next message may be fine."""


# ---------------------------------------------------------------------------
# Settings
# ---------------------------------------------------------------------------

def read_env_file(path: Path) -> dict[str, str]:
    """KEY=VALUE lines of .env, never sourced. Inline comments and quotes are removed."""
    out: dict[str, str] = {}
    if not path.is_file():
        return out
    for line in path.read_text(encoding="utf-8").splitlines():
        m = _ENV_LINE.match(line)
        if not m:
            continue
        value = re.sub(r"\s+#.*$", "", m.group(2)).strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        out[m.group(1)] = value
    return out


@dataclass(frozen=True)
class Config:
    brevo_key: str = ""
    key_file: str = ""
    sender: str = DEFAULT_SENDER
    cap: int = DEFAULT_CAP
    host: str = ""
    path: str = ""
    site_url: str = ""
    state_db: str = str(ROOT / "data" / "alerts.sqlite")

    @classmethod
    def load(cls, env: Mapping[str, str] | None = None) -> "Config":
        merged = dict(read_env_file(ROOT / ".env"))
        merged.update(os.environ if env is None else env)
        def get(name: str, default: str = "") -> str:
            return (merged.get(name) or default).strip()
        try:
            cap = int(get("ALERTS_DAILY_CAP", str(DEFAULT_CAP)))
        except ValueError as exc:
            raise ConfigError("ALERTS_DAILY_CAP is not a number") from exc
        return cls(
            brevo_key=get("BREVO_API_KEY"), key_file=get("ALERTS_KEY_FILE"),
            sender=get("ALERTS_SENDER", DEFAULT_SENDER), cap=cap,
            host=get("DEPLOY_HOST"), path=get("DEPLOY_PATH", "public_html"),
            site_url=get("SITE_URL").rstrip("/"),
            state_db=get("ALERTS_STATE_DB", str(ROOT / "data" / "alerts.sqlite")))

    def require(self, *names: str) -> None:
        missing = [n for n in names if not getattr(self, n)]
        if missing:
            raise ConfigError("missing setting: " + ", ".join(missing))


# ---------------------------------------------------------------------------
# Keys
# ---------------------------------------------------------------------------

def _nacl():
    try:
        import nacl.public
        import nacl.utils  # noqa: F401
    except ImportError as exc:
        raise ConfigError("PyNaCl is not installed: .venv/bin/pip install pynacl") from exc
    return nacl


def keygen(key_file: str) -> str:
    """Write a new private key (base64, mode 600). Returns the public key, base64."""
    nacl = _nacl()
    if not key_file:
        raise ConfigError("missing setting: key_file (ALERTS_KEY_FILE)")
    private = nacl.public.PrivateKey.generate()
    fd = os.open(key_file, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)   # never overwrite a key
    with os.fdopen(fd, "w") as fh:
        fh.write(base64.b64encode(bytes(private)).decode() + "\n")
    return base64.b64encode(bytes(private.public_key)).decode()


class Opener:
    """Opens what sodium_crypto_box_seal sealed on the host."""

    def __init__(self, key_file: str):
        nacl = _nacl()
        path = Path(key_file)
        if not key_file or not path.is_file():
            raise ConfigError("the private key file is missing (ALERTS_KEY_FILE)")
        if path.stat().st_mode & 0o077:
            raise ConfigError("the private key file must have mode 600")
        raw = base64.b64decode(path.read_text().strip())
        self._box = nacl.public.SealedBox(nacl.public.PrivateKey(raw))

    def open(self, sealed_b64: str) -> str:
        try:
            return self._box.decrypt(base64.b64decode(sealed_b64)).decode("utf-8")
        except Exception as exc:  # noqa: BLE001 -- never include the sealed value in the message
            raise AlertsError(f"cannot open a sealed value ({type(exc).__name__}): wrong key?") from exc


# ---------------------------------------------------------------------------
# The host (SSH) and Brevo (HTTPS)
# ---------------------------------------------------------------------------

class Host:
    """The shared host's bin/alerts.php, over SSH. Every call returns the parsed JSON."""

    def __init__(self, destination: str, path: str, timeout: float = 120.0):
        self.destination, self.path, self.timeout = destination, path, timeout

    def _remote_command(self, command: str, args: list[str]) -> str:
        # `~/x` must reach the remote shell unquoted for the tilde to expand.
        if self.path.startswith("~/"):
            base = "~/" + shlex.quote(self.path[2:])
        else:
            base = shlex.quote(self.path)
        return "php " + base + "/bin/alerts.php " + " ".join(shlex.quote(a) for a in [command, *args])

    def call(self, command: str, args: list[str] | None = None, stdin: object = None) -> dict:
        argv = ["ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=20", self.destination,
                self._remote_command(command, args or [])]
        try:
            done = subprocess.run(argv, input=None if stdin is None else json.dumps(stdin),
                                  capture_output=True, text=True, timeout=self.timeout)
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise HostError(f"ssh failed: {type(exc).__name__}") from exc
        try:
            data = json.loads(done.stdout)
        except ValueError as exc:
            raise HostError(f"{command}: the host did not answer with JSON (exit {done.returncode})") from exc
        if done.returncode != 0 or not isinstance(data, dict) or "error" in data:
            reason = data.get("error") if isinstance(data, dict) else "bad answer"
            raise HostError(f"{command}: {reason}")
        return data

    def pull(self) -> dict:
        return self.call("pull")

    def outbox_done(self, ids: list[int]) -> None:
        if ids:
            self.call("outbox-done", [",".join(map(str, ids))])

    def delete(self, ids: list[int]) -> None:
        if ids:
            self.call("delete", [",".join(map(str, ids))])

    def candidates(self, asked: list[dict]) -> list[dict]:
        return self.call("candidates", stdin=asked)["candidates"]

    def digest_done(self, sub_id: int, when_iso: str) -> None:
        self.call("digest-done", [str(sub_id), when_iso])


class Brevo:
    """The three Brevo calls this script may make. Nothing else."""

    def __init__(self, api_key: str, sender: str, timeout: float = TIMEOUT_S):
        self.api_key, self.sender, self.timeout = api_key, sender, timeout

    def _request(self, method: str, path: str, body: dict | None = None) -> tuple[int, dict]:
        data = None if body is None else json.dumps(body).encode("utf-8")
        request = urllib.request.Request(
            BREVO_URL + path, data=data, method=method,
            headers={"api-key": self.api_key, "accept": "application/json",
                     "content-type": "application/json"})
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                raw = response.read()
                return response.status, (json.loads(raw) if raw else {})
        except urllib.error.HTTPError as exc:
            return exc.code, {}
        except (urllib.error.URLError, TimeoutError, OSError, ValueError) as exc:
            raise BrevoError(f"{method} {path.split('?')[0].split('/')[1]}: {type(exc).__name__}") from exc

    def send(self, to: str, subject: str, html_body: str) -> None:
        status, _ = self._request("POST", "/smtp/email", {
            "sender": {"email": self.sender, "name": SENDER_NAME},
            "to": [{"email": to}], "subject": subject, "htmlContent": html_body})
        if status == 400:
            raise Rejected("Brevo refused one message (HTTP 400)")
        if status not in (200, 201, 202):
            raise BrevoError(f"Brevo send failed (HTTP {status})")

    def blocked(self) -> set[str]:
        """Every address on the blocked list, lower case."""
        out: set[str] = set()
        offset = 0
        while True:
            status, data = self._request("GET", f"/smtp/blockedContacts?limit=100&offset={offset}")
            if status != 200:
                raise BrevoError(f"Brevo blocked list failed (HTTP {status})")
            contacts = data.get("contacts") or []
            out.update(str(c.get("email", "")).lower() for c in contacts)
            offset += 100
            if len(contacts) < 100:
                return out

    def unblock(self, email: str) -> None:
        status, _ = self._request("DELETE", "/smtp/blockedContacts/" + urllib.parse.quote(email, safe=""))
        if status not in (200, 204, 404):      # 404: the address was not blocked
            raise BrevoError(f"Brevo unblock failed (HTTP {status})")


# ---------------------------------------------------------------------------
# The send log (alerts.sqlite) and the daily cap
# ---------------------------------------------------------------------------

class SendLog:
    """One row per send: subscription id, kind, time, result. Never an address."""

    def __init__(self, path: str):
        if path != ":memory:":
            Path(path).parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(path)
        self.db.execute("""CREATE TABLE IF NOT EXISTS sends (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            day TEXT NOT NULL, sent_at TEXT NOT NULL, kind TEXT NOT NULL,
            subscription_id INTEGER, result TEXT NOT NULL,
            last_posting_created_at TEXT)""")
        self.db.commit()

    def sent_today(self, now: datetime) -> int:
        row = self.db.execute("SELECT COUNT(*) FROM sends WHERE day = ? AND result = 'ok'",
                              (now.astimezone(timezone.utc).strftime("%Y-%m-%d"),)).fetchone()
        return int(row[0])

    def record(self, now: datetime, kind: str, sub_id: int | None, result: str,
               last_posting: str | None = None) -> None:
        utc = now.astimezone(timezone.utc)
        self.db.execute(
            "INSERT INTO sends (day, sent_at, kind, subscription_id, result, last_posting_created_at)"
            " VALUES (?, ?, ?, ?, ?, ?)",
            (utc.strftime("%Y-%m-%d"), utc.strftime("%Y-%m-%dT%H:%M:%SZ"), kind, sub_id, result, last_posting))
        self.db.commit()


# ---------------------------------------------------------------------------
# Email content: plain HTML, no images, Romanian
# ---------------------------------------------------------------------------

TRACKING_LINE = ("Furnizorul de email (Brevo) numără deschiderile și clicurile, "
                 "fără să le lege de tine.")


def plural_anunturi(n: int) -> str:
    """„1 anunț nou”, „5 anunțuri noi”, „20 de anunțuri noi”."""
    if n == 1:
        return "1 anunț nou"
    de = "" if 1 <= n % 100 <= 19 else " de"
    return f"{n}{de} anunțuri noi"


def _page(title: str, body: str) -> str:
    return ("<!doctype html><html lang=\"ro\"><head><meta charset=\"utf-8\">"
            f"<title>{html.escape(title)}</title></head>"
            "<body style=\"font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;max-width:36rem\">"
            f"{body}</body></html>")


def build_confirm(site: str, sub_id: int, token: str) -> tuple[str, str]:
    link = f"{site}/alerte/confirma/{sub_id}/{token}"
    subject = "Confirmă alerta pe email"
    body = (f"<h1 style=\"font-size:20px\">{html.escape(subject)}</h1>"
            "<p>Ai cerut o alertă pe email pentru o căutare de pe posturi.gov2.ro.</p>"
            f"<p><a href=\"{html.escape(link)}\">Confirmă abonarea</a></p>"
            "<p>Nu ai cerut tu alerta? Ignoră mesajul. Abonarea se șterge singură în 48 de ore.</p>"
            f"<p style=\"font-size:13px\">{html.escape(TRACKING_LINE)}</p>")
    return subject, _page(subject, body)


def build_manage(site: str, token: str) -> tuple[str, str]:
    link = f"{site}/alerte/intra/{token}"
    subject = "Abonamentele mele"
    body = (f"<h1 style=\"font-size:20px\">{html.escape(subject)}</h1>"
            "<p>Ai cerut un link către abonările tale pe posturi.gov2.ro.</p>"
            f"<p><a href=\"{html.escape(link)}\">Vezi și șterge abonările</a></p>"
            "<p>Linkul este valabil o oră. Nu ai cerut tu linkul? Ignoră mesajul.</p>"
            f"<p style=\"font-size:13px\">{html.escape(TRACKING_LINE)}</p>")
    return subject, _page(subject, body)


def build_digest(site: str, sub: dict, token: str, cand: dict) -> tuple[str, str]:
    """The digest of one subscription. `sub` has id, label, search_key, sort."""
    items = cand["items"][:MAX_ITEMS]
    total = int(cand["total"])
    key = sub["search_key"] + (f"&sort={sub['sort']}" if sub.get("sort") else "")
    search_link = f"{site}/?{key}"
    label = sub.get("label") or "Căutare salvată"
    subject = f"{plural_anunturi(total)}: {label}"
    rows = []
    for it in items:
        facts = ", ".join(x for x in (it.get("employer"), it.get("place")) if x)
        deadline = f" · termen {html.escape(str(it['deadline']))}" if it.get("deadline") else ""
        rows.append(f"<li><a href=\"{html.escape(it['url'])}\">{html.escape(it['title'])}</a>"
                    f"<br><span style=\"font-size:13px\">{html.escape(facts)}{deadline}</span></li>")
    more = ""
    if total > len(items):
        more = (f"<p><a href=\"{html.escape(search_link)}\">și încă {total - len(items)}</a></p>")
    sid = sub["id"]
    delete_link = f"{site}/alerte/sterge/{sid}/{token}"
    manage_link = f"{site}/alerte/abonari/{sid}/{token}"
    body = (f"<h1 style=\"font-size:20px\">{html.escape(plural_anunturi(total))}</h1>"
            f"<p>Căutarea ta: <a href=\"{html.escape(search_link)}\">{html.escape(label)}</a></p>"
            f"<ul>{''.join(rows)}</ul>{more}"
            "<hr>"
            f"<p style=\"font-size:13px\"><a href=\"{html.escape(delete_link)}\">Șterge această abonare</a>"
            f" · <a href=\"{html.escape(manage_link)}\">Toate abonările mele</a></p>"
            f"<p style=\"font-size:13px\">{html.escape(TRACKING_LINE)}</p>")
    return subject, _page(subject, body)


# ---------------------------------------------------------------------------
# The two jobs
# ---------------------------------------------------------------------------

@dataclass
class Report:
    sent: int = 0
    skipped_cap: int = 0
    rejected: int = 0
    deleted_blocked: int = 0
    digests: int = 0
    due: int = 0
    lines: list[str] = field(default_factory=list)

    def say(self, line: str) -> None:
        self.lines.append(line)
        print(line)


def _iso(when: datetime) -> str:
    return when.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _parse(value: str | None) -> datetime | None:
    if not value:
        return None
    return datetime.fromisoformat(value.replace("Z", "+00:00")).astimezone(timezone.utc)


def process_outbox(cfg: Config, host: Host, brevo: Brevo, opener: Opener, log: SendLog,
                   rows: list[dict], now: datetime, dry_run: bool, report: Report) -> None:
    """Send the confirmation and manage emails of one pull, oldest first."""
    done: list[int] = []
    try:
        for row in sorted(rows, key=lambda r: r["id"]):
            if log.sent_today(now) >= cfg.cap:
                report.skipped_cap += 1
                continue
            if dry_run:
                report.say(f"would send {row['kind']} (outbox {row['id']})")
                continue
            try:
                payload = json.loads(opener.open(row["payload_sealed"]))
                email, token = payload["email"], payload["token"]
                sub_id = int(payload.get("subscription_id") or 0)
                if row["kind"] == "confirm":
                    brevo.unblock(email)        # a reader who subscribes again after a block
                    subject, body = build_confirm(cfg.site_url, sub_id, token)
                else:
                    subject, body = build_manage(cfg.site_url, token)
                brevo.send(email, subject, body)
            except Rejected:
                log.record(now, row["kind"], None, "rejected")
                report.rejected += 1
                done.append(row["id"])          # Brevo will never take this one: drop it
                report.say(f"{row['kind']} outbox {row['id']}: rejected by Brevo, dropped")
                continue
            except (KeyError, ValueError) as exc:
                raise AlertsError(f"outbox {row['id']}: unreadable payload ({type(exc).__name__})") from exc
            log.record(now, row["kind"], sub_id or None, "ok")
            report.sent += 1
            done.append(row["id"])
            report.say(f"sent {row['kind']} (outbox {row['id']}, subscription {sub_id})")
    finally:
        if done and not dry_run:
            host.outbox_done(done)              # also after a failure: do not send these twice
    if report.skipped_cap:
        report.say(f"daily cap {cfg.cap} reached: {report.skipped_cap} outbox row(s) wait for tomorrow")


def run_outbox(cfg: Config, host: Host, brevo: Brevo, opener: Opener, log: SendLog,
               now: datetime, dry_run: bool = False) -> Report:
    report = Report()
    pulled = host.pull()                         # a failed pull raises: nothing is sent
    rows = pulled.get("outbox", [])
    report.say(f"outbox: {len(rows)} row(s)")
    process_outbox(cfg, host, brevo, opener, log, rows, now, dry_run, report)
    return report


def due_subscriptions(subs: list[dict], now: datetime) -> list[dict]:
    """Subscriptions whose last digest (or confirmation) is 3 days old, oldest first."""
    due = []
    for s in subs:
        ref = _parse(s.get("last_digest_at")) or _parse(s.get("confirmed_at"))
        if ref is not None and now - ref >= DUE_AFTER:
            due.append((ref, s["id"], s))
    return [s for _, _, s in sorted(due, key=lambda t: (t[0], t[1]))]


def run_digest(cfg: Config, host: Host, brevo: Brevo, opener: Opener, log: SendLog,
               now: datetime, dry_run: bool = False) -> Report:
    report = Report()
    pulled = host.pull()                         # a failed pull raises: nothing is sent
    # Confirmation and manage emails go first.
    process_outbox(cfg, host, brevo, opener, log, pulled.get("outbox", []), now, dry_run, report)

    subs = pulled.get("subscriptions", [])
    blocked = brevo.blocked()
    emails: dict[int, str] = {}
    for s in subs:
        emails[s["id"]] = opener.open(s["email_sealed"]).strip().lower()
    gone = [s["id"] for s in subs if emails[s["id"]] in blocked]
    if gone:
        report.deleted_blocked = len(gone)
        report.say(f"blocked on Brevo: delete subscription(s) {','.join(map(str, gone))}")
        if not dry_run:
            host.delete(gone)
        subs = [s for s in subs if s["id"] not in set(gone)]

    due = due_subscriptions(subs, now)
    report.due = len(due)
    report.say(f"digest: {len(subs)} confirmed, {len(due)} due")
    query_time = _iso(now)
    results: dict[int, dict] = {}
    for i in range(0, len(due), CANDIDATE_CHUNK):
        chunk = due[i:i + CANDIDATE_CHUNK]
        asked = [{"id": s["id"], "since": s.get("last_digest_at") or s["confirmed_at"]} for s in chunk]
        for c in host.candidates(asked):
            results[int(c["id"])] = c

    with_news = [s for s in due if results.get(s["id"], {}).get("total", 0) > 0]
    report.say(f"digest: {len(with_news)} with new postings")
    for s in with_news:
        if log.sent_today(now) >= cfg.cap:
            report.skipped_cap += 1
            continue
        if dry_run:
            report.say(f"would send digest (subscription {s['id']}, {results[s['id']]['total']} postings)")
            continue
        token = opener.open(s["token_sealed"])
        subject, body = build_digest(cfg.site_url, s, token, results[s["id"]])
        try:
            brevo.send(emails[s["id"]], subject, body)
        except Rejected:
            log.record(now, "digest", s["id"], "rejected")
            report.rejected += 1
            report.say(f"digest subscription {s['id']}: rejected by Brevo")
            continue
        newest = max((it.get("id", 0) for it in results[s["id"]]["items"]), default=0)
        log.record(now, "digest", s["id"], "ok", str(newest))
        host.digest_done(s["id"], query_time)
        report.sent += 1
        report.digests += 1
        report.say(f"sent digest (subscription {s['id']}, {results[s['id']]['total']} postings)")
    if report.skipped_cap:
        report.say(f"daily cap {cfg.cap} reached: {report.skipped_cap} item(s) wait for the next run")
    return report


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def push_failure(title: str, reason: str) -> None:
    """Tell pax through ops/ntfy.py. A broken notifier must not hide the original error."""
    try:
        sys.path.insert(0, str(Path(__file__).resolve().parent))
        import ntfy
        ntfy.notify(ntfy.Message(title, reason, 4, ("warning",)))
    except Exception as exc:  # noqa: BLE001
        print(f"WARNING: could not push the alert: {type(exc).__name__}")


def _lock(state_db: str, wait: bool):
    path = Path(state_db).with_suffix(".lock")
    path.parent.mkdir(parents=True, exist_ok=True)
    handle = open(path, "w")
    try:
        fcntl.flock(handle, fcntl.LOCK_EX | (0 if wait else fcntl.LOCK_NB))
    except BlockingIOError:
        handle.close()
        return None
    return handle


def main(argv: list[str] | None = None,
         make_host: Callable[[Config], Host] | None = None,
         make_brevo: Callable[[Config], Brevo] | None = None,
         now: datetime | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("command", choices=["keygen", "outbox", "digest"])
    parser.add_argument("--dry-run", action="store_true", help="print what would happen; send and change nothing")
    args = parser.parse_args(argv)
    try:
        cfg = Config.load()
        if args.command == "keygen":
            cfg.require("key_file")
            public = keygen(cfg.key_file)
            print("Private key written to the file named in ALERTS_KEY_FILE (mode 600).")
            print("Add that file to your manual backup now. Without it every subscriber is lost.")
            print("Public key, for alerts-config.php on the host (vps_public_key):")
            print(public)
            return 0
        cfg.require("brevo_key", "key_file", "host", "path", "site_url")
        opener = Opener(cfg.key_file)
        host = make_host(cfg) if make_host else Host(cfg.host, cfg.path)
        brevo = make_brevo(cfg) if make_brevo else Brevo(cfg.brevo_key, cfg.sender)
        lock = _lock(cfg.state_db, wait=args.command == "digest")
        if lock is None:
            print("another alerts run is active: skipped")
            return 0
        log = SendLog(cfg.state_db)
        when = now or datetime.now(timezone.utc)
        run = run_outbox if args.command == "outbox" else run_digest
        report = run(cfg, host, brevo, opener, log, when, args.dry_run)
        print(f"done: {report.sent} sent" + (" (dry run)" if args.dry_run else ""))
        return 0
    except AlertsError as exc:
        print(f"ERROR: {exc}")
        push_failure(f"posturi alerts {args.command} failed", str(exc))
        return 1


if __name__ == "__main__":
    sys.exit(main())
