import datetime as dt
import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("credential_expiry", ROOT / "scripts" / "credential-expiry.py")
ce = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ce)

TODAY = dt.date(2026, 10, 21)


def reg(lead, *dates):
    return {"lead_days": lead, "credentials": [{"name": f"C{i}", "expires_on": d} for i, d in enumerate(dates)]}


class CredentialExpiryTest(unittest.TestCase):
    def test_window_follows_lead_days(self):
        r = reg(14, "2026-11-04", "2026-11-05")  # +14 due, +15 not
        self.assertEqual([d["name"] for d in ce.evaluate(r, TODAY)["due"]], ["C0"])
        r["lead_days"] = 15
        self.assertEqual([d["name"] for d in ce.evaluate(r, TODAY)["due"]], ["C0", "C1"])

    def test_expired_is_due_and_sorted_first(self):
        out = ce.evaluate(reg(14, "2026-11-01", "2026-10-01"), TODAY)["due"]
        self.assertEqual([d["name"] for d in out], ["C1", "C0"])
        self.assertLess(out[0]["days_left"], 0)

    def test_null_date_is_undated_not_due(self):
        out = ce.evaluate(reg(14, None, "2027-01-01"), TODAY)
        self.assertEqual(out["undated"], ["C0"])
        self.assertEqual(out["due"], [])

    def test_title_is_stable_per_credential_and_date(self):
        t = ce.evaluate(reg(14, "2026-11-01"), TODAY)["due"][0]["title"]
        self.assertEqual(t, "[security] Credential C0 expires 2026-11-01")

    def test_committed_registry_parses_and_holds_no_values(self):
        out = subprocess.run([sys.executable, str(ROOT / "scripts" / "credential-expiry.py")], capture_output=True, text=True, check=True)
        self.assertIn("due", json.loads(out.stdout))
        text = (ROOT / "docs" / "ops" / "credential-expiry.yml").read_text()
        for bad in ("password:", "secret:", "token:", "BEGIN "):
            self.assertNotIn(bad, text.lower() if bad != "BEGIN " else text)


if __name__ == "__main__":
    unittest.main()
