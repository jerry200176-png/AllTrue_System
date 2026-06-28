#!/usr/bin/env bash
# Event-driven node runner — wraps stage handlers as event emitters.

mempalace_runner_check_inputs() {
  local node="$1"
  if [ "${MEMPALACE_INGEST_FORCE:-0}" = "1" ]; then
    return 0
  fi

  local inputs
  inputs="$(mempalace_dag_node_json "$node" inputs 2>/dev/null || true)"
  [ -z "$inputs" ] && return 0

  local artifact
  while IFS= read -r artifact; do
    [ -z "$artifact" ] && continue
    if [ ! -e "$MEMPALACE_RUN_DIR/$artifact" ]; then
      mempalace_event_stage_failed "$node" "missing input artifact: $artifact"
      return 1
    fi
  done <<<"$inputs"
  return 0
}

mempalace_runner_retry_policy() {
  local node="$1"
  python3 - "$(mempalace_dag_manifest_path)" "$node" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as f:
    nodes = json.load(f)["nodes"]
r = nodes[sys.argv[2]].get("retry", {})
print(int(r.get("max_attempts", 1)))
print(int(r.get("delay_sec", 0)))
PY
}

mempalace_runner_execute_node() {
  local node="$1"
  local handler
  handler="$(mempalace_dag_node_json "$node" handler)"

  if ! declare -F "$handler" >/dev/null 2>&1; then
    mempalace_event_stage_failed "$node" "handler not loaded: $handler"
    return 1
  fi

  if ! mempalace_runner_check_inputs "$node"; then
    return 1
  fi

  mempalace_event_stage_started "$node"
  mempalace_log_stage_start "$node"

  local max_attempts delay_sec attempt=1
  read -r max_attempts delay_sec < <(mempalace_runner_retry_policy "$node")

  while [ "$attempt" -le "$max_attempts" ]; do
    if [ "$attempt" -gt 1 ]; then
      mempalace_log "WARN" "$node" "retry attempt=$attempt/$max_attempts"
      mempalace_event_emit "stage_retry" "$node" "attempt=$attempt/$max_attempts"
      sleep "$delay_sec"
    fi

    if $handler; then
      if [ "${MEMPALACE_INGEST_ABORT:-0}" = "1" ]; then
        return 2
      fi
      return 0
    fi

    attempt=$((attempt + 1))
  done

  mempalace_state_stage_failed "$node" "exhausted retries ($max_attempts)"
  return 1
}

mempalace_runner_run_plan() {
  local plan_file="$MEMPALACE_RUN_DIR/execution.plan"
  mempalace_dag_execution_plan >"$plan_file"

  local plan_oneline
  plan_oneline="$(tr '\n' ' ' <"$plan_file" | sed 's/ $//')"
  mempalace_event_run_started "plan=$plan_oneline"
  mempalace_log "INFO" "engine" "execution_plan=$plan_oneline"

  local node rc=0
  while IFS= read -r node; do
    [ -z "$node" ] && continue

    if ! mempalace_state_should_run_node "$node" "$plan_file"; then
      mempalace_event_stage_skipped "$node" "already completed (resume)"
      continue
    fi

    rc=0
    mempalace_runner_execute_node "$node" || rc=$?

    if [ "$rc" -eq 2 ]; then
      mempalace_event_run_aborted "lock held"
      return 2
    fi
    if [ "$rc" -ne 0 ]; then
      mempalace_event_run_failed "failed_at=$node"
      return 1
    fi
  done <"$plan_file"

  # run_completed emitted by verify stage via mempalace_state_mark_success
  return 0
}
