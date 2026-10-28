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
                With `--watch-seconds N` (gap #3), after verify + smoke pass
                the gate keeps watching for N seconds: health + identity every
                `--watch-interval`, and the Sentry error count when
                SENTRY_WATCH is set. A breach
                (`--watch-failures` consecutive failed checks, or an error-count
                spike against the pre-deploy baseline) takes the same
                rollback-or-hold decision as a failed verify.
* `range-additive PREV TARGET` — prints 1 when every migration in the range is a new,
                additive-only file per the previous commit's classifier, else 0.
* `summarize` — the step summary for a failed production executor exit code.

Stdlib only: it runs on the Pi and on a bare GitHub-hosted runner.
"""

from __future__ import annotations

import argparse
import collections
import json
import os
import re
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.parse
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
USER_AGENT = "AllTrue-post-deploy-verify/1"


def _fetch(url: str, timeout: float, insecure: bool) -> tuple[int, bytes]:
    context = ssl._create_unverified_context() if insecure else None  # noqa: S323 - mirrors the Pi's existing `curl -k`
    # Production's WAF answers 403 to urllib's default "Python-urllib/x.y" User-Agent
    # (2026-10-14 release train: every check 403 while curl got 200), so name ourselves.
    request = urllib.request.Request(url, headers={"Cache-Control": "no-cache", "Connection": "close",
                                                  "User-Agent": USER_AGENT})
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
    deployed) commit, never the target's own. (This verifier script itself comes
    from the target, the same trust the existing gate/decide already has.)
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


_PENDING_RE = re.compile(r"\b(\d{4}_\d{2}_\d{2}_\d{6}_\w+)\b")


def pending_in_range(status: str, previous_sha: str, target_sha: str, run=subprocess.run) -> str:
    """`1` only when every pending migration in `migrate:status` output is a file this range adds.

    A pending migration that already existed at the previous commit (left over
    from an earlier deploy) ran outside the reviewed range, so the additive
    rollback must not apply. Any doubt is `0`.
    """
    rows = [line for line in status.splitlines() if re.search(r"\| No|Pending", line)]
    names = [match.group(1) for line in rows if (match := _PENDING_RE.search(line))]
    if not rows or len(names) != len(rows):
        return "0"
    try:
        result = run(["git", "diff", "--no-renames", "--name-only", "--diff-filter=A", previous_sha, target_sha,
                      "--", MIGRATIONS_PATHSPEC], capture_output=True, text=True, timeout=60)
    except (OSError, subprocess.SubprocessError):
        return "0"
    if result.returncode != 0:
        return "0"
    added = {line.strip().rsplit("/", 1)[-1] for line in result.stdout.splitlines() if line.strip()}
    return "1" if all(f"{name}.php" in added for name in names) else "0"


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


def gate(args, verify_fn=None, run=subprocess.run, migrations_fn=range_migrations, additive_fn=None,
         watch_fn=None) -> int:
    """Executor's final stage: 0 verified, RC_HELD_MIGRATION hold, 20 roll back (caller runs rollback_deploy)."""
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
        env.pop(SENTRY_TOKEN_ENV, None)  # the smoke script never needs the Sentry read token
        if frontend_changed:
            env["SMOKE_STRICT_VERSION"] = "1"
        ok = run(["bash", args.smoke], env=env).returncode == 0
        if not ok:
            print("post-deploy smoke: FAILED")
    if ok and getattr(args, "watch_seconds", 0) > 0:
        watch_args = argparse.Namespace(**vars(verify_args))
        watch_args.watch_seconds = args.watch_seconds
        watch_args.watch_interval = args.watch_interval
        watch_args.watch_failures = args.watch_failures
        watch_args.sentry_every = args.sentry_every
        watch_args.spike = SpikePolicy(args.sentry_factor, args.sentry_floor_per_min, args.sentry_min_count)
        watch_args.sentry_baseline_minutes = args.sentry_baseline_minutes
        watch_args.sentry_baseline_gap_minutes = args.sentry_baseline_gap_minutes
        breach = (watch_fn or watch)(watch_args, sentry=sentry_config_from_env(os.environ))
        if breach:
            print(f"::error::post-deploy watch breach: {breach}")
            if breach.startswith("Sentry "):
                # Event fields are client-supplied: a Sentry-only breach holds and alerts, never rolls back.
                print("::error::Sentry-only breach: held for review; no automatic rollback.")
                return RC_HELD_MIGRATION
            ok = False
    if ok:
        print("🚀 部署完成 — Health + identity + Smoke test 全通過")
        return 0
    decision = decide(migrations_fn(args.previous_sha, args.target_sha), args.migration_ran)
    # "hard": a pending migration from outside this range ran; never take the additive rollback.
    if decision == "hold" and "hard" not in str(args.migration_ran):
        decision = decide("1", "1", (additive_fn or range_additive)(args.previous_sha, args.target_sha))
    if decision == "rollback":
        return RC_GATE_ROLLBACK
    print(
        "::error::Post-deploy verification failed and the deployed range contains a migration "
        f"(migration ran={args.migration_ran}). No automatic rollback and no migrate:rollback: "
        f"production stays on {args.target_sha}. Manual intervention required."
    )
    return RC_HELD_MIGRATION


# --- post-deploy watch (gap #3; Argo Rollouts AnalysisRun / Flagger / SRE canary analysis) ---

FLAP_WINDOW, FLAP_FAILURES = 12, 8
SENTRY_TOKEN_ENV = "SENTRY_WATCH"
DEFAULT_SENTRY_API = "https://sentry.io"
_SLUG_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$")


class SpikePolicy:
    """Error-count spike thresholds (all configurable from the gate's command line)."""

    def __init__(self, factor: float, floor_per_min: float, min_count: int):
        self.factor, self.floor_per_min, self.min_count = factor, floor_per_min, min_count


def next_failure_streak(streak: int, problems: list) -> int:
    """Consecutive failed health/identity checks; one passing check resets the streak."""
    return streak + 1 if problems else 0


def sentry_spike(baseline_count, baseline_minutes, watch_count, watch_minutes, policy: SpikePolicy) -> str | None:
    """A reason when the post-deploy error rate is a spike against the pre-deploy baseline, else None.

    Spike = at least ``min_count`` errors since the watch began AND a per-minute rate above
    ``factor`` x max(baseline rate, ``floor_per_min``). The floor keeps a quiet baseline
    (0 errors) from turning one stray error into a rollback; ``min_count`` keeps short
    windows from tripping on a handful. Unknown counts never trigger.
    """
    if baseline_count is None or watch_count is None or baseline_minutes <= 0:
        return None
    watch_minutes = max(float(watch_minutes), 1.0)
    baseline_rate = baseline_count / float(baseline_minutes)
    watch_rate = watch_count / watch_minutes
    threshold = policy.factor * max(baseline_rate, policy.floor_per_min)
    if watch_count >= policy.min_count and watch_rate > threshold:
        return (f"Sentry errors {watch_count} in {watch_minutes:.1f} min ({watch_rate:.2f}/min) exceed "
                f"{policy.factor:g}x baseline {baseline_rate:.2f}/min (floor {policy.floor_per_min:g}/min)")
    return None


def sentry_config_from_env(environ) -> dict | None:
    """Sentry read-only settings, or None when not configured (the watch then skips Sentry)."""
    # "<org>/<project> <token>", or "<https base> <org>/<project> <token>" for a regional host
    # such as https://de.sentry.io. One secret, so the executor heredoc stays under GitHub's limit.
    parts = (environ.get(SENTRY_TOKEN_ENV) or "").split()
    if not parts:
        return None
    if len(parts) not in (2, 3):
        print("post-deploy watch: SENTRY_WATCH is malformed; Sentry check skipped")
        return None
    base = parts[0] if len(parts) == 3 else DEFAULT_SENTRY_API
    org, _, project = parts[-2].partition("/")
    token = parts[-1]
    if not (_SLUG_RE.match(org) and _SLUG_RE.match(project) and base.startswith("https://")):
        print("post-deploy watch: Sentry settings are malformed; Sentry check skipped")
        return None
    return {"token": token, "org": org, "project": project, "base": base.rstrip("/")}


def _iso(epoch: float) -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime(epoch))


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def sentry_error_count(config: dict, start: float, end: float, timeout: float = 10.0, opener=None) -> int | None:
    """Error events in [start, end) for the configured project; None on any failure (never a breach)."""
    # Server (PHP SDK) events only: the browser DSN is public, so its events must never drive a rollback.
    # ponytail: a spoofed php event sent with a shared DSN still counts; keep the browser DSN in another project.
    query = urllib.parse.urlencode({
        "field": "count()", "query": f"event.type:error platform.name:php project:{config['project']}",
        "start": _iso(start), "end": _iso(end), "dataset": "errors", "referrer": "alltrue.post-deploy-watch",
    })
    url = f"{config['base']}/api/0/organizations/{urllib.parse.quote(config['org'])}/events/?{query}"
    request = urllib.request.Request(url, headers={"Authorization": f"Bearer {config['token']}",
                                                  "User-Agent": USER_AGENT})
    try:
        # No redirects: urllib would forward the Bearer token to another host.
        with (opener or urllib.request.build_opener(_NoRedirect).open)(request, timeout=timeout) as response:  # noqa: S310
            payload = json.loads(response.read(65536).decode("utf-8"))
        value = payload["data"][0]["count()"]
        return int(value) if isinstance(value, (int, float)) and not isinstance(value, bool) else None
    except Exception as error:  # noqa: BLE001 - Sentry outages must not roll production back
        status = getattr(error, "code", None)  # an HTTP 400 here means Sentry rejected the query fields
        print(f"post-deploy watch: Sentry query failed ({error.__class__.__name__}"
              f"{f' HTTP {status}' if status else ''}); counted as unknown")
        return None


def watch(args, sentry=None, check=None, count=None, sleep=time.sleep, clock=time.monotonic,
          wall=time.time) -> str | None:
    """Watch a verified release; return the breach reason, or None when the window passed."""
    check = check or (lambda: check_once(args.base_url, args.health_path or DEFAULT_HEALTH_PATHS,
                                         args.expect_backend_sha, args.expect_frontend_sha, args.timeout,
                                         args.insecure))
    count = count or sentry_error_count
    start, wall_start = clock(), wall()
    end = start + args.watch_seconds
    baseline = None
    baseline_minutes = float(args.sentry_baseline_minutes)
    if sentry is None:
        print("post-deploy watch: Sentry check skipped (secret SENTRY_WATCH not set)")
    else:
        gap = args.sentry_baseline_gap_minutes * 60.0
        baseline = count(sentry, wall_start - gap - baseline_minutes * 60.0, wall_start - gap)
        if baseline is None:
            print("post-deploy watch: Sentry baseline unavailable; Sentry check skipped for this deploy")
        else:
            print(f"post-deploy watch: Sentry baseline {baseline} errors in {baseline_minutes:g} min")
    print(f"post-deploy watch: {args.watch_seconds:g}s, health + identity every {args.watch_interval:g}s, "
          f"breach after {args.watch_failures} consecutive failures")
    streak, checks, next_sentry = 0, 0, start + args.sentry_every
    recent = collections.deque(maxlen=FLAP_WINDOW)  # a flapping site never fails 4 in a row (#4560 review)
    while True:
        problems = check()
        checks += 1
        streak = next_failure_streak(streak, problems)
        recent.append(bool(problems))
        if problems:
            print(f"post-deploy watch: check {checks} failed ({streak}/{args.watch_failures}): {'; '.join(problems)}")
        if streak >= args.watch_failures:
            return f"{streak} consecutive failed health/identity checks: {'; '.join(problems)}"
        if sum(recent) >= FLAP_FAILURES:
            return f"{sum(recent)} of the last {len(recent)} health/identity checks failed: {'; '.join(problems) or 'flapping'}"
        last = clock() + args.watch_interval > end
        if baseline is not None and (clock() >= next_sentry or last):
            next_sentry += args.sentry_every
            now = wall()
            current = count(sentry, wall_start, now)
            reason = sentry_spike(baseline, baseline_minutes, current, (now - wall_start) / 60.0, args.spike)
            if reason and any(recent):  # health is failing too: a health breach (rollback), not Sentry-only (hold)
                return f"{sum(recent)} of the last {len(recent)} health/identity checks failed and {reason}"
            if reason:
                return reason
            if current is not None:
                print(f"post-deploy watch: Sentry {current} errors since watch start (no spike)")
        if last:
            break
        sleep(args.watch_interval)
    print(f"post-deploy watch: OK after {checks} checks over {args.watch_seconds:g}s")
    return None


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
    g.add_argument("--watch-seconds", type=float, default=0.0, help="post-deploy watch window; 0 = no watch")
    g.add_argument("--watch-interval", type=float, default=30.0)
    g.add_argument("--watch-failures", type=int, default=4, help="consecutive failed checks that breach")
    g.add_argument("--sentry-every", type=float, default=300.0, help="seconds between Sentry queries")
    g.add_argument("--sentry-factor", type=float, default=5.0)
    g.add_argument("--sentry-floor-per-min", type=float, default=0.2)
    g.add_argument("--sentry-min-count", type=int, default=20)
    g.add_argument("--sentry-baseline-minutes", type=float, default=60.0)
    g.add_argument("--sentry-baseline-gap-minutes", type=float, default=15.0,
                   help="baseline ends this long before the watch, to leave the deploy itself out")

    q = sub.add_parser("pending-in-range")
    q.add_argument("previous_sha")
    q.add_argument("target_sha")

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
    if args.command == "gate" and (args.watch_seconds < 0 or args.watch_failures < 1 or args.watch_interval <= 0):
        parser.error("--watch-seconds must be >= 0, --watch-failures >= 1, --watch-interval > 0")
    if args.command == "verify":
        return verify(args)
    if args.command == "gate":
        return gate(args)
    if args.command == "pending-in-range":
        print(pending_in_range(sys.stdin.read(), args.previous_sha, args.target_sha))
        return 0
    if args.command == "range-additive":
        print(range_additive(args.previous_sha, args.target_sha))
        return 0
    sys.stdout.write(summarize(args.rc, args.target_sha, args.previous_sha, args.runner_verify))
    return 0


if __name__ == "__main__":
    sys.exit(main())
