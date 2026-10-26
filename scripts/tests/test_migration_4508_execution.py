"""Execute the published migration shell block in a private synthetic Git/CLI sandbox."""
from pathlib import Path
import os
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).parents[2]
FIXED = "2026_10_26_000001_add_expires_at_to_personal_access_tokens"


class Migration4508ExecutionTest(unittest.TestCase):
    def run_block(self, status, added="2026_10_25_000001_synthetic_scope", check=0, migrate=0, interrupt=False):
        with tempfile.TemporaryDirectory(prefix="alltrue-migration-boundary-") as directory:
            root = Path(directory)
            for name in ["backend/database/migrations", "scripts/production-deploy", "bin"]:
                (root / name).mkdir(parents=True)
            (root / "backend/.env").write_text("DB_USERNAME=fixture\nDB_PASSWORD=fixture\n")
            (root / "scripts/verify-db-backup.sh").write_bytes((ROOT / "scripts/verify-db-backup.sh").read_bytes())
            def git(*args):
                return subprocess.check_output(["git", "-c", "user.name=fixture", "-c", "user.email=fixture@invalid", *args], cwd=root, text=True).strip()
            git("init", "-q"); git("add", "."); git("commit", "-qm", "fixture base")
            before = git("rev-parse", "HEAD")
            (root / "backend/database/migrations" / (added + ".php")).write_text("<?php // synthetic fixture\n")
            git("add", "."); git("commit", "-qm", "fixture target")
            target = git("rev-parse", "HEAD")
            php = root / "bin/php"
            php.write_text("""#!/usr/bin/env bash
case "$*" in
  'artisan migrate:status') printf '%s\\n' "$STATUS" ;;
  *migrate-4508-bounded.php*--check*) echo check >> "$TRACE"; exit "$CHECK" ;;
  *migrate-4508-bounded.php*) echo bounded >> "$TRACE"; [ "$INTERRUPT" != 1 ] || exec sleep 2; exit "$MIGRATE" ;;
  'artisan migrate --force') echo migrate >> "$TRACE"; exit "$MIGRATE" ;;
  *) exit 99 ;;
esac
""")
            php.chmod(0o700)
            script = (ROOT / "scripts/production-deploy/pi-remote-deploy.sh").read_text()
            block = 'echo "=== [3/7] Migration' + script.split('echo "=== [3/7] Migration', 1)[1].split('echo "=== [3b/7]', 1)[0]
            block = block.replace("/home/admin", str(root))
            if interrupt:
                block = block.replace("--kill-after=10s 300s", "--kill-after=1s 0.1s")
            prefix = """set -uo pipefail
MIGRATION_RAN=0 MIG_HARD=
abort_deploy() { echo "abort:$MIGRATION_RAN" >> "$TRACE"; if [ "$MIGRATION_RAN" != 0 ]; then exit 13; fi; echo rollback >> "$TRACE"; exit 1; }
mysqldump() { echo backup >> "$TRACE"; printf 'CREATE TABLE fixture(id int);\\n-- Dump completed on 2026-10-26 00:00:00\\n'; }
"""
            env = {"PATH": str(root / "bin") + os.pathsep + os.environ["PATH"],
                   "TRACE": str(root / "trace"), "STATUS": status, "CHECK": str(check),
                   "MIGRATE": str(migrate), "INTERRUPT": str(int(interrupt)),
                   "PDV": str(ROOT / "scripts/post-deploy-verify.py"), "PREV_COMMIT": before, "TARGET_SHA": target}
            result = subprocess.run(["bash", "-c", prefix + block + '\necho continued >> "$TRACE"\n'],
                                    cwd=root, env=env, text=True, capture_output=True, timeout=15)
            trace = (root / "trace").read_text().splitlines() if (root / "trace").exists() else []
            return result, trace

    def test_outside_pending_rejected_before_backup_or_migration(self):
        run, trace = self.run_block("2026_10_20_000001_outside .... Pending")
        self.assertNotEqual(run.returncode, 0)
        self.assertNotIn("backup", trace)
        self.assertNotIn("migrate", trace)

    def test_unparseable_pending_and_empty_successful_status_hold(self):
        for status in ["unparseable Pending", "", "header only"]:
            run, trace = self.run_block(status)
            self.assertNotEqual(run.returncode, 0)
            self.assertNotIn("backup", trace)
            self.assertNotIn("migrate", trace)

    def test_in_range_legacy_and_no_pending_paths_still_work(self):
        run, trace = self.run_block("2026_10_25_000001_synthetic_scope .... Pending")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("migrate", trace)
        run, trace = self.run_block("| Yes | 2026_10_25_000001_synthetic_scope | 1 |")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(trace, ["continued"])

    def test_already_applied_4508_does_not_intercept_another_reviewed_migration(self):
        status = FIXED + " .... [1] Ran\n2026_10_25_000001_synthetic_scope .... Pending"
        run, trace = self.run_block(status)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertIn("migrate", trace)
        self.assertNotIn("bounded", trace)

    def test_fixed_preflight_failure_stops_before_backup(self):
        run, trace = self.run_block(FIXED + " .... Pending", added=FIXED, check=78)
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("check", trace)
        self.assertNotIn("backup", trace)
        self.assertNotIn("bounded", trace)

    def test_fixed_success_uses_only_bounded_entry(self):
        run, trace = self.run_block(FIXED + " .... Pending", added=FIXED)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(trace, ["check", "backup", "bounded", "continued"])

    def test_error_or_real_process_timeout_holds_without_rollback_or_continuation(self):
        for code, interrupt in [(75, False), (1, False), (0, True)]:
            run, trace = self.run_block(FIXED + " .... Pending", added=FIXED, migrate=code, interrupt=interrupt)
            self.assertEqual(run.returncode, 13, run.stderr)
            self.assertIn("bounded", trace)
            self.assertNotIn("rollback", trace)
            self.assertNotIn("continued", trace)


if __name__ == "__main__":
    unittest.main()
