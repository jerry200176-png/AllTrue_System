#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=/dev/null
source "$ROOT_DIR/scripts/acceptance/login-contract.sh"

workflow_file="$ROOT_DIR/.github/workflows/calendar-course-acceptance.yml"
grep -Fq 'php -d display_errors=0 -d log_errors=1 /dev/fd/3' "$workflow_file"
grep -Fq 'ALLTRUE_401_DIAGNOSIS_V1=' "$workflow_file"
grep -Fq 'acceptance_extract_b64_json_marker "$diagnosis_raw"' "$workflow_file"
grep -Fq 'timeout --signal=TERM --kill-after=5s 30s ssh' "$workflow_file"
grep -Fq 'head -c 16385' "$workflow_file"
grep -Fq 'shape=capture_or_limit' "$workflow_file"

unset SMOKE_DIRECTOR_LOGIN SMOKE_DIRECTOR_PASSWORD
SMOKE_DIRECTOR_PASSWORD=placeholder
export SMOKE_DIRECTOR_PASSWORD
if acceptance_require_login_secrets; then
  echo 'missing login secret unexpectedly passed' >&2
  exit 1
fi

unset SMOKE_DIRECTOR_LOGIN SMOKE_DIRECTOR_PASSWORD
SMOKE_DIRECTOR_LOGIN=placeholder
export SMOKE_DIRECTOR_LOGIN
if acceptance_require_login_secrets; then
  echo 'missing password secret unexpectedly passed' >&2
  exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

printf '%s\n' '{"account":"director","password":"placeholder","role":"director"}' > "$tmp_dir/valid.json"
acceptance_login_request_valid "$tmp_dir/valid.json"

printf '%s\n' '{"account":"","password":"placeholder","role":"director"}' > "$tmp_dir/invalid.json"
if acceptance_login_request_valid "$tmp_dir/invalid.json"; then
  echo 'invalid login request unexpectedly passed' >&2
  exit 1
fi

printf '%s\n' '{"account":"   \t  ","password":"placeholder","role":"director"}' > "$tmp_dir/whitespace-account.json"
if acceptance_login_request_valid "$tmp_dir/whitespace-account.json"; then
  echo 'whitespace-only account unexpectedly passed' >&2
  exit 1
fi

printf '%s\n' '{"account":"director\nforged","password":"placeholder","role":"director"}' > "$tmp_dir/newline-account.json"
if acceptance_login_request_valid "$tmp_dir/newline-account.json"; then
  echo 'newline account unexpectedly passed' >&2
  exit 1
fi

long_account="$(printf 'a%.0s' {1..129})"
printf '{"account":"%s","password":"placeholder","role":"director"}\n' "$long_account" > "$tmp_dir/long-account.json"
if acceptance_login_request_valid "$tmp_dir/long-account.json"; then
  echo 'overlong account unexpectedly passed' >&2
  exit 1
fi

printf '%s\n' '{"account":"director","password":"","role":"director"}' > "$tmp_dir/empty-password.json"
if acceptance_login_request_valid "$tmp_dir/empty-password.json"; then
  echo 'empty password unexpectedly passed' >&2
  exit 1
fi

printf '%s\n' '{"account":"director","password":"placeholder","role":"teacher"}' > "$tmp_dir/invalid-role.json"
if acceptance_login_request_valid "$tmp_dir/invalid-role.json"; then
  echo 'invalid role unexpectedly passed' >&2
  exit 1
fi

login_response() {
  local must_change="$1"
  if [ "$must_change" = missing ]; then
    printf '%s\n' '{"data":{"user":{"role":"director","campuses":[16]}}}'
  else
    printf '%s\n' "{\"data\":{\"user\":{\"role\":\"director\",\"campuses\":[16],\"must_change_password\":$must_change}}}"
  fi
}

for must_change in false true null '"false"' missing; do
  login_response "$must_change" > "$tmp_dir/must-change-$must_change.json"
  if [ "$must_change" = false ]; then
    acceptance_login_response_authorized "$tmp_dir/must-change-$must_change.json" 16
  elif acceptance_login_response_authorized "$tmp_dir/must-change-$must_change.json" 16; then
    echo "must_change_password=$must_change unexpectedly passed" >&2
    exit 1
  fi
done

product_acceptance_started=0
if acceptance_login_http_status_allows_acceptance 401; then
  echo '401 unexpectedly entered acceptance' >&2
  exit 1
fi
test "$product_acceptance_started" -eq 0
if ! acceptance_login_http_status_allows_acceptance 200; then
  echo '200 unexpectedly blocked acceptance' >&2
  exit 1
fi
product_acceptance_started=1
test "$product_acceptance_started" -eq 1

printf '%s\n' '{"matching_rows":1,"active_director_rows":1,"must_change_password_required_rows":0,"approved_branch_16_rows":1}' > "$tmp_dir/diagnosis-valid.json"
acceptance_login_401_diagnosis_result_valid "$tmp_dir/diagnosis-valid.json"
test "$(acceptance_login_401_diagnosis_summary "$tmp_dir/diagnosis-valid.json")" = 'active_director_rows=1 approved_branch_16_rows=1 matching_rows=1 must_change_password_required_rows=0'
printf '%s\n' '{"matching_rows":1,"active_director_rows":1,"must_change_password_required_rows":0,"approved_branch_16_rows":1,"account":"forbidden"}' > "$tmp_dir/diagnosis-account.json"
if acceptance_login_401_diagnosis_result_valid "$tmp_dir/diagnosis-account.json"; then exit 1; fi

diagnosis_payload="$(base64 -w0 "$tmp_dir/diagnosis-valid.json")"
printf 'remote banner\nALLTRUE_401_DIAGNOSIS_V1=%s\nremote footer\n' "$diagnosis_payload" > "$tmp_dir/diagnosis-framed.txt"
acceptance_extract_b64_json_marker "$tmp_dir/diagnosis-framed.txt" "$tmp_dir/diagnosis-extracted.json" 'ALLTRUE_401_DIAGNOSIS_V1='
acceptance_login_401_diagnosis_result_valid "$tmp_dir/diagnosis-extracted.json"

printf 'ALLTRUE_401_DIAGNOSIS_V1=%s\nALLTRUE_401_DIAGNOSIS_V1=%s\n' "$diagnosis_payload" "$diagnosis_payload" > "$tmp_dir/diagnosis-duplicate.txt"
if acceptance_extract_b64_json_marker "$tmp_dir/diagnosis-duplicate.txt" "$tmp_dir/diagnosis-duplicate.json" 'ALLTRUE_401_DIAGNOSIS_V1='; then exit 1; fi
printf '%s\n' 'ALLTRUE_401_DIAGNOSIS_V1=not-base64!' > "$tmp_dir/diagnosis-invalid-base64.txt"
if acceptance_extract_b64_json_marker "$tmp_dir/diagnosis-invalid-base64.txt" "$tmp_dir/diagnosis-invalid-base64.json" 'ALLTRUE_401_DIAGNOSIS_V1='; then exit 1; fi
printf 'ALLTRUE_401_DIAGNOSIS_V1=%s\n' "$(printf 'not-json' | base64 -w0)" > "$tmp_dir/diagnosis-invalid-json.txt"
if acceptance_extract_b64_json_marker "$tmp_dir/diagnosis-invalid-json.txt" "$tmp_dir/diagnosis-invalid-json.json" 'ALLTRUE_401_DIAGNOSIS_V1='; then exit 1; fi
printf 'ALLTRUE_401_DIAGNOSIS_V1=%s\n' "$(printf '{}\n{}\n' | base64 -w0)" > "$tmp_dir/diagnosis-multiple-json.txt"
if acceptance_extract_b64_json_marker "$tmp_dir/diagnosis-multiple-json.txt" "$tmp_dir/diagnosis-multiple-json.json" 'ALLTRUE_401_DIAGNOSIS_V1='; then exit 1; fi
printf 'ALLTRUE_401_DIAGNOSIS_V1=%s\n' "$(printf '[]' | base64 -w0)" > "$tmp_dir/diagnosis-array-json.txt"
if acceptance_extract_b64_json_marker "$tmp_dir/diagnosis-array-json.txt" "$tmp_dir/diagnosis-array-json.json" 'ALLTRUE_401_DIAGNOSIS_V1='; then exit 1; fi
printf '%*s\n' 17000 '' > "$tmp_dir/diagnosis-oversized.txt"
if acceptance_extract_b64_json_marker "$tmp_dir/diagnosis-oversized.txt" "$tmp_dir/diagnosis-oversized.json" 'ALLTRUE_401_DIAGNOSIS_V1='; then exit 1; fi
printf '%s\n' 'noise only' > "$tmp_dir/diagnosis-missing.txt"
if acceptance_extract_b64_json_marker "$tmp_dir/diagnosis-missing.txt" "$tmp_dir/diagnosis-missing.json" 'ALLTRUE_401_DIAGNOSIS_V1='; then exit 1; fi

printf '%s\n' '{"errors":{"account":["redacted"],"password":["redacted"]},"code":"not-allowlisted","message":"must not be printed"}' > "$tmp_dir/rejected.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/rejected.json")" = 'json=valid errors=account,password code=none'

printf '%s\n' '{"code":"teacher_pending_approval","message":"must not be printed"}' > "$tmp_dir/pending.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/pending.json")" = 'json=valid errors=none code=teacher_pending_approval'

printf '%s\n' 'not-json' > "$tmp_dir/malformed.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/malformed.json")" = 'json=unparseable errors=none code=none'

: > "$tmp_dir/empty-response.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/empty-response.json")" = 'json=unparseable errors=none code=none'

printf ' \t\n' > "$tmp_dir/whitespace-response.json"
test "$(acceptance_login_response_taxonomy "$tmp_dir/whitespace-response.json")" = 'json=unparseable errors=none code=none'

echo 'acceptance login contract: PASS'
