#!/usr/bin/env bash

mempalace_ingest_stage_verify() {
  if _mempalace_is_dry_run; then
    mempalace_state_stage_done verify "dry-run"
    mempalace_state_mark_success "dry-run"
    return 0
  fi

  local status_file="$(_mempalace_run_artifact post-verify.status.txt)"
  "$MEMPALACE" status >"$status_file" 2>&1 || {
    mempalace_log_stage_fail verify "mempalace status failed"
    return 1
  }

  local drawer_count
  drawer_count="$(grep -E '^  MemPalace Status' "$status_file" | sed -n 's/.*— \([0-9]*\) drawers.*/\1/p')"
  drawer_count="${drawer_count:-unknown}"

  mempalace_warn_orphan_wings

  {
    echo "verified_at=$(_mempalace_ts)"
    echo "drawer_count=$drawer_count"
    echo "run_id=$MEMPALACE_RUN_ID"
  } >"$(_mempalace_run_artifact verify.env)"

  mempalace_state_stage_done verify "drawers=$drawer_count"
  mempalace_state_mark_success
}
