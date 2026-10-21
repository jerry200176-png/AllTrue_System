#!/usr/bin/env bash
# Verify a gzip'd mysqldump archive before the deploy runs migrations (fail closed).
# Usage: verify-db-backup.sh <file.sql.gz>
# Checks: file exists and is non-empty, gzip integrity (gunzip -t), and the dump
# contains at least one CREATE TABLE. Prints counts only, never dump content.
set -euo pipefail

f="${1:-}"
[ -n "$f" ] || { echo "usage: $0 <file.sql.gz>" >&2; exit 2; }
[ -s "$f" ] || { echo "backup missing or empty: $f" >&2; exit 1; }
gunzip -t "$f" || { echo "backup failed gzip integrity check: $f" >&2; exit 1; }
# grep -c reads all input (no SIGPIPE under pipefail); exit 1 on zero matches is tolerated here.
tables=$(gunzip -c "$f" | grep -c '^CREATE TABLE' || true)
if [ "${tables:-0}" -lt 1 ]; then
  echo "backup contains no CREATE TABLE: $f" >&2
  exit 1
fi
echo "backup verified: $(basename "$f") tables=$tables bytes=$(stat -c %s "$f")"
