#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=/dev/null
source "$ROOT_DIR/scripts/acceptance/stage-contract.sh"

fake_curl() {
  printf '200'
}

fake_ssh() {
  printf 'bounded-output'
}

fake_failed_ssh() {
  return 37
}

diagnostics_file="$(mktemp)"
acceptance_capture login_curl fake_curl 2>"$diagnostics_file" >/dev/null
diagnostics="$(<"$diagnostics_file")"
test "$diagnostics" = $'acceptance_stage=login_curl_start\nacceptance_stage=login_curl_ok'
test "$STAGE_OUTPUT" = 200

acceptance_capture session_ssh fake_ssh 2>"$diagnostics_file" >/dev/null
diagnostics="$(<"$diagnostics_file")"
test "$diagnostics" = $'acceptance_stage=session_ssh_start\nacceptance_stage=session_ssh_ok'
test "$STAGE_OUTPUT" = bounded-output

if acceptance_capture session_ssh fake_failed_ssh >/tmp/acceptance-stage-contract.out 2>&1; then
  echo 'expected fake SSH failure to propagate' >&2
  exit 1
fi
grep -Fq 'acceptance_stage=session_ssh_failed exit=37' /tmp/acceptance-stage-contract.out
rm -f /tmp/acceptance-stage-contract.out "$diagnostics_file"

echo 'production acceptance stage contract: PASS'
