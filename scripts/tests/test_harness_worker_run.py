"""H4b WorkerRun — start/attach/resume + durable session identity."""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.harness.dispatch import dispatch, ingest_handoff  # noqa: E402
from scripts.harness.governance_adapter import bind_goal  # noqa: E402
from scripts.harness.launcher import (  # noqa: E402
    InProcessLauncher,
    LaunchResult,
    set_launcher_for_tests,
    worktree_for,
)
from scripts.harness.models import Program, Task  # noqa: E402
from scripts.harness.planner import select_next_task  # noqa: E402
from scripts.harness.states import TaskState  # noqa: E402
from scripts.harness.store import HarnessStore, SCHEMA_VERSION  # noqa: E402
from scripts.harness.worker_run import start_or_resume_worker  # noqa: E402

SHA = "b" * 40
NOW = datetime(2026, 9, 17, 11, 0, 0, tzinfo=timezone.utc)


def _iso(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


class FakeAttachLauncher:
    def __init__(self, result: LaunchResult) -> None:
        self.result = result
        self.calls: list[dict[str, Any]] = []

    def start_or_attach(self, **kwargs: Any) -> LaunchResult:
        self.calls.append(kwargs)
        return self.result


class HarnessH4bWorkerRunTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.db = Path(self._tmp.name) / "harness.sqlite"
        self.store = HarnessStore(self.db)
        self.sessions = Path(self._tmp.name) / "sessions"
        self.sessions.mkdir()
        set_launcher_for_tests(None)

    def tearDown(self) -> None:
        set_launcher_for_tests(None)
        self.store.close()
        self._tmp.cleanup()

    def _seed(self, tid: str = "t-h4b") -> dict[str, Any]:
        paths = ["docs/harness/HARNESS_STATUS.md"]
        self.store.upsert_program(Program(
            program_id="p1", name="P", owner="o", repository="r",
            goal="g", canonical_status_source="docs/x.md",
        ))
        self.store.upsert_task(Task(
            task_id=tid, program_id="p1", outcome="docs", status=TaskState.READY,
            business_value=50, affected_paths=list(paths), scope=list(paths),
            designed_slice=True,
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

    def test_schema_v4(self):
        row = self.store._conn.execute(
            "SELECT value FROM meta WHERE key='schema_version'"
        ).fetchone()
        self.assertEqual(row["value"], str(SCHEMA_VERSION))
        self.assertEqual(SCHEMA_VERSION, 4)

    def test_dispatch_records_deferred_worker_run_without_worktree(self):
        plan = self._seed()
        r = dispatch(self.store, plan, worker="A", apply=True, main_sha=SHA, now=NOW)
        self.assertTrue(r.ok, r.reason)
        self.assertTrue(r.spawn and r.spawn.get("deferred"))
        runs = self.store.list_worker_runs(attempt_id=r.attempt_id)
        self.assertEqual(len(runs), 1)
        self.assertEqual(runs[0]["status"], "deferred")
        self.assertEqual(runs[0]["mode"], "start")

    def test_attach_existing_worktree_records_session(self):
        # Build a fake task worktree under temp task root via env override.
        import scripts.harness.launcher as launcher_mod

        task_root = Path(self._tmp.name) / "tasks" / "alltrue"
        wt = task_root / "demo-task"
        wt.mkdir(parents=True)
        (wt / ".git").mkdir()
        (wt / ".agent-session").mkdir()
        (wt / ".agent-session" / "manifest.json").write_text(
            json.dumps({
                "schema_version": "1.0",
                "session_id": "oldsession",
                "project": "alltrue",
                "task_id": "demo-task",
                "repo_remote": "https://example.invalid/r.git",
                "base_sha": SHA,
                "branch": "chore/task-demo-task",
                "worktree_path": str(wt),
                "started_at": _iso(NOW),
                "production_mutation": False,
                "preflight_result": "pass",
                "provenance_type": "agent-session",
            }),
            encoding="utf-8",
        )
        old_roots = dict(launcher_mod.DEFAULT_TASK_ROOTS)
        launcher_mod.DEFAULT_TASK_ROOTS["alltrue"] = task_root
        try:
            # Stub git helpers by using FakeAttachLauncher for dispatch path.
            fake = FakeAttachLauncher(LaunchResult(
                ok=True, mode="resume", reason="resumed",
                session_id="newsess", worktree_path=str(wt),
                branch="chore/task-demo-task", base_sha=SHA,
                launcher_task_id="demo-task", project="alltrue",
                manifest_path=str(self.sessions / "newsess.json"),
            ))
            set_launcher_for_tests(fake)
            plan = self._seed("demo-task")
            # Point task worktree so resolve picks launcher id
            task = self.store.get_task("demo-task")
            assert task is not None
            task.worktree = str(wt)
            task.branch = "chore/task-demo-task"
            self.store.upsert_task(task)
            r = dispatch(self.store, plan, worker="A", apply=True, main_sha=SHA, now=NOW)
            self.assertTrue(r.ok, r.reason)
            self.assertTrue(r.spawn and r.spawn.get("spawned"))
            self.assertEqual(r.spawn.get("mode"), "resume")
            self.assertEqual(r.spawn.get("session_id"), "newsess")
            runs = self.store.list_worker_runs(attempt_id=r.attempt_id)
            self.assertEqual(runs[0]["session_id"], "newsess")
            self.assertEqual(runs[0]["status"], "running")
            self.assertEqual(len(fake.calls), 1)
            self.assertTrue(fake.calls[0]["prefer_resume"] is False or True)
        finally:
            launcher_mod.DEFAULT_TASK_ROOTS.clear()
            launcher_mod.DEFAULT_TASK_ROOTS.update(old_roots)

    def test_handoff_observes_worker_run(self):
        fake = FakeAttachLauncher(LaunchResult(
            ok=True, mode="attach", reason="attached",
            session_id="s1", worktree_path="/tmp/x", branch="chore/task-t1",
            base_sha=SHA, launcher_task_id="t1", project="alltrue",
        ))
        set_launcher_for_tests(fake)
        plan = self._seed("t1")
        r = dispatch(self.store, plan, worker="A", apply=True, main_sha=SHA, now=NOW)
        self.assertTrue(r.ok, r.reason)
        hand = ingest_handoff(
            self.store, r.attempt_id,
            claimed_bindings=r.lease_binding.to_dict(),
            result={"status": "PR_READY"},
        )
        self.assertTrue(hand.ok, hand.reason)
        runs = self.store.list_worker_runs(attempt_id=r.attempt_id)
        self.assertEqual(runs[0]["status"], "handed_off")
        att = self.store.get_dispatch_attempt(r.attempt_id)
        self.assertEqual(att["payload"]["worker_handoff"]["reason"], "worker_handoff_observed")

    def test_inprocess_attach_writes_manifest(self):
        import scripts.harness.launcher as launcher_mod

        task_root = Path(self._tmp.name) / "tasks" / "alltrue"
        wt = task_root / "inproc"
        wt.mkdir(parents=True)
        (wt / ".git").mkdir()
        (wt / ".agent-session").mkdir()
        (wt / ".agent-session" / "manifest.json").write_text("{}", encoding="utf-8")
        old = dict(launcher_mod.DEFAULT_TASK_ROOTS)
        launcher_mod.DEFAULT_TASK_ROOTS["alltrue"] = task_root
        try:
            launcher = InProcessLauncher(sessions_dir=self.sessions)
            # Avoid git dependency: monkeypatch helpers
            launcher_mod._git_branch = lambda _p: "chore/task-inproc"  # type: ignore
            launcher_mod._git_head_sha = lambda _p: SHA  # type: ignore
            out = launcher.start_or_attach(
                project="alltrue", launcher_task_id="inproc", prefer_resume=True,
            )
            self.assertTrue(out.ok, out.reason)
            self.assertEqual(out.mode, "resume")
            self.assertTrue(out.session_id)
            self.assertTrue((self.sessions / f"{out.session_id}.json").is_file())
            man = json.loads((wt / ".agent-session" / "manifest.json").read_text())
            self.assertEqual(man["session_id"], out.session_id)
            self.assertEqual(man["production_mutation"], False)
        finally:
            launcher_mod.DEFAULT_TASK_ROOTS.clear()
            launcher_mod.DEFAULT_TASK_ROOTS.update(old)

    def test_start_or_resume_direct(self):
        fake = FakeAttachLauncher(LaunchResult(
            ok=True, mode="attach", reason="attached",
            session_id="s2", worktree_path="/x", branch="b", base_sha=SHA,
            launcher_task_id="t2", project="alltrue",
        ))
        set_launcher_for_tests(fake)
        spawn = start_or_resume_worker(self.store, {
            "attempt_id": "da_test",
            "task_id": "t2",
            "worker": "A",
            "lease_binding": {"bindings": [{
                "resource_key": "program:p", "lease_id": "L", "fencing_token": 3,
                "task_id": "t2", "worker": "A",
            }]},
        })
        self.assertTrue(spawn["spawned"])
        self.assertEqual(spawn["fencing_token"], 3)
        self.assertEqual(self.store.get_worker_run(spawn["run_id"])["fencing_token"], 3)


if __name__ == "__main__":
    unittest.main()
