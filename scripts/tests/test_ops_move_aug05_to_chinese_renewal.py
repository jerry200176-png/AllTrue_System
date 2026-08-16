import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "ops-move-aug05-to-chinese-renewal.sh"
PHP = ROOT / "scripts" / "ops-cancel-last-future-slot.php"
WORKFLOW = ROOT / ".github" / "workflows" / "ops-move-aug05-to-chinese-renewal.yml"


class MoveAug05ChineseRenewalTest(unittest.TestCase):
    def test_allowlist_excludes_math_batches(self):
        text = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("zhang_zheng_ning:374:1681:3230:23157:張正甯", text)
        self.assertIn("zhang_zheng_le:373:1682:3231:27156:張正樂", text)
        self.assertIn("2649|2606|1324", text)
        self.assertIn("DRY_RUN_ONLY", text)
        self.assertIn("APPROVE_MOVE_20260805_TO_3230_3231", text)

    def test_php_only_cancels_renewal_targets(self):
        text = PHP.read_text(encoding="utf-8")
        self.assertIn("[3230, 3231]", text)
        self.assertIn("cancelled", text)
        self.assertNotIn("2649", text)
        self.assertNotIn("2606", text)

    def test_workflow_is_fail_closed(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertTrue(re.search(r"contents:\s*read", text))
        self.assertIn("3230", text)
        self.assertIn("3231", text)
        self.assertNotIn("2649", text)
        self.assertIn("apply-move-aug05-chinese", text)


if __name__ == "__main__":
    unittest.main()
