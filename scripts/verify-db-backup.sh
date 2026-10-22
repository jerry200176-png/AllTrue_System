#!/usr/bin/env bash
# Verify a gzip'd mysqldump archive before the deploy runs migrations (fail closed).
# Usage: verify-db-backup.sh <file.sql.gz>
# Checks: non-empty archive, gzip integrity, at least one CREATE TABLE and a
# final mysqldump completion footer. This is not a restore verification.
# Reads the full stream, prints counts only and never stores plaintext SQL.
set -euo pipefail

f="${1:-}"
[ -n "$f" ] || { echo "usage: $0 <file.sql.gz>" >&2; exit 2; }
[ -s "$f" ] || { echo "backup missing or empty: $f" >&2; exit 1; }
gunzip -t -- "$f" || { echo "backup failed gzip integrity check: $f" >&2; exit 1; }
# The consumer reads to EOF and emits metadata only. pipefail must also preserve
# a decompressor error after it has already emitted a complete-looking prefix.
if ! summary=$(gunzip -c -- "$f" | awk '
  /^CREATE TABLE/ { tables += 1 }
  /[^[:space:]]/ {
    complete = ($0 ~ /^-- Dump completed on [0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9][[:space:]]+[0-9][0-9]?:[0-9][0-9]:[0-9][0-9][[:space:]]*$/)
  }
  END { printf "%d %d\n", tables, complete }
'); then
  echo "backup decompression/read failed: $f" >&2
  exit 1
fi
read -r tables complete <<< "$summary"
if [ "${tables:-0}" -lt 1 ]; then
  echo "backup contains no CREATE TABLE: $f" >&2
  exit 1
fi
if [ "$complete" != 1 ]; then
  echo "backup missing final mysqldump completion footer: $f" >&2
  exit 1
fi
bytes=$(stat -c %s -- "$f")
echo "backup format/completion verified: $(basename "$f") tables=$tables bytes=$bytes"
