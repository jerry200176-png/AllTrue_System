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


FAKE_MYSQL_PY = r"""
import os, re, sys
sql = sys.argv[-1]
with open(os.environ["FAKE_SQL_LOG"], "a", encoding="utf-8") as log:
    log.write(sql + "\n")
denied = set(filter(None, os.environ.get("FAKE_DENY", "").split(",")))
raw = os.environ.get("FAKE_RAW")
m = re.fullmatch(
    r"SELECT COUNT\(\*\) FROM (mysql\.global_priv|mysql\.user) WHERE User REGEXP '([^']+)' "
    r"AND NOT \(User = '([^']+)' AND Host = '([^']+)'\)"
    r"( AND COALESCE\(JSON_VALUE\(Priv, '\$\.account_locked'\), 'false'\) <> 'true'| AND account_locked <> 'Y')?",
    sql,
)
if not m:
    sys.exit("unexpected preflight SQL shape")
table, pattern, ex_user, ex_host, lock_filter = m.groups()
source = "global_priv" if table == "mysql.global_priv" else ("account_locked" if lock_filter else "user")
if table == "mysql.global_priv" and lock_filter is None or table == "mysql.user" and lock_filter and "JSON" in lock_filter:
    sys.exit("lock filter does not match table")
if source in denied:
    sys.exit("ERROR 1142 (42000): SELECT command denied")
if raw is not None:
    print(raw)
    sys.exit(0)
count = 0
for entry in filter(None, os.environ["FAKE_ACCOUNTS"].split(",")):
    account, _, flag = entry.partition(":")
    user, host = account.rsplit("@", 1)
    if not re.search(pattern, user) or (user == ex_user and host == ex_host):
        continue
    if lock_filter and flag == "locked":
        continue
    count += 1
print(count)
"""

CURRENT = "atr_261020090000_bbbbbb"
PREDECESSOR = "atr_261015083000_aaaaaa"
ORPHAN = "atr_261021100000_cccccc"


class LeftoverStagedPrincipalPreflightTest(unittest.TestCase):
    def run_preflight(self, accounts, old_user="admin", old_host="%", deny="", raw=None):
        with tempfile.TemporaryDirectory() as tmp:
            fake = Path(tmp) / "fake_mysql.py"
            fake.write_text(FAKE_MYSQL_PY, encoding="utf-8")
            script = Path(tmp) / "preflight.sh"
            sql_log = Path(tmp) / "sql.log"
            script.write_text(
                "set -euo pipefail\n"
                f"OLD_MYSQL=({sys.executable} -I {fake} --batch)\n"
                f"OLD_DB_USERNAME='{old_user}'\nOLD_ACCOUNT_HOST='{old_host}'\n"
                + extract_preflight()
                + "echo PREFLIGHT_PASSED\n",
                encoding="utf-8",
            )
            env = dict(os.environ, FAKE_ACCOUNTS=accounts, FAKE_DENY=deny, FAKE_SQL_LOG=str(sql_log))
            if raw is not None:
                env["FAKE_RAW"] = raw
            result = subprocess.run(["bash", str(script)], capture_output=True, text=True, env=env, check=False)
            sql = sql_log.read_text(encoding="utf-8") if sql_log.exists() else ""
        return result, sql

    def assert_passed(self, result, source):
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn(f"count=0 source={source} ", result.stdout)
        self.assertIn("PREFLIGHT_PASSED", result.stdout)

    def assert_refused(self, result):
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("PREFLIGHT_PASSED", result.stdout)

    def assert_no_names(self, result):
        self.assertNotIn("atr_2", result.stdout + result.stderr)
        self.assertNotIn("ERROR 1142", result.stdout + result.stderr)

    def test_zero_leftovers_passes_with_count_only(self):
        result, sql = self.run_preflight("admin@%,root@localhost")
        self.assert_passed(result, "mysql.global_priv")
        self.assertEqual(len(sql.splitlines()), 1)
        for statement in sql.splitlines():
            self.assertTrue(statement.startswith("SELECT COUNT(*) FROM mysql."), statement)

    def test_orphan_principal_refuses_before_any_create(self):
        result, _ = self.run_preflight(f"admin@%,{ORPHAN}@%")
        self.assert_refused(result)
        self.assertIn("count=1 source=mysql.global_priv", result.stdout)
        self.assertIn("unlocked staged principal other than the current one exists without a state file", result.stdout)
        self.assertIn("then lock or drop it manually", result.stdout)
        self.assert_no_names(result)

    def test_current_principal_is_excluded_so_a_later_rotation_can_run(self):
        result, sql = self.run_preflight(f"{CURRENT}@%,root@localhost", old_user=CURRENT)
        self.assert_passed(result, "mysql.global_priv")
        self.assertIn(f"AND NOT (User = '{CURRENT}' AND Host = '%')", sql)
        self.assert_no_names(result)

    def test_locked_predecessor_and_current_principal_pass(self):
        # admin -> atr_1 -> atr_2: Phase 3 locked atr_1; atr_2 is the app user.
        accounts = f"admin@%:locked,{PREDECESSOR}@%:locked,{CURRENT}@%"
        for deny, source in (("", "mysql.global_priv"), ("global_priv", "mysql.user.account_locked")):
            with self.subTest(source=source):
                result, _ = self.run_preflight(accounts, old_user=CURRENT, deny=deny)
                self.assert_passed(result, source)
                self.assert_no_names(result)

    def test_unlocked_non_current_staged_principal_refuses(self):
        cases = {
            "orphan beside locked predecessor": f"{PREDECESSOR}@%:locked,{CURRENT}@%,{ORPHAN}@%",
            "password-quarantined predecessor": f"{PREDECESSOR}@%,{CURRENT}@%",
            "same user, other host": f"{CURRENT}@%,{CURRENT}@localhost",
        }
        for name, accounts in cases.items():
            for deny in ("", "global_priv"):
                with self.subTest(case=name, deny=deny):
                    result, _ = self.run_preflight(accounts, old_user=CURRENT, deny=deny)
                    self.assert_refused(result)
                    self.assertIn("count=1 ", result.stdout)
                    self.assert_no_names(result)

    def test_unreadable_lock_status_counts_locked_predecessors(self):
        accounts = f"{PREDECESSOR}@%:locked,{CURRENT}@%"
        result, _ = self.run_preflight(accounts, old_user=CURRENT, deny="global_priv,account_locked")
        self.assert_refused(result)
        self.assertIn("count=1 source=mysql.user-lock-status-unavailable", result.stdout)
        self.assert_no_names(result)
        result, _ = self.run_preflight(f"{CURRENT}@%", old_user=CURRENT, deny="global_priv,account_locked")
        self.assert_passed(result, "mysql.user-lock-status-unavailable")

    def test_unreadable_mysql_user_fails_closed_without_fallback(self):
        result, sql = self.run_preflight("admin@%", deny="global_priv,account_locked,user")
        self.assert_refused(result)
        self.assertIn("cannot read mysql.user, so it cannot list all accounts", result.stdout)
        self.assertIn("Founder review required", result.stdout)
        self.assertNotIn("information_schema", sql)
        self.assertEqual(len(sql.splitlines()), 3)
        self.assert_no_names(result)

    def test_malformed_count_refuses(self):
        for raw in ("", "1\n2", "abc", "-1"):
            with self.subTest(raw=raw):
                result, _ = self.run_preflight("admin@%", raw=raw)
                self.assert_refused(result)

    def test_pattern_matches_the_staged_name_generator_and_runs_before_it(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        generator = "STAGED_DB_USERNAME=\"atr_$(date -u '+%y%m%d%H%M%S')_$(openssl rand -hex 3)\""
        self.assertIn(generator, workflow)
        self.assertLess(workflow.index("# END leftover-staged-principal preflight"), workflow.index(generator))
        self.assertLess(workflow.index('if [[ -f "$STATE_FILE" ]]; then'), workflow.index("# BEGIN leftover-staged-principal preflight"))
        self.assertLess(workflow.index('OLD_ACCOUNT_HOST="${CURRENT_ACCOUNT#'), workflow.index("# BEGIN leftover-staged-principal preflight"))
        code = "\n".join(line for line in extract_preflight().splitlines() if not line.lstrip().startswith("#"))
        self.assertNotIn("information_schema", code)
        pattern = re.search(r"STAGED_PRINCIPAL_REGEXP='([^']+)'", workflow).group(1)
        self.assertRegex("atr_261015083000_a1b2c3", pattern)
        self.assertNotRegex("admin", pattern)
        self.assertNotRegex("atr_2610150830_a1b2c3", pattern)

    def test_phase3_lock_flag_matches_the_preflight_exclusion(self):
        # The preflight trusts only ACCOUNT LOCK; the quarantine fallback leaves
        # an unlocked account, which must keep counting.
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn('-e "ALTER USER ${OLD_ACCOUNT_SQL} ACCOUNT LOCK"', workflow)
        self.assertIn("LOCK_METHOD=password_quarantine", workflow)
        phase3 = workflow[workflow.index("- name: Lock the old principal"):workflow.index("- name: Remove local key material")]
        self.assertTrue("DROP USER" not in phase3, "Phase 3 must keep (lock), not drop, the predecessor")


if __name__ == "__main__":
    unittest.main()
