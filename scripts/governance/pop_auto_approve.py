#!/usr/bin/env python3
"""Founder 2026-10-28 A (b): an internal append-only POP repair may approve without the Founder click.

`auto_approve=true` only when every condition holds; anything missing, malformed or unreadable
gives `false`, which keeps the production-activation click (fail closed):

- the workflow and its scope are in APPEND_ONLY, the one reviewed allowlist (internal ledger /
  occurrence repairs whose execute only inserts rows and keeps a rollback snapshot; nothing that
  creates or changes invoices, receipts or payments, or notifies parents);
- mode is approve and the run is on refs/heads/main;
- the approve run cites one dry-run run id, and that run (read through the API) is a successful
  workflow_dispatch of the same workflow file on main, created within 24 h, whose SSH job logged
  exactly one POP_DRY_RUN_EVIDENCE line with mode=dry-run, ok=true, blocked=0, that run id and the
  same deployed backend SHA, scope and plan digest as this approve run.

The SSH job re-checks the fresh plan on the Pi before approving (same digest, blocked=0) and uses a
`standing-a-auto-<workflow>-<digest12>` approval reference, which POP accepts only for the same
operations (PopOperationService::STANDING_A_AUTO). The Pi verify step must pass or the run fails
with the rollback snapshot kept.

Usage (guard job): env REPO WORKFLOW MODE REF BACKEND_SHA SCOPE DIGEST DRY_RUN_ID GITHUB_OUTPUT,
GH_TOKEN with actions:read. Stdlib only; always exits 0 so an evidence error falls back to the click.
"""
import json
import os
import re
import subprocess
from datetime import datetime, timedelta, timezone

# workflow file -> (name of its SSH job, whose log carries the dry-run evidence; allowed scope)
APPEND_ONLY = {
    # PackageLedgerNetRepairStrategy: inserts the exact-manifest package_session_ledger rows and recomputes the
    # counters from the ledger; rollback deletes exactly those rows (exact_manifest_package_ledger_rows_insert_only).
    "pop-package-ledger-net-repair-4455.yml": (
        "Pi-local short-lived POP repair (package ledger net, #4455)", r"package-ledger-net-repair-4455"),
    # Td076HistoryPinsStrategy, r2 only: creates history-pin schedule rows; rollback deletes them
    # (campus_scoped_history_pin_rows_created_only). r1 changes row status, so it keeps the click.
    "pop-td076-occurrence-repairs.yml": (
        "Pi-local short-lived POP repair (TD-076 occurrence repairs)", r"r2:c[1-9][0-9]{0,5}"),
}
WINDOW = timedelta(hours=24)
MARKER = "POP_DRY_RUN_EVIDENCE"
# GitHub prefixes every log line with a UTC timestamp; the echoed script never starts a line with the marker.
_EVIDENCE_LINE = re.compile(rf"^(?:﻿)?(?:\S+Z )?{MARKER} (\{{.*\}})\s*$", re.M)


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


def decide(*, workflow: str, mode: str, ref: str, backend_sha: str, scope: str, digest: str, dry_run_id: str,
           now: datetime, run: dict | None) -> tuple[bool, str]:
    if workflow not in APPEND_ONLY:
        return False, "workflow is not in the append-only allowlist"
    if mode != "approve":
        return False, "not approve mode"
    if ref != "refs/heads/main":
        return False, "not running on main"
    if not (re.fullmatch(r"[0-9a-f]{40}", backend_sha or "") and re.fullmatch(r"[0-9a-f]{64}", digest or "")
            and re.fullmatch(APPEND_ONLY[workflow][1], scope or "") and re.fullmatch(r"[1-9][0-9]{0,19}", dry_run_id or "")):
        return False, "inputs are not a full backend SHA, a 64-hex digest, an allowlisted scope and a cited dry-run run id"
    if not run or str(run.get("id")) != dry_run_id:
        return False, "cited dry-run run was not found"
    created = _iso(run.get("created_at"))
    if not (run.get("event") == "workflow_dispatch" and run.get("head_branch") == "main"
            and run.get("conclusion") == "success" and run.get("path") == f".github/workflows/{workflow}"
            and created is not None and timedelta(0) <= now - created <= WINDOW):
        return False, "cited run is not a successful main workflow_dispatch of this workflow within 24 h"
    evidence = run.get("evidence") or []
    if len(evidence) != 1:
        return False, "cited run does not carry exactly one dry-run evidence line"
    want = {"mode": "dry-run", "workflow": workflow, "backend_sha": backend_sha, "scope": scope, "digest": digest,
            "ok": True, "blocked": 0, "run_id": dry_run_id}
    # type() as well as value: blocked must be the integer 0 (not False or "0"), ok the boolean True.
    if not all(type(evidence[0].get(k)) is type(v) and evidence[0].get(k) == v for k, v in want.items()):
        return False, "cited dry-run evidence does not match (backend SHA, scope, digest, ok, blocked=0, run id)"
    return True, f"dry-run run {dry_run_id} matches (same backend SHA, scope and digest; blocked=0)"


def _gh(path: str) -> str:
    return subprocess.run(["gh", "api", path], check=True, capture_output=True, text=True, timeout=60).stdout


def collect_run(repo: str, workflow: str, run_id: str) -> dict:
    run = json.loads(_gh(f"/repos/{repo}/actions/runs/{run_id}"))
    jobs = json.loads(_gh(f"/repos/{repo}/actions/runs/{run_id}/jobs?per_page=50")).get("jobs") or []
    ssh = [job for job in jobs if job.get("name") == APPEND_ONLY[workflow][0] and job.get("conclusion") == "success"]
    evidence = parse_evidence(_gh(f"/repos/{repo}/actions/jobs/{ssh[0].get('id')}/logs")) if len(ssh) == 1 else []
    return {key: run.get(key) for key in ("id", "created_at", "conclusion", "head_branch", "event", "path")} | {"evidence": evidence}


def main(env=os.environ) -> int:
    try:
        workflow, mode, dry_run_id = env.get("WORKFLOW", ""), env.get("MODE", ""), env.get("DRY_RUN_ID", "")
        cite = workflow in APPEND_ONLY and mode == "approve" and re.fullmatch(r"[1-9][0-9]{0,19}", dry_run_id)
        run = collect_run(env["REPO"], workflow, dry_run_id) if cite else None
        ok, reason = decide(workflow=workflow, mode=mode, ref=env.get("REF", ""), backend_sha=env.get("BACKEND_SHA", ""),
                            scope=env.get("SCOPE", ""), digest=env.get("DIGEST", ""), dry_run_id=dry_run_id,
                            now=datetime.now(timezone.utc), run=run)
    except Exception as exc:  # fail closed: any evidence error keeps the Founder click
        ok, reason = False, f"evidence check failed ({type(exc).__name__})"
    print(f"auto_approve={'true' if ok else 'false'}: {reason}")
    with open(env["GITHUB_OUTPUT"], "a", encoding="utf-8") as out:
        out.write(f"auto_approve={'true' if ok else 'false'}\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
