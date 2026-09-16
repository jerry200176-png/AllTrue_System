#!/usr/bin/env bash
# One-time staging environment provisioning (issue #868).
#
# Runs on a DEDICATED staging host — NOT the production Pi. This is
# deliberate: CONTROL_PLANE_CONTRACT.md I1 says only deploy.yml (or POP
# Executor) may execute changes on the production box; a second SSH path
# onto that box would need a formal [contract-change] PR. A separate host
# sidesteps that boundary instead of reopening it.
#
# This script is NOT run by CI or by any agent — a human (or an authorized
# staging operator session) runs it once on the fresh staging host.
#
# Prerequisites: Debian 12 with Apache 2.4, PHP 8.2-FPM (proxy_fcgi, NOT
# mod_php), MariaDB 10.11, Composer 2, Node 22, git/curl/unzip — match
# production versions (see docs/GUIDE_STAGING_ENVIRONMENT.md).
#
# Usage (on the staging host):
#   bash scripts/infra/setup-staging-env.sh <git-remote-url>
set -euo pipefail

REPO_URL="${1:?Usage: setup-staging-env.sh <git-remote-url>}"
STAGING_DIR=/home/staging/AllTrue_System
STAGING_DB=AllTrue_staging
STAGING_DB_USERNAME="atr_staging"
APACHE_SITE=alltrue-staging
PHP_FPM_SOCK=/run/php/php8.2-fpm.sock

if [[ "$(id -u)" -ne 0 ]] && ! sudo -n true 2>/dev/null; then
  echo "This script needs root or passwordless sudo for Apache/MariaDB setup." >&2
  exit 1
fi
SUDO=(sudo)
[[ "$(id -u)" -eq 0 ]] && SUDO=()

echo "=== [0/5] Refuse ambiguous PHP SAPI dual-stack ==="
if dpkg -l 'libapache2-mod-php*' 2>/dev/null | grep -q '^ii'; then
  echo "Refuse: libapache2-mod-php* is installed. Remove it and use PHP-FPM only." >&2
  exit 1
fi
if [[ ! -S "${PHP_FPM_SOCK}" ]]; then
  echo "Refuse: missing ${PHP_FPM_SOCK}. Install/start php8.2-fpm first." >&2
  exit 1
fi
command -v apache2 >/dev/null || { echo "Refuse: apache2 missing" >&2; exit 1; }
command -v mariadb >/dev/null || command -v mysql >/dev/null || {
  echo "Refuse: mariadb/mysql client missing" >&2
  exit 1
}

echo "=== [1/5] Staging Linux user + code directory ==="
if ! id -u staging >/dev/null 2>&1; then
  "${SUDO[@]}" useradd --create-home --shell /bin/bash staging
  echo "✅ created Linux user staging"
else
  echo "✅ Linux user staging already exists"
fi

if [[ -d "${STAGING_DIR}/.git" ]]; then
  echo "✅ ${STAGING_DIR} already a git checkout, skipping clone"
else
  "${SUDO[@]}" mkdir -p /home/staging
  "${SUDO[@]}" chown staging:staging /home/staging
  "${SUDO[@]}" -u staging git clone "${REPO_URL}" "${STAGING_DIR}"
  echo "✅ Cloned into ${STAGING_DIR}"
fi

echo "=== [2/5] Staging MariaDB database + localhost-only user ==="
STAGING_DB_PASSWORD="$(openssl rand -hex 24)"
MYSQL=(mariadb)
command -v mariadb >/dev/null || MYSQL=(mysql)

# Prefer unix_socket root (Debian default). Falls back to interactive -p.
if "${MYSQL[@]}" -u root -e "SELECT 1" >/dev/null 2>&1; then
  MYSQL_ROOT=("${MYSQL[@]}" -u root)
elif "${SUDO[@]}" "${MYSQL[@]}" -u root -e "SELECT 1" >/dev/null 2>&1; then
  MYSQL_ROOT=("${SUDO[@]}" "${MYSQL[@]}" -u root)
else
  echo "Enter MariaDB root password (staging host only):"
  MYSQL_ROOT=("${MYSQL[@]}" -u root -p)
fi

"${MYSQL_ROOT[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${STAGING_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${STAGING_DB_USERNAME}'@'localhost' IDENTIFIED BY '${STAGING_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${STAGING_DB}\`.* TO '${STAGING_DB_USERNAME}'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "✅ Database ${STAGING_DB} + user ${STAGING_DB_USERNAME}@localhost created"

# Fail closed if MariaDB appears bound beyond localhost.
BIND="$("${MYSQL_ROOT[@]}" -N -e "SHOW VARIABLES LIKE 'bind_address';" | awk '{print $2}')"
if [[ -n "${BIND}" && "${BIND}" != "127.0.0.1" && "${BIND}" != "::1" && "${BIND}" != "localhost" ]]; then
  echo "Refuse: MariaDB bind_address=${BIND} (must be localhost-only on staging)." >&2
  exit 1
fi

echo "=== [3/5] Apache vhost → proxy_fcgi → PHP-FPM ==="
for mod in rewrite headers proxy proxy_fcgi setenvif; do
  "${SUDO[@]}" a2enmod "${mod}" >/dev/null
done

APACHE_CONF="/etc/apache2/sites-available/${APACHE_SITE}.conf"
"${SUDO[@]}" tee "${APACHE_CONF}" >/dev/null <<APACHE
# Staging-only AllTrue vhost. LAN HTTP; no production ServerName/DNS.
<VirtualHost *:80>
    ServerName daan-staging
    ServerAlias 192.168.0.202
    DocumentRoot ${STAGING_DIR}/backend/public

    <Directory ${STAGING_DIR}/backend/public>
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \\.php\$>
        SetHandler "proxy:unix:${PHP_FPM_SOCK}|fcgi://localhost/"
    </FilesMatch>

    <FilesMatch "^\\.ht">
        Require all denied
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/alltrue-staging-error.log
    CustomLog \${APACHE_LOG_DIR}/alltrue-staging-access.log combined
</VirtualHost>
APACHE

"${SUDO[@]}" a2ensite "${APACHE_SITE}" >/dev/null
"${SUDO[@]}" a2dissite 000-default >/dev/null 2>&1 || true
"${SUDO[@]}" apache2ctl configtest
"${SUDO[@]}" systemctl reload apache2
"${SUDO[@]}" systemctl reload php8.2-fpm || "${SUDO[@]}" systemctl restart php8.2-fpm
echo "✅ Apache staging vhost active (proxy_fcgi → ${PHP_FPM_SOCK})"

echo "=== [4/5] Ownership for PHP-FPM writes ==="
"${SUDO[@]}" mkdir -p \
  "${STAGING_DIR}/backend/storage" \
  "${STAGING_DIR}/backend/bootstrap/cache"
"${SUDO[@]}" chown -R staging:www-data "${STAGING_DIR}/backend/storage" "${STAGING_DIR}/backend/bootstrap/cache"
"${SUDO[@]}" chmod -R ug+rwx "${STAGING_DIR}/backend/storage" "${STAGING_DIR}/backend/bootstrap/cache"

echo "=== [5/5] Next steps (manual, credential-sensitive) ==="
cat <<NEXT

1. Generate a dedicated deploy SSH key for THIS host (do not reuse PI_SSH_KEY):
     ssh-keygen -t ed25519 -f ~/.ssh/staging_deploy -C "github-actions-staging-deploy" -N ""
     cat ~/.ssh/staging_deploy.pub >> /home/staging/.ssh/authorized_keys

2. From your operator machine (optional until automation is approved):
     gh secret set STAGING_SSH_HOST --body '<this host LAN IP>'
     gh secret set STAGING_SSH_USER --body 'staging'
     gh secret set STAGING_SSH_KEY  --body "\$(base64 -w0 ~/.ssh/staging_deploy)"
     gh secret set STAGING_DB_USERNAME --body '${STAGING_DB_USERNAME}'
     gh secret set STAGING_DB_PASSWORD --body '${STAGING_DB_PASSWORD}'

3. Copy backend/.env.example to ${STAGING_DIR}/backend/.env and set:
     APP_ENV=staging
     APP_DEBUG=false
     APP_URL=http://192.168.0.202
     DB_HOST=127.0.0.1
     DB_DATABASE=${STAGING_DB}
     DB_USERNAME=${STAGING_DB_USERNAME}
     DB_PASSWORD=${STAGING_DB_PASSWORD}
     # Leave LINE/mail/payment/SMS/Telegram/Sentry empty or sandbox-only.
     TRUEFIT_V1=false

4. Deploy an exact CI-green main SHA — see docs/runbooks/STAGING_RECOVERY.md.

Printed once — store the staging DB password in your password manager:
  DB_USERNAME=${STAGING_DB_USERNAME}
  DB_PASSWORD=${STAGING_DB_PASSWORD}
NEXT
