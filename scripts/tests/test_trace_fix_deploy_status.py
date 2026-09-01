import importlib.util
import unittest
from pathlib import Path


ROOT = Path(__file__).parents[2]
SCRIPT = ROOT / "scripts" / "trace-fix-deploy-status.py"
SPEC = importlib.util.spec_from_file_location("trace_fix_deploy_status", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


class TraceFixDeployStatusTest(unittest.TestCase):
    def test_issue_reference_requires_exact_hash_number(self):
        pattern = MODULE.re.compile(MODULE.ISSUE_REFERENCE_TEMPLATE.format(878))
        self.assertIsNotNone(pattern.search("Refs #878"))
        self.assertIsNotNone(pattern.search("Closes #878"))
        self.assertIsNone(pattern.search("Refs #1878"))
        self.assertIsNone(pattern.search("Refs #8780"))

    def test_runtime_status_does_not_treat_unknown_commit_as_live(self):
        self.assertEqual(MODULE.status(None), "NOT_VERIFIED (commit unavailable locally)")
        self.assertEqual(MODULE.status(False), "NOT_LIVE")
        self.assertEqual(MODULE.status(True), "LIVE")

    def test_trace_contract_reports_all_runtime_evidence_boundaries(self):
        source = SCRIPT.read_text(encoding="utf-8")
        for marker in (
            "frontend_build_sha",
            "release_evidence:",
            "deploy_run:",
            "NOT_VERIFIED: this read-only trace cannot infer app status",
            "GitHub issue closure and in-app resolved state remain separate decisions",
            "git", "merge-base", "deployment.json",
        ):
            self.assertIn(marker, source)


if __name__ == "__main__":
    unittest.main()
