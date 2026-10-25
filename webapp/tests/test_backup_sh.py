"""BETA-BACKUP -- ops/backup.sh and ops/restore-test.sh, with restic, pg_dump, pg_restore,
curl and the Python interpreter all stubbed on PATH. Nothing here touches a repository,
a database or the network.

What is pinned: the script refuses to run (exit 78, a message naming what is missing, no
dump taken, nothing uploaded) when configuration is missing; it reads its settings from
the environment or .env the way run-pipeline.sh does; the order dump -> backup -> forget
-> check; what is and is not handed to restic (.env never); that a failure is reported
(healthcheck /fail, ntfy) and never prunes; and that the dump is gone from the disk
afterwards. The restore test's verdicts are pinned the same way, and so is the crontab
line in docs/deploy-vps.md (exactly one firing a night across both DST changes).
"""

from __future__ import annotations

import os
import re
import shutil
import subprocess
from datetime import datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]

STUB_RESTIC = r"""#!/usr/bin/env bash
echo "restic $*" >> "$STUB_LOG"
case "$1" in
  version) echo "restic 0.16.4 (stub)" ;;
  cat) exit "${STUB_RESTIC_CAT:-0}" ;;
  snapshots) echo "ID  Time  Host  Tags" ;;
  backup)
    echo "repo=${RESTIC_REPOSITORY:-}" >> "$STUB_LOG"
    for a in "$@"; do
      case "$a" in *posturi.dump) [ -s "$a" ] && echo "dump-readable" >> "$STUB_LOG" ;; esac
    done
    exit "${STUB_RESTIC_BACKUP:-0}" ;;
  forget) exit "${STUB_RESTIC_FORGET:-0}" ;;
  check) exit "${STUB_RESTIC_CHECK:-0}" ;;
  restore)
    target=""
    while [ $# -gt 0 ]; do [ "$1" = "--target" ] && target="$2"; shift; done
    [ "${STUB_RESTORE_EXIT:-0}" = "0" ] || exit "$STUB_RESTORE_EXIT"
    base="$target$STUB_ROOT/data"
    mkdir -p "$base/.backup-staging" "$base/anunturi/2026" "$base/downloads"
    [ "${STUB_RESTORE_NODUMP:-0}" = "1" ] || echo dumpbytes > "$base/.backup-staging/posturi.dump"
    for i in $(seq 1 "${STUB_RESTORE_HTML:-10}"); do echo x > "$base/anunturi/2026/p$i.html"; done
    for i in $(seq 1 "${STUB_RESTORE_DL:-5}"); do echo x > "$base/downloads/d$i.pdf"; done
    echo '{"kind":"run"}' > "$base/pipeline-runs.jsonl" ;;
esac
exit 0
"""

STUB_PG_DUMP = r"""#!/usr/bin/env bash
echo "pg_dump $*" >> "$STUB_LOG"
for a in "$@"; do
  case "$a" in --file=*) head -c 100 /dev/zero | tr '\0' 'D' > "${a#--file=}" ;; esac
done
exit "${STUB_PGDUMP:-0}"
"""

STUB_PG_RESTORE = r"""#!/usr/bin/env bash
echo "pg_restore $*" >> "$STUB_LOG"
[ "${STUB_PGRESTORE:-0}" = "0" ] || { echo "pg_restore: error: bad archive" >&2; exit "$STUB_PGRESTORE"; }
echo "; Archive created at 2026-10-26"
echo "; Dumped from database version 16.4"
if [ "${STUB_TOC_NO_JOBS:-0}" != "1" ]; then
  echo "5123; 0 16444 TABLE DATA public jobs_jobposting pax"
fi
echo "5124; 0 16450 TABLE DATA public jobs_llmcall pax"
exit 0
"""

STUB_LOGGER = """#!/usr/bin/env bash
echo "$(basename "$0") $*" >> "$STUB_LOG"
"""

GOOD = {
    "RESTIC_REPOSITORY": "s3:https://s3.example.invalid/bucket/posturi",
    "RESTIC_PASSWORD": "not-a-real-password",
    "AWS_ACCESS_KEY_ID": "key-id-not-real",
    "AWS_SECRET_ACCESS_KEY": "app-key-not-real-s3cr3t",
    "DATABASE_URL": "postgres://pax@/posturi",
}


@pytest.fixture
def root(tmp_path):
    repo = tmp_path / "repo"
    (repo / "ops").mkdir(parents=True)
    for name in ("backup.sh", "backup-env.sh", "env.sh", "restore-test.sh"):
        shutil.copy(REPO_ROOT / "ops" / name, repo / "ops" / name)
    html = repo / "data" / "anunturi" / "2026"
    html.mkdir(parents=True)
    for i in range(1, 11):
        (html / f"p{i}.html").write_text("<html></html>")
    (repo / "data" / "anunturi" / "anunturi.csv").write_text("regenerable\n")
    (repo / "data" / "downloads").mkdir()
    for i in range(1, 6):
        (repo / "data" / "downloads" / f"d{i}.pdf").write_text("pdf")
    (repo / "data" / "pipeline-runs.jsonl").write_text('{"kind": "run"}\n')
    (repo / ".env").write_text("SECRET_THING=do-not-upload\n")
    bin_dir = tmp_path / "bin"
    bin_dir.mkdir()
    for name, body in (("restic", STUB_RESTIC), ("pg_dump", STUB_PG_DUMP),
                       ("pg_restore", STUB_PG_RESTORE), ("curl", STUB_LOGGER),
                       ("python-stub", STUB_LOGGER)):
        p = bin_dir / name
        p.write_text(body)
        p.chmod(0o755)
    return repo


def run_script(root: Path, script: str = "backup.sh", args=(), config=GOOD, **extra):
    """Run a script copied into `root`. The environment is built from scratch, so nothing
    of the real shell (RESTIC_*, AWS_*, DATABASE_URL) can leak into a test."""
    log = root.parent / "calls.log"
    log.unlink(missing_ok=True)               # one run's calls per result
    bin_dir = root.parent / "bin"
    env = {
        "PATH": f"{bin_dir}:{os.environ['PATH']}",
        "HOME": str(root.parent),
        "TMPDIR": str(root.parent),
        "STUB_LOG": str(log),
        "STUB_ROOT": str(root),
        "PYTHON": str(bin_dir / "python-stub"),
        "POSTURI_BACKUP_LOCKED": "1",         # skip the flock re-exec
        "BACKUP_MIN_DUMP_BYTES": "1",
        **config,
        **{k: str(v) for k, v in extra.items()},
    }
    proc = subprocess.run(["bash", str(root / "ops" / script), *args], env=env, cwd=root.parent,
                          capture_output=True, text=True, timeout=60)
    calls = log.read_text().splitlines() if log.exists() else []
    return proc.returncode, proc.stdout + proc.stderr, calls


def called(calls, prefix):
    return [c for c in calls if c.startswith(prefix)]


def index_of(calls, prefix):
    return next(i for i, c in enumerate(calls) if c.startswith(prefix))


def without(**drop):
    return {k: v for k, v in GOOD.items() if k not in drop}


# ----------------------------------------------------------------- refusing to run

def test_refuses_to_run_with_no_configuration_at_all(root):
    code, out, calls = run_script(root, config={})
    assert code == 78
    assert "ERROR: RESTIC_REPOSITORY is not set" in out
    assert "neither RESTIC_PASSWORD_FILE nor RESTIC_PASSWORD" in out
    assert "DATABASE_URL is not set" in out
    assert "ABORT: not configured" in out
    assert not called(calls, "pg_dump") and not called(calls, "restic backup"), "nothing ran"


def test_refuses_to_run_when_restic_is_absent(root):
    code, out, calls = run_script(root, RESTIC_BIN="definitely-not-installed-restic")
    assert code == 78
    assert "restic not found" in out and "definitely-not-installed-restic" in out
    assert not called(calls, "pg_dump")


def test_an_s3_repository_needs_the_aws_credentials(root):
    code, out, calls = run_script(root, config=without(AWS_ACCESS_KEY_ID=1, AWS_SECRET_ACCESS_KEY=1))
    assert code == 78
    assert "AWS_ACCESS_KEY_ID is not set" in out and "AWS_SECRET_ACCESS_KEY is not set" in out
    assert not called(calls, "pg_dump")


def test_a_native_b2_repository_needs_the_b2_credentials(root):
    config = {**without(AWS_ACCESS_KEY_ID=1, AWS_SECRET_ACCESS_KEY=1),
              "RESTIC_REPOSITORY": "b2:bucket:posturi"}
    code, out, _ = run_script(root, config=config)
    assert code == 78 and "B2_ACCOUNT_ID is not set" in out and "B2_ACCOUNT_KEY is not set" in out
    code, out, _ = run_script(root, config=config, B2_ACCOUNT_ID="a", B2_ACCOUNT_KEY="b")
    assert code == 0, out


def test_a_password_file_must_exist_and_not_be_empty(root, tmp_path):
    config = without(RESTIC_PASSWORD=1)
    code, out, _ = run_script(root, config=config, RESTIC_PASSWORD_FILE=tmp_path / "missing")
    assert code == 78 and "RESTIC_PASSWORD_FILE" in out and "not readable" in out
    empty = tmp_path / "empty"
    empty.write_text("")
    code, out, _ = run_script(root, config=config, RESTIC_PASSWORD_FILE=empty)
    assert code == 78 and "is empty" in out
    good = tmp_path / "good"
    good.write_text("a long random line\n")
    code, out, _ = run_script(root, config=config, RESTIC_PASSWORD_FILE=good)
    assert code == 0, out


def test_refuses_when_there_is_nothing_to_back_up(root):
    shutil.rmtree(root / "data" / "downloads")
    code, out, calls = run_script(root)
    assert code == 78 and "data/downloads does not exist" in out
    assert not called(calls, "pg_dump")


def test_a_missing_config_is_reported_to_the_healthcheck(root):
    code, out, calls = run_script(root, config={}, BACKUP_HEALTHCHECK_URL="http://hc.invalid/b")
    assert code == 78
    assert called(calls, "curl") and called(calls, "curl")[-1].endswith("/b/fail")


# ------------------------------------------------------------------ a healthy run

def test_settings_come_from_dotenv_and_the_environment_wins(root, tmp_path):
    password = tmp_path / "restic.pass"
    password.write_text("pw\n")
    (root / ".env").write_text("\n".join([
        "SECRET_THING=do-not-upload",
        "# the repository",
        f"RESTIC_REPOSITORY=s3:https://from-dotenv.invalid/bucket   # inline comment",
        f"RESTIC_PASSWORD_FILE={password}",
        'AWS_ACCESS_KEY_ID="quoted-id"',
        "AWS_SECRET_ACCESS_KEY=dotenv-secret",
        "DATABASE_URL=postgres://pax@/posturi",
        "BACKUP_HEALTHCHECK_URL=http://hc.invalid/from-dotenv",
        ""]))
    code, out, calls = run_script(root, config={})
    assert code == 0, out
    assert "repo=s3:https://from-dotenv.invalid/bucket" in calls
    assert called(calls, "curl")[0].endswith("/from-dotenv/start")

    code, out, calls = run_script(root, config={}, RESTIC_REPOSITORY="s3:https://from-env.invalid/b")
    assert code == 0 and "repo=s3:https://from-env.invalid/b" in calls


def test_a_healthy_run_dumps_uploads_prunes_and_checks_in_that_order(root):
    code, out, calls = run_script(root, BACKUP_HEALTHCHECK_URL="http://hc.invalid/b")
    assert code == 0, out
    assert "=== done" in out
    order = [index_of(calls, p) for p in ("restic cat config", "pg_dump", "pg_restore --list",
                                           "restic backup", "restic forget", "restic check")]
    assert order == sorted(order), calls
    assert "dump-readable" in calls, "restic saw a dump file"

    pings = [c.split()[-1] for c in called(calls, "curl")]
    assert [p.rsplit("/", 1)[-1] for p in pings] == ["start", "b"], "start, then a bare success ping"

    dump = next(c for c in calls if c.startswith("pg_dump"))
    assert "--format=custom" in dump and "--dbname=postgres://pax@/posturi" in dump
    assert not (root / "data" / ".backup-staging" / "posturi.dump").exists(), "the dump is not left behind"
    assert not list((root / "data" / ".backup-staging").glob("posturi.dump*"))


def test_what_is_handed_to_restic(root):
    code, out, calls = run_script(root)
    assert code == 0, out
    backup = next(c for c in calls if c.startswith("restic backup"))
    words = backup.split()
    for wanted in (root / "data" / ".backup-staging" / "posturi.dump", root / "data" / "anunturi",
                   root / "data" / "downloads", root / "data" / "pipeline-runs.jsonl"):
        assert str(wanted) in words, wanted
    assert "--tag nightly" in backup
    assert f"--exclude {root}/data/anunturi/anunturi.csv" in backup, "regenerable, 57 MB and changing daily"
    assert "--exclude *.tmp" in backup and "--exclude .tmp-*" in backup
    assert "--exclude .env" in backup, "secrets are never backed up"
    assert str(root / ".env") not in words and str(root) not in words, "only named paths, never the checkout"


def test_an_absent_optional_file_is_noted_not_fatal(root):
    (root / "data" / "pipeline-runs.jsonl").unlink()
    code, out, calls = run_script(root)
    assert code == 0, out
    assert "NOTE: data/pipeline-runs.jsonl is absent" in out
    assert str(root / "data" / "pipeline-runs.jsonl") not in next(c for c in calls if c.startswith("restic backup"))


def test_retention_is_7_5_12_grouped_by_host_and_tags(root):
    code, out, calls = run_script(root)
    assert "restic forget --tag nightly --group-by host,tags --keep-daily 7 --keep-weekly 5 " \
           "--keep-monthly 12 --prune" in calls


def test_the_check_reads_a_data_subset_and_it_can_be_overridden(root):
    code, out, calls = run_script(root)
    assert re.search(r"^restic check --read-data-subset=(1|10)%$", next(c for c in calls if c.startswith("restic check")))
    code, out, calls = run_script(root, BACKUP_CHECK_SUBSET="25%")
    assert "restic check --read-data-subset=25%" in calls


def test_without_a_healthcheck_url_nothing_is_curled(root):
    code, out, calls = run_script(root)
    assert code == 0 and not called(calls, "curl")


def test_no_secret_reaches_the_log(root):
    code, out, calls = run_script(root, BACKUP_HEALTHCHECK_URL="http://hc.invalid/b")
    assert code == 0
    for secret in (GOOD["RESTIC_PASSWORD"], GOOD["AWS_SECRET_ACCESS_KEY"], "do-not-upload"):
        assert secret not in out


def test_restic_passthrough_runs_any_restic_command_with_the_configuration(root):
    code, out, calls = run_script(root, args=["--restic", "snapshots", "--tag", "nightly"])
    assert code == 0
    assert calls == ["restic snapshots --tag nightly"]
    code, out, calls = run_script(root, args=["--restic", "init"], config={})
    assert code == 78 and "RESTIC_REPOSITORY is not set" in out and calls == []


# ----------------------------------------------------------------------- failures

def test_a_failed_upload_is_reported_and_never_prunes(root):
    code, out, calls = run_script(root, STUB_RESTIC_BACKUP=1, BACKUP_HEALTHCHECK_URL="http://hc.invalid/b")
    assert code == 1
    assert "FAILED (exit 1) during: restic backup" in out
    assert called(calls, "curl")[-1].endswith("/b/fail")
    push = called(calls, "python-stub")
    assert len(push) == 1 and "ops/ntfy.py send" in push[0] and "restic backup" in push[0]
    assert not called(calls, "restic forget") and not called(calls, "restic check")
    assert not list((root / "data" / ".backup-staging").glob("posturi.dump*")), "dump removed on failure too"


def test_an_incomplete_snapshot_still_prunes_and_checks_but_is_reported(root):
    code, out, calls = run_script(root, STUB_RESTIC_BACKUP=3, BACKUP_HEALTHCHECK_URL="http://hc.invalid/b")
    assert code == 3
    assert called(calls, "restic forget") and called(calls, "restic check")
    assert called(calls, "curl")[-1].endswith("/b/fail")
    assert "incomplete snapshot" in out


def test_a_failed_check_after_a_good_backup_is_a_failure(root):
    code, out, calls = run_script(root, STUB_RESTIC_CHECK=1)
    assert code == 1 and "during: restic check" in out
    assert called(calls, "python-stub")


def test_an_unopenable_repository_stops_before_the_dump(root):
    code, out, calls = run_script(root, STUB_RESTIC_CAT=1)
    assert code == 1
    assert "cannot open the restic repository" in out and "ops/backup.sh --restic init" in out
    assert not called(calls, "pg_dump")


def test_a_failed_pg_dump_uploads_nothing(root):
    code, out, calls = run_script(root, STUB_PGDUMP=1)
    assert code == 1 and "during: pg_dump" in out
    assert not called(calls, "restic backup")
    assert not list((root / "data" / ".backup-staging").glob("posturi.dump*"))


def test_a_tiny_dump_is_refused_as_the_wrong_database(root):
    code, out, calls = run_script(root, BACKUP_MIN_DUMP_BYTES=1_000_000)
    assert code == 1 and "wrong database" in out
    assert not called(calls, "restic backup")


def test_an_unreadable_dump_is_refused(root):
    code, out, calls = run_script(root, STUB_PGRESTORE=1)
    assert code == 1 and "during: checking the dump" in out
    assert not called(calls, "restic backup")


def test_a_notifier_that_crashes_does_not_change_the_exit_status(root):
    (root.parent / "bin" / "python-stub").write_text("#!/usr/bin/env bash\nexit 7\n")
    code, out, calls = run_script(root, STUB_RESTIC_BACKUP=1)
    assert code == 1


# --------------------------------------------------------------------- restore test

def test_restore_test_passes_on_a_good_snapshot(root):
    code, out, calls = run_script(root, "restore-test.sh")
    assert code == 0, out
    assert "VERDICT: PASS" in out
    assert "dump       OK" in out and "jobs_jobposting data present" in out
    assert "anunturi   OK" in out and "restored 10 files, live 10" in out
    assert "downloads  OK" in out and "restored 5 files, live 5" in out
    assert "restic restore latest --tag nightly --target" in " ".join(called(calls, "restic restore"))
    assert called(calls, "pg_restore --list")
    assert not [c for c in calls if c.startswith("pg_restore") and "--list" not in c], \
        "the dump is listed, never restored into a database"


def test_restore_test_counts_by_the_rule_the_backup_excludes_by(root):
    # anunturi.csv and *.tmp are not backed up, so they must not count against the restore.
    (root / "data" / "anunturi" / "2026" / "half.html.tmp").write_text("x")
    code, out, _ = run_script(root, "restore-test.sh")
    assert code == 0 and "restored 10 files, live 10" in out


def test_restore_test_fails_when_the_archive_is_short(root):
    code, out, _ = run_script(root, "restore-test.sh", STUB_RESTORE_HTML=5)
    assert code == 1
    assert "anunturi   FAIL" in out and "more than 2% missing" in out
    assert "VERDICT: FAIL" in out


def test_restore_test_tolerance_is_configurable_and_a_newer_live_archive_is_fine(root):
    code, out, _ = run_script(root, "restore-test.sh", STUB_RESTORE_HTML=9)
    assert code == 1, "10% missing is over the default 2%"
    code, out, _ = run_script(root, "restore-test.sh", STUB_RESTORE_HTML=9, RESTORE_TOLERANCE_PCT=10)
    assert code == 0 and "1 newer than the snapshot" in out


def test_restore_test_fails_without_a_dump(root):
    code, out, _ = run_script(root, "restore-test.sh", STUB_RESTORE_NODUMP=1)
    assert code == 1 and "dump       FAIL" in out and "VERDICT: FAIL" in out


def test_restore_test_fails_when_the_dump_has_no_jobs_table(root):
    code, out, _ = run_script(root, "restore-test.sh", STUB_TOC_NO_JOBS=1)
    assert code == 1 and "jobs_jobposting has no data entry" in out


def test_restore_test_fails_when_the_dump_is_unreadable(root):
    code, out, _ = run_script(root, "restore-test.sh", STUB_PGRESTORE=1)
    assert code == 1 and "cannot read it" in out


def test_restore_test_fails_when_restic_cannot_restore(root):
    code, out, _ = run_script(root, "restore-test.sh", STUB_RESTORE_EXIT=1)
    assert code == 1 and "restic could not restore" in out and "VERDICT: FAIL" in out


def test_restore_test_refuses_without_configuration(root):
    code, out, calls = run_script(root, "restore-test.sh", config={})
    assert code == 78 and "RESTIC_REPOSITORY is not set" in out
    assert not called(calls, "restic restore")


def test_restore_test_removes_its_scratch_directory_unless_asked(root):
    scratch = lambda: list(root.parent.glob("posturi-restore.*"))  # noqa: E731 (TMPDIR is root.parent)
    run_script(root, "restore-test.sh")
    assert scratch() == []
    code, out, _ = run_script(root, "restore-test.sh", args=["--keep"])
    assert code == 0 and "kept: " in out and len(scratch()) == 1


def test_restore_test_can_name_a_snapshot(root):
    code, out, calls = run_script(root, "restore-test.sh", args=["--snapshot", "3f2a91c0"])
    assert code == 0, out
    assert any(c.startswith("restic restore 3f2a91c0 --target") for c in calls)


# ------------------------------------------------------------------ docs: the crontab

def _backup_cron_line():
    doc = (REPO_ROOT / "docs" / "deploy-vps.md").read_text()
    lines = [ln for ln in doc.splitlines() if "ops/backup.sh" in ln and ln.rstrip().endswith("2>&1")]
    assert len(lines) == 1, "exactly one backup crontab line in docs/deploy-vps.md"
    return lines[0]


def test_the_crontab_line_fires_once_a_night_clear_of_the_pipeline_slots():
    line = _backup_cron_line()
    m = re.match(r'^(\d+) ([\d,]+) \* \* \* \[ "\$\(TZ=Europe/Bucharest date \+\\%H\)" = "(\d\d)" \] && ', line)
    assert m, line
    minute, utc_hours, local_hour = int(m[1]), [int(h) for h in m[2].split(",")], m[3]
    bucharest = ZoneInfo("Europe/Bucharest")
    day = datetime(2026, 1, 1, tzinfo=timezone.utc)
    while day.year < 2028:                       # every night, both DST changes of two years
        fires = [d for d in (day.replace(hour=h, minute=minute) for h in utc_hours)
                 if f"{d.astimezone(bucharest):%H}" == local_hour]
        assert len(fires) == 1, f"{day:%Y-%m-%d}: fires {len(fires)} times"
        day += timedelta(days=1)
    local = int(local_hour) * 60 + minute
    for slot in (13 * 60 + 15, 18 * 60 + 33):
        assert abs(local - slot) >= 6 * 60, "well clear of the pipeline slots"


def test_the_pipeline_crontab_lines_are_not_confused_with_the_backup_line():
    assert "ops/run-pipeline.sh" not in _backup_cron_line()
