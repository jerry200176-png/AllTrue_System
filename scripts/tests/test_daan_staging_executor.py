#!/usr/bin/env python3
"""Exercise the Daan staging Docker executor contract without Docker or sudo."""

from __future__ import annotations

import os
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
COMMON = ROOT / "infra" / "daan-staging" / "lifecycle" / "_common.sh"


class DaanStagingExecutorTest(unittest.TestCase):
    def _run_helper(
        self, stub_body: str, timeout: str = "2"
    ) -> tuple[subprocess.CompletedProcess[str], str]:
        with tempfile.TemporaryDirectory() as tmp:
            stub = Path(tmp) / "docker-stub"
            args_file = Path(tmp) / "args"
            stub.write_text(
                "#!/usr/bin/env bash\nset -euo pipefail\n"
                'printf "%s\\n" "$@" > "${DOCKER_ARGS_FILE}"\n'
                + stub_body
                + "\n",
                encoding="utf-8",
            )
            stub.chmod(0o700)
            script = textwrap.dedent(
                f"""
                set -euo pipefail
                source {COMMON!s}
                compose_exec_bounded app php -r 'echo "probe\\n";'
                """
            )
            env = os.environ | {
                "DOCKER_BIN": str(stub),
                "COMPOSE_EXEC_TIMEOUT_SECONDS": timeout,
                "DOCKER_ARGS_FILE": str(args_file),
            }
            result = subprocess.run(
                ["bash", "-c", script],
                check=False,
                capture_output=True,
                text=True,
                env=env,
            )
            return result, args_file.read_text(encoding="utf-8") if args_file.exists() else ""

    def test_probe_disables_stdin_and_tty_and_returns(self) -> None:
        result, args = self._run_helper('printf "PDO_MYSQL_OK\\n"')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("PDO_MYSQL_OK", result.stdout)
        self.assertIn("-T\n", args)
        self.assertIn("--interactive=false\n", args)

    def test_probe_is_bounded_when_executor_stalls(self) -> None:
        result, _ = self._run_helper("sleep 10", timeout="1")
        self.assertEqual(result.returncode, 124, result)

    def test_scripts_use_noninteractive_executor_contract(self) -> None:
        smoke = (ROOT / "infra/daan-staging/lifecycle/smoke.sh").read_text(encoding="utf-8")
        migrate = (ROOT / "infra/daan-staging/lifecycle/migrate.sh").read_text(encoding="utf-8")
        self.assertIn("compose_exec_bounded", smoke)
        self.assertIn("PDO_MYSQL_OK", smoke)
        self.assertNotIn("php -m | grep", smoke)
        self.assertIn("compose_exec_noninteractive", migrate)


if __name__ == "__main__":
    unittest.main()
