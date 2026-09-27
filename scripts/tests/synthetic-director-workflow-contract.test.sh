#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow_file="$ROOT_DIR/.github/workflows/provision-synthetic-director.yml"
helper_file="$ROOT_DIR/scripts/acceptance/register-synthetic-director.mjs"

test -s "$workflow_file"
test -s "$helper_file"
grep -Fq 'post-approval-verify' "$workflow_file"
grep -Fq 'VERIFY_SYNTHETIC_DIRECTOR:POST_APPROVAL:CAMPUS:16' "$workflow_file"
grep -Fq 'Route::post(\x27directors/register\x27' "$workflow_file" || grep -Fq "Route::post('directors/register'" "$workflow_file"
grep -Fq 'pending_application_id' "$helper_file"
grep -Fq 'identity_collision_refused' "$helper_file"
grep -Fq 'registration_requires_invite_token' "$helper_file"

# The retired implementation must not return through this workflow. Build the
# forbidden markers in pieces so this test does not match its own assertion.
forbidden_token_table='auth_'"tokens"
forbidden_role='super_'"admin"
! grep -Fq "$forbidden_token_table" "$workflow_file"
! grep -Fq "$forbidden_role" "$workflow_file"
! grep -Fq "jq -er '.data.user.must_change_password'" "$workflow_file"

echo 'synthetic director workflow contract: PASS'

