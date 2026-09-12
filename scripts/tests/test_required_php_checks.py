"""Required PHP checks must execute instead of being path-skipped."""

from pathlib import Path
import unittest


ROOT = Path(__file__).parents[2]


def job_block(source: str, name: str, next_name: str | None = None) -> str:
    start = source.index(f"  {name}:\n")
    end = source.index(f"\n  {next_name}:", start) if next_name else len(source)
    return source[start:end]


class RequiredPhpChecksTest(unittest.TestCase):
    def test_phpunit_is_not_path_skipped(self):
        ci = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        phpunit = job_block(ci, "phpunit", "vite-build")

        self.assertIn("name: PHPUnit Feature & Unit Tests", phpunit)
        self.assertIn("runs-on: ubuntu-latest", phpunit)
        self.assertNotIn("needs.changes.outputs", phpunit)
        self.assertNotIn("\n    if:", phpunit)

    def test_phpstan_is_not_path_skipped(self):
        workflow = (ROOT / ".github/workflows/codeql.yml").read_text(encoding="utf-8")
        phpstan = job_block(workflow, "phpstan")

        self.assertIn("name: PHPStan Advisory (php)", phpstan)
        self.assertIn("runs-on: ubuntu-latest", phpstan)
        self.assertNotIn("needs.changes.outputs", phpstan)
        self.assertNotIn("\n    if:", phpstan)


if __name__ == "__main__":
    unittest.main()
