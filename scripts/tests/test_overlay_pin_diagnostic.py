"""Exercise the real shell gate offline; malformed/migrated input must fail closed."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "governance/check-overlay-pin.sh"


class OverlayPinDiagnosticTest(unittest.TestCase):
    def check_overlay(self, overlay):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "constitution.md").write_text("**Version:** 0.1.0\n")
            (root / "overlay.md").write_text(overlay)
            return subprocess.run(
                ["bash", str(SCRIPT)], capture_output=True, text=True,
                env={**os.environ, "CONSTITUTION_FILE": str(root / "constitution.md"),
                     "OVERLAY_FILE": str(root / "overlay.md")},
            )

    def test_matching_legacy_pin_passes(self):
        result = self.check_overlay("Constitution v0.1.0\n")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("overlay-pin: OK", result.stdout)

    def test_legacy_drift_still_fails(self):
        result = self.check_overlay("Constitution 0.2.0\n")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("pin drift constitution=0.1.0 overlay=0.2.0", result.stderr)

    def test_rulebook_migration_is_diagnosed_and_never_uses_cached_pin(self):
        result = self.check_overlay("Rules: governance/RULEBOOK.md; pinned in governance/RULEBOOK.lock.\n")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("legacy Constitution equality is unverified", result.stderr)
        self.assertIn("do not substitute SUNRISE_OVERLAY_PIN", result.stderr)
        self.assertNotIn("overlay-pin: OK", result.stdout)

    def test_unrecognized_or_empty_input_is_an_explicit_failure(self):
        for overlay in ["", "unknown contract"]:
            with self.subTest(overlay=overlay):
                result = self.check_overlay(overlay)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("overlay-pin: FAIL: could not parse overlay pin version", result.stderr)


if __name__ == "__main__":
    unittest.main()
