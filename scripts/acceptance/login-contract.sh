#!/usr/bin/env bash

acceptance_login_request_valid() {
  local request_file="$1"
  jq -e '
    type == "object" and
    (.account | (type == "string" and length > 0 and length <= 128)) and
    (.password | (type == "string" and length > 0)) and
    (.role == "director")
  ' "$request_file" >/dev/null 2>/dev/null
}

acceptance_login_response_taxonomy() {
  local response_file="$1"
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
