#!/usr/bin/env bash

mempalace_ingest_stage_mining() {
  _mempalace_require_artifact manifest.raw.json mining || return 1

  local plan="$(_mempalace_run_artifact mining.plan.env)"
  {
    echo "sessions_enabled=$([ -d "$TRANSCRIPT_DIR" ] && echo 1 || echo 0)"
    echo "docs_enabled=$([ -d "$MEMPALACE_DOCS_DIR" ] && echo 1 || echo 0)"
    echo "sessions_cmd=$MEMPALACE mine $TRANSCRIPT_DIR --mode convos --wing $MEMPALACE_WING_SESSIONS"
    echo "docs_cmd=$MEMPALACE mine $MEMPALACE_DOCS_DIR --wing $MEMPALACE_WING_DOCS"
  } >"$plan"

  local detail="plan=$plan"
  _mempalace_is_dry_run && detail="dry-run $detail"
  mempalace_state_stage_done mining "$detail"
}
