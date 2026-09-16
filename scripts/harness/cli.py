"""CLI: status / plan / sync / founder-inbox / resume."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

from .execution import prepare_task_execution
from .leases import reclaim_stale
from .loop import tick_program
from .planner import select_across_programs, select_next_task
from .programs_loader import sync_programs_to_store
from .states import ACTIVE_MUTATING
from .store import HarnessStore, default_db_path
from .worker import dispatch_task


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
    programs = store.list_programs()
    rows: list[dict[str, Any]] = []
    for prog in programs:
        tasks = store.list_tasks(program_id=prog.program_id)
        active = [t for t in tasks if t.status in ACTIVE_MUTATING]
        current = None
        if prog.current_task_id:
            current = store.get_task(prog.current_task_id)
        if current is None and active:
            current = active[0]
        if current is None:
            ready = [t for t in tasks if t.status.value == "READY"]
            current = ready[0] if ready else (tasks[0] if tasks else None)
        rows.append(
            {
                "PROGRAM": prog.name,
                "program_id": prog.program_id,
                "CURRENT_TASK": current.task_id if current else "-",
                "STATE": current.status.value if current else (
                    "BLOCKED_HUMAN" if prog.blockers else "IDLE"
                ),
                "OWNER": current.assignee if current and current.assignee else prog.owner,
                "blockers": prog.blockers or ([current.blocker] if current and current.blocker else []),
                "next_action": (current.next_action if current else prog.next_action) or "-",
            }
        )

    # Operator table
    headers = ("PROGRAM", "CURRENT_TASK", "STATE", "OWNER")
    widths = {h: len(h) for h in headers}
    for row in rows:
        for h in headers:
            widths[h] = max(widths[h], len(str(row[h])))
    line = "  ".join(h.ljust(widths[h]) for h in headers)
    print(line)
    print("  ".join("-" * widths[h] for h in headers))
    for row in rows:
        print("  ".join(str(row[h]).ljust(widths[h]) for h in headers))

    leases = store.list_leases()
    esc = store.list_open_escalations()
    print()
    print(f"db={store.db_path}")
    print(f"active_leases={len(leases)} open_founder_escalations={len(esc)}")
    if args.json:
        print(json.dumps({"programs": rows, "leases": leases, "escalations": [e.to_dict() for e in esc]}, indent=2))
    return 0


def cmd_plan(args: argparse.Namespace) -> int:
    store = _store(args)
    if args.sync:
        sync_programs_to_store(store)
    if args.program:
        prog = store.get_program(args.program)
        if not prog:
            print(f"unknown program: {args.program}", file=sys.stderr)
            return 2
        result = select_next_task(store, prog, dry_run=True)
        print(json.dumps(result.to_dict(), indent=2))
        return 0
    programs = store.list_programs()
    results = select_across_programs(store, programs, dry_run=True)
    print(json.dumps([r.to_dict() for r in results], indent=2))
    return 0


def cmd_founder_inbox(args: argparse.Namespace) -> int:
    store = _store(args)
    items = [e.to_dict() for e in store.list_open_escalations()]
    print(json.dumps({"count": len(items), "escalations": items}, indent=2))
    return 0


def cmd_prepare(args: argparse.Namespace) -> int:
    store = _store(args)
    if args.sync:
        sync_programs_to_store(store)
    task = store.get_task(args.task)
    if not task:
        print(json.dumps({"ok": False, "error": "unknown_task", "task": args.task}))
        return 2
    prepared = prepare_task_execution(
        store,
        task,
        worker=args.worker,
        create_worktree=args.create_worktree,
        dry_run=True,
    )
    print(json.dumps(prepared.to_dict(), indent=2))
    return 0 if prepared.skipped_reason is None else 1


def cmd_dispatch(args: argparse.Namespace) -> int:
    store = _store(args)
    task = store.get_task(args.task)
    if not task:
        print(json.dumps({"ok": False, "error": "unknown_task"}))
        return 2
    result = dispatch_task(store, task, enable_codex=args.codex)
    print(json.dumps(result, indent=2, default=str))
    return 0 if result.get("ok") else 1


def cmd_tick(args: argparse.Namespace) -> int:
    store = _store(args)
    if args.sync:
        sync_programs_to_store(store)
    reclaim_stale(store)
    if args.program:
        programs = [store.get_program(args.program)]
        if programs[0] is None:
            print(json.dumps({"ok": False, "error": "unknown_program"}))
            return 2
    else:
        programs = store.list_programs()
    results = []
    for prog in programs:
        results.append(
            tick_program(
                store,
                prog,
                worker=args.worker,
                create_worktree=args.create_worktree,
                enable_codex=False,
            ).to_dict()
        )
    print(json.dumps({"ticks": results}, indent=2, default=str))
    return 0


def cmd_resume(args: argparse.Namespace) -> int:
    """Crash/resume reconstruction summary from durable state."""
    store = _store(args)
    if args.sync:
        sync_programs_to_store(store)
    reclaimed = reclaim_stale(store)
    programs = store.list_programs()
    running = []
    waiting = []
    founder = []
    next_exec = []
    for prog in programs:
        plan = select_next_task(store, prog, dry_run=True)
        for task in store.list_tasks(program_id=prog.program_id):
            if task.status in ACTIVE_MUTATING:
                running.append({"program": prog.program_id, "task": task.task_id, "state": task.status.value})
            elif task.status.value == "FOUNDER_REQUIRED":
                founder.append({"program": prog.program_id, "task": task.task_id})
            elif task.status.value in {"CI_PENDING", "BLOCKED", "STAGING_PENDING", "PRODUCTION_PENDING"}:
                waiting.append({"program": prog.program_id, "task": task.task_id, "state": task.status.value})
        if plan.would_execute and plan.selected:
            next_exec.append(plan.to_dict())
    payload = {
        "programs": [p.program_id for p in programs],
        "running": running,
        "waiting": waiting,
        "founder_required": founder,
        "next_executable": next_exec,
        "reclaimed_stale_leases": reclaimed,
        "db": str(store.db_path),
    }
    print(json.dumps(payload, indent=2))
    return 0


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="python3 -m scripts.harness",
        description="AllTrue Autonomous Execution Harness (orchestration; not governance)",
    )
    p.add_argument("--db", default=None, help=f"SQLite path (default {default_db_path()})")
    sub = p.add_subparsers(dest="command", required=True)

    sync = sub.add_parser("sync", help="Load program YAML contracts into durable store")
    sync.add_argument("--refresh-tasks", action="store_true", help="Overwrite existing tasks from YAML")
    sync.set_defaults(func=cmd_sync)

    st = sub.add_parser("status", help="Operator status table")
    st.add_argument("--sync", action="store_true", help="Sync YAML before status")
    st.add_argument("--json", action="store_true")
    st.set_defaults(func=cmd_status)

    plan = sub.add_parser("plan", help="Dry-run next-task selection")
    plan.add_argument("--program", default=None)
    plan.add_argument("--sync", action="store_true")
    plan.set_defaults(func=cmd_plan)

    inbox = sub.add_parser("founder-inbox", help="Open Founder escalations only")
    inbox.set_defaults(func=cmd_founder_inbox)

    prep = sub.add_parser("prepare", help="Acquire leases (+ optional worktree) for a READY task")
    prep.add_argument("--task", required=True)
    prep.add_argument("--worker", default="harness")
    prep.add_argument("--create-worktree", action="store_true")
    prep.add_argument("--sync", action="store_true")
    prep.set_defaults(func=cmd_prepare)

    disp = sub.add_parser("dispatch", help="Write worker Goal and mark EXECUTING (file-queue)")
    disp.add_argument("--task", required=True)
    disp.add_argument("--codex", action="store_true", help="Also invoke codex-route (opt-in)")
    disp.set_defaults(func=cmd_dispatch)

    tick = sub.add_parser("tick", help="H7/H8: one autonomous tick per program (file-queue)")
    tick.add_argument("--program", default=None, help="Limit to one program")
    tick.add_argument("--worker", default="harness")
    tick.add_argument("--create-worktree", action="store_true")
    tick.add_argument("--sync", action="store_true")
    tick.set_defaults(func=cmd_tick)

    resume = sub.add_parser("resume", help="Reconstruct state after crash/restart")
    resume.add_argument("--sync", action="store_true")
    resume.set_defaults(func=cmd_resume)
    return p


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    return int(args.func(args))
