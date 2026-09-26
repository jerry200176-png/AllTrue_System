#!/usr/bin/env python3
"""Regression tests for the Composer-audit parser embedded in CI."""

import json
from pathlib import Path
import subprocess
import sys
import tempfile
import textwrap
import unittest


REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = REPOSITORY_ROOT / ".github/workflows/ci.yml"
AUDIT_INPUT = 'open("/tmp/composer-audit.json")'


def composer_audit_parser() -> str:
    workflow = WORKFLOW.read_text(encoding="utf-8")
    audit_step = workflow.index("      - name: Composer audit (security)")
    start_marker = "          python3 - <<'PYEOF'\n"
    start = workflow.index(start_marker, audit_step) + len(start_marker)
    end = workflow.index("          PYEOF\n", start)
    return textwrap.dedent(workflow[start:end])


def run_parser(payload: dict) -> subprocess.CompletedProcess[str]:
    with tempfile.TemporaryDirectory() as directory:
        fixture = Path(directory) / "composer-audit.json"
        fixture.write_text(json.dumps(payload), encoding="utf-8")
        parser = composer_audit_parser()
        parser = parser.replace(AUDIT_INPUT, f"open({str(fixture)!r})", 1)
        if AUDIT_INPUT in parser:
            raise AssertionError("Composer audit parser input was not replaced")
        return subprocess.run(
            [sys.executable, "-c", parser],
            check=False,
            capture_output=True,
            text=True,
        )


class ComposerAuditShapeTest(unittest.TestCase):
    def test_empty_list_advisories_are_clean(self) -> None:
        result = run_parser({"advisories": [], "ignored-advisories": []})

        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("無已知漏洞", result.stdout)
        self.assertNotIn("AttributeError", result.stderr)

    def test_high_advisory_still_blocks(self) -> None:
        result = run_parser(
            {
                "advisories": {
                    "example/package": {
                        "severity": "high",
                        "title": "Regression fixture",
                    }
                },
                "ignored-advisories": [],
            }
        )

        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("::error::example/package: [HIGH] Regression fixture", result.stdout)
        self.assertIn("HIGH/CRITICAL", result.stdout)

    def test_ignored_advisory_reporting_is_preserved(self) -> None:
        result = run_parser(
            {
                "advisories": [],
                "ignored-advisories": {
                    "example/package": {
                        "severity": "low",
                        "title": "Accepted fixture",
                        "ignoreReason": "Regression coverage",
                    }
                },
            }
        )

        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("::notice::example/package: [LOW] accepted risk", result.stdout)


if __name__ == "__main__":
    unittest.main()
