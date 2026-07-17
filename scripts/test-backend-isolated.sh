#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="${ROOT_DIR}/backend"
PHPUNIT_XML="${BACKEND_DIR}/phpunit.xml"
TEST_DATABASE=""
TEMP_DIR=""
MARIADB_PROCESS=""
DB_HOST="127.0.0.1"
DB_PORT=""
DB_USERNAME="root"

is_safe_test_database() {
  [[ "$1" =~ ^AllTrue_test_[A-Za-z0-9_]+$ ]]
}

generate_test_database() {
  local worktree_hash
  worktree_hash="$(printf '%s' "$ROOT_DIR" | sha256sum | cut -c1-10)"
  printf 'AllTrue_test_%s_%s' "$worktree_hash" "$$"
}

find_free_port() {
  php -r '
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errorCode, $errorMessage);
    if ($socket === false) {
        fwrite(STDERR, $errorMessage . PHP_EOL);
        exit(1);
    }
    echo parse_url(stream_socket_get_name($socket, false), PHP_URL_PORT);
  '
}

cleanup() {
  local status="$1"

  if [[ -n "$DB_PORT" ]]; then
    mariadb-admin --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
      shutdown >/dev/null 2>&1 || true
  fi

  if [[ -n "$MARIADB_PROCESS" ]]; then
    kill "$MARIADB_PROCESS" >/dev/null 2>&1 || true
    wait "$MARIADB_PROCESS" >/dev/null 2>&1 || true
  fi

  # Only remove directories created by this script's fixed mktemp template.
  if [[ "$TEMP_DIR" == /tmp/alltrue-phpunit-mariadb.* && -d "$TEMP_DIR" ]]; then
    rm -rf -- "$TEMP_DIR"
  fi

  exit "$status"
}

self_test() {
  local failures=0
  local generated

  for safe in AllTrue_test_a AllTrue_test_agent_123; do
    if ! is_safe_test_database "$safe"; then
      echo "FAIL: rejected safe database name: $safe" >&2
      failures=$((failures + 1))
    fi
  done

  for unsafe in AllTrue AllTrue_test alltrue_test_x 'AllTrue_test_x;DROP_DATABASE'; do
    if is_safe_test_database "$unsafe"; then
      echo "FAIL: accepted unsafe database name: $unsafe" >&2
      failures=$((failures + 1))
    fi
  done

  generated="$(generate_test_database)"
  if ! is_safe_test_database "$generated" || ((${#generated} > 64)); then
    echo "FAIL: generated invalid database name: $generated" >&2
    failures=$((failures + 1))
  fi

  for setting in DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
    if grep -Eq "<env name=\"${setting}\"[^>]*force=\"true\"" "$PHPUNIT_XML"; then
      echo "FAIL: phpunit.xml prevents isolated ${setting} overrides." >&2
      failures=$((failures + 1))
    fi
  done

  if ((failures > 0)); then
    return 1
  fi

  echo "Isolated backend test runner self-test passed."
}

if [[ "${1:-}" == "--self-test" ]]; then
  self_test
  exit $?
fi

for command in php mariadb-install-db mariadbd mariadb-admin mariadb; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "Missing required command: ${command}" >&2
    exit 1
  fi
done

if [[ ! -x "${BACKEND_DIR}/vendor/bin/phpunit" ]]; then
  echo "Missing backend/vendor dependencies; run composer install in backend first." >&2
  exit 1
fi

TEST_DATABASE="$(generate_test_database)"
if ! is_safe_test_database "$TEST_DATABASE"; then
  echo "Refusing to create unsafe test database: ${TEST_DATABASE}" >&2
  exit 1
fi

TEMP_DIR="$(mktemp -d /tmp/alltrue-phpunit-mariadb.XXXXXX)"
trap 'cleanup "$?"' EXIT
trap 'exit 130' HUP INT TERM

mariadb-install-db --no-defaults \
  --datadir="${TEMP_DIR}/data" \
  --auth-root-authentication-method=normal \
  --skip-test-db >"${TEMP_DIR}/install.log" 2>&1

DB_PORT="$(find_free_port)"
mariadbd --no-defaults \
  --datadir="${TEMP_DIR}/data" \
  --socket="${TEMP_DIR}/mysql.sock" \
  --pid-file="${TEMP_DIR}/mariadb.pid" \
  --port="$DB_PORT" \
  --bind-address="$DB_HOST" \
  --skip-log-bin \
  --log-error="${TEMP_DIR}/error.log" &
MARIADB_PROCESS="$!"

ready=false
for _ in {1..100}; do
  if mariadb-admin --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
    ping --silent >/dev/null 2>&1; then
    ready=true
    break
  fi
  if ! kill -0 "$MARIADB_PROCESS" >/dev/null 2>&1; then
    break
  fi
  sleep 0.1
done

if [[ "$ready" != true ]]; then
  echo "Ephemeral MariaDB failed to start:" >&2
  tail -n 30 "${TEMP_DIR}/error.log" >&2 || true
  exit 1
fi

mariadb --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
  -e "CREATE DATABASE \`${TEST_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

export DB_HOST DB_PORT DB_USERNAME
export DB_PASSWORD=""
export DB_DATABASE="$TEST_DATABASE"
export APP_KEY="${APP_KEY:-base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=}"

echo "Running PHPUnit with ephemeral database ${TEST_DATABASE} on localhost:${DB_PORT}."
cd "$BACKEND_DIR"
php vendor/bin/phpunit "$@"
