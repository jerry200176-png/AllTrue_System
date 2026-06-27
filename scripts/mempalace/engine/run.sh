#!/usr/bin/env bash
# MemPalace event-sourced DAG ingestion engine.

set -euo pipefail

ENGINE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPTS_DIR="$(cd "$ENGINE_DIR/../.." && pwd)"
export ENGINE_DIR

# shellcheck source=../mempalace-config.sh
source "$SCRIPTS_DIR/mempalace-config.sh"
# shellcheck source=log.sh
source "$ENGINE_DIR/log.sh"
# shellcheck source=events.sh
source "$ENGINE_DIR/events.sh"
# shellcheck source=state.sh
source "$ENGINE_DIR/state.sh"
# shellcheck source=dag.sh
source "$ENGINE_DIR/dag.sh"
# shellcheck source=runner.sh
source "$ENGINE_DIR/runner.sh"

# shellcheck source=stages/_helpers.sh
source "$ENGINE_DIR/stages/_helpers.sh"
for stage_file in "$ENGINE_DIR"/stages/*.sh; do
  [[ "$(basename "$stage_file")" == "_helpers.sh" ]] && continue
  # shellcheck source=/dev/null
  source "$stage_file"
done

mempalace_engine_usage() {
  cat <<EOF
Usage: bash scripts/mempalace-ingest.sh [options]

Event-sourced DAG ingestion. State = events.jsonl (append-only).
Pipeline manifest: scripts/mempalace/engine/pipeline.manifest.json

Options:
  --stage NAME       Run one node only
  --from-stage NAME  Run node and downstream dependents
  --resume           Skip stages with stage_completed in event log
  --replay [RUN_ID]  Reconstruct run state from event log (no execution)
  --dry-run          Plan only; no mempalace mine writes
  --no-lock          Skip flock (node testing)
  --force            Ignore missing input artifacts
  --list-stages      Print topologically sorted node names
  --show-plan        Print derived execution plan and exit
  --help             Show this help

Events: run_started, stage_started, stage_completed, stage_failed, stage_skipped,
         run_completed, run_failed, run_aborted

Recovery:
  bash scripts/mempalace-ingest.sh --resume
  bash scripts/mempalace-ingest.sh --replay
  Event log: ~/.mempalace/palace/.ingest-run/runs/<run_id>/events.jsonl
EOF
}

mempalace_engine_parse_args() {
  export MEMPALACE_INGEST_STAGE_ONLY=""
  export MEMPALACE_INGEST_FROM_STAGE=""
  export MEMPALACE_INGEST_RESUME=0
  export MEMPALACE_INGEST_DRY_RUN=0
  export MEMPALACE_INGEST_NO_LOCK=0
  export MEMPALACE_INGEST_FORCE=0
  export MEMPALACE_INGEST_SHOW_PLAN=0
  export MEMPALACE_INGEST_REPLAY=""
  export MEMPALACE_INGEST_REPLAY_ONLY=0

  while [ $# -gt 0 ]; do
    case "$1" in
      --stage)
        MEMPALACE_INGEST_STAGE_ONLY="${2:?--stage requires name}"
        shift 2
        ;;
      --from-stage)
        MEMPALACE_INGEST_FROM_STAGE="${2:?--from-stage requires name}"
        shift 2
        ;;
      --resume) MEMPALACE_INGEST_RESUME=1; shift ;;
      --replay)
        if [ "${2:-}" != "" ] && [[ "${2:-}" != --* ]]; then
          MEMPALACE_INGEST_REPLAY="$2"
          shift 2
        else
          MEMPALACE_INGEST_REPLAY="__current__"
          shift
        fi
        MEMPALACE_INGEST_REPLAY_ONLY=1
        ;;
      --dry-run) MEMPALACE_INGEST_DRY_RUN=1; shift ;;
      --no-lock) MEMPALACE_INGEST_NO_LOCK=1; shift ;;
      --force) MEMPALACE_INGEST_FORCE=1; shift ;;
      --show-plan) MEMPALACE_INGEST_SHOW_PLAN=1; shift ;;
      --list-stages)
        mempalace_dag_validate_manifest
        mempalace_dag_list_nodes
        exit 0
        ;;
      --help|-h)
        mempalace_engine_usage
        exit 0
        ;;
      *)
        echo "Unknown option: $1" >&2
        mempalace_engine_usage >&2
        exit 1
        ;;
    esac
  done
}

mempalace_engine_main() {
  mempalace_engine_parse_args "$@"
  mempalace_dag_validate_manifest

  export MEMPALACE_RUN_ROOT="${MEMPALACE_RUN_ROOT:-$MEMPALACE_PALACE_DIR/.ingest-run}"

  if [ "${MEMPALACE_INGEST_REPLAY_ONLY:-0}" = "1" ]; then
    local rid="${MEMPALACE_INGEST_REPLAY}"
    if [ "$rid" = "__current__" ]; then
      rid="$(cat "$MEMPALACE_RUN_ROOT/current/run_id" 2>/dev/null || true)"
      [ -z "$rid" ] && { echo "No current run_id" >&2; exit 1; }
    fi
    export MEMPALACE_RUN_ID="$rid"
    mempalace_events_reconstruct "$rid"
    exit 0
  fi

  mempalace_state_init
  mempalace_log_init
  export MEMPALACE_LOG_FILE="${MEMPALACE_LOG_FILE:-$MEMPALACE_RUN_DIR/ingest.log}"

  if [ "${MEMPALACE_INGEST_SHOW_PLAN:-0}" = "1" ]; then
    echo "Derived execution plan:"
    mempalace_dag_execution_plan
    exit 0
  fi

  echo "=== MemPalace ingest ==="
  echo "Run ID: $MEMPALACE_RUN_ID"
  echo "Palace: $MEMPALACE_PALACE_DIR"
  echo "Manifest: $(mempalace_dag_manifest_path)"
  echo "Events: $MEMPALACE_EVENTS_FILE"
  [ "${MEMPALACE_INGEST_DRY_RUN:-0}" = "1" ] && echo "Mode: dry-run"
  echo "Log: $MEMPALACE_LOG_FILE"
  echo ""

  local rc=0
  mempalace_runner_run_plan || rc=$?

  if [ "$rc" -eq 2 ]; then
    echo ""
    echo "⏳ Ingest skipped (lock held)."
    exit 0
  fi
  if [ "$rc" -ne 0 ]; then
    echo ""
    echo "❌ Ingest failed."
    echo "   Resume: bash scripts/mempalace-ingest.sh --resume"
    echo "   Replay: bash scripts/mempalace-ingest.sh --replay"
    echo "   Events: $MEMPALACE_EVENTS_FILE"
    exit 1
  fi

  echo ""
  echo "✅ Ingest complete. run_id=$MEMPALACE_RUN_ID"
}

mempalace_engine_main "$@"
