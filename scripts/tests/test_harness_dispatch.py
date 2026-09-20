"""H4 dispatch evals — Founder amendments A1–A8; no production."""

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

from scripts.harness.dispatch import (  # noqa: E402
    dispatch, heartbeat, ingest_handoff, release_on_pr_ready, revalidate_plan,
)
from scripts.harness.governance_adapter import bind_goal  # noqa: E402
from scripts.harness.leases import acquire, program_resource  # noqa: E402
from scripts.harness.models import Program, Task  # noqa: E402
from scripts.harness.planner import select_next_task  # noqa: E402
from scripts.harness.states import TaskState  # noqa: E402
from scripts.harness.store import HarnessStore  # noqa: E402

SHA = "a" * 40
NOW = datetime(2026, 9, 17, 8, 0, 0, tzinfo=timezone.utc)


def _iso(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


class HarnessH4DispatchTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.db = Path(self._tmp.name) / "harness.sqlite"
        self.store = HarnessStore(self.db)

    def tearDown(self) -> None:
        self.store.close()
        self._tmp.cleanup()

    def _seed(self, *, paths: list[str] | None = None, contracts: list[str] | None = None,
              value: int = 50, tid: str = "t1") -> dict[str, Any]:
        paths = paths or ["docs/harness/HARNESS_STATUS.md"]
        self.store.upsert_program(Program(
            program_id="p1", name="P", owner="o", repository="r",
            goal="g", canonical_status_source="docs/x.md",
        ))
        self.store.upsert_task(Task(
            task_id=tid, program_id="p1", outcome="docs", status=TaskState.READY,
            business_value=value, affected_paths=list(paths), scope=list(paths),
            affected_contracts=list(contracts or []), designed_slice=True,
        ))
        self.store.record_transition(
            tid, TaskState.DISCOVERED, TaskState.READY, "test", {}, _iso(NOW),
        )
        g = bind_goal(
            program_id="p1", task_id=tid, outcome="docs", subject_sha=SHA,
            scope=["docs/harness/**"], declared_risk="R0", declared_tier="T0",
        )
        self.store.put_goal(g)
        plan = select_next_task(self.store, program_id="p1", now=NOW, main_sha=SHA)
        self.assertTrue(plan.would_execute, plan.reason)
        return plan.to_dict()

    def test_happy_path_apply(self):
        plan = self._seed()
        # A1: time-dependent snapshot mismatch must not block if critical fields hold
        plan["input_snapshot_fingerprint"] = "stale_clock_fingerprint"
        r = dispatch(self.store, plan, worker="w1", apply=True, main_sha=SHA, now=NOW)
        self.assertTrue(r.ok, r.reason)
        self.assertEqual(r.reason, "dispatched")
        self.assertTrue(r.attempt_id)
        self.assertIsNotNone(r.lease_binding)
        self.assertGreaterEqual(len(r.lease_binding.bindings), 1)
        self.assertEqual(self.store.get_task("t1").status, TaskState.LEASED)
        att = self.store.get_dispatch_attempt(r.attempt_id)
        self.assertEqual(att["status"], "active")
        spawn = att["payload"].get("spawn") or {}
        self.assertTrue(spawn.get("deferred") or spawn.get("spawned") is False)
        self.assertTrue(spawn.get("run_id"))
        runs = self.store.list_worker_runs(attempt_id=r.attempt_id)
        self.assertEqual(len(runs), 1)

    def test_dry_run_no_mutate(self):
        plan = self._seed()
        before = self.store.list_leases()
        r = dispatch(self.store, plan, worker="w1", apply=False, main_sha=SHA, now=NOW)
        self.assertTrue(r.ok)
        self.assertEqual(r.reason, "dry_run_ok")
        self.assertTrue(r.would_mutate)
        self.assertEqual(self.store.list_leases(), before)
        self.assertEqual(self.store.get_task("t1").status, TaskState.READY)

    def test_would_execute_false_rejected(self):
        plan = self._seed()
        plan["would_execute"] = False
        r = dispatch(self.store, plan, worker="w1", apply=True, main_sha=SHA, now=NOW)
        self.assertFalse(r.ok)
        self.assertEqual(r.reason, "plan_not_executable")

    def test_stale_goal_fp(self):
        plan = self._seed()
        plan["goal_contract_fingerprint"] = "deadbeef"
        r = dispatch(self.store, plan, worker="w1", apply=True, main_sha=SHA, now=NOW)
        self.assertFalse(r.ok)
        self.assertEqual(r.reason, "stale_goal_fp")

    def test_missing_goal_contract(self):
        plan = self._seed()
        plan["goal_id"] = "missing"
        r = dispatch(self.store, plan, worker="w1", apply=True, main_sha=SHA, now=NOW)
        self.assertFalse(r.ok)
        self.assertEqual(r.reason, "missing_goal_contract")

    def test_foreign_lease_conflict_rollback(self):
        plan = self._seed(contracts=["auth"])
        keys = list(plan["required_leases"])
        self.assertGreaterEqual(len(keys), 2)
        # Hold second resource so first acquire succeeds then conflict → rollback
        acquire(self.store, keys[1], task_id="other", worker="foreign", now=NOW)
        r = dispatch(self.store, plan, worker="w1", apply=True, main_sha=SHA, now=NOW)
        self.assertFalse(r.ok)
        self.assertIn(r.reason, {"lease_conflict", "partial_acquire_rollback_failed"})
        # Program lease must not remain held by t1 after rollback
        prog = self.store.get_lease(program_resource("p1"))
        self.assertTrue(prog is None or prog.get("holder_task_id") != "t1")
        self.assertEqual(self.store.get_task("t1").status, TaskState.READY)

    def test_heartbeat_renews_fencing(self):
        plan = self._seed()
        r = dispatch(self.store, plan, worker="w1", apply=True, main_sha=SHA, now=NOW)
        self.assertTrue(r.ok)
        before = r.lease_binding.bindings[0].fencing_token
        hb = heartbeat(self.store, r.attempt_id, worker="w1", now=NOW + timedelta(minutes=1))
        self.assertTrue(hb.ok, hb.reason)
        self.assertGreater(hb.lease_binding.bindings[0].fencing_token, before)

    def test_stale_worker_handoff_rejected(self):
        plan = self._seed()
        r = dispatch(self.store, plan, worker="w1", apply=True, main_sha=SHA, now=NOW)
        self.assertTrue(r.ok)
        stale = {
            "bindings": [{
                **r.lease_binding.bindings[0].to_dict(),
                "fencing_token": 999,
            }]
        }
        bad = ingest_handoff(self.store, r.attempt_id, claimed_bindings=stale, result={"x": 1})
        self.assertFalse(bad.ok)
        self.assertEqual(bad.reason, "stale_worker_fencing")
        good = ingest_handoff(
            self.store, r.attempt_id,
            claimed_bindings=r.lease_binding.to_dict(),
            result={"pr_ready": True},
        )
        self.assertTrue(good.ok, good.reason)

    def test_release_on_pr_ready(self):
        plan = self._seed()
        r = dispatch(self.store, plan, worker="w1", apply=True, main_sha=SHA, now=NOW)
        rel = release_on_pr_ready(
            self.store, r.attempt_id,
            handoff={"lease_binding": r.lease_binding.to_dict(), "result": {"status": "PR_READY"}},
        )
        self.assertTrue(rel.ok, rel.reason)
        self.assertEqual(rel.reason, "leases_released_pr_ready")
        self.assertIsNone(self.store.get_lease(program_resource("p1")))
        att = self.store.get_dispatch_attempt(r.attempt_id)
        self.assertEqual(att["status"], "released")

    def test_dispatch_attempt_before_spawn(self):
        seen: list[str] = []

        def spawn(ctx):
            att = self.store.get_dispatch_attempt(str(ctx["attempt_id"]))
            seen.append(att["status"] if att else "missing")
            return {"spawned": True, "deferred": False}

        plan = self._seed()
        r = dispatch(
            self.store, plan, worker="w1", apply=True, main_sha=SHA, now=NOW, spawn_hook=spawn,
        )
        self.assertTrue(r.ok)
        # Attempt existed as acquired before spawn hook ran
        self.assertEqual(seen, ["acquired"])

    def test_concurrent_dispatch_exactly_one_winner(self):
        plan = self._seed()
        barrier = threading.Barrier(2)
        results: list[Any] = []
        lock = threading.Lock()

        def worker(name: str) -> None:
            store = HarnessStore(self.db)
            try:
                barrier.wait(timeout=5)
                out = dispatch(
                    store, plan, worker=name, apply=True, main_sha=SHA, now=NOW,
                )
                with lock:
                    results.append(out)
            finally:
                store.close()

        t1 = threading.Thread(target=worker, args=("A",))
        t2 = threading.Thread(target=worker, args=("B",))
        t1.start(); t2.start()
        t1.join(timeout=15); t2.join(timeout=15)
        wins = [r for r in results if r.ok]
        losses = [r for r in results if not r.ok]
        self.assertEqual(len(wins), 1, msg=[r.reason for r in results])
        self.assertEqual(len(losses), 1)
        self.assertIn(
            losses[0].reason,
            {"dispatch_attempt_conflict", "lease_conflict", "partial_acquire_rollback_failed"},
        )

    def test_execution_critical_ignores_snapshot_clock(self):
        plan = self._seed()
        plan["input_snapshot_fingerprint"] = "aaa"
        err, ctx = revalidate_plan(self.store, plan, main_sha=SHA)
        self.assertIsNone(err)
        plan["input_snapshot_fingerprint"] = "bbb"
        err2, ctx2 = revalidate_plan(self.store, plan, main_sha=SHA)
        self.assertIsNone(err2)
        self.assertEqual(ctx["execution_critical_fingerprint"], ctx2["execution_critical_fingerprint"])

    def test_deny_and_continue_peer_program_untouched(self):
        plan = self._seed()
        self.store.upsert_program(Program(
            program_id="p2", name="P2", owner="o", repository="r",
            goal="g", canonical_status_source="docs/x.md",
        ))
        self.store.upsert_task(Task(
            task_id="peer", program_id="p2", outcome="docs", status=TaskState.READY,
            business_value=10, affected_paths=["docs/harness/x.md"], designed_slice=True,
        ))
        acquire(self.store, program_resource("p1"), task_id="busy", worker="x", now=NOW)
        r = dispatch(self.store, plan, worker="w1", apply=True, main_sha=SHA, now=NOW)
        self.assertFalse(r.ok)
        peer = self.store.get_task("peer")
        self.assertEqual(peer.status, TaskState.READY)
        self.assertIsNone(self.store.get_lease(program_resource("p2")))


if __name__ == "__main__":
    unittest.main()
