#!/usr/bin/env python3
"""Founder 2026-10-28 A (b): an append-only POP repair may approve without the Founder click.

`auto_approve=true` only when every condition holds; anything missing, malformed or unreadable
gives `false`, which keeps the production-activation click (fail closed):

- the workflow is in APPEND_ONLY, the one reviewed allowlist (its strategy's execute only inserts
  rows and keeps a rollback snapshot);
- mode is approve and the run is on refs/heads/main;
- a successful workflow_dispatch run of the same workflow file on main, created within 24 h, whose
  SSH job logged exactly one POP_DRY_RUN_EVIDENCE line with mode=dry-run, ok=true, blocked=0 and
  the same deployed backend SHA, scope and plan digest as this approve run.

The SSH job re-checks on the Pi before approving (live digest == expected_digest, blocked=0), and
the Pi verify step must pass or the run fails with the rollback snapshot kept.

Usage (guard job): env REPO WORKFLOW MODE REF BACKEND_SHA SCOPE DIGEST RUN_ID GITHUB_OUTPUT, GH_TOKEN
with actions:read. Stdlib only; always exits 0 so an evidence error falls back to the click.
"""
import json
import os
import re
import subprocess
from datetime import datetime, timedelta, timezone

# workflow file -> name of its SSH job (the job whose log carries the dry-run evidence)
APPEND_ONLY = {
    # UnbilledBacklogCatchupStrategy::execute only creates StudentClass/Invoice/InvoiceItem rows;
    # its rollback deletes exactly the snapshot rows (catalog: reversible, snapshot_required).
    "pop-unbilled-backlog-catchup.yml": "Pi-local short-lived POP unbilled-backlog catch-up",
}
WINDOW = timedelta(hours=24)
MARKER = "POP_DRY_RUN_EVIDENCE"
# GitHub prefixes every log line with a UTC timestamp; the echoed script never starts a line with the marker.
_EVIDENCE_LINE = re.compile(rf"^(?:﻿)?(?:\S+Z )?{MARKER} (\{{.*\}})\s*$", re.M)
_MAX_RUNS = 20  # ponytail: newest 20 successful dispatches in 24 h; more than that falls back to the click


def parse_evidence(log: str) -> list[dict]:
    found = []
    for raw in _EVIDENCE_LINE.findall(log or ""):
        try:
            value = json.loads(raw)
        except ValueError:
            value = None
        found.append(value if isinstance(value, dict) else {})
    return found


def _iso(value: object) -> datetime | None:
    try:
        parsed = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    except ValueError:
        return None
    return parsed if parsed.tzinfo else None


def decide(*, workflow: str, mode: str, ref: str, backend_sha: str, scope: str, digest: str,
           now: datetime, runs: list[dict]) -> tuple[bool, str]:
    if workflow not in APPEND_ONLY:
        return False, "workflow is not in the append-only allowlist"
    if mode != "approve":
        return False, "not approve mode"
    if ref != "refs/heads/main":
        return False, "not running on main"
    if not (re.fullmatch(r"[0-9a-f]{40}", backend_sha or "") and re.fullmatch(r"[0-9a-f]{64}", digest or "")
            and re.fullmatch(r"[1-9][0-9]{0,5}", scope or "")):
        return False, "approve inputs are not a full backend SHA, a 64-hex digest and a numeric scope"
    want = {"mode": "dry-run", "workflow": workflow, "backend_sha": backend_sha, "scope": scope,
            "digest": digest, "ok": True, "blocked": 0}
    for run in runs:
        created = _iso(run.get("created_at"))
        if not (run.get("event") == "workflow_dispatch" and run.get("head_branch") == "main"
                and run.get("conclusion") == "success" and run.get("path") == f".github/workflows/{workflow}"
                and created is not None and timedelta(0) <= now - created <= WINDOW):
            continue
        evidence = run.get("evidence") or []
        if len(evidence) != 1:
            continue
        ev = evidence[0]
        # type() as well as value: blocked must be the integer 0 (not False or "0"), ok the boolean True.
        if all(type(ev.get(k)) is type(v) and ev.get(k) == v for k, v in want.items()) \
                and ev.get("run_id") == str(run.get("id")):
            return True, f"dry-run run {run.get('id')} matches (same backend SHA, scope and digest; blocked=0)"
    return False, "no successful dry-run of this workflow on main within 24 h with the same backend SHA, scope and digest and blocked=0"


def _gh(path: str) -> str:
    return subprocess.run(["gh", "api", path], check=True, capture_output=True, text=True, timeout=60).stdout


def collect_runs(repo: str, workflow: str, now: datetime, current_run_id: str) -> list[dict]:
    since = (now - WINDOW).strftime("%Y-%m-%dT%H:%M:%SZ")
    listing = json.loads(_gh(f"/repos/{repo}/actions/workflows/{workflow}/runs?branch=main&event=workflow_dispatch"
                             f"&status=success&created=%3E%3D{since}&per_page={_MAX_RUNS}"))
    runs = []
    for run in listing.get("workflow_runs") or []:
        if str(run.get("id")) == str(current_run_id):
            continue
        jobs = json.loads(_gh(f"/repos/{repo}/actions/runs/{run.get('id')}/jobs?per_page=50")).get("jobs") or []
        ssh = [job for job in jobs if job.get("name") == APPEND_ONLY[workflow] and job.get("conclusion") == "success"]
        evidence = parse_evidence(_gh(f"/repos/{repo}/actions/jobs/{ssh[0].get('id')}/logs")) if len(ssh) == 1 else []
        runs.append({key: run.get(key) for key in ("id", "created_at", "conclusion", "head_branch", "event", "path")}
                    | {"evidence": evidence})
    return runs


def main(env=os.environ) -> int:
    try:
        now = datetime.now(timezone.utc)
        workflow, mode = env.get("WORKFLOW", ""), env.get("MODE", "")
        runs = collect_runs(env["REPO"], workflow, now, env.get("RUN_ID", "")) \
            if workflow in APPEND_ONLY and mode == "approve" else []
        ok, reason = decide(workflow=workflow, mode=mode, ref=env.get("REF", ""), backend_sha=env.get("BACKEND_SHA", ""),
                            scope=env.get("SCOPE", ""), digest=env.get("DIGEST", ""), now=now, runs=runs)
    except Exception as exc:  # fail closed: any evidence error keeps the Founder click
        ok, reason = False, f"evidence check failed ({type(exc).__name__})"
    print(f"auto_approve={'true' if ok else 'false'}: {reason}")
    with open(env["GITHUB_OUTPUT"], "a", encoding="utf-8") as out:
        out.write(f"auto_approve={'true' if ok else 'false'}\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
