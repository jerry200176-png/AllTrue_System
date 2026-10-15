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

# The cloud proxy answers 403 for api.github.com zipball downloads. Composer
# falls back to a git clone for packages that list a source, but a dist-only
# package (phpstan/phpstan) has no fallback and fails the whole install. For
# each such package, fetch the commit over git (public repo, clean env, no
# credential helper) and place the zip in composer's files cache under the key
# composer looks up (sha1 of the dist URL).
seed_github_dists() { # $1=composer.lock
  command -v git >/dev/null 2>&1 || return 0
  local cache="$COMPOSER_CACHE/files"
  python3 -I - "$1" <<'PY' | while read -r name url repo ref; do
import json, re, sys
lock = json.load(open(sys.argv[1]))
for p in lock.get("packages", []) + lock.get("packages-dev", []):
    d = p.get("dist") or {}
    m = re.match(r"https://api\.github\.com/repos/([^/]+/[^/]+)/zipball/([0-9a-f]{40})$", d.get("url", ""))
    if m and not p.get("source"):
        print(p["name"], d["url"], m.group(1), m.group(2))
PY
    local dest="$cache/$name/$(printf %s "$url" | sha1sum | cut -d' ' -f1).zip"
    [ -s "$dest" ] && continue
    clean_proxied curl -sfI -o /dev/null "$url" && continue   # direct download works
    log "seeding composer cache for $name from git"
    local tmp; tmp="$(mktemp -d)"
    if clean_proxied git -C "$tmp" init -q \
       && clean_proxied git -C "$tmp" -c credential.helper= fetch -q --depth 1 "https://github.com/$repo.git" "$ref" \
       && mkdir -p "$cache/$name" \
       && clean git -C "$tmp" archive --format=zip --prefix="${repo/\//-}-${ref:0:7}/" -o "$dest" FETCH_HEAD; then
      :
    else
      rm -f "$dest"; log "could not seed $name; composer may fail"
    fi
    rm -rf "$tmp"
  done
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

exit 0
