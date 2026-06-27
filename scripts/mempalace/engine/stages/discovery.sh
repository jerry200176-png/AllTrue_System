#!/usr/bin/env bash

mempalace_ingest_stage_discovery() {
  local transcript_files="$(_mempalace_count_glob "$TRANSCRIPT_DIR" '*.jsonl')"
  local docs_files="$(_mempalace_count_glob "$MEMPALACE_DOCS_DIR" '*.md')"

  cat >"$(_mempalace_run_artifact manifest.raw.json)" <<EOF
{
  "run_id": "$MEMPALACE_RUN_ID",
  "discovered_at": "$(_mempalace_ts)",
  "sources": {
    "sessions": {
      "path": "$TRANSCRIPT_DIR",
      "exists": $([ -d "$TRANSCRIPT_DIR" ] && echo true || echo false),
      "file_count": $transcript_files,
      "glob": "*.jsonl"
    },
    "docs": {
      "path": "$MEMPALACE_DOCS_DIR",
      "exists": $([ -d "$MEMPALACE_DOCS_DIR" ] && echo true || echo false),
      "file_count": $docs_files,
      "glob": "*.md"
    }
  }
}
EOF

  mempalace_state_stage_done discovery "sessions=$transcript_files docs=$docs_files"
}
