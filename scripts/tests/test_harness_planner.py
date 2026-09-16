"""H3 planner evals — read-only selection; no lease mutation / no production."""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.harness.governance_adapter import bind_goal  # noqa: E402
from scripts.harness.leases import acquire, program_resource  # noqa: E402
from scripts.harness.models import Program, Task  # noqa: E402
from scripts.harness.planner import (  # noqa: E402
    aging_boost,
    effective_priority,
    lease_is_available,
    select_across_programs,
    select_next_task,
)
from scripts.harness.states import TaskState  # noqa: E402
from scripts.harness.store import HarnessStore  # noqa: E402

SHA = "a" * 40
NOW = datetime(2026, 9, 16, 12, 0, 0, tzinfo=timezone.utc)


def _iso(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


class HarnessH3PlannerTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.db = Path(self._tmp.name) / "harness.sqlite"
        self.store = HarnessStore(self.db)

    def tearDown(self) -> None:
        self.store.close()
        self._tmp.cleanup()

    def _prog(self, pid: str = "p1", **kwargs) -> Program:
        p = Program(
            program_id=pid, name=pid.upper(), owner="o", repository="r",
            goal="g", canonical_status_source="docs/x.md", **kwargs,
        )
        self.store.upsert_program(p)
        return p

    def _task(
        self,
        tid: str,
        *,
        program_id: str = "p1",
        status: TaskState = TaskState.READY,
        business_value: int = 50,
        paths: list[str] | None = None,
        ready_since: str = "",
        deps: list[str] | None = None,
        contracts: list[str] | None = None,
        designed_slice: bool = True,
        goal: bool = True,
        **kwargs,
    ) -> Task:
        paths = paths if paths is not None else ["docs/harness/HARNESS_STATUS.md"]
        t = Task(
            task_id=tid, program_id=program_id, outcome="docs",
            status=status, business_value=business_value,
            affected_paths=list(paths), scope=list(paths),
            dependencies=list(deps or []),
            affected_contracts=list(contracts or []),
            designed_slice=designed_slice,
            ready_since=ready_since or (_iso(NOW) if status == TaskState.READY else ""),
            **kwargs,
        )
        self.store.upsert_task(t)
        if goal and status == TaskState.READY:
            g = bind_goal(
                program_id=program_id, task_id=tid, outcome="docs",
                subject_sha=SHA, scope=list(paths), declared_risk="R0", declared_tier="T0",
            )
            self.store.put_goal(g)
        return t

    def test_highest_value_ready_wins(self):
        prog = self._prog()
        self._task("low", business_value=10)
        self._task("high", business_value=90)
        r = select_next_task(self.store, prog, now=NOW)
        self.assertTrue(r.would_execute)
        self.assertEqual(r.selected.task_id, "high")
        self.assertEqual(r.reason, "highest_effective_priority_ready")
        self.assertTrue(r.goal_id)
        self.assertTrue(r.goal_contract_fingerprint)
        self.assertTrue(r.snapshot_fingerprint)
        self.assertIn(program_resource("p1"), r.required_leases)
        self.assertIsNotNone(r.governance)

    def test_unmet_deps_skipped(self):
        prog = self._prog()
        self._task("dep", status=TaskState.DISCOVERED, goal=False)
        self._task("child", deps=["dep"], business_value=99)
        r = select_next_task(self.store, prog, now=NOW)
        self.assertFalse(r.would_execute)
        self.assertEqual(r.reason, "no_executable_tasks")
        self.assertTrue(any(s["reason"] == "dependencies_unmet" for s in r.skipped))

    def test_wip_blocks_second_mutate(self):
        prog = self._prog()
        self._task("wip", status=TaskState.EXECUTING, goal=False)
        self._task("ready", business_value=99)
        r = select_next_task(self.store, prog, now=NOW)
        self.assertFalse(r.would_execute)
        self.assertTrue(r.reason.startswith("wip_active:"))

    def test_deny_and_continue_across_programs(self):
        a = self._prog("pa", blockers=["human"])
        b = self._prog("pb")
        self._task("a1", program_id="pa", business_value=99)
        self._task("b1", program_id="pb", business_value=10)
        plans = select_across_programs(self.store, [a, b], now=NOW)
        by = {p.program_id: p for p in plans}
        self.assertFalse(by["pa"].would_execute)
        self.assertTrue(by["pa"].reason.startswith("program_blocked:"))
        self.assertTrue(by["pb"].would_execute)
        self.assertEqual(by["pb"].selected.task_id, "b1")

    def test_live_foreign_lease_skip_expired_selectable(self):
        prog = self._prog()
        self._task("t1", business_value=50)
        key = program_resource("p1")
        acquire(self.store, key, task_id="other", worker="w")
        r = select_next_task(self.store, prog, now=NOW)
        self.assertFalse(r.would_execute)
        self.assertTrue(any("lease_busy" in s["reason"] for s in r.skipped))

        # Expired lease is potentially available (H4 CAS); planner does not mutate.
        past = _iso(NOW - timedelta(hours=2))
        self.store.put_lease({
            "lease_id": "old", "resource_key": key, "holder_task_id": "other",
            "holder_worker": "w", "expires_at": past, "fencing_token": 1, "payload": {},
        })
        r2 = select_next_task(self.store, prog, now=NOW)
        self.assertTrue(r2.would_execute)
        self.assertEqual(r2.selected.task_id, "t1")
        # Read-only: expired row still present
        self.assertIsNotNone(self.store.get_lease(key))

    def test_task_id_alone_not_ownership(self):
        prog = self._prog()
        t = self._task("t1", business_value=50)
        key = program_resource("p1")
        lease = acquire(self.store, key, task_id="t1", worker="w")
        # Matching holder_task_id but missing lease_id/fencing on task → busy
        t.lease_id = ""
        t.lease_fencing_token = 0
        self.store.upsert_task(t)
        ok, why = lease_is_available(self.store, key, task=t, now=NOW)
        self.assertFalse(ok)
        self.assertIn("lease_busy", why)
        r = select_next_task(self.store, prog, now=NOW)
        self.assertFalse(r.would_execute)

        # Full fencing evidence → owned
        t.lease_id = lease["lease_id"]
        t.lease_fencing_token = int(lease["fencing_token"])
        self.store.upsert_task(t)
        ok2, why2 = lease_is_available(self.store, key, task=self.store.get_task("t1"), now=NOW)
        self.assertTrue(ok2)
        self.assertEqual(why2, "owned_with_fencing")
        r2 = select_next_task(self.store, prog, now=NOW)
        self.assertTrue(r2.would_execute)

    def test_auth_path_founder_required(self):
        prog = self._prog()
        self._task(
            "auth",
            business_value=99,
            paths=["backend/app/Http/Controllers/Auth/LoginController.php"],
        )
        self._task("docs", business_value=10)
        r = select_next_task(self.store, prog, now=NOW)
        self.assertTrue(r.would_execute)
        self.assertEqual(r.selected.task_id, "docs")
        self.assertTrue(
            any(s["reason"] == "founder_required_by_governance" for s in r.skipped)
        )

    def test_starvation_aging_uses_ready_since_not_updated_at(self):
        prog = self._prog()
        old_ready = _iso(NOW - timedelta(days=10))
        # Older lower BV with long READY wait
        self._task("old", business_value=50, ready_since=old_ready)
        # Newer higher BV just became READY (updated_at can be stale/noisy)
        young = self._task(
            "young", business_value=99, ready_since=_iso(NOW),
        )
        young.updated_at = _iso(NOW - timedelta(days=30))
        self.store.upsert_task(young)

        self.assertGreater(aging_boost(self.store.get_task("old"), now=NOW), 0)
        self.assertEqual(aging_boost(self.store.get_task("young"), now=NOW), 0)
        # No ready_since → no aging even if updated_at is ancient
        bare = Task(
            task_id="bare", program_id="p1", outcome="docs", status=TaskState.READY,
            business_value=1, ready_since="", updated_at=_iso(NOW - timedelta(days=40)),
            affected_paths=["docs/x.md"], designed_slice=True,
        )
        self.assertEqual(aging_boost(bare, now=NOW), 0)

        r = select_next_task(self.store, prog, now=NOW)
        self.assertTrue(r.would_execute)
        self.assertEqual(r.selected.task_id, "old")
        self.assertGreater(
            effective_priority(self.store.get_task("old"), now=NOW),
            effective_priority(self.store.get_task("young"), now=NOW),
        )

    def test_determinism_same_fixture_same_plan_id(self):
        prog = self._prog()
        self._task("a", business_value=40)
        self._task("b", business_value=80)
        r1 = select_next_task(self.store, prog, now=NOW, observed_main_sha=SHA)
        r2 = select_next_task(self.store, prog, now=NOW, observed_main_sha=SHA)
        self.assertEqual(r1.plan_id, r2.plan_id)
        self.assertEqual(r1.snapshot_fingerprint, r2.snapshot_fingerprint)
        self.assertEqual(r1.to_dict(), r2.to_dict())
        # JSON stable round-trip
        self.assertEqual(
            json.loads(json.dumps(r1.to_dict(), sort_keys=True)),
            json.loads(json.dumps(r2.to_dict(), sort_keys=True)),
        )

    def test_dependency_cycle_no_select(self):
        prog = self._prog()
        self._task("c1", deps=["c2"], business_value=90)
        self._task("c2", deps=["c1"], business_value=90)
        r = select_next_task(self.store, prog, now=NOW)
        self.assertFalse(r.would_execute)
        self.assertTrue(any(s["reason"] == "dependency_cycle" for s in r.skipped))

    def test_empty_paths_skip(self):
        prog = self._prog()
        self._task("empty", paths=[], business_value=90)
        r = select_next_task(self.store, prog, now=NOW)
        self.assertFalse(r.would_execute)
        self.assertTrue(any(s["reason"] == "missing_paths" for s in r.skipped))

    def test_goal_scope_drift_skip(self):
        prog = self._prog()
        self._task(
            "drift",
            business_value=90,
            paths=["docs/harness/HARNESS_STATUS.md"],
            goal=False,
        )
        g = bind_goal(
            program_id="p1", task_id="drift", outcome="docs", subject_sha=SHA,
            scope=["docs/other/**"], declared_risk="R0", declared_tier="T0",
        )
        self.store.put_goal(g)
        r = select_next_task(self.store, prog, now=NOW)
        self.assertFalse(r.would_execute)
        self.assertTrue(any(s["reason"] == "scope_drift" for s in r.skipped))

    def test_missing_goal_surfaces_but_not_execute(self):
        prog = self._prog()
        self._task("nog", business_value=80, goal=False)
        r = select_next_task(self.store, prog, now=NOW)
        self.assertEqual(r.selected.task_id, "nog")
        self.assertFalse(r.would_execute)
        self.assertEqual(r.reason, "missing_goal_contract")
        self.assertIsNone(r.goal_id)

    def test_plan_binds_world_fields(self):
        prog = self._prog()
        self._task("t1", business_value=50)
        r = select_next_task(self.store, prog, now=NOW, observed_main_sha=SHA)
        d = r.to_dict()
        for key in (
            "goal_id", "goal_contract_fingerprint", "observed_main_sha",
            "snapshot_fingerprint", "required_leases", "governance", "plan_id",
        ):
            self.assertIn(key, d)
        self.assertEqual(d["observed_main_sha"], SHA)
        self.assertTrue(d["would_execute"])

    def test_stale_goal_sha_skip_when_reconcile(self):
        prog = self._prog()
        self._task("t1", business_value=50)
        r = select_next_task(
            self.store, prog, now=NOW, observed_main_sha="b" * 40, reconcile_stale=True,
        )
        self.assertFalse(r.would_execute)
        self.assertTrue(any(s["reason"] == "stale_goal_sha" for s in r.skipped))

    def test_cli_plan_read_only_no_reclaim(self):
        from scripts.harness.cli import cmd_plan
        import argparse

        prog = self._prog()
        self._task("t1")
        key = program_resource("p1")
        past = _iso(NOW - timedelta(hours=2))
        self.store.put_lease({
            "lease_id": "expired", "resource_key": key, "holder_task_id": "dead",
            "holder_worker": "w", "expires_at": past, "fencing_token": 1, "payload": {},
        })
        args = argparse.Namespace(
            db=str(self.db), sync=False, program="p1", main_sha=None,
        )
        # Capture stdout
        import io
        from contextlib import redirect_stdout
        buf = io.StringIO()
        with redirect_stdout(buf):
            code = cmd_plan(args)
        self.assertIn(code, (0, 1))
        # Expired lease must still exist — plan does not reclaim
        self.assertIsNotNone(self.store.get_lease(key))
        self.assertEqual(self.store.get_lease(key)["lease_id"], "expired")


if __name__ == "__main__":
    unittest.main()
