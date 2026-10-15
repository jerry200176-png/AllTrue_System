#!/usr/bin/env bash
# SessionStart hook for Claude Code cloud sessions.
# Installs backend (composer) and frontend (npm) dependencies so tests and lint
# can run in the cloud container.
#
# Safety contract (CLAUDE.md R1-R6):
#   - Cloud only: exits immediately unless CLAUDE_CODE_REMOTE=true.
#   - Installs packages only. Never runs artisan, phpunit, migrations, deploy,
#     SSH, or anything against production. composer runs with --no-scripts so
#     no artisan hook (e.g. package:discover) can fire.
#   - Reads no secrets and never touches .env files.
#   - Idempotent: each install is skipped when its lockfile hash matches the
#     stamp left by the last successful install.
#   - A missing tool or a failed install prints a note and moves on; the
#     session must still start.
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

# The cloud proxy answers 403 for api.github.com zipball downloads. Composer
# falls back to a git clone for packages that list a source, but a dist-only
# package (phpstan/phpstan) has no fallback and fails the whole install. For
# each such package, fetch the commit over git and place the zip in composer's
# files cache under the key composer looks up (sha1 of the dist URL).
seed_github_dists() { # $1=composer.lock
  command -v git >/dev/null 2>&1 || return 0
  local cache; cache="$(composer config --global cache-files-dir 2>/dev/null)" || return 0
  [ -n "$cache" ] || return 0
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
    curl -sfI -o /dev/null "$url" && continue   # direct download works
    log "seeding composer cache for $name from git"
    local tmp; tmp="$(mktemp -d)"
    if git -C "$tmp" init -q && git -C "$tmp" fetch -q --depth 1 "https://github.com/$repo.git" "$ref" \
       && mkdir -p "$cache/$name" \
       && git -C "$tmp" archive --format=zip --prefix="${repo/\//-}-${ref:0:7}/" -o "$dest" FETCH_HEAD; then
      :
    else
      rm -f "$dest"; log "could not seed $name; composer may fail"
    fi
    rm -rf "$tmp"
  done
}

# Backend: composer install (no scripts, no artisan).
BACKEND="$ROOT/backend"
if [ -f "$BACKEND/composer.lock" ]; then
  if ! command -v composer >/dev/null 2>&1 || ! command -v php >/dev/null 2>&1; then
    log "composer/php not found; skipping backend install"
  elif up_to_date "$BACKEND/composer.lock" "$BACKEND/vendor/.session-start.stamp" "$BACKEND/vendor"; then
    log "backend/vendor up to date"
  else
    seed_github_dists "$BACKEND/composer.lock"
    log "composer install in backend/"
    if (cd "$BACKEND" && COMPOSER_NO_INTERACTION=1 composer install \
          --no-interaction --no-progress --prefer-dist --no-scripts >&2); then
      lock_hash "$BACKEND/composer.lock" > "$BACKEND/vendor/.session-start.stamp"
    else
      log "composer install failed; continuing"
    fi
  fi
fi

# Frontend: npm ci.
FRONTEND="$ROOT/frontend"
if [ -f "$FRONTEND/package-lock.json" ]; then
  if ! command -v npm >/dev/null 2>&1; then
    log "npm not found; skipping frontend install"
  elif up_to_date "$FRONTEND/package-lock.json" "$FRONTEND/node_modules/.session-start.stamp" "$FRONTEND/node_modules"; then
    log "frontend/node_modules up to date"
  else
    log "npm ci in frontend/"
    if (cd "$FRONTEND" && npm ci --no-audit --no-fund >&2); then
      lock_hash "$FRONTEND/package-lock.json" > "$FRONTEND/node_modules/.session-start.stamp"
    else
      log "npm ci failed; continuing"
    fi
  fi
fi

exit 0
