#!/usr/bin/env bash

mempalace_ingest_stage_preflight() {
  mempalace_require_cli
  mkdir -p "$MEMPALACE_PALACE_DIR"

  [ -w "$MEMPALACE_PALACE_DIR" ] || {
    mempalace_log_stage_fail preflight "palace not writable: $MEMPALACE_PALACE_DIR"
    return 1
  }

  local sources=0
  [ -d "$TRANSCRIPT_DIR" ] && sources=$((sources + 1))
  [ -d "$MEMPALACE_DOCS_DIR" ] && sources=$((sources + 1))
  [ "$sources" -gt 0 ] || {
    mempalace_log_stage_fail preflight "no source directories found"
    return 1
  }

  local cli_version="$("$MEMPALACE" --version 2>&1 | tail -1)"
  {
    echo "cli_version=$cli_version"
    echo "palace=$MEMPALACE_PALACE_DIR"
    echo "transcript_dir=$TRANSCRIPT_DIR"
    echo "docs_dir=$MEMPALACE_DOCS_DIR"
    echo "wing_sessions=$MEMPALACE_WING_SESSIONS"
    echo "wing_docs=$MEMPALACE_WING_DOCS"
    echo "sources_found=$sources"
  } >"$(_mempalace_run_artifact preflight.env)"

  mempalace_state_stage_done preflight "cli=$cli_version sources=$sources"
}
