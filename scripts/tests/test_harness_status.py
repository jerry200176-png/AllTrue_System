import json
import subprocess
import sys
import tempfile
import time
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

from scripts.harness.leases import acquire
from scripts.harness.models import Program, Task
from scripts.harness.reconcile import observe_workers, write_checkpoint
from scripts.harness.states import TaskState
from scripts.harness.store import HarnessStore


ROOT = Path(__file__).resolve().parents[2]


class HarnessStatusTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.db = Path(self.temp.name) / "harness.sqlite"
        self.store = HarnessStore(self.db)
        self.addCleanup(self.store.close)

    def seed(self, *, now, progress, ttl_sec=3600, session_id="test-session"):
        self.store.upsert_program(Program(
            program_id="p", name="Test", owner="engineering", repository="example/test",
            goal="Synthetic validation", canonical_status_source="docs/status.md",
        ))
        task = Task(task_id="t", program_id="p", outcome="Verify recovery",
                    status=TaskState.EXECUTING, updated_at=progress.isoformat(),
                    branch="fix/test", worktree="/example/test", merge_sha="a" * 40)
        self.store.upsert_task(task)
        lease = acquire(self.store, "program:p:mutating", task_id="t", worker="worker",
                        ttl_sec=ttl_sec, now=now)
        binding = {"bindings": [{"resource_key": lease["resource_key"], "lease_id": lease["lease_id"],
                                 "fencing_token": lease["fencing_token"], "task_id": "t"}]}
        self.store.put_dispatch_attempt({
            "attempt_id": "attempt", "plan_id": "plan", "task_id": "t", "goal_id": "",
            "goal_contract_fingerprint": "", "status": "active", "worker": "worker",
            "payload": {"lease_binding": binding}, "created_at": progress.isoformat(), "updated_at": now.isoformat(),
        })
        self.store.put_worker_run({
            "run_id": "worker-run", "attempt_id": "attempt", "task_id": "t", "mode": "start",
            "status": "running", "session_id": session_id, "branch": task.branch, "worktree_path": task.worktree,
            "worker": "worker", "fencing_token": lease["fencing_token"], "lease_binding": binding,
            "payload": {"internal": "must not appear in status output"},
            "created_at": progress.isoformat(), "updated_at": progress.isoformat(),
        })
        return task, lease

    def cli(self, *args):
        result = subprocess.run(
            [sys.executable, "-m", "scripts.harness", "--db", str(self.db), *args],
            cwd=ROOT, text=True, capture_output=True, check=True, timeout=6,
        )
        self.assertNotIn("must not appear", result.stdout)
        return json.loads(result.stdout)

    def cli_status(self):
        return self.cli("status", "--json")

    def test_normal_slow_work_and_external_wait_are_distinct_from_stalled_progress(self):
        now = datetime(2026, 11, 21, 9, tzinfo=timezone.utc)
        task, _ = self.seed(now=now, progress=now - timedelta(minutes=40))
        observe = lambda threshold: observe_workers(self.store, now=now, stale_after=timedelta(minutes=threshold))[0]
        self.assertEqual("running", observe(60)["observation"])
        stale = observe(30)
        self.assertEqual("progress_unconfirmed", stale["observation"])
        self.assertEqual(stale["attention_key"], observe(30)["attention_key"])

        task.status = TaskState.CI_PENDING
        self.store.upsert_task(task)
        self.assertEqual("external_wait_recorded", observe(30)["observation"])
        self.assertIsNone(observe(30)["attention_key"])
        task.status = TaskState.FOUNDER_REQUIRED
        self.store.upsert_task(task)
        self.assertEqual("founder_required", observe(30)["observation"])

    def test_separate_observer_detects_interrupted_worker_and_preserves_recovery_state(self):
        with subprocess.Popen([sys.executable, "-c", "import time; time.sleep(60)"]) as worker:
            try:
                now = datetime.now(timezone.utc)
                task, lease = self.seed(now=now, progress=now, ttl_sec=4, session_id=str(worker.pid))
                checkpoint = write_checkpoint(self.store, task, evidence_ids=["existing-local-effect-receipt"])
                self.assertEqual("running", self.cli_status()["worker_runs_open"][0]["observation"])
            finally:
                worker.terminate()
                worker.wait(timeout=4)
        deadline = time.monotonic() + 6
        while True:
            observed = self.cli_status()["worker_runs_open"][0]
            if observed["observation"] == "lease_lost" or time.monotonic() >= deadline:
                break
            time.sleep(0.1)
        self.assertEqual("lease_lost", observed["observation"])
        self.assertEqual(lease, self.store.get_lease(lease["resource_key"]))
        self.assertEqual(observed["attention_key"], self.cli_status()["worker_runs_open"][0]["attention_key"])
        restored = self.cli("resume", "--checkpoint", checkpoint.checkpoint_id)
        self.assertEqual("a" * 40, restored["checkpoint"]["subject_sha"])
        self.assertEqual(["existing-local-effect-receipt"], restored["checkpoint"]["evidence_ids"])
        self.assertEqual("fix/test", restored["task"]["branch"])
        self.assertEqual(lease, self.store.get_lease(lease["resource_key"]))
        self.assertEqual(1, len(self.store.list_worker_runs()))

    def test_observation_reads_a_consistent_snapshot_during_a_concurrent_write(self):
        now = datetime.now(timezone.utc)
        task, _ = self.seed(now=now, progress=now)
        observer = HarnessStore(self.db, create=False, read_only=True)
        self.addCleanup(observer.close)
        with observer.read_snapshot():
            self.assertEqual(TaskState.EXECUTING, observer.get_task("t").status)
            task.status = TaskState.CI_PENDING
            self.store.upsert_task(task)
            state = observe_workers(observer, now=now, stale_after=timedelta(hours=1))[0]
            self.assertEqual("running", state["observation"])
        state = observe_workers(observer, now=now, stale_after=timedelta(hours=1))[0]
        self.assertEqual("external_wait_recorded", state["observation"])


if __name__ == "__main__":
    unittest.main()
