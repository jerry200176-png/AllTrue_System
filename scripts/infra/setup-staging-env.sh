#!/usr/bin/env bash
# One-time staging environment provisioning (issue #868).
#
# Runs on a DEDICATED staging host — NOT the production Pi. This is
# deliberate: CONTROL_PLANE_CONTRACT.md I1 says only deploy.yml (or POP
# Executor) may execute changes on the production box; a second SSH path
# onto that box would need a formal [contract-change] PR. A separate host
# sidesteps that boundary instead of reopening it.
#
# This script is NOT run by CI or by any agent — a human runs it once,
# interactively, on the fresh staging host.
#
# Prerequisites: Ubuntu/Debian host with PHP 8.2, nginx, MySQL 8, composer,
# node/npm already installed (match production versions — see
# docs/OPERATIONS_RUNBOOK.md for the Pi's stack).
#
# Usage (on the staging host):
#   bash scripts/infra/setup-staging-env.sh <git-remote-url>
set -euo pipefail

REPO_URL="${1:?Usage: setup-staging-env.sh <git-remote-url>}"
STAGING_DIR=/home/staging/AllTrue_System
STAGING_DB=AllTrue_staging

echo "=== [1/4] Staging code directory ==="
if [ -d "$STAGING_DIR/.git" ]; then
  echo "✅ $STAGING_DIR already a git checkout, skipping clone"
else
  mkdir -p /home/staging
  git clone "$REPO_URL" "$STAGING_DIR"
  echo "✅ Cloned into $STAGING_DIR"
fi

echo "=== [2/4] Staging MySQL database + least-privilege user ==="
STAGING_DB_PASSWORD="$(openssl rand -hex 24)"
STAGING_DB_USERNAME="atr_staging"
mysql -u root -p <<SQL
CREATE DATABASE IF NOT EXISTS \`${STAGING_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${STAGING_DB_USERNAME}'@'localhost' IDENTIFIED BY '${STAGING_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${STAGING_DB}\`.* TO '${STAGING_DB_USERNAME}'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "✅ Database ${STAGING_DB} + user ${STAGING_DB_USERNAME} created"

echo "=== [3/4] nginx vhost on port 80 (this host is staging-only, no shared prod traffic) ==="
NGINX_CONF=/etc/nginx/sites-available/alltrue-staging
sudo tee "$NGINX_CONF" >/dev/null <<NGINX
server {
    listen 80;
    server_name _;
    root ${STAGING_DIR}/backend/public;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
sudo ln -sf "$NGINX_CONF" /etc/nginx/sites-enabled/alltrue-staging
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
echo "✅ nginx vhost active on port 80"

echo "=== [4/4] Next steps (manual, credential-sensitive) ==="
cat <<NEXT

1. Generate a dedicated deploy SSH key for THIS host (do not reuse PI_SSH_KEY):
     ssh-keygen -t ed25519 -f ~/.ssh/staging_deploy -C "github-actions-staging-deploy" -N ""
     cat ~/.ssh/staging_deploy.pub >> ~/.ssh/authorized_keys

2. From your own machine, add these GitHub repo secrets:
     gh secret set STAGING_SSH_HOST --body '<this host's IP or hostname>'
     gh secret set STAGING_SSH_USER --body '<the user you SSH'd in as>'
     gh secret set STAGING_SSH_KEY  --body "\$(base64 -w0 ~/.ssh/staging_deploy)"
     gh secret set STAGING_DB_USERNAME --body '${STAGING_DB_USERNAME}'
     gh secret set STAGING_DB_PASSWORD --body '${STAGING_DB_PASSWORD}'

3. Copy backend/.env.example to ${STAGING_DIR}/backend/.env and fill in:
     APP_ENV=staging
     DB_DATABASE=${STAGING_DB}
     DB_USERNAME=${STAGING_DB_USERNAME}
     DB_PASSWORD=${STAGING_DB_PASSWORD}

Auto-deploy workflow is NOT set up yet (blocked on a formal contract-change
review, see docs/GUIDE_STAGING_ENVIRONMENT.md) — for now, deploy manually:
  ssh into this host, then:
    cd ${STAGING_DIR} && git fetch && git reset --hard origin/main
    cd backend && composer install --no-interaction --prefer-dist
    php artisan migrate --force
    cd ../frontend && npm install --prefer-offline && npm run build
NEXT
