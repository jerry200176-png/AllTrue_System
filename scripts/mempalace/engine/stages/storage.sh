#!/usr/bin/env bash

mempalace_ingest_stage_storage() {
  _mempalace_require_artifact manifest.normalized.json storage || return 1

  if _mempalace_is_dry_run; then
    _mempalace_dry_run_done storage storage.sessions.done
    return 0
  fi

  if [ -d "$TRANSCRIPT_DIR" ]; then
    _mempalace_mine_sessions || {
      mempalace_log_stage_fail storage "sessions mine failed"
      return 1
    }
  else
    mempalace_log_stage_skip storage "transcript dir missing"
  fi

  echo "completed_at=$(_mempalace_ts)" >"$(_mempalace_run_artifact storage.sessions.done)"
  mempalace_state_stage_done storage "wing=$MEMPALACE_WING_SESSIONS"
}
