import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "diagnose-director-285.sh"
WORKFLOW = ROOT / ".github" / "workflows" / "ops-director-285-diagnose.yml"


class DiagnoseDirector285Test(unittest.TestCase):
    def test_script_is_exact_allowlist_and_read_only(self):
        text = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("ALLOW_USER_ID=285", text)
        self.assertIn("w3-director-test-20260813", text)
        self.assertIn("ALLOW_CAMPUS_ID=9", text)
        self.assertIn("IDENTITY_OK", text)
        self.assertIn("SELECT", text)
        self.assertNotRegex(text, r"\b(DELETE FROM|INSERT INTO|UPDATE |DROP |TRUNCATE |ALTER )\b")
        self.assertIn("workflow_dispatch", WORKFLOW.read_text(encoding="utf-8"))
        self.assertIn("scripts/diagnose-director-285.sh", WORKFLOW.read_text(encoding="utf-8"))
        self.assertTrue(re.search(r"contents:\s*read", WORKFLOW.read_text(encoding="utf-8")))


if __name__ == "__main__":
    unittest.main()
