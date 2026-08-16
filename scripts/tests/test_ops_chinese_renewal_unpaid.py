import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "ops-chinese-renewal-unpaid.sh"
PHP = ROOT / "scripts" / "ops-chinese-renewal-unpaid.php"
WORKFLOW = ROOT / ".github" / "workflows" / "ops-chinese-renewal-unpaid.yml"


class ChineseRenewalUnpaidTest(unittest.TestCase):
    def test_script_is_exact_allowlist_and_unpaid(self):
        text = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("zhang_zheng_ning:374:1681:張正甯", text)
        self.assertIn("zhang_zheng_le:373:1682:張正樂", text)
        self.assertIn("2649|2606|1324", text)
        self.assertIn("2026-08-19", text)
        self.assertIn("SESSIONS:-8", text)
        self.assertIn("paid=0", text)
        self.assertIn("DRY_RUN_ONLY", text)
        self.assertIn("APPROVE_CHINESE_RENEWAL_UNPAID_8_SESSIONS_20260814", text)
        self.assertIn("PLAN_OK", text)
        self.assertNotRegex(text, r"\bPaid\s*=\s*1\b")
        self.assertNotRegex(text, r"repair:transfer-session-entitlement")

    def test_php_invokes_official_purchase_batch_without_payment(self):
        text = PHP.read_text(encoding="utf-8")
        self.assertIn("purchaseBatch", text)
        self.assertIn("new_purchase", text)
        self.assertIn("auth_role", text)
        self.assertIn("super_admin", text)
        self.assertIn("paid !== 0", text)
        self.assertIn("23157, 27156", text)
        self.assertNotRegex(text, r"Paid'\s*=>\s*1")
        self.assertNotRegex(text, r"transfer-session-entitlement")

    def test_workflow_is_fail_closed(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("workflow_dispatch", text)
        self.assertIn("scripts/ops-chinese-renewal-unpaid.sh", text)
        self.assertIn("scripts/ops-chinese-renewal-unpaid.php", text)
        self.assertTrue(re.search(r"contents:\s*read", text))
        self.assertIn("apply-chinese-renewal-unpaid", text)
        self.assertIn("APPROVE_CHINESE_RENEWAL_UNPAID_8_SESSIONS_20260814", text)
        self.assertIn("zhang_zheng_ning", text)
        self.assertIn("zhang_zheng_le", text)
        self.assertNotIn("2649", text)
        self.assertNotIn("2606", text)


if __name__ == "__main__":
    unittest.main()

