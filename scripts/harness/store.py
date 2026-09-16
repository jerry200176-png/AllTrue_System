"""SQLite durable store for Programs, Tasks, leases, escalations, transition log."""

from __future__ import annotations

import json
import os
import sqlite3
from pathlib import Path
from typing import Any, Iterable

from .models import Escalation, Program, Task
from .states import TaskState

DEFAULT_DB = Path("/home/jerry/workspace/state/alltrue/harness.sqlite")
SCHEMA_VERSION = 1


def default_db_path() -> Path:
    env = os.environ.get("HARNESS_DB")
    return Path(env) if env else DEFAULT_DB


class HarnessStore:
    def __init__(self, db_path: Path | str | None = None) -> None:
        self.db_path = Path(db_path) if db_path else default_db_path()
        self.db_path.parent.mkdir(parents=True, exist_ok=True)
        self._conn = sqlite3.connect(str(self.db_path), timeout=30)
        self._conn.row_factory = sqlite3.Row
        self._conn.execute("PRAGMA journal_mode=WAL")
        self._conn.execute("PRAGMA foreign_keys=ON")
        self._migrate()

    def close(self) -> None:
        self._conn.close()

    def _migrate(self) -> None:
        cur = self._conn.cursor()
        cur.executescript(
            """
            CREATE TABLE IF NOT EXISTS meta (
              key TEXT PRIMARY KEY,
              value TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS programs (
              program_id TEXT PRIMARY KEY,
              payload TEXT NOT NULL,
              updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS tasks (
              task_id TEXT PRIMARY KEY,
              program_id TEXT NOT NULL,
              status TEXT NOT NULL,
              payload TEXT NOT NULL,
              updated_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_tasks_program ON tasks(program_id);
            CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status);
            CREATE TABLE IF NOT EXISTS leases (
              lease_id TEXT PRIMARY KEY,
              resource_key TEXT NOT NULL UNIQUE,
              holder_task_id TEXT NOT NULL,
              holder_worker TEXT NOT NULL,
              expires_at TEXT NOT NULL,
              fencing_token INTEGER NOT NULL,
              payload TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS escalations (
              escalation_id TEXT PRIMARY KEY,
              dedupe_key TEXT NOT NULL,
              status TEXT NOT NULL,
              payload TEXT NOT NULL,
              updated_at TEXT NOT NULL
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_escalation_open_dedupe
              ON escalations(dedupe_key) WHERE status = 'open';
            CREATE TABLE IF NOT EXISTS transitions (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              task_id TEXT NOT NULL,
              from_state TEXT NOT NULL,
              to_state TEXT NOT NULL,
              actor TEXT NOT NULL,
              evidence TEXT NOT NULL,
              created_at TEXT NOT NULL
            );
            """
        )
        cur.execute(
            "INSERT OR IGNORE INTO meta(key, value) VALUES('schema_version', ?)",
            (str(SCHEMA_VERSION),),
        )
        self._conn.commit()

    def upsert_program(self, program: Program) -> None:
        self._conn.execute(
            """
            INSERT INTO programs(program_id, payload, updated_at)
            VALUES(?, ?, ?)
            ON CONFLICT(program_id) DO UPDATE SET
              payload=excluded.payload,
              updated_at=excluded.updated_at
            """,
            (program.program_id, json.dumps(program.to_dict()), program.updated_at),
        )
        self._conn.commit()

    def get_program(self, program_id: str) -> Program | None:
        row = self._conn.execute(
            "SELECT payload FROM programs WHERE program_id = ?", (program_id,)
        ).fetchone()
        return Program.from_dict(json.loads(row["payload"])) if row else None

    def list_programs(self) -> list[Program]:
        rows = self._conn.execute(
            "SELECT payload FROM programs ORDER BY program_id"
        ).fetchall()
        return [Program.from_dict(json.loads(r["payload"])) for r in rows]

    def upsert_task(self, task: Task) -> None:
        self._conn.execute(
            """
            INSERT INTO tasks(task_id, program_id, status, payload, updated_at)
            VALUES(?, ?, ?, ?, ?)
            ON CONFLICT(task_id) DO UPDATE SET
              program_id=excluded.program_id,
              status=excluded.status,
              payload=excluded.payload,
              updated_at=excluded.updated_at
            """,
            (
                task.task_id,
                task.program_id,
                task.status.value,
                json.dumps(task.to_dict()),
                task.updated_at,
            ),
        )
        self._conn.commit()

    def get_task(self, task_id: str) -> Task | None:
        row = self._conn.execute(
            "SELECT payload FROM tasks WHERE task_id = ?", (task_id,)
        ).fetchone()
        return Task.from_dict(json.loads(row["payload"])) if row else None

    def list_tasks(
        self,
        program_id: str | None = None,
        statuses: Iterable[TaskState] | None = None,
    ) -> list[Task]:
        sql = "SELECT payload FROM tasks WHERE 1=1"
        params: list[Any] = []
        if program_id:
            sql += " AND program_id = ?"
            params.append(program_id)
        if statuses:
            marks = ",".join("?" for _ in statuses)
            sql += f" AND status IN ({marks})"
            params.extend(s.value for s in statuses)
        sql += " ORDER BY program_id, task_id"
        rows = self._conn.execute(sql, params).fetchall()
        return [Task.from_dict(json.loads(r["payload"])) for r in rows]

    def record_transition(
        self,
        task_id: str,
        from_state: TaskState,
        to_state: TaskState,
        actor: str,
        evidence: dict[str, Any],
        created_at: str,
    ) -> None:
        self._conn.execute(
            """
            INSERT INTO transitions(task_id, from_state, to_state, actor, evidence, created_at)
            VALUES(?, ?, ?, ?, ?, ?)
            """,
            (
                task_id,
                from_state.value,
                to_state.value,
                actor,
                json.dumps(evidence),
                created_at,
            ),
        )
        self._conn.commit()

    def list_transitions(self, task_id: str, limit: int = 50) -> list[dict[str, Any]]:
        rows = self._conn.execute(
            """
            SELECT task_id, from_state, to_state, actor, evidence, created_at
            FROM transitions WHERE task_id = ?
            ORDER BY id DESC LIMIT ?
            """,
            (task_id, limit),
        ).fetchall()
        out = []
        for r in rows:
            out.append(
                {
                    "task_id": r["task_id"],
                    "from_state": r["from_state"],
                    "to_state": r["to_state"],
                    "actor": r["actor"],
                    "evidence": json.loads(r["evidence"]),
                    "created_at": r["created_at"],
                }
            )
        return out

    def upsert_escalation(self, esc: Escalation) -> None:
        self._conn.execute(
            """
            INSERT INTO escalations(escalation_id, dedupe_key, status, payload, updated_at)
            VALUES(?, ?, ?, ?, ?)
            ON CONFLICT(escalation_id) DO UPDATE SET
              dedupe_key=excluded.dedupe_key,
              status=excluded.status,
              payload=excluded.payload,
              updated_at=excluded.updated_at
            """,
            (
                esc.escalation_id,
                esc.dedupe_key,
                esc.status,
                json.dumps(esc.to_dict()),
                esc.updated_at,
            ),
        )
        self._conn.commit()

    def find_open_escalation(self, dedupe_key: str) -> Escalation | None:
        row = self._conn.execute(
            """
            SELECT payload FROM escalations
            WHERE dedupe_key = ? AND status = 'open'
            """,
            (dedupe_key,),
        ).fetchone()
        return Escalation.from_dict(json.loads(row["payload"])) if row else None

    def list_open_escalations(self) -> list[Escalation]:
        rows = self._conn.execute(
            "SELECT payload FROM escalations WHERE status = 'open' ORDER BY updated_at"
        ).fetchall()
        return [Escalation.from_dict(json.loads(r["payload"])) for r in rows]

    # --- leases ---
    def get_lease(self, resource_key: str) -> dict[str, Any] | None:
        row = self._conn.execute(
            "SELECT * FROM leases WHERE resource_key = ?", (resource_key,)
        ).fetchone()
        return dict(row) if row else None

    def put_lease(self, lease: dict[str, Any]) -> None:
        self._conn.execute(
            """
            INSERT INTO leases(
              lease_id, resource_key, holder_task_id, holder_worker,
              expires_at, fencing_token, payload
            ) VALUES(?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(resource_key) DO UPDATE SET
              lease_id=excluded.lease_id,
              holder_task_id=excluded.holder_task_id,
              holder_worker=excluded.holder_worker,
              expires_at=excluded.expires_at,
              fencing_token=excluded.fencing_token,
              payload=excluded.payload
            """,
            (
                lease["lease_id"],
                lease["resource_key"],
                lease["holder_task_id"],
                lease["holder_worker"],
                lease["expires_at"],
                lease["fencing_token"],
                json.dumps(lease.get("payload") or {}),
            ),
        )
        self._conn.commit()

    def delete_lease(self, resource_key: str) -> None:
        self._conn.execute("DELETE FROM leases WHERE resource_key = ?", (resource_key,))
        self._conn.commit()

    def list_leases(self) -> list[dict[str, Any]]:
        rows = self._conn.execute("SELECT * FROM leases ORDER BY resource_key").fetchall()
        return [dict(r) for r in rows]
