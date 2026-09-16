"""Deterministic harness tests — no production touch."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.harness.governance_adapter import (  # noqa: E402
    classify_task_paths,
    decide_merge_readiness,
    reject_production_direct_write,
)
from scripts.harness.leases import (  # noqa: E402
    LeaseBusyError,
    acquire,
    contract_resource,
    program_resource,
    reclaim_stale,
    release,
)
from scripts.harness.models import Escalation, Program, Task  # noqa: E402
from scripts.harness.planner import select_next_task  # noqa: E402
from scripts.harness.states import TaskState  # noqa: E402
from scripts.harness.store import HarnessStore  # noqa: E402
from scripts.harness.execution import prepare_task_execution  # noqa: E402
from scripts.harness.reconciler import CheckSummary, reconcile_task_ci  # noqa: E402
from scripts.harness.worker import build_worker_goal, dispatch_task  # noqa: E402
from scripts.harness.transitions import (  # noqa: E402
    TransitionError,
    enqueue_founder_escalation,
    transition,
)


class HarnessCoreTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.db = Path(self._tmp.name) / "harness.sqlite"
        self.store = HarnessStore(self.db)

    def tearDown(self) -> None:
        self.store.close()
        self._tmp.cleanup()

    def test_allowed_autonomous_transition(self):
        task = Task(
            task_id="t-auto",
            program_id="p1",
            outcome="docs",
            status=TaskState.READY,
            affected_paths=["docs/harness/HARNESS_STATUS.md"],
        )
        self.store.upsert_task(task)
        gov = classify_task_paths(task.affected_paths)
        self.assertTrue(gov.autonomous)
        self.assertFalse(gov.founder_required)
        leased = transition(self.store, task, TaskState.LEASED, actor="test")
        self.assertEqual(leased.status, TaskState.LEASED)

    def test_founder_required_transition(self):
        task = Task(
            task_id="t-found",
            program_id="p1",
            outcome="auth",
            status=TaskState.READY,
            affected_paths=["backend/app/Http/Controllers/Auth/LoginController.php"],
        )
        self.store.upsert_task(task)
        gov = classify_task_paths(task.affected_paths)
        self.assertTrue(gov.founder_required)
        task.governance_result = gov.to_dict()
        esc = enqueue_founder_escalation(
            self.store,
            task,
            decision_required="approve auth boundary change",
            why="activation founder-required path",
            governance_rule="autonomy_gate.classify_activation_scope",
            options=["approve", "reject", "narrow_scope"],
            consequences="blocks program until decided",
            recommended_default="reject_until_narrowed",
        )
        again = enqueue_founder_escalation(
            self.store,
            self.store.get_task(task.task_id),
            decision_required="approve auth boundary change",
            why="activation founder-required path",
            governance_rule="autonomy_gate.classify_activation_scope",
            options=["approve", "reject", "narrow_scope"],
            consequences="blocks program until decided",
            recommended_default="reject_until_narrowed",
        )
        self.assertEqual(esc.escalation_id, again.escalation_id)
        self.assertEqual(self.store.get_task(task.task_id).status, TaskState.FOUNDER_REQUIRED)

    def test_contradictory_evidence_fail_closed(self):
        task = Task(task_id="t-ci", program_id="p1", outcome="x", status=TaskState.CI_PENDING)
        self.store.upsert_task(task)
        with self.assertRaises(TransitionError):
            transition(self.store, task, TaskState.CI_GREEN, actor="test", evidence={})
        with self.assertRaises(TransitionError):
            transition(
                self.store,
                task,
                TaskState.CI_GREEN,
                actor="test",
                evidence={
                    "ci": {"conclusion": "success"},
                    "exact_sha": "a" * 40,
                    "observed_sha": "b" * 40,
                },
            )

    def test_blocked_task_choose_next(self):
        prog = Program(
            program_id="p1",
            name="P1",
            owner="o",
            repository="r",
            goal="g",
            canonical_status_source="docs/x.md",
        )
        self.store.upsert_program(prog)
        blocked = Task(
            task_id="blocked",
            program_id="p1",
            outcome="b",
            status=TaskState.BLOCKED,
            blocker="external",
            business_value=99,
            designed_slice=True,
        )
        ready = Task(
            task_id="ready",
            program_id="p1",
            outcome="r",
            status=TaskState.READY,
            business_value=50,
            designed_slice=True,
            affected_paths=["docs/harness/HARNESS_STATUS.md"],
        )
        self.store.upsert_task(blocked)
        self.store.upsert_task(ready)
        plan = select_next_task(self.store, prog, dry_run=True)
        self.assertEqual(plan.selected.task_id, "ready")
        self.assertTrue(plan.would_execute)

    def test_stale_lease_recovery(self):
        from datetime import datetime, timedelta, timezone

        key = program_resource("p1")
        past = datetime.now(timezone.utc) - timedelta(hours=2)
        # plant expired lease
        self.store.put_lease(
            {
                "lease_id": "old",
                "resource_key": key,
                "holder_task_id": "dead",
                "holder_worker": "w0",
                "expires_at": past.isoformat().replace("+00:00", "Z"),
                "fencing_token": 1,
                "payload": {},
            }
        )
        reclaimed = reclaim_stale(self.store)
        self.assertIn(key, reclaimed)
        lease = acquire(self.store, key, task_id="alive", worker="w1")
        self.assertEqual(lease["holder_task_id"], "alive")
        self.assertEqual(lease["fencing_token"], 1)

    def test_two_workers_shared_contract_collision(self):
        key = contract_resource("schema")
        acquire(self.store, key, task_id="t1", worker="w1")
        with self.assertRaises(LeaseBusyError):
            acquire(self.store, key, task_id="t2", worker="w2")
        release(self.store, key, task_id="t1")
        lease2 = acquire(self.store, key, task_id="t2", worker="w2")
        self.assertEqual(lease2["holder_task_id"], "t2")

    def test_crash_resume_reconstructs(self):
        prog = Program(
            program_id="p1",
            name="P1",
            owner="o",
            repository="r",
            goal="g",
            canonical_status_source="docs/x.md",
        )
        self.store.upsert_program(prog)
        task = Task(
            task_id="t-run",
            program_id="p1",
            outcome="o",
            status=TaskState.EXECUTING,
            assignee="worker-1",
        )
        self.store.upsert_task(task)
        self.store.close()
        # reopen = crash/resume
        store2 = HarnessStore(self.db)
        restored = store2.get_task("t-run")
        self.assertEqual(restored.status, TaskState.EXECUTING)
        self.assertEqual(store2.get_program("p1").program_id, "p1")
        store2.close()

    def test_ci_failure_and_pr_head_changed(self):
        task = Task(task_id="t-pr", program_id="p1", outcome="o", status=TaskState.CI_PENDING)
        self.store.upsert_task(task)
        failed = transition(
            self.store,
            task,
            TaskState.CI_FAILED,
            actor="reconciler",
            evidence={"ci": {"conclusion": "failure", "sha": "a" * 40}},
        )
        self.assertEqual(failed.status, TaskState.CI_FAILED)
        decision = decide_merge_readiness(
            paths=["docs/harness/HARNESS_STATUS.md"],
            patch="",
            pr_body="Risk-Class: R0\nAutonomy-Tier: T0\nRollback: revert commit\n",
            ci_green=True,
            exact_head_sha="a" * 40,
            observed_head_sha="c" * 40,
        )
        self.assertIn("exact-SHA mismatch", decision.reasons)
        self.assertFalse(decision.autonomous)

    def test_main_advanced_exact_sha_mismatch(self):
        decision = decide_merge_readiness(
            paths=["docs/x.md"],
            patch="",
            pr_body="Risk-Class: R0\nAutonomy-Tier: T0\nRollback: n/a docs\n",
            ci_green=True,
            exact_head_sha="1" * 40,
            observed_head_sha="2" * 40,
        )
        self.assertFalse(decision.autonomous)

    def test_production_direct_write_rejected(self):
        d = reject_production_direct_write({"action": "ad_hoc_sql_write"})
        self.assertTrue(d.founder_required)
        self.assertFalse(d.autonomous)

    def test_program_no_executable_tasks(self):
        prog = Program(
            program_id="empty",
            name="E",
            owner="o",
            repository="r",
            goal="g",
            canonical_status_source="docs/x.md",
            blockers=["everything"],
        )
        self.store.upsert_program(prog)
        plan = select_next_task(self.store, prog)
        self.assertIsNone(plan.selected)
        self.assertIn("program_blocked", plan.reason)

    def test_escalation_deduplication(self):
        task = Task(task_id="t-dup", program_id="p1", outcome="o", status=TaskState.READY)
        self.store.upsert_task(task)
        a = enqueue_founder_escalation(
            self.store,
            task,
            decision_required="d",
            why="w",
            governance_rule="rule-x",
            options=["a"],
            consequences="c",
            recommended_default="pause",
        )
        # second call should hit dedupe without illegal FOUNDER->FOUNDER transition
        current = self.store.get_task("t-dup")
        b = enqueue_founder_escalation(
            self.store,
            current,
            decision_required="d",
            why="w",
            governance_rule="rule-x",
            options=["a"],
            consequences="c",
            recommended_default="pause",
        )
        self.assertEqual(a.escalation_id, b.escalation_id)
        opens = self.store.list_open_escalations()
        self.assertEqual(len(opens), 1)

    def test_prepare_lease_and_dispatch_file_queue(self):
        prog = Program(
            program_id="p1",
            name="P1",
            owner="o",
            repository="r",
            goal="g",
            canonical_status_source="docs/x.md",
        )
        self.store.upsert_program(prog)
        task = Task(
            task_id="t-prep",
            program_id="p1",
            outcome="docs slice",
            status=TaskState.READY,
            affected_paths=["docs/harness/HARNESS_STATUS.md"],
            designed_slice=True,
        )
        self.store.upsert_task(task)
        prepared = prepare_task_execution(self.store, task, worker="w1", create_worktree=False)
        self.assertIsNone(prepared.skipped_reason)
        self.assertEqual(prepared.task.status, TaskState.LEASED)
        # Fake worktree for file-queue goal write
        wt = Path(self._tmp.name) / "wt"
        wt.mkdir()
        leased = prepared.task
        leased.worktree = str(wt)
        self.store.upsert_task(leased)
        goal = build_worker_goal(leased)
        self.assertEqual(goal["task_id"], "t-prep")
        self.assertIn("stop_conditions", goal)
        result = dispatch_task(self.store, leased, enable_codex=False)
        self.assertTrue(result["ok"])
        self.assertEqual(self.store.get_task("t-prep").status, TaskState.EXECUTING)
        self.assertTrue((wt / ".agent-session" / "harness-goal.json").is_file())

    def test_reconcile_ci_retry_and_green(self):
        task = Task(
            task_id="t-ci2",
            program_id="p1",
            outcome="o",
            status=TaskState.CI_PENDING,
            pr={"number": 1, "head_sha": "a" * 40},
        )
        self.store.upsert_task(task)
        failed = reconcile_task_ci(
            self.store,
            task,
            checks=CheckSummary(head_sha="a" * 40, conclusion="failure", required=[], raw={}),
        )
        self.assertEqual(failed.status, TaskState.CI_FAILED)
        # retry path: back to executing then later CI pending — here directly pending again
        pending = transition(
            self.store,
            failed,
            TaskState.EXECUTING,
            actor="test",
            evidence={"next_action": "fix_ci"},
        )
        pending = transition(
            self.store,
            pending,
            TaskState.PR_OPEN,
            actor="test",
            evidence={"pr": {"number": 1, "head_sha": "a" * 40}},
        )
        pending = transition(
            self.store,
            pending,
            TaskState.CI_PENDING,
            actor="test",
            evidence={"pr": {"number": 1, "head_sha": "a" * 40}, "ci": {"conclusion": "pending"}},
        )
        green = reconcile_task_ci(
            self.store,
            pending,
            checks=CheckSummary(head_sha="a" * 40, conclusion="success", required=[], raw={}),
        )
        self.assertEqual(green.status, TaskState.CI_GREEN)
        with self.assertRaises(TransitionError):
            reconcile_task_ci(
                self.store,
                green,
                checks=CheckSummary(head_sha="b" * 40, conclusion="success", required=[], raw={}),
                expected_head_sha="a" * 40,
            )


if __name__ == "__main__":
    unittest.main()
