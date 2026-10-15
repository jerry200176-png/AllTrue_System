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
                self.assertEqual(result.stdout, "")
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
                self.assertEqual(result.stdout, "")

    def test_late_refusal_emits_zero_bytes_even_after_valid_lines(self):
        # Run 38724893175 refused on a GRANT line after CREATE USER was already
        # printed; validation must now finish before anything reaches mysql.
        result = self.run_transform([
            "GRANT ALL PRIVILEGES ON `alltrue`.* TO `admin`@`%`",
            "GRANT SELECT ON `alltrue`.* TO `admin`@`%`",
            "GRANT USAGE ON *.* TO `admin`@`%` IDENTIFIED BY PASSWORD('s3cret')",
        ])
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(REFUSAL, result.stderr)
        self.assertEqual(result.stdout, "")
        self.assertNotIn("CREATE USER", result.stdout + result.stderr)

    def test_workflow_never_echoes_raw_grants(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        step = workflow[workflow.index("Create principal by replaying"):workflow.index("Backup, cut over .env")]
        self.assertIn('| python3 "$TRANSFORMER" \\', step)
        self.assertIn('| "${OLD_MYSQL[@]}" >/dev/null', step)
        self.assertNotRegex(step, r"\btee\b")


def extract_preflight():
    workflow = WORKFLOW.read_text(encoding="utf-8")
    match = re.search(
        r"\n([ ]*# BEGIN leftover-staged-principal preflight.*?# END leftover-staged-principal preflight)\n",
        workflow,
        re.S,
    )
    if not match:
        raise AssertionError("leftover staged-principal preflight not found in deploy.yml")
    return textwrap.dedent(match.group(1)) + "\n"


FAKE_MYSQL = r"""
fake_mysql() {
  local sql="${@: -1}"
  printf '%s\n' "$sql" >> "$FAKE_SQL_LOG"
  case "$sql" in
    *"FROM mysql.user"*)
      [[ "$FAKE_USER_TABLE" == deny ]] && { echo "ERROR 1142 SELECT command denied" >&2; return 1; }
      printf '%s\n' "$FAKE_USER_TABLE" ;;
    *"information_schema.USER_PRIVILEGES"*)
      [[ "$FAKE_IS" == deny ]] && { echo "ERROR 1044 denied" >&2; return 1; }
      printf '%s\n' "$FAKE_IS" ;;
    *) echo "unexpected statement" >&2; return 1 ;;
  esac
}
OLD_MYSQL=(fake_mysql)
"""


class LeftoverStagedPrincipalPreflightTest(unittest.TestCase):
    def run_preflight(self, user_table, info_schema="0"):
        with tempfile.TemporaryDirectory() as tmp:
            script = Path(tmp) / "preflight.sh"
            sql_log = Path(tmp) / "sql.log"
            script.write_text(
                "set -euo pipefail\n" + FAKE_MYSQL + extract_preflight() + "echo PREFLIGHT_PASSED\n",
                encoding="utf-8",
            )
            result = subprocess.run(
                ["bash", str(script)],
                capture_output=True,
                text=True,
                env=dict(os.environ, FAKE_USER_TABLE=user_table, FAKE_IS=info_schema, FAKE_SQL_LOG=str(sql_log)),
                check=False,
            )
            sql = sql_log.read_text(encoding="utf-8") if sql_log.exists() else ""
        return result, sql

    def test_zero_leftovers_passes_with_count_only(self):
        result, sql = self.run_preflight("0")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("count=0 source=mysql.user", result.stdout)
        self.assertIn("PREFLIGHT_PASSED", result.stdout)
        self.assertIn("SELECT COUNT(*) FROM mysql.user WHERE User REGEXP '^atr_[0-9]{12}_[0-9a-f]{6}$'", sql)
        for statement in sql.splitlines():
            self.assertTrue(statement.startswith("SELECT COUNT("), statement)

    def test_leftover_principal_refuses_before_any_create(self):
        result, sql = self.run_preflight("1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("count=1 source=mysql.user", result.stdout)
        self.assertIn("leftover staged principal exists without a state file", result.stdout)
        self.assertIn("Founder review required", result.stdout)
        self.assertNotIn("PREFLIGHT_PASSED", result.stdout)
        self.assertNotIn("atr_", result.stdout.replace("count=1", ""))

    def test_falls_back_to_information_schema_when_mysql_user_is_unreadable(self):
        result, sql = self.run_preflight("deny", "0")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("count=0 source=information_schema.USER_PRIVILEGES", result.stdout)
        self.assertIn("covers only grantees visible to it", result.stdout)
        self.assertIn("GRANTEE REGEXP '^''atr_[0-9]{12}_[0-9a-f]{6}''@'", sql)
        self.assertNotIn("ERROR", result.stdout + result.stderr)

    def test_fallback_leftover_refuses(self):
        result, _ = self.run_preflight("deny", "2")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("count=2 source=information_schema.USER_PRIVILEGES", result.stdout)

    def test_uncountable_or_malformed_count_refuses(self):
        for user_table, info_schema in (("deny", "deny"), ("", "0"), ("1\n2", "0"), ("abc", "0")):
            with self.subTest(user_table=user_table, info_schema=info_schema):
                result, _ = self.run_preflight(user_table, info_schema)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn("PREFLIGHT_PASSED", result.stdout)

    def test_pattern_matches_the_staged_name_generator_and_runs_before_it(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        generator = "STAGED_DB_USERNAME=\"atr_$(date -u '+%y%m%d%H%M%S')_$(openssl rand -hex 3)\""
        self.assertIn(generator, workflow)
        self.assertLess(workflow.index("# END leftover-staged-principal preflight"), workflow.index(generator))
        self.assertLess(workflow.index('if [[ -f "$STATE_FILE" ]]; then'), workflow.index("# BEGIN leftover-staged-principal preflight"))
        pattern = re.search(r"STAGED_PRINCIPAL_REGEXP='([^']+)'", workflow).group(1)
        self.assertRegex("atr_261015083000_a1b2c3", pattern)
        self.assertNotRegex("admin", pattern)
        self.assertNotRegex("atr_2610150830_a1b2c3", pattern)


if __name__ == "__main__":
    unittest.main()
