#!/usr/bin/env bash
# DRAFT — proposed path: scripts/agreed-fee-isolated-db-guard.sh
# MUST run before any migrate / schema ALTER / seed.
# Exit 90 = refuse (non-isolated target).
# This is env/preflight only — Laravel loaded-config assert happens in the
# same-process migrate.php BEFORE DDL (plain `php artisan migrate` is forbidden).
set -euo pipefail

: "${DB_DATABASE:?DB_DATABASE required}"
: "${DB_HOST:?DB_HOST required}"

if [[ -n "${DATABASE_URL:-}" ]]; then
  echo "db-guard: refusing DATABASE_URL (overrides connection)" >&2
  exit 90
fi
if [[ -n "${DB_SOCKET:-}" ]]; then
  echo "db-guard: refusing DB_SOCKET" >&2
  exit 90
fi
if [[ -n "${DB_URL:-}" ]]; then
  echo "db-guard: refusing DB_URL" >&2
  exit 90
fi
if [[ -n "${DB_READ_HOST:-}" ]]; then
  echo "db-guard: refusing DB_READ_HOST" >&2
  exit 90
fi
if [[ -n "${DB_WRITE_HOST:-}" ]]; then
  echo "db-guard: refusing DB_WRITE_HOST" >&2
  exit 90
fi

case "$DB_DATABASE" in
  AllTrue_test) ;;
  *)
    echo "db-guard: refusing DB_DATABASE=$DB_DATABASE (need AllTrue_test)" >&2
    exit 90
    ;;
esac

case "$DB_HOST" in
  127.0.0.1|localhost|agreed-fee-test-mysql) ;;
  *)
    echo "db-guard: refusing DB_HOST=$DB_HOST (need loopback/test)" >&2
    exit 90
    ;;
esac

# Force connection name so artisan cannot silently use another default.
export DB_CONNECTION=mysql
# Drop cached config that could reintroduce production DSN.
unset DB_READ_HOST DB_WRITE_HOST MYSQL_HOST MYSQL_DATABASE MYSQL_PWD 2>/dev/null || true

# Refuse pre-baked Laravel config cache (silent redirect risk) when backend root known.
BACKEND_ROOT="${AGREED_FEE_BACKEND_ROOT:-}"
if [[ -z "$BACKEND_ROOT" && -d "$(pwd)/backend" ]]; then
  BACKEND_ROOT="$(pwd)/backend"
elif [[ -z "$BACKEND_ROOT" && -f "$(pwd)/artisan" ]]; then
  BACKEND_ROOT="$(pwd)"
fi
if [[ -n "$BACKEND_ROOT" && -f "$BACKEND_ROOT/bootstrap/cache/config.php" ]]; then
  echo "db-guard: refusing bootstrap/cache/config.php present under $BACKEND_ROOT" >&2
  exit 90
fi

echo "db-guard: OK ${DB_HOST}/${DB_DATABASE}"
