"""Dogfood Supervisor process for HARNESS_STORE_AUTHORITY_CUTOVER recovery proof.

This is intentionally a small deterministic process — not a durable-execution engine.
Kill/restart this process to prove rediscovery from harness.sqlite without chat state.
"""

from __future__ import annotations

import argparse
import json
import os
import signal
import sys
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.harness.dispatch import ingest_handoff  # noqa: E402
from scripts.harness.launcher import get_launcher  # noqa: E402
from scripts.harness.leases import acquire  # noqa: E402
from scripts.harness.models import Program, Task  # noqa: E402
from scripts.harness.states import TaskState  # noqa: E402
from scripts.harness.store import HarnessStore  # noqa: E402


DOGFOOD_PROGRAM = "harness-cutover-dogfood"
DOGFOOD_TASK = "CUTOVER-DOGFOOD-1"
DOGFOOD_RESOURCE = "ownership:harness-cutover-dogfood/**"


def _now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _write_json(path: Path, obj: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(obj, indent=2) + "\n", encoding="utf-8")


def seed_dogfood(
    store: HarnessStore,
    *,
    session_id: str,
    worktree_path: str,
    branch: str,
    evidence_dir: Path,
) -> dict[str, Any]:
    """Create one safe non-production WorkerRun bound to the cutover worktree."""
    now = _now()
    store.upsert_program(Program(
        program_id=DOGFOOD_PROGRAM,
        name="Harness Cutover Dogfood",
        owner="platform",
        repository="jerry200176-png/AllTrue_System",
        goal="Prove harness.sqlite authority cutover recovery",
        canonical_status_source="docs/harness/CUTOVER_LIFECYCLE.md",
        current_task_id=DOGFOOD_TASK,
        updated_at=now,
    ))
    store.upsert_task(Task(
        task_id=DOGFOOD_TASK,
        program_id=DOGFOOD_PROGRAM,
        outcome="Prove WorkerRun rediscovery after Supervisor process kill",
        status=TaskState.EXECUTING,
        business_value=10,
        affected_paths=["docs/harness/**", "scripts/harness/**"],
        scope=["docs/harness/**", "scripts/harness/**"],
        designed_slice=True,
        worktree=worktree_path,
        branch=branch,
        assignee="dogfood-supervisor",
        next_action="supervisor_running",
        updated_at=now,
    ))

    lease = acquire(
        store,
        DOGFOOD_RESOURCE,
        task_id=DOGFOOD_TASK,
        worker="dogfood-supervisor",
        ttl_sec=6 * 3600,
    )

    attempt_id = f"da_cutover_{uuid.uuid4().hex[:12]}"
    run_id = f"wr_cutover_{uuid.uuid4().hex[:12]}"
    lease_binding = {
        "bindings": [{
            "resource_key": DOGFOOD_RESOURCE,
            "lease_id": lease["lease_id"],
            "fencing_token": int(lease["fencing_token"]),
            "task_id": DOGFOOD_TASK,
            "worker": "dogfood-supervisor",
        }],
    }
    store.put_dispatch_attempt({
        "attempt_id": attempt_id,
        "plan_id": "plan_cutover_dogfood",
        "task_id": DOGFOOD_TASK,
        "goal_id": "goal_cutover_dogfood",
        "goal_contract_fingerprint": "cutover-dogfood-fp",
        "status": "active",
        "worker": "dogfood-supervisor",
        "payload": {"dogfood": True, "lease_binding": lease_binding},
        "created_at": now,
        "updated_at": now,
    })
    store.put_worker_run({
        "run_id": run_id,
        "attempt_id": attempt_id,
        "task_id": DOGFOOD_TASK,
        "mode": "attach",
        "status": "running",
        "session_id": session_id,
        "worktree_path": worktree_path,
        "branch": branch,
        "worker": "dogfood-supervisor",
        "fencing_token": int(lease["fencing_token"]),
        "lease_binding": lease_binding,
        "payload": {"dogfood": True, "seeded_at": now},
        "created_at": now,
        "updated_at": now,
    })

    out = {
        "ok": True,
        "program_id": DOGFOOD_PROGRAM,
        "task_id": DOGFOOD_TASK,
        "attempt_id": attempt_id,
        "run_id": run_id,
        "session_id": session_id,
        "worktree_path": worktree_path,
        "branch": branch,
        "fencing_token": int(lease["fencing_token"]),
        "lease_id": lease["lease_id"],
        "seeded_at": now,
    }
    _write_json(evidence_dir / "DOGFOOD_SEED.json", out)
    return out


def recover_from_store(
    store: HarnessStore,
    *,
    run_id: str | None = None,
    evidence_dir: Path,
    attach: bool = True,
) -> dict[str, Any]:
    """Fresh process entry: discover WorkerRun from sqlite and reattach."""
    if run_id:
        run = store.get_worker_run(run_id)
        runs = [run] if run else []
    else:
        runs = [
            r for r in store.list_worker_runs(task_id=DOGFOOD_TASK)
            if r.get("status") in {"starting", "running"}
        ]
    if not runs:
        return {"ok": False, "reason": "worker_run_not_found"}

    run = runs[-1]
    claim = {
        "attempt_id": run["attempt_id"],
        "lease_binding": run.get("lease_binding") or {},
        "result": {"recovered_by": "dogfood_supervisor", "at": _now()},
    }
    # Live fencing must match.
    handoff = ingest_handoff(
        store,
        claim["attempt_id"],
        claimed_bindings=claim["lease_binding"],
        result=claim["result"],
    )
    attach_result: dict[str, Any] | None = None
    if attach and run.get("worktree_path"):
        launcher = get_launcher()
        lr = launcher.start_or_attach(
            project="alltrue",
            launcher_task_id="harness-store-authority-cutover",
            prefer_resume=True,
            dry_run=True,
        )
        attach_result = lr.to_dict()

    live_binding = ((run.get("lease_binding") or {}).get("bindings") or [{}])[0]
    # Stale fencing must fail.
    stale_bindings = {
        "bindings": [{
            "resource_key": live_binding.get("resource_key") or DOGFOOD_RESOURCE,
            "lease_id": live_binding.get("lease_id") or "",
            "fencing_token": int(run.get("fencing_token") or 0) - 1,
            "task_id": live_binding.get("task_id") or DOGFOOD_TASK,
            "worker": live_binding.get("worker") or "dogfood-supervisor",
        }],
    }
    stale = ingest_handoff(
        store,
        run["attempt_id"],
        claimed_bindings=stale_bindings,
        result={"stale_probe": True},
    )

    out = {
        "ok": bool(handoff.ok) and (stale.ok is False),
        "recovered_at": _now(),
        "pid": os.getpid(),
        "run": run,
        "handoff": handoff.to_dict(),
        "stale_denied": stale.to_dict(),
        "attach": attach_result,
        "chat_history_used": False,
        "founder_relay_used": False,
    }
    _write_json(evidence_dir / "DOGFOOD_RECOVER.json", out)
    return out


def run_loop(evidence_dir: Path, *, heartbeat_s: float = 2.0, max_beats: int = 3) -> int:
    db = os.environ.get("HARNESS_DB")
    store = HarnessStore(Path(db) if db else None)
    pid_path = evidence_dir / "supervisor.pid"
    pid_path.write_text(str(os.getpid()) + "\n", encoding="utf-8")
    stop = {"flag": False}

    def _stop(signum: int, _frame: Any) -> None:
        stop["flag"] = True
        _write_json(evidence_dir / "SUPERVISOR_SIGNAL.json", {
            "signal": signum, "pid": os.getpid(), "at": _now(),
        })

    signal.signal(signal.SIGTERM, _stop)
    signal.signal(signal.SIGINT, _stop)

    beats = 0
    while not stop["flag"] and beats < max_beats:
        beats += 1
        _write_json(evidence_dir / "SUPERVISOR_HEARTBEAT.json", {
            "pid": os.getpid(),
            "beat": beats,
            "at": _now(),
            "db": str(store.db_path),
        })
        time.sleep(heartbeat_s)

    _write_json(evidence_dir / "SUPERVISOR_EXIT.json", {
        "pid": os.getpid(),
        "at": _now(),
        "beats": beats,
        "stopped": stop["flag"],
    })
    store.close()
    return 0


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="cutover_dogfood")
    p.add_argument("--db", default=None)
    p.add_argument("--evidence-dir", required=True)
    sub = p.add_subparsers(dest="cmd", required=True)

    seed = sub.add_parser("seed")
    seed.add_argument("--session-id", required=True)
    seed.add_argument("--worktree", required=True)
    seed.add_argument("--branch", required=True)

    rec = sub.add_parser("recover")
    rec.add_argument("--run-id", default=None)
    rec.add_argument("--no-attach", action="store_true")

    loop = sub.add_parser("run")
    loop.add_argument("--heartbeat-s", type=float, default=2.0)
    loop.add_argument("--max-beats", type=int, default=30)

    args = p.parse_args(argv)
    if args.db:
        os.environ["HARNESS_DB"] = args.db
    evidence = Path(args.evidence_dir)
    store = HarnessStore(Path(args.db) if args.db else None)

    if args.cmd == "seed":
        out = seed_dogfood(
            store,
            session_id=args.session_id,
            worktree_path=args.worktree,
            branch=args.branch,
            evidence_dir=evidence,
        )
        print(json.dumps(out, indent=2))
        return 0 if out.get("ok") else 1
    if args.cmd == "recover":
        out = recover_from_store(
            store,
            run_id=args.run_id,
            evidence_dir=evidence,
            attach=not args.no_attach,
        )
        print(json.dumps(out, indent=2))
        return 0 if out.get("ok") else 1
    if args.cmd == "run":
        store.close()
        return run_loop(evidence, heartbeat_s=args.heartbeat_s, max_beats=args.max_beats)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
