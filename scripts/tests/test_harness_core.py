"""H0–H1 harness tests — durable state + transitions (no production)."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.harness.models import Program, Task  # noqa: E402
from scripts.harness.states import TaskState  # noqa: E402
from scripts.harness.store import HarnessStore  # noqa: E402
from scripts.harness.transitions import (  # noqa: E402
    TransitionError,
    enqueue_founder_escalation,
    transition,
)


class HarnessH1Test(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.db = Path(self._tmp.name) / "harness.sqlite"
        self.store = HarnessStore(self.db)

    def tearDown(self) -> None:
        self.store.close()
        self._tmp.cleanup()

    def test_allowed_transition(self):
        task = Task(task_id="t1", program_id="p1", outcome="o", status=TaskState.READY)
        self.store.upsert_task(task)
        self.assertEqual(
            transition(self.store, task, TaskState.LEASED, actor="test").status,
            TaskState.LEASED,
        )

    def test_founder_escalation_dedupe(self):
        task = Task(task_id="t2", program_id="p1", outcome="o", status=TaskState.READY)
        self.store.upsert_task(task)
        kwargs = dict(
            decision_required="d", why="w", governance_rule="r",
            options=["a"], consequences="c", recommended_default="pause",
        )
        a = enqueue_founder_escalation(self.store, task, **kwargs)
        b = enqueue_founder_escalation(self.store, self.store.get_task("t2"), **kwargs)
        self.assertEqual(a.escalation_id, b.escalation_id)
        self.assertEqual(len(self.store.list_open_escalations()), 1)

    def test_missing_evidence_fail_closed(self):
        task = Task(task_id="t3", program_id="p1", outcome="o", status=TaskState.CI_PENDING)
        self.store.upsert_task(task)
        with self.assertRaises(TransitionError):
            transition(self.store, task, TaskState.CI_GREEN, actor="test", evidence={})

    def test_exact_sha_mismatch_fail_closed(self):
        task = Task(task_id="t4", program_id="p1", outcome="o", status=TaskState.CI_PENDING)
        self.store.upsert_task(task)
        with self.assertRaises(TransitionError):
            transition(
                self.store, task, TaskState.CI_GREEN, actor="test",
                evidence={"ci": {"ok": 1}, "exact_sha": "a" * 40, "observed_sha": "b" * 40},
            )

    def test_crash_resume(self):
        self.store.upsert_program(Program(
            program_id="p1", name="P", owner="o", repository="r",
            goal="g", canonical_status_source="docs/x.md",
        ))
        self.store.upsert_task(Task(
            task_id="t5", program_id="p1", outcome="o", status=TaskState.EXECUTING,
        ))
        self.store.close()
        store2 = HarnessStore(self.db)
        self.assertEqual(store2.get_task("t5").status, TaskState.EXECUTING)
        store2.close()

    def test_no_executable_when_program_empty(self):
        self.store.upsert_program(Program(
            program_id="empty", name="E", owner="o", repository="r",
            goal="g", canonical_status_source="docs/x.md", blockers=["x"],
        ))
        self.assertEqual(self.store.list_tasks("empty"), [])


class HarnessStoreOpenTest(unittest.TestCase):
    def run_cli(self, db: Path, *args: str, use_env: bool = False):
        env = {**os.environ, "HARNESS_DB": str(db)}
        command = [sys.executable, "-m", "scripts.harness"]
        if not use_env:
            command += ["--db", str(db)]
        return subprocess.run(
            command + list(args), cwd=ROOT, env=env,
            capture_output=True, text=True, timeout=20,
        )

    def test_missing_store_is_not_an_empty_success_or_projection(self):
        commands = [
            ("status",), ("status", "--sync"), ("resume",),
            ("resume", "--sync"), ("resume", "--checkpoint", "old-summary"),
            ("plan",), ("plan", "--sync"), ("graph",), ("founder-inbox",),
            ("dispatch", "--apply", "--spawn-exec"), ("project-state",),
        ]
        with tempfile.TemporaryDirectory() as td:
            for n, args in enumerate(commands):
                with self.subTest(command=args):
                    db = Path(td) / str(n) / "missing.sqlite"
                    out = Path(td) / str(n) / "CURRENT_STATE.json"
                    if args[0] == "project-state":
                        args += ("--out", str(out))
                    proc = self.run_cli(db, *args, use_env=n % 2 == 0)
                    self.assertEqual(proc.returncode, 2, proc.stdout + proc.stderr)
                    self.assertEqual(proc.stdout, "")
                    error = json.loads(proc.stderr)
                    self.assertFalse(error["ok"])
                    self.assertEqual(error["reason"], "harness_store_unavailable")
                    self.assertEqual(error["db"], str(db))
                    self.assertTrue(error["continue_independent_work"])
                    self.assertFalse(db.parent.exists())
                    self.assertFalse(out.exists())

    def test_restarted_cli_uses_existing_work_and_preserves_partial_block(self):
        with tempfile.TemporaryDirectory() as td:
            # Include URI metacharacters: an existing path must remain literal.
            db = Path(td) / "task state?#.sqlite"
            store = HarnessStore(db)
            store.upsert_program(Program(
                program_id="resume-case", name="Resume", owner="test", repository="fixture",
                goal="Continue independent work", canonical_status_source="fixture",
            ))
            for task_id, state in [("protected", TaskState.FOUNDER_REQUIRED),
                                   ("independent", TaskState.EXECUTING)]:
                store.upsert_task(Task(
                    task_id=task_id, program_id="resume-case", outcome=task_id, status=state,
                ))
            store.close()
            for _ in range(2):
                proc = self.run_cli(db, "resume")
                self.assertEqual(proc.returncode, 0, proc.stderr)
                resumed = json.loads(proc.stdout)
                self.assertEqual([x["task"] for x in resumed["running"]], ["independent"])
                self.assertEqual([x["task"] for x in resumed["founder_required"]], ["protected"])
            store = HarnessStore(db)
            self.assertEqual(len(store.list_tasks()), 2)
            self.assertEqual(store.get_task("independent").status, TaskState.EXECUTING)
            self.assertEqual(store.get_task("protected").status, TaskState.FOUNDER_REQUIRED)
            store.close()

    def test_explicit_sync_can_still_initialize_an_isolated_store(self):
        with tempfile.TemporaryDirectory() as td:
            db = Path(td) / "fixture" / "state.sqlite"
            proc = self.run_cli(db, "sync")
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertTrue(db.is_file())
            self.assertTrue(json.loads(proc.stdout)["synced_programs"])


if __name__ == "__main__":
    unittest.main()
