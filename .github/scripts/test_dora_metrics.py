"""Real-shaped run/job evidence for the AllTrue weekly DORA report."""

import json
import hashlib
import io
import os
import re
import sys
import tempfile
import unittest
import zipfile
from datetime import datetime, timedelta, timezone
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import dora_metrics as dora

NOW = datetime(2026, 10, 4, 4, 0, tzinfo=timezone.utc)
SHA = "e483c1dc43975cfa173b12e75376fa51dc107a30"
EVENT_HEAD = "c" * 40
MANUAL_TARGET = "d" * 40


def run(run_id, status, conclusion, sha=SHA, attempt=1, event="workflow_run",
        created="2026-10-03T17:01:08Z"):
    return {"id": run_id, "run_attempt": attempt, "path": ".github/workflows/deploy.yml",
            "status": status, "conclusion": conclusion, "head_sha": sha, "event": event,
            "created_at": created, "updated_at": "2026-10-03T22:12:29Z"}


def job(conclusion, completed="2026-10-03T22:12:28Z", steps=None, attempt=1,
        run_id=37138976778, sha=SHA):
    return {"name": "Deploy to Production", "status": "completed", "conclusion": conclusion,
            "completed_at": completed, "steps": steps if steps is not None else [],
            "run_id": run_id, "run_attempt": attempt, "head_sha": sha}


GOOD = job("success", steps=[
    {"name": "Deploy", "status": "completed", "conclusion": "success"},
    {"name": "Record deployed and production-verified state", "status": "completed", "conclusion": "success"},
])
SKIPPED = job("skipped")


class FakeGH:
    def __init__(self, responses, archives=None, repo="owner/repo"):
        self.responses = responses
        self.archives = archives or {}
        self.repo = repo
        self.calls = 0

    def get(self, path, params=None):
        self.calls += 1
        page = (params or {}).get("page", 1)
        value = self.responses.get((path, page))
        if value is None:
            raise dora.UnknownEvidence("fixture API failure")
        return value

    def download_artifact(self, artifact_id):
        self.calls += 1
        if artifact_id not in self.archives:
            raise dora.UnknownEvidence("fixture artifact missing")
        return self.archives[artifact_id]


def manual_fixture(run_id=36955378940, attempt=1, target=MANUAL_TARGET):
    candidate = run(run_id, "completed", "success", sha=EVENT_HEAD,
                    attempt=attempt, event="workflow_dispatch")
    steps = GOOD["steps"] + [{"name": "Upload exact target deployment receipt",
                              "status": "completed", "conclusion": "success"}]
    deployed_job = job("success", run_id=run_id, attempt=attempt, sha=EVENT_HEAD, steps=steps)
    receipt = {
        "schema": 1, "repository": "owner/repo", "source_sha": target,
        "workflow_revision_sha": "b" * 40, "event_head_sha": EVENT_HEAD,
        "event": "workflow_dispatch", "run_id": run_id, "run_attempt": attempt,
        "deployed_at": "2026-10-03T22:11:33Z",
        "observed_at": "2026-10-03T22:12:00Z",
        "runtime": {"backend_sha": target, "frontend_sha": "f" * 40,
                    "frontend_build_sha": "f" * 40, "source": "github-actions:deploy.yml"},
        "verification_state": "production-verified",
        "verification_evidence": "deploy.yml Deploy step health and post-merge smoke succeeded before receipt creation",
        "application_artifact_digest": "unknown", "configuration_version": "unknown",
    }
    archive = receipt_zip(receipt)
    artifact = {
        "id": 9001 + attempt, "name": f"production-deploy-receipt-{run_id}-{attempt}",
        "size_in_bytes": len(archive), "digest": "sha256:" + hashlib.sha256(archive).hexdigest(),
        "expired": False, "created_at": "2026-10-03T22:12:20Z",
        "expires_at": "2026-12-03T22:12:20Z",
        "workflow_run": {"id": run_id, "head_sha": EVENT_HEAD},
    }
    responses = {
        (f"actions/runs/{run_id}/attempts/{attempt}/jobs", 1): {"total_count": 1, "jobs": [deployed_job]},
        (f"actions/runs/{run_id}/artifacts", 1): {"total_count": 1, "artifacts": [artifact]},
    }
    return candidate, receipt, artifact, FakeGH(responses, {artifact["id"]: archive})


def receipt_zip(receipt):
    output = io.BytesIO()
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as bundle:
        bundle.writestr(dora.RECEIPT_FILE, json.dumps(receipt))
    return output.getvalue()


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

    def test_manual_receipt_proves_target_distinct_from_run_head(self):
        candidate, receipt, artifact, gh = manual_fixture()
        candidate["workflow_sha"] = receipt["workflow_revision_sha"]
        proven = dora.verified_receipts(gh, [candidate], NOW - timedelta(days=30), NOW)
        self.assertEqual([(36955378940, 1, MANUAL_TARGET)],
                         [(item["run_id"], item["attempt"], item["sha"]) for item in proven])
        self.assertNotEqual(candidate["head_sha"], proven[0]["sha"])
        with patch.object(dora, "workflow_runs", return_value=[candidate]):
            self.assertEqual(1, len(dora.calculate(gh, NOW,
                (MANUAL_TARGET, datetime(2026, 10, 3, 22, 11, 33, tzinfo=timezone.utc)))))
            with self.assertRaisesRegex(dora.UnknownEvidence, "differs from production runtime"):
                dora.calculate(gh, NOW, (MANUAL_TARGET, NOW - timedelta(minutes=1)))

    def test_manual_receipt_missing_duplicate_expired_or_mismatched_is_unknown(self):
        def rejected(change):
            candidate, receipt, artifact, gh = manual_fixture()
            change(candidate, receipt, artifact, gh)
            with self.assertRaises(dora.UnknownEvidence):
                dora.verified_receipts(gh, [candidate], NOW - timedelta(days=30), NOW)

        key = ("actions/runs/36955378940/artifacts", 1)
        rejected(lambda _run, _receipt, _artifact, gh: gh.responses.__setitem__(key, {"total_count": 0, "artifacts": []}))
        rejected(lambda _run, _receipt, artifact, gh: gh.responses.__setitem__(key,
            {"total_count": 2, "artifacts": [artifact, artifact]}))
        rejected(lambda _run, _receipt, artifact, _gh: artifact.__setitem__("expired", True))
        rejected(lambda _run, _receipt, artifact, _gh: artifact.__setitem__("size_in_bytes", 0))
        rejected(lambda _run, _receipt, artifact, _gh: artifact.__setitem__("size_in_bytes", dora.MAX_ARTIFACT_BYTES + 1))
        rejected(lambda _run, _receipt, artifact, _gh: artifact.__setitem__("digest", "sha256:" + "0" * 64))
        rejected(lambda _run, _receipt, artifact, _gh: artifact.__setitem__("workflow_run", {"id": 1, "head_sha": EVENT_HEAD}))
        rejected(lambda _run, receipt, artifact, gh: gh.archives.__setitem__(artifact["id"], receipt_zip({**receipt, "source_sha": "a" * 40})))
        rejected(lambda _run, _receipt, artifact, gh: gh.archives.__setitem__(artifact["id"], b"not a zip"))
        rejected(lambda _run, _receipt, artifact, _gh: artifact.__setitem__("expires_at", "2026-10-03T22:12:20Z"))
        rejected(lambda candidate, _receipt, _artifact, _gh: candidate.__setitem__("workflow_sha", "0" * 40))

    def test_manual_receipt_json_fields_fail_closed_after_valid_archive_digest(self):
        changes = [
            lambda r: r.__setitem__("schema", 2),
            lambda r: r.__setitem__("repository", "other/repo"),
            lambda r: r.__setitem__("event", "repository_dispatch"),
            lambda r: r.__setitem__("run_id", 1),
            lambda r: r.__setitem__("run_attempt", 2),
            lambda r: r.__setitem__("event_head_sha", "0" * 40),
            lambda r: r.__setitem__("workflow_revision_sha", "short"),
            lambda r: r.__setitem__("source_sha", "short"),
            lambda r: r["runtime"].__setitem__("backend_sha", "0" * 40),
            lambda r: r.__setitem__("deployed_at", "2026-10-04T22:11:33Z"),
            lambda r: r.__setitem__("observed_at", "2026-10-03T22:50:00Z"),
            lambda r: r.__setitem__("verification_evidence", ""),
            lambda r: r.__setitem__("verification_state", "deployed"),
        ]
        for change in changes:
            with self.subTest(change=changes.index(change)):
                candidate, receipt, artifact, gh = manual_fixture()
                change(receipt)
                archive = receipt_zip(receipt)
                artifact["size_in_bytes"] = len(archive)
                artifact["digest"] = "sha256:" + hashlib.sha256(archive).hexdigest()
                gh.archives[artifact["id"]] = archive
                with self.assertRaises(dora.UnknownEvidence):
                    dora.verified_receipts(gh, [candidate], NOW - timedelta(days=30), NOW)

    def test_manual_reruns_bind_artifact_to_attempt_and_count_both(self):
        second, receipt2, artifact2, gh2 = manual_fixture(attempt=2)
        first, receipt1, artifact1, gh1 = manual_fixture(attempt=1)
        gh2.responses.update(gh1.responses)
        gh2.responses[("actions/runs/36955378940/artifacts", 1)] = {
            "total_count": 2, "artifacts": [artifact1, artifact2]}
        gh2.responses[("actions/runs/36955378940/attempts/1", 1)] = first
        gh2.archives.update(gh1.archives)
        proven = dora.verified_receipts(gh2, [second], NOW - timedelta(days=30), NOW)
        self.assertEqual([(36955378940, 1), (36955378940, 2)],
                         [(item["run_id"], item["attempt"]) for item in proven])
        self.assertEqual([MANUAL_TARGET, MANUAL_TARGET], [item["sha"] for item in proven])

    def test_repository_dispatch_receipt_and_download_call_cap(self):
        candidate, receipt, artifact, gh = manual_fixture()
        candidate["event"] = "repository_dispatch"
        receipt["event"] = "repository_dispatch"
        archive = receipt_zip(receipt)
        artifact["size_in_bytes"] = len(archive)
        artifact["digest"] = "sha256:" + hashlib.sha256(archive).hexdigest()
        gh.archives[artifact["id"]] = archive
        proven = dora.verified_receipts(gh, [candidate], NOW - timedelta(days=30), NOW)
        self.assertEqual(MANUAL_TARGET, proven[0]["sha"])
        client = dora.GitHub("owner/repo")
        client.calls = dora.MAX_API_CALLS
        with self.assertRaisesRegex(dora.UnknownEvidence, "cap exceeded"):
            client.download_artifact(artifact["id"])

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
        sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
        try:
            from scripts.governance.autonomy_gate import is_application_runtime_path

            self.assertFalse(is_application_runtime_path(".github/scripts/dora_metrics.py"))
            self.assertFalse(is_application_runtime_path(".github/scripts/test_dora_metrics.py"))
            self.assertTrue(is_application_runtime_path("scripts/dora_metrics.py"))
        finally:
            sys.path.pop(0)

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
