"""Founder 2026-10-14 (三): billing/login code with focused tests + independent review activates as T2."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.governance.autonomy_gate import (  # noqa: E402
    classify_activation_provenance, classify_activation_scope, classify_scope, has_independent_review,
    standing_exception_scope, validate_declaration,
)

SVC = "backend/app/Services/SessionDeduction/SessionDeductionService.php"
CTRL = "backend/app/Http/Controllers/Billing/InvoiceController.php"
TEST = "backend/tests/Feature/SessionDeductionServiceTest.php"
HEAD = "a" * 40


def chunk(path, added, removed=("old();",)):
    lines = [f"-{line}" for line in removed] + [f"+{line}" for line in added]
    return f"diff --git a/{path} b/{path}\n@@ -1,{len(removed)} +1,{len(added)} @@\n" + "\n".join(lines) + "\n"


BILLING = chunk(SVC, ["$deducted = $this->calculateCharge($session);", "$invoice->save();"])
COVER = chunk(TEST, ["use App\\Services\\SessionDeduction\\SessionDeductionService;",
                    "public function test_deducts_once() { $svc = new SessionDeductionService(); }"], ())


def scope(paths, patch):
    return standing_exception_scope(paths, patch)


class StandingExceptionDiff(unittest.TestCase):
    def test_billing_with_named_test_is_eligible_and_t2(self):
        paths, patch = [SVC, TEST], BILLING + COVER
        self.assertTrue(scope(paths, patch)["eligible"])
        self.assertEqual(2, classify_scope(paths, patch)["machine_minimum_tier"])
        activation = classify_activation_scope(paths, patch)
        self.assertEqual(2, activation["machine_minimum_tier"])
        self.assertTrue(activation["requires_independent_review"])
        self.assertFalse(activation["founder_required"])

    def test_declared_r2_is_valid_for_an_eligible_diff(self):
        body = "Rollback: revert this PR\n\n**Risk-Class:** R2\n**Autonomy-Tier:** T2\n"
        result = validate_declaration(body, [SVC, TEST], BILLING + COVER)
        self.assertTrue(result["valid"], result)

    def test_stays_t3_without_a_test_naming_each_changed_file(self):
        unrelated = chunk(TEST, ["public function test_unrelated() {}"], ())
        for paths, patch in (
            ([SVC], BILLING),
            ([SVC, TEST], BILLING + unrelated),
            ([SVC, CTRL, TEST], BILLING + chunk(CTRL, ["$payment->save();"]) + COVER),
        ):
            self.assertFalse(scope(paths, patch)["eligible"], paths)
            self.assertEqual(3, classify_scope(paths, patch)["machine_minimum_tier"])
            self.assertEqual(3, classify_activation_scope(paths, patch)["machine_minimum_tier"])

    def test_boundaries_outside_billing_login_stay_t3(self):
        cases = (
            ("backend/app/Http/Middleware/Authenticate.php", ["return $next($request);"]),
            ("backend/app/Services/SessionEntitlement/EntitlementService.php", ["$x->save();"]),
            ("backend/app/Http/Controllers/StudentIdentity/LinkController.php", ["$x->save();"]),
            ("backend/app/Http/Controllers/Auth/PasswordResetController.php", ["$x->save();"]),
            ("backend/app/Http/Controllers/Auth/TokenController.php", ["$x->save();"]),
            ("backend/routes/api.php", ["Route::post('/billing/x', [BillingController::class, 'x']);"]),
            (".github/workflows/deploy.yml", ["  run: echo billing"]),
            ("docs/governance/RISK_BASED_MERGE_POLICY.md", ["billing"]),
        )
        for path, added in cases:
            name = path.rsplit("/", 1)[-1].split(".")[0]
            test = chunk(TEST, [f"// {name}"], ())
            paths, patch = [path, TEST], chunk(path, added) + test
            self.assertFalse(scope(paths, patch)["eligible"], path)
            self.assertEqual(3, classify_scope(paths, patch)["machine_minimum_tier"], path)

    def test_data_privacy_mass_and_migration_effects_stay_t3(self):
        for added in (
            ["DB::statement('delete from invoices');"],
            ["Invoice::query()->chunkById(100, fn ($rows) => $rows->each->save());"],
            ["$days = config('privacy.retention'); $invoice->save();"],
            ["$token = $user->createToken('x');"],
            ["if ($user->permission('x')) { $invoice->save(); }"],
            ["$user->assignRole('director'); $invoice->save();"],
            ["Gate::allows('refund', $invoice); $invoice->save();"],
            ["if ($user->isAdmin()) { $invoice->save(); }"],
        ):
            paths, patch = [SVC, TEST], chunk(SVC, added) + COVER
            self.assertFalse(scope(paths, patch)["eligible"], added)
            self.assertEqual(3, classify_activation_scope(paths, patch)["machine_minimum_tier"], added)
        mig = "backend/database/migrations/2026_10_14_000000_x.php"
        paths, patch = [SVC, TEST, mig], BILLING + COVER + chunk(mig, ["<?php"], ())
        self.assertFalse(scope(paths, patch)["eligible"])

    def test_name_only_or_import_only_tests_do_not_count(self):
        for added in (
            ["use App\\Services\\SessionDeduction\\SessionDeductionService;", "public function test_x() {}"],
            ["// SessionDeductionService", "public function test_x() {}"],
            ["$svc = new SessionDeductionService();"],  # no test method
        ):
            self.assertFalse(scope([SVC, TEST], BILLING + chunk(TEST, added, ()))["eligible"], added)

    def test_destructive_writes_and_authorize_stay_t3(self):
        for added in (["$invoice->delete();"], ["$invoice->forceDelete();"], ["Invoice::destroy($id);"],
                      ["$this->authorize('update', $invoice); $invoice->save();"]):
            paths, patch = [SVC, TEST], chunk(SVC, added) + COVER
            self.assertFalse(scope(paths, patch)["eligible"], added)
            self.assertEqual(3, classify_activation_scope(paths, patch)["machine_minimum_tier"], added)

    def test_truncated_patch_stays_t3(self):
        truncated = BILLING.replace("@@ -1,1 +1,2 @@", "@@ -1,1 +1,5 @@")
        self.assertFalse(scope([SVC, TEST], truncated + COVER)["eligible"])

    def test_guarded_sensitive_billing_path_also_needs_review(self):
        path = "backend/app/Services/Billing/BillingPeriodInvoiceExists.php"
        test = chunk(TEST, ["public function test_exists() { BillingPeriodInvoiceExists::check(); }"], ())
        patch = chunk(path, ["return $invoice !== null;"], ("return false;",)) + test
        activation = classify_activation_scope([path, TEST], patch)
        self.assertTrue(activation["requires_independent_review"], activation)
        record = {"number": 3, "merged_at": "2026-10-14T00:00:00Z", "paths": [path, TEST], "patch": patch,
                  "patch_complete": True, "declared_risk": 2, "declared_tier": 2, "independent_review": False}
        self.assertTrue(classify_activation_provenance([record])["blocked"])

    def test_size_bounds_stay_t3(self):
        big = chunk(SVC, [f"$invoice->total{i} = $this->calculateCharge($s{i});" for i in range(260)])
        self.assertFalse(scope([SVC, TEST], big + COVER)["eligible"])


class StandingExceptionReview(unittest.TestCase):
    THREADS = {"nodes": [{"isResolved": True}], "pageInfo": {"hasNextPage": False}}
    CODEX = {"user": {"login": "chatgpt-codex-connector[bot]"}, "commit_id": HEAD, "state": "COMMENTED"}

    def test_codex_review_on_final_head_with_resolved_threads(self):
        self.assertTrue(has_independent_review([self.CODEX], self.THREADS, HEAD, "jerry200176-png"))

    def test_review_evidence_fails_closed(self):
        open_thread = {"nodes": [{"isResolved": True}, {"isResolved": False}], "pageInfo": {"hasNextPage": False}}
        more = {"nodes": [], "pageInfo": {"hasNextPage": True}}
        cases = (
            ([], self.THREADS, HEAD),
            ([dict(self.CODEX, commit_id="b" * 40)], self.THREADS, HEAD),
            ([dict(self.CODEX, user={"login": "jerry200176-png"})], self.THREADS, HEAD),
            ([dict(self.CODEX, user={"login": "cursor[bot]"})], self.THREADS, HEAD),
            ([dict(self.CODEX, state="PENDING")], self.THREADS, HEAD),
            ([self.CODEX], open_thread, HEAD),
            ([self.CODEX], more, HEAD),
            ([self.CODEX], {"nodes": []}, HEAD),
            ([self.CODEX], self.THREADS, "short"),
            (None, self.THREADS, HEAD),
        )
        for reviews, threads, head in cases:
            self.assertFalse(has_independent_review(reviews, threads, head, "jerry200176-png"), (reviews, threads, head))

    def test_provenance_blocks_without_review_and_allows_t2_with_it(self):
        record = {"number": 1, "merged_at": "2026-10-14T00:00:00Z", "paths": [SVC, TEST], "patch": BILLING + COVER,
                  "patch_complete": True, "declared_risk": 2, "declared_tier": 2}
        for evidence, blocked in ((None, True), (False, True), ("yes", True), (True, False)):
            result = classify_activation_provenance([dict(record, independent_review=evidence)])
            self.assertEqual(blocked, result["blocked"], evidence)
            if not blocked:
                self.assertEqual("T2", result["tier_name"])
                self.assertFalse(result["founder_required"])
            else:
                self.assertTrue(result["founder_required"])

    def test_routine_t2_does_not_need_review(self):
        path = "backend/app/Http/Controllers/ScheduleController.php"
        record = {"number": 2, "merged_at": "2026-10-14T00:00:00Z", "paths": [path],
                  "patch": chunk(path, ["$schedule->note = 'x';"]), "patch_complete": True,
                  "declared_risk": 2, "declared_tier": 2}
        self.assertFalse(classify_activation_provenance([record])["blocked"])


if __name__ == "__main__":
    unittest.main()
