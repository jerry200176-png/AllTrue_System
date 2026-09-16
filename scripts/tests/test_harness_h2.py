"""H2 governance / drift eval scenarios — no production touch."""

from __future__ import annotations

import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.harness.contracts import DelegationContract, EvidenceEnvelope  # noqa: E402
from scripts.harness.governance_adapter import (  # noqa: E402
    bind_goal, check_delegation, check_risk_declaration, classify_task_paths,
    decide_merge_readiness, issue_decision_receipt, reject_production_direct_write,
)
from scripts.harness.graph import build_graph, deny_and_continue_peers  # noqa: E402
from scripts.harness.leases import (  # noqa: E402
    LeaseBusyError, acquire, contract_resource, program_resource, reclaim_stale, release,
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

    def _goal(self, sha: str = SHA_A, scope: list[str] | None = None):
        return bind_goal(
            program_id="p1", task_id="t1", outcome="docs", subject_sha=sha,
            scope=scope or ["docs/harness/**"], declared_risk="R0", declared_tier="T0",
        )

    def test_founder_approves_sha_a_main_advances_to_b(self):
        goal = self._goal(SHA_A)
        receipt = issue_decision_receipt(goal=goal, decision="approve_production")
        result = reconcile(goal=goal, receipt=receipt, world=WorldObservation(main_sha=SHA_B))
        self.assertFalse(result.ok)
        self.assertIn("stale_approval", result.drift_codes)
        self.assertIn("main_advanced", result.drift_codes)
        self.assertTrue(result.continue_safe)

    def test_ci_evidence_belongs_to_different_sha(self):
        d = reconcile_ci_sha(expected_head_sha=SHA_A, observed_ci_sha=SHA_C, conclusion="success")
        self.assertFalse(d.autonomous)
        self.assertIn("sha_mismatch", d.drift_codes)
        env = EvidenceEnvelope(kind="ci", subject_sha=SHA_C, source="gh", payload={"ok": True})
        result = reconcile(goal=self._goal(SHA_A), evidence=[env])
        self.assertFalse(result.ok)
        self.assertIn("stale_evidence", result.drift_codes)

    def test_worker_deployed_runtime_disagrees(self):
        result = reconcile(world=WorldObservation(worker_deploy_sha=SHA_A, runtime_sha=SHA_B))
        self.assertFalse(result.ok)
        self.assertIn("runtime_mismatch", result.drift_codes)
        self.assertTrue(result.continue_safe)

    def test_scope_changes_after_approval(self):
        goal = self._goal(SHA_A, scope=["docs/harness/**"])
        receipt = issue_decision_receipt(goal=goal, decision="approve")
        result = reconcile(
            goal=goal, receipt=receipt,
            world=WorldObservation(main_sha=SHA_A, current_scope=["docs/harness/**", "backend/app/**"]),
        )
        self.assertFalse(result.ok)
        self.assertIn("scope_drift", result.drift_codes)

    def test_machine_risk_exceeds_declaration(self):
        d = check_risk_declaration(
            ["backend/app/Http/Controllers/Auth/LoginController.php"],
            declared_risk="R0", declared_tier="T0",
        )
        self.assertTrue(d.founder_required or not d.autonomous)

    def test_blocked_production_does_not_block_safe_peers(self):
        d = reject_production_direct_write({"action": "ad_hoc_sql_write"})
        self.assertFalse(d.autonomous)
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
        result = reconcile(world=WorldObservation(production_attempt={"action": "ssh_mutate"}))
        self.assertFalse(result.ok)
        self.assertTrue(result.continue_safe)

    def test_two_workers_conflicting_ownership(self):
        key = contract_resource("schema")
        acquire(self.store, key, task_id="t1", worker="w1")
        with self.assertRaises(LeaseBusyError):
            acquire(self.store, key, task_id="t2", worker="w2")
        release(self.store, key, task_id="t1")
        self.assertEqual(acquire(self.store, key, task_id="t2", worker="w2")["holder_task_id"], "t2")

    def test_session_restart_reconstructs_from_checkpoint(self):
        goal = self._goal(SHA_A)
        self.store.put_goal(goal)
        receipt = issue_decision_receipt(goal=goal, decision="continue")
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
        self.assertTrue(any(L["lease_id"] == lease["lease_id"] for L in restored["leases"]))
        store2.close()

    def test_stale_lease_recovery(self):
        key = program_resource("p1")
        past = datetime.now(timezone.utc) - timedelta(hours=2)
        self.store.put_lease({
            "lease_id": "old", "resource_key": key, "holder_task_id": "dead",
            "holder_worker": "w0",
            "expires_at": past.isoformat().replace("+00:00", "Z"),
            "fencing_token": 1, "payload": {},
        })
        self.assertIn(key, reclaim_stale(self.store))
        self.assertEqual(acquire(self.store, key, task_id="alive", worker="w1")["holder_task_id"], "alive")

    def test_bounded_delegation_denies_exceed(self):
        d = check_delegation(
            DelegationContract(worker_id="w1", allowed_actions=["edit_docs"],
                               allowed_paths=["docs/**"], max_tier="T0"),
            action="edit_docs", paths=["backend/app/Models/User.php"], machine_tier="T0",
        )
        self.assertFalse(d.autonomous)
        self.assertTrue(d.continue_safe)

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
        self.assertIn("exact-SHA mismatch", d.reasons)


if __name__ == "__main__":
    unittest.main()
