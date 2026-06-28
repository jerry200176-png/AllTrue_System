#!/usr/bin/env bash
# Single source of truth for AllTrue MemPalace paths and wing names.
# Sourced by: mempalace-ingest.sh, mempalace-maintain.sh, post-merge hook
#
# Override via environment: MEMPALACE, TRANSCRIPT_DIR, MEMPALACE_PALACE_DIR

export MEMPALACE_WING_SESSIONS="${MEMPALACE_WING_SESSIONS:-alltrue-sessions}"
export MEMPALACE_WING_DOCS="${MEMPALACE_WING_DOCS:-alltrue-docs}"
export MEMPALACE_WING_CODE="${MEMPALACE_WING_CODE:-alltrue-code}"

export MEMPALACE="${MEMPALACE:-$HOME/.local/bin/mempalace}"
export MEMPALACE_PALACE_DIR="${MEMPALACE_PALACE_DIR:-$HOME/.mempalace/palace}"

_mempalace_repo_root() {
  if [ -n "${MEMPALACE_REPO_ROOT:-}" ]; then
    echo "$MEMPALACE_REPO_ROOT"
    return
  fi
  local here="${BASH_SOURCE[0]:-}"
  if [ -n "$here" ] && [ -f "$here" ]; then
    cd "$(dirname "$here")/.." && pwd
    return
  fi
  git rev-parse --show-toplevel 2>/dev/null || pwd
}

if [ -z "${TRANSCRIPT_DIR:-}" ]; then
  _repo="$(_mempalace_repo_root)"
  _slug="$(basename "$_repo" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9._-]/-/g')"
  export TRANSCRIPT_DIR="$HOME/.cursor/projects/home-${USER}-${_slug}/agent-transcripts"
  if [ ! -d "$TRANSCRIPT_DIR" ] && [ -d "$HOME/.cursor/projects/home-jerry-alltrue/agent-transcripts" ]; then
    export TRANSCRIPT_DIR="$HOME/.cursor/projects/home-jerry-alltrue/agent-transcripts"
  fi
fi

export MEMPALACE_REPO_ROOT="${MEMPALACE_REPO_ROOT:-$(_mempalace_repo_root)}"
export MEMPALACE_DOCS_DIR="${MEMPALACE_DOCS_DIR:-$MEMPALACE_REPO_ROOT/docs}"

mempalace_require_cli() {
  if ! command -v "$MEMPALACE" >/dev/null 2>&1; then
    echo "❌ mempalace not found at $MEMPALACE"
    echo "   Prerequisite: uv tool install mempalace  (or pipx install mempalace)"
    exit 1
  fi
}

mempalace_warn_orphan_wings() {
  command -v "$MEMPALACE" >/dev/null 2>&1 || return 0
  local count
  count=$("$MEMPALACE" status 2>/dev/null | grep -cE '^  WING: [0-9a-f]{8}-' || true)
  if [ "${count:-0}" -gt 0 ]; then
    echo "⚠️  MemPalace: $count UUID-named wing(s) — run wing consolidation (#997)"
  fi
}
