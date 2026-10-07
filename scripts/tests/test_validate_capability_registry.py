"""#3491: high-risk capabilities warn before review_after hard-fails every PR."""
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "governance" / "validate-capability-registry.py"


def run(review_after, risk="high", today="2026-10-01"):
    cap = {
        "id": "cap_x", "name": "x", "status": "Proven", "risk": risk,
        "last_verified_at": "2026-09-01", "review_after": review_after,
        "verification_method": "m", "environment": "prod", "verifier": "v", "evidence": "e",
    }
    with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as f:
        json.dump({"capabilities": [cap]}, f)
    env = {"PATH": "/usr/bin:/bin"}  # no GITHUB_ACTIONS: plain WARN prefix
    return subprocess.run([sys.executable, str(SCRIPT), "--file", f.name, "--today", today],
                          capture_output=True, text=True, env=env)


class CapabilityExpiryWarningTest(unittest.TestCase):
    def test_high_risk_within_window_warns_but_passes(self):
        r = run("2026-10-07")
        self.assertEqual(r.returncode, 0, r.stdout)
        self.assertIn("WARN: high-risk capability cap_x review_after=2026-10-07 hard-fails in 7 day(s)", r.stdout)

    def test_due_today_warns_one_day(self):
        r = run("2026-10-01")
        self.assertEqual(r.returncode, 0, r.stdout)
        self.assertIn("hard-fails in 1 day(s)", r.stdout)

    def test_high_risk_outside_window_is_silent(self):
        r = run("2026-10-08")
        self.assertEqual(r.returncode, 0)
        self.assertNotIn("hard-fails", r.stdout)

    def test_low_risk_within_window_is_silent(self):
        r = run("2026-10-03", risk="low")
        self.assertNotIn("hard-fails", r.stdout)

    def test_high_risk_past_review_after_still_fails_closed(self):
        r = run("2026-09-30")
        self.assertEqual(r.returncode, 1)
        self.assertIn("high-risk fail-closed", r.stdout)


if __name__ == "__main__":
    unittest.main()
