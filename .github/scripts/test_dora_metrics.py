"""Real-shaped run/job evidence for the AllTrue weekly DORA report."""

import json
import os
import re
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import dora_metrics as dora

NOW = datetime(2026, 10, 4, 4, 0, tzinfo=timezone.utc)
SHA = "e483c1dc43975cfa173b12e75376fa51dc107a30"


def run(run_id, status, conclusion, sha=SHA, attempt=1, event="workflow_run",
        created="2026-10-03T17:01:08Z"):
    return {"id": run_id, "run_attempt": attempt, "path": ".github/workflows/deploy.yml",
            "status": status, "conclusion": conclusion, "head_sha": sha, "event": event,
            "created_at": created, "updated_at": "2026-10-03T22:12:29Z"}


def job(conclusion, completed="2026-10-03T22:12:28Z", steps=None, attempt=1,
        run_id=37138976778):
    return {"name": "Deploy to Production", "status": "completed", "conclusion": conclusion,
            "completed_at": completed, "steps": steps if steps is not None else [],
            "run_id": run_id, "run_attempt": attempt, "head_sha": SHA}


GOOD = job("success", steps=[
    {"name": "Deploy", "status": "completed", "conclusion": "success"},
    {"name": "Record deployed and production-verified state", "status": "completed", "conclusion": "success"},
])
SKIPPED = job("skipped")


class FakeGH:
    def __init__(self, responses):
        self.responses = responses
        self.calls = 0

    def get(self, path, params=None):
        self.calls += 1
        page = (params or {}).get("page", 1)
        value = self.responses.get((path, page))
        if value is None:
            raise dora.UnknownEvidence("fixture API failure")
        return value


class DoraEvidenceTest(unittest.TestCase):
    def test_real_run_shapes_count_only_completed_deploy_job(self):
        cases = [
            run(37138976778, "completed", "success"),  # actual production deploy
            run(37174446889, "completed", "success", sha="f" * 40),  # workflow success, deploy skipped
            run(37169285343, "waiting", None),  # Founder gate pending
            run(37092564565, "completed", "failure"),  # exact-main gate failed before deploy
        ]
        responses = {
            ("actions/runs/37138976778/attempts/1/jobs", 1): {"total_count": 10, "jobs": [GOOD] + [{}] * 9},
            ("actions/runs/37174446889/attempts/1/jobs", 1): {"total_count": 10, "jobs": [job("skipped", run_id=37174446889)] + [{}] * 9},
            ("actions/runs/37092564565/attempts/1/jobs", 1): {"total_count": 10, "jobs": [job("skipped", run_id=37092564565)] + [{}] * 9},
        }
        receipts = dora.verified_receipts(FakeGH(responses), cases, NOW - timedelta(days=30), NOW)
        self.assertEqual([(37138976778, 1, SHA)], [(r["run_id"], r["attempt"], r["sha"]) for r in receipts])

    def test_two_page_api_is_complete_and_missing_page_is_unknown(self):
        path = "actions/workflows/deploy.yml/runs"
        first = {"total_count": 101, "workflow_runs": [{"id": n} for n in range(100)]}
        second = {"total_count": 101, "workflow_runs": [{"id": 100}]}
        self.assertEqual(101, len(dora.pages(FakeGH({(path, 1): first, (path, 2): second}), path, "workflow_runs", {})))
        with self.assertRaises(dora.UnknownEvidence):
            dora.pages(FakeGH({(path, 1): first}), path, "workflow_runs", {})
        with self.assertRaises(dora.UnknownEvidence):
            dora.pages(FakeGH({(path, 1): first, (path, 2): {"total_count": 100, "workflow_runs": []}}), path, "workflow_runs", {})
        with self.assertRaises(dora.UnknownEvidence):
            dora.pages(FakeGH({(path, 1): {"total_count": 1000, "workflow_runs": [{}] * 100}}), path, "workflow_runs", {})

    def test_old_creation_completed_in_window_and_old_update_skips_job_call(self):
        older = run(37138976778, "completed", "success", created="2026-08-31T10:00:00Z")
        stale = run(37100000000, "completed", "success", created="2026-08-31T10:00:00Z")
        stale["updated_at"] = "2026-09-01T10:00:00Z"
        gh = FakeGH({("actions/runs/37138976778/attempts/1/jobs", 1): {"total_count": 1, "jobs": [GOOD]}})
        receipts = dora.verified_receipts(gh, [older, stale], NOW - timedelta(days=30), NOW)
        self.assertEqual([37138976778], [r["run_id"] for r in receipts])
        self.assertEqual(1, gh.calls)

    def test_duplicate_slice_run_is_deduped_but_two_successful_attempts_count(self):
        duplicate = run(37138976778, "completed", "success", attempt=2,
                        created="2026-10-03T04:00:00Z")
        path = "actions/workflows/deploy.yml/runs"
        responses = {
            (path, 1): {"total_count": 1, "workflow_runs": [duplicate]},
            ("actions/runs/37138976778/attempts/1", 1): run(37138976778, "completed", "success"),
            ("actions/runs/37138976778/attempts/1/jobs", 1): {"total_count": 1, "jobs": [GOOD]},
            ("actions/runs/37138976778/attempts/2/jobs", 1): {"total_count": 1, "jobs": [job("success", steps=GOOD["steps"], attempt=2)]},
        }
        gh = FakeGH(responses)
        runs = dora.workflow_runs(gh, NOW - timedelta(days=8), NOW)
        self.assertEqual(1, len(runs))
        receipts = dora.verified_receipts(gh, runs, NOW - timedelta(days=30), NOW)
        self.assertEqual([(37138976778, 1), (37138976778, 2)], [(r["run_id"], r["attempt"]) for r in receipts])

    def test_missing_step_and_dispatch_target_fail_closed(self):
        cases = [run(37138976778, "completed", "success")]
        path = ("actions/runs/37138976778/attempts/1/jobs", 1)
        with self.assertRaises(dora.UnknownEvidence):
            dora.verified_receipts(FakeGH({path: {"total_count": 1, "jobs": [job("success")]}}), cases, NOW - timedelta(days=30), NOW)
        cases[0]["event"] = "workflow_dispatch"
        with self.assertRaises(dora.UnknownEvidence):
            dora.verified_receipts(FakeGH({path: {"total_count": 1, "jobs": [GOOD]}}), cases, NOW - timedelta(days=30), NOW)
        first = run(37138976778, "completed", "success")
        second = run(37138976779, "completed", "success", event="workflow_dispatch")
        with self.assertRaises(dora.UnknownEvidence) as failure:
            dora.verified_receipts(FakeGH({
                path: {"total_count": 1, "jobs": [GOOD]},
                ("actions/runs/37138976779/attempts/1/jobs", 1): {"total_count": 1, "jobs": [job("success", run_id=37138976779, steps=GOOD["steps"])]},
            }), [first, second], NOW - timedelta(days=30), NOW)
        self.assertEqual(1, failure.exception.lower_bound)

    def test_failed_workflow_after_verified_deploy_still_counts(self):
        failed_later = run(37138976778, "completed", "failure")
        path = ("actions/runs/37138976778/attempts/1/jobs", 1)
        receipts = dora.verified_receipts(FakeGH({path: {"total_count": 1, "jobs": [GOOD]}}),
                                          [failed_later], NOW - timedelta(days=30), NOW)
        self.assertEqual([(37138976778, 1)], [(r["run_id"], r["attempt"]) for r in receipts])

    def test_predeploy_failure_excluded_but_partial_deploy_unknown(self):
        failed = run(37092564565, "completed", "failure")
        path = ("actions/runs/37092564565/attempts/1/jobs", 1)
        self.assertEqual([], dora.verified_receipts(FakeGH({path: {"total_count": 1, "jobs": [
            job("failure", run_id=37092564565, steps=[{"name": "Final exact-main gate before production executor", "conclusion": "failure"}])]}}),
            [failed], NOW - timedelta(days=30), NOW))
        self.assertEqual([], dora.verified_receipts(FakeGH({path: {"total_count": 1, "jobs": [
            {"name": "Resolve exact activation target", "conclusion": "failure"}]}}),
            [failed], NOW - timedelta(days=30), NOW))
        with self.assertRaises(dora.UnknownEvidence):
            dora.verified_receipts(FakeGH({path: {"total_count": 1, "jobs": [
                job("failure", run_id=37092564565, steps=[])]}}),
                [failed], NOW - timedelta(days=30), NOW)
        with self.assertRaises(dora.UnknownEvidence):
            dora.verified_receipts(FakeGH({path: {"total_count": 1, "jobs": [
                job("failure", run_id=37092564565, steps=[{"name": "Deploy", "conclusion": "failure"}])]}}),
                [failed], NOW - timedelta(days=30), NOW)

    def test_unusual_completed_conclusions_depend_on_full_job_evidence(self):
        path = ("actions/runs/37092564565/attempts/1/jobs", 1)
        for conclusion in ("timed_out", "startup_failure", "stale", "neutral", "action_required"):
            with self.subTest(conclusion=conclusion):
                candidate = run(37092564565, "completed", conclusion)
                self.assertEqual([], dora.verified_receipts(FakeGH({path: {"total_count": 1, "jobs": [
                    job("skipped", run_id=37092564565)]}}),
                    [candidate], NOW - timedelta(days=30), NOW))
                with self.assertRaises(dora.UnknownEvidence):
                    dora.verified_receipts(FakeGH({path: {"total_count": 1, "jobs": [
                        job("failure", run_id=37092564565, steps=[{"name": "Deploy", "conclusion": "failure"}])]}}),
                        [candidate], NOW - timedelta(days=30), NOW)

    def test_receipt_names_match_committed_deploy_workflow(self):
        workflow = (Path(__file__).resolve().parents[1] / "workflows" / "deploy.yml").read_text()
        block = re.search(r"(?ms)^  deploy:\n(.*?)(?=^  [\w-]+:\n|\Z)", workflow)
        self.assertIsNotNone(block)
        self.assertRegex(block.group(1), rf"(?m)^    name: {re.escape(dora.DEPLOY_JOB)}$")
        for step in dora.REQUIRED_STEPS:
            self.assertEqual(1, len(re.findall(rf"(?m)^      - name: {re.escape(step)}$", block.group(1))))

    def test_reporter_path_is_control_plane_only(self):
        from scripts.governance.autonomy_gate import is_application_runtime_path

        self.assertFalse(is_application_runtime_path(".github/scripts/dora_metrics.py"))
        self.assertFalse(is_application_runtime_path(".github/scripts/test_dora_metrics.py"))
        self.assertTrue(is_application_runtime_path("scripts/dora_metrics.py"))

    def test_runtime_mismatch_and_api_failure_report_unknown(self):
        with patch.object(dora, "workflow_runs", return_value=[run(37138976778, "completed", "success")]):
            gh = FakeGH({("actions/runs/37138976778/attempts/1/jobs", 1): {"total_count": 1, "jobs": [GOOD]}})
            self.assertIn("Deployment Frequency: UNKNOWN", dora.report("owner/repo", NOW, gh, lambda: ("f" * 40, NOW)))
        with patch.object(dora, "workflow_runs", side_effect=dora.UnknownEvidence("API failed")):
            self.assertIn("Deployment Frequency: UNKNOWN", dora.report("owner/repo", NOW, FakeGH({}), lambda: (SHA, NOW)))
        with patch.object(dora, "workflow_runs", return_value=[]):
            self.assertIn("Deployment Frequency: UNKNOWN", dora.report("owner/repo", NOW, FakeGH({}), lambda: (SHA, NOW)))
            self.assertIn("after report cutoff", dora.report("owner/repo", NOW, FakeGH({}), lambda: (SHA, NOW + timedelta(seconds=1))))
            self.assertIn("Deployment Frequency: 0.0/week", dora.report("owner/repo", NOW, FakeGH({}), lambda: (SHA, NOW - timedelta(days=40))))
        self.assertIn("Deployment Rework Rate: UNKNOWN", dora.report("owner/repo", NOW, FakeGH({}), lambda: (SHA, NOW)))
        self.assertIn("Failed Deployment Recovery Time: UNKNOWN", dora.report("owner/repo", NOW, FakeGH({}), lambda: (SHA, NOW)))
        self.assertIn("As of: 2026-10-04T04:00:00Z", dora.report("owner/repo", NOW, FakeGH({}), lambda: (SHA, NOW)))

    def test_runtime_manifest_requires_deployed_at_and_source(self):
        manifest = {"backend_sha": SHA, "source": "github-actions:deploy.yml"}
        with patch.object(dora.subprocess, "run", return_value=SimpleNamespace(returncode=0, stdout=json.dumps(manifest))):
            with self.assertRaises(dora.UnknownEvidence):
                dora.runtime_identity()
        manifest["deployed_at"] = "2026-10-03T22:11:33Z"
        with patch.object(dora.subprocess, "run", return_value=SimpleNamespace(returncode=0, stdout=json.dumps(manifest))):
            self.assertEqual(SHA, dora.runtime_identity()[0])

    def test_unknown_frequency_writes_summary_and_fails_step(self):
        with tempfile.NamedTemporaryFile() as summary, patch.dict(os.environ, {"GITHUB_STEP_SUMMARY": summary.name}):
            with patch.object(dora, "report", return_value="Deployment Frequency: UNKNOWN\nChange Failure Rate: UNKNOWN"):
                self.assertEqual(1, dora.main())
            with open(summary.name, "rb") as content:
                self.assertIn(b"Deployment Frequency: UNKNOWN", content.read())
            with patch.object(dora, "report", return_value="Deployment Frequency: 0.0/week\nChange Failure Rate: UNKNOWN"):
                self.assertEqual(0, dora.main())


if __name__ == "__main__":
    unittest.main()
