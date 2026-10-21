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
CA="${SSL_CERT_FILE:-/etc/ssl/certs/ca-certificates.crt}"
clean() {
  env -i PATH="$PATH" HOME="$HOME" LANG=C.UTF-8 TERM=dumb \
    SSL_CERT_FILE="$CA" CURL_CA_BUNDLE="$CA" NODE_EXTRA_CA_CERTS="${NODE_EXTRA_CA_CERTS:-$CA}" \
    GIT_SSL_CAINFO="$CA" GIT_TERMINAL_PROMPT=0 GIT_CONFIG_NOSYSTEM=1 \
    NODE_OPTIONS="${NODE_OPTIONS:-}" "$@"
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

# The cloud proxy answers 403 for api.github.com zipball downloads (every
# package in composer.lock uses one). Composer then falls back to one
# `git clone` per package (slow: ~8 min cold) and a dist-only package
# (phpstan/phpstan) has no fallback, which fails the whole install. So, when a
# direct download is refused, fetch each locked commit over git (public repos,
# clean env, no credential helper), `git archive` it (honours export-ignore,
# like GitHub's zipball) and place the zip in composer's files cache under the
# key composer looks up (<name>/sha1(dist url).zip). Composer then installs
# every package from cache with --prefer-dist, the same layout CI gets.
seed_one() { # name url repo ref
  local name="$1" url="$2" repo="$3" ref="$4"
  local dest="$COMPOSER_CACHE/files/$name/$(printf %s "$url" | sha1sum | cut -d' ' -f1).zip"
  local tmp; tmp="$(mktemp -d)"
  if clean git -C "$tmp" init -q \
     && clean_proxied git -C "$tmp" -c credential.helper= fetch -q --depth 1 "https://github.com/$repo.git" "$ref" \
     && mkdir -p "$(dirname "$dest")" \
     && clean git -C "$tmp" archive --format=zip --prefix="${repo/\//-}-${ref:0:7}/" -o "$dest.part" FETCH_HEAD \
     && mv "$dest.part" "$dest"; then
    :
  else
    rm -f "$dest.part"; log "could not seed $name; composer will try its own fallback"
  fi
  rm -rf "$tmp"
}

seed_github_dists() { # $1=composer.lock
  command -v git >/dev/null 2>&1 || return 0
  local todo
  todo="$(python3 -I - "$1" "$COMPOSER_CACHE/files" <<'PY'
import hashlib, json, os, re, sys
PKG = re.compile(r"^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$")
GH = re.compile(r"^https://github\.com/[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*(\.git)?$")
lock = json.load(open(sys.argv[1]))
for p in lock.get("packages", []) + lock.get("packages-dev", []):
    url = (p.get("dist") or {}).get("url", "")
    m = re.match(r"https://api\.github\.com/repos/([^/]+/[^/]+)/zipball/([0-9a-f]{40})$", url)
    if not m:
        continue
    name = p.get("name", "")
    repo = m.group(1)
    if not PKG.match(name) or not GH.match("https://github.com/" + repo + ".git"):
        print("session-start: skipping invalid composer.lock entry (name or repo URL)", file=sys.stderr)
        continue
    dest = os.path.join(sys.argv[2], p["name"], hashlib.sha1(url.encode()).hexdigest() + ".zip")
    if not os.path.isfile(dest) or os.path.getsize(dest) == 0:
        print(p["name"], url, m.group(1), m.group(2))
PY
)" || return 0
  [ -n "$todo" ] || return 0
  # One probe: if the proxy allows zipballs in this session, let composer download.
  clean_proxied curl -sfI -o /dev/null "$(echo "$todo" | head -n1 | cut -d' ' -f2)" && return 0
  log "seeding composer cache from git for $(echo "$todo" | wc -l) packages"
  local name url repo ref
  while read -r name url repo ref; do
    while [ "$(jobs -rp | wc -l)" -ge 8 ]; do wait -n; done
    seed_one "$name" "$url" "$repo" "$ref" &
  done <<<"$todo"
  wait
}

# Backend: composer install (no scripts, no plugins, no artisan).
BACKEND="$ROOT/backend"
if [ -f "$BACKEND/composer.lock" ]; then
  if ! command -v composer >/dev/null 2>&1 || ! command -v php >/dev/null 2>&1; then
    log "composer/php not found; skipping backend install"
  elif up_to_date "$BACKEND/composer.lock" "$BACKEND/vendor/.session-start.stamp" "$BACKEND/vendor"; then
    log "backend/vendor up to date"
  else
    seed_github_dists "$BACKEND/composer.lock"
    log "composer install in backend/"
    if (cd "$BACKEND" && composer_clean install \
          --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins >&2); then
      lock_hash "$BACKEND/composer.lock" > "$BACKEND/vendor/.session-start.stamp"
    else
      log "composer install failed; continuing"
    fi
  fi
fi

# Frontend: npm ci without lifecycle scripts, then the listed exception.
# registry.npmjs.org is reached directly (it is in NO_PROXY), so npm gets no
# proxy variables.
FRONTEND="$ROOT/frontend"
if [ -f "$FRONTEND/package-lock.json" ]; then
  if ! command -v npm >/dev/null 2>&1; then
    log "npm not found; skipping frontend install"
  elif up_to_date "$FRONTEND/package-lock.json" "$FRONTEND/node_modules/.session-start.stamp" "$FRONTEND/node_modules"; then
    log "frontend/node_modules up to date"
  else
    log "npm ci --ignore-scripts in frontend/"
    if (cd "$FRONTEND" && clean npm ci --ignore-scripts --no-audit --no-fund >&2) \
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
  if ! mysqladmin ping --silent >/dev/null 2>&1; then
    (service mariadb start || service mysql start) >/dev/null 2>&1 || log "could not start local MariaDB"
  fi
  if mysqladmin ping --silent >/dev/null 2>&1; then
    mysql -u root <<'SQL' || log "could not provision AllTrue_test"
CREATE DATABASE IF NOT EXISTS AllTrue_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'admin'@'localhost' IDENTIFIED BY 'ci-ephemeral';
CREATE USER IF NOT EXISTS 'admin'@'127.0.0.1' IDENTIFIED BY 'ci-ephemeral';
GRANT ALL PRIVILEGES ON AllTrue_test.* TO 'admin'@'localhost';
GRANT ALL PRIVILEGES ON AllTrue_test.* TO 'admin'@'127.0.0.1';
SQL
    log "local test DB ready (MariaDB; CI uses MySQL 8, so CI stays the source of truth)"
  fi
fi

exit 0
