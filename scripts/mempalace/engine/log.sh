#!/usr/bin/env bash
# Structured logging for MemPalace ingest pipeline.

mempalace_log_init() {
  export MEMPALACE_LOG_FILE="${MEMPALACE_LOG_FILE:-${MEMPALACE_RUN_DIR:-}/ingest.log}"
  mkdir -p "$(dirname "$MEMPALACE_LOG_FILE")"
}

mempalace_log() {
  local level="$1"
  local stage="$2"
  local message="$3"
  local ts
  ts="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  local line="[$ts] [ingest] [level=$level] [stage=$stage] $message"
  echo "$line"
  if [ -n "${MEMPALACE_LOG_FILE:-}" ]; then
    echo "$line" >>"$MEMPALACE_LOG_FILE"
  fi
}

mempalace_log_stage_start() {
  mempalace_log "INFO" "$1" "status=start"
}

mempalace_log_stage_ok() {
  mempalace_log "INFO" "$1" "status=ok ${2:-}"
}

mempalace_log_stage_skip() {
  mempalace_log "INFO" "$1" "status=skip ${2:-}"
}

mempalace_log_stage_fail() {
  mempalace_log "ERROR" "$1" "status=fail ${2:-}"
}
