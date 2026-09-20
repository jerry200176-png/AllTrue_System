#!/usr/bin/env bash

acceptance_login_request_valid() {
  local request_file="$1"
  jq -e '
    type == "object" and
    (.account | (type == "string" and (test("[\\r\\n]") | not) and ((gsub("^\\s+|\\s+$"; "")) | length > 0 and length <= 128))) and
    (.password | (type == "string" and length > 0)) and
    (.role == "director")
  ' "$request_file" >/dev/null 2>/dev/null
}

acceptance_require_login_secrets() {
  if [ -z "${SMOKE_DIRECTOR_LOGIN:-}" ]; then
    printf '%s\n' 'acceptance_stage=login_secret_missing' >&2
    return 1
  fi
  if [ -z "${SMOKE_DIRECTOR_PASSWORD:-}" ]; then
    printf '%s\n' 'acceptance_stage=login_secret_missing' >&2
    return 1
  fi
}

acceptance_login_http_status_allows_acceptance() {
  [ "${1:-}" = 200 ]
}

acceptance_login_401_diagnosis_result_valid() {
  local result_file="$1"
  jq -e 'type == "object" and (keys | sort) == ["active_director_rows", "approved_branch_16_rows", "matching_rows", "must_change_password_required_rows"] and all(.[]; type == "number" and floor == . and . >= 0)' "$result_file" >/dev/null 2>/dev/null
}

acceptance_login_response_taxonomy() {
  local response_file="$1"
  if [ ! -s "$response_file" ] || ! grep -q '[^[:space:]]' "$response_file"; then
    printf '%s\n' 'json=unparseable errors=none code=none'
    return 0
  fi
  jq -r '
    def safe_error_keys:
      if (.errors? | type) == "object" then
        ([.errors | keys[] | select(type == "string" and test("^[A-Za-z0-9_.-]{1,64}$"))]
          | sort | .[0:16] | join(","))
      else "none" end;
    if type != "object" then
      "json=invalid errors=none code=none"
    else
      "json=valid errors=" + safe_error_keys +
      " code=" + (if .code? == "teacher_pending_approval" then "teacher_pending_approval" else "none" end)
    end
  ' "$response_file" 2>/dev/null || printf '%s\n' 'json=unparseable errors=none code=none'
}

acceptance_login_response_authorized() {
  local response_file="$1"
  local branch_id="${2:-}"
  jq -e '
    (.data.user | type == "object") and
    (.data.user.role | . == "director" or . == "super_admin") and
    (.data.user.campuses | type == "array" and length > 0 and all(.[]; type == "number" and floor == . and . > 0))
  ' "$response_file" >/dev/null 2>/dev/null || return 1
  jq -e '.data.user.must_change_password == false' "$response_file" >/dev/null 2>/dev/null || return 1

  local session_role
  session_role="$(jq -er '.data.user.role' "$response_file")" || return 1
  if [ "$session_role" != director ] && [ "$session_role" != super_admin ]; then
    return 1
  fi
  if [ -n "$branch_id" ]; then
    jq -e --argjson branch "$branch_id" '.data.user.campuses | index($branch) != null' "$response_file" >/dev/null 2>/dev/null || return 1
  fi
}
