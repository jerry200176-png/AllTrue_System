#!/usr/bin/env bash
# One-time staging host provisioning helper (issue #868) — Stage A only.
#
# Runs on a DEDICATED staging host — NOT the production Pi.
# CONTROL_PLANE_CONTRACT.md I1: only deploy.yml / POP Executor may mutate
# production. A separate host sidesteps that boundary.
#
# This script is NOT run by CI or by any agent. Completing it does NOT prove
# a public staging URL, GitHub staging environment, or auto-deploy path
# exists. Stages B–E are documented in docs/GUIDE_STAGING_ENVIRONMENT.md and
# are intentionally out of scope for this script.
#
# Prerequisites (Debian 12 amd64 / bookworm — production-parity path):
#   Apache 2.4, PHP 8.2-FPM (proxy_fcgi), MariaDB 10.11, Composer 2,
#   Node 22 (for later frontend builds), git, openssl.
#
# Usage (on the staging host):
#   bash scripts/infra/setup-staging-env.sh <git-remote-url>
#
# Database credential (fail-closed; never print the credential):
#   - First run (atr_staging absent): generate or validate /home/staging/.config/alltrue/staging-db-password.
#   - Rerun (atr_staging present): require the existing credential file.
#   The legacy STAGING_DB_PASSWORD environment variable is rejected.
set -euo pipefail

REPO_URL="${1:?Usage: setup-staging-env.sh <git-remote-url>}"
STAGING_DIR="${STAGING_DIR:-/home/staging/AllTrue_System}"
STAGING_DB=AllTrue_staging
STAGING_DB_USERNAME="atr_staging"
APACHE_SITE=alltrue-staging
PHP_FPM_SOCK="${PHP_FPM_SOCK:-/run/php/php8.2-fpm.sock}"
APACHE_SITES_AVAILABLE_DIR="${APACHE_SITES_AVAILABLE_DIR:-/etc/apache2/sites-available}"
STAGING_DB_PASSWORD_FILE="${STAGING_DB_PASSWORD_FILE:-/home/staging/.config/alltrue/staging-db-password}"
STAGING_HOST_MARKER="${STAGING_HOST_MARKER:-/etc/alltrue/staging-host}"
STAGING_HOST_MARKER_VALUE="alltrue-staging-host-v1"
SCRIPT_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# These are never supported as caller configuration.  Only the private
# bootstrap re-exec may provide them, together with its one-shot random token.
if [[ -v STAGING_PARENT_FD || -v STAGING_PARENT_BASENAME || -v STAGING_LOCK_FD ]]; then
  [[ -v STAGING_PARENT_FD && -v STAGING_PARENT_BASENAME && -v STAGING_LOCK_FD ]] || {
    echo "ERROR: credential parent descriptors are incomplete" >&2
    exit 1
  }
fi
if [[ -v STAGING_INTERNAL_TOKEN ]]; then
  echo "ERROR: STAGING_INTERNAL_TOKEN is unsupported" >&2
  exit 1
fi

MYSQL_ROOT_MODE=""
PASSWORD_SOURCE=""
STAGING_SHELL_PID="$$"

if [[ -v STAGING_DB_PASSWORD ]]; then
  echo "ERROR: STAGING_DB_PASSWORD is unsupported; use STAGING_DB_PASSWORD_FILE" >&2
  exit 1
fi

die() {
  echo "ERROR: $*" >&2
  exit 1
}

validate_path_safety() {
  local label="$1" path="$2" ancestor
  [[ "$path" == /* ]] || die "$label must be an absolute path"
  [[ "$path" != *$'\n'* && "$path" != *$'\r'* && "$path" != *$'\t'* ]] || die "$label contains control characters"
  ancestor="$path"
  while [[ "$ancestor" != / ]]; do
    [[ ! -L "$ancestor" ]] || die "$label has a symbolic-link ancestor"
    ancestor="$(dirname -- "$ancestor")"
  done
  path="$(realpath -m -- "$path")" || die "cannot canonicalize $label"
  [[ "$path" != /home/admin && "$path" != /home/admin/* ]] || die "$label points at the production path"
  [[ "$path" != "$SCRIPT_REPO_ROOT" && "$path" != "$SCRIPT_REPO_ROOT"/* ]] || die "$label must not point into the source checkout"
  ancestor="$path"
  while [[ "$ancestor" != / ]]; do
    [[ ! -L "$ancestor" ]] || die "$label has a symbolic-link ancestor"
    ancestor="$(dirname -- "$ancestor")"
  done
}

validate_configured_paths() {
  # Inspect the user-supplied spelling first so a symlink ancestor cannot be
  # hidden by canonicalization; canonical values are then used for all writes.
  validate_path_safety STAGING_DIR "$STAGING_DIR"
  validate_path_safety STAGING_DB_PASSWORD_FILE "$STAGING_DB_PASSWORD_FILE"
  validate_path_safety STAGING_HOST_MARKER "$STAGING_HOST_MARKER"
  validate_path_safety APACHE_SITES_AVAILABLE_DIR "$APACHE_SITES_AVAILABLE_DIR"
  validate_path_safety PHP_FPM_SOCK "$PHP_FPM_SOCK"
  SCRIPT_REPO_ROOT="$(realpath -m -- "$SCRIPT_REPO_ROOT")" || die "cannot canonicalize source checkout"
  STAGING_DIR="$(realpath -m -- "$STAGING_DIR")" || die "cannot canonicalize STAGING_DIR"
  STAGING_DB_PASSWORD_FILE="$(realpath -m -- "$STAGING_DB_PASSWORD_FILE")" || die "cannot canonicalize credential path"
  STAGING_HOST_MARKER="$(realpath -m -- "$STAGING_HOST_MARKER")" || die "cannot canonicalize marker path"
  APACHE_SITES_AVAILABLE_DIR="$(realpath -m -- "$APACHE_SITES_AVAILABLE_DIR")" || die "cannot canonicalize Apache path"
  PHP_FPM_SOCK="$(realpath -m -- "$PHP_FPM_SOCK")" || die "cannot canonicalize PHP-FPM socket"
  validate_path_safety STAGING_DIR "$STAGING_DIR"
  validate_path_safety STAGING_DB_PASSWORD_FILE "$STAGING_DB_PASSWORD_FILE"
  validate_path_safety STAGING_HOST_MARKER "$STAGING_HOST_MARKER"
  validate_path_safety APACHE_SITES_AVAILABLE_DIR "$APACHE_SITES_AVAILABLE_DIR"
  validate_path_safety PHP_FPM_SOCK "$PHP_FPM_SOCK"
  [[ "$STAGING_DB_PASSWORD_FILE" != "$STAGING_DIR"/* ]] || die "credential file must not be inside STAGING_DIR"
}

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    die "missing required command '$1'. Install the Debian 12 staging stack first — see docs/GUIDE_STAGING_ENVIRONMENT.md Stage A."
  fi
}

validate_staging_host() {
  local os_release marker_owner marker_mode marker_value marker_mode_value
  os_release="$(command cat /etc/os-release 2>/dev/null || true)"
  grep -q '^ID=debian$' <<<"$os_release" || die "staging host must be Debian 12 (bookworm)"
  grep -q '^VERSION_ID="12"$' <<<"$os_release" || die "staging host must be Debian 12 (bookworm)"
  [[ "$(uname -m)" == "x86_64" ]] || die "staging host must be x86_64"
  [[ "$STAGING_DIR" != /home/admin && "$STAGING_DIR" != /home/admin/* ]] || die "production path is not a staging path"
  [[ "$STAGING_HOST_MARKER" != /home/admin/* ]] || die "production identity marker path is forbidden"
  [[ -f "$STAGING_HOST_MARKER" && ! -L "$STAGING_HOST_MARKER" ]] || die "missing or unsafe staging host marker"
  marker_owner="$(stat -c '%u' -- "$STAGING_HOST_MARKER" 2>/dev/null)" || die "cannot inspect staging host marker owner"
  [[ "$marker_owner" == "0" ]] || die "staging host marker must be root-owned"
  marker_mode="$(stat -c '%a' -- "$STAGING_HOST_MARKER" 2>/dev/null)" || die "cannot inspect staging host marker mode"
  marker_mode_value="$(printf '%d' "0${marker_mode}")" || die "invalid staging host marker mode"
  (( (marker_mode_value & 077) == 0 )) || die "staging host marker must not be group/world writable"
  marker_value="$(command cat -- "$STAGING_HOST_MARKER")"
  [[ "$marker_value" == "$STAGING_HOST_MARKER_VALUE" ]] || die "staging host marker value is invalid"
}

require_runtime_versions() {
  local php_version node_version db_version apache_version composer_version fpm_version
  php_version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  [[ "$php_version" == "8.2" ]] || die "expected PHP 8.2.x, found ${php_version}"
  node_version="$(node --version 2>/dev/null || true)"
  [[ "$node_version" =~ ^v22\. ]] || die "expected Node 22.x, found ${node_version:-missing}"
  require_cmd npm
  db_version="$(mysql --version 2>/dev/null || true)"
  grep -qE 'Distrib 10\.11\..*MariaDB' <<<"$db_version" || die "expected MariaDB 10.11.x client"
  apache_version="$(apache2ctl -v 2>/dev/null || true)"
  grep -qE 'Apache/2\.4\.' <<<"$apache_version" || die "expected Apache 2.4.x"
  composer_version="$(composer --version 2>/dev/null || true)"
  grep -qE 'Composer version 2\.' <<<"$composer_version" || die "expected Composer 2.x"
  require_cmd php-fpm8.2
  fpm_version="$(php-fpm8.2 -v 2>/dev/null || true)"
  grep -qE 'PHP 8\.2\.' <<<"$fpm_version" || die "expected PHP-FPM 8.2.x"
}

prepare_credential_parent() {
  # Canonicalize and hold the parent directory and lock by descriptor.  The
  # helper re-execs this script with both descriptors inherited, so a later
  # replacement of any absolute-path ancestor cannot redirect credentials or
  # the lock to another tree.
  [[ -z "${STAGING_PARENT_FD:-}" ]] || return 0
  local parent basename script="$SCRIPT_REPO_ROOT/scripts/infra/setup-staging-env.sh"
  parent="$(dirname -- "$STAGING_DB_PASSWORD_FILE")"
  basename="$(basename -- "$STAGING_DB_PASSWORD_FILE")"
  export STAGING_PARENT_BASENAME="$basename"
  exec python3 - "$script" "$REPO_URL" "$parent" "$basename" <<'PY'
import fcntl, os, stat, sys

script, repo_url, parent, basename = sys.argv[1:]
if not parent.startswith("/") or not basename or basename in {".", ".."}:
    raise SystemExit("invalid credential parent")
parts = [part for part in parent.split("/") if part]
fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
try:
    for part in parts:
        try:
            next_fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
        except FileNotFoundError:
            os.mkdir(part, 0o700, dir_fd=fd)
            next_fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
        os.close(fd)
        fd = next_fd
    st = os.fstat(fd)
    if not stat.S_ISDIR(st.st_mode) or st.st_uid != os.getuid() or stat.S_IMODE(st.st_mode) != 0o700:
        raise SystemExit("credential parent ownership/mode invalid")
    lock_fd = os.open(
        ".staging-db-password.lock",
        os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW,
        0o600,
        dir_fd=fd,
    )
    lock_st = os.fstat(lock_fd)
    if (not stat.S_ISREG(lock_st.st_mode) or lock_st.st_uid != os.getuid()
            or stat.S_IMODE(lock_st.st_mode) != 0o600):
        raise SystemExit("credential lock ownership/type/mode invalid")
    fcntl.flock(lock_fd, fcntl.LOCK_EX)
    # Keep descriptors outside bash's low-numbered internal range.
    os.dup2(fd, 50)
    os.dup2(lock_fd, 51)
    os.close(fd)
    os.close(lock_fd)
    fd, lock_fd = 50, 51
    os.set_inheritable(fd, True)
    os.set_inheritable(lock_fd, True)
    env = os.environ.copy()
    env["STAGING_PARENT_FD"] = str(fd)
    env["STAGING_LOCK_FD"] = str(lock_fd)
    os.execvpe("bash", ["bash", script, repo_url], env)
finally:
    # execvpe does not return on success.  Close descriptors on an error.
    try: os.close(fd)
    except OSError: pass
PY
}

mysql_root() {
  case "${MYSQL_ROOT_MODE}" in
    sudo)
      sudo mysql "$@"
      ;;
    password)
      MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" mysql -u root "$@"
      ;;
    *)
      die "MariaDB root auth mode not initialized"
      ;;
  esac
}

init_mysql_root() {
  if sudo mysql -e "SELECT 1" >/dev/null 2>&1; then
    MYSQL_ROOT_MODE=sudo
    return
  fi
  if [[ -t 0 ]]; then
    local root_password
    read -rsp "MariaDB root password (blank if using sudo socket auth only): " root_password
    echo
    if [[ -z "${root_password}" ]]; then
      die "cannot authenticate as MariaDB root (sudo mysql failed; empty password)"
    fi
    if MYSQL_PWD="${root_password}" mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
      MYSQL_ROOT_MODE=password
      MYSQL_ROOT_PASSWORD="${root_password}"
      unset root_password
      return
    fi
    unset root_password
    die "MariaDB root password rejected"
  fi
  die "cannot authenticate as MariaDB root — use sudo socket auth or interactive root password"
}

create_credential_file() {
  python3 "$SCRIPT_REPO_ROOT/scripts/infra/credential-parent-helper.py" \
    "${STAGING_PARENT_FD:-50}" "${STAGING_PARENT_BASENAME:-$(basename -- "$STAGING_DB_PASSWORD_FILE")}" create \
    || die "cannot create staging credential without clobbering"
}

credential_state() {
  python3 "$SCRIPT_REPO_ROOT/scripts/infra/credential-parent-helper.py" \
    "${STAGING_PARENT_FD:-50}" "${STAGING_PARENT_BASENAME:-$(basename -- "$STAGING_DB_PASSWORD_FILE")}" state
}

# Verify app credentials over TCP without selecting a database.
verify_staging_db_login() {
  python3 "$SCRIPT_REPO_ROOT/scripts/infra/credential-parent-helper.py" "${STAGING_PARENT_FD:-50}" \
    "${STAGING_PARENT_BASENAME:-$(basename -- "$STAGING_DB_PASSWORD_FILE")}" mysql \
    --protocol=TCP -h 127.0.0.1 -u "${STAGING_DB_USERNAME}" -e "SELECT 1 AS ok" >/dev/null 2>&1
}

verify_staging_db_host() {
  local host="$1" protocol_args=()
  if [[ "$host" == localhost ]]; then
    protocol_args=(--protocol=SOCKET)
  elif [[ "$host" == 127.0.0.1 ]]; then
    protocol_args=(--protocol=TCP -h 127.0.0.1)
  else
    die "unsupported atr_staging host '${host}'"
  fi
  python3 "$SCRIPT_REPO_ROOT/scripts/infra/credential-parent-helper.py" "${STAGING_PARENT_FD:-50}" \
    "${STAGING_PARENT_BASENAME:-$(basename -- "$STAGING_DB_PASSWORD_FILE")}" mysql \
    "${protocol_args[@]}" -u "${STAGING_DB_USERNAME}" -e "SELECT 1 AS ok" >/dev/null 2>&1
}

verify_staging_db_select() {
  python3 "$SCRIPT_REPO_ROOT/scripts/infra/credential-parent-helper.py" "${STAGING_PARENT_FD:-50}" \
    "${STAGING_PARENT_BASENAME:-$(basename -- "$STAGING_DB_PASSWORD_FILE")}" mysql \
    --protocol=TCP -h 127.0.0.1 -u "${STAGING_DB_USERNAME}" "${STAGING_DB}" -e "SELECT 1 AS ok" >/dev/null 2>&1
}

staging_user_hosts() {
  mysql_root -N -e \
    "SELECT Host FROM mysql.user WHERE User='${STAGING_DB_USERNAME}' ORDER BY Host;"
}

validate_host_inventory() {
  local raw="$1" host count=0
  declare -A seen=()
  while IFS= read -r host; do
    [[ -n "$host" ]] || die "empty atr_staging host inventory row"
    [[ "$host" == "localhost" || "$host" == "127.0.0.1" ]] || die "unexpected atr_staging host '${host}'"
    [[ -z "${seen[$host]+x}" ]] || die "duplicate atr_staging host '${host}'"
    seen[$host]=1
    ((count += 1))
  done <<< "$raw"
  [[ "$count" -le 2 ]] || die "invalid atr_staging host inventory"
}

verify_mariadb_server() {
  local server_version
  server_version="$(mysql_root -N -e 'SELECT VERSION();')" || die "cannot read MariaDB server version"
  grep -qE '^10\.11\..*MariaDB' <<<"$server_version" || die "expected MariaDB server 10.11.x"
}

echo "=== [0/4] Prerequisite checks (Debian 12 production-parity) ==="
require_cmd git
require_cmd openssl
require_cmd realpath
require_cmd python3
require_cmd flock
flock -n /dev/null true || die "flock is unavailable or unusable"
require_cmd mysql
require_cmd php
require_cmd composer
require_cmd apache2ctl
require_cmd systemctl
require_cmd sudo
require_cmd node

validate_staging_host
validate_configured_paths
require_runtime_versions
php -m | grep -qi '^pdo_mysql$' || die "php8.2-mysql (pdo_mysql) extension is required"

if [[ ! -S "$PHP_FPM_SOCK" ]]; then
  die "PHP 8.2-FPM socket not found at $PHP_FPM_SOCK — install/enable php8.2-fpm first"
fi
echo "✅ Prerequisites look present"

prepare_credential_parent
STAGING_PARENT_FD=50
exec 8>&51
STAGING_LOCK_FD=8
python3 "$SCRIPT_REPO_ROOT/scripts/infra/credential-parent-helper.py" \
  "$STAGING_PARENT_FD" "$STAGING_PARENT_BASENAME" validate \
  "$STAGING_DB_PASSWORD_FILE" "$STAGING_LOCK_FD" \
  || die "inherited credential parent/lock validation failed"
init_mysql_root
echo "✅ MariaDB root auth via ${MYSQL_ROOT_MODE}"
verify_mariadb_server
echo "✅ MariaDB server version verified"

# Re-read topology only after the lock: another process may have changed it.
if ! EXISTING_HOSTS_RAW="$(staging_user_hosts)"; then
  die "cannot read atr_staging host inventory; no mutation is allowed"
fi
if [[ -n "$EXISTING_HOSTS_RAW" ]]; then
  validate_host_inventory "$EXISTING_HOSTS_RAW"
  mapfile -t EXISTING_USER_HOSTS <<< "$EXISTING_HOSTS_RAW"
else
  EXISTING_USER_HOSTS=()
fi
EXISTING_USER_COUNT="${#EXISTING_USER_HOSTS[@]}"
[[ "$EXISTING_USER_COUNT" == 0 || "$EXISTING_USER_COUNT" == 1 || "$EXISTING_USER_COUNT" == 2 ]] || die "invalid atr_staging host inventory"

# Resolve and, for existing users, prove the credential before any clone or DB
# mutation. The value is only held in memory and is never emitted.
credential_state_output="$(mktemp)" || die "cannot allocate credential state buffer"
credential_state >"$credential_state_output" || {
  rm -f -- "$credential_state_output"
  die "cannot inspect credential file"
}
credential_state_value="$(<"$credential_state_output")"
rm -f -- "$credential_state_output"
if [[ "$credential_state_value" == present ]]; then
  PASSWORD_SOURCE=file
elif [[ "${EXISTING_USER_COUNT}" == "0" ]]; then
  create_credential_file
  PASSWORD_SOURCE=generated
else
  die "user '${STAGING_DB_USERNAME}' already exists and credential file is missing; refusing to generate or replace credentials"
fi

if [[ "${EXISTING_USER_COUNT}" != "0" ]]; then
  for host in "${EXISTING_USER_HOSTS[@]}"; do
    verify_staging_db_host "$host" \
      || die "credential file does not authenticate atr_staging via ${host}; failing closed"
  done
fi

echo "=== [1/4] Staging code directory ==="
if [[ -d "$STAGING_DIR/.git" ]]; then
  echo "✅ $STAGING_DIR already a git checkout, skipping clone"
else
  mkdir -p "$(dirname "$STAGING_DIR")"
  git clone "$REPO_URL" "$STAGING_DIR"
  echo "✅ Cloned into $STAGING_DIR"
fi

echo "=== [2/4] Staging MariaDB database + atr_staging (idempotent, fail-closed) ==="
mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`${STAGING_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL

if [[ "${EXISTING_USER_COUNT}" == "0" ]]; then
  echo "First-run: creating '${STAGING_DB_USERNAME}'@localhost and @127.0.0.1"
  for host in localhost 127.0.0.1; do
    if [[ "$MYSQL_ROOT_MODE" == password ]]; then
      MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" python3 "$SCRIPT_REPO_ROOT/scripts/infra/credential-parent-helper.py" "$STAGING_PARENT_FD" "$STAGING_PARENT_BASENAME" root-mysql "$MYSQL_ROOT_MODE" "$STAGING_DB" "$STAGING_DB_USERNAME" "$host" first || die "cannot create staging user"
    else
      python3 "$SCRIPT_REPO_ROOT/scripts/infra/credential-parent-helper.py" "$STAGING_PARENT_FD" "$STAGING_PARENT_BASENAME" root-mysql "$MYSQL_ROOT_MODE" "$STAGING_DB" "$STAGING_DB_USERNAME" "$host" first || die "cannot create staging user"
    fi
  done
else
  echo "Rerun: '${STAGING_DB_USERNAME}' inventory verified; no password reset or ALTER"
  if [[ "${EXISTING_USER_COUNT}" == "1" ]]; then
    missing_host=localhost
    [[ "${EXISTING_USER_HOSTS[0]}" == "localhost" ]] && missing_host=127.0.0.1
    if [[ "$MYSQL_ROOT_MODE" == password ]]; then
      MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" python3 "$SCRIPT_REPO_ROOT/scripts/infra/credential-parent-helper.py" "$STAGING_PARENT_FD" "$STAGING_PARENT_BASENAME" root-mysql "$MYSQL_ROOT_MODE" "$STAGING_DB" "$STAGING_DB_USERNAME" "$missing_host" add-host || die "cannot add staging user host"
    else
      python3 "$SCRIPT_REPO_ROOT/scripts/infra/credential-parent-helper.py" "$STAGING_PARENT_FD" "$STAGING_PARENT_BASENAME" root-mysql "$MYSQL_ROOT_MODE" "$STAGING_DB" "$STAGING_DB_USERNAME" "$missing_host" add-host || die "cannot add staging user host"
    fi
  else
    mysql_root <<SQL
GRANT ALL PRIVILEGES ON \`${STAGING_DB}\`.* TO '${STAGING_DB_USERNAME}'@'localhost';
GRANT ALL PRIVILEGES ON \`${STAGING_DB}\`.* TO '${STAGING_DB_USERNAME}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
  fi
fi

if [[ "${EXISTING_USER_COUNT}" == "0" ]]; then
  verify_staging_db_host localhost || die "localhost credential verification failed"
  verify_staging_db_host 127.0.0.1 || die "127.0.0.1 credential verification failed"
elif [[ "${EXISTING_USER_COUNT}" == "1" ]]; then
  verify_staging_db_host "${missing_host}" || die "new host credential verification failed"
else
  verify_staging_db_host localhost || die "localhost post-grant verification failed"
  verify_staging_db_host 127.0.0.1 || die "127.0.0.1 post-grant verification failed"
fi

if ! verify_staging_db_select; then
  die "post-configure database SELECT verification failed; failing closed without printing credential"
fi
echo "✅ Verified: ${STAGING_DB_USERNAME} can SELECT on ${STAGING_DB} via 127.0.0.1 (source=${PASSWORD_SOURCE})"

echo "=== [3/4] Apache vhost on port 80 (proxy_fcgi → php8.2-fpm) ==="
APACHE_CONF="${APACHE_SITES_AVAILABLE_DIR}/${APACHE_SITE}.conf"
sudo tee "$APACHE_CONF" >/dev/null <<APACHE
# Generated by scripts/infra/setup-staging-env.sh — staging host only (Stage A).
# Production Pi is untouched. DocumentRoot → Laravel public + PHP 8.2-FPM.
<VirtualHost *:80>
    ServerName _
    DocumentRoot ${STAGING_DIR}/backend/public

    <Directory ${STAGING_DIR}/backend/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \\.php\$>
        SetHandler "proxy:unix:${PHP_FPM_SOCK}|fcgi://localhost"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/${APACHE_SITE}-error.log
    CustomLog \${APACHE_LOG_DIR}/${APACHE_SITE}-access.log combined
</VirtualHost>
APACHE

sudo a2enmod rewrite proxy_fcgi setenvif >/dev/null
sudo a2enconf php8.2-fpm >/dev/null || true
sudo a2dissite 000-default >/dev/null 2>&1 || true
sudo a2ensite "$APACHE_SITE" >/dev/null
sudo apache2ctl configtest
sudo systemctl reload apache2
echo "✅ Apache vhost ${APACHE_SITE} active (DocumentRoot → backend/public, php8.2-fpm via proxy_fcgi)"

echo "=== [4/4] Stage A complete — next stages are manual ==="
cat <<NEXT

Stage A (host provisioning) finished on THIS host only.
It does NOT deploy an exact SHA, run smoke, create GitHub Environments,
or prove a public staging URL. Stages B–E: docs/GUIDE_STAGING_ENVIRONMENT.md

DB credential (verified moments ago against ${STAGING_DB} @ 127.0.0.1):
  DB_USERNAME=${STAGING_DB_USERNAME}
  DB_DATABASE=${STAGING_DB}
  DB_PASSWORD=<see below>
NEXT

cat <<PASS

  Password source: ${PASSWORD_SOURCE}; credential remains in ${STAGING_DB_PASSWORD_FILE} (mode 0600).
PASS

cat <<NEXT

Write staging-only backend/.env (never copy production .env / PI_*):
  cp ${STAGING_DIR}/backend/.env.example ${STAGING_DIR}/backend/.env
  # set APP_ENV=staging, DB_HOST=127.0.0.1, DB_DATABASE=${STAGING_DB},
  # DB_USERNAME=${STAGING_DB_USERNAME}, DB_PASSWORD=<the verified password>

Stage B — manual exact-SHA deploy (required for a usable app; still no CI):
  cd ${STAGING_DIR}
  git fetch origin
  git reset --hard <CI-green-SHA>
  cd backend && composer install --no-interaction --prefer-dist
  php artisan migrate --force
  cd ../frontend && npm ci && npm run build
  sudo systemctl reload php8.2-fpm

Stage C — smoke (no production SMOKE_* credentials):
  curl -fsS http://127.0.0.1/api/v1/health
  curl -fsS http://127.0.0.1/deployment.json

Stage D is not approved or implemented; do not upload DB credentials or
production secrets. A future workflow requires a separate Founder-approved
contract; see GUIDE_STAGING_ENVIRONMENT.md Stage D.

Stage E — optional future production gate: Founder [contract-change] only.
Do not edit deploy.yml from this path.
NEXT
