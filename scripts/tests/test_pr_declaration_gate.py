import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

import re
import subprocess
import sys
import tempfile
import types

from scripts.governance import autonomy_gate
from scripts.governance.autonomy_gate import (
    _LOCAL_ONLY_E2E_SPECS,
    classify_activation_scope,
    classify_scope,
    is_control_plane_only_paths,
    machine_declaration,
    validate_declaration,
)
from scripts.governance.pr_requires_security import requires_security


class PrDeclarationGateTest(unittest.TestCase):
    def test_production_smoke_assertion_cannot_take_t0_auto_merge(self):
        path = "frontend/e2e/dashboard-workbench.spec.js"
        patch = (
            f"diff --git a/{path} b/{path}\n"
            "@@ -1 +1 @@\n"
            "-    expect(tuitionAlertsResponse.status()).toBe(200);\n"
            "+    test.skip(true, 'temporarily bypass tuition smoke');\n"
        )
        generated = machine_declaration([path], patch)
        self.assertEqual((generated["risk_class"], generated["autonomy_tier"]), ("R3", "T3"))
        rejected = validate_declaration("Risk-Class: R0\nAutonomy-Tier: T0", [path], patch)
        self.assertFalse(rejected["valid"])
        self.assertIn("below", rejected["error"])

    def test_only_production_smoke_controls_raise_test_only_scope(self):
        for path in (
            "frontend/playwright.config.js",
            "frontend/e2e/classroom-management-production-smoke.spec.js",
            "frontend/e2e/smoke.spec.js",
            "frontend/e2e/parent-portal-production.spec.js",
            "frontend/e2e/fixtures/dismissOverlays.js",
            "frontend/e2e/fixtures/directorDashboardTelemetry.js",
            "frontend/e2e/fixtures/tutoringReceivableControls.js",
        ):
            with self.subTest(path=path):
                self.assertEqual(classify_scope([path], "")["tier_name"], "T3")
        for path in (
            "frontend/e2e/assessment-playwright.config.js",
            "frontend/e2e/README.md",
            "frontend/e2e/fixtures/ui-foundation/pilot-mount.js",
            "frontend/src/components/__tests__/Widget.test.js",
        ):
            with self.subTest(path=path):
                self.assertEqual(classify_scope([path], "")["tier_name"], "T0")

    def test_npm_smoke_entrypoint_change_is_protected(self):
        patch = '-    "test:e2e": "playwright test"\n+    "test:e2e": "echo skipped"'
        self.assertEqual(classify_scope(["frontend/package.json"], patch)["tier_name"], "T3")
        self.assertNotEqual(classify_scope(["frontend/package.json"], '+    "vue": "3.5.1"')["tier_name"], "T3")

    def test_every_default_ui_smoke_spec_is_a_protected_control(self):
        paths = (
            "after-class-confirmed-save", "attendance-runtime", "billing-correction-guidance",
            "branch-health-clarity", "calendar-course-consistency", "calendar-print-production",
            "classroom-clarity", "classroom-management-production-smoke", "classroom-management-recovery",
            "dashboard-workbench", "duplicate-review-human-labels", "feedback-launcher-production",
            "navigation-history", "navigation-more-search", "parent-portal-production",
            "role-onboarding", "school-directory-production", "smoke", "tutoring-continuation",
            "tutoring-free-production",
        )
        for stem in paths:
            path = f"frontend/e2e/{stem}.spec.js"
            with self.subTest(path=path):
                declaration = machine_declaration([path], "+ weaken production smoke evidence")
                self.assertEqual((declaration["risk_class"], declaration["autonomy_tier"]), ("R3", "T3"))

    def test_local_only_playwright_specs_remain_test_only(self):
        config = (Path(__file__).resolve().parents[2] / "frontend/playwright.config.js").read_text(encoding="utf-8")
        for path in _LOCAL_ONLY_E2E_SPECS:
            with self.subTest(path=path):
                self.assertTrue((Path(__file__).resolve().parents[2] / path).is_file())
                stem = Path(path).name.removesuffix(".spec.js")
                self.assertIn(stem, config, f"{stem} must remain ignored by the default production smoke config")
                self.assertEqual(classify_scope([path], "")["tier_name"], "T0")

    def test_missing_declaration_fails_before_merge(self):
        result = validate_declaration(
            "",
            ["frontend/src/components/Badge.vue"],
            "+ display-only change",
        )
        self.assertFalse(result["valid"])
        self.assertIn("missing", result["error"])
        self.assertEqual(result["generated"]["autonomy_tier"], "T1")

    def test_malformed_declaration_fails_before_merge(self):
        result = validate_declaration(
            "Risk-Class: R9\nAutonomy-Tier: T1",
            ["frontend/src/components/Badge.vue"],
            "+ display-only change",
        )
        self.assertFalse(result["valid"])

    def test_understated_declaration_fails_against_machine_minimum(self):
        result = validate_declaration(
            "Risk-Class: R1\nAutonomy-Tier: T1",
            ["backend/app/Http/Controllers/StudentClassController.php"],
            "+ billing authorization check",
        )
        self.assertFalse(result["valid"])
        self.assertIn("below", result["error"])
        self.assertEqual(result["generated"]["autonomy_tier"], "T3")

    def test_valid_reversible_t1_and_t2_declarations_pass(self):
        self.assertTrue(
            validate_declaration(
                "Risk-Class: R0\nAutonomy-Tier: T0",
                ["docs/README.md"],
                "+ clarify documentation",
            )["valid"]
        )
        self.assertTrue(
            validate_declaration(
                "Risk-Class: R1\nAutonomy-Tier: T1",
                ["frontend/src/components/Badge.vue"],
                "+ display-only change",
            )["valid"]
        )
        self.assertTrue(
            validate_declaration(
                "Risk-Class: R2\nAutonomy-Tier: T2",
                ["frontend/src/pages/SmartCalendar.vue"],
                "+ schedule conflict display",
            )["valid"]
        )

    def test_billing_path_requires_protected_t3_declaration(self):
        paths = [
            "backend/app/Http/Controllers/StudentClassController.php",
            "backend/app/Services/TransactionDiscountCalculator.php",
        ]
        self.assertEqual(classify_scope(paths, "+ billing discount")["tier_name"], "T3")
        result = validate_declaration(
            "Risk-Class: R3\nAutonomy-Tier: T3",
            paths,
            "+ billing discount authorization",
        )
        self.assertTrue(result["valid"])
        activation = classify_activation_scope(paths, "+ billing discount authorization")
        self.assertIn(activation["activation_class"], {"routine", "founder-required"})

    def test_deploy_classifier_stays_independent_from_merge_gate(self):
        routine = classify_activation_scope(
            ["frontend/src/components/Badge.vue"], "+ display-only change"
        )
        self.assertEqual(routine["activation_class"], "routine")
        protected = classify_activation_scope(
            ["backend/app/Http/Controllers/StudentClassController.php"],
            "+ billing authorization mutation",
        )
        self.assertEqual(protected["activation_class"], "founder-required")

    def test_machine_declaration_is_derived_from_scope(self):
        generated = machine_declaration(
            ["frontend/src/pages/SmartCalendar.vue"], "+ schedule behavior"
        )
        self.assertEqual(generated["risk_class"], "R2")
        self.assertEqual(generated["autonomy_tier"], "T2")


WF = ".github/workflows/production-case-dump.yml"


def _wf_patch(*lines, path=WF):
    return f"diff --git a/{path} b/{path}\n@@ -1 +1 @@\n" + "\n".join("+" + l for l in lines)


class ReadOnlyProbeTierTest(unittest.TestCase):
    READ = ("if ($case === 'x') {", "$out['n'] = $db::table('Student')->where('id', 1)->count();", "}")

    def test_read_only_probe_is_t2_not_founder(self):
        r = validate_declaration("Risk-Class: R2\nAutonomy-Tier: T2", [WF, ".agent-session/manifest.json"], _wf_patch(*self.READ))
        self.assertTrue(r["valid"], r)
        self.assertEqual(r["generated"]["autonomy_tier"], "T2")

    def test_probe_with_write_stays_t3(self):
        for line in ("$db::table('Student')->where('id', 1)->update(['a' => 1]);", "DB::statement('x');", "UPDATE Student SET a=1", "permissions:", "run: php artisan migrate"):
            self.assertEqual(classify_scope([WF], _wf_patch(line))["tier_name"], "T3", line)

    def test_probe_plus_other_workflow_or_no_patch_stays_t3(self):
        other = ".github/workflows/deploy.yml"
        patch = _wf_patch(*self.READ) + "\n" + _wf_patch("# x", path=other)
        self.assertEqual(classify_scope([WF, other], patch)["tier_name"], "T3")
        self.assertEqual(classify_scope([WF], "")["tier_name"], "T3")


class WorkflowExecutedScriptTierTest(unittest.TestCase):
    """Retro #5201 row 16: a diff of only the merge review gate was classed R0 "documentation"."""

    T0_BODY = "Risk-Class: R0\nAutonomy-Tier: T0"

    @staticmethod
    def _executed_by_workflows() -> set[str]:
        # Read from the committed workflows and directories, so a new gate script is covered without editing a list.
        found = set()
        for workflow in (ROOT / ".github/workflows").glob("*.yml"):
            found.update(re.findall(r"\.github/(?:scripts|actions)/[\w./-]*\w", workflow.read_text(encoding="utf-8")))
        for folder in ("scripts", "actions"):
            found.update(p.relative_to(ROOT).as_posix() for p in (ROOT / ".github" / folder).rglob("*") if p.is_file())
        return found

    def test_merge_gate_scripts_are_never_docs(self):
        paths = self._executed_by_workflows()
        for required in ("review-gate.mjs", "land-queue.mjs", "autoland.mjs"):
            self.assertIn(f".github/scripts/{required}", paths)
        for path in sorted(paths):
            with self.subTest(path=path):
                generated = machine_declaration([path], "+ const ok = true;")
                self.assertEqual((generated["risk_class"], generated["autonomy_tier"]), ("R3", "T3"))
                self.assertNotIn("documentation or generated evidence only", generated["reasons"])
                rejected = validate_declaration(self.T0_BODY, [path], "+ const ok = true;")
                self.assertFalse(rejected["valid"])
                self.assertIn("below", rejected["error"])

    def test_review_gate_change_needs_the_r3_review_of_the_gate_itself(self):
        files = [{"filename": ".github/scripts/review-gate.mjs", "patch": "+ return { ok: true };"}]
        for body in (self.T0_BODY, "Risk-Class: R2\nAutonomy-Tier: T2", "Risk-Class: R3\nAutonomy-Tier: T3"):
            self.assertTrue(requires_security(body, files), body)
        renamed = [{"filename": "docs/notes.md", "previous_filename": ".github/scripts/land-queue.mjs", "patch": "+x"}]
        self.assertTrue(requires_security(self.T0_BODY, renamed))

    def test_gate_script_raises_a_mixed_docs_change(self):
        generated = machine_declaration(["docs/CHANGELOG.md", ".github/scripts/autoland.mjs"], "+ note")
        self.assertEqual(generated["autonomy_tier"], "T3")
        self.assertEqual(generated["reasons"], ["protected path: .github/scripts/autoland.mjs"])

    def test_pure_docs_change_stays_r0(self):
        for paths in (["docs/CHANGELOG.md"], ["README.md", "docs/changes/2026-11-11-x.md"], [".github/pull_request_template.md"]):
            with self.subTest(paths=paths):
                generated = machine_declaration(paths, "+ wording")
                self.assertEqual((generated["risk_class"], generated["autonomy_tier"]), ("R0", "T0"))
                self.assertTrue(validate_declaration(self.T0_BODY, paths, "+ wording")["valid"])

    def test_gate_script_is_effective_at_merge_not_a_production_activation(self):
        # Like governance/ docs, these run from main in Actions; nothing is deployed to the app host.
        path = ".github/scripts/review-gate.mjs"
        self.assertTrue(is_control_plane_only_paths([path]))
        activation = classify_activation_scope([path], "+ const ok = true;")
        self.assertEqual((activation["activation_class"], activation["runtime_paths"]), ("routine", []))


class ClassifierSelfProtectionTest(unittest.TestCase):
    """Retro #5201 rows 16a/16b/16c/16e. Controls only tighten: no path's tier drops, activation is unchanged."""

    PATCH = "+ const ok = true;"
    # Tiers and activation computed by autonomy_gate.py at this origin/main commit, before #5208 and this change.
    BASE_SHA = "f8e26b509382f5153de258f4b2fcfd91b3a2192d"
    G = "scripts/governance/"
    # (paths, tier at BASE_SHA, tier now, activation class at BASE_SHA, activation tier at BASE_SHA)
    MATRIX = (
        ([G + "autonomy_gate.py"], 2, 3, "routine", 1),
        ([G + "pr_requires_security.py"], 2, 3, "routine", 1),
        ([G + "check_pr_declaration.py"], 2, 3, "routine", 1),
        ([G + "pr_declaration.py"], 2, 3, "routine", 1),
        ([G + "pop_auto_approve.py"], 2, 3, "routine", 1),
        ([G + "r3_backend_paths.py"], 2, 3, "routine", 1),
        ([G + "gh_api_retry.py"], 2, 3, "routine", 1),
        ([".github/pii-log-workflows.exempt.txt"], 0, 3, "routine", 0),
        ([".github/zizmor.yml"], 0, 3, "routine", 0),
        ([".gitleaks.toml"], 0, 3, "routine", 0),
        ([".gitleaksignore"], 0, 3, "routine", 0),
        ([".github/scripts/review-gate.mjs"], 0, 3, "routine", 0),
        ([".github/actions/production-ssh-trust/action.yml"], 0, 3, "routine", 0),
        (["docs/CHANGELOG.md"], 0, 0, "routine", 0),
        (["docs/changes/2026-11-12-x.md"], 0, 0, "routine", 0),
        (["README.md"], 0, 0, "routine", 0),
        (["CONTRIBUTING.md"], 0, 0, "routine", 0),
        (["frontend/README.md"], 0, 0, "routine", 1),
        ([".github/pull_request_template.md"], 0, 0, "routine", 0),
        ([".github/ISSUE_TEMPLATE/02_bug_report.yml"], 0, 0, "routine", 0),
        (["operations/closeout/2026-11-11-x.md"], 0, 0, "routine", 0),
        ([".claude/skills/x/SKILL.md"], 0, 2, "routine", 0),
        ([".agents/skills/x/SKILL.md"], 0, 2, "routine", 0),
        (["backend/tests/Feature/XTest.php"], 0, 0, "routine", 0),
        (["scripts/ci/x.test.mjs"], 0, 0, "routine", 0),
        (["scripts/tests/test_x.py"], 0, 0, "routine", 0),
        (["frontend/e2e/attendance-clarity.spec.js"], 0, 0, "routine", 0),
        (["frontend/src/a.test.js"], 0, 0, "routine", 0),
        (["Makefile"], 0, 1, "routine", 0),
        (["package.json"], 0, 1, "routine", 0),
        (["infra/daan-staging/lifecycle/x.sh"], 0, 1, "routine", 0),
        ([".claude/settings.json"], 0, 3, "routine", 0),
        ([".claude/hooks/guard_bash.py"], 0, 3, "routine", 0),
        ([".claude/agents/qa-lead.md"], 0, 3, "routine", 0),
        ([".claude/settings.local.json"], 0, 3, "routine", 0),
        (["docs/PARENT_UPDATES.yml"], 0, 1, "routine", 0),
        (["docs/parent-updates/parent-2026-11-12-x.yml"], 0, 1, "routine", 0),
        (["docs/STAFF_UPDATES.yml"], 0, 1, "routine", 0),
        (["docs/staff-updates/staff-2026-11-12-x.yml"], 0, 1, "routine", 0),
        (["docs/x.md", ".claude/skills/x/SKILL.md"], 0, 2, "routine", 0),
        (["docs/staff-updates/staff-2026-11-12-x.yml", "frontend/src/App.vue"], 1, 1, "routine", 1),
        ([".github/labels.yml"], 0, 1, "routine", 0),
        ([".github/pii-log-workflows.txt"], 0, 3, "routine", 0),
        (["docker-compose.yml"], 0, 1, "routine", 0),
        (["brand/new/dir/file.xyz"], 0, 1, "routine", 0),
        (["notes/x.md"], 0, 1, "routine", 0),
        (["frontend/vite.config.js"], 0, 1, "routine", 1),
        (["frontend/vendor-modules/x/index.js"], 0, 1, "routine", 1),
        (["frontend/public/x.png"], 0, 1, "routine", 1),
        (["frontend/src/App.vue"], 1, 1, "routine", 1),
        (["frontend/package.json"], 2, 2, "routine", 1),
        (["backend/app/Models/X.php"], 2, 2, "routine", 1),
        ([".github/workflows/deploy.yml"], 3, 3, "founder-required", 3),
        (["backend/database/migrations/2026_01_01_x.php"], 3, 3, "founder-required", 3),
        (["scripts/ops/x.sh"], 3, 3, "founder-required", 3),
        (["scripts/post-deploy-verify.py"], 3, 3, "founder-required", 3),
        (["backend/app/Http/Controllers/BillingController.php"], 3, 3, "guarded-sensitive", 2),
        (["operations/closeout/bug-queue-dump.request.md"], 1, 1, "routine", 0),
        (["composer.lock"], 2, 2, "routine", 1),
        (["scripts/x.sh"], 2, 2, "routine", 1),
        (["docs/governance/X.md"], 3, 3, "routine", 0),
        (["AGENTS.md"], 3, 3, "founder-required", 3),
        (["docs/x.md", "Makefile"], 0, 1, "routine", 0),
        (["backend/tests/Feature/XTest.php", G + "autonomy_gate.py"], 2, 3, "routine", 1),
        (["frontend/src/App.vue", ".gitleaks.toml"], 1, 3, "routine", 1),
        (["docs/x.md", "backend/app/Models/X.php"], 2, 2, "routine", 1),
        (["docs/x.md", "scripts/ci/x.mjs"], 0, 0, "routine", 0),
        (["frontend/src/App.vue", "package.json"], 1, 1, "routine", 1),
    )
    # _ALWAYS_FOUNDER_ACTIVATION_PREFIXES as the literal tuple at BASE_SHA (row 16e: now derived).
    ALWAYS_FOUNDER_AT_BASE = (
        ".github/workflows/", ".github/CODEOWNERS", ".github/dependabot.yml", "backend/database/migrations/",
        "backend/app/Console/Commands/Repair", "backend/app/Services/Repair", "backend/app/Services/SessionDeduction",
        "backend/app/Services/SessionEntitlement", "backend/app/Services/ApprovalSessionSync",
        "backend/app/Http/Controllers/StudentIdentity", "backend/app/Http/Controllers/Auth",
        "backend/app/Http/Middleware/", "backend/routes/api.php", "scripts/ops/", "scripts/production",
        "governance/", "docs/governance/", "AGENTS.md", "CLAUDE.md", "codex.md",
    )

    @staticmethod
    def _load(source: str):
        module = types.ModuleType("autonomy_gate_under_test")
        exec(compile(source, "autonomy_gate_under_test.py", "exec"), module.__dict__)
        return module

    @staticmethod
    def _classifier_modules() -> set[str]:
        # Follow the imports: review-gate.mjs runs pr_requires_security.py; every scripts/governance module that
        # imports autonomy_gate, and every one a workflow or gate script names, decides a tier or an approval.
        found = {"scripts/governance/autonomy_gate.py"}
        for folder in (".github/workflows", ".github/scripts"):
            for source in (ROOT / folder).iterdir():
                if source.is_file():
                    found.update(re.findall(r"scripts/governance/[\w.-]*\w", source.read_text(encoding="utf-8")))
        for module in (ROOT / "scripts/governance").glob("*.py"):
            if "autonomy_gate" in module.read_text(encoding="utf-8"):
                found.add(module.relative_to(ROOT).as_posix())
        return found

    def test_the_classifier_cannot_clear_a_change_to_itself(self):
        modules = self._classifier_modules()
        for required in ("autonomy_gate.py", "pr_requires_security.py", "check_pr_declaration.py", "pr_declaration.py"):
            self.assertIn(f"scripts/governance/{required}", modules)
        for path in sorted(modules):
            with self.subTest(path=path):
                generated = machine_declaration([path], self.PATCH)
                self.assertEqual((generated["risk_class"], generated["autonomy_tier"]), ("R3", "T3"))
                self.assertEqual(generated["reasons"][0], f"protected path: {path}")
                self.assertFalse(validate_declaration("Risk-Class: R2\nAutonomy-Tier: T2", [path], self.PATCH)["valid"])
                self.assertTrue(requires_security("Risk-Class: R2\nAutonomy-Tier: T2", [{"filename": path, "patch": "+x"}]))

    def test_scanner_exemption_files_read_by_workflows_are_protected(self):
        # Enumerated from the workflows, so a renamed or newly referenced exemption file fails here.
        workflows = "\n".join(p.read_text(encoding="utf-8") for p in (ROOT / ".github/workflows").glob("*.yml"))
        referenced = set(re.findall(
            r"(?<![\w/.-])(\.gitleaks\.toml|\.gitleaksignore|\.github/zizmor\.yml|\.github/[\w.-]*exempt[\w-]*\.\w+)", workflows))
        self.assertLessEqual({".gitleaks.toml", ".github/zizmor.yml", ".github/pii-log-workflows.exempt.txt"}, referenced)
        for path in sorted(referenced | {".gitleaksignore"}):  # gitleaks reads .gitleaksignore from the source root
            with self.subTest(path=path):
                self.assertTrue((ROOT / path).is_file(), path)
                scope = classify_scope([path], "+ ci.yml  # exempt")
                self.assertEqual((scope["tier_name"], scope["reasons"]), ("T3", [f"protected path: {path}"]))
                self.assertFalse(validate_declaration("Risk-Class: R0\nAutonomy-Tier: T0", [path], "+x")["valid"])

    def test_agent_guards_and_the_pii_purge_list_are_protected(self):
        # Enumerated from what wires them: hooks named by .claude/settings.json, every file in .claude/hooks/,
        # and the purge list pii-log-purge.yml reads.
        settings = (ROOT / ".claude/settings.json").read_text(encoding="utf-8")
        paths = {".claude/settings.json", *re.findall(r"\.claude/hooks/[\w.-]*\w", settings)}
        paths.update(p.relative_to(ROOT).as_posix() for p in (ROOT / ".claude/hooks").rglob("*") if p.is_file())
        purge = (ROOT / ".github/workflows/pii-log-purge.yml").read_text(encoding="utf-8")
        paths.update(re.findall(r"\.github/pii-log-workflows\.txt", purge))
        self.assertLessEqual({".claude/hooks/guard_bash.py", ".github/pii-log-workflows.txt"}, paths)
        for path in sorted(paths):
            with self.subTest(path=path):
                generated = machine_declaration([path], "+ allow")
                self.assertEqual((generated["risk_class"], generated["autonomy_tier"]), ("R3", "T3"))
                self.assertEqual(generated["reasons"], [f"protected path: {path}"])
                for body in ("Risk-Class: R0\nAutonomy-Tier: T0", "Risk-Class: R2\nAutonomy-Tier: T2"):
                    self.assertFalse(validate_declaration(body, [path], "+ allow")["valid"])
                    self.assertTrue(requires_security(body, [{"filename": path, "patch": "+ allow"}]))
                activation = classify_activation_scope([path], "+ allow")
                self.assertEqual((activation["activation_class"], activation["runtime_paths"]), ("routine", []))

    def test_declaration_helper_reads_a_non_ascii_docs_path_as_docs(self):
        # git quotes non-ASCII names by default ("docs/\345..."); a quoted string is not a docs path.
        with tempfile.TemporaryDirectory() as repo:
            def git(*args):
                subprocess.run(["git", "-c", "user.name=t", "-c", "user.email=t@example.invalid", *args],
                               cwd=repo, check=True, capture_output=True)
            git("init", "-q", "-b", "main")
            Path(repo, "README.md").write_text("base\n", encoding="utf-8")
            git("add", "-A")
            git("commit", "-q", "-m", "base")
            git("checkout", "-q", "-b", "docs/x")
            Path(repo, "docs").mkdir()
            Path(repo, "docs", "排課說明.md").write_text("說明\n", encoding="utf-8")
            git("add", "-A")
            git("commit", "-q", "-m", "docs")
            out = subprocess.run(
                [sys.executable, str(ROOT / "scripts/governance/pr_declaration.py"), "--base", "main", "--head", "HEAD"],
                cwd=repo, check=True, capture_output=True, text=True).stdout
        self.assertEqual(out.splitlines()[:2], ["Risk-Class: R0", "Autonomy-Tier: T0"])
        self.assertNotIn("unclassified path", out)

    def test_unknown_path_is_unclassified_not_documentation(self):
        for path in ("Makefile", "package.json", "brand/new/dir/file.xyz", ".claude/mempal-stop-hook.sh", "notes/x.md"):
            with self.subTest(path=path):
                generated = machine_declaration([path], self.PATCH)
                self.assertEqual((generated["risk_class"], generated["autonomy_tier"]), ("R1", "T1"))
                self.assertEqual(generated["reasons"], [f"unclassified path: {path}"])
                self.assertFalse(validate_declaration("Risk-Class: R0\nAutonomy-Tier: T0", [path], self.PATCH)["valid"])
        mixed = machine_declaration(["docs/x.md", "Makefile"], self.PATCH)
        self.assertEqual((mixed["autonomy_tier"], mixed["reasons"]), ("T1", ["unclassified path: Makefile"]))

    def test_fallback_tier_is_read_from_the_module_setting(self):
        original = autonomy_gate._UNCLASSIFIED_PATH_TIER
        try:
            autonomy_gate._UNCLASSIFIED_PATH_TIER = 2
            self.assertEqual(classify_scope(["Makefile"], self.PATCH)["tier_name"], "T2")
        finally:
            autonomy_gate._UNCLASSIFIED_PATH_TIER = original

    def test_documentation_and_tests_stay_t0(self):
        for paths, _before, after, _cls, _tier in self.MATRIX:
            if after == 0:
                with self.subTest(paths=paths):
                    scope = classify_scope(paths, self.PATCH)
                    self.assertEqual(
                        (scope["tier_name"], scope["reasons"]), ("T0", ["documentation or generated evidence only"]))

    def test_tier_never_drops_and_activation_is_unchanged(self):
        for paths, before, after, activation_class, activation_tier in self.MATRIX:
            with self.subTest(paths=paths):
                tier = classify_scope(paths, self.PATCH)["machine_minimum_tier"]
                self.assertGreaterEqual(tier, before)
                self.assertEqual(tier, after)
                self.assertEqual(machine_declaration(paths, self.PATCH)["machine_minimum_tier"], after)
                activation = classify_activation_scope(paths, self.PATCH)
                self.assertEqual(
                    (activation["activation_class"], activation["machine_minimum_tier"]), (activation_class, activation_tier))

    def test_always_founder_activation_prefixes_are_derived_and_unchanged(self):
        self.assertEqual(autonomy_gate._ALWAYS_FOUNDER_ACTIVATION_PREFIXES, self.ALWAYS_FOUNDER_AT_BASE)
        for name in ("_ALWAYS_FOUNDER_ACTIVATION_PREFIXES", "_MERGE_ONLY_T3_PREFIXES", "_GUARDED_ACTIVATION_T3_PREFIXES"):
            self.assertLessEqual(set(getattr(autonomy_gate, name)), set(autonomy_gate._T3_PREFIXES), name)
        # Derived, not copied: a prefix added to _T3_PREFIXES alone becomes always-Founder at activation.
        source = Path(autonomy_gate.__file__).read_text(encoding="utf-8")
        marker = '    "codex.md",\n)\n\n# T3 at merge only'
        self.assertEqual(source.count(marker), 1)
        varied = self._load(source.replace(marker, '    "codex.md",\n    "backend/app/Services/NewProtected",\n)\n\n# T3 at merge only'))
        self.assertEqual(
            varied._ALWAYS_FOUNDER_ACTIVATION_PREFIXES, (*self.ALWAYS_FOUNDER_AT_BASE, "backend/app/Services/NewProtected"))

    def test_against_the_base_commit_when_it_is_in_the_clone(self):
        # Exact check when history is available (local, full clones); shallow CI checkouts rely on MATRIX above.
        shown = subprocess.run(["git", "show", f"{self.BASE_SHA}:scripts/governance/autonomy_gate.py"],
                               cwd=ROOT, capture_output=True, text=True)
        if shown.returncode != 0:
            self.skipTest("base commit not in this clone")
        base = self._load(shown.stdout)
        for paths, before, _after, _cls, _tier in self.MATRIX:
            with self.subTest(paths=paths):
                self.assertEqual(base.classify_scope(paths, self.PATCH)["machine_minimum_tier"], before)
                self.assertEqual(
                    classify_activation_scope(paths, self.PATCH), base.classify_activation_scope(paths, self.PATCH))
        tracked = subprocess.run(["git", "-c", "core.quotepath=off", "ls-files"],
                                 cwd=ROOT, capture_output=True, text=True, check=True)
        for path in tracked.stdout.splitlines():
            new, old = classify_scope([path], ""), base.classify_scope([path], "")
            self.assertGreaterEqual(new["machine_minimum_tier"], old["machine_minimum_tier"], path)
            self.assertEqual(classify_activation_scope([path], ""), base.classify_activation_scope([path], ""), path)

    def test_policy_document_lists_the_same_path_families_as_the_code(self):
        policy = (ROOT / "docs/governance/RISK_BASED_MERGE_POLICY.md").read_text(encoding="utf-8")
        blocks = re.findall(r"^```classifier-paths\n(.*?)^```", policy, re.DOTALL | re.MULTILINE)
        self.assertEqual(len(blocks), 1, "the path families live in exactly one place")
        documented: dict[str, list[str]] = {}
        for line in blocks[0].splitlines():
            family, _, path = line.partition(" ")
            documented.setdefault(family, []).append(path.strip())
        docs = set(autonomy_gate._DOCS_T0_PATTERNS)
        code = {
            "T3": sorted({*autonomy_gate._T3_PREFIXES, *autonomy_gate._DEPLOY_EXECUTOR_PATHS}),
            "T2": sorted(autonomy_gate._T2_PREFIXES),
            "T2-agent-procedures": sorted(autonomy_gate._AGENT_PROCEDURE_T2_PREFIXES),
            "T1-content": sorted(autonomy_gate._RUNTIME_CONTENT_T1_PATTERNS),
            "T0-docs": sorted(docs),
            "T0-tests": sorted(set(autonomy_gate._NON_DEPLOYABLE_PATTERNS) - docs),
            "unmatched": [f"T{autonomy_gate._UNCLASSIFIED_PATH_TIER}"],
        }
        self.assertEqual({family: sorted(paths) for family, paths in documented.items()}, code)


class AgentInstructionAndContentPathTest(unittest.TestCase):
    """Retro #5201 row 20. Agent definitions, agent procedures and runtime-read docs content are not "docs"."""

    PATCH = "+ always approve"
    LOWER = ("Risk-Class: R0\nAutonomy-Tier: T0", "Risk-Class: R1\nAutonomy-Tier: T1")

    @staticmethod
    def _tracked(*folders: str) -> list[str]:
        found = [p.relative_to(ROOT).as_posix() for folder in folders for p in (ROOT / folder).rglob("*") if p.is_file()]
        return sorted(found)

    def test_agent_definitions_and_local_settings_are_protected(self):
        # Every agent definition in the repo, plus the local settings file Claude Code merges over settings.json.
        paths = self._tracked(".claude/agents")
        self.assertIn(".claude/agents/qa-lead.md", paths)
        for path in (*paths, ".claude/agents/new/reviewer.md", ".claude/settings.local.json"):
            with self.subTest(path=path):
                generated = machine_declaration([path], self.PATCH)
                self.assertEqual((generated["risk_class"], generated["autonomy_tier"]), ("R3", "T3"))
                self.assertEqual(generated["reasons"], [f"protected path: {path}"])
                for body in (*self.LOWER, "Risk-Class: R2\nAutonomy-Tier: T2"):
                    self.assertFalse(validate_declaration(body, [path], self.PATCH)["valid"])
                    self.assertTrue(requires_security(body, [{"filename": path, "patch": self.PATCH}]))
                activation = classify_activation_scope([path], self.PATCH)
                self.assertEqual((activation["activation_class"], activation["runtime_paths"]), ("routine", []))

    def test_agent_procedures_are_t2_not_documentation(self):
        paths = self._tracked(".claude/skills", ".agents/skills")
        self.assertIn(".agents/skills/alltrue-release/SKILL.md", paths)
        for path in (*paths, ".claude/skills/new/SKILL.md", ".agents/skills/new/run.cjs"):
            with self.subTest(path=path):
                generated = machine_declaration([path], self.PATCH)
                self.assertEqual((generated["risk_class"], generated["autonomy_tier"]), ("R2", "T2"))
                self.assertEqual(generated["reasons"], [f"agent procedure path: {path}"])
                for body in self.LOWER:
                    self.assertFalse(validate_declaration(body, [path], self.PATCH)["valid"])
                activation = classify_activation_scope([path], self.PATCH)
                self.assertEqual((activation["founder_required"], activation["runtime_paths"]), (False, []))
        # Not T3: a procedure wording fix does not take the R3 security review.
        self.assertNotIn(".claude/skills/", autonomy_gate._T3_PREFIXES)
        self.assertNotIn(".agents/skills/", autonomy_gate._T3_PREFIXES)
        mixed = classify_scope(["docs/x.md", ".claude/skills/new/SKILL.md"], self.PATCH)
        self.assertEqual((mixed["tier_name"], mixed["reasons"]), ("T2", ["agent procedure path: .claude/skills/new/SKILL.md"]))

    def test_docs_files_the_app_reads_are_user_visible_content(self):
        # Enumerated from the readers: the two generators that bundle update cards into the frontend build.
        sources: set[str] = set()
        for script in ("scripts/parent-updates-to-js.mjs", "scripts/staff-updates-to-js.mjs"):
            text = (ROOT / script).read_text(encoding="utf-8")
            calls = re.findall(r"readUpdatesYaml\(root, '([\w.-]+)', '(docs/[\w-]+)'\)", text)
            self.assertEqual(len(calls), 1, script)
            sources.update({f"docs/{calls[0][0]}", f"{calls[0][1]}/x-2026-11-12-new.yml"})
            sources.update(self._tracked(calls[0][1]))
        self.assertLessEqual({"docs/PARENT_UPDATES.yml", "docs/STAFF_UPDATES.yml"}, sources)
        self.assertTrue(any(path.startswith("docs/staff-updates/") for path in sources))
        self.assertTrue(any(path.startswith("docs/parent-updates/") for path in sources))
        for path in sorted(sources):
            with self.subTest(path=path):
                generated = machine_declaration([path], "+ title: x")
                self.assertEqual((generated["risk_class"], generated["autonomy_tier"]), ("R1", "T1"))
                self.assertEqual(generated["reasons"], [f"user-visible content path: {path}"])
                self.assertFalse(validate_declaration("Risk-Class: R0\nAutonomy-Tier: T0", [path], "+ title: x")["valid"])
        # A neighbouring docs file the app does not read stays documentation.
        for path in ("docs/staff-updates.md", "docs/PARENT_UPDATES.yml.md", "docs/x/PARENT_UPDATES.yml"):
            self.assertEqual(classify_scope([path], "+x")["tier_name"], "T0", path)

    def test_new_protected_paths_are_merge_only_and_founder_activation_is_unchanged(self):
        for prefix in (".claude/agents/", ".claude/settings.local.json"):
            self.assertIn(prefix, autonomy_gate._T3_PREFIXES)
            self.assertIn(prefix, autonomy_gate._MERGE_ONLY_T3_PREFIXES)
        self.assertEqual(
            autonomy_gate._ALWAYS_FOUNDER_ACTIVATION_PREFIXES, ClassifierSelfProtectionTest.ALWAYS_FOUNDER_AT_BASE)
        for path in (".claude/agents/qa-lead.md", ".claude/skills/x/SKILL.md", "docs/staff-updates/x.yml"):
            activation = classify_activation_scope([path], self.PATCH)
            self.assertEqual(
                (activation["founder_required"], activation["machine_minimum_tier"], activation["auto_activation_holds"]),
                (False, 0, []), path)


    def test_no_tracked_file_drops_below_the_previous_classifier(self):
        # Exact check when history is available; shallow CI checkouts rely on the MATRIX rows above.
        previous_sha = "ad9eda202bd45b46286460c21d8149cf7fa77dd7"  # origin/main before this change (#5210)
        shown = subprocess.run(["git", "show", f"{previous_sha}:scripts/governance/autonomy_gate.py"],
                               cwd=ROOT, capture_output=True, text=True)
        if shown.returncode != 0:
            self.skipTest("previous commit not in this clone")
        previous = ClassifierSelfProtectionTest._load(shown.stdout)
        tracked = subprocess.run(["git", "ls-files", "-z"], cwd=ROOT, capture_output=True, text=True, check=True)
        raised: dict[tuple[int, int], int] = {}
        for path in filter(None, tracked.stdout.split("\0")):
            new, old = classify_scope([path], ""), previous.classify_scope([path], "")
            self.assertGreaterEqual(new["machine_minimum_tier"], old["machine_minimum_tier"], path)
            self.assertEqual(classify_activation_scope([path], ""), previous.classify_activation_scope([path], ""), path)
            if new["machine_minimum_tier"] != old["machine_minimum_tier"]:
                key = (old["machine_minimum_tier"], new["machine_minimum_tier"])
                raised[key] = raised.get(key, 0) + 1
        self.assertLessEqual(set(raised), {(0, 1), (0, 2), (1, 3)})


class DeclarationHelperPathListTest(unittest.TestCase):
    """Retro #5201 row 20: the local helper must hand the classifier every real path, unquoted."""

    def _declare(self, build) -> str:
        with tempfile.TemporaryDirectory() as repo:
            def git(*args):
                subprocess.run(["git", "-c", "user.name=t", "-c", "user.email=t@example.invalid", *args],
                               cwd=repo, check=True, capture_output=True)
            git("init", "-q", "-b", "main")
            Path(repo, "README.md").write_text("base\n", encoding="utf-8")
            Path(repo, "scripts/governance").mkdir(parents=True)
            Path(repo, "scripts/governance/gate.py").write_text("".join(f"line {i}\n" for i in range(40)), encoding="utf-8")
            git("add", "-A")
            git("commit", "-q", "-m", "base")
            git("checkout", "-q", "-b", "change")
            build(Path(repo), git)
            return subprocess.run(
                [sys.executable, str(ROOT / "scripts/governance/pr_declaration.py"), "--base", "main", "--head", "HEAD"],
                cwd=repo, check=True, capture_output=True, text=True).stdout

    def test_rename_out_of_a_protected_directory_is_still_protected(self):
        def build(repo, git):
            Path(repo, "docs").mkdir()
            git("mv", "scripts/governance/gate.py", "docs/gate.py")
            git("commit", "-q", "-m", "move")
        out = self._declare(build)
        self.assertEqual(out.splitlines()[:2], ["Risk-Class: R3", "Autonomy-Tier: T3"])
        self.assertIn("- protected path: scripts/governance/gate.py", out.splitlines())

    def test_tab_or_double_quote_in_a_protected_path_is_still_protected(self):
        for name in ("a\tb.py", 'q"x.py'):
            for committed in (True, False):
                with self.subTest(name=name, committed=committed):
                    def build(repo, git):
                        Path(repo, "scripts/governance", name).write_text("x = 1\n", encoding="utf-8")
                        if committed:
                            git("add", "-A")
                            git("commit", "-q", "-m", "add")
                    out = self._declare(build)
                    self.assertEqual(out.splitlines()[:2], ["Risk-Class: R3", "Autonomy-Tier: T3"])
                    self.assertIn(f"- protected path: scripts/governance/{name}", out.split("\n"))


class RequiresSecurityTest(unittest.TestCase):
    R3_FILE = {"filename": ".github/workflows/deploy.yml", "patch": "+x"}
    DOC = {"filename": "docs/x.md", "patch": "+x"}

    def check(self, body, files):
        from scripts.governance.pr_requires_security import requires_security
        return requires_security(body, files)

    def test_body_r1_on_r3_path_is_held(self):
        self.assertTrue(self.check("Risk-Class: R1\nAutonomy-Tier: T1", [self.R3_FILE]))

    def test_declared_r1_on_docs_passes(self):
        self.assertFalse(self.check("Risk-Class: R0\nAutonomy-Tier: T0", [self.DOC]))

    def test_declared_r3_or_missing_is_held(self):
        self.assertTrue(self.check("Risk-Class: R3\nAutonomy-Tier: T3", [self.DOC]))
        self.assertTrue(self.check("no declaration", [self.DOC]))

    def test_rename_missing_patch_and_cap_are_held(self):
        body = "Risk-Class: R0\nAutonomy-Tier: T0"
        self.assertTrue(self.check(body, [{"filename": "docs/y.md", "previous_filename": ".github/workflows/deploy.yml", "patch": "+x"}]))
        self.assertTrue(self.check(body, [{"filename": "docs/x.md"}]))
        self.assertTrue(self.check(body, [{"filename": "docs/x.md", "patch": None}]))
        self.assertTrue(self.check(body, [{"filename": f"docs/{i}.md", "patch": "+x"} for i in range(300)]))

    def test_lone_cr_and_vt_count_as_line_breaks(self):
        for sep in ("\r", "\x0b"):
            self.assertTrue(self.check(f"Risk-Class: R0{sep}Risk-Class: R3\nAutonomy-Tier: T0", [self.DOC]))

    def test_real_pr_template(self):
        tpl = (ROOT / ".github/pull_request_template.md").read_text(encoding="utf-8")
        fill = lambda r, t: tpl.replace("**Risk-Class:** R?", f"**Risk-Class:** {r}").replace("**Autonomy-Tier:** T?", f"**Autonomy-Tier:** {t}")
        self.assertFalse(self.check(fill("R0", "T0"), [self.DOC]))
        self.assertTrue(self.check(fill("R3", "T3"), [self.DOC]))
        self.assertTrue(self.check(tpl, [self.DOC]))  # unfilled

    def test_duplicate_declaration_is_held(self):
        self.assertTrue(self.check("Risk-Class: R0\nRisk-Class: R0\nAutonomy-Tier: T0", [self.DOC]))

    def test_u2028_on_declaration_line_is_held(self):
        self.assertTrue(self.check("Risk-Class: R0\u2028Risk-Class: R3\nAutonomy-Tier: T0", [self.DOC]))
        self.assertTrue(self.check("Risk-Class: R0\nAutonomy-Tier: T0 \u2029", [self.DOC]))


if __name__ == "__main__":
    unittest.main()
