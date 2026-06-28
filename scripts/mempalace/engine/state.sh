#!/usr/bin/env bash
# Run lifecycle init — state derived from event log (events.jsonl).

mempalace_state_init() {
  export MEMPALACE_RUN_ROOT="${MEMPALACE_RUN_ROOT:-$MEMPALACE_PALACE_DIR/.ingest-run}"
  mkdir -p "$MEMPALACE_RUN_ROOT"

  if [ "${MEMPALACE_INGEST_RESUME:-0}" = "1" ] && [ -f "$MEMPALACE_RUN_ROOT/current/run_id" ]; then
    export MEMPALACE_RUN_ID="$(cat "$MEMPALACE_RUN_ROOT/current/run_id")"
  else
    export MEMPALACE_RUN_ID="${MEMPALACE_RUN_ID:-$(date -u '+%Y%m%dT%H%M%SZ')-$$}"
  fi

  export MEMPALACE_RUN_DIR="$MEMPALACE_RUN_ROOT/runs/$MEMPALACE_RUN_ID"
  mkdir -p "$MEMPALACE_RUN_DIR"
  mkdir -p "$MEMPALACE_RUN_ROOT/current"
  echo "$MEMPALACE_RUN_ID" >"$MEMPALACE_RUN_ROOT/current/run_id"

  mempalace_events_init
}

# Backward-compat aliases — emit events instead of touching .done files
mempalace_state_stage_done() {
  local stage="$1"
  local detail="${2:-}"
  mempalace_event_stage_completed "$stage" "$detail"
  mempalace_log_stage_ok "$stage" "$detail"
}

mempalace_state_stage_failed() {
  local stage="$1"
  local reason="${2:-unknown}"
  mempalace_event_stage_failed "$stage" "$reason"
  echo "$stage" >"$MEMPALACE_RUN_ROOT/current/failed_stage"
}

mempalace_state_stage_completed() {
  local stage="$1"
  mempalace_events_stage_completed "$stage"
}

mempalace_state_mark_success() {
  local detail="${1:-run_id=$MEMPALACE_RUN_ID}"
  rm -f "$MEMPALACE_RUN_ROOT/current/failed_stage"
  mempalace_event_run_completed "$detail"
  echo "$MEMPALACE_RUN_ID" >"$MEMPALACE_RUN_ROOT/current/last_success_run_id"
}

mempalace_state_should_run_node() {
  local node="$1"
  local _plan_file="${2:-}"

  if [ "${MEMPALACE_INGEST_RESUME:-0}" = "1" ] && mempalace_events_stage_completed "$node"; then
    return 1
  fi
  return 0
}
