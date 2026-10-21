#!/usr/bin/env bash
# Contract test for scripts/verify-db-backup.sh and its wiring in deploy.yml.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
v="$root/scripts/verify-db-backup.sh"
d="$(mktemp -d)"; trap 'rm -rf "$d"' EXIT

printf 'CREATE TABLE `a` (id int);\nINSERT INTO a VALUES (1);\n' | gzip > "$d/good.sql.gz"
bash "$v" "$d/good.sql.gz" >/dev/null || { echo "FAIL good archive rejected"; exit 1; }

: > "$d/empty.sql.gz"
! bash "$v" "$d/empty.sql.gz" 2>/dev/null || { echo "FAIL empty accepted"; exit 1; }

printf 'INSERT INTO a VALUES (1);\n' | gzip > "$d/notables.sql.gz"
! bash "$v" "$d/notables.sql.gz" 2>/dev/null || { echo "FAIL no-CREATE-TABLE accepted"; exit 1; }

printf 'CREATE TABLE a (id int);\n' | gzip > "$d/trunc.sql.gz"
head -c 10 "$d/trunc.sql.gz" > "$d/trunc2.sql.gz"
! bash "$v" "$d/trunc2.sql.gz" 2>/dev/null || { echo "FAIL truncated gzip accepted"; exit 1; }

! bash "$v" "$d/missing.sql.gz" 2>/dev/null || { echo "FAIL missing accepted"; exit 1; }

# Wiring: deploy.yml must verify the pre-migration backup and abort on failure.
grep -q 'verify-db-backup.sh "\$BACKUP_FILE" || abort_deploy' "$root/.github/workflows/deploy.yml" \
  || { echo "FAIL deploy.yml does not fail closed on backup verification"; exit 1; }
echo "verify-db-backup tests passed"
