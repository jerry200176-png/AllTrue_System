import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


SCRIPT = (Path(__file__).resolve().parents[1] / "production-identity.sh").read_text()
SCRIPT_PATH = Path(__file__).resolve().parents[1] / "production-identity.sh"


class ProductionIdentityBehaviorTest(unittest.TestCase):
    """Execute the actual shell helper with synthetic, network-free transports."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        (self.root / "scratch").mkdir()
        self.sha = "a" * 40
        self.resolver = {"id": 201, "run_id": 20, "name": "Resolve exact activation target",
                         "status": "completed", "conclusion": "success"}
        run = {"databaseId": 20, "status": "completed", "conclusion": "success",
               "headSha": self.sha, "url": "https://example.invalid/runs/20"}
        self.fixture = {
            "main": self.sha, "health": {"status": "ok"},
            "version": {"hash": "aaaaaaa", "build_sha": self.sha},
            "deployment": {"backend_sha": self.sha, "frontend_build_sha": self.sha},
            "pi": [{"conclusion": "success", "createdAt": "2026-11-18T08:00:00Z"}],
            "runs": [run], "matching": [run],
            "details": {"20": {"id": 20, "path": ".github/workflows/deploy.yml",
                               "head_sha": self.sha, "head_branch": "main",
                               "status": "completed", "conclusion": "success"}},
            "jobs": {"20": [{"id": 200, "run_id": 20, "name": "Deploy to Production",
                            "status": "completed", "conclusion": "success",
                            "completed_at": "2026-11-18T08:03:11Z"}, self.resolver]},
            "resolver_logs": {"201": "2026-11-18T08:01:00Z Resolved activation target: " + self.sha + "\n"},
        }
        stub = self.root / "transport"
        stub.write_text(f"#!{sys.executable}\n" + r'''
import json, os, sys
from pathlib import Path
data = json.loads(Path(os.environ["IDENTITY_FIXTURE"]).read_text())
name = Path(sys.argv[0]).name
args = sys.argv[1:]
with open(os.environ["IDENTITY_CALLS"], "a") as log:
    log.write(json.dumps([name, args]) + "\n")
def emit(value):
    print(json.dumps(value))
if name == "git":
    assert args[:1] == ["ls-remote"], args
    print(data["main"] + "\trefs/heads/main")
elif name == "curl":
    insecure = any(a == "--insecure" or (a.startswith("-") and not a.startswith("--") and "k" in a[1:]) for a in args)
    if data.get("tls_untrusted") and not insecure:
        sys.stderr.write("curl: certificate verify failed\n")
        raise SystemExit(60)
    if "-w" in args:
        print(data.get("http_code", "200"), end="")
    else:
        url = args[-1]
        key = "health" if url.endswith("/health") else "version" if url.endswith("/version.json") else "deployment"
        emit(data[key])
elif name == "gh" and args[:2] == ["run", "list"]:
    emit(data["pi"] if any("pi-health.yml" in a for a in args)
         else data["matching"] if "--commit" in args else data["runs"])
elif name == "gh" and args[:1] == ["api"]:
    path = args[1].split("?")[0]
    if path.endswith("/logs"):
        job_id = path.split("/")[-2]
        if job_id not in data.get("resolver_logs", {}):
            raise SystemExit(1)
        sys.stdout.write(data["resolver_logs"][job_id])
    elif "/compare/" in path:
        emit({"files": []})
    else:
        parts = path.split("/")
        run_id = parts[-2] if parts[-1] == "jobs" else parts[-1]
        if run_id in data.get("unreadable", []):
            raise SystemExit(1)
        jobs = data["jobs"].get(run_id, [])
        emit(data.get("job_envelopes", {}).get(run_id, {"jobs": jobs, "total_count": len(jobs)}) if parts[-1] == "jobs"
             else data["details"].get(run_id, {}))
else:
    raise SystemExit("Unexpected transport: " + name + repr(args))
''')
        stub.chmod(0o700)
        for name in ["git", "curl", "gh"]:
            (self.root / name).symlink_to(stub)

    def run_helper(self):
        fixture = self.root / "fixture.json"
        fixture.write_text(json.dumps(self.fixture))
        env = os.environ.copy()
        env.update(PATH=str(self.root) + os.pathsep + env["PATH"],
                   IDENTITY_FIXTURE=str(fixture), IDENTITY_CALLS=str(self.root / "calls.jsonl"),
                   TMPDIR=str(self.root / "scratch"),
                   ALLTRUE_PROD_URL="https://example.invalid",
                   ALLTRUE_REPO="synthetic/identity")
        result = subprocess.run(["bash", str(SCRIPT_PATH)], env=env,
                                capture_output=True, text=True, timeout=15)
        self.assertEqual([], list((self.root / "scratch").iterdir()), "helper leaked temporary evidence")
        return result, json.loads(result.stdout)

    def test_successful_wrapper_with_skipped_deploy_is_not_delivery(self):
        self.fixture["jobs"]["20"][0]["conclusion"] = "skipped"
        result, identity = self.run_helper()
        self.assertEqual(1, result.returncode)
        self.assertIsNone(identity["last_deploy_workflow_run"])
        self.assertIn("no_successful_deploy_matches_manifest", identity["drift_red"])

    def test_matching_real_job_is_recorded_without_inventing_rollback(self):
        result, identity = self.run_helper()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(20, identity["last_deploy_workflow_run"]["databaseId"])
        self.assertEqual(200, identity["production_deploy_job"]["id"])
        self.assertIsNone(identity["rollback_target"])
        self.assertEqual("unknown_not_validated", identity["rollback_target_status"])

    def test_older_real_deploy_wins_over_newer_successful_wrapper(self):
        self.fixture["jobs"]["20"][0]["conclusion"] = "skipped"
        self.fixture["matching"].append(dict(self.fixture["matching"][0], databaseId=19))
        self.fixture["details"]["19"] = dict(self.fixture["details"]["20"], id=19)
        self.fixture["jobs"]["19"] = [dict(self.fixture["jobs"]["20"][0], id=190,
                                          run_id=19, conclusion="success"),
                                       dict(self.resolver, id=191, run_id=19)]
        self.fixture["resolver_logs"]["191"] = self.fixture["resolver_logs"]["201"]
        result, identity = self.run_helper()
        self.assertEqual(0, result.returncode)
        self.assertEqual(19, identity["last_deploy_workflow_run"]["databaseId"])
        self.assertEqual(190, identity["production_deploy_job"]["id"])

    def test_backend_only_deploy_preserves_the_separate_frontend_revision(self):
        frontend_sha = "b" * 40
        self.fixture["version"]["build_sha"] = frontend_sha
        self.fixture["deployment"]["frontend_build_sha"] = frontend_sha
        result, identity = self.run_helper()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(self.sha, identity["deployed_backend_full_sha"])
        self.assertEqual(frontend_sha, identity["deployed_frontend_build_sha"])
        self.assertEqual(frontend_sha, identity["deployment_manifest_frontend_sha"])
        self.assertEqual(200, identity["production_deploy_job"]["id"])

    def test_resolved_target_must_match_manifest_not_just_workflow_revision(self):
        self.fixture["resolver_logs"]["201"] = "2026-11-18T08:01:00Z Resolved activation target: " + "b" * 40 + "\n"
        result, identity = self.run_helper()
        self.assertEqual(1, result.returncode)
        self.assertIsNone(identity["last_deploy_workflow_run"])

    def test_manual_target_can_differ_from_workflow_revision(self):
        workflow_sha = "b" * 40
        self.fixture["runs"][0]["headSha"] = workflow_sha
        self.fixture["matching"] = []
        self.fixture["details"]["20"]["head_sha"] = workflow_sha
        result, identity = self.run_helper()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(self.sha, identity["deployed_backend_full_sha"])
        self.assertEqual(self.sha, identity["production_deploy_job"]["target_sha"])

    def test_resolver_job_must_be_unique_successful_and_owned_by_the_run(self):
        deploy = self.fixture["jobs"]["20"][0]
        invalid = [[], [self.resolver, self.resolver]]
        invalid += [[dict(self.resolver, **change)] for change in [
            {"id": True}, {"id": 0}, {"run_id": 999}, {"run_id": True},
            {"status": "in_progress"}, {"conclusion": "failure"},
            {"name": "Unrelated resolver"},
        ]]
        for resolvers in invalid:
            with self.subTest(resolvers=resolvers):
                self.fixture["jobs"]["20"] = [deploy, *resolvers]
                result, identity = self.run_helper()
                self.assertEqual(1, result.returncode)
                self.assertIsNone(identity["production_deploy_job"])

    def test_missing_ambiguous_or_source_echo_targets_are_not_proof(self):
        good_log = self.fixture["resolver_logs"]["201"]
        for log in [None, "", good_log * 2,
                    "Resolved activation target: " + self.sha + "\n",
                    '2026-11-18T08:01:00Z echo "Resolved activation target: ' + self.sha + '"\n']:
            with self.subTest(log=log):
                self.fixture["resolver_logs"] = {} if log is None else {"201": log}
                result, identity = self.run_helper()
                self.assertEqual(1, result.returncode)
                self.assertIsNone(identity["production_deploy_job"])
        private_marker = "SYNTHETIC_PRIVATE_LOG_LINE_DO_NOT_PRINT"
        self.fixture["resolver_logs"] = {"201": private_marker + "\n" + good_log}
        result, identity = self.run_helper()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertNotIn(private_marker, result.stdout + result.stderr)

    def test_missing_ambiguous_failed_or_unreadable_jobs_fail_closed(self):
        good = self.fixture["jobs"]["20"][0].copy()
        for jobs in [[], [good, good], [dict(good, conclusion="failure")],
                     [dict(good, status="in_progress")],
                     [dict(good, status="in_progress", conclusion=None)],
                     [dict(good, completed_at=None)], [dict(good, run_id=999)],
                     [dict(good, name="Unrelated job")], [dict(good, id=True)],
                     [dict(good, id=0)], [dict(good, completed_at="not-a-time")],
                     [dict(good, completed_at="2026-11-18T08:03:11")]]:
            with self.subTest(jobs=jobs):
                self.fixture["jobs"]["20"] = jobs + [self.resolver]
                result, identity = self.run_helper()
                self.assertEqual(1, result.returncode)
                self.assertIsNone(identity["last_deploy_workflow_run"])
        self.fixture["jobs"]["20"] = [good, self.resolver]
        self.fixture["unreadable"] = ["20"]
        result, identity = self.run_helper()
        self.assertEqual(1, result.returncode)
        self.assertIsNone(identity["last_deploy_workflow_run"])

    def test_incomplete_or_malformed_job_envelopes_fail_closed(self):
        jobs = self.fixture["jobs"]["20"]
        for envelope in [{"jobs": jobs}, {"jobs": jobs, "total_count": True},
                         {"jobs": jobs, "total_count": 101}, {"jobs": [None], "total_count": 1}]:
            with self.subTest(envelope=envelope):
                self.fixture["job_envelopes"] = {"20": envelope}
                result, identity = self.run_helper()
                self.assertEqual(1, result.returncode)
                self.assertIsNone(identity["last_deploy_workflow_run"])

    def test_run_identity_and_runtime_sha_must_match(self):
        good = self.fixture["details"]["20"].copy()
        for change in [{"path": ".github/workflows/other.yml"}, {"id": 999},
                       {"head_branch": "feature"}, {"head_sha": "b" * 40},
                       {"status": "in_progress"}, {"conclusion": "failure"},
                       {"status": "in_progress", "conclusion": None}]:
            with self.subTest(change=change):
                self.fixture["details"]["20"] = dict(good, **change)
                result, identity = self.run_helper()
                self.assertEqual(1, result.returncode)
                self.assertIsNone(identity["last_deploy_workflow_run"])

    def test_health_and_scheduler_failures_remain_red_with_a_valid_deploy(self):
        self.fixture["health"] = {"status": "degraded"}
        result, identity = self.run_helper()
        self.assertEqual(1, result.returncode)
        self.assertIn("health_not_ok", identity["drift_red"])
        self.fixture["health"] = {"status": "ok"}
        self.fixture["pi"][0]["conclusion"] = "failure"
        result, identity = self.run_helper()
        self.assertEqual(1, result.returncode)
        self.assertIn("scheduler_or_pi_health_failed", identity["drift_red"])
        self.assertIn("health_ok_but_background_jobs_unhealthy", identity["drift_red"])

    def test_untrusted_tls_is_not_accepted_as_runtime_evidence(self):
        self.fixture["tls_untrusted"] = True
        result, identity = self.run_helper()
        self.assertEqual(1, result.returncode)
        self.assertIn("certificate verify failed", result.stderr)
        self.assertIn("health_not_ok", identity["drift_red"])

    def test_large_metadata_does_not_depend_on_argv_capacity(self):
        self.fixture["version"]["synthetic_padding"] = "x" * 250000
        result, identity = self.run_helper()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("GREEN", identity["overall"])


class ProductionIdentityContractTest(unittest.TestCase):
    def test_large_evidence_is_transferred_via_private_temp_files(self):
        self.assertIn('IDENTITY_TMP_DIR="$(mktemp -d)"', SCRIPT)
        self.assertIn("trap 'rm -rf -- \"$IDENTITY_TMP_DIR\"' EXIT", SCRIPT)
        self.assertIn("umask 077", SCRIPT)
        self.assertIn('"$IDENTITY_TMP_DIR/deploy-runs.json"', SCRIPT)
        self.assertIn('"$IDENTITY_TMP_DIR/matching-deploy-runs.json"', SCRIPT)
        self.assertNotIn('python3 - "$REMOTE_MAIN" "$HEALTH_RAW"', SCRIPT)
        self.assertNotIn('python3 - "$REMOTE_MAIN" "$DEPLOYMENT_RAW"', SCRIPT)

if __name__ == "__main__":
    unittest.main()
