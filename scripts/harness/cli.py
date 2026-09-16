"""CLI: sync / status / resume / founder-inbox / graph (H0–H2)."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

from .graph import build_graph
from .leases import reclaim_stale
from .programs_loader import sync_programs_to_store
from .reconcile import resume_from_checkpoint
from .states import ACTIVE_MUTATING
from .store import HarnessStore, default_db_path


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
    print(f"\ndb={store.db_path} founder_open={len(store.list_open_escalations())} "
          f"leases={len(store.list_leases())} goals={len(store.list_goals())}")
    if args.json:
        print(json.dumps({"programs": rows}, indent=2))
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
    }, indent=2))
    return 0


def cmd_graph(args: argparse.Namespace) -> int:
    print(json.dumps(build_graph(_store(args)).to_dict(), indent=2))
    return 0


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
    return p


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    return int(args.func(args))
