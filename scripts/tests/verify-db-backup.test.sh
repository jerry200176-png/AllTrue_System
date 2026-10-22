#!/usr/bin/env bash
# Contract test for scripts/verify-db-backup.sh and its wiring in deploy.yml.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
v="$root/scripts/verify-db-backup.sh"
d="$(mktemp -d)"; trap 'rm -rf "$d"' EXIT

printf 'CREATE TABLE `a` (id int);\nINSERT INTO a VALUES (1);\n-- Dump completed on 2026-10-22 04:30:00\n\n  \n' | gzip > "$d/good.sql.gz"
verified_output=$(bash "$v" "$d/good.sql.gz") || { echo "FAIL good archive rejected"; exit 1; }
[[ "$verified_output" == *tables=1* && "$verified_output" != *'CREATE TABLE'* && "$verified_output" != *'INSERT INTO'* ]] \
  || { echo "FAIL verification must print metadata, never SQL"; exit 1; }

: > "$d/empty.sql.gz"
! bash "$v" "$d/empty.sql.gz" 2>/dev/null || { echo "FAIL empty accepted"; exit 1; }

printf 'INSERT INTO a VALUES (1);\n-- Dump completed on 2026-10-22 04:30:00\n' | gzip > "$d/notables.sql.gz"
! bash "$v" "$d/notables.sql.gz" 2>/dev/null || { echo "FAIL no-CREATE-TABLE accepted"; exit 1; }

printf 'CREATE TABLE a (id int);\n' | gzip > "$d/trunc.sql.gz"
head -c 10 "$d/trunc.sql.gz" > "$d/trunc2.sql.gz"
! bash "$v" "$d/trunc2.sql.gz" 2>/dev/null || { echo "FAIL truncated gzip accepted"; exit 1; }

! bash "$v" "$d/missing.sql.gz" 2>/dev/null || { echo "FAIL missing accepted"; exit 1; }

printf 'CREATE TABLE `a` (id int);\nINSERT INTO a VALUES (1);\n' | gzip > "$d/partial.sql.gz"
! bash "$v" "$d/partial.sql.gz" >/dev/null 2>&1 || { echo "FAIL clean gzip containing an incomplete dump prefix accepted"; exit 1; }

printf 'CREATE TABLE `a` (id int);\n-- Dump completed on 2026-10-22 04:30:00\nINSERT INTO a VALUES (2);\n' | gzip > "$d/after-footer.sql.gz"
! bash "$v" "$d/after-footer.sql.gz" >/dev/null 2>&1 || { echo "FAIL SQL after completion footer accepted"; exit 1; }

printf 'CREATE TABLE `a` (id int);\n-- Dump completed on 2026-10-22 04:30:00 junk\n' | gzip > "$d/footer-junk.sql.gz"
! bash "$v" "$d/footer-junk.sql.gz" >/dev/null 2>&1 || { echo "FAIL malformed completion footer accepted"; exit 1; }

printf 'CREATE TABLE `a` (id int);\n-- Dump completed on\n' | gzip > "$d/footer-no-date.sql.gz"
! bash "$v" "$d/footer-no-date.sql.gz" >/dev/null 2>&1 || { echo "FAIL undated completion footer accepted"; exit 1; }

# gzip integrity may pass while the subsequent decompression read fails.
# The failing reader emits a complete-looking stream before returning nonzero.
real_gunzip="$(command -v gunzip)"
mkdir "$d/bin"
cat > "$d/bin/gunzip" <<'STUB'
#!/usr/bin/env bash
if [ "${1:-}" = -c ]; then
  printf 'CREATE TABLE `a` (id int);\n-- Dump completed on 2026-10-22 04:30:00\n'
  exit 7
fi
exec "$REAL_GUNZIP" "$@"
STUB
chmod +x "$d/bin/gunzip"
! REAL_GUNZIP="$real_gunzip" PATH="$d/bin:$PATH" bash "$v" "$d/good.sql.gz" >/dev/null 2>&1 \
  || { echo "FAIL second decompression error swallowed"; exit 1; }

# Wiring: deploy.yml must verify the pre-migration backup and abort on failure.
grep -q 'verify-db-backup.sh "\$BACKUP_FILE" || abort_deploy' "$root/.github/workflows/deploy.yml" \
  || { echo "FAIL deploy.yml does not fail closed on backup verification"; exit 1; }

python3 - "$root" <<'PY'
import re
import sys
from pathlib import Path

root = Path(sys.argv[1])
deploy = (root / ".github/workflows/deploy.yml").read_text()
block = deploy.split('BACKUP_FILE="/home/admin/backups/emergency/db_pre_migration_', 1)[1].split('echo "=== [3b/7]', 1)[0]
producer = re.search(r"if ! mysqldump\b(.*?)\| gzip > \"\$BACKUP_FILE\"", block, re.DOTALL)
assert producer, "missing full-DB pre-migration dump producer"
assert all(flag in producer.group(1).split() for flag in ("--single-transaction", "--comments", "--dump-date")), "dump completion footer flags must be explicit"
assert block.index('bash /home/admin/scripts/verify-db-backup.sh "$BACKUP_FILE" || abort_deploy') < block.index("php artisan migrate --force"), "backup verification must finish before migration"
ci = (root / ".github/workflows/ci.yml").read_text()
required_step = ci.split("      - name: Run control plane lint\n", 1)[1].split("\n      - name:", 1)[0]
assert "          bash scripts/tests/verify-db-backup.test.sh\n" in required_step, "required Control Plane Contract Lint must execute these regressions"
PY

# Run the affected producer/consumer path in an isolated migration sandbox too.
# These retain the pre-existing migration assertions, using the real verifier
# with a synthetic complete dump (or the explicit partial-dump failure case).
(
  cd "$root"
  python3 -m unittest \
    scripts.tests.test_deploy_activation_state.DeployActivationWorkflowContractTest.test_successful_migration_marks_ran_and_continues \
    scripts.tests.test_deploy_activation_state.DeployActivationWorkflowContractTest.test_failed_migration_aborts_before_any_later_deploy_step \
    scripts.tests.test_deploy_activation_state.DeployActivationWorkflowContractTest.test_backup_completion_failure_aborts_before_migration \
    scripts.tests.test_deploy_activation_state.DeployActivationWorkflowContractTest.test_failed_migration_status_aborts_instead_of_skipping \
    scripts.tests.test_deploy_activation_state.DeployActivationWorkflowContractTest.test_no_pending_migration_skips_and_continues \
    scripts.tests.test_deploy_activation_state.DeployActivationWorkflowContractTest.test_migration_step_has_no_failure_swallowing_construct \
    scripts.tests.test_deploy_activation_state.DeployActivationWorkflowContractTest.test_expression_bearing_run_scripts_stay_under_the_github_limit
)
echo "verify-db-backup tests passed"
