#!/usr/bin/env bash
# Local simulation of stage→validate→atomic replace and failure restore (no Pi, no secrets).
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
  if [ -f "$PREV" ]; then mv -f "$PREV" "$LIVE"; else rm -f "$LIVE"; fi
  rm -f "$STAGE"
}

# 1) Happy path: prior LIVE preserved until swap; STAGE becomes LIVE
good_json "$LIVE"
echo prior >"$LIVE.marker"
cp "$LIVE" "$LIVE.prior_copy"
good_json "$STAGE"
validate_content "$STAGE"
atomic_swap
validate_content "$LIVE"
rm -f "$PREV"
test ! -f "$STAGE"
test -f "$LIVE"
echo "PASS happy-path atomic replace"

# 2) Bad STAGE content: LIVE unchanged
good_json "$LIVE"
PRIOR_HASH="$(sha256sum "$LIVE" | awk '{print $1}')"
bad_json "$STAGE"
if validate_content "$STAGE" 2>/dev/null; then echo "ERROR bad stage should fail validate"; exit 1; fi
rm -f "$STAGE"
test "$(sha256sum "$LIVE" | awk '{print $1}')" = "$PRIOR_HASH"
echo "PASS failing-stage keeps LIVE"

# 3) Interrupted after LIVE→PREV (simulate crash before STAGE→LIVE): restore PREV
good_json "$LIVE"
good_json "$STAGE"
mv -f "$LIVE" "$PREV"
# crash: STAGE still present, LIVE missing
test ! -f "$LIVE"
test -f "$PREV"
# recovery (matches workflow cleanup_stage_preserve_live)
rm -f "$STAGE" || true
if [ -f "$PREV" ] && [ ! -f "$LIVE" ]; then mv -f "$PREV" "$LIVE"; fi
test -f "$LIVE"
validate_content "$LIVE"
echo "PASS interrupted LIVE→PREV restores prior"

# 4) Post-swap content fail restores PREV
good_json "$LIVE"
PRIOR_HASH="$(sha256sum "$LIVE" | awk '{print $1}')"
bad_json "$STAGE"
# force swap of bad content then detect
atomic_swap
if validate_content "$LIVE" 2>/dev/null; then echo "ERROR expected post-swap fail"; exit 1; fi
restore_on_fail
test "$(sha256sum "$LIVE" | awk '{print $1}')" = "$PRIOR_HASH"
echo "PASS post-swap failure restores PREV"

# 5) Guard rejects scp-to-live regression
cd "$ROOT"
python3 scripts/check-pop-mount-remote-guards.py
python3 scripts/check-pop-mount-remote-guards.py --self-test
echo "pop-mount-atomic-stage test: OK"
