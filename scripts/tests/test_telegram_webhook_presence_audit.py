#!/usr/bin/env python3

import unittest
from pathlib import Path


WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "telegram-webhook-presence-audit.yml"


class TelegramWebhookPresenceAuditTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")

    def test_is_manual_read_only_and_uses_pinned_ssh(self):
        self.assertIn("workflow_dispatch", self.workflow)
        self.assertIn("permissions:\n  contents: read", self.workflow)
        self.assertIn("PI_HOST_KEY", self.workflow)
        self.assertIn('PI_USER: ${{ secrets.PI_USER }}', self.workflow)
        self.assertIn('PI_HOST: ${{ secrets.PI_HOST }}', self.workflow)
        self.assertIn("StrictHostKeyChecking=yes", self.workflow)
        self.assertIn("actions/upload-artifact@v4", self.workflow)

    def test_reports_only_presence_metadata_for_campus_15(self):
        self.assertIn('"campus_15_has_telegram_token"', self.workflow)
        self.assertIn('"campus_15_has_webhook_secret"', self.workflow)
        self.assertIn('"webhook_secret_missing_with_token_count"', self.workflow)
        self.assertIn('firstWhere("id", 15)', self.workflow)
        self.assertNotIn("TelegramWebhookSecret, PHP_EOL", self.workflow)
        self.assertNotIn("TelegramToken, PHP_EOL", self.workflow)

    def test_contains_no_telegram_or_database_mutation(self):
        forbidden = (
            "setWebhook",
            "secret_token",
            "ALTER ",
            "UPDATE ",
            "INSERT ",
            "DELETE ",
            "->save(",
            "->update(",
            "->create(",
            "->delete(",
        )
        for token in forbidden:
            self.assertNotIn(token, self.workflow)


if __name__ == "__main__":
    unittest.main()
