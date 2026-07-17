#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKEND_DIR="$REPO_ROOT/backend"
BASE_CONFIG="$BACKEND_DIR/phpunit.xml"
TEMP_CONFIG=""
TEMP_DIR=""
TEMP_PARENT=""
SCHEMA_NAME=""
DB_HOST='127.0.0.1'
DB_PORT=""
DB_USERNAME='root'
MARIADB_PROCESS=""

die() {
  echo "❌ Isolated PHPUnit: $1" >&2
  exit "${2:-1}"
}

sanitize_suffix() {
  local raw="$1"
  local sanitized
  sanitized="$(printf '%s' "$raw" \
    | tr -c 'A-Za-z0-9_' '_' \
    | sed -E 's/^_+//; s/_+$//; s/_+/_/g')"
  printf '%s' "${sanitized:-local}"
}

validate_schema_name() {
  local schema="$1"
  [[ "$schema" =~ ^AllTrue_test_[A-Za-z0-9_]+$ ]] \
    && [[ "${#schema}" -le 64 ]] \
    && [[ "$schema" != "AllTrue" ]] \
    && [[ "$schema" != "AllTrue_test" ]]
}

make_schema_name() {
  local raw_suffix="$1"
  local safe_suffix nonce
  safe_suffix="$(sanitize_suffix "$raw_suffix")"
  safe_suffix="${safe_suffix:0:36}"
  nonce="$(printf '%s' "$REPO_ROOT:$$:${RANDOM}:${RANDOM}" | sha256sum | cut -c1-12)"
  printf 'AllTrue_test_%s_%s' "$safe_suffix" "$nonce"
}

configuration_arg_is_unsafe() {
  local arg="$1"
  case "$arg" in
    -c|-c?*|--configuration|--configuration=*|--no-configuration)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

xml_env_value() {
  local name="$1"
  local count line value
  count="$(grep -cE "<env[[:space:]]+name=\"${name}\"[[:space:]]+value=\"[^\"]*\"" "$BASE_CONFIG" || true)"
  [[ "$count" == "1" ]] || die "phpunit.xml must define exactly one $name value"
  line="$(grep -E "<env[[:space:]]+name=\"${name}\"[[:space:]]+value=\"[^\"]*\"" "$BASE_CONFIG")"
  value="${line#*value=\"}"
  value="${value%%\"*}"
  printf '%s' "$value"
}

replace_xml_env_value() {
  local config="$1"
  local name="$2"
  local value="$3"
  local count next

  [[ "$name" =~ ^DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)$ ]] \
    || die "unsupported temporary config setting: $name"
  [[ "$value" =~ ^[A-Za-z0-9_.]*$ ]] \
    || die "unsafe temporary config value for $name"

  count="$(grep -cE "name=\"${name}\" value=\"[^\"]*\" force=\"true\"" "$config" || true)"
  [[ "$count" == "1" ]] || die "phpunit.xml must force exactly one $name value"

  next="${config}.next"
  sed -E \
    "s#(name=\"${name}\" value=\")[^\"]*(\" force=\"true\"/>)#\\1${value}\\2#" \
    "$config" > "$next"
  mv "$next" "$config"
}

render_config() {
  local destination="$1"
  local schema="$2"
  local host="$3"
  local port="$4"
  local username="$5"
  local password="$6"
  local setting expected

  [[ "$(xml_env_value DB_CONNECTION)" == 'mysql' ]] \
    || die 'only the MySQL test connection is supported'
  [[ "$(xml_env_value DB_DATABASE)" == 'AllTrue_test' ]] \
    || die 'phpunit.xml default DB must remain exactly AllTrue_test'
  [[ "$(xml_env_value DB_HOST)" == '127.0.0.1' || "$(xml_env_value DB_HOST)" == 'localhost' ]] \
    || die 'tracked PHPUnit config must remain local-only'
  validate_schema_name "$schema" || die 'refusing to render an unsafe schema name'
  [[ "$host" == '127.0.0.1' ]] || die 'ephemeral MariaDB must bind to loopback'
  [[ "$port" =~ ^[0-9]+$ && "$port" -ge 1024 && "$port" -le 65535 ]] \
    || die 'invalid ephemeral MariaDB port'
  [[ "$username" == 'root' && -z "$password" ]] \
    || die 'unexpected ephemeral MariaDB credential contract'

  cp "$BASE_CONFIG" "$destination"
  replace_xml_env_value "$destination" DB_HOST "$host"
  replace_xml_env_value "$destination" DB_PORT "$port"
  replace_xml_env_value "$destination" DB_DATABASE "$schema"
  replace_xml_env_value "$destination" DB_USERNAME "$username"
  replace_xml_env_value "$destination" DB_PASSWORD "$password"
  chmod 600 "$destination"

  for setting in \
    "DB_HOST=$host" \
    "DB_PORT=$port" \
    "DB_DATABASE=$schema" \
    "DB_USERNAME=$username" \
    "DB_PASSWORD=$password"; do
    expected="${setting#*=}"
    setting="${setting%%=*}"
    grep -q "name=\"${setting}\" value=\"${expected}\" force=\"true\"" "$destination" \
      || die "temporary PHPUnit config did not force $setting"
  done

  if grep -q 'name="DB_DATABASE" value="AllTrue_test"' "$destination"; then
    die 'temporary PHPUnit config still targets the shared default schema'
  fi
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

temp_dir_is_safe() {
  local directory="${1%/}"
  local parent="${2%/}"
  local name

  [[ "$directory" == /* && "$parent" == /* && "$directory" != "$parent" ]] \
    || return 1
  [[ "${directory%/*}" == "$parent" ]] || return 1
  name="${directory##*/}"
  [[ "$name" =~ ^alltrue-phpunit-mariadb\.[A-Za-z0-9]+$ ]]
}

self_test() {
  validate_schema_name 'AllTrue_test_worktree_0123456789ab' \
    || die 'schema validator rejected a valid isolated name'
  for unsafe in AllTrue AllTrue_test alltrue_test_x 'AllTrue_test_bad-name' 'AllTrue_test_'; do
    if validate_schema_name "$unsafe"; then
      die "schema validator accepted unsafe name: $unsafe"
    fi
  done

  [[ "$(sanitize_suffix 'feature/local db')" == 'feature_local_db' ]] \
    || die 'suffix sanitizer contract failed'
  configuration_arg_is_unsafe '--configuration=other.xml' \
    || die 'configuration override guard failed'
  configuration_arg_is_unsafe '-cother.xml' \
    || die 'short configuration override guard failed'
  if configuration_arg_is_unsafe '--filter'; then
    die 'normal PHPUnit argument was rejected'
  fi
  temp_dir_is_safe '/tmp/alltrue-phpunit-mariadb.A1b2c3' '/tmp' \
    || die 'Linux temporary directory guard rejected a valid path'
  temp_dir_is_safe '/private/tmp/alltrue-phpunit-mariadb.A1b2c3' '/private/tmp' \
    || die 'macOS temporary directory guard rejected a valid path'
  if temp_dir_is_safe '/tmp/alltrue-phpunit-mariadb.A1b2c3' '/private/tmp'; then
    die 'temporary directory guard accepted a mismatched parent'
  fi
  if temp_dir_is_safe '/tmp/alltrue-phpunit-mariadb-unsafe' '/tmp'; then
    die 'temporary directory guard accepted an invalid namespace'
  fi

  local first second test_config
  first="$(make_schema_name 'explicit suffix')"
  second="$(make_schema_name 'explicit suffix')"
  validate_schema_name "$first" || die 'generated schema failed validation'
  validate_schema_name "$second" || die 'second generated schema failed validation'
  [[ "$first" != "$second" ]] || die 'generated schema names are not process-unique'
  [[ "$first" == AllTrue_test_explicit_suffix_* ]] \
    || die 'explicit suffix was not preserved safely'

  test_config="$(mktemp "$BACKEND_DIR/.phpunit-isolated.self-test.XXXXXX.xml")"
  TEMP_CONFIG="$test_config"
  render_config "$test_config" "$first" '127.0.0.1' '33317' 'root' ''
  rm -f "$test_config" "${test_config}.next"
  TEMP_CONFIG=""

  echo '✅ Isolated PHPUnit self-test passed'
}

cleanup() {
  local status=$?
  trap - EXIT HUP INT TERM

  if [[ -n "$TEMP_CONFIG" ]]; then
    rm -f "$TEMP_CONFIG" "${TEMP_CONFIG}.next"
  fi

  if [[ -n "$MARIADB_PROCESS" ]]; then
    if [[ -n "$DB_PORT" ]] && validate_schema_name "$SCHEMA_NAME"; then
      mariadb --no-defaults --protocol=tcp \
        --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
        -e "DROP DATABASE IF EXISTS \`${SCHEMA_NAME}\`" >/dev/null 2>&1 || true
      mariadb-admin --no-defaults --protocol=tcp \
        --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
        shutdown >/dev/null 2>&1 || true
    fi
    kill "$MARIADB_PROCESS" >/dev/null 2>&1 || true
    wait "$MARIADB_PROCESS" >/dev/null 2>&1 || true
  fi

  if [[ -n "$TEMP_DIR" ]]; then
    if temp_dir_is_safe "$TEMP_DIR" "$TEMP_PARENT" && [[ ! -L "$TEMP_DIR" ]]; then
      if ! rm -rf -- "$TEMP_DIR"; then
        echo "❌ Could not remove ephemeral MariaDB directory: $TEMP_DIR" >&2
        [[ "$status" -eq 0 ]] && status=70
      fi
    else
      echo "❌ Refusing cleanup outside the isolated temp namespace: $TEMP_DIR" >&2
      [[ "$status" -eq 0 ]] && status=70
    fi
  fi

  exit "$status"
}

if [[ "${1:-}" == '--self-test' ]]; then
  [[ "$#" -eq 1 ]] || die '--self-test does not accept PHPUnit arguments'
  self_test
  exit 0
fi

for arg in "$@"; do
  if configuration_arg_is_unsafe "$arg"; then
    die 'PHPUnit configuration overrides are disabled; the isolated config is mandatory'
  fi
done

for command in php sha256sum mariadb-install-db mariadbd mariadb-admin mariadb; do
  command -v "$command" >/dev/null 2>&1 \
    || die "missing required local command: $command"
done
[[ -f "$BASE_CONFIG" ]] || die 'backend/phpunit.xml is missing'
[[ -f "$BACKEND_DIR/vendor/bin/phpunit" ]] || die 'run composer install in backend/ first'

worktree_suffix="${ALLTRUE_TEST_DB_SUFFIX:-$(basename "$REPO_ROOT")}"
SCHEMA_NAME="$(make_schema_name "$worktree_suffix")"
validate_schema_name "$SCHEMA_NAME" || die 'generated schema failed the safety boundary'

TEMP_PARENT="${TMPDIR:-/tmp}"
TEMP_PARENT="${TEMP_PARENT%/}"
[[ "$TEMP_PARENT" == /* && "$TEMP_PARENT" != '/' ]] \
  || die 'TMPDIR must be an absolute non-root directory'
TEMP_DIR="$(mktemp -d "$TEMP_PARENT/alltrue-phpunit-mariadb.XXXXXX")"
temp_dir_is_safe "$TEMP_DIR" "$TEMP_PARENT" \
  || die 'mktemp returned an unsafe ephemeral MariaDB directory'
TEMP_CONFIG="$BACKEND_DIR/.phpunit-isolated.$$.xml"
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

if ! mariadb-install-db --no-defaults \
  --datadir="$TEMP_DIR/data" \
  --auth-root-authentication-method=normal \
  --skip-test-db >"$TEMP_DIR/install.log" 2>&1; then
  tail -n 30 "$TEMP_DIR/install.log" >&2 || true
  die 'ephemeral MariaDB initialization failed'
fi

DB_PORT="$(find_free_port)"
mariadbd --no-defaults \
  --datadir="$TEMP_DIR/data" \
  --socket="$TEMP_DIR/mysql.sock" \
  --pid-file="$TEMP_DIR/mariadb.pid" \
  --port="$DB_PORT" \
  --bind-address="$DB_HOST" \
  --skip-log-bin \
  --log-error="$TEMP_DIR/error.log" &
MARIADB_PROCESS=$!

ready=0
for _ in $(seq 1 100); do
  if mariadb-admin --no-defaults --protocol=tcp \
    --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
    ping --silent >/dev/null 2>&1; then
    ready=1
    break
  fi
  kill -0 "$MARIADB_PROCESS" >/dev/null 2>&1 || break
  sleep 0.1
done

if [[ "$ready" -ne 1 ]]; then
  tail -n 30 "$TEMP_DIR/error.log" >&2 || true
  die 'ephemeral MariaDB did not become ready'
fi

mariadb --no-defaults --protocol=tcp \
  --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
  -e "CREATE DATABASE \`${SCHEMA_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

effective_schema="$(mariadb --no-defaults --protocol=tcp \
  --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
  --batch --skip-column-names \
  -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${SCHEMA_NAME}'")"
[[ "$effective_schema" == "$SCHEMA_NAME" ]] \
  || die 'created schema does not match the isolated namespace'

render_config "$TEMP_CONFIG" "$SCHEMA_NAME" "$DB_HOST" "$DB_PORT" "$DB_USERNAME" ''

# Clean worktrees may not have backend/.env. Keep the test-only key in process
# memory and let the private PHPUnit config own every database setting.
export APP_KEY="${APP_KEY:-base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=}"

echo "🧪 PHPUnit schema: $SCHEMA_NAME (ephemeral localhost:$DB_PORT)"
if php "$BACKEND_DIR/vendor/bin/phpunit" --configuration "$TEMP_CONFIG" "$@"; then
  test_status=0
else
  test_status=$?
fi

exit "$test_status"
