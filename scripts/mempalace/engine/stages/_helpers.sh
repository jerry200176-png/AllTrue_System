#!/usr/bin/env bash
# Shared helpers for stage handlers only (Ponytail-permitted utilities).

_mempalace_ts() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

_mempalace_run_artifact() {
  echo "$MEMPALACE_RUN_DIR/$1"
}

_mempalace_require_artifact() {
  local file="$1" stage="$2"
  [ -f "$(_mempalace_run_artifact "$file")" ] || {
    mempalace_log_stage_fail "$stage" "$file missing"
    return 1
  }
}

_mempalace_is_dry_run() {
  [ "${MEMPALACE_INGEST_DRY_RUN:-0}" = "1" ]
}

_mempalace_dry_run_done() {
  local stage="$1" artifact="$2" detail="${3:-dry-run}"
  echo "dry_run=1" >"$(_mempalace_run_artifact "$artifact")"
  mempalace_log_stage_skip "$stage" "$detail"
  mempalace_state_stage_done "$stage" "$detail"
}

_mempalace_count_glob() {
  local dir="$1" pattern="$2"
  [ -d "$dir" ] || { echo 0; return; }
  find "$dir" -type f -name "$pattern" 2>/dev/null | wc -l | tr -d ' '
}

_mempalace_mine_sessions() {
  echo "→ mine sessions → wing $MEMPALACE_WING_SESSIONS"
  "$MEMPALACE" mine "$TRANSCRIPT_DIR" --mode convos --wing "$MEMPALACE_WING_SESSIONS"
}

_mempalace_mine_docs() {
  echo "→ mine docs → wing $MEMPALACE_WING_DOCS"
  "$MEMPALACE" mine "$MEMPALACE_DOCS_DIR" --wing "$MEMPALACE_WING_DOCS"
}
