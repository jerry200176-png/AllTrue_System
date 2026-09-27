"""H3 planner evals — Founder amendments A1–A6 + Plan selection behaviors."""

from __future__ import annotations

import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.harness.governance_adapter import bind_goal  # noqa: E402
from scripts.harness.leases import contract_resource, program_resource  # noqa: E402
from scripts.harness.models import Program, Task  # noqa: E402
from scripts.harness.planner import (  # noqa: E402
    AGING_MAX_BOOST,
    LeaseOwnershipClaim,
    aging_boost_for,
    ready_since_iso,
    select_across_programs,
    select_next_task,
)
from scripts.harness.states import TaskState  # noqa: E402
from scripts.harness.store import HarnessStore  # noqa: E402

SHA = "a" * 40
NOW = datetime(2026, 9, 16, 12, 0, 0, tzinfo=timezone.utc)


def _iso(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


class HarnessPlannerTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.db = Path(self._tmp.name) / "harness.sqlite"
        self.store = HarnessStore(self.db)

    def tearDown(self) -> None:
        self.store.close()
        self._tmp.cleanup()

    def _program(self, pid: str = "p1", **kwargs) -> Program:
        prog = Program(
            program_id=pid, name=pid.upper(), owner="o", repository="r",
            goal="g", canonical_status_source="docs/x.md", **kwargs,
        )
        self.store.upsert_program(prog)
        return prog

    def _ready(
        self,
        tid: str,
        *,
        program_id: str = "p1",
        value: int = 50,
        paths: list[str] | None = None,
        contracts: list[str] | None = None,
        deps: list[str] | None = None,
        ready_at: datetime | None = None,
        with_goal: bool = True,
        risk: str = "R0",
        tier: str = "T0",
        scope: list[str] | None = None,
        **kwargs,
    ) -> Task:
        if self.store.get_program(program_id) is None:
            self._program(program_id)
        paths = paths if paths is not None else ["docs/harness/HARNESS_STATUS.md"]
        scope = scope if scope is not None else ["docs/harness/**"]
        task = Task(
            task_id=tid,
            program_id=program_id,
            outcome="docs",
            status=TaskState.READY,
            business_value=value,
            affected_paths=list(paths),
            scope=list(scope),
            affected_contracts=list(contracts or []),
            dependencies=list(deps or []),
            risk_declaration=risk,
            designed_slice=True,
            reversible=True,
            updated_at=_iso(NOW),  # must NOT drive aging (A6)
            **kwargs,
        )
        self.store.upsert_task(task)
        if ready_at is not None:
            self.store.record_transition(
                tid, TaskState.DISCOVERED, TaskState.READY, "test",
                {}, _iso(ready_at),
            )
        if with_goal:
            goal = bind_goal(
                program_id=program_id, task_id=tid, outcome="docs",
                subject_sha=SHA, scope=list(scope),
                declared_risk=risk, declared_tier=tier,
            )
            self.store.put_goal(goal)
        return task

    # --- A1 starvation ordering ---
    def test_a1_older_lower_value_outranks_newer_higher_within_aging(self):
        # Older READY: BV=10, age=5*24h → boost=5 → effective=15
        # Newer READY: BV=14, age=0 → boost=0 → effective=14
        self._ready("old_low", value=10, ready_at=NOW - timedelta(hours=5 * 24))
        self._ready("new_high", value=14, ready_at=NOW - timedelta(hours=1))
        plan = select_next_task(self.store, now=NOW, apply_governance=True)
        self.assertTrue(plan.would_execute)
        self.assertEqual(plan.selected.task_id, "old_low")
        self.assertEqual(plan.effective_priority, 15)
        self.assertEqual(plan.aging_boost, 5)
        self.assertLessEqual(plan.aging_boost, AGING_MAX_BOOST)

    def test_a1_effective_priority_not_business_value_primary(self):
        # Older low BV with boost must beat newer higher BV when effective wins.
        self._ready("old", value=10, ready_at=NOW - timedelta(hours=96))  # boost=4 → 14
        self._ready("new", value=13, ready_at=NOW)  # 13
        plan = select_next_task(self.store, now=NOW)
        self.assertEqual(plan.selected.task_id, "old")
        self.assertGreater(plan.effective_priority, 13)

    # --- A6 ready_since ---
    def test_a6_aging_uses_ready_transition_not_updated_at(self):
        self._ready("t", value=10, ready_at=NOW - timedelta(hours=72))
        task = self.store.get_task("t")
        # Mutate updated_at to "now" — aging must ignore it.
        task.updated_at = _iso(NOW)
        self.store.upsert_task(task)
        self.assertEqual(ready_since_iso(self.store, task), _iso(NOW - timedelta(hours=72)))
        boost = aging_boost_for(self.store, task, now=NOW, aging_unit_hours=24, max_boost=5)
        self.assertEqual(boost, 3)
        # No READY transition → boost 0 (not updated_at)
        bare = Task(
            task_id="bare", program_id="p1", outcome="o", status=TaskState.READY,
            business_value=99, updated_at=_iso(NOW - timedelta(days=30)),
            affected_paths=["docs/harness/x.md"], scope=["docs/harness/**"],
        )
        self.store.upsert_task(bare)
        self.assertIsNone(ready_since_iso(self.store, bare))
        self.assertEqual(aging_boost_for(self.store, bare, now=NOW), 0)

    # --- A2 lease identity ---
    def test_a2_live_foreign_lease_unavailable(self):
        self._ready("candidate", value=90, contracts=["schema"])
        key = contract_resource("schema")
        self.store.put_lease({
            "lease_id": "lease_foreign",
            "resource_key": key,
            "holder_task_id": "other",
            "holder_worker": "w",
            "expires_at": _iso(NOW + timedelta(hours=1)),
            "fencing_token": 3,
            "payload": {},
        })
        plan = select_next_task(self.store, now=NOW)
        self.assertFalse(plan.would_execute)
        self.assertTrue(any("lease_busy" in s["reason"] for s in plan.skipped))

    def test_a2_task_id_alone_does_not_imply_ownership(self):
        self._ready("holder", value=90, contracts=["schema"])
        key = contract_resource("schema")
        self.store.put_lease({
            "lease_id": "lease_self",
            "resource_key": key,
            "holder_task_id": "holder",  # same task_id
            "holder_worker": "w",
            "expires_at": _iso(NOW + timedelta(hours=1)),
            "fencing_token": 2,
            "payload": {},
        })
        # Without lease_id+fencing claim → unavailable
        plan = select_next_task(self.store, now=NOW)
        self.assertFalse(plan.would_execute)
        self.assertTrue(any("lease_busy" in s["reason"] for s in plan.skipped))
        # With full identity → owned / selectable
        claim = LeaseOwnershipClaim(
            lease_id="lease_self", fencing_token=2, task_id="holder",
        )
        plan2 = select_next_task(
            self.store, now=NOW, ownership={key: claim},
        )
        self.assertTrue(plan2.would_execute)
        self.assertEqual(plan2.selected.task_id, "holder")

    def test_a2_expired_lease_reclaimable_for_plan(self):
        self._ready("candidate", value=90, contracts=["schema"])
        key = contract_resource("schema")
        self.store.put_lease({
            "lease_id": "lease_old",
            "resource_key": key,
            "holder_task_id": "other",
            "holder_worker": "w",
            "expires_at": _iso(NOW - timedelta(seconds=1)),
            "fencing_token": 1,
            "payload": {},
        })
        plan = select_next_task(self.store, now=NOW)
        self.assertTrue(plan.would_execute)
        self.assertEqual(plan.selected.task_id, "candidate")
        # Planner must not delete the expired lease (A4); H4 CAS reclaim.
        self.assertIsNotNone(self.store.get_lease(key))

    # --- A3 GoalContract ---
    def test_a3_missing_goal_contract_not_executable(self):
        self._ready("nog", value=99, with_goal=False, ready_at=NOW)
        plan = select_next_task(self.store, now=NOW)
        self.assertFalse(plan.would_execute)
        self.assertEqual(plan.reason, "missing_goal_contract")
        self.assertEqual(plan.selected.task_id, "nog")

    # --- A4 read-only ---
    def test_a4_planner_does_not_mutate_leases(self):
        self._ready("t", value=50, ready_at=NOW, contracts=["auth"])
        key = contract_resource("auth")
        self.store.put_lease({
            "lease_id": "lease_x",
            "resource_key": key,
            "holder_task_id": "other",
            "holder_worker": "w",
            "expires_at": _iso(NOW - timedelta(hours=1)),
            "fencing_token": 1,
            "payload": {},
        })
        before = self.store.list_leases()
        with mock.patch("scripts.harness.leases.acquire") as acq, \
             mock.patch("scripts.harness.leases.renew") as ren, \
             mock.patch("scripts.harness.leases.reclaim_stale") as rec, \
             mock.patch("scripts.harness.leases.release") as rel:
            plan = select_next_task(self.store, now=NOW)
            self.assertTrue(plan.would_execute)
            acq.assert_not_called()
            ren.assert_not_called()
            rec.assert_not_called()
            rel.assert_not_called()
        after = self.store.list_leases()
        self.assertEqual(len(before), len(after))
        self.assertEqual(before[0]["lease_id"], after[0]["lease_id"])
        self.assertEqual(before[0]["fencing_token"], after[0]["fencing_token"])

    # --- A5 PlanResult world-bind ---
    def test_a5_planresult_world_bind_fields(self):
        self._ready("t", value=50, ready_at=NOW)
        plan = select_next_task(self.store, now=NOW, main_sha=SHA, reconcile_stale=True)
        d = plan.to_dict()
        self.assertTrue(d["would_execute"])
        self.assertEqual(d["goal_id"], f"p1:t:{SHA[:12]}")
        self.assertTrue(d["goal_contract_fingerprint"])
        self.assertEqual(d["observed_main_sha"], SHA)
        self.assertTrue(d["input_snapshot_fingerprint"])
        self.assertIn(program_resource("p1"), d["required_leases"])
        self.assertIsNotNone(d["governance"])
        self.assertTrue(d["plan_id"])

    # --- Plan behaviors ---
    def test_highest_value_ready_wins_without_aging(self):
        self._ready("low", value=10, ready_at=NOW)
        self._ready("high", value=80, ready_at=NOW)
        plan = select_next_task(self.store, now=NOW)
        self.assertEqual(plan.selected.task_id, "high")

    def test_unmet_deps_skipped(self):
        self._ready("blocked", value=99, deps=["missing"], ready_at=NOW)
        self._ready("ok", value=10, ready_at=NOW)
        plan = select_next_task(self.store, now=NOW)
        self.assertEqual(plan.selected.task_id, "ok")
        self.assertTrue(any(s["reason"] == "unmet_dependencies" for s in plan.skipped))

    def test_wip_blocks_second_mutate(self):
        self._ready("ready", value=90, ready_at=NOW)
        wip = Task(
            task_id="wip", program_id="p1", outcome="o",
            status=TaskState.EXECUTING, business_value=1,
        )
        self.store.upsert_task(wip)
        plan = select_next_task(self.store, now=NOW)
        self.assertFalse(plan.would_execute)
        self.assertTrue(any("wip_active" in s["reason"] for s in plan.skipped))

    def test_deny_and_continue_across_programs(self):
        self._program("pa", blockers=["human"])
        self._ready("a1", program_id="pa", value=99, ready_at=NOW)
        self._ready("b1", program_id="pb", value=10, ready_at=NOW)
        plan = select_across_programs(self.store, now=NOW)
        self.assertTrue(plan.would_execute)
        self.assertEqual(plan.selected.task_id, "b1")

    def test_auth_path_founder_required(self):
        self._ready(
            "auth", value=99, ready_at=NOW,
            paths=["backend/app/Http/Middleware/Authenticate.php"],
            scope=["backend/app/**"],
            risk="R1", tier="T1",
        )
        plan = select_next_task(self.store, now=NOW)
        self.assertFalse(plan.would_execute)
        reasons = [s["reason"] for s in plan.skipped] + [plan.reason]
        self.assertTrue(any("founder_required" in r for r in reasons))

    def test_determinism_same_fixture_same_plan_id(self):
        self._ready("t1", value=40, ready_at=NOW - timedelta(hours=1))
        self._ready("t2", value=40, ready_at=NOW - timedelta(hours=1))
        a = select_next_task(self.store, now=NOW, main_sha=SHA)
        b = select_next_task(self.store, now=NOW, main_sha=SHA)
        self.assertEqual(a.plan_id, b.plan_id)
        self.assertEqual(a.to_dict()["input_snapshot_fingerprint"],
                         b.to_dict()["input_snapshot_fingerprint"])
        self.assertEqual(a.selected.task_id, b.selected.task_id)

    def test_dependency_cycle_skipped(self):
        self._ready("c1", value=50, deps=["c2"], ready_at=NOW)
        self._ready("c2", value=50, deps=["c1"], ready_at=NOW)
        plan = select_next_task(self.store, now=NOW)
        self.assertFalse(plan.would_execute)
        self.assertTrue(all(s["reason"] == "dependency_cycle" for s in plan.skipped))

    def test_missing_paths_fail_closed(self):
        self._ready("np", value=90, paths=[], scope=[], ready_at=NOW)
        # Goal scope empty + paths empty → missing_paths under governance
        plan = select_next_task(self.store, now=NOW, apply_governance=True)
        self.assertFalse(plan.would_execute)
        self.assertTrue(
            any(s["reason"] == "missing_paths" for s in plan.skipped)
            or plan.reason == "missing_paths"
        )

    def test_goal_scope_drift_skipped(self):
        self._ready(
            "drift", value=90, ready_at=NOW,
            paths=["backend/app/Models/User.php"],
            scope=["docs/harness/**"],
            risk="R0", tier="T0",
        )
        plan = select_next_task(self.store, now=NOW)
        self.assertFalse(plan.would_execute)
        self.assertTrue(
            any(s["reason"] == "scope_drift" for s in plan.skipped)
            or plan.reason == "scope_drift"
        )

    def test_stale_goal_sha_when_reconcile(self):
        self._ready("stale", value=90, ready_at=NOW)
        plan = select_next_task(
            self.store, now=NOW, main_sha="b" * 40, reconcile_stale=True,
        )
        self.assertFalse(plan.would_execute)
        self.assertTrue(any(s["reason"] == "stale_goal_sha" for s in plan.skipped))


if __name__ == "__main__":
    unittest.main()
