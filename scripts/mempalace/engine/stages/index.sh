#!/usr/bin/env bash

mempalace_ingest_stage_index() {
  if _mempalace_is_dry_run; then
    _mempalace_dry_run_done index index.env
    return 0
  fi

  local chroma_before=0 chroma_after=0
  [ -f "$MEMPALACE_PALACE_DIR/chroma.sqlite3" ] && chroma_before="$(stat -c '%Y' "$MEMPALACE_PALACE_DIR/chroma.sqlite3")"

  if [ -d "$MEMPALACE_DOCS_DIR" ]; then
    _mempalace_mine_docs || {
      mempalace_log_stage_fail index "docs mine failed"
      return 1
    }
  else
    mempalace_log_stage_skip index "docs dir missing"
  fi

  [ -f "$MEMPALACE_PALACE_DIR/chroma.sqlite3" ] && chroma_after="$(stat -c '%Y' "$MEMPALACE_PALACE_DIR/chroma.sqlite3")"

  {
    echo "chroma_before=$chroma_before"
    echo "chroma_after=$chroma_after"
    echo "index_touched=$([ "$chroma_after" != "$chroma_before" ] && echo 1 || echo 0)"
  } >"$(_mempalace_run_artifact index.env)"

  local touched=$([ "$chroma_after" != "$chroma_before" ] && echo yes || echo no)
  mempalace_state_stage_done index "wing=$MEMPALACE_WING_DOCS index_touched=$touched"
}
