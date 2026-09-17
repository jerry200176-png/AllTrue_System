"""Explicit additive schema migration for harness.sqlite (v1 → v4).

This module is the *only* approved first-touch migration path for a live DB.
Do NOT open HarnessStore() against live as the first migration experiment —
HarnessStore.__init__ still calls _migrate() for empty/test DBs.

Additive only: CREATE TABLE/INDEX IF NOT EXISTS; never DROP or rewrite rows.
"""

from __future__ import annotations

import hashlib
import json
import sqlite3
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

TARGET_SCHEMA_VERSION = 4

V1_TABLES = ("meta", "programs", "tasks", "leases", "escalations", "transitions")
# Domain rows that must be byte-stable across additive migration (meta may gain keys).
V1_STABLE_TABLES = ("programs", "tasks", "leases", "escalations", "transitions")
V4_EXTRA_TABLES = (
    "goals",
    "decision_receipts",
    "checkpoints",
    "dispatch_attempts",
    "worker_runs",
)
META_OWNED_BY_MIGRATION = {
    "schema_version",
    "schema_migrated_at",
    "authority_role",
}


DDL_V4_ADDITIVE = """
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


def _now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def connect(path: Path | str) -> sqlite3.Connection:
    conn = sqlite3.connect(str(path), timeout=30)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys=ON")
    return conn


def schema_version(conn: sqlite3.Connection) -> int:
    names = {
        r[0]
        for r in conn.execute(
            "SELECT name FROM sqlite_master WHERE type='table'"
        ).fetchall()
    }
    if "meta" not in names:
        return 0
    row = conn.execute(
        "SELECT value FROM meta WHERE key='schema_version'"
    ).fetchone()
    if row is None:
        return 0
    try:
        return int(row["value"] if isinstance(row, sqlite3.Row) else row[0])
    except (TypeError, ValueError):
        return 0


def table_names(conn: sqlite3.Connection) -> list[str]:
    rows = conn.execute(
        "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' "
        "ORDER BY name"
    ).fetchall()
    return [r["name"] for r in rows]


def row_counts(conn: sqlite3.Connection) -> dict[str, int]:
    out: dict[str, int] = {}
    for name in table_names(conn):
        out[name] = int(conn.execute(f'SELECT COUNT(*) AS c FROM "{name}"').fetchone()["c"])
    return out


def logical_digest(conn: sqlite3.Connection, tables: tuple[str, ...] = V1_TABLES) -> str:
    """Stable digest of pre-v4 authority rows (order-independent per table)."""
    parts: list[str] = []
    for t in tables:
        if t not in table_names(conn):
            parts.append(f"{t}:MISSING")
            continue
        rows = conn.execute(f'SELECT * FROM "{t}"').fetchall()
        keys = list(rows[0].keys()) if rows else []
        serialized = json.dumps(
            sorted([[r[k] for k in keys] for r in rows], key=lambda x: json.dumps(x)),
            default=str,
        )
        parts.append(f"{t}:{hashlib.sha256(serialized.encode()).hexdigest()}")
    return hashlib.sha256("\n".join(parts).encode()).hexdigest()


def apply_additive_v4(conn: sqlite3.Connection) -> dict[str, Any]:
    """Apply additive DDL and set schema_version=4. Preserves all existing rows."""
    before_version = schema_version(conn)
    before_tables = table_names(conn)
    before_counts = row_counts(conn)
    existing_stable = tuple(t for t in V1_STABLE_TABLES if t in before_tables)
    before_digest = logical_digest(conn, existing_stable) if existing_stable else None
    before_meta = {}
    if "meta" in before_tables:
        before_meta = {
            r["key"]: r["value"]
            for r in conn.execute("SELECT key, value FROM meta")
            if r["key"] not in META_OWNED_BY_MIGRATION
        }

    conn.executescript(DDL_V4_ADDITIVE)
    conn.execute(
        """
        CREATE UNIQUE INDEX IF NOT EXISTS idx_dispatch_open_task
          ON dispatch_attempts(task_id)
          WHERE status IN ('pending','acquired','active')
        """
    )
    conn.execute(
        """
        CREATE UNIQUE INDEX IF NOT EXISTS idx_worker_run_open_attempt
          ON worker_runs(attempt_id)
          WHERE status IN ('starting','running')
        """
    )
    # Required v4 tables must exist before bumping version.
    missing = [t for t in (*V1_TABLES, *V4_EXTRA_TABLES) if t not in table_names(conn)]
    if missing:
        raise RuntimeError(f"migration incomplete; missing tables: {missing}")

    conn.execute(
        "INSERT INTO meta(key, value) VALUES('schema_version', ?) "
        "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
        (str(TARGET_SCHEMA_VERSION),),
    )
    conn.execute(
        "INSERT INTO meta(key, value) VALUES('schema_migrated_at', ?) "
        "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
        (_now(),),
    )
    conn.execute(
        "INSERT INTO meta(key, value) VALUES('authority_role', ?) "
        "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
        ("domain_authority_candidate",),
    )
    conn.commit()

    after_counts = row_counts(conn)
    after_digest = logical_digest(conn, existing_stable) if existing_stable else None
    preserved = {
        t: {"before": before_counts.get(t, 0), "after": after_counts.get(t, 0)}
        for t in existing_stable
    }
    for t, pair in preserved.items():
        if pair["before"] != pair["after"]:
            raise RuntimeError(f"non-additive migration: {t} counts changed {pair}")

    if existing_stable and before_digest != after_digest:
        raise RuntimeError(
            "non-additive migration: logical digest of v1 tables changed "
            f"{before_digest} → {after_digest}"
        )

    after_meta = {
        r["key"]: r["value"]
        for r in conn.execute("SELECT key, value FROM meta")
        if r["key"] not in META_OWNED_BY_MIGRATION
    }
    if before_meta != after_meta:
        raise RuntimeError(
            f"non-additive migration: non-owned meta keys changed {before_meta} → {after_meta}"
        )

    return {
        "ok": True,
        "before_version": before_version,
        "after_version": schema_version(conn),
        "before_tables": before_tables,
        "after_tables": table_names(conn),
        "v1_row_counts": preserved,
        "v1_logical_digest": before_digest,
        "preserved_meta_keys": sorted(before_meta.keys()),
        "new_tables": [t for t in table_names(conn) if t not in before_tables],
        "additive": True,
    }


@dataclass
class MigrateCopyResult:
    ok: bool
    src: str
    dst: str
    src_sha256: str
    dst_sha256_pre: str
    report: dict[str, Any]


def migrate_copy(src: Path, dst: Path) -> MigrateCopyResult:
    """Copy src → dst via sqlite backup, then migrate dst only."""
    src = Path(src)
    dst = Path(dst)
    if not src.is_file():
        raise FileNotFoundError(src)
    dst.parent.mkdir(parents=True, exist_ok=True)
    if dst.exists():
        dst.unlink()

    src_conn = connect(src)
    try:
        dst_conn = sqlite3.connect(str(dst))
        try:
            src_conn.backup(dst_conn)
        finally:
            dst_conn.close()
    finally:
        src_conn.close()

    src_sha = hashlib.sha256(src.read_bytes()).hexdigest()
    dst_sha_pre = hashlib.sha256(dst.read_bytes()).hexdigest()

    conn = connect(dst)
    try:
        integrity = conn.execute("PRAGMA integrity_check").fetchone()[0]
        if integrity != "ok":
            raise RuntimeError(f"copy integrity_check={integrity}")
        report = apply_additive_v4(conn)
        report["integrity_check"] = conn.execute("PRAGMA integrity_check").fetchone()[0]
    finally:
        conn.close()

    return MigrateCopyResult(
        ok=True,
        src=str(src),
        dst=str(dst),
        src_sha256=src_sha,
        dst_sha256_pre=dst_sha_pre,
        report=report,
    )


def migrate_live_explicit(live: Path, *, allow_live: bool = False) -> dict[str, Any]:
    """Migrate live DB in place. Requires allow_live=True after copy verification."""
    if not allow_live:
        raise RuntimeError("refusing live migrate without allow_live=True")
    live = Path(live)
    conn = connect(live)
    try:
        integrity = conn.execute("PRAGMA integrity_check").fetchone()[0]
        if integrity != "ok":
            raise RuntimeError(f"live integrity_check={integrity}")
        report = apply_additive_v4(conn)
        report["path"] = str(live)
        report["integrity_check"] = conn.execute("PRAGMA integrity_check").fetchone()[0]
        return report
    finally:
        conn.close()


__all__ = [
    "TARGET_SCHEMA_VERSION",
    "apply_additive_v4",
    "connect",
    "logical_digest",
    "migrate_copy",
    "migrate_live_explicit",
    "row_counts",
    "schema_version",
    "table_names",
]
