"""#4359 bounded subset: real-shaped evidence, ten fail-closed regressions, no flag oracle."""
from pathlib import Path
from datetime import datetime, timedelta, timezone
import unittest

from scripts.governance import autonomy_gate as gate

BASE, TARGET, HEAD = (c * 40 for c in "abc")
ROOT = Path(__file__).parents[2]
PATH = "frontend/src/pages/Home.vue"
PATCH = "@@ -1 +1 @@\n-<p>Before</p>\n+<p>After</p>"


class Evidence:
    def __init__(self, skipped=0):
        self.body = "Rollback: revert this PR\nRisk-Class: R3\nAutonomy-Tier: T3"
        self.head, self.open_thread, self.ci = HEAD, False, "success"
        self.runs = [{"id": n, "status": "completed", "run_attempt": 1} for n in range(skipped + 1, 0, -1)]
        self.jobs = {r["id"]: [{"id": r["id"], "name": gate.DEPLOY_JOB_NAME,
                      "status": "completed", "conclusion": "skipped" if r["id"] > 1 else "success"}]
                     for r in self.runs}
        for n, jobs in self.jobs.items():
            start = datetime(2026, 10, 21, tzinfo=timezone.utc) + timedelta(minutes=2*n)
            jobs[0].update(started_at=start.isoformat(), completed_at=(start + timedelta(minutes=1)).isoformat())
        self.attempt_jobs, self.calls = {}, []

    def api(self, path, *extra):
        self.calls.append(path)
        if "/pulls/1" == path[-8:]:
            return {"number": 1, "body": self.body, "head": {"sha": self.head}}
        if "/comments?" in path:
            return [[{"user": {"login": gate.CODEX_REVIEWER}, "body":
                      '<!-- codex-pull-request-review-summary -->\n✅ **Completed** '
                      '<relative-time datetime="2026-10-21T00:00:00Z">then</relative-time> | `'
                      + HEAD + '`'}]]
        if "/reviews?" in path:
            return [[]]
        if "/reactions?" in path:
            return [[{"user": {"login": gate.CODEX_REVIEWER}, "content": "+1",
                      "created_at": "2026-10-21T00:01:00Z"}]]
        if "/ci.yml/" in path:
            return {"workflow_runs": [{"id": 900, "name": "CI — PHPUnit Tests",
                    "status": "completed", "conclusion": self.ci}]}
        page = int(path.split("page=")[-1])
        if "/deploy.yml/" in path:
            rows, key = self.runs, "workflow_runs"
        else:
            run_id = int(path.split("/runs/")[1].split("/")[0])
            attempt = int(path.split("/attempts/")[1].split("/")[0]) if "/attempts/" in path else next(r["run_attempt"] for r in self.runs if r["id"] == run_id)
            rows, key = self.attempt_jobs.get((run_id, attempt), self.jobs[run_id]), "jobs"
        return {"total_count": len(rows), key: rows[(page - 1) * 100:page * 100]}

    def graphql(self, query):
        nodes = [{"isResolved": False, "comments": {"totalCount": 1}}] if self.open_thread else []
        return {"data": {"repository": {"pullRequest": {"reviewThreads": {
            "nodes": nodes, "pageInfo": {"hasNextPage": False}}}}}}

    def read(self):
        return gate.r3_runtime_evidence(repo="o/r", pr_numbers=[1], api=self.api, graphql=self.graphql)


def comparison():
    return {"status": "ahead", "total_commits": 1, "commits": [{"sha": TARGET}],
            "base_commit": {"sha": BASE}, "merge_base_commit": {"sha": BASE}}


def records(files):
    # Same immutable compare, landed files and aggregation path used by the workflow producer.
    commits = gate.activation_range_commits(comparison(), BASE, TARGET)
    paths = gate.landed_file_paths(files)
    patch = "\n".join(f"diff --git a/{f['filename']} b/{f['filename']}\n+++ b/{f['filename']}\n{f.get('patch', '')}"
                      for f in files)
    landed = gate.aggregate_landed_pr_effects([{
        "number": 1, "merged_at": "2026-10-21T00:00:00Z", "commit_sha": commits[0]["sha"],
        "paths": paths, "patch": patch, "patch_complete": all("patch" in f for f in files),
        "declared_risk": 3, "declared_tier": 3}])
    return landed, paths


def decide(files=None, evidence=None, event="schedule"):
    landed, paths = records(files if files is not None else [{"filename": PATH, "patch": PATCH}])
    return gate.decide_r3_auto_activation(landed, range_paths=paths, event_name=event,
                                         **(evidence or Evidence()).read())


class ActivationEvidenceIntegrityTest(unittest.TestCase):
    def test_integrated_real_shaped_evidence_producer_accepts_only_existing_routine_subset(self):
        evidence = Evidence()
        self.assertTrue(decide(evidence=evidence)["ok"])
        self.assertTrue(any("/comments?" in p for p in evidence.calls))
        self.assertTrue(any("/jobs?" in p for p in evidence.calls))
        for event in ("workflow_run", "repository_dispatch"):
            self.assertTrue(decide(event=event)["ok"])
        self.assertFalse(decide(event="workflow_dispatch")["ok"])

    def test_existing_guarded_sensitive_read_only_t2_subset_is_preserved(self):
        files = [{"filename": "frontend/src/pages/BillingStatus.vue", "patch":
                  "@@ -1 +1 @@\n-<span>{{ oldStatus }}</span>\n+<span>{{ payment_status }}</span>"}]
        landed, _ = records(files)
        scope = gate.classify_activation_provenance(landed)["application_prs"][0]["scope"]
        self.assertEqual("guarded-sensitive", scope["activation_class"])
        self.assertEqual(2, scope["machine_minimum_tier"])
        self.assertTrue(decide(files)["ok"])

    def test_compare_truncation_and_commit_identity_are_holds(self):
        for mutation in ({"total_commits": 2}, {"commits": [{"sha": "d" * 40}]},
                         {"merge_base_commit": {"sha": "d" * 40}}, {"status": "diverged"},
                         {"commits": [{"sha": TARGET}] * 2, "total_commits": 2}):
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                gate.activation_range_commits(comparison() | mutation, BASE, TARGET)

    def test_renamed_protected_source_is_in_actual_record_and_classification(self):
        for old in ("backend/app/Services/SessionDeductionService.php", "backend/routes/api.php",
                    "backend/database/migrations/2026_10_21_change.php", ".github/actions/x/action.yml"):
            files = [{"filename": PATH, "previous_filename": old, "status": "renamed", "patch": PATCH}]
            landed, paths = records(files)
            self.assertIn(old, landed[0]["paths"])
            self.assertIn(old, paths)
            self.assertFalse(decide(files)["ok"], old)
        with self.assertRaises(ValueError):
            gate.landed_file_paths([{"filename": PATH, "status": "renamed"}])
        with self.assertRaises(ValueError):
            gate.landed_file_paths([{"filename": PATH}] * 300)

    def test_after_queue_rechecks_freeze_review_rollback_and_actual_runtime_base(self):
        mutations = (lambda e: e.jobs[1][0].update(conclusion="failure"),
                     lambda e: setattr(e, "open_thread", True), lambda e: setattr(e, "head", "d" * 40),
                     lambda e: setattr(e, "ci", "failure"), lambda e: setattr(e, "body", "Rollback: impossible"))
        for mutate in mutations:
            evidence = Evidence()
            self.assertTrue(decide(evidence=evidence)["ok"])
            mutate(evidence)
            with self.assertRaises(ValueError):
                gate.recheck_r3_execution(repo="o/r", base_sha=BASE, runtime_base_sha=BASE,
                                         pr_numbers=[1], api=evidence.api, graphql=evidence.graphql)
        with self.assertRaises(ValueError):
            gate.recheck_r3_execution(repo="o/r", base_sha=BASE, runtime_base_sha=TARGET,
                                     pr_numbers=[1], api=Evidence().api, graphql=Evidence().graphql)

    def test_laravel_route_auth_removals_remain_founder_held(self):
        for authorization in ("can:edit", "role:admin", "verified", "Gate::allows('edit')"):
            self.assertFalse(decide([{"filename": "backend/routes/api.php", "patch":
                "@@ -1 +1 @@\n-" + authorization + "\n+Route::get('/private', $handler);"}])["ok"])

    def test_freeze_pages_beyond_30_skips_and_finds_actual_executor(self):
        evidence = Evidence(skipped=130)
        self.assertIsNone(evidence.read()["freeze_reason"])
        self.assertTrue(any("deploy.yml/runs?per_page=100&page=2" in p for p in evidence.calls))
        for conclusion in ("failure", "cancelled", None):
            evidence.jobs[1][0]["conclusion"] = conclusion
            self.assertIsNotNone(evidence.read()["freeze_reason"])
        # A failed executor may release its lock before its workflow finishes.
        evidence.runs[-1]["status"] = "in_progress"
        self.assertIsNotNone(evidence.read()["freeze_reason"])

    def test_older_created_executor_failure_after_newer_success_freezes(self):
        evidence = Evidence(skipped=1)
        evidence.runs[0].update(created_at="2026-10-21T12:00:00Z")
        evidence.runs[1].update(created_at="2026-10-21T11:00:00Z")
        evidence.jobs[2][0].update(conclusion="success", started_at="2026-10-21T12:01:00Z", completed_at="2026-10-21T12:02:00Z")
        evidence.jobs[1][0].update(conclusion="failure", started_at="2026-10-21T13:01:00Z", completed_at="2026-10-21T13:02:00Z")
        self.assertIsNotNone(evidence.read()["freeze_reason"])
        self.assertFalse(decide(evidence=evidence)["ok"])
        evidence.jobs[1][0]["conclusion"], evidence.jobs[2][0]["conclusion"] = "success", "failure"
        self.assertTrue(decide(evidence=evidence)["ok"])  # Later actual recovery, despite older run creation.

    def test_queued_retry_does_not_hide_previous_failed_attempt(self):
        evidence = Evidence(skipped=1); evidence.runs[0]["run_attempt"] = 2
        evidence.jobs[2][0]["conclusion"] = "failure"
        evidence.attempt_jobs[2, 2] = [{"id": 100, "name": gate.DEPLOY_JOB_NAME,
            "status": "queued", "conclusion": None, "started_at": None, "completed_at": None}]
        self.assertIsNotNone(evidence.read()["freeze_reason"])
        self.assertTrue(any("/runs/2/attempts/1/jobs?" in p for p in evidence.calls))
        self.assertTrue(any("/runs/2/attempts/2/jobs?" in p for p in evidence.calls))

    def test_unknown_executor_chronology_or_attempt_is_not_old_or_skipped(self):
        for field, value in (("name", None), ("started_at", None), ("completed_at", None),
                             ("completed_at", "garbage"), ("started_at", "2026-10-22T00:00:00Z")):
            evidence = Evidence(); evidence.jobs[1][0][field] = value
            with self.assertRaises(ValueError):
                evidence.read()
        evidence = Evidence(skipped=1); evidence.jobs[2][0].update(conclusion="success", started_at=evidence.jobs[1][0]["started_at"])
        with self.assertRaisesRegex(ValueError, "overlaps"):
            evidence.read()
        evidence = Evidence(); evidence.runs[0]["run_attempt"] = 1001
        with self.assertRaisesRegex(ValueError, "ceiling"):
            evidence.read()
        evidence = Evidence(); evidence.runs[0].pop("run_attempt")
        with self.assertRaises(ValueError):
            evidence.read()

    def test_skip_own_run_but_not_a_previous_executors_failure(self):
        evidence = Evidence(skipped=1)
        evidence.jobs[2][0].update(status="in_progress", conclusion=None)
        self.assertIsNotNone(gate.deploy_freeze_reason(repo="o/r", api=evidence.api))
        self.assertIsNone(gate.deploy_freeze_reason(repo="o/r", api=evidence.api, current_run_id=2))

    def test_non_actionable_or_contradictory_rollback_prose_rejected(self):
        for body in ("Rollback: impossible; manual data repair required", "Rollback: TBD",
                     "Rollback: revert this PR\nRecovery requires manual data repair"):
            evidence = Evidence(); evidence.body = body
            self.assertFalse(decide(evidence=evidence)["ok"])

    def test_flags_and_backend_prose_cannot_authorize_real_r3_effects(self):
        # Source FLAGS_OK/default-false/token/none positives were invalid: neither effective
        # off-state, effect guard coverage, absence of user effects nor data recovery follows.
        for claim in ("Feature-Flag: billing_v2 (default off)",
                      "Feature-Flag: none — backend-only cron", "Feature-Flag: new_key (default off)"):
            evidence = Evidence(); evidence.body += "\n" + claim
            for code in ("// config('perfflags.billing_v2')\n+$invoice->save();",
                         "$x = (bool) env('BILLING_V2', false);\n+$invoice->save();",
                         "Log::info('billing_v2');\n+$invoice->save();"):
                self.assertFalse(decide([{"filename": "backend/app/Services/SessionDeductionService.php",
                                         "patch": "@@ -1 +1 @@\n-old\n+" + code}], evidence)["ok"])

    def test_all_control_plane_paths_held_even_in_separate_pr(self):
        landed, paths = records([{"filename": PATH, "patch": PATCH}])
        for path in (".github/actions/helper/action.yml", ".github/workflows/other.yml",
                     "scripts/ci/helper.py", "docs/governance/decisions.md"):
            control = {"number": 2, "merged_at": "2026-10-21T00:00:00Z", "paths": [path],
                       "patch": "+new", "patch_complete": True, "declared_risk": 3, "declared_tier": 3}
            self.assertFalse(gate.decide_r3_auto_activation(landed + [control],
                event_name="schedule", range_paths=paths + [path], **Evidence().read())["ok"])

    def test_partial_or_capped_history_and_unknown_threads_fail_closed(self):
        evidence = Evidence()
        def partial(path, *extra):
            response = evidence.api(path, *extra)
            return response | {"total_count": 101} if "/deploy.yml/" in path else response
        with self.assertRaises(ValueError):
            gate.deploy_freeze_reason(repo="o/r", api=partial)
        evidence = Evidence(skipped=3383)  # Root observed3384 run metadata, not production proof.
        with self.assertRaisesRegex(ValueError, "ceiling"):
            evidence.read()
        self.assertFalse(any("/attempts/" in p for p in evidence.calls))
        conn = {"nodes": [], "pageInfo": {"hasNextPage": True}}
        self.assertIsNone(gate.unresolved_review_threads(conn))

    def test_workflow_collects_old_paths_and_rechecks_before_ssh_with_both_policies(self):
        workflow = (ROOT / ".github/workflows/deploy.yml").read_text()
        self.assertIn("commits = activation_range_commits(comparison, base, target)", workflow)
        self.assertIn("actual_paths = landed_file_paths(actual_files)", workflow)
        start = workflow.index("      - name: Recheck limited R3 evidence after executor queue")
        end = workflow.index("      - name: Checkout pinned SSH trust helper", start)
        self.assertIn('for root in ("gate-trusted", "gate-target")', workflow[start:end])
        self.assertIn('runtime_base_sha=runtime_base', workflow[start:end])
        self.assertIn('os.environ.get("GITHUB_RUN_ATTEMPT") != "1"', workflow[start:end])
        self.assertIn('current_run_id=os.environ["GITHUB_RUN_ID"]', workflow[start:end])
        self.assertNotIn("FEATURE_FLAG_CONFIG", workflow)
        self.assertIn("Final exact-main gate before production executor", workflow[end:])


if __name__ == "__main__":
    unittest.main()
