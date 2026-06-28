#!/usr/bin/env bash

mempalace_ingest_stage_lock() {
  if [ "${MEMPALACE_INGEST_NO_LOCK:-0}" = "1" ]; then
    echo "skipped_at=$(_mempalace_ts)" >"$(_mempalace_run_artifact lock.acquired)"
    mempalace_state_stage_done lock "no-lock mode"
    return 0
  fi

  export MEMPALACE_INGEST_LOCK_FILE="${MEMPALACE_INGEST_LOCK:-$MEMPALACE_PALACE_DIR/.ingest.lock}"
  mkdir -p "$(dirname "$MEMPALACE_INGEST_LOCK_FILE")"
  exec 9>"$MEMPALACE_INGEST_LOCK_FILE"

  if ! flock -n 9; then
    mempalace_log_stage_skip lock "already running lock=$MEMPALACE_INGEST_LOCK_FILE"
    export MEMPALACE_INGEST_ABORT=1
    return 0
  fi

  echo "$MEMPALACE_RUN_ID" >"$MEMPALACE_INGEST_LOCK_FILE.run_id"
  echo "acquired_at=$(_mempalace_ts)" >"$(_mempalace_run_artifact lock.acquired)"
  mempalace_state_stage_done lock "run_id=$MEMPALACE_RUN_ID"
}
