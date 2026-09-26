"""H2 + H2.1 governance / drift / lease CAS eval — no production touch."""

from __future__ import annotations

import sys
import tempfile
import threading
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.harness.contracts import (  # noqa: E402
    DelegationContract, EvidenceEnvelope, path_in_scope,
)
from scripts.harness.governance_adapter import (  # noqa: E402
    bind_goal, check_delegation, check_risk_declaration, check_scope_drift,
    classify_task_paths, decide_merge_readiness, issue_decision_receipt,
    reject_production_direct_write, validate_decision_receipt, validate_evidence,
)
from scripts.harness.graph import build_graph, deny_and_continue_peers  # noqa: E402
from scripts.harness.leases import (  # noqa: E402
    LeaseBusyError, LeaseError, acquire, contract_resource, program_resource,
    reclaim_stale, release, renew,
)
from scripts.harness.models import Program, Task  # noqa: E402
from scripts.harness.reconcile import (  # noqa: E402
    WorldObservation, reconcile, reconcile_ci_sha, resume_from_checkpoint, write_checkpoint,
)
from scripts.harness.states import TaskState  # noqa: E402
from scripts.harness.store import HarnessStore  # noqa: E402

SHA_A, SHA_B, SHA_C = "a" * 40, "b" * 40, "c" * 40


class HarnessH2EvalTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.db = Path(self._tmp.name) / "harness.sqlite"
        self.store = HarnessStore(self.db)

    def tearDown(self) -> None:
        self.store.close()
        self._tmp.cleanup()

    def _goal(self, sha: str = SHA_A, scope: list[str] | None = None, **kwargs):
        return bind_goal(
            program_id="p1", task_id="t1", outcome="docs", subject_sha=sha,
            scope=scope or ["docs/harness/**"], declared_risk="R0", declared_tier="T0",
            **kwargs,
        )

    def test_founder_approves_sha_a_main_advances_to_b(self):
        goal = self._goal(SHA_A)
        receipt = issue_decision_receipt(
            goal=goal, decision="approve_production",
            authorized_action="activate_production", actor="founder",
        )
        result = reconcile(
            goal=goal, receipt=receipt,
            world=WorldObservation(main_sha=SHA_B, requested_action="activate_production"),
        )
        self.assertFalse(result.ok)
        self.assertIn("stale_approval", result.drift_codes)
        self.assertIn("main_advanced", result.drift_codes)

    def test_ci_evidence_belongs_to_different_sha(self):
        d = reconcile_ci_sha(expected_head_sha=SHA_A, observed_ci_sha=SHA_C, conclusion="success")
        self.assertFalse(d.autonomous)
        goal = self._goal(SHA_A)
        env = EvidenceEnvelope(
            kind="ci", subject_sha=SHA_C, source="gh", goal_id=goal.goal_id, payload={"ok": True},
        )
        result = reconcile(goal=goal, evidence=[env])
        self.assertFalse(result.ok)
        self.assertIn("stale_evidence", result.drift_codes)

    def test_evidence_requires_goal_binding(self):
        goal = self._goal(SHA_A)
        env = EvidenceEnvelope(kind="ci", subject_sha=SHA_A, source="gh", goal_id="", payload={})
        d = validate_evidence(env, goal=goal)
        self.assertFalse(d.autonomous)
        self.assertIn("missing_goal_binding", d.drift_codes)

    def test_worker_deployed_runtime_disagrees(self):
        result = reconcile(world=WorldObservation(worker_deploy_sha=SHA_A, runtime_sha=SHA_B))
        self.assertFalse(result.ok)
        self.assertIn("runtime_mismatch", result.drift_codes)

    def test_scope_changes_after_approval(self):
        goal = self._goal(SHA_A, scope=["docs/harness/**"])
        receipt = issue_decision_receipt(
            goal=goal, decision="approve_merge", authorized_action="merge_pr", actor="founder",
        )
        result = reconcile(
            goal=goal, receipt=receipt,
            world=WorldObservation(
                main_sha=SHA_A, requested_action="merge_pr",
                current_scope=["docs/harness/**", "backend/app/**"],
            ),
        )
        self.assertFalse(result.ok)
        self.assertIn("scope_drift", result.drift_codes)

    def test_contract_drift_invalidates_receipt(self):
        goal = self._goal(SHA_A)
        receipt = issue_decision_receipt(
            goal=goal, decision="continue", authorized_action="edit_docs", actor="system",
        )
        drifted = bind_goal(
            program_id="p1", task_id="t1", outcome="docs", subject_sha=SHA_A,
            scope=["docs/harness/**"], declared_risk="R1", declared_tier="T1",
        )
        d = validate_decision_receipt(
            receipt, goal=drifted, requested_action="edit_docs", observed_main_sha=SHA_A,
        )
        self.assertFalse(d.autonomous)
        self.assertIn("contract_drift", d.drift_codes)

    def test_receipt_requires_requested_action_and_actor(self):
        goal = self._goal(SHA_A)
        with self.assertRaises(ValueError):
            issue_decision_receipt(
                goal=goal, decision="approve_production",
                authorized_action="activate_production", actor="worker",
            )
        receipt = issue_decision_receipt(
            goal=goal, decision="approve_production",
            authorized_action="activate_production", actor="founder",
        )
        d = validate_decision_receipt(receipt, goal=goal, requested_action="")
        self.assertIn("missing_requested_action", d.drift_codes)
        d2 = validate_decision_receipt(
            receipt, goal=goal, requested_action="something_else", observed_main_sha=SHA_A,
        )
        self.assertIn("action_mismatch", d2.drift_codes)

    def test_path_boundary_no_prefix_escape(self):
        self.assertTrue(path_in_scope("docs/harness/a.md", "docs/harness/**"))
        self.assertFalse(path_in_scope("docs/harness-secret/x", "docs/harness"))
        self.assertFalse(path_in_scope("docs/harness-secret/x", "docs/harness/**"))
        with self.assertRaises(ValueError):
            path_in_scope("docs/a.md", "docs*")
        goal = self._goal(scope=["docs/harness"])
        d = check_scope_drift(goal, ["docs/harness-secret/x"])
        self.assertFalse(d.autonomous)

    def test_machine_risk_exceeds_declaration(self):
        d = check_risk_declaration(
            ["backend/app/Http/Controllers/Auth/LoginController.php"],
            declared_risk="R0", declared_tier="T0",
        )
        self.assertTrue(d.founder_required or not d.autonomous)

    def test_blocked_production_does_not_block_safe_peers(self):
        d = reject_production_direct_write({"action": "ad_hoc_sql_write"})
        self.assertTrue(d.continue_safe)
        self.store.upsert_program(Program(
            program_id="p1", name="P", owner="o", repository="r",
            goal="g", canonical_status_source="docs/x.md",
        ))
        self.store.upsert_task(Task(
            task_id="blocked-prod", program_id="p1", outcome="prod",
            status=TaskState.FOUNDER_REQUIRED,
        ))
        self.store.upsert_task(Task(
            task_id="safe-docs", program_id="p1", outcome="docs", status=TaskState.READY,
        ))
        self.assertIn("safe-docs", deny_and_continue_peers(self.store, "blocked-prod"))

    def test_two_workers_conflicting_ownership(self):
        key = contract_resource("schema")
        a = acquire(self.store, key, task_id="t1", worker="w1")
        with self.assertRaises(LeaseBusyError):
            acquire(self.store, key, task_id="t2", worker="w2")
        release(self.store, key, lease_id=a["lease_id"], fencing_token=a["fencing_token"], task_id="t1")
        b = acquire(self.store, key, task_id="t2", worker="w2")
        self.assertEqual(b["holder_task_id"], "t2")

    def test_stale_fencing_cannot_release_or_renew(self):
        key = program_resource("p1")
        a = acquire(self.store, key, task_id="t1", worker="w1")
        renewed = renew(
            self.store, key, lease_id=a["lease_id"], fencing_token=a["fencing_token"],
            task_id="t1", worker="w1",
        )
        with self.assertRaises(LeaseError):
            release(
                self.store, key, lease_id=a["lease_id"],
                fencing_token=a["fencing_token"], task_id="t1",
            )
        with self.assertRaises(LeaseError):
            renew(
                self.store, key, lease_id=a["lease_id"], fencing_token=a["fencing_token"],
                task_id="t1", worker="w1",
            )
        release(
            self.store, key, lease_id=renewed["lease_id"],
            fencing_token=renewed["fencing_token"], task_id="t1",
        )

    def test_same_task_cannot_acquire_without_renew(self):
        key = program_resource("p1")
        acquire(self.store, key, task_id="t1", worker="w1")
        with self.assertRaises(LeaseBusyError):
            acquire(self.store, key, task_id="t1", worker="w1-old")

    def test_concurrent_acquire_one_winner(self):
        key = contract_resource("billing")
        barrier = threading.Barrier(2)
        results: list[Any] = []
        errors: list[BaseException] = []
        lock = threading.Lock()

        def worker(name: str) -> None:
            store = HarnessStore(self.db)
            try:
                barrier.wait(timeout=5)
                lease = acquire(store, key, task_id=f"task-{name}", worker=name)
                with lock:
                    results.append(lease)
            except BaseException as exc:  # noqa: BLE001 — capture domain + unexpected
                with lock:
                    errors.append(exc)
            finally:
                store.close()

        t1 = threading.Thread(target=worker, args=("A",))
        t2 = threading.Thread(target=worker, args=("B",))
        t1.start()
        t2.start()
        t1.join(timeout=10)
        t2.join(timeout=10)
        self.assertEqual(len(results), 1, msg=f"results={results} errors={errors}")
        self.assertEqual(len(errors), 1)
        self.assertIsInstance(errors[0], LeaseBusyError)

    def test_reclaim_stale_does_not_delete_renewed_lease(self):
        key = program_resource("p1")
        past = (datetime.now(timezone.utc) - timedelta(hours=2)).isoformat().replace("+00:00", "Z")
        self.store.put_lease({
            "lease_id": "old", "resource_key": key, "holder_task_id": "dead",
            "holder_worker": "w0", "expires_at": past, "fencing_token": 1, "payload": {},
        })
        # Another process reacquires (CAS) before reclaim runs its delete
        alive = acquire(self.store, key, task_id="alive", worker="w1")
        reclaimed = reclaim_stale(self.store)
        self.assertNotIn(key, reclaimed)
        still = self.store.get_lease(key)
        self.assertEqual(still["lease_id"], alive["lease_id"])

    def test_stale_lease_recovery(self):
        key = program_resource("p2")
        past = (datetime.now(timezone.utc) - timedelta(hours=2)).isoformat().replace("+00:00", "Z")
        self.store.put_lease({
            "lease_id": "old", "resource_key": key, "holder_task_id": "dead",
            "holder_worker": "w0", "expires_at": past, "fencing_token": 1, "payload": {},
        })
        self.assertIn(key, reclaim_stale(self.store))
        lease = acquire(self.store, key, task_id="alive", worker="w1")
        self.assertEqual(lease["holder_task_id"], "alive")

    def test_session_restart_reconstructs_from_checkpoint(self):
        goal = self._goal(SHA_A)
        self.store.put_goal(goal)
        receipt = issue_decision_receipt(
            goal=goal, decision="continue", authorized_action="edit_docs", actor="system",
        )
        self.store.put_decision_receipt(receipt)
        task = Task(task_id="t1", program_id="p1", outcome="docs",
                    status=TaskState.EXECUTING, assignee="worker-1")
        self.store.upsert_task(task)
        lease = acquire(self.store, program_resource("p1"), task_id="t1", worker="worker-1")
        task.lease_id = lease["lease_id"]
        self.store.upsert_task(task)
        cp = write_checkpoint(self.store, task, goal=goal, receipt=receipt)
        self.store.close()
        store2 = HarnessStore(self.db)
        restored = resume_from_checkpoint(store2, cp.checkpoint_id)
        self.assertTrue(restored["ok"])
        self.assertEqual(restored["task_state"], TaskState.EXECUTING.value)
        self.assertEqual(restored["goal"]["subject_sha"], SHA_A)
        store2.close()

    def test_bounded_delegation_denies_exceed(self):
        d = check_delegation(
            DelegationContract(worker_id="w1", allowed_actions=["edit_docs"],
                               allowed_paths=["docs/**"], max_tier="T0"),
            action="edit_docs", paths=["backend/app/Models/User.php"], machine_tier="T0",
        )
        self.assertFalse(d.autonomous)

    def test_docs_path_autonomous_and_graph_builds(self):
        self.assertTrue(classify_task_paths(["docs/harness/HARNESS_STATUS.md"]).autonomous)
        self.store.upsert_program(Program(
            program_id="p1", name="P", owner="o", repository="r",
            goal="g", canonical_status_source="docs/x.md",
        ))
        self.store.upsert_task(Task(task_id="t1", program_id="p1", outcome="o", status=TaskState.READY))
        self.store.put_goal(self._goal())
        kinds = {n.kind for n in build_graph(self.store).nodes}
        self.assertTrue({"program", "task", "goal"} <= kinds)

    def test_merge_readiness_exact_sha(self):
        d = decide_merge_readiness(
            paths=["docs/x.md"], patch="",
            pr_body="Risk-Class: R0\nAutonomy-Tier: T0\nRollback: n/a docs\n",
            ci_green=True, exact_head_sha=SHA_A, observed_head_sha=SHA_B,
        )
        self.assertFalse(d.autonomous)


if __name__ == "__main__":
    unittest.main()
