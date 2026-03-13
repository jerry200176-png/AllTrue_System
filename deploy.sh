#!/usr/bin/env bash

set -Eeuo pipefail

timestamp() {
  date "+%Y-%m-%d %H:%M:%S"
}

log() {
  echo "[$(timestamp)] [DEPLOY] $*"
}

fail() {
  echo "[$(timestamp)] [ERROR] $*" >&2
  exit 1
}

on_error() {
  local exit_code="$1"
  local line_no="$2"
  fail "Deployment failed at line ${line_no} (exit code: ${exit_code})"
}

trap 'on_error "$?" "$LINENO"' ERR

run_step() {
  local msg="$1"
  shift
  log "$msg"
  "$@"
}

has_cmd() {
  command -v "$1" >/dev/null 2>&1
}

require_cmd() {
  for cmd in "$@"; do
    has_cmd "$cmd" || fail "Required command not found: ${cmd}"
  done
}

service_cmd() {
  local action="$1"
  local service="$2"

  if has_cmd systemctl; then
    if systemctl "${action}" "${service}" >/dev/null 2>&1; then
      systemctl "${action}" "${service}"
      return 0
    fi

    if has_cmd sudo && sudo -n true >/dev/null 2>&1; then
      sudo systemctl "${action}" "${service}"
      return 0
    fi
  fi

  return 1
}

docker_restart_fallback() {
  local compose_file="${1}"
  if has_cmd docker && has_cmd docker-compose; then
    docker-compose -f "${compose_file}" restart app nginx
    return 0
  fi

  if has_cmd docker; then
    docker compose -f "${compose_file}" restart app nginx
    return 0
  fi

  return 1
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-${SCRIPT_DIR}}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
BACKEND_DIR="${BACKEND_DIR:-${APP_DIR}/backend}"
FRONTEND_DIR="${FRONTEND_DIR:-${APP_DIR}/frontend}"
COMPOSE_FILE="${COMPOSE_FILE:-${APP_DIR}/docker-compose.yml}"
NGINX_SERVICE="${NGINX_SERVICE:-nginx}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.2-fpm}"

log "Starting deployment"
log "APP_DIR=${APP_DIR}"
log "DEPLOY_BRANCH=${DEPLOY_BRANCH}"

[[ -d "${APP_DIR}" ]] || fail "APP_DIR does not exist: ${APP_DIR}"
[[ -d "${BACKEND_DIR}" ]] || fail "BACKEND_DIR does not exist: ${BACKEND_DIR}"
[[ -d "${FRONTEND_DIR}" ]] || fail "FRONTEND_DIR does not exist: ${FRONTEND_DIR}"

require_cmd git composer php npm

run_step "Updating source code from Git (branch: ${DEPLOY_BRANCH})" bash -lc "cd '${APP_DIR}' && git fetch origin '${DEPLOY_BRANCH}' && git checkout '${DEPLOY_BRANCH}' && git pull --ff-only origin '${DEPLOY_BRANCH}'"

run_step "Installing backend dependencies (composer --no-dev)" bash -lc "cd '${BACKEND_DIR}' && composer install --no-dev --optimize-autoloader --no-interaction"
run_step "Running database migrations" bash -lc "cd '${BACKEND_DIR}' && php artisan migrate --force"

run_step "Installing frontend dependencies (npm install)" bash -lc "cd '${FRONTEND_DIR}' && npm install"
run_step "Building frontend assets (npm run build)" bash -lc "cd '${FRONTEND_DIR}' && npm run build"

if [[ -d "${FRONTEND_DIR}/dist" ]]; then
  run_step "Syncing frontend dist to backend/public" bash -lc "mkdir -p '${BACKEND_DIR}/public' && if command -v rsync >/dev/null 2>&1; then rsync -a --delete '${FRONTEND_DIR}/dist/' '${BACKEND_DIR}/public/'; else cp -a '${FRONTEND_DIR}/dist/.' '${BACKEND_DIR}/public/'; fi"
fi

if service_cmd reload "${NGINX_SERVICE}"; then
  log "Nginx reloaded: ${NGINX_SERVICE}"
else
  if [[ -f "${COMPOSE_FILE}" ]] && docker_restart_fallback "${COMPOSE_FILE}"; then
    log "Nginx/PHP restarted via Docker Compose fallback"
  else
    fail "Unable to reload nginx service (${NGINX_SERVICE}) and no Docker fallback available"
  fi
fi

if service_cmd restart "${PHP_FPM_SERVICE}"; then
  log "PHP-FPM restarted: ${PHP_FPM_SERVICE}"
else
  if [[ -f "${COMPOSE_FILE}" ]] && docker_restart_fallback "${COMPOSE_FILE}"; then
    log "PHP/Nginx restarted via Docker Compose fallback"
  else
    fail "Unable to restart php-fpm service (${PHP_FPM_SERVICE}) and no Docker fallback available"
  fi
fi

log "Deployment completed successfully"
