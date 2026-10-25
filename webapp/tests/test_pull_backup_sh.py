"""BETA-BACKUP — ops/pull-backup.sh, with ssh and rsync stubbed.

The script runs on the Mac and pulls a dated snapshot from the VPS: a gzipped
plain-SQL dump plus the raw archive. These tests run the real script with `ssh`
and `rsync` replaced by stubs on PATH, in a scratch backup directory.
"""

from __future__ import annotations

import os
import subprocess
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
SCRIPT = REPO_ROOT / "ops" / "pull-backup.sh"

STUB_SSH = r"""#!/usr/bin/env bash
# ssh [-o X]... HOST COMMAND...
while [ "$1" = "-o" ]; do shift 2; done
shift                                   # the host
echo "ssh $*" >> "$STUB_LOG"
case "$1" in
  test*) exit "${STUB_REACH:-0}" ;;
  bash)  cat > /dev/null
         if [ -n "${STUB_NO_POSTINGS:-}" ]; then
           printf 'COPY public.jobs_employer (id) FROM stdin;\n1\n\\.\n' | gzip
         else
           printf 'COPY public.jobs_jobposting (id) FROM stdin;\n1\n\\.\n' | gzip
         fi ;;
  *)     echo "${STUB_REMOTE_FILES:-2}" ;;
esac
"""

STUB_RSYNC = r"""#!/usr/bin/env bash
echo "rsync $*" >> "$STUB_LOG"
dest="${@: -1}"
mkdir -p "$dest/anunturi" "$dest/downloads"
echo page > "$dest/anunturi/a.html"
echo pdf > "$dest/downloads/b.pdf"
"""


@pytest.fixture
def env(tmp_path):
    bin_dir = tmp_path / "bin"
    bin_dir.mkdir()
    for name, body in (("ssh", STUB_SSH), ("rsync", STUB_RSYNC)):
        stub = bin_dir / name
        stub.write_text(body)
        stub.chmod(0o755)
    backups = tmp_path / "backups"
    return {
        "PATH": f"{bin_dir}:{os.environ['PATH']}",
        "HOME": str(tmp_path),
        "STUB_LOG": str(tmp_path / "stub.log"),
        "POSTURI_VPS": "stub-host",
        "POSTURI_VPS_DIR": "/srv/posturi",
        "POSTURI_BACKUP_DIR": str(backups),
        "POSTURI_BACKUP_KEEP": "3",
        "POSTURI_BACKUP_MIN_DUMP_BYTES": "10",
    }


def run(env, **extra):
    return subprocess.run(["bash", str(SCRIPT)], env={**env, **extra},
                          capture_output=True, text=True, cwd=REPO_ROOT)


def snapshots(env):
    root = Path(env["POSTURI_BACKUP_DIR"])
    return sorted(p.name for p in root.iterdir() if p.is_dir() and not p.is_symlink()) if root.exists() else []


def test_a_run_publishes_a_snapshot_and_points_latest_at_it(env):
    result = run(env)
    assert result.returncode == 0, result.stderr
    [name] = snapshots(env)
    snap = Path(env["POSTURI_BACKUP_DIR"]) / name
    assert (snap / "posturi.sql.gz").stat().st_size > 0
    assert (snap / "data" / "anunturi" / "a.html").exists()
    assert os.readlink(Path(env["POSTURI_BACKUP_DIR"]) / "latest") == name


def test_an_unreachable_vps_creates_nothing(env):
    result = run(env, STUB_REACH="255")
    assert result.returncode == 69
    assert "cannot reach stub-host" in result.stderr
    assert snapshots(env) == []


def test_a_dump_without_postings_is_refused_and_latest_stays(env):
    root = Path(env["POSTURI_BACKUP_DIR"])
    (root / "2020-01-01T0000Z" / "data").mkdir(parents=True)
    (root / "latest").symlink_to("2020-01-01T0000Z")
    result = run(env, STUB_NO_POSTINGS="1")
    assert result.returncode == 1
    assert "no data for jobs_jobposting" in result.stderr
    assert snapshots(env) == ["2020-01-01T0000Z"]
    assert os.readlink(root / "latest") == "2020-01-01T0000Z"


def test_fewer_files_than_the_vps_had_fails(env):
    result = run(env, STUB_REMOTE_FILES="5")
    assert result.returncode == 1
    assert "fewer files arrived" in result.stderr
    assert snapshots(env) == []


def test_unchanged_files_link_to_the_previous_snapshot(env):
    root = Path(env["POSTURI_BACKUP_DIR"])
    (root / "2020-01-01T0000Z" / "data").mkdir(parents=True)
    (root / "latest").symlink_to("2020-01-01T0000Z")
    assert run(env).returncode == 0
    log = Path(env["STUB_LOG"]).read_text()
    assert f"--link-dest={root}/latest/data" in log


def test_old_snapshots_are_pruned_to_keep(env):
    root = Path(env["POSTURI_BACKUP_DIR"])
    for day in range(1, 6):
        (root / f"2020-01-0{day}T0000Z").mkdir(parents=True)
    (root / "notes").mkdir()                      # not a snapshot name: never pruned
    assert run(env).returncode == 0
    kept = [n for n in snapshots(env) if n != "notes"]
    assert len(kept) == 3
    assert kept[:2] == ["2020-01-04T0000Z", "2020-01-05T0000Z"]
    assert (root / "notes").exists()


def test_an_unfinished_attempt_is_cleared(env):
    root = Path(env["POSTURI_BACKUP_DIR"])
    (root / "2020-01-01T0000Z.partial").mkdir(parents=True)
    assert run(env).returncode == 0
    assert not (root / "2020-01-01T0000Z.partial").exists()


@pytest.mark.parametrize("keep", ["0", "x"])
def test_a_bad_keep_is_a_settings_error(env, keep):
    assert run(env, POSTURI_BACKUP_KEEP=keep).returncode == 78
