#!/usr/bin/env python3

import unittest
from pathlib import Path


WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "td076-collision-evidence.yml"


class Td076CollisionEvidenceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")

    def test_is_manual_pinned_and_read_only(self):
        self.assertIn("workflow_dispatch", self.workflow)
        self.assertIn("permissions:\n  contents: read", self.workflow)
        self.assertIn("PI_HOST_KEY", self.workflow)
        self.assertIn('PI_USER: ${{ secrets.PI_USER }}', self.workflow)
        self.assertIn('PI_HOST: ${{ secrets.PI_HOST }}', self.workflow)
        self.assertIn("StrictHostKeyChecking=yes", self.workflow)
        self.assertIn("schedules:backfill-occurrence-identity", self.workflow)
        self.assertNotIn("schedules:backfill-occurrence-identity --execute", self.workflow)
        self.assertEqual(self.workflow.count("REMOTE"), 2)

    def test_queries_all_required_relation_evidence(self):
        for table in ("ClassSession", "LearningRecord", "StudentSingIn", "schedule_change_log", "Invoice", "Payment"):
            self.assertIn(f'DB::table("{table}', self.workflow)
        self.assertIn('"collision_index"', self.workflow)
        self.assertIn('"keeper_decision"', self.workflow)
        self.assertIn('"read_only" => true', self.workflow)
        self.assertIn('"groups_with_keeper_decision" => 0', self.workflow)

    def test_has_no_mutation_or_pii_output_path(self):
        for token in ("->save(", "->update(", "->delete(", "ALTER ", "INSERT ", "UPDATE ", "DELETE ", "setWebhook", "secret_token", "student_name", "phone", "Content", "Amount", "Note"):
            self.assertNotIn(token, self.workflow)
        self.assertIn("UNDECIDED_REQUIRES_CHAIN_AND_OWNER_REVIEW", self.workflow)
        self.assertIn("no execute path is authorized", self.workflow)


if __name__ == "__main__":
    unittest.main()
