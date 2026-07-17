#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKEND_DIR="$REPO_ROOT/backend"
BASE_CONFIG="$BACKEND_DIR/phpunit.xml"
TEMP_CONFIG=""
SCHEMA_NAME=""
SCHEMA_CREATED=0
MODE='run'

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

render_config() {
  local destination="$1"
  local schema="$2"
  local source_count rendered_count

  source_count="$(grep -c 'name="DB_DATABASE" value="AllTrue_test"' "$BASE_CONFIG" || true)"
  [[ "$source_count" == "1" ]] || die "refusing to patch a config whose default DB is not exactly AllTrue_test"

  sed -E \
    "s#(name=\"DB_DATABASE\" value=\")AllTrue_test(\" force=\"true\"/>)#\\1${schema}\\2#" \
    "$BASE_CONFIG" > "$destination"
  chmod 600 "$destination"

  rendered_count="$(grep -c "name=\"DB_DATABASE\" value=\"${schema}\"" "$destination" || true)"
  [[ "$rendered_count" == "1" ]] || die "temporary PHPUnit config did not receive the isolated schema"
  if grep -q 'name="DB_DATABASE" value="AllTrue_test"' "$destination"; then
    die "temporary PHPUnit config still targets the shared default schema"
  fi
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
  if configuration_arg_is_unsafe '--filter'; then
    die 'normal PHPUnit argument was rejected'
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
  render_config "$test_config" "$first"
  rm -f "$test_config"
  TEMP_CONFIG=""

  echo '✅ Isolated PHPUnit self-test passed'
}

cleanup() {
  local status=$?
  trap - EXIT INT TERM

  rm -f "${TEMP_CONFIG:-}"

  if [[ "$SCHEMA_CREATED" -eq 1 ]]; then
    if ! validate_schema_name "$SCHEMA_NAME"; then
      echo '❌ Refusing cleanup because the schema namespace is invalid' >&2
      [[ "$status" -eq 0 ]] && status=70
    elif ! mysql "${MYSQL_ARGS[@]}" \
      -e "DROP DATABASE IF EXISTS \`${SCHEMA_NAME}\`" >/dev/null; then
      echo "❌ Could not drop isolated test schema: $SCHEMA_NAME" >&2
      [[ "$status" -eq 0 ]] && status=70
    fi
  fi

  unset MYSQL_PWD
  exit "$status"
}

if [[ "${1:-}" == '--self-test' ]]; then
  [[ "$#" -eq 1 ]] || die '--self-test does not accept PHPUnit arguments'
  self_test
  exit 0
fi

if [[ "${1:-}" == '--provision' ]]; then
  [[ "$#" -eq 1 ]] || die '--provision does not accept PHPUnit arguments'
  MODE='provision'
  shift
fi

for arg in "$@"; do
  if configuration_arg_is_unsafe "$arg"; then
    die "PHPUnit configuration overrides are disabled; the isolated config is mandatory"
  fi
done

command -v mysql >/dev/null 2>&1 || die 'mysql client is required'
command -v php >/dev/null 2>&1 || die 'PHP is required'
command -v sha256sum >/dev/null 2>&1 || die 'sha256sum is required'
[[ -f "$BASE_CONFIG" ]] || die 'backend/phpunit.xml is missing'
[[ -f "$BACKEND_DIR/vendor/bin/phpunit" ]] || die 'run composer install in backend/ first'

DB_CONNECTION="$(xml_env_value DB_CONNECTION)"
DB_HOST="$(xml_env_value DB_HOST)"
DB_PORT="$(xml_env_value DB_PORT)"
DB_DATABASE="$(xml_env_value DB_DATABASE)"
DB_USERNAME="$(xml_env_value DB_USERNAME)"
DB_PASSWORD="$(xml_env_value DB_PASSWORD)"

[[ "$DB_CONNECTION" == 'mysql' ]] || die 'only the MySQL test connection is supported'
[[ "$DB_HOST" == '127.0.0.1' || "$DB_HOST" == 'localhost' ]] \
  || die 'refusing to create a test schema on a non-local database host'
[[ "$DB_PORT" =~ ^[0-9]+$ ]] || die 'invalid MySQL port in phpunit.xml'
[[ "$DB_DATABASE" == 'AllTrue_test' ]] \
  || die 'phpunit.xml default DB must remain exactly AllTrue_test'

MYSQL_ARGS=(
  --protocol=TCP
  --host="$DB_HOST"
  --port="$DB_PORT"
  --user="$DB_USERNAME"
  --batch
  --skip-column-names
)
export MYSQL_PWD="$DB_PASSWORD"

if [[ "$MODE" == 'provision' ]]; then
  command -v sudo >/dev/null 2>&1 || die 'sudo is required for one-time local grant provisioning'
  current_account="$(mysql "${MYSQL_ARGS[@]}" -e 'SELECT CURRENT_USER()')" \
    || die 'the tracked local PHPUnit credentials cannot connect'
  grant_user="${current_account%@*}"
  grant_host="${current_account#*@}"
  [[ "$grant_user" =~ ^[A-Za-z0-9_.-]+$ && "$grant_host" =~ ^[A-Za-z0-9_.:%-]+$ ]] \
    || die 'refusing to provision an unexpected MySQL account'

  echo "Provisioning local-only PHPUnit schemas for ${grant_user}@${grant_host} (sudo may prompt)..."
  sudo mysql -e "GRANT ALL PRIVILEGES ON \`AllTrue\\_test\\_%\`.* TO '${grant_user}'@'${grant_host}'; FLUSH PRIVILEGES;"
  unset MYSQL_PWD DB_PASSWORD
  echo '✅ Local isolated PHPUnit grants provisioned'
  exit 0
fi

worktree_suffix="${ALLTRUE_TEST_DB_SUFFIX:-$(basename "$REPO_ROOT")}"
SCHEMA_NAME="$(make_schema_name "$worktree_suffix")"
validate_schema_name "$SCHEMA_NAME" || die 'generated schema failed the safety boundary'

TEMP_CONFIG="$BACKEND_DIR/.phpunit-isolated.$$.xml"
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
render_config "$TEMP_CONFIG" "$SCHEMA_NAME"

if ! mysql "${MYSQL_ARGS[@]}" \
  -e "CREATE DATABASE \`${SCHEMA_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" \
  2>/dev/null; then
  die 'could not create the isolated local test schema; run this script once with --provision'
fi
SCHEMA_CREATED=1

effective_schema="$(mysql "${MYSQL_ARGS[@]}" \
  -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${SCHEMA_NAME}'")"
[[ "$effective_schema" == "$SCHEMA_NAME" ]] \
  || die 'created schema does not match the isolated namespace'

# Clean worktrees may not have backend/.env. Keep the test-only key in process
# memory and let phpunit.xml continue to own every database setting.
export APP_KEY="${APP_KEY:-base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=}"

echo "🧪 PHPUnit schema: $SCHEMA_NAME"
if php "$BACKEND_DIR/vendor/bin/phpunit" --configuration "$TEMP_CONFIG" "$@"; then
  test_status=0
else
  test_status=$?
fi

exit "$test_status"
