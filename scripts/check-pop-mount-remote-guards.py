#!/usr/bin/env python3
"""Fail if pop-mount workflow remote regresses to world-readable or weak identity."""
from __future__ import annotations

import pathlib
import re
import sys
import tempfile

PATH = pathlib.Path(".github/workflows/pop-mount-reviewed-financial-once.yml")

FORBIDDEN = (r"chmod\s+644", r"chmod\s+o\+r", r"else\s+chmod\s+64", r"chmod\s+777")


def remote_blob(text: str) -> str:
    m = re.search(r"bash -s\"?\s*<<'REMOTE'\n(.*?\n)\s*REMOTE\n", text, re.S)
    if m:
        return m.group(1)
    m = re.search(r'bash -s\"?\s*<<"REMOTE"\n(.*?\n)\s*REMOTE\n', text, re.S)
    if m:
        return m.group(1)
    return text


def check_text(text: str, *, label: str = str(PATH)) -> list[str]:
    errors: list[str] = []
    for pat in FORBIDDEN:
        if re.search(pat, text):
            errors.append(f"FAIL A1: forbidden pattern /{pat}/ in {label}")

    blob = remote_blob(text)

    if re.search(r"sudo\s+-n\s+chown", blob) and not re.search(r"sudo\s+-n\s+chmod", blob):
        errors.append("FAIL A2: sudo -n chown without sudo -n chmod in remote")
    if "/proc/" not in blob or "Uid:" not in blob:
        errors.append("FAIL A3: remote lacks /proc worker Uid binding")
    if not re.search(r"world-accessible|mode % 10|refusing", blob, re.I):
        errors.append("FAIL A4: no fail-closed other-read guard in remote")
    if "www-data:www-data" in blob and "pool_user" not in blob:
        errors.append("FAIL A3b: name-guess chown without pool_user binding")

    if "/home/admin/backend" not in blob or "daan.lifenet.com.tw" not in blob:
        errors.append("FAIL A5: remote lacks app docroot/ServerName binding hints")
    if "listen_target" not in blob or "pool_name" not in blob:
        errors.append("FAIL A5b: remote lacks listen_target/pool_name app-serving resolution")
    if "proxy:unix" not in blob and "fastcgi_pass" not in blob:
        errors.append("FAIL A5c: remote lacks vhost upstream extraction (proxy:unix|fastcgi_pass)")

    # Ban first-pool break: first non-root user= then break, without listen match in that loop.
    first_pool = re.search(
        r"for conf in /etc/php/\*/fpm/pool\.d/\*\.conf.*?done",
        blob,
        re.S,
    )
    if first_pool:
        loop = first_pool.group(0)
        if "plisten" not in loop and "listen_target" not in loop:
            if re.search(r'pool_user="\$u"', loop) and "break" in loop:
                errors.append("FAIL A5d: first non-root pool user= break without listen match")

    return errors


def self_test() -> int:
    good = PATH.read_text(encoding="utf-8")
    errs = check_text(good)
    if errs:
        print("self-test FAIL: live workflow rejected:\n" + "\n".join(errs), file=sys.stderr)
        return 1

    # Minimal bad fixture: first-pool + chmod 644 must fail A1 and A5d.
    bad = """
ssh host bash -s <<'REMOTE'
set -euo pipefail
f=/tmp/x
pool_user=""
for conf in /etc/php/*/fpm/pool.d/*.conf; do
  u=www-data
  pool_user="$u"
  break
done
sudo -n chown 33:33 "$f"
sudo -n chmod 644 "$f"
REMOTE
"""
    bad_errs = check_text(bad, label="fixture-first-pool-644")
    if not any("A1" in e for e in bad_errs):
        print("self-test FAIL: expected A1 on 644 fixture", file=sys.stderr)
        return 1
    if not any("A5" in e for e in bad_errs):
        print("self-test FAIL: expected A5* on first-pool fixture", file=sys.stderr)
        print("\n".join(bad_errs), file=sys.stderr)
        return 1

    # Temp-dir smoke: write good file under alternate cwd.
    with tempfile.TemporaryDirectory() as td:
        root = pathlib.Path(td)
        wf = root / ".github" / "workflows"
        wf.mkdir(parents=True)
        (wf / "pop-mount-reviewed-financial-once.yml").write_text(good, encoding="utf-8")
        # Re-run via PATH relative to td
        import os

        old = os.getcwd()
        try:
            os.chdir(td)
            live_errs = check_text(PATH.read_text(encoding="utf-8"))
        finally:
            os.chdir(old)
        if live_errs:
            print("self-test FAIL temp copy:\n" + "\n".join(live_errs), file=sys.stderr)
            return 1

    print("OK pop-mount remote guards self-test")
    return 0


def main(argv: list[str] | None = None) -> int:
    argv = list(sys.argv[1:] if argv is None else argv)
    if argv and argv[0] in ("--self-test", "self-test"):
        return self_test()

    text = PATH.read_text(encoding="utf-8")
    errors = check_text(text)
    if errors:
        print("\n".join(errors), file=sys.stderr)
        return 1
    print("OK pop-mount remote guards A1–A5")
    return 0


if __name__ == "__main__":
    sys.exit(main())
