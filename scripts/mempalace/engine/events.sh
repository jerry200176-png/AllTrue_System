#!/usr/bin/env bash
# Event-sourced state for MemPalace ingest (append-only JSONL log).

mempalace_events_init() {
  export MEMPALACE_EVENTS_FILE="${MEMPALACE_EVENTS_FILE:-$MEMPALACE_RUN_DIR/events.jsonl}"
  mkdir -p "$(dirname "$MEMPALACE_EVENTS_FILE")"
  touch "$MEMPALACE_EVENTS_FILE"
}

mempalace_event_emit() {
  local event="$1"
  local stage="${2:-}"
  local detail="${3:-}"
  mempalace_events_init
  python3 - "$MEMPALACE_EVENTS_FILE" "$MEMPALACE_RUN_ID" "$event" "$stage" "$detail" <<'PY'
import json, sys, datetime, os
path, run_id, event, stage, detail = sys.argv[1:6]
seq = 1
if os.path.exists(path) and os.path.getsize(path) > 0:
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            try:
                seq = max(seq, int(json.loads(line).get("seq", 0)) + 1)
            except json.JSONDecodeError:
                pass
record = {
    "seq": seq,
    "ts": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    "run_id": run_id,
    "event": event,
}
if stage:
    record["stage"] = stage
if detail:
    record["detail"] = detail
with open(path, "a", encoding="utf-8") as f:
    f.write(json.dumps(record, ensure_ascii=False) + "\n")
PY
}

mempalace_event_stage_started() {
  local stage="$1"
  mempalace_event_emit "stage_started" "$stage" ""
}

mempalace_event_stage_completed() {
  local stage="$1"
  local detail="${2:-}"
  mempalace_event_emit "stage_completed" "$stage" "$detail"
}

mempalace_event_stage_failed() {
  local stage="$1"
  local reason="${2:-unknown}"
  mempalace_event_emit "stage_failed" "$stage" "$reason"
  mempalace_log_stage_fail "$stage" "$reason"
}

mempalace_event_stage_skipped() {
  local stage="$1"
  local reason="${2:-}"
  mempalace_event_emit "stage_skipped" "$stage" "$reason"
  mempalace_log_stage_skip "$stage" "$reason"
}

mempalace_event_run_started() {
  local detail="${1:-}"
  mempalace_event_emit "run_started" "" "$detail"
}

mempalace_event_run_completed() {
  local detail="${1:-}"
  mempalace_event_emit "run_completed" "" "$detail"
}

mempalace_event_run_failed() {
  local detail="${1:-}"
  mempalace_event_emit "run_failed" "" "$detail"
}

mempalace_event_run_aborted() {
  local detail="${1:-lock held}"
  mempalace_event_emit "run_aborted" "" "$detail"
}

mempalace_events_file_for_run() {
  local run_id="${1:-$MEMPALACE_RUN_ID}"
  echo "${MEMPALACE_RUN_ROOT:-$MEMPALACE_PALACE_DIR/.ingest-run}/runs/$run_id/events.jsonl"
}

mempalace_events_query_completed_stages() {
  local events_file="${1:-$MEMPALACE_EVENTS_FILE}"
  python3 - "$events_file" <<'PY'
import json, sys
path = sys.argv[1]
completed = set()
if not path:
    sys.exit(0)
try:
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            ev = json.loads(line)
            stage = ev.get("stage")
            if not stage:
                continue
            if ev.get("event") == "stage_completed":
                completed.add(stage)
            elif ev.get("event") == "stage_failed":
                completed.discard(stage)
print("\n".join(sorted(completed)))
PY
}

mempalace_events_stage_completed() {
  local stage="$1"
  local events_file="${2:-$MEMPALACE_EVENTS_FILE}"
  mempalace_events_query_completed_stages "$events_file" | grep -qx "$stage"
}

mempalace_events_reconstruct() {
  local run_id="${1:-$MEMPALACE_RUN_ID}"
  local events_file
  events_file="$(mempalace_events_file_for_run "$run_id")"
  if [ ! -f "$events_file" ]; then
    echo "No event log for run_id=$run_id" >&2
    return 1
  fi
  python3 - "$events_file" "$run_id" <<'PY'
import json, sys
path, run_id = sys.argv[1:3]
events = []
with open(path, encoding="utf-8") as f:
    for line in f:
        line = line.strip()
        if line:
            events.append(json.loads(line))

if not events:
    print(f"Run {run_id}: empty event log")
    sys.exit(0)

stage_state = {}
run_status = "in_progress"
for ev in events:
    et = ev.get("event")
    st = ev.get("stage", "")
    seq = ev.get("seq", "?")
    ts = ev.get("ts", "")
    detail = ev.get("detail", "")
    if et == "run_started":
        run_status = "in_progress"
    elif et == "stage_started":
        stage_state[st] = "running"
    elif et == "stage_completed":
        stage_state[st] = "completed"
    elif et == "stage_failed":
        stage_state[st] = "failed"
        run_status = "failed"
    elif et == "stage_skipped":
        stage_state[st] = "skipped"
    elif et == "run_completed":
        run_status = "completed"
    elif et == "run_failed":
        run_status = "failed"
    elif et == "run_aborted":
        run_status = "aborted"

print(f"=== Replay: run_id={run_id} ===")
print(f"Events: {len(events)}")
print(f"Status: {run_status}")
print("")
print("Timeline:")
for ev in events:
    st = ev.get("stage", "-")
    detail = ev.get("detail", "")
    suffix = f" ({detail})" if detail else ""
    print(f"  [{ev.get('seq','?'):>3}] {ev.get('ts','')} {ev.get('event')} stage={st}{suffix}")

print("")
print("Stage state:")
for st in sorted(stage_state):
    print(f"  {st}: {stage_state[st]}")
PY
}
