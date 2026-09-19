#!/usr/bin/env python3

import contextlib
import hashlib
import importlib.util
import io
import json
import os
import socket
import subprocess
import tempfile
import unittest
from pathlib import Path


MODULE_PATH = Path(__file__).with_name("credential-fingerprint-audit.py")
SPEC = importlib.util.spec_from_file_location("credential_fingerprint_audit", MODULE_PATH)
AUDIT = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(AUDIT)


class CredentialFingerprintAuditTest(unittest.TestCase):
    SETUP_SCRIPT = MODULE_PATH.parent / "infra" / "setup-staging-env.sh"

    def _run_setup(
        self, scenario="success", credential=None, env_extra=None, mode=None, symlink=False,
        marker_value="alltrue-staging-host-v1", marker_mode=0o600, symlink_ancestor=False,
        lock_symlink=False, swap_parent_after_validation=False
    ):
        """Run the staging helper against fake commands and no services."""
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            bindir = root / "bin"
            bindir.mkdir()
            staging = root / "staging"
            apache = root / "apache"
            apache.mkdir()
            credential_file = root / "credential"
            credential_file_parent = root / "credential-parent"
            credential_file_parent.mkdir()
            credential_file_parent.chmod(0o700)
            credential_file = credential_file_parent / "credential"
            marker_parent = root / "marker-parent"
            marker_parent.mkdir()
            marker_parent.chmod(0o700)
            marker = marker_parent / "staging-host"
            marker.write_text(marker_value, encoding="ascii")
            marker.chmod(marker_mode)
            unsafe_target = root / "unsafe-target"
            unsafe_target.mkdir()
            unsafe_link = root / "unsafe-link"
            unsafe_link.symlink_to(unsafe_target, target_is_directory=True)
            lock_victim = root / "lock-victim"
            lock_victim.write_text("victim-must-survive", encoding="ascii")
            if lock_symlink:
                (credential_file_parent / ".staging-db-password.lock").symlink_to(lock_victim)
            race_alt = root / "credential-parent-alt"
            if swap_parent_after_validation:
                race_alt.mkdir()
                race_alt.chmod(0o700)
            sql_log = root / "mysql.sql"
            if credential is not None:
                credential_file.write_text(credential, encoding="ascii")
                if symlink:
                    target = root / "credential-target"
                    credential_file.rename(target)
                    credential_file.symlink_to(target)
                elif mode is not None:
                    credential_file.chmod(mode)
                else:
                    credential_file.chmod(0o600)
            fake = {
                "sudo": """#!/bin/bash
if [[ $1 == mysql ]]; then shift; exec mysql \"$@\"; fi
exec \"$@\"
""",
"mysql": """#!/bin/bash
printf 'ARGS %s\\n' \"$*\" >> \"${MYSQL_LOG}\"
if [[ ! -t 0 ]]; then input=$(/usr/bin/cat); [[ -n $input ]] && printf '%s\\n' \"$input\" >> \"${MYSQL_LOG}\"; fi
if [[ $1 == --version ]]; then
  [[ ${FAKE_SCENARIO} == bad-mariadb ]] && echo 'mysql Distrib 8.0' || echo 'mysql  Ver 15.1 Distrib 10.11.14-MariaDB'
  exit 0
fi
if [[ $* == *'SELECT VERSION()'* ]]; then
  [[ ${FAKE_SCENARIO} == bad-server ]] && echo '10.10.9-MariaDB' || echo '10.11.14-MariaDB'
  exit 0
fi
if [[ $* == *--protocol=TCP* ]]; then
  [[ ${FAKE_SCENARIO} == login-failure || ${FAKE_SCENARIO} == tcp-mismatch ]] && exit 1
  exit 0
fi
if [[ $* == *--protocol=SOCKET* ]]; then
  [[ ${FAKE_SCENARIO} == socket-mismatch ]] && exit 1
  exit 0
fi
if [[ $* == *-N* ]]; then
  [[ ${FAKE_SCENARIO} == inventory-failure ]] && exit 1
  [[ ${FAKE_SCENARIO} == unknown-host ]] && echo 'evil-host'
  [[ ${FAKE_SCENARIO} == three-host ]] && printf 'localhost\\n127.0.0.1\\nlocalhost\\n'
  [[ ${FAKE_SCENARIO} == one-host || ${FAKE_SCENARIO} == localhost-only ]] && echo localhost
  [[ ${FAKE_SCENARIO} == existing || ${FAKE_SCENARIO} == two-host || ${FAKE_SCENARIO} == socket-mismatch || ${FAKE_SCENARIO} == tcp-mismatch ]] && printf 'localhost\\n127.0.0.1\\n'
  [[ ${FAKE_SCENARIO} == tcp-only ]] && echo 127.0.0.1
fi
[[ ${FAKE_SCENARIO} == sql-failure ]] && exit 1
exit 0
""",
                "openssl": "#!/bin/bash\nprintf '%s\\n' 0123456789abcdef0123456789abcdef0123456789abcdef\n",
                "git": "#!/bin/bash\nif [[ $1 == clone ]]; then mkdir -p \"${@: -1}/.git\"; fi\n",
                "php": "#!/bin/bash\nif [[ $* == *PHP_MAJOR_VERSION* ]]; then echo -n 8.2; else echo pdo_mysql; fi\n",
                "python3": "#!/bin/bash\nif [[ ${FAKE_SCENARIO} == swap-parent ]]; then mv -- \"${RACE_PARENT}\" \"${RACE_ALT}\"; ln -s -- \"${RACE_ALT}\" \"${RACE_PARENT}\"; fi\nexec /usr/bin/python3 \"$@\"\n",
                "node": "#!/bin/bash\n[[ ${FAKE_SCENARIO} == bad-node ]] && echo v20.0.0 || echo v22.22.2\n",
                "npm": "#!/bin/bash\necho 12.0.1\n",
                "cat": "#!/bin/bash\nif [[ $1 == /etc/os-release ]]; then\n  [[ ${FAKE_SCENARIO} == bad-os ]] && printf 'ID=ubuntu\\nVERSION_ID=\\\"24.04\\\"\\n' || printf 'ID=debian\\nVERSION_ID=\\\"12\\\"\\n'\nelse\n  /usr/bin/cat \"$@\"\nfi\n",
                "uname": "#!/bin/bash\n[[ ${FAKE_SCENARIO} == bad-arch ]] && echo aarch64 || echo x86_64\n",
                "stat": "#!/bin/bash\nif [[ $* == *\"${STAGING_HOST_MARKER}\"* ]]; then [[ $2 == %u ]] && echo 0 || echo \"${FAKE_MARKER_MODE}\"; exit; fi\nexec /usr/bin/stat \"$@\"\n",
                "composer": "#!/bin/bash\n[[ ${FAKE_SCENARIO} == bad-composer ]] && echo 'Composer version 1.10.0' || echo 'Composer version 2.7.0'\n",
                "php-fpm8.2": "#!/bin/bash\n[[ ${FAKE_SCENARIO} == bad-fpm ]] && echo 'PHP 8.1.0 (fpm-fcgi)' || echo 'PHP 8.2.20 (fpm-fcgi)'\n",
                "apache2ctl": "#!/bin/bash\n[[ ${FAKE_SCENARIO} == bad-apache ]] && echo 'Server version: Apache/2.2.0' || echo 'Server version: Apache/2.4.62'\n",
                "systemctl": "#!/bin/bash\nexit 0\n",
                "flock": "#!/bin/bash\n[[ ${FAKE_SCENARIO} == missing-flock ]] && exit 127\nexec /usr/bin/flock \"$@\"\n",
                "a2enmod": "#!/bin/bash\nexit 0\n",
                "a2enconf": "#!/bin/bash\nexit 0\n",
                "a2dissite": "#!/bin/bash\nexit 0\n",
                "a2ensite": "#!/bin/bash\nexit 0\n",
            }
            for name, body in fake.items():
                path = bindir / name
                path.write_text(body, encoding="utf-8")
                path.chmod(0o755)
            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{bindir}:{env['PATH']}",
                    "FAKE_SCENARIO": scenario,
                    "STAGING_DIR": str(staging),
                    "APACHE_SITES_AVAILABLE_DIR": str(apache),
                    "PHP_FPM_SOCK": str(root / "php-fpm.sock"),
                    "STAGING_DB_PASSWORD_FILE": str(credential_file),
                    "STAGING_HOST_MARKER": str(marker),
                    "MYSQL_LOG": str(sql_log),
                    "FAKE_MARKER_MODE": format(marker_mode, "o"),
                    "RACE_PARENT": str(credential_file_parent),
                    "RACE_ALT": str(race_alt),
                }
            )
            php_socket = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            php_socket.bind(str(root / "php-fpm.sock"))
            if env_extra:
                env.update(env_extra)
            if symlink_ancestor:
                env["STAGING_DIR"] = str(unsafe_link / "checkout")
            result = subprocess.run(
                ["bash", str(self.SETUP_SCRIPT), "https://example.invalid/repo.git"],
                cwd=MODULE_PATH.parent.parent,
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )
            result.credential_snapshot = (
                credential_file.read_text(encoding="ascii")
                if credential_file.exists()
                else None
            )
            result.credential_mode = (
                credential_file.stat().st_mode & 0o777
                if credential_file.exists()
                else None
            )
            result.lock_victim_snapshot = (
                lock_victim.read_text(encoding="ascii")
                if lock_victim.exists()
                else None
            )
            result.sql_snapshot = sql_log.read_text(encoding="ascii") if sql_log.exists() else ""
            php_socket.close()
            # Only snapshots captured while TemporaryDirectory is alive are returned;
            # never return a path whose parent has already been removed.
            return result, None

    def test_staging_success_never_emits_credential(self):
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        result, _ = self._run_setup(credential=sentinel + "\n")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn(sentinel, result.stdout + result.stderr)

    def test_staging_first_run_generates_secure_file_without_emitting_credential(self):
        result, _ = self._run_setup()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertRegex(result.credential_snapshot or "", r"^[0-9a-f]{48}\n$")
        self.assertEqual(result.credential_mode, 0o600)
        self.assertNotIn((result.credential_snapshot or "").strip(), result.stdout + result.stderr)

    def test_staging_rejects_invalid_modes_symlinks_and_multiline_without_emitting_value(self):
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        cases = [("bad", sentinel[:-1] + "g\n"), ("multiline", sentinel + "\nother\n")]
        for name, contents in cases:
            with self.subTest(name=name):
                result, _ = self._run_setup(credential=contents)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn(sentinel, result.stdout + result.stderr)
        result, _ = self._run_setup(credential=sentinel + "\n", mode=0o644)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn(sentinel, result.stdout + result.stderr)

        result, _ = self._run_setup(credential=sentinel + "\n", symlink=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn(sentinel, result.stdout + result.stderr)

    def test_staging_rejects_symlink_and_legacy_env_without_emitting_value(self):
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        result, _ = self._run_setup(env_extra={"STAGING_DB_PASSWORD": sentinel})
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn(sentinel, result.stdout + result.stderr)

    def test_staging_existing_user_missing_file_fails_closed_without_generation(self):
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        result, _ = self._run_setup(scenario="existing")
        self.assertNotEqual(result.returncode, 0)
        self.assertIsNone(result.credential_snapshot)
        self.assertNotIn(sentinel, result.stdout + result.stderr)

    def test_staging_rerun_uses_existing_file_without_regeneration(self):
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        result, _ = self._run_setup(scenario="existing", credential=sentinel + "\n")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.credential_snapshot, sentinel + "\n")
        self.assertNotIn(sentinel, result.stdout + result.stderr)

    def test_staging_login_failure_does_not_regenerate_or_emit_credential(self):
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        result, _ = self._run_setup(
            scenario="login-failure", credential=sentinel + "\n"
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.credential_snapshot, sentinel + "\n")
        self.assertNotIn(sentinel, result.stdout + result.stderr)

    def test_one_host_auth_valid_repairs_only_missing_host_and_grants(self):
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        result, _ = self._run_setup(scenario="one-host", credential=sentinel + "\n")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("CREATE USER 'atr_staging'@'127.0.0.1'", result.sql_snapshot)
        self.assertNotIn("CREATE USER 'atr_staging'@'localhost'", result.sql_snapshot)
        self.assertLess(
            result.sql_snapshot.index("SELECT Host FROM mysql.user"),
            result.sql_snapshot.index("CREATE USER 'atr_staging'@'127.0.0.1'"),
        )
        self.assertLess(
            result.sql_snapshot.index("--protocol=SOCKET"),
            result.sql_snapshot.index("CREATE USER 'atr_staging'@'127.0.0.1'"),
        )
        self.assertNotIn(sentinel, result.stdout + result.stderr)

    def test_single_host_topologies_verify_existing_transport_before_repair(self):
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        for scenario in ("localhost-only", "tcp-only"):
            with self.subTest(scenario=scenario):
                result, _ = self._run_setup(scenario=scenario, credential=sentinel + "\n")
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn("CREATE USER", result.sql_snapshot)
                self.assertNotIn("ALTER USER", result.sql_snapshot)
                self.assertNotIn(sentinel, result.stdout + result.stderr)

    def test_two_hosts_auth_valid_repairs_grants_without_create_or_password_reset(self):
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        result, _ = self._run_setup(scenario="two-host", credential=sentinel + "\n")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("GRANT ALL PRIVILEGES", result.sql_snapshot)
        self.assertNotIn("CREATE USER", result.sql_snapshot)
        self.assertNotIn("ALTER USER", result.sql_snapshot)
        self.assertLess(
            result.sql_snapshot.index("--protocol=SOCKET"),
            result.sql_snapshot.index("GRANT ALL PRIVILEGES"),
        )
        self.assertLess(
            result.sql_snapshot.index("--protocol=TCP"),
            result.sql_snapshot.index("GRANT ALL PRIVILEGES"),
        )
        self.assertNotIn(sentinel, result.stdout + result.stderr)

    def test_host_and_runtime_preflight_rejects_bad_inputs_without_secret(self):
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        for scenario in ("bad-os", "bad-arch", "bad-node", "bad-mariadb", "bad-apache", "bad-composer", "bad-fpm", "missing-flock"):
            with self.subTest(scenario=scenario):
                result, _ = self._run_setup(scenario=scenario, credential=sentinel + "\n")
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn(sentinel, result.stdout + result.stderr)
        result, _ = self._run_setup(credential=sentinel + "\n", marker_value="wrong-marker")
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn(sentinel, result.stdout + result.stderr)

    def test_server_inventory_and_transport_failures_stop_before_mutation(self):
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        for scenario in ("bad-server", "inventory-failure", "unknown-host", "three-host", "socket-mismatch", "tcp-mismatch"):
            with self.subTest(scenario=scenario):
                result, _ = self._run_setup(scenario=scenario, credential=sentinel + "\n")
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn("CREATE DATABASE", result.sql_snapshot)
                self.assertNotIn("GRANT ALL", result.sql_snapshot)
                self.assertNotIn(sentinel, result.stdout + result.stderr)

    def test_path_guards_reject_control_characters_and_checkout_paths(self):
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        for variable, value in (
            ("STAGING_DIR", "/tmp/staging\nunsafe"),
            ("STAGING_DIR", "/home/staging/../admin/AllTrue_System"),
            ("STAGING_DB_PASSWORD_FILE", "/tmp/credential\tunsafe"),
            ("STAGING_DB_PASSWORD_FILE", str(self.SETUP_SCRIPT.parents[2] / "checkout-secret")),
            ("STAGING_DB_PASSWORD_FILE", "/home/staging/../staging/AllTrue_System/credential"),
            ("APACHE_SITES_AVAILABLE_DIR", "/home/admin/production-sites"),
        ):
            with self.subTest(variable=variable):
                result, _ = self._run_setup(
                    credential=sentinel + "\n", env_extra={variable: value}
                )
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn("CREATE DATABASE", result.sql_snapshot)
                self.assertNotIn(sentinel, result.stdout + result.stderr)

        result, _ = self._run_setup(
            credential=sentinel + "\n",
            env_extra={
                "STAGING_DIR": "/home/staging/AllTrue_System",
                "STAGING_DB_PASSWORD_FILE": "/home/staging/../staging/AllTrue_System/credential",
            },
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("CREATE DATABASE", result.sql_snapshot)

        result, _ = self._run_setup(
            credential=sentinel + "\n", symlink_ancestor=True
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.sql_snapshot, "")
        result, _ = self._run_setup(credential=sentinel + "\n", marker_mode=0o644)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn(sentinel, result.stdout + result.stderr)

    def test_lock_symlink_fails_closed_without_truncating_victim(self):
        result, _ = self._run_setup(lock_symlink=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("CREATE DATABASE", result.sql_snapshot)
        self.assertEqual(result.credential_snapshot, None)
        self.assertEqual(
            "victim-must-survive", result.lock_victim_snapshot,
        )

    def test_post_validation_parent_swap_fails_closed_without_alternate_credential(self):
        result, _ = self._run_setup(
            scenario="swap-parent", swap_parent_after_validation=True
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("CREATE DATABASE", result.sql_snapshot)
        self.assertNotIn("CREATE USER", result.sql_snapshot)
        self.assertNotIn("GRANT ALL", result.sql_snapshot)
        self.assertIsNone(result.credential_snapshot)

    def test_parent_is_pinned_componentwise_before_credential_operations(self):
        source = self.SETUP_SCRIPT.read_text(encoding="utf-8")
        self.assertIn("os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)", source)
        helper = (self.SETUP_SCRIPT.parent / "credential-parent-helper.py").read_text(encoding="utf-8")
        self.assertIn("os.open(basename, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW", helper)
        self.assertNotIn("exec 9>\"$(dirname", source)

    def test_helper_validates_bound_basename_and_rejects_absolute_alternate(self):
        helper = self.SETUP_SCRIPT.parent / "credential-parent-helper.py"
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory) / "parent"; parent.mkdir(); parent.chmod(0o700)
            lock = parent / ".staging-db-password.lock"; lock.touch(mode=0o600)
            parent_fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY)
            lock_fd = os.open(lock, os.O_RDWR)
            import fcntl; fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            args = ["python3", str(helper), str(parent_fd), "credential", "validate", str(parent / "credential"), str(lock_fd)]
            ok = subprocess.run(args, pass_fds=(parent_fd, lock_fd), capture_output=True, text=True)
            self.assertEqual(ok.returncode, 0, ok.stderr)
            for bad in ("/tmp/escape", "other"):
                bad_args = args[:]
                bad_args[3] = bad
                bad_args[4] = "validate"
                bad_args[5] = str(parent / "credential")
                bad_result = subprocess.run(bad_args, pass_fds=(parent_fd, lock_fd), capture_output=True, text=True)
                self.assertNotEqual(bad_result.returncode, 0)
                self.assertIn("credential basename does not match configured path", bad_result.stderr)
            os.close(lock_fd); os.close(parent_fd)

    def test_helper_rejects_unlocked_inherited_fd_when_third_fd_holds_lock(self):
        helper = self.SETUP_SCRIPT.parent / "credential-parent-helper.py"
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory) / "parent"; parent.mkdir(); parent.chmod(0o700)
            lock = parent / ".staging-db-password.lock"; lock.touch(mode=0o600)
            parent_fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY)
            holder = os.open(lock, os.O_RDWR); candidate = os.open(lock, os.O_RDWR)
            import fcntl; fcntl.flock(holder, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = subprocess.run(["python3", str(helper), str(parent_fd), "credential", "validate", str(parent / "credential"), str(candidate)], pass_fds=(parent_fd, candidate), capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            for fd in (candidate, holder, parent_fd): os.close(fd)

    def test_root_mysql_sanitizes_child_error_and_secret_from_observed_channels(self):
        helper = self.SETUP_SCRIPT.parent / "credential-parent-helper.py"
        sentinel = "abcdef0123456789abcdef0123456789abcdef0123456789"
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); parent = root / "parent"; parent.mkdir(); parent.chmod(0o700)
            (parent / "credential").write_text(sentinel + "\n", encoding="ascii" ); (parent / "credential").chmod(0o600)
            bindir = root / "bin"; bindir.mkdir(); log = root / "log"
            (bindir / "sudo").write_text("#!/bin/sh\nexec mysql \"$@\"\n", encoding="ascii")
            (bindir / "mysql").write_text(f"#!/bin/sh\nprintf '%s\\n' \"$*\" > '{log}'\nenv > '{log}.env'\ncase \"$*\" in *--defaults-extra-file=/proc/self/fd/*) f=${{*##*--defaults-extra-file=/proc/self/fd/}}; f=${{f%% *}}; /usr/bin/cat <\"/proc/self/fd/$f\" >&2;; *) /usr/bin/cat >&2;; esac\nexit 1\n", encoding="ascii")
            for item in (bindir / "sudo", bindir / "mysql"): item.chmod(0o755)
            pfd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY); lfd = os.open(parent / ".staging-db-password.lock", os.O_RDWR | os.O_CREAT, 0o600); import fcntl; fcntl.flock(lfd, fcntl.LOCK_EX)
            env = {"PATH": f"{bindir}:{os.environ['PATH']}"}
            result = subprocess.run(["python3", str(helper), str(pfd), "credential", "root-mysql", "sudo", "AllTrue_staging", "atr_staging", "localhost", "first"], pass_fds=(pfd, lfd), env=env, capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0); self.assertNotIn(sentinel, result.stdout + result.stderr)
            self.assertNotIn(sentinel, log.read_text(encoding="ascii") + (log.with_name("log.env")).read_text(encoding="ascii"))
            log.unlink(); log.with_name("log.env").unlink()
            state_extra = subprocess.run(["python3", str(helper), str(pfd), "credential", "state", "EXTRA"], pass_fds=(pfd,), capture_output=True, text=True)
            self.assertNotEqual(state_extra.returncode, 0)
            mysql_extra = subprocess.run(["python3", str(helper), str(pfd), "credential", "mysql", "--execute=UNDOCUMENTED"], pass_fds=(pfd,), env=env, capture_output=True, text=True)
            self.assertNotEqual(mysql_extra.returncode, 0)
            self.assertIn("invalid staging mysql request", mysql_extra.stderr)
            self.assertFalse(log.exists())
            valid_mysql = subprocess.run(["python3", str(helper), str(pfd), "credential", "mysql", "--protocol=TCP", "-h", "127.0.0.1", "-u", "atr_staging", "-e", "SELECT 1 AS ok"], pass_fds=(pfd,), env=env, capture_output=True, text=True)
            self.assertNotEqual(valid_mysql.returncode, 0)
            self.assertIn("staging mysql failed", valid_mysql.stderr)
            self.assertNotIn(sentinel, valid_mysql.stdout + valid_mysql.stderr)
            self.assertNotIn(sentinel, log.read_text(encoding="ascii") + log.with_name("log.env").read_text(encoding="ascii"))
            os.close(lfd); os.close(pfd)

    def test_extracts_supported_values_without_returning_raw_values(self):
        telegram = "123456789:AAabcdefghijklmnopqrstuvwxyzABCDEFGH"
        app_key = "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
        bearer = "sample-bearer-token-abcdefghijklmnopqrstuvwxyz"
        with tempfile.TemporaryDirectory() as directory:
            sample = Path(directory) / "transcript.jsonl"
            sample.write_text(
                f'APP_KEY={app_key}\nAuthorization: Bearer {bearer}\nbot={telegram}\n',
                encoding="utf-8",
            )
            findings = AUDIT.extract([Path(directory)])

        self.assertEqual({kind for kind, _ in findings}, {"APP_KEY", "BEARER", "TELEGRAM"})
        serialized = repr(findings)
        self.assertNotIn(app_key, serialized)
        self.assertNotIn(bearer, serialized)
        self.assertNotIn(telegram, serialized)

    def test_compare_reports_match_without_printing_fingerprints(self):
        digest = hashlib.sha256(b"secret-value").hexdigest()
        with tempfile.TemporaryDirectory() as directory:
            leaked = Path(directory) / "leaked.tsv"
            production = Path(directory) / "production.tsv"
            leaked.write_text(
                f"APP_KEY\t{digest}\nBEARER\t{digest}\nTELEGRAM\t{digest}\n",
                encoding="utf-8",
            )
            production.write_text(leaked.read_text(encoding="utf-8"), encoding="utf-8")
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                result = AUDIT.compare(leaked, production)

        self.assertEqual(result, 2)
        self.assertIn("MATCH_ROTATION_REQUIRED", output.getvalue())
        self.assertNotIn(digest, output.getvalue())

    def test_compare_can_scope_db_audit_without_unrelated_kinds(self):
        leaked_digest = hashlib.sha256(b"historical-ci-password").hexdigest()
        production_digest = hashlib.sha256(b"current-production-password").hexdigest()
        with tempfile.TemporaryDirectory() as directory:
            leaked = Path(directory) / "leaked.tsv"
            production = Path(directory) / "production.tsv"
            leaked.write_text(f"DB_PASSWORD\t{leaked_digest}\n", encoding="utf-8")
            production.write_text(
                f"DB_PASSWORD\t{production_digest}\n", encoding="utf-8"
            )
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                result = AUDIT.compare(leaked, production, ("DB_PASSWORD",))

        self.assertEqual(result, 0)
        self.assertIn("DB_PASSWORD\tDIFFERENT\tleaked=1\tproduction=1", output.getvalue())
        self.assertNotIn(leaked_digest, output.getvalue())
        self.assertNotIn(production_digest, output.getvalue())

    def test_extracts_db_password_shapes_without_returning_raw_values(self):
        yaml_pw = "sw0rdfish-ci-only-1234"
        xml_pw = "another-fake-pw-5678"
        env_pw = "third-fake-pw-9012"
        with tempfile.TemporaryDirectory() as directory:
            (Path(directory) / "ci.yml").write_text(
                f"      MYSQL_PASSWORD: {yaml_pw}\n", encoding="utf-8"
            )
            (Path(directory) / "phpunit.xml").write_text(
                f'<env name="DB_PASSWORD" value="{xml_pw}" force="true"/>\n',
                encoding="utf-8",
            )
            (Path(directory) / ".env").write_text(
                f"DB_PASSWORD={env_pw}\n", encoding="utf-8"
            )
            findings = AUDIT.extract([Path(directory)])

        kinds = {kind for kind, _ in findings}
        self.assertIn("DB_PASSWORD", kinds)
        self.assertEqual(
            {digest for kind, digest in findings if kind == "DB_PASSWORD"},
            {
                hashlib.sha256(yaml_pw.encode()).hexdigest(),
                hashlib.sha256(xml_pw.encode()).hexdigest(),
                hashlib.sha256(env_pw.encode()).hexdigest(),
            },
        )
        serialized = repr(findings)
        self.assertNotIn(yaml_pw, serialized)
        self.assertNotIn(xml_pw, serialized)
        self.assertNotIn(env_pw, serialized)

    def test_selects_only_gitguardian_linked_blobs(self):
        linked = ".cursor/projects/example/transcript.jsonl"
        anchor = hashlib.sha256(linked.encode("utf-8")).hexdigest()
        with tempfile.TemporaryDirectory() as directory:
            metadata = Path(directory) / "commit.json"
            metadata.write_text(
                json.dumps(
                    {
                        "files": [
                            {"filename": linked, "sha": "a" * 40},
                            {"filename": "unrelated.txt", "sha": "b" * 40},
                        ]
                    }
                ),
                encoding="utf-8",
            )
            selected = AUDIT.select_blobs([metadata], {anchor}, set())

        self.assertEqual(selected, {"a" * 40})


if __name__ == "__main__":
    unittest.main()
