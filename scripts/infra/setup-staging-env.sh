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
# Database password (fail-closed; never print an unverified credential):
#   STAGING_DB_PASSWORD=<known> bash scripts/infra/setup-staging-env.sh <url>
#   - First run (atr_staging absent): use env password, or generate one.
#   - Rerun (atr_staging present): require STAGING_DB_PASSWORD that already
#     authenticates; otherwise exit non-zero. No silent password replace.
set -euo pipefail

REPO_URL="${1:?Usage: setup-staging-env.sh <git-remote-url>}"
STAGING_DIR=/home/staging/AllTrue_System
STAGING_DB=AllTrue_staging
STAGING_DB_USERNAME="atr_staging"
APACHE_SITE=alltrue-staging
PHP_FPM_SOCK=/run/php/php8.2-fpm.sock

MYSQL_ROOT_MODE=""
PASSWORD_SOURCE=""
GENERATED_PASSWORD=0

die() {
  echo "ERROR: $*" >&2
  exit 1
}

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    die "missing required command '$1'. Install the Debian 12 staging stack first — see docs/GUIDE_STAGING_ENVIRONMENT.md Stage A."
  fi
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
  if [[ -n "${MYSQL_ROOT_PASSWORD:-}" ]]; then
    if MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
      MYSQL_ROOT_MODE=password
      return
    fi
    die "MYSQL_ROOT_PASSWORD was set but root login failed"
  fi
  if [[ -t 0 ]]; then
    read -rsp "MariaDB root password (blank if using sudo socket auth only): " MYSQL_ROOT_PASSWORD
    echo
    if [[ -z "${MYSQL_ROOT_PASSWORD}" ]]; then
      die "cannot authenticate as MariaDB root (sudo mysql failed; empty password)"
    fi
    if MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
      MYSQL_ROOT_MODE=password
      return
    fi
    die "MariaDB root password rejected"
  fi
  die "cannot authenticate as MariaDB root — use sudo socket auth or set MYSQL_ROOT_PASSWORD"
}

# Verify app credentials over TCP (matches Laravel DB_HOST=127.0.0.1).
verify_staging_db_login() {
  local pass="$1"
  MYSQL_PWD="${pass}" mysql --protocol=TCP -h 127.0.0.1 -u "${STAGING_DB_USERNAME}" \
    "${STAGING_DB}" -e "SELECT 1 AS ok" >/dev/null 2>&1
}

staging_user_host_count() {
  mysql_root -N -e \
    "SELECT COUNT(*) FROM mysql.user WHERE User='${STAGING_DB_USERNAME}' AND Host IN ('localhost','127.0.0.1');"
}

# Escape a value for use inside a single-quoted SQL string literal.
sql_quote() {
  local raw="$1"
  # MariaDB single-quote escape is '' (two single quotes)
  printf "%s" "${raw//\'/\'\'}"
}

echo "=== [0/4] Prerequisite checks (Debian 12 production-parity) ==="
require_cmd git
require_cmd openssl
require_cmd mysql
require_cmd php
require_cmd composer
require_cmd apache2ctl
require_cmd systemctl
require_cmd sudo

if [[ ! -S "$PHP_FPM_SOCK" ]]; then
  die "PHP 8.2-FPM socket not found at $PHP_FPM_SOCK — install/enable php8.2-fpm first"
fi

PHP_MAJOR_MINOR="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
[[ "$PHP_MAJOR_MINOR" == "8.2" ]] || die "expected PHP 8.2.x, found $(php -v | head -1)"

php -m | grep -qi '^pdo_mysql$' || die "php8.2-mysql (pdo_mysql) extension is required"

DB_VERSION_LINE="$(mysql --version 2>/dev/null || true)"
if ! grep -qiE 'MariaDB' <<<"$DB_VERSION_LINE"; then
  echo "WARN: expected MariaDB 10.11 client banner, got: $DB_VERSION_LINE" >&2
fi
echo "✅ Prerequisites look present"
echo "   php=$(php -v | head -1)"
echo "   db=$DB_VERSION_LINE"
echo "   apache=$(apache2ctl -v 2>/dev/null | head -1 || true)"

init_mysql_root
echo "✅ MariaDB root auth via ${MYSQL_ROOT_MODE}"

echo "=== [1/4] Staging code directory ==="
if [[ -d "$STAGING_DIR/.git" ]]; then
  echo "✅ $STAGING_DIR already a git checkout, skipping clone"
else
  mkdir -p /home/staging
  git clone "$REPO_URL" "$STAGING_DIR"
  echo "✅ Cloned into $STAGING_DIR"
fi

echo "=== [2/4] Staging MariaDB database + atr_staging (idempotent, fail-closed) ==="
EXISTING_USER_COUNT="$(staging_user_host_count)"
EXISTING_USER_COUNT="${EXISTING_USER_COUNT//[[:space:]]/}"

# Resolve candidate password without treating it as authoritative yet.
if [[ -n "${STAGING_DB_PASSWORD:-}" ]]; then
  PASSWORD_SOURCE=env
elif [[ "${EXISTING_USER_COUNT}" == "0" ]]; then
  STAGING_DB_PASSWORD="$(openssl rand -hex 24)"
  PASSWORD_SOURCE=generated
  GENERATED_PASSWORD=1
else
  die "user '${STAGING_DB_USERNAME}' already exists. Re-run with STAGING_DB_PASSWORD=<known working password> to verify. Refusing to invent a new password for an existing user."
fi

mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`${STAGING_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL

SQL_PASSWORD="$(sql_quote "${STAGING_DB_PASSWORD}")"

if [[ "${EXISTING_USER_COUNT}" == "0" ]]; then
  echo "First-run: creating '${STAGING_DB_USERNAME}'@localhost and @127.0.0.1"
  mysql_root <<SQL
CREATE USER '${STAGING_DB_USERNAME}'@'localhost' IDENTIFIED BY '${SQL_PASSWORD}';
CREATE USER '${STAGING_DB_USERNAME}'@'127.0.0.1' IDENTIFIED BY '${SQL_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${STAGING_DB}\`.* TO '${STAGING_DB_USERNAME}'@'localhost';
GRANT ALL PRIVILEGES ON \`${STAGING_DB}\`.* TO '${STAGING_DB_USERNAME}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
else
  echo "Rerun: '${STAGING_DB_USERNAME}' already present — proving provided password (no silent reset)"
  if ! verify_staging_db_login "${STAGING_DB_PASSWORD}"; then
    die "provided STAGING_DB_PASSWORD does not authenticate '${STAGING_DB_USERNAME}' to '${STAGING_DB}' over 127.0.0.1. Fail closed — fix the password or drop the user intentionally as root, then re-run."
  fi
  mysql_root <<SQL
GRANT ALL PRIVILEGES ON \`${STAGING_DB}\`.* TO '${STAGING_DB_USERNAME}'@'localhost';
GRANT ALL PRIVILEGES ON \`${STAGING_DB}\`.* TO '${STAGING_DB_USERNAME}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
fi

if ! verify_staging_db_login "${STAGING_DB_PASSWORD}"; then
  die "post-configure credential verification failed for '${STAGING_DB_USERNAME}' / '${STAGING_DB}'. Not printing any password as authoritative."
fi
echo "✅ Verified: ${STAGING_DB_USERNAME} can SELECT on ${STAGING_DB} via 127.0.0.1 (source=${PASSWORD_SOURCE})"

echo "=== [3/4] Apache vhost on port 80 (proxy_fcgi → php8.2-fpm) ==="
APACHE_CONF="/etc/apache2/sites-available/${APACHE_SITE}.conf"
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

if [[ "${GENERATED_PASSWORD}" -eq 1 ]]; then
  cat <<PASS

  *** Verified generated password (store in your password manager, then clear scrollback) ***
  ${STAGING_DB_PASSWORD}
  *******************************************************************************
PASS
else
  cat <<PASS

  Password source: ${PASSWORD_SOURCE} (already known to you; re-verified, not reprinted).
PASS
fi

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

Stage D — optional GitHub staging environment + deploy key (NOT required for A–C):
  Generate the ed25519 keypair on a trusted operator/CI machine (private key
  never created on this host). Install ONLY the public key here:
    install -d -m 700 ~/.ssh
    # append the .pub line to ~/.ssh/authorized_keys, then:
    chmod 700 ~/.ssh
    chmod 600 ~/.ssh/authorized_keys
  Store the private key + STAGING_* values in GitHub Environment "staging"
  later — see docs/runbooks/GITHUB_ENVIRONMENTS_SETUP.md.

Stage E — optional future production gate: Founder [contract-change] only.
Do not edit deploy.yml from this path.
NEXT
