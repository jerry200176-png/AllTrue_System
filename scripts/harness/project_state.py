"""Project CURRENT_STATE.json from harness.sqlite (read model only).

Domain authority is harness.sqlite. This module never treats JSON as source of truth.
"""

from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from .store import HarnessStore

DEFAULT_PROJECTION = Path("/home/jerry/workspace/state/alltrue/CURRENT_STATE.json")


def _now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def build_projection(store: HarnessStore) -> dict[str, Any]:
    open_runs = [
        r for r in store.list_worker_runs()
        if r.get("status") in {"starting", "running", "deferred"}
    ]
    workers = []
    for r in open_runs:
        workers.append({
            "worker_id": r.get("worker") or "?",
            "status": r.get("status"),
            "task": r.get("task_id"),
            "run_id": r.get("run_id"),
            "attempt_id": r.get("attempt_id"),
            "session_id": r.get("session_id"),
            "worktree_path": r.get("worktree_path"),
            "branch": r.get("branch"),
            "fencing_token": r.get("fencing_token"),
            "source": "harness.sqlite.worker_runs",
        })

    meta = {
        row["key"]: row["value"]
        for row in store._conn.execute("SELECT key, value FROM meta")
    }
    return {
        "schema_version": 6,
        "role": "projection",
        "authority": "harness.sqlite",
        "authority_db": str(store.db_path),
        "authority_schema_version": int(meta.get("schema_version") or 0),
        "projected_at": _now(),
        "not_authoritative": True,
        "session_status": "PROJECTED_FROM_HARNESS",
        "workers": workers,
        "programs": [
            {
                "program_id": p.program_id,
                "name": p.name,
                "tasks": [
                    {"task_id": t.task_id, "status": t.status.value}
                    for t in store.list_tasks(program_id=p.program_id)
                ],
            }
            for p in store.list_programs()
        ],
        "open_escalations": len(store.list_open_escalations()),
        "leases": len(store.list_leases()),
        "worker_runs_open": open_runs,
        "meta_keys": sorted(meta.keys()),
    }


def write_projection(
    store: HarnessStore,
    path: Path | None = None,
    *,
    backup_existing: bool = True,
) -> dict[str, Any]:
    path = Path(path) if path else DEFAULT_PROJECTION
    payload = build_projection(store)
    path.parent.mkdir(parents=True, exist_ok=True)
    if backup_existing and path.is_file():
        bak = path.with_suffix(path.suffix + f".pre-projection-{_now().replace(':', '')}")
        bak.write_text(path.read_text(encoding="utf-8"), encoding="utf-8")
        payload["previous_projection_backup"] = str(bak)
    path.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    store._conn.execute(
        "INSERT INTO meta(key, value) VALUES('current_state_path', ?) "
        "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
        (str(path),),
    )
    store._conn.execute(
        "INSERT INTO meta(key, value) VALUES('current_state_role', ?) "
        "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
        ("projection",),
    )
    store._conn.execute(
        "INSERT INTO meta(key, value) VALUES('last_projection_at', ?) "
        "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
        (_now(),),
    )
    store._conn.commit()
    return payload


__all__ = ["DEFAULT_PROJECTION", "build_projection", "write_projection"]
