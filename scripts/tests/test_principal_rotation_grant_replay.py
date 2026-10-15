"""Executes the Phase 1 SHOW GRANTS transformer embedded in deploy.yml.

The transformer runs on the Pi inside a quoted heredoc, so this test extracts
the exact text that ships in the workflow and runs it against fixtures. All
hashes and passwords below are synthetic test values.
"""

from pathlib import Path
import os
import re
import subprocess
import sys
import tempfile
import textwrap
import unittest


WORKFLOW = Path(__file__).parents[2] / ".github" / "workflows" / "deploy.yml"
OLD_USER = "admin"
OLD_HOST = "%"
NEW_USER = "atr_261015000000_abc123"
NEW_PASS = "0" * 8 + "feedface" * 7
FAKE_HASH = "*" + "A1B2C3D4E5" * 4
FAKE_NATIVE = "*" + "0F1E2D3C4B" * 4
REFUSAL = "SHOW GRANTS contains authentication material; refusing unsafe replay"


def extract_transformer():
    workflow = WORKFLOW.read_text(encoding="utf-8")
    job = workflow[workflow.index("  staged_principal_rotation:"):]
    match = re.search(r"cat > \"\$TRANSFORMER\" <<'PY'\n(.*?)\n\s*PY\n", job, re.S)
    if not match:
        raise AssertionError("Phase 1 transformer heredoc not found in deploy.yml")
    return textwrap.dedent(match.group(1)) + "\n"


class GrantReplayTransformerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        cls.script = Path(cls.tmp.name) / "transform.py"
        cls.script.write_text(extract_transformer(), encoding="utf-8")

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def run_transform(self, grants):
        env = dict(
            os.environ,
            OLD_DB_USERNAME=OLD_USER,
            OLD_ACCOUNT_HOST=OLD_HOST,
            STAGED_DB_USERNAME=NEW_USER,
            STAGED_DB_PASSWORD=NEW_PASS,
        )
        return subprocess.run(
            [sys.executable, "-I", str(self.script)],
            input="\n".join(grants) + "\n",
            capture_output=True,
            text=True,
            env=env,
            check=False,
        )

    def assert_replayed(self, result, expected_grants, stripped):
        self.assertEqual(result.returncode, 0, result.stderr)
        lines = result.stdout.splitlines()
        self.assertEqual(lines[0], f"CREATE USER '{NEW_USER}'@'%' IDENTIFIED BY '{NEW_PASS}';")
        self.assertEqual(lines[1:], expected_grants)
        for line in lines[1:]:
            self.assertNotIn("IDENTIFIED", line.upper())
            self.assertNotIn("PASSWORD", line.upper())
        self.assertIn(
            f"grant replay: {len(expected_grants)} statement(s), {stripped} authentication clause(s) stripped",
            result.stderr,
        )

    def assert_no_secret_material(self, result):
        for text in (result.stdout, result.stderr):
            self.assertNotIn(FAKE_HASH, text)
            self.assertNotIn(FAKE_HASH[1:], text)
            self.assertNotIn(FAKE_NATIVE, text)
            self.assertNotIn(FAKE_NATIVE[1:], text)
        self.assertNotIn(NEW_PASS, result.stderr)

    def test_mariadb_identified_by_password_is_stripped_and_retargeted(self):
        result = self.run_transform([
            f"GRANT USAGE ON *.* TO `admin`@`%` IDENTIFIED BY PASSWORD '{FAKE_HASH}'",
            "GRANT ALL PRIVILEGES ON `alltrue`.* TO `admin`@`%` WITH GRANT OPTION",
        ])
        self.assert_replayed(result, [
            f"GRANT USAGE ON *.* TO '{NEW_USER}'@'%';",
            f"GRANT ALL PRIVILEGES ON `alltrue`.* TO '{NEW_USER}'@'%' WITH GRANT OPTION;",
        ], stripped=1)
        self.assert_no_secret_material(result)

    def test_mariadb_identified_via_plugin_using_is_stripped(self):
        result = self.run_transform([
            f"GRANT USAGE ON *.* TO 'admin'@'%' IDENTIFIED VIA mysql_native_password USING '{FAKE_NATIVE}' OR unix_socket",
            "GRANT SELECT, INSERT ON `alltrue`.* TO 'admin'@'%'",
        ])
        self.assert_replayed(result, [
            f"GRANT USAGE ON *.* TO '{NEW_USER}'@'%';",
            f"GRANT SELECT, INSERT ON `alltrue`.* TO '{NEW_USER}'@'%';",
        ], stripped=1)
        self.assert_no_secret_material(result)

    def test_identified_via_keeps_trailing_grant_option(self):
        result = self.run_transform([
            f"GRANT USAGE ON *.* TO `admin`@`%` IDENTIFIED VIA mysql_native_password USING '{FAKE_NATIVE}' WITH GRANT OPTION",
        ])
        self.assert_replayed(result, [f"GRANT USAGE ON *.* TO '{NEW_USER}'@'%' WITH GRANT OPTION;"], stripped=1)
        self.assert_no_secret_material(result)

    def test_mysql8_grants_without_auth_clause_are_unchanged(self):
        result = self.run_transform([
            "GRANT USAGE ON *.* TO `admin`@`%`",
            "GRANT SELECT, INSERT, UPDATE, DELETE ON `alltrue`.* TO `admin`@`%`",
        ])
        self.assert_replayed(result, [
            f"GRANT USAGE ON *.* TO '{NEW_USER}'@'%';",
            f"GRANT SELECT, INSERT, UPDATE, DELETE ON `alltrue`.* TO '{NEW_USER}'@'%';",
        ], stripped=0)

    def test_malformed_or_residual_auth_material_still_refuses(self):
        cases = {
            "unterminated hash": f"GRANT USAGE ON *.* TO `admin`@`%` IDENTIFIED BY PASSWORD '{FAKE_HASH}",
            "plaintext password()": "GRANT USAGE ON *.* TO `admin`@`%` IDENTIFIED BY PASSWORD('s3cret')",
            "plain identified by": "GRANT USAGE ON *.* TO `admin`@`%` IDENTIFIED BY 's3cret'",
            "second auth clause": (
                f"GRANT USAGE ON *.* TO `admin`@`%` IDENTIFIED BY PASSWORD '{FAKE_HASH}'"
                f" IDENTIFIED BY PASSWORD '{FAKE_NATIVE}'"
            ),
            "bare hash after strip": f"GRANT USAGE ON *.* TO `admin`@`%` IDENTIFIED VIA ed25519 {FAKE_HASH}",
            "hash outside auth clause": f"GRANT USAGE ON *.* TO `admin`@`%` REQUIRE SUBJECT '{FAKE_HASH[1:]}'",
            "auth clause before grantee": f"GRANT USAGE ON *.* IDENTIFIED BY PASSWORD '{FAKE_HASH}' TO `admin`@`%`",
        }
        for name, grant in cases.items():
            with self.subTest(case=name):
                result = self.run_transform([grant])
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(REFUSAL, result.stderr)
                self.assertEqual(result.stdout.count("GRANT"), 0)
                self.assert_no_secret_material(result)

    def test_other_existing_guards_still_refuse(self):
        cases = {
            "wrong grantee": ("GRANT USAGE ON *.* TO `root`@`%`", "SHOW GRANTS target does not match CURRENT_USER()"),
            "no grantee": ("GRANT USAGE ON *.*", "unexpected SHOW GRANTS target shape"),
            "empty": ("", "SHOW GRANTS returned no statements"),
        }
        for name, (grant, message) in cases.items():
            with self.subTest(case=name):
                result = self.run_transform([grant])
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(message, result.stderr)

    def test_workflow_never_echoes_raw_grants(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        step = workflow[workflow.index("Create principal by replaying"):workflow.index("Backup, cut over .env")]
        self.assertIn('| python3 "$TRANSFORMER" \\', step)
        self.assertIn('| "${OLD_MYSQL[@]}" >/dev/null', step)
        self.assertNotRegex(step, r"\btee\b")


if __name__ == "__main__":
    unittest.main()
