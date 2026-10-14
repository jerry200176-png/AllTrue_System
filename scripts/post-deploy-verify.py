#!/usr/bin/env python3
"""Post-deploy verification and rollback decision for the production executor.

Read-only. Used by `.github/workflows/deploy.yml`:

* `verify`    — bounded retries until every health path answers 200 (and a JSON
                `status`/`ok` field, when present, says healthy) and the public
                `deployment.json` reports the expected backend SHA (and the
                expected frontend SHA when the frontend was rebuilt).
* `gate`      — the executor's final stage on the Pi: `verify`, then the
                existing post-merge smoke. On failure it decides whether the
                automatic rollback may run: any migration in the deployed range
                (or a migration that ran) means hold (exit 12): fail and alert,
                never roll code back under a newer schema and never run
                `migrate:rollback`. Only exit 20 tells the executor to run its
                reviewed `rollback_deploy`; any other non-zero exit (a crash,
                a traceback = 1, argparse = 2, a kill) is treated as a hold.
                Exception (Founder 2026-10-14): when every migration in the
                range is a new additive-only file (`range-additive` = 1),
                code rollback is safe under the newer schema and proceeds.
* `range-additive PREV TARGET` — prints 1 when every migration in the range is a new,
                additive-only file per the previous commit's classifier, else 0.
* `summarize` — the step summary for a failed production executor exit code.

Stdlib only: it runs on the Pi and on a bare GitHub-hosted runner.
"""

from __future__ import annotations

import argparse
import json
import os
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.request

DEFAULT_BASE_URL = "https://daan.lifenet.com.tw"
DEFAULT_HEALTH_PATHS = ("/api/health", "/api/v1/health")
DEFAULT_SMOKE = "/home/admin/scripts/post-merge-smoke.sh"

# Exit codes of the production executor (the Deploy step's remote script).
RC_ROLLED_BACK = 10
RC_ROLLBACK_FAILED = 11
RC_HELD_MIGRATION = 12
RC_HELD_PRE_VERIFY = 13  # a step failed after a migration ran (or with one in range), before identity was verified
RC_GATE_ROLLBACK = 20  # gate -> executor: run rollback_deploy (distinct from a Python crash's 1)
RC_SSH_LOST = 255


def _fetch(url: str, timeout: float, insecure: bool) -> tuple[int, bytes]:
    context = ssl._create_unverified_context() if insecure else None  # noqa: S323 - mirrors the Pi's existing `curl -k`
    request = urllib.request.Request(url, headers={"Cache-Control": "no-cache", "Connection": "close"})
    try:
        with urllib.request.urlopen(request, timeout=timeout, context=context) as response:  # noqa: S310 - fixed https base URL
            return response.status, response.read(65536)
    except urllib.error.HTTPError as error:
        return error.code, b""


def _health_problem(path: str, status: int, body: bytes) -> str | None:
    """Healthy = HTTP 200 and a JSON object saying so (an HTML page or PHP source never passes)."""
    if status != 200:
        return f"{path} returned HTTP {status}"
    try:
        payload = json.loads(body.decode("utf-8"))
    except (UnicodeDecodeError, ValueError):
        return f"{path} returned a non-JSON body"
    if not isinstance(payload, dict):
        return f"{path} returned non-object JSON"
    if "status" in payload:
        return None if payload["status"] == "ok" else f"{path} status={payload['status']!r}"
    if "ok" in payload:
        return None if payload["ok"] is True else f"{path} ok={payload['ok']!r}"
    return f"{path} has no status/ok field"


def check_once(base_url, health_paths, expect_backend, expect_frontend, timeout, insecure, fetch=_fetch):
    """Return a list of problems; empty means the deployment is verified."""
    problems = []
    base = base_url.rstrip("/")
    for path in health_paths:
        try:
            status, body = fetch(base + path, timeout, insecure)
        except Exception as error:  # noqa: BLE001 - any transport fault (incl. http.client.HTTPException) is retryable
            problems.append(f"{path} unreachable: {error.__class__.__name__}")
            continue
        problem = _health_problem(path, status, body)
        if problem:
            problems.append(problem)
    try:
        status, body = fetch(f"{base}/deployment.json?ts={int(time.time())}", timeout, insecure)
        manifest = json.loads(body.decode("utf-8")) if status == 200 else None
    except Exception as error:  # noqa: BLE001 - retryable, never a crash that the executor could misread
        problems.append(f"deployment.json unreachable or invalid: {error.__class__.__name__}")
        return problems
    if not isinstance(manifest, dict):
        problems.append(f"deployment.json missing or invalid (HTTP {status})")
        return problems
    if manifest.get("backend_sha") != expect_backend:
        problems.append(f"deployment.json backend_sha={manifest.get('backend_sha')} expected {expect_backend}")
    if expect_frontend and manifest.get("frontend_sha") != expect_frontend:
        problems.append(f"deployment.json frontend_sha={manifest.get('frontend_sha')} expected {expect_frontend}")
    return problems


def verify(args, fetch=_fetch, sleep=time.sleep, clock=time.monotonic) -> int:
    problems = ["not attempted"]
    deadline = clock() + getattr(args, "deadline", 150.0)
    for attempt in range(1, args.attempts + 1):
        problems = check_once(
            args.base_url, args.health_path or DEFAULT_HEALTH_PATHS, args.expect_backend_sha,
            args.expect_frontend_sha, args.timeout, args.insecure, fetch,
        )
        if not problems:
            print(f"post-deploy verify: OK on attempt {attempt}/{args.attempts} (backend_sha={args.expect_backend_sha})")
            return 0
        print(f"post-deploy verify: attempt {attempt}/{args.attempts} failed: {'; '.join(problems)}")
        if attempt < args.attempts:
            if clock() + args.interval >= deadline:
                print(f"post-deploy verify: deadline reached after attempt {attempt}")
                break
            sleep(args.interval)
    print(f"post-deploy verify: FAILED after {args.attempts} attempts: {'; '.join(problems)}")
    return 1


def decide(migration_files: str, migration_ran: str, migrations_additive: str = "0") -> str:
    """`rollback` when the range provably has no migration, or only additive ones; anything else holds."""
    if str(migrations_additive).strip() == "1":
        return "rollback"
    values = (str(migration_files).strip(), str(migration_ran).strip())
    return "rollback" if all(value == "0" for value in values) else "hold"


MIGRATIONS_PATHSPEC = ":(top)backend/database/migrations"
CLASSIFIER_PATH = "scripts/governance/autonomy_gate.py"


def range_additive(previous_sha: str, target_sha: str, run=subprocess.run) -> str:
    """`1` only when every migration in the range is a new, additive-only file; otherwise `0`.

    Founder 2026-10-14 (三): code rolled back over additive-only schema (new
    tables, nullable/defaulted columns, plain indexes) still runs, so the
    rollback may proceed. The classifier is the one in the *previous* (already
    deployed) commit, never the target's own: a range must not judge itself.
    A previous commit without the classifier, a git error or any doubt is `0`.
    """
    import importlib.util
    import tempfile

    def git(*args: str) -> str | None:
        try:
            result = run(["git", *args], capture_output=True, text=True, timeout=60)
        except (OSError, subprocess.SubprocessError):
            return None
        return result.stdout if result.returncode == 0 else None

    names = git("diff", "--no-renames", "--name-only", previous_sha, target_sha, "--", MIGRATIONS_PATHSPEC)
    patch = git("diff", "--no-renames", "--no-color", "--no-ext-diff", "--no-textconv",
                previous_sha, target_sha, "--", MIGRATIONS_PATHSPEC)
    source = git("show", f"{previous_sha}:{CLASSIFIER_PATH}")
    if names is None or patch is None or source is None:
        return "0"
    paths = {line.strip() for line in names.splitlines() if line.strip()}
    if not paths:
        return "0"
    try:
        with tempfile.TemporaryDirectory(prefix="trusted-classifier-") as directory:
            module_path = os.path.join(directory, "autonomy_gate.py")
            with open(module_path, "w", encoding="utf-8") as handle:
                handle.write(source)
            spec = importlib.util.spec_from_file_location("trusted_autonomy_gate", module_path)
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            classify = getattr(module, "additive_migration_paths", None)
            additive = classify(sorted(paths), patch) if callable(classify) else set()
    except BaseException:  # noqa: BLE001 - any classifier failure (even SystemExit) means hold
        return "0"
    return "1" if set(additive) == paths else "0"


def range_migrations(previous_sha: str, target_sha: str, run=subprocess.run) -> str:
    """Count migration files changed in the deployed range; `unknown` (so: hold) when git cannot tell."""
    try:
        result = run(
            ["git", "diff", "--name-only", previous_sha, target_sha, "--", ":(top)backend/database/migrations"],
            capture_output=True, text=True, timeout=60,
        )
    except (OSError, subprocess.SubprocessError):
        return "unknown"
    if result.returncode != 0:
        return "unknown"
    return str(len([line for line in result.stdout.splitlines() if line.strip()]))


def gate(args, verify_fn=None, run=subprocess.run, migrations_fn=range_migrations, additive_fn=None) -> int:
    """Executor's final stage: 0 verified, RC_HELD_MIGRATION hold, 1 roll back (caller runs rollback_deploy)."""
    frontend_changed = str(args.frontend_changed).strip() not in ("", "0")
    verify_args = argparse.Namespace(
        base_url=args.base_url, health_path=None, expect_backend_sha=args.target_sha,
        expect_frontend_sha=args.target_sha if frontend_changed else None,
        attempts=args.attempts, interval=args.interval, timeout=args.timeout, insecure=args.insecure,
        deadline=args.deadline,
    )
    ok = (verify_fn or verify)(verify_args) == 0
    if ok:
        env = dict(os.environ)
        if frontend_changed:
            env["SMOKE_STRICT_VERSION"] = "1"
        ok = run(["bash", args.smoke], env=env).returncode == 0
        if not ok:
            print("post-deploy smoke: FAILED")
    if ok:
        print("🚀 部署完成 — Health + identity + Smoke test 全通過")
        return 0
    decision = decide(migrations_fn(args.previous_sha, args.target_sha), args.migration_ran)
    if decision == "hold":
        decision = decide("1", "1", (additive_fn or range_additive)(args.previous_sha, args.target_sha))
    if decision == "rollback":
        return RC_GATE_ROLLBACK
    print(
        "::error::Post-deploy verification failed and the deployed range contains a migration "
        f"(migration ran={args.migration_ran}). No automatic rollback and no migrate:rollback: "
        f"production stays on {args.target_sha}. Manual intervention required."
    )
    return RC_HELD_MIGRATION


def summarize(rc: int, target_sha: str, previous_sha: str, runner_verify: str) -> str:
    lines = ["## state=production-verification-failed", ""]
    if rc == 0 and runner_verify != "success":
        lines.append(
            f"The executor verified `{target_sha}` from the Pi, but the independent runner check failed. "
            "No automatic rollback was attempted from the runner; check `/api/health` and `deployment.json` now."
        )
    elif rc == RC_ROLLED_BACK:
        lines.append(
            "Automatic rollback ran: production was returned to the commit that was live when the executor started "
            f"(preflight detected `{previous_sha}`; the Deploy log's `回滾到` line names the exact commit) "
            f"after `{target_sha}` failed, and the rollback was re-verified. The target is NOT live."
        )
    elif rc == RC_ROLLBACK_FAILED:
        lines.append(
            f"`{target_sha}` failed and the automatic rollback to `{previous_sha}` did not verify. "
            "Manual intervention is required now."
        )
    elif rc == RC_HELD_MIGRATION:
        lines.append(
            f"`{target_sha}` failed after activation and either a non-additive migration is in range/ran or the verifier could "
            "not decide, so no automatic rollback ran and `migrate:rollback` was never invoked. Production remains "
            "on the target. Manual intervention is required now (see docs/RUNBOOK_ROLLBACK.md)."
        )
    elif rc == RC_HELD_PRE_VERIFY:
        lines.append(
            f"A deploy step for `{target_sha}` failed after a non-additive migration ran (or was in the range), before the release identity was "
            "verified. No automatic rollback ran and `migrate:rollback` was never invoked. Runtime state is UNKNOWN "
            "(code, frontend and deployment.json may be partly updated): check `/api/health` and `deployment.json` "
            "now and follow docs/RUNBOOK_ROLLBACK.md §3."
        )
    elif rc == RC_SSH_LOST:
        lines.append("The SSH session to the production executor was lost. Production state is unknown; check it now.")
    else:
        lines.append(
            f"The production executor exited with code `{rc}`: refused before activation, or failed after activation "
            "without a classified outcome. Check `/api/health` and `deployment.json` now."
        )
    return "\n".join(lines) + "\n"


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n", 1)[0])
    sub = parser.add_subparsers(dest="command", required=True)

    def common(p):
        p.add_argument("--base-url", default=DEFAULT_BASE_URL)
        p.add_argument("--attempts", type=int, default=12)
        p.add_argument("--interval", type=float, default=5.0)
        p.add_argument("--timeout", type=float, default=5.0)
        p.add_argument("--deadline", type=float, default=150.0, help="total seconds across all attempts")
        p.add_argument("-k", "--insecure", action="store_true")

    v = sub.add_parser("verify")
    common(v)
    v.add_argument("--health-path", action="append")
    v.add_argument("--expect-backend-sha", required=True)
    v.add_argument("--expect-frontend-sha")

    g = sub.add_parser("gate")
    common(g)
    g.add_argument("--target-sha", required=True)
    g.add_argument("--previous-sha", required=True)
    g.add_argument("--frontend-changed", default="0")
    g.add_argument("--migration-ran", required=True)
    g.add_argument("--smoke", default=DEFAULT_SMOKE)

    r = sub.add_parser("range-additive")
    r.add_argument("previous_sha")
    r.add_argument("target_sha")

    s = sub.add_parser("summarize")
    s.add_argument("--rc", type=int, required=True)
    s.add_argument("--target-sha", required=True)
    s.add_argument("--previous-sha", default="unknown")
    s.add_argument("--runner-verify", default="skipped")

    args = parser.parse_args(argv)
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(line_buffering=True)  # keep order with the smoke subprocess output
    if args.command in ("verify", "gate") and args.attempts < 1:
        parser.error("--attempts must be >= 1")
    if args.command == "verify":
        return verify(args)
    if args.command == "gate":
        return gate(args)
    if args.command == "range-additive":
        print(range_additive(args.previous_sha, args.target_sha))
        return 0
    sys.stdout.write(summarize(args.rc, args.target_sha, args.previous_sha, args.runner_verify))
    return 0


if __name__ == "__main__":
    sys.exit(main())
