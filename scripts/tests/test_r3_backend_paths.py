import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.governance.r3_backend_paths import r3_backend_php


class R3BackendPathsTest(unittest.TestCase):
    def test_only_r3_backend_php(self):
        paths = [
            "backend/app/Services/SessionDeduction/X.php",  # R3 prefix
            "backend/app/Http/Middleware/Auth.php",  # R3 prefix
            "backend/app/Services/Plain.php",  # backend but not R3
            "backend/app/Services/Repair/README.md",  # not php
            "backend/tests/Feature/XTest.php",
            "scripts/governance/autonomy_gate.py",
        ]
        self.assertEqual(r3_backend_php(paths), ["backend/app/Services/SessionDeduction/X.php", "backend/app/Http/Middleware/Auth.php"])


if __name__ == "__main__":
    unittest.main()
