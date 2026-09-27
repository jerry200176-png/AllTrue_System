#!/usr/bin/env bash

# Small, dependency-free contract for bounded acceptance diagnostics.
# Commands keep their stdout as data; stage markers go to stderr so captured
# login/session output can never be contaminated by diagnostics.

acceptance_stage() {
  printf 'acceptance_stage=%s\n' "$1" >&2
}

acceptance_capture() {
  local stage="$1"
  shift
  acceptance_stage "${stage}_start"
  if STAGE_OUTPUT="$("$@")"; then
    acceptance_stage "${stage}_ok"
    return 0
  else
    local status=$?
  fi
  acceptance_stage "${stage}_failed exit=${status}"
  return "$status"
}
