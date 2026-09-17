"""SQLite durable store for Programs, Tasks, leases, goals, receipts, checkpoints."""

from __future__ import annotations

import json
import os
import sqlite3
from pathlib import Path
from typing import Any, Iterable

from .contracts import Checkpoint, DecisionReceipt, GoalContract
from .models import Escalation, Program, Task
from .states import TaskState

DEFAULT_DB = Path("/home/jerry/workspace/state/alltrue/harness.sqlite")
SCHEMA_VERSION = 4


def default_db_path() -> Path:
    env = os.environ.get("HARNESS_DB")
    return Path(env) if env else DEFAULT_DB


class HarnessStore:
    def __init__(self, db_path: Path | str | None = None) -> None:
        self.db_path = Path(db_path) if db_path else default_db_path()
        self.db_path.parent.mkdir(parents=True, exist_ok=True)
        # isolation_level=None enables explicit BEGIN IMMEDIATE for lease CAS.
        self._conn = sqlite3.connect(str(self.db_path), timeout=30, isolation_level=None)
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
            CREATE TABLE IF NOT EXISTS leases (
              lease_id TEXT PRIMARY KEY,
              resource_key TEXT NOT NULL UNIQUE,
              holder_task_id TEXT NOT NULL,
              holder_worker TEXT NOT NULL,
              expires_at TEXT NOT NULL,
              fencing_token INTEGER NOT NULL,
              payload TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS goals (
              goal_id TEXT PRIMARY KEY,
              program_id TEXT NOT NULL,
              task_id TEXT NOT NULL,
              subject_sha TEXT NOT NULL,
              payload TEXT NOT NULL,
              updated_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_goals_task ON goals(task_id);
            CREATE TABLE IF NOT EXISTS decision_receipts (
              receipt_id TEXT PRIMARY KEY,
              goal_id TEXT NOT NULL,
              subject_sha TEXT NOT NULL,
              status TEXT NOT NULL,
              payload TEXT NOT NULL,
              updated_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_receipts_goal ON decision_receipts(goal_id);
            CREATE TABLE IF NOT EXISTS checkpoints (
              checkpoint_id TEXT PRIMARY KEY,
              task_id TEXT NOT NULL,
              goal_id TEXT NOT NULL,
              task_state TEXT NOT NULL,
              payload TEXT NOT NULL,
              created_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_checkpoints_task ON checkpoints(task_id);
            CREATE TABLE IF NOT EXISTS dispatch_attempts (
              attempt_id TEXT PRIMARY KEY,
              plan_id TEXT NOT NULL,
              task_id TEXT NOT NULL,
              goal_id TEXT NOT NULL,
              goal_contract_fingerprint TEXT NOT NULL,
              status TEXT NOT NULL,
              worker TEXT NOT NULL,
              payload TEXT NOT NULL,
              created_at TEXT NOT NULL,
              updated_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_dispatch_plan ON dispatch_attempts(plan_id);
            CREATE INDEX IF NOT EXISTS idx_dispatch_task ON dispatch_attempts(task_id);
            CREATE TABLE IF NOT EXISTS worker_runs (
              run_id TEXT PRIMARY KEY,
              attempt_id TEXT NOT NULL,
              task_id TEXT NOT NULL,
              mode TEXT NOT NULL,
              status TEXT NOT NULL,
              session_id TEXT NOT NULL,
              worktree_path TEXT NOT NULL,
              branch TEXT NOT NULL,
              worker TEXT NOT NULL,
              fencing_token INTEGER,
              lease_binding TEXT NOT NULL,
              payload TEXT NOT NULL,
              created_at TEXT NOT NULL,
              updated_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_worker_runs_attempt ON worker_runs(attempt_id);
            CREATE INDEX IF NOT EXISTS idx_worker_runs_task ON worker_runs(task_id);
            CREATE INDEX IF NOT EXISTS idx_worker_runs_session ON worker_runs(session_id);
            """
        )
        # At most one open mutation attempt per task (H4 duplicate-launch guard).
        cur.execute(
            """
            CREATE UNIQUE INDEX IF NOT EXISTS idx_dispatch_open_task
              ON dispatch_attempts(task_id)
              WHERE status IN ('pending','acquired','active')
            """
        )
        # At most one open WorkerRun per attempt (H4b single canonical child).
        cur.execute(
            """
            CREATE UNIQUE INDEX IF NOT EXISTS idx_worker_run_open_attempt
              ON worker_runs(attempt_id)
              WHERE status IN ('starting','running')
            """
        )
        cur.execute(
            "INSERT INTO meta(key, value) VALUES('schema_version', ?) "
            "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
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

    def _lease_row(self, row: sqlite3.Row | None) -> dict[str, Any] | None:
        if not row:
            return None
        out = dict(row)
        if isinstance(out.get("payload"), str):
            out["payload"] = json.loads(out["payload"] or "{}")
        return out

    def get_lease(self, resource_key: str) -> dict[str, Any] | None:
        return self._lease_row(self._conn.execute(
            "SELECT * FROM leases WHERE resource_key = ?", (resource_key,)
        ).fetchone())

    def put_lease(self, lease: dict[str, Any]) -> None:
        """Test/seed helper only — production paths must use cas_* methods."""
        self._conn.execute(
            """
            INSERT INTO leases(lease_id, resource_key, holder_task_id, holder_worker,
              expires_at, fencing_token, payload)
            VALUES(?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(resource_key) DO UPDATE SET
              lease_id=excluded.lease_id, holder_task_id=excluded.holder_task_id,
              holder_worker=excluded.holder_worker, expires_at=excluded.expires_at,
              fencing_token=excluded.fencing_token, payload=excluded.payload
            """,
            (lease["lease_id"], lease["resource_key"], lease["holder_task_id"],
             lease["holder_worker"], lease["expires_at"], lease["fencing_token"],
             json.dumps(lease.get("payload") or {})),
        )
        self._conn.commit()

    def delete_lease(self, resource_key: str) -> None:
        """Test helper — prefer cas_release_lease / cas_reclaim_stale_leases."""
        self._conn.execute("DELETE FROM leases WHERE resource_key = ?", (resource_key,))
        self._conn.commit()

    def list_leases(self) -> list[dict[str, Any]]:
        return [
            self._lease_row(r)  # type: ignore[misc]
            for r in self._conn.execute("SELECT * FROM leases ORDER BY resource_key")
        ]

    def cas_acquire_lease(self, lease: dict[str, Any], *, now_iso: str) -> dict[str, Any]:
        """Atomic initial acquire or take-over of an expired lease. Raises LeaseBusyError."""
        from .leases import LeaseBusyError, LeaseError

        key = lease["resource_key"]
        self._conn.execute("BEGIN IMMEDIATE")
        try:
            row = self._lease_row(self._conn.execute(
                "SELECT * FROM leases WHERE resource_key = ?", (key,)
            ).fetchone())
            if row is None:
                self._conn.execute(
                    """
                    INSERT INTO leases(lease_id, resource_key, holder_task_id, holder_worker,
                      expires_at, fencing_token, payload)
                    VALUES(?, ?, ?, ?, ?, ?, ?)
                    """,
                    (lease["lease_id"], key, lease["holder_task_id"], lease["holder_worker"],
                     lease["expires_at"], 1, json.dumps(lease.get("payload") or {})),
                )
                self._conn.commit()
                out = dict(lease)
                out["fencing_token"] = 1
                return out
            if str(row["expires_at"]) > now_iso:
                self._conn.rollback()
                raise LeaseBusyError(
                    f"{key} held by task={row['holder_task_id']} "
                    f"worker={row['holder_worker']} until {row['expires_at']}"
                )
            # Expired: conditional replace by exact identity + fencing + expiry
            new_fencing = int(row["fencing_token"]) + 1
            cur = self._conn.execute(
                """
                UPDATE leases SET
                  lease_id=?, holder_task_id=?, holder_worker=?,
                  expires_at=?, fencing_token=?, payload=?
                WHERE resource_key=? AND lease_id=? AND fencing_token=? AND expires_at<=?
                """,
                (lease["lease_id"], lease["holder_task_id"], lease["holder_worker"],
                 lease["expires_at"], new_fencing, json.dumps(lease.get("payload") or {}),
                 key, row["lease_id"], row["fencing_token"], now_iso),
            )
            if cur.rowcount != 1:
                self._conn.rollback()
                raise LeaseBusyError(f"{key} reacquire lost race")
            self._conn.commit()
            out = dict(lease)
            out["fencing_token"] = new_fencing
            return out
        except LeaseBusyError:
            raise
        except Exception:
            self._conn.rollback()
            raise LeaseError("cas_acquire_lease failed")

    def cas_renew_lease(
        self,
        resource_key: str,
        *,
        lease_id: str,
        fencing_token: int,
        task_id: str,
        worker: str,
        expires_at: str,
        now_iso: str,
    ) -> dict[str, Any]:
        from .leases import LeaseError

        self._conn.execute("BEGIN IMMEDIATE")
        try:
            new_fencing = int(fencing_token) + 1
            payload = json.dumps({"renewed_at": now_iso})
            cur = self._conn.execute(
                """
                UPDATE leases SET
                  holder_worker=?, expires_at=?, fencing_token=?, payload=?
                WHERE resource_key=? AND lease_id=? AND fencing_token=?
                  AND holder_task_id=? AND expires_at>?
                """,
                (worker, expires_at, new_fencing, payload,
                 resource_key, lease_id, fencing_token, task_id, now_iso),
            )
            if cur.rowcount != 1:
                self._conn.rollback()
                raise LeaseError("stale lease identity/fencing on renew")
            self._conn.commit()
            row = self.get_lease(resource_key)
            if not row:
                raise LeaseError("lease missing after renew")
            return row
        except LeaseError:
            raise
        except Exception:
            self._conn.rollback()
            raise LeaseError("cas_renew_lease failed")

    def cas_release_lease(
        self,
        resource_key: str,
        *,
        lease_id: str,
        fencing_token: int,
        task_id: str,
    ) -> None:
        from .leases import LeaseError

        self._conn.execute("BEGIN IMMEDIATE")
        try:
            cur = self._conn.execute(
                """
                DELETE FROM leases
                WHERE resource_key=? AND lease_id=? AND fencing_token=? AND holder_task_id=?
                """,
                (resource_key, lease_id, fencing_token, task_id),
            )
            if cur.rowcount != 1:
                self._conn.rollback()
                raise LeaseError("stale lease identity/fencing on release")
            self._conn.commit()
        except LeaseError:
            raise
        except Exception:
            self._conn.rollback()
            raise LeaseError("cas_release_lease failed")

    def cas_reclaim_stale_leases(self, *, now_iso: str) -> list[str]:
        """Delete only leases that remain expired under the same lease_id+fencing."""
        self._conn.execute("BEGIN IMMEDIATE")
        try:
            rows = list(self._conn.execute(
                "SELECT resource_key, lease_id, fencing_token, expires_at FROM leases"
            ))
            reclaimed: list[str] = []
            for r in rows:
                if str(r["expires_at"]) > now_iso:
                    continue
                cur = self._conn.execute(
                    """
                    DELETE FROM leases
                    WHERE resource_key=? AND lease_id=? AND fencing_token=? AND expires_at<=?
                    """,
                    (r["resource_key"], r["lease_id"], r["fencing_token"], now_iso),
                )
                if cur.rowcount == 1:
                    reclaimed.append(r["resource_key"])
            self._conn.commit()
            return reclaimed
        except Exception:
            self._conn.rollback()
            raise

    def put_goal(self, goal: GoalContract) -> None:
        self._conn.execute(
            """
            INSERT INTO goals(goal_id, program_id, task_id, subject_sha, payload, updated_at)
            VALUES(?, ?, ?, ?, ?, ?)
            ON CONFLICT(goal_id) DO UPDATE SET
              program_id=excluded.program_id, task_id=excluded.task_id,
              subject_sha=excluded.subject_sha, payload=excluded.payload,
              updated_at=excluded.updated_at
            """,
            (goal.goal_id, goal.program_id, goal.task_id, goal.subject_sha,
             json.dumps(goal.to_dict()), goal.updated_at),
        )
        self._conn.commit()

    def get_goal(self, goal_id: str) -> GoalContract | None:
        row = self._conn.execute(
            "SELECT payload FROM goals WHERE goal_id = ?", (goal_id,)
        ).fetchone()
        return GoalContract.from_dict(json.loads(row["payload"])) if row else None

    def list_goals(self, task_id: str | None = None) -> list[GoalContract]:
        if task_id:
            rows = self._conn.execute(
                "SELECT payload FROM goals WHERE task_id = ? ORDER BY goal_id", (task_id,)
            ).fetchall()
        else:
            rows = self._conn.execute("SELECT payload FROM goals ORDER BY goal_id").fetchall()
        return [GoalContract.from_dict(json.loads(r["payload"])) for r in rows]

    def put_decision_receipt(self, receipt: DecisionReceipt) -> None:
        self._conn.execute(
            """
            INSERT INTO decision_receipts(receipt_id, goal_id, subject_sha, status, payload, updated_at)
            VALUES(?, ?, ?, ?, ?, ?)
            ON CONFLICT(receipt_id) DO UPDATE SET
              goal_id=excluded.goal_id, subject_sha=excluded.subject_sha,
              status=excluded.status, payload=excluded.payload, updated_at=excluded.updated_at
            """,
            (receipt.receipt_id, receipt.goal_id, receipt.subject_sha, receipt.status,
             json.dumps(receipt.to_dict()), receipt.decided_at),
        )
        self._conn.commit()

    def get_decision_receipt(self, receipt_id: str) -> DecisionReceipt | None:
        row = self._conn.execute(
            "SELECT payload FROM decision_receipts WHERE receipt_id = ?", (receipt_id,)
        ).fetchone()
        return DecisionReceipt.from_dict(json.loads(row["payload"])) if row else None

    def put_checkpoint(self, checkpoint: Checkpoint) -> None:
        self._conn.execute(
            """
            INSERT INTO checkpoints(checkpoint_id, task_id, goal_id, task_state, payload, created_at)
            VALUES(?, ?, ?, ?, ?, ?)
            ON CONFLICT(checkpoint_id) DO UPDATE SET
              task_id=excluded.task_id, goal_id=excluded.goal_id,
              task_state=excluded.task_state, payload=excluded.payload,
              created_at=excluded.created_at
            """,
            (checkpoint.checkpoint_id, checkpoint.task_id, checkpoint.goal_id,
             checkpoint.task_state, json.dumps(checkpoint.to_dict()), checkpoint.created_at),
        )
        self._conn.commit()

    def get_checkpoint(self, checkpoint_id: str) -> Checkpoint | None:
        row = self._conn.execute(
            "SELECT payload FROM checkpoints WHERE checkpoint_id = ?", (checkpoint_id,)
        ).fetchone()
        return Checkpoint.from_dict(json.loads(row["payload"])) if row else None

    def put_dispatch_attempt(self, attempt: dict[str, Any]) -> None:
        """Insert or update a dispatch attempt. Open-task uniqueness enforced by index."""
        self._conn.execute(
            """
            INSERT INTO dispatch_attempts(
              attempt_id, plan_id, task_id, goal_id, goal_contract_fingerprint,
              status, worker, payload, created_at, updated_at
            ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(attempt_id) DO UPDATE SET
              status=excluded.status, worker=excluded.worker, payload=excluded.payload,
              updated_at=excluded.updated_at
            """,
            (
                attempt["attempt_id"], attempt["plan_id"], attempt["task_id"],
                attempt["goal_id"], attempt["goal_contract_fingerprint"],
                attempt["status"], attempt["worker"],
                json.dumps(attempt.get("payload") or {}),
                attempt["created_at"], attempt["updated_at"],
            ),
        )
        self._conn.commit()

    def insert_dispatch_attempt_open(self, attempt: dict[str, Any]) -> None:
        """Insert pending attempt; raises on duplicate open task (exactly-one winner)."""
        self._conn.execute("BEGIN IMMEDIATE")
        try:
            self._conn.execute(
                """
                INSERT INTO dispatch_attempts(
                  attempt_id, plan_id, task_id, goal_id, goal_contract_fingerprint,
                  status, worker, payload, created_at, updated_at
                ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    attempt["attempt_id"], attempt["plan_id"], attempt["task_id"],
                    attempt["goal_id"], attempt["goal_contract_fingerprint"],
                    attempt["status"], attempt["worker"],
                    json.dumps(attempt.get("payload") or {}),
                    attempt["created_at"], attempt["updated_at"],
                ),
            )
            self._conn.commit()
        except sqlite3.IntegrityError as exc:
            self._conn.rollback()
            raise RuntimeError(f"dispatch_attempt_conflict:{attempt.get('task_id')}") from exc
        except Exception:
            self._conn.rollback()
            raise

    def get_dispatch_attempt(self, attempt_id: str) -> dict[str, Any] | None:
        row = self._conn.execute(
            "SELECT * FROM dispatch_attempts WHERE attempt_id = ?", (attempt_id,)
        ).fetchone()
        if not row:
            return None
        return {
            "attempt_id": row["attempt_id"],
            "plan_id": row["plan_id"],
            "task_id": row["task_id"],
            "goal_id": row["goal_id"],
            "goal_contract_fingerprint": row["goal_contract_fingerprint"],
            "status": row["status"],
            "worker": row["worker"],
            "payload": json.loads(row["payload"] or "{}"),
            "created_at": row["created_at"],
            "updated_at": row["updated_at"],
        }

    def list_open_dispatch_attempts(self, task_id: str | None = None) -> list[dict[str, Any]]:
        q = (
            "SELECT * FROM dispatch_attempts WHERE status IN ('pending','acquired','active')"
        )
        args: tuple[Any, ...] = ()
        if task_id:
            q += " AND task_id = ?"
            args = (task_id,)
        q += " ORDER BY created_at"
        out = []
        for row in self._conn.execute(q, args):
            out.append({
                "attempt_id": row["attempt_id"],
                "plan_id": row["plan_id"],
                "task_id": row["task_id"],
                "goal_id": row["goal_id"],
                "goal_contract_fingerprint": row["goal_contract_fingerprint"],
                "status": row["status"],
                "worker": row["worker"],
                "payload": json.loads(row["payload"] or "{}"),
                "created_at": row["created_at"],
                "updated_at": row["updated_at"],
            })
        return out

    def _row_worker_run(self, row: sqlite3.Row) -> dict[str, Any]:
        return {
            "run_id": row["run_id"],
            "attempt_id": row["attempt_id"],
            "task_id": row["task_id"],
            "mode": row["mode"],
            "status": row["status"],
            "session_id": row["session_id"],
            "worktree_path": row["worktree_path"],
            "branch": row["branch"],
            "worker": row["worker"],
            "fencing_token": row["fencing_token"],
            "lease_binding": json.loads(row["lease_binding"] or "{}"),
            "payload": json.loads(row["payload"] or "{}"),
            "created_at": row["created_at"],
            "updated_at": row["updated_at"],
        }

    def put_worker_run(self, run: dict[str, Any]) -> None:
        """Insert or update a WorkerRun row (H4b durable child/session identity)."""
        self._conn.execute(
            """
            INSERT INTO worker_runs(
              run_id, attempt_id, task_id, mode, status, session_id,
              worktree_path, branch, worker, fencing_token, lease_binding,
              payload, created_at, updated_at
            ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(run_id) DO UPDATE SET
              status=excluded.status,
              session_id=excluded.session_id,
              worktree_path=excluded.worktree_path,
              branch=excluded.branch,
              worker=excluded.worker,
              fencing_token=excluded.fencing_token,
              lease_binding=excluded.lease_binding,
              payload=excluded.payload,
              updated_at=excluded.updated_at
            """,
            (
                run["run_id"], run["attempt_id"], run["task_id"], run["mode"],
                run["status"], run.get("session_id") or "",
                run.get("worktree_path") or "", run.get("branch") or "",
                run.get("worker") or "", run.get("fencing_token"),
                json.dumps(run.get("lease_binding") or {}),
                json.dumps(run.get("payload") or {}),
                run["created_at"], run["updated_at"],
            ),
        )
        self._conn.commit()

    def get_worker_run(self, run_id: str) -> dict[str, Any] | None:
        row = self._conn.execute(
            "SELECT * FROM worker_runs WHERE run_id = ?", (run_id,)
        ).fetchone()
        return self._row_worker_run(row) if row else None

    def list_worker_runs(
        self,
        *,
        attempt_id: str | None = None,
        task_id: str | None = None,
        session_id: str | None = None,
    ) -> list[dict[str, Any]]:
        clauses: list[str] = []
        args: list[Any] = []
        if attempt_id:
            clauses.append("attempt_id = ?")
            args.append(attempt_id)
        if task_id:
            clauses.append("task_id = ?")
            args.append(task_id)
        if session_id:
            clauses.append("session_id = ?")
            args.append(session_id)
        q = "SELECT * FROM worker_runs"
        if clauses:
            q += " WHERE " + " AND ".join(clauses)
        q += " ORDER BY created_at"
        return [self._row_worker_run(r) for r in self._conn.execute(q, tuple(args))]
