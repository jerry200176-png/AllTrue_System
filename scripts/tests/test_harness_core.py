"""H0–H1 harness tests — durable state + transitions (no production)."""

from __future__ import annotations

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


if __name__ == "__main__":
    unittest.main()
