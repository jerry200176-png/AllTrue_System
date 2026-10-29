#!/usr/bin/env bash
# SessionStart hook for Claude Code cloud sessions.
# Installs backend (composer) and frontend (npm) dependencies so tests, lint
# and the build can run in the cloud container.
#
# Safety contract (CLAUDE.md R1-R6):
#   - Cloud only: exits immediately unless CLAUDE_CODE_REMOTE=true.
#   - No third-party code runs at install time. `npm ci --ignore-scripts` skips
#     every package lifecycle script (and husky's `prepare`), and
#     `composer install --no-scripts --no-plugins` skips Composer scripts and
#     plugins. This is the real control: the hook runs as root, so any install
#     script could read files such as the session ingress token file or
#     ~/.config/composer/auth.json.
#   - Defense in depth: installs run under `env -i` with an allowlist (PATH,
#     HOME, locale, CA bundle, and the proxy only where the tool needs it), so
#     GH_TOKEN, AWS_*, CLOUDSDK_*, *_PASSWORD, *_TOKEN and similar variables are
#     not visible. Composer gets a throwaway COMPOSER_HOME so the global
#     auth.json is not used. Note that the agent proxy itself authenticates
#     GitHub requests for this session's repositories, whatever the environment
#     holds, which is one more reason no install script may run.
#   - Never runs artisan, phpunit, migrations, deploy, SSH, or touches .env.
#     It may start the container's own MariaDB and create the local test DB.
#   - Idempotent: each install is skipped when its lockfile hash matches the
#     stamp left by the last successful install.
#   - A missing tool or a failed install logs and moves on; the session must
#     still start.
#
# Build-step exceptions (the only commands run after the installs):
#   1. frontend: `node ../scripts/sync-generated.mjs`. Repo-owned script (the
#      skipped `postinstall`); regenerates the git-ignored release-note
#      bundles (*.generated.js) that the app imports. Uses only node built-ins
#      and scripts/lib.
#   No native rebuild is needed: esbuild loads its prebuilt @esbuild/linux-x64
#   optional package without its postinstall (checked with `npm run build` and
#   `npm run test:unit`). husky (`prepare`) is skipped; git hooks are not used
#   in cloud sessions, and CI runs the same checks.
set -uo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"

log() { echo "[session-start] $*" >&2; }

lock_hash() { sha256sum "$1" | cut -d' ' -f1; }

up_to_date() { # $1=lockfile $2=stamp $3=install dir
  [ -d "$3" ] && [ -f "$2" ] && [ "$(cat "$2")" = "$(lock_hash "$1")" ]
}

# Run a command with no credentials in its environment.
CA="${SSL_CERT_FILE:-${CURL_CA_BUNDLE:-/etc/ssl/certs/ca-certificates.crt}}"
clean() {
  env -i PATH="$PATH" HOME="$HOME" LANG=C.UTF-8 TERM=dumb \
    SSL_CERT_FILE="$CA" CURL_CA_BUNDLE="${CURL_CA_BUNDLE:-$CA}" NODE_EXTRA_CA_CERTS="${NODE_EXTRA_CA_CERTS:-$CA}" \
    GIT_SSL_CAINFO="${GIT_SSL_CAINFO:-$CA}" GIT_TERMINAL_PROMPT=0 GIT_CONFIG_NOSYSTEM=1 \
    "$@"
}
# Same, plus the agent proxy (Packagist and GitHub are reached through it).
clean_proxied() {
  clean HTTPS_PROXY="${HTTPS_PROXY:-}" https_proxy="${https_proxy:-${HTTPS_PROXY:-}}" \
    HTTP_PROXY="${HTTP_PROXY:-}" http_proxy="${http_proxy:-${HTTP_PROXY:-}}" \
    NO_PROXY="${NO_PROXY:-}" no_proxy="${no_proxy:-${NO_PROXY:-}}" "$@"
}

COMPOSER_CACHE="${COMPOSER_CACHE_DIR:-$HOME/.cache/composer}"
COMPOSER_TMP_HOME="$(mktemp -d)"
trap 'rm -rf "$COMPOSER_TMP_HOME"' EXIT
composer_clean() {
  clean_proxied COMPOSER_HOME="$COMPOSER_TMP_HOME" COMPOSER_CACHE_DIR="$COMPOSER_CACHE" \
    COMPOSER_NO_INTERACTION=1 COMPOSER_ALLOW_SUPERUSER=1 composer "$@"
}

# Native Composer is the only dependency downloader. Refusals must not cause a
# second Git route or a hand-built cache entry. Only Composer versions exposing
# the source-fallback safety control are supported; older versions skip safely.
# Backend: composer install (no scripts, no plugins, no artisan).
BACKEND="$ROOT/backend"
if [ -f "$BACKEND/composer.lock" ]; then
  if ! command -v composer >/dev/null 2>&1 || ! command -v php >/dev/null 2>&1; then
    log "composer/php not found; skipping backend install"
  elif up_to_date "$BACKEND/composer.lock" "$BACKEND/vendor/.session-start.stamp" "$BACKEND/vendor"; then
    log "backend/vendor up to date"
  else
    log "composer install in backend/"
    if (cd "$BACKEND" &&
        composer_clean --no-plugins config --global source-fallback false >/dev/null 2>&1 &&
        [ "$(composer_clean --no-plugins config source-fallback 2>/dev/null)" = "false" ] &&
        composer_clean install \
          --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins >&2); then
      lock_hash "$BACKEND/composer.lock" > "$BACKEND/vendor/.session-start.stamp"
    else
      log "composer install unavailable or failed; no source fallback; continuing"
    fi
  fi
fi

# Frontend: npm ci without lifecycle scripts, then the listed exception.
# Preserve the managed runtime proxy and CA trust; no direct-network fallback.
FRONTEND="$ROOT/frontend"
if [ -f "$FRONTEND/package-lock.json" ]; then
  if ! command -v npm >/dev/null 2>&1; then
    log "npm not found; skipping frontend install"
  elif up_to_date "$FRONTEND/package-lock.json" "$FRONTEND/node_modules/.session-start.stamp" "$FRONTEND/node_modules"; then
    log "frontend/node_modules up to date"
  else
    log "npm ci --ignore-scripts in frontend/"
    if (cd "$FRONTEND" && clean_proxied npm ci --ignore-scripts --no-audit --no-fund >&2) \
       && (cd "$FRONTEND" && clean node ../scripts/sync-generated.mjs >&2); then
      lock_hash "$FRONTEND/package-lock.json" > "$FRONTEND/node_modules/.session-start.stamp"
    else
      log "frontend install failed; continuing"
    fi
  fi
fi

# Test database: CI runs PHPUnit against MySQL 8 (backend/phpunit.xml forces
# DB_CONNECTION=mysql, 127.0.0.1:3306, AllTrue_test, admin / ci-ephemeral; the
# password is CI-only and already committed there). When the container ships
# MariaDB, start it and create that throwaway local database and user so
# `php artisan test` works here. Local container only; never a remote DB.
if command -v mariadbd >/dev/null 2>&1 && command -v mysql >/dev/null 2>&1; then
  if ! clean mysqladmin --no-defaults --protocol=socket --socket=/run/mysqld/mysqld.sock ping --silent >/dev/null 2>&1; then
    (clean service mariadb start || clean service mysql start) >/dev/null 2>&1 || log "could not start local MariaDB"
  fi
  if clean mysqladmin --no-defaults --protocol=socket --socket=/run/mysqld/mysqld.sock ping --silent >/dev/null 2>&1; then
    if clean mysql --no-defaults --protocol=socket --socket=/run/mysqld/mysqld.sock --user=root <<'SQL'
CREATE DATABASE IF NOT EXISTS AllTrue_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'admin'@'localhost' IDENTIFIED BY 'ci-ephemeral';
CREATE USER IF NOT EXISTS 'admin'@'127.0.0.1' IDENTIFIED BY 'ci-ephemeral';
GRANT ALL PRIVILEGES ON AllTrue_test.* TO 'admin'@'localhost';
GRANT ALL PRIVILEGES ON AllTrue_test.* TO 'admin'@'127.0.0.1';
SQL
    then
      log "local test DB ready (MariaDB; CI uses MySQL 8, so CI stays the source of truth)"
    else
      log "could not provision AllTrue_test; local test DB readiness unknown"
    fi
  fi
fi

exit 0
