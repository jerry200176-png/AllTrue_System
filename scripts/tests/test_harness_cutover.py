"""Explicit additive schema migration + projection tests (cutover)."""

from __future__ import annotations

import json
import sqlite3
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.harness.project_state import build_projection, write_projection  # noqa: E402
from scripts.harness.schema_migrate import (  # noqa: E402
    TARGET_SCHEMA_VERSION,
    migrate_copy,
    schema_version,
    table_names,
)
from scripts.harness.store import HarnessStore  # noqa: E402


def _make_v1_db(path: Path) -> None:
    conn = sqlite3.connect(str(path))
    conn.executescript(
        """
        CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE programs (
          program_id TEXT PRIMARY KEY, payload TEXT NOT NULL, updated_at TEXT NOT NULL);
        CREATE TABLE tasks (
          task_id TEXT PRIMARY KEY, program_id TEXT NOT NULL, status TEXT NOT NULL,
          payload TEXT NOT NULL, updated_at TEXT NOT NULL);
        CREATE TABLE leases (
          lease_id TEXT PRIMARY KEY, resource_key TEXT NOT NULL UNIQUE,
          holder_task_id TEXT NOT NULL, holder_worker TEXT NOT NULL,
          expires_at TEXT NOT NULL, fencing_token INTEGER NOT NULL, payload TEXT NOT NULL);
        CREATE TABLE escalations (
          escalation_id TEXT PRIMARY KEY, dedupe_key TEXT NOT NULL, status TEXT NOT NULL,
          payload TEXT NOT NULL, updated_at TEXT NOT NULL);
        CREATE TABLE transitions (
          id INTEGER PRIMARY KEY AUTOINCREMENT, task_id TEXT NOT NULL,
          from_state TEXT NOT NULL, to_state TEXT NOT NULL, actor TEXT NOT NULL,
          evidence TEXT NOT NULL, created_at TEXT NOT NULL);
        INSERT INTO meta VALUES('schema_version','1');
        INSERT INTO meta VALUES('note','keep-me');
        INSERT INTO programs VALUES('p1','{"program_id":"p1"}','2026-09-17T00:00:00Z');
        INSERT INTO tasks VALUES('t1','p1','READY','{"task_id":"t1"}','2026-09-17T00:00:00Z');
        INSERT INTO leases VALUES('l1','r1','t1','w1','2099-01-01T00:00:00Z',7,'{}');
        INSERT INTO transitions VALUES(NULL,'t1','DISCOVERED','READY','test','{}','2026-09-17T00:00:00Z');
        """
    )
    conn.commit()
    conn.close()


class SchemaMigrateCutoverTest(unittest.TestCase):
    def test_migrate_copy_additive(self):
        with tempfile.TemporaryDirectory() as td:
            src = Path(td) / "v1.sqlite"
            dst = Path(td) / "v4.sqlite"
            _make_v1_db(src)
            result = migrate_copy(src, dst)
            self.assertTrue(result.ok)
            self.assertEqual(result.report["before_version"], 1)
            self.assertEqual(result.report["after_version"], TARGET_SCHEMA_VERSION)
            self.assertTrue(result.report["additive"])
            for t in ("goals", "decision_receipts", "checkpoints",
                      "dispatch_attempts", "worker_runs"):
                self.assertIn(t, result.report["after_tables"])
            conn = sqlite3.connect(str(dst))
            conn.row_factory = sqlite3.Row
            self.assertEqual(schema_version(conn), 4)
            self.assertEqual(
                conn.execute("SELECT value FROM meta WHERE key='note'").fetchone()[0],
                "keep-me",
            )
            self.assertEqual(
                conn.execute("SELECT COUNT(*) FROM tasks").fetchone()[0], 1,
            )
            self.assertEqual(
                conn.execute("SELECT fencing_token FROM leases").fetchone()[0], 7,
            )
            conn.close()

    def test_projection_marks_non_authoritative(self):
        with tempfile.TemporaryDirectory() as td:
            db = Path(td) / "h.sqlite"
            out = Path(td) / "CURRENT_STATE.json"
            store = HarnessStore(db)
            payload = write_projection(store, out, backup_existing=False)
            self.assertEqual(payload["role"], "projection")
            self.assertTrue(payload["not_authoritative"])
            self.assertEqual(payload["authority"], "harness.sqlite")
            disk = json.loads(out.read_text(encoding="utf-8"))
            self.assertEqual(disk["role"], "projection")
            built = build_projection(store)
            self.assertEqual(built["authority"], "harness.sqlite")
            store.close()


if __name__ == "__main__":
    unittest.main()
