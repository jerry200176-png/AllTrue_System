#!/usr/bin/env bash

mempalace_ingest_stage_normalization() {
  _mempalace_require_artifact manifest.raw.json normalization || return 1

  cat >"$(_mempalace_run_artifact manifest.normalized.json)" <<EOF
{
  "run_id": "$MEMPALACE_RUN_ID",
  "normalized_at": "$(_mempalace_ts)",
  "wings": {
    "sessions": "$MEMPALACE_WING_SESSIONS",
    "docs": "$MEMPALACE_WING_DOCS"
  },
  "operations": [
    {
      "id": "sessions",
      "enabled": $([ -d "$TRANSCRIPT_DIR" ] && echo true || echo false),
      "source": "$TRANSCRIPT_DIR",
      "mode": "convos",
      "wing": "$MEMPALACE_WING_SESSIONS"
    },
    {
      "id": "docs",
      "enabled": $([ -d "$MEMPALACE_DOCS_DIR" ] && echo true || echo false),
      "source": "$MEMPALACE_DOCS_DIR",
      "mode": "projects",
      "wing": "$MEMPALACE_WING_DOCS"
    }
  ]
}
EOF

  mempalace_state_stage_done normalization "manifest=$(_mempalace_run_artifact manifest.normalized.json)"
}
