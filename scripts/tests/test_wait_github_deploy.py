import json
import subprocess
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from scripts import wait_github_deploy as deploy


SHA = "a" * 40
OTHER = "b" * 40
RUN = 401
REPO = "example/alltrue"


class DeployObserverTest(unittest.TestCase):
    def setUp(self):
        self.options = SimpleNamespace(run_id=RUN, pr=None, sha=SHA, timeout=60, poll_interval=2,
                                       json=True, once=False, repo=REPO, workflow="deploy.yml",
                                       prod_url="https://example.invalid")
        self.run = {"id": RUN, "path": ".github/workflows/deploy.yml", "head_branch": "main",
                    "head_sha": OTHER, "status": "completed", "conclusion": "success"}
        self.jobs = [{"name": "Deploy to Production", "id": 500, "run_id": RUN,
                     "status": "completed", "conclusion": "success"},
                     {"name": "Resolve exact activation target", "id": 501, "run_id": RUN,
                      "status": "completed", "conclusion": "success"}]
        self.identity = {"production_deploy_job": {"run_id": RUN, "target_sha": SHA},
                         "deployed_backend_full_sha": SHA, "deployment_manifest_backend_sha": SHA,
                         "deployed_frontend_build_sha": OTHER}
        self.gates = [{"environment": {"name": "production-activation"}}]
        self.resolver_log = f"2026-11-21T09:00:00Z Resolved activation target: {SHA}\n"
        self.calls = []

    def transport(self, args, deadline, *, accepted=(0,), env=None):
        self.calls.append(args)
        if args[0] == "bash":
            self.assertEqual(str(RUN), env["ALLTRUE_DEPLOY_RUN_ID"])
            return json.dumps(self.identity)
        self.assertEqual(["gh", "api"], args[:2], "observer only reads the GitHub API")
        prefix = f"/repos/{REPO}/"
        self.assertTrue(args[2].startswith(prefix))
        endpoints = {
            f"actions/runs/{RUN}": self.run,
            f"actions/runs/{RUN}/jobs?per_page=100&filter=latest": {"jobs": self.jobs, "total_count": len(self.jobs)},
            f"actions/runs/{RUN}/pending_deployments": self.gates,
            "actions/jobs/501/logs": self.resolver_log,
        }
        path = args[2][len(prefix):]
        item = endpoints[path]
        return item if isinstance(item, str) else json.dumps(item)

    def observe(self):
        with patch.object(deploy, "read_command", side_effect=self.transport):
            return deploy.watch(deploy.Observer(self.options))

    def test_backend_only_proof_keeps_frontend_and_workflow_revision_separate(self):
        result = self.observe()
        self.assertEqual("verified", result["status"])
        self.assertEqual(SHA, result["target"])
        self.assertEqual(OTHER, result["frontend"])

    def test_successful_wrapper_with_skipped_production_job_does_not_claim_deploy(self):
        self.jobs[0]["conclusion"] = "skipped"
        result = self.observe()
        self.assertEqual("not_deployed", result["status"])
        self.assertTrue(all(command[0] == "gh" for command in self.calls))

    def test_completed_workflow_requires_production_receipt_for_same_run(self):
        self.identity["production_deploy_job"]["run_id"] = RUN + 1
        result = self.observe()
        self.assertEqual("unverified", result["status"])
        self.assertEqual(1, sum(command[0] == "bash" for command in self.calls))

    def test_invalid_identity_structure_is_an_observation_failure(self):
        for malformed in ([], None, {"production_deploy_job": []},
                          {"deployment_manifest_backend_sha": 42}):
            with self.subTest(identity=malformed):
                self.identity = malformed
                self.assertEqual("unavailable", self.observe()["status"])

    def test_invalid_transport_text_is_an_observation_failure(self):
        err = UnicodeDecodeError("utf-8", b"\xff", 0, 1, "invalid start byte")
        with patch.object(deploy.subprocess, "run", side_effect=err):
            result = deploy.watch(deploy.Observer(self.options))
        self.assertEqual("unavailable", result["status"])
        self.assertIn("invalid text", result["reason"])

    def test_invalid_run_state_is_an_observation_failure(self):
        self.run["status"] = []
        self.assertEqual("unavailable", self.observe()["status"])

    def test_non_string_merge_commit_is_an_observation_failure(self):
        self.options.pr = 42
        self.options.sha = ""
        with patch.object(deploy.Observer, "api", return_value={"merged": True, "merge_commit_sha": 17}):
            result = deploy.watch(deploy.Observer(self.options))
        self.assertEqual("unavailable", result["status"])
        self.assertIn("exact merge commit", result["reason"])

    def test_actual_approval_wait_names_exact_run_target_step_and_resume(self):
        self.run["status"] = "waiting"
        result = self.observe()
        self.assertEqual("approval_required", result["status"])
        self.assertEqual(SHA, result["target"])
        self.assertEqual(f"https://github.com/{REPO}/actions/runs/{RUN}", result["url"])
        for text in ("Review deployments", "production-activation", "same run ID"):
            self.assertIn(text, result["next"])

    def test_different_pending_target_does_not_request_approval(self):
        self.run["status"] = "waiting"
        self.resolver_log = self.resolver_log.replace(SHA, OTHER)
        result = self.observe()
        self.assertEqual("unverified", result["status"])
        self.assertEqual(OTHER, result["observed_target"])
        self.assertNotIn("approve", result["next"].lower())

    def test_ambiguous_resolver_log_does_not_request_approval(self):
        self.run["status"] = "waiting"
        self.resolver_log += f"broken Resolved activation target: {OTHER}\n"
        self.assertEqual("unavailable", self.observe()["status"])

    def test_normal_slow_run_is_reported_as_waiting_by_one_shot(self):
        self.run["status"] = "in_progress"
        self.gates = []
        self.options.once = True
        result = self.observe()
        self.assertEqual("waiting", result["status"])
        self.assertEqual(RUN, result["run_id"])

    def test_unrelated_workflow_is_refused_before_looking_for_approval(self):
        self.run["path"] = ".github/workflows/other.yml"
        self.assertEqual("unavailable", self.observe()["status"])
        self.assertEqual(1, len(self.calls))

    def test_transient_http_read_retries_with_a_cap(self):
        observer = deploy.Observer(self.options)
        transient = subprocess.CompletedProcess([], 1, "", "gh: failure (HTTP 503)")
        with (patch.object(deploy.subprocess, "run", return_value=transient) as read,
              patch.object(deploy.time, "sleep") as sleep):
            result = deploy.watch(observer)
        self.assertEqual("unavailable", result["status"])
        self.assertEqual(3, read.call_count)
        self.assertEqual([2, 4], [call.args[0] for call in sleep.call_args_list])

    def test_permanent_http_denial_does_not_retry(self):
        observer = deploy.Observer(self.options)
        denied = subprocess.CompletedProcess([], 1, "", "gh: denied (HTTP 403)")
        with (patch.object(deploy.subprocess, "run", return_value=denied) as read,
              patch.object(deploy.time, "sleep") as sleep):
            result = deploy.watch(observer)
        self.assertEqual("unavailable", result["status"])
        self.assertIn("403", result["reason"])
        read.assert_called_once()
        sleep.assert_not_called()


if __name__ == "__main__":
    unittest.main()
