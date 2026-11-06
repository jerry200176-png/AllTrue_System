"""Required PHP checks must execute instead of being path-skipped."""

from pathlib import Path
import subprocess
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


class MergeGroupTriggerTest(unittest.TestCase):
    """Native merge queue only reports required checks from workflows that trigger on merge_group."""

    REQUIRED_WORKFLOWS = ["presubmit", "codeql", "ci", "docs-integrity", "secret-scan"]

    def test_required_workflows_trigger_on_merge_group(self):
        for name in self.REQUIRED_WORKFLOWS:
            source = (ROOT / f".github/workflows/{name}.yml").read_text(encoding="utf-8")
            self.assertIn("\n  merge_group:\n    types: [checks_requested]\n", source, name)

    def test_merge_group_head_ref_to_pr_number(self):
        script = ROOT / "scripts/ci/merge-group-context.sh"
        sha = "0123456789abcdef0123456789abcdef01234567"

        def parse(ref):
            return subprocess.run(["bash", str(script), "--parse", ref], capture_output=True, text=True)

        for ref in (f"refs/heads/gh-readonly-queue/main/pr-4978-{sha}", f"gh-readonly-queue/main/pr-4978-{sha}"):
            result = parse(ref)
            self.assertEqual((result.returncode, result.stdout.strip()), (0, "4978"), ref)
        for ref in ("refs/heads/main", f"refs/heads/gh-readonly-queue/main/pr-x-{sha}", "gh-readonly-queue/main/pr-1-abc"):
            self.assertNotEqual(parse(ref).returncode, 0, ref)


if __name__ == "__main__":
    unittest.main()
