#!/usr/bin/env bash
# Local simulation of stage→validate→atomic replace and failure restore (no Pi, no secrets).
# Failure paths must preserve prior LIVE contents, owner, and mode.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

POP_DIR="$TMP/pop"
LIVE="$POP_DIR/reviewed-financial-cases.json"
STAGE="$POP_DIR/reviewed-financial-cases.json.staging"
PREV="$POP_DIR/reviewed-financial-cases.json.prev"
mkdir -p "$POP_DIR"

good_json() {
  cat >"$1" <<'JSON'
{"version":1,"eligible_cases":[{"operation_id":"reviewed-unpaid-invoice-charge-alignment","parameters_sha256":"aa","idempotency_sha256":"bb","requester_sha256":"cc","approver_sha256":"dd","approval_reference":"ref","valid_until":"2099-01-01T00:00:00Z"}]}
JSON
}
bad_json() {
  echo '{"version":1,"eligible_cases":[]}' >"$1"
}

# Distinctive prior LIVE metadata (contents + mode; owner = current uid/gid).
seed_prior_live() {
  good_json "$LIVE"
  chmod 600 "$LIVE"
  # Touch mtime/identity without needing root chown; record actual owner.
  PRIOR_HASH="$(sha256sum "$LIVE" | awk '{print $1}')"
  PRIOR_MODE="$(stat -c '%a' "$LIVE")"
  PRIOR_OWNER="$(stat -c '%u:%g' "$LIVE")"
  export PRIOR_HASH PRIOR_MODE PRIOR_OWNER
}

assert_prior_preserved() {
  local label="$1"
  test -f "$LIVE" || { echo "ERROR $label: LIVE missing"; exit 1; }
  local hash mode owner
  hash="$(sha256sum "$LIVE" | awk '{print $1}')"
  mode="$(stat -c '%a' "$LIVE")"
  owner="$(stat -c '%u:%g' "$LIVE")"
  if [ "$hash" != "$PRIOR_HASH" ] || [ "$mode" != "$PRIOR_MODE" ] || [ "$owner" != "$PRIOR_OWNER" ]; then
    echo "ERROR $label: prior LIVE not preserved" >&2
    echo "  want hash=$PRIOR_HASH mode=$PRIOR_MODE owner=$PRIOR_OWNER" >&2
    echo "  got  hash=$hash mode=$mode owner=$owner" >&2
    exit 1
  fi
  echo "PASS $label preserves contents+owner+mode"
}

validate_content() {
  python3 - "$1" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
assert d.get("version") == 1
cases = d.get("eligible_cases") or []
assert len(cases) == 1
c = cases[0]
for k in ("operation_id", "parameters_sha256", "idempotency_sha256", "requester_sha256", "approver_sha256", "approval_reference", "valid_until"):
    assert k in c and c[k], k
assert c["operation_id"] == "reviewed-unpaid-invoice-charge-alignment"
PY
}

atomic_swap() {
  rm -f "$PREV"
  if [ -f "$LIVE" ]; then mv -f "$LIVE" "$PREV"; fi
  mv -f "$STAGE" "$LIVE"
}

restore_on_fail() {
  # Match workflow: restore PREV over LIVE; drop STAGE.
  if [ -f "$PREV" ]; then mv -f "$PREV" "$LIVE"; else rm -f "$LIVE"; fi
  rm -f "$STAGE"
}

# 1) Happy path
seed_prior_live
good_json "$STAGE"
chmod 640 "$STAGE"
validate_content "$STAGE"
atomic_swap
validate_content "$LIVE"
rm -f "$PREV"
test ! -f "$STAGE"
test -f "$LIVE"
echo "PASS happy-path atomic replace"

# 2) Bad STAGE: LIVE untouched (contents+owner+mode)
seed_prior_live
bad_json "$STAGE"
chmod 640 "$STAGE"
if validate_content "$STAGE" 2>/dev/null; then echo "ERROR bad stage should fail validate"; exit 1; fi
rm -f "$STAGE"
assert_prior_preserved "failing-stage"

# 3) Interrupted after LIVE→PREV: restore PREV with same attrs
seed_prior_live
good_json "$STAGE"
chmod 640 "$STAGE"
mv -f "$LIVE" "$PREV"
test ! -f "$LIVE"
test -f "$PREV"
# PREV must still carry prior mode/owner/hash
test "$(sha256sum "$PREV" | awk '{print $1}')" = "$PRIOR_HASH"
test "$(stat -c '%a' "$PREV")" = "$PRIOR_MODE"
test "$(stat -c '%u:%g' "$PREV")" = "$PRIOR_OWNER"
rm -f "$STAGE" || true
if [ -f "$PREV" ] && [ ! -f "$LIVE" ]; then mv -f "$PREV" "$LIVE"; fi
assert_prior_preserved "interrupted-LIVE-to-PREV"

# 4) Post-swap validation fail restores PREV attrs
seed_prior_live
bad_json "$STAGE"
chmod 777 "$STAGE" 2>/dev/null || chmod 666 "$STAGE"
atomic_swap
if validate_content "$LIVE" 2>/dev/null; then echo "ERROR expected post-swap fail"; exit 1; fi
# LIVE is now bad/wrong mode; restore must bring prior back
restore_on_fail
assert_prior_preserved "post-swap-failure"

# 5) Guard + --self-test (must stay enforced by PR CI via this script)
cd "$ROOT"
python3 scripts/check-pop-mount-remote-guards.py
python3 scripts/check-pop-mount-remote-guards.py --self-test
echo "pop-mount-atomic-stage test: OK"
