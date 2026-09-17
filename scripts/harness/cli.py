"""CLI: sync / status / resume / founder-inbox / graph / plan / dispatch (H0–H4b)."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

from .dispatch import dispatch, heartbeat, ingest_handoff, release_on_pr_ready
from .graph import build_graph
from .leases import reclaim_stale
from .planner import select_across_programs, select_next_task
from .programs_loader import sync_programs_to_store
from .reconcile import resume_from_checkpoint
from .states import ACTIVE_MUTATING
from .store import HarnessStore


def _store(args: argparse.Namespace) -> HarnessStore:
    return HarnessStore(Path(args.db) if args.db else None)


def cmd_sync(args: argparse.Namespace) -> int:
    store = _store(args)
    ids = sync_programs_to_store(store, only_missing_tasks=not args.refresh_tasks)
    print(json.dumps({"synced_programs": ids, "db": str(store.db_path)}, indent=2))
    return 0


def cmd_status(args: argparse.Namespace) -> int:
    store = _store(args)
    if args.sync:
        sync_programs_to_store(store)
    reclaim_stale(store)
    rows: list[dict[str, Any]] = []
    for prog in store.list_programs():
        tasks = store.list_tasks(program_id=prog.program_id)
        active = [t for t in tasks if t.status in ACTIVE_MUTATING]
        current = store.get_task(prog.current_task_id) if prog.current_task_id else None
        if current is None and active:
            current = active[0]
        if current is None:
            ready = [t for t in tasks if t.status.value == "READY"]
            current = ready[0] if ready else (tasks[0] if tasks else None)
        rows.append({
            "PROGRAM": prog.name,
            "CURRENT_TASK": current.task_id if current else "-",
            "STATE": current.status.value if current else (
                "BLOCKED_HUMAN" if prog.blockers else "IDLE"
            ),
            "OWNER": (current.assignee if current and current.assignee else prog.owner),
        })
    headers = ("PROGRAM", "CURRENT_TASK", "STATE", "OWNER")
    widths = {h: max(len(h), max((len(str(r[h])) for r in rows), default=0)) for h in headers}
    print("  ".join(h.ljust(widths[h]) for h in headers))
    print("  ".join("-" * widths[h] for h in headers))
    for row in rows:
        print("  ".join(str(row[h]).ljust(widths[h]) for h in headers))
    open_runs = [r for r in store.list_worker_runs() if r["status"] in {"starting", "running"}]
    print(f"\ndb={store.db_path} founder_open={len(store.list_open_escalations())} "
          f"leases={len(store.list_leases())} goals={len(store.list_goals())} "
          f"worker_runs_open={len(open_runs)}")
    if args.json:
        print(json.dumps({"programs": rows, "worker_runs_open": open_runs}, indent=2))
    return 0


def cmd_founder_inbox(args: argparse.Namespace) -> int:
    store = _store(args)
    items = [e.to_dict() for e in store.list_open_escalations()]
    print(json.dumps({"count": len(items), "escalations": items}, indent=2))
    return 0


def cmd_resume(args: argparse.Namespace) -> int:
    store = _store(args)
    if args.sync:
        sync_programs_to_store(store)
    reclaimed = reclaim_stale(store)
    if args.checkpoint:
        print(json.dumps(resume_from_checkpoint(store, args.checkpoint), indent=2))
        return 0
    running, waiting, founder = [], [], []
    for prog in store.list_programs():
        for task in store.list_tasks(program_id=prog.program_id):
            item = {"program": prog.program_id, "task": task.task_id, "state": task.status.value}
            if task.status in ACTIVE_MUTATING:
                running.append(item)
            elif task.status.value == "FOUNDER_REQUIRED":
                founder.append(item)
            elif task.status.value in {"CI_PENDING", "BLOCKED", "STAGING_PENDING"}:
                waiting.append(item)
    print(json.dumps({
        "programs": [p.program_id for p in store.list_programs()],
        "running": running, "waiting": waiting, "founder_required": founder,
        "reclaimed_stale_leases": reclaimed, "db": str(store.db_path),
        "worker_runs": store.list_worker_runs(),
    }, indent=2))
    return 0


def cmd_graph(args: argparse.Namespace) -> int:
    print(json.dumps(build_graph(_store(args)).to_dict(), indent=2))
    return 0


def cmd_plan(args: argparse.Namespace) -> int:
    """H3 read-only planner. --sync may reload YAML; never mutates leases."""
    store = _store(args)
    if args.sync:
        sync_programs_to_store(store)
    kwargs = {
        "main_sha": args.main_sha or None,
        "reconcile_stale": bool(args.sync or args.reconcile_stale),
        "apply_governance": not args.skip_governance,
    }
    if args.program:
        result = select_next_task(store, program_id=args.program, **kwargs)
    else:
        result = select_across_programs(store, **kwargs)
    payload = result.to_dict()
    payload["db"] = str(store.db_path)
    print(json.dumps(payload, indent=2))
    return 0


def cmd_dispatch(args: argparse.Namespace) -> int:
    """H4/H4b dispatch — dry-run by default; --apply mutates leases + WorkerRun."""
    store = _store(args)
    if args.plan_json:
        plan = json.loads(Path(args.plan_json).read_text(encoding="utf-8"))
    elif args.program:
        plan = select_next_task(
            store, program_id=args.program, main_sha=args.main_sha or None,
        ).to_dict()
    else:
        plan = select_across_programs(
            store, main_sha=args.main_sha or None,
        ).to_dict()
    if args.heartbeat:
        result = heartbeat(store, args.heartbeat, worker=args.worker)
    elif args.release:
        handoff = None
        if args.handoff_json:
            handoff = json.loads(Path(args.handoff_json).read_text(encoding="utf-8"))
        result = release_on_pr_ready(store, args.release, handoff=handoff)
    elif args.ingest:
        claim = json.loads(Path(args.ingest).read_text(encoding="utf-8"))
        result = ingest_handoff(
            store, claim["attempt_id"],
            claimed_bindings=claim.get("lease_binding") or claim.get("claimed_bindings") or {},
            result=claim.get("result"),
        )
    else:
        result = dispatch(
            store, plan, worker=args.worker, apply=bool(args.apply),
            main_sha=args.main_sha or None,
            reclaim_expired=not args.skip_reclaim,
            spawn_dry_run=not bool(args.spawn_exec),
        )
    print(json.dumps(result.to_dict(), indent=2))
    return 0 if result.ok else 1


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="python3 -m scripts.harness")
    p.add_argument("--db", default=None)
    sub = p.add_subparsers(dest="command", required=True)
    sync = sub.add_parser("sync")
    sync.add_argument("--refresh-tasks", action="store_true")
    sync.set_defaults(func=cmd_sync)
    st = sub.add_parser("status")
    st.add_argument("--sync", action="store_true")
    st.add_argument("--json", action="store_true")
    st.set_defaults(func=cmd_status)
    inbox = sub.add_parser("founder-inbox")
    inbox.set_defaults(func=cmd_founder_inbox)
    resume = sub.add_parser("resume")
    resume.add_argument("--sync", action="store_true")
    resume.add_argument("--checkpoint", default=None)
    resume.set_defaults(func=cmd_resume)
    graph = sub.add_parser("graph")
    graph.set_defaults(func=cmd_graph)
    plan = sub.add_parser("plan", help="H3 read-only PlanResult (no lease mutate)")
    plan.add_argument("--program", default=None)
    plan.add_argument("--sync", action="store_true")
    plan.add_argument("--reconcile-stale", action="store_true")
    plan.add_argument("--main-sha", default=None)
    plan.add_argument("--skip-governance", action="store_true")
    plan.set_defaults(func=cmd_plan)
    disp = sub.add_parser("dispatch", help="H4 revalidate+CAS+WorkerRun (dry-run default)")
    disp.add_argument("--program", default=None)
    disp.add_argument("--plan-json", default=None, help="PlanResult JSON path")
    disp.add_argument("--worker", default="harness-worker")
    disp.add_argument("--apply", action="store_true")
    disp.add_argument("--main-sha", default=None)
    disp.add_argument("--skip-reclaim", action="store_true")
    disp.add_argument(
        "--spawn-exec", action="store_true",
        help="Pass dry_run=False to launcher (still no CLI unless agent-start given one)",
    )
    disp.add_argument("--heartbeat", default=None, help="attempt_id to renew leases")
    disp.add_argument("--release", default=None, help="attempt_id for PR_READY lease release")
    disp.add_argument("--handoff-json", default=None)
    disp.add_argument("--ingest", default=None, help="handoff claim JSON path")
    disp.set_defaults(func=cmd_dispatch)
    return p


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    return int(args.func(args))
