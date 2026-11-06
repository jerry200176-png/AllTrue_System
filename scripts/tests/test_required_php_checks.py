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
        shard = job_block(ci, "phpunit_shard", "phpunit")
        phpunit = job_block(ci, "phpunit", "vite_core")

        # The required check is the aggregator: exact name, always runs, needs every shard.
        self.assertIn("name: PHPUnit Feature & Unit Tests", phpunit)
        self.assertIn("needs: phpunit_shard", phpunit)
        self.assertIn("\n    if: always()", phpunit)
        self.assertIn('[ "$SHARDS" = "success" ]', phpunit)
        self.assertNotIn("needs.changes.outputs", phpunit)
        self.assertNotIn("name: PHPUnit Feature & Unit Tests", shard)
        self.assertIn("runs-on: ubuntu-latest", shard)
        self.assertNotIn("needs.changes.outputs", shard)
        self.assertNotIn("\n    if:", shard)

    def test_vite_check_aggregates_core_and_playwright_shards(self):
        ci = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        vite = job_block(ci, "vite-build", "design-advisory")

        self.assertIn("name: Vite Frontend Build", vite)
        self.assertIn("needs: [changes, vite_core, ui_foundation_e2e]", vite)
        self.assertIn("\n    if: always()", vite)
        self.assertNotIn("name: Vite Frontend Build", job_block(ci, "vite_core", "ui_foundation_e2e"))

    def test_phpstan_is_not_path_skipped(self):
        workflow = (ROOT / ".github/workflows/codeql.yml").read_text(encoding="utf-8")
        phpstan = job_block(workflow, "phpstan")

        self.assertIn("name: PHPStan Advisory (php)", phpstan)
        self.assertIn("runs-on: ubuntu-latest", phpstan)
        self.assertNotIn("needs.changes.outputs", phpstan)
        self.assertNotIn("\n    if:", phpstan)


if __name__ == "__main__":
    unittest.main()
