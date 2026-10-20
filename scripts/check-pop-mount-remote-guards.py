#!/usr/bin/env python3
"""Fail if pop-mount workflow remote regresses to world-readable, weak identity, or live-overwrite-before-validate."""
from __future__ import annotations

import pathlib
import re
import sys
import tempfile

PATH = pathlib.Path(".github/workflows/pop-mount-reviewed-financial-once.yml")

FORBIDDEN = (r"chmod\s+644", r"chmod\s+o\+r", r"else\s+chmod\s+64", r"chmod\s+777")
LIVE = "reviewed-financial-cases.json"
STAGE_MARKERS = (
    "reviewed-financial-cases.json.staging",
    "validate_mount_file",
    "cleanup_stage_preserve_live",
)


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

    # A6: never SCP/overwrite LIVE before validation; require same-FS staging + atomic replace.
    scp_live = re.search(
        rf"scp[\s\S]{{0,400}}:/home/admin/backend/storage/app/private/pop/{re.escape(LIVE)}(?:\s|$|\"|')",
        text,
    )
    if scp_live and ".staging" not in scp_live.group(0):
        errors.append("FAIL A6: scp targets live authorization file before validation")

    for marker in STAGE_MARKERS:
        if marker not in text:
            errors.append(f"FAIL A6b: missing staging/atomic marker {marker!r}")

    if "mv -f \"$STAGE\" \"$LIVE\"" not in blob and "mv -f \"$STAGE\" \"$LIVE\"" not in text:
        # Allow equivalent quoting forms
        if not re.search(r'mv\s+-f\s+"\$STAGE"\s+"\$LIVE"', blob):
            errors.append("FAIL A6c: remote lacks atomic STAGE→LIVE rename")

    if "PREV" not in blob or "restored_live_from_prev" not in blob:
        errors.append("FAIL A6d: remote lacks prior-LIVE preserve/restore on failure")
    if "cp -a \"$LIVE\" \"$PREV\"" not in blob and 'cp -a "$LIVE" "$PREV"' not in blob:
        errors.append("FAIL A6f: must backup LIVE with cp -a to PREV while LIVE stays")
    # Ban LIVE→PREV mv as the backup method (would remove LIVE before replace).
    if re.search(r'mv\s+-f\s+"\$LIVE"\s+"\$PREV"', blob):
        errors.append("FAIL A6g: must not mv LIVE→PREV before STAGE→LIVE (use cp backup)")
    for msg in ("validate_mount_file: setfacl failed", "validate_mount_file: fpm cannot read",
                "validate_mount_file: fpm cannot traverse", "setfacl/getfacl missing"):
        if msg not in blob:
            errors.append(f"FAIL A6h: validate_mount_file must explicitly fail-message {msg!r}")
    # Production sudo needs a password (run 39427575892); access is granted by the
    # file owner's named ACL, so the remote must not depend on sudo at all.
    if re.search(r"\bsudo\b", re.sub(r"#.*", "", blob)):
        errors.append("FAIL A2b: remote must not call sudo (owner ACL instead)")
    if not re.search(r'setfacl --set "u::rw-,g::---,o::---,u:\$\{uid\}:r--,m::r--"', blob):
        errors.append("FAIL A2c: file ACL must be replaced (--set) granting only the bound worker uid read")
    if "acl_exact" not in blob:
        errors.append("FAIL A2d: file ACL must be verified exactly (no extra entries or wider mask)")
    if "setfacl --restore" not in blob or "restore_dir_acls" not in blob:
        errors.append("FAIL A6i: directory ACL grants must be restored on failure")

    # scp destination must be $STAGE (or literal …json.staging), never bare LIVE path.
    if not (
        re.search(r"scp[\s\S]{0,500}\$STAGE\b", text)
        or re.search(rf"scp[\s\S]{{0,500}}{re.escape(LIVE)}\.staging", text)
    ):
        errors.append("FAIL A6e: scp must target $STAGE / .staging path, not LIVE")
    if "STAGE=" in text and f"{LIVE}.staging" not in text:
        errors.append("FAIL A6e: STAGE must resolve to reviewed-financial-cases.json.staging")

    return errors


DEPLOY_SOURCES = (
    pathlib.Path(".github/workflows/deploy.yml"),
    pathlib.Path("scripts/production-deploy/pi-remote-deploy.sh"),
)
RECURSIVE_STORAGE_CHMOD = re.compile(r"chmod\s+-R\s+[0-7]*[1-7]\s+[^\n]*\bstorage\b")


def check_deploy_preserves_private(sources=DEPLOY_SOURCES) -> list[str]:
    """A mount is only safe once deploy no longer re-widens storage/app/private
    (a recursive chmod sets the ACL mask and other bits on the live file)."""
    errors: list[str] = []
    seen = False
    for path in sources:
        if not path.is_file():
            continue
        seen = True
        if RECURSIVE_STORAGE_CHMOD.search(path.read_text(encoding="utf-8")):
            errors.append(f"FAIL D1: {path} still runs a recursive chmod over storage; mount would be re-exposed by the next deploy")
    if not seen:
        errors.append("FAIL D1: no deploy source found to verify storage/app/private is preserved")
    return errors


def self_test() -> int:
    good = PATH.read_text(encoding="utf-8")
    errs = check_text(good)
    if errs:
        print("self-test FAIL: live workflow rejected:\n" + "\n".join(errs), file=sys.stderr)
        return 1

    # Bad: SCP directly onto LIVE (pre-validation overwrite).
    bad_scp = """
scp file host:/home/admin/backend/storage/app/private/pop/reviewed-financial-cases.json
ssh host bash -s <<'REMOTE'
set -euo pipefail
f=/home/admin/backend/storage/app/private/pop/reviewed-financial-cases.json
sudo -n chown 33:33 "$f"
sudo -n chmod 640 "$f"
REMOTE
"""
    bad_errs = check_text(bad_scp, label="fixture-scp-live")
    if not any("A6" in e for e in bad_errs):
        print("self-test FAIL: expected A6 on scp-to-live fixture", file=sys.stderr)
        print("\n".join(bad_errs), file=sys.stderr)
        return 1

    bad_sudo = good.replace('elif ! setfacl --set', 'elif ! sudo -n setfacl --set', 1)
    if bad_sudo == good or not any("A2b" in e for e in check_text(bad_sudo, label="fixture-sudo")):
        print("self-test FAIL: expected A2b on sudo fixture", file=sys.stderr)
        return 1

    with tempfile.TemporaryDirectory() as td:
        old_deploy = pathlib.Path(td) / "deploy.yml"
        old_deploy.write_text("chmod -R 775 storage bootstrap/cache 2>/dev/null || true\n", encoding="utf-8")
        new_deploy = pathlib.Path(td) / "deploy-new.yml"
        new_deploy.write_text("bash /home/admin/scripts/deploy-storage-perms.sh\n", encoding="utf-8")
        if not check_deploy_preserves_private((old_deploy,)) or check_deploy_preserves_private((new_deploy,)):
            print("self-test FAIL: D1 deploy recursive-chmod detection", file=sys.stderr)
            return 1

    bad644 = """
ssh host bash -s <<'REMOTE'
chmod 644 /tmp/x
REMOTE
"""
    if not any("A1" in e for e in check_text(bad644, label="fixture-644")):
        print("self-test FAIL: expected A1 on 644 fixture", file=sys.stderr)
        return 1

    with tempfile.TemporaryDirectory() as td:
        root = pathlib.Path(td)
        wf = root / ".github" / "workflows"
        wf.mkdir(parents=True)
        (wf / "pop-mount-reviewed-financial-once.yml").write_text(good, encoding="utf-8")
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
    if argv and argv[0] == "--require-deploy-safe":
        errors = check_deploy_preserves_private()
        if errors:
            print("\n".join(errors), file=sys.stderr)
            return 1
        print("OK deploy preserves storage/app/private")
        return 0

    text = PATH.read_text(encoding="utf-8")
    errors = check_text(text)
    if errors:
        print("\n".join(errors), file=sys.stderr)
        return 1
    print("OK pop-mount remote guards A1–A6")
    return 0


if __name__ == "__main__":
    sys.exit(main())
