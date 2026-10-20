#!/usr/bin/env bash
# Local simulation: cp backup while LIVE stays; one STAGE→LIVE; explicit-fail validate.
# Also exercises chown/chmod/runuser failure paths via stub sudo.
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

POP_DIR="$TMP/pop"
LIVE="$POP_DIR/reviewed-financial-cases.json"
STAGE="$POP_DIR/reviewed-financial-cases.json.staging"
PREV="$POP_DIR/reviewed-financial-cases.json.prev"
mkdir -p "$POP_DIR"

# Stub sudo that can fail selected ops (no real root needed).
SUDO_FAIL_MODE="${SUDO_FAIL_MODE:-}"
sudo() {
  # ignore -n
  shift
  case "${SUDO_FAIL_MODE}" in
    chown) if [ "$1" = "chown" ]; then echo "stub sudo: chown fail" >&2; return 1; fi ;;
    chmod) if [ "$1" = "chmod" ]; then echo "stub sudo: chmod fail" >&2; return 1; fi ;;
    runuser_read|runuser_traverse)
      if [ "$1" = "runuser" ]; then
        # After shifting -n: runuser -u USER -- test {-r|-x} PATH.
        if [ "$4" = "--" ] && [ "$5" = "test" ]; then
          case "${SUDO_FAIL_MODE}:$6" in
            runuser_read:-r|runuser_traverse:-x)
              echo "stub sudo: ${SUDO_FAIL_MODE} fail" >&2; return 1 ;;
          esac
        fi
      fi
      ;;
  esac
  # chmod must apply its mode so later probes do not stop at the mode guard.
  case "$1" in
    chmod) command "$@" ;;
    chown) return 0 ;;
    runuser) return 0 ;;
    *) command sudo "$@" ;;
  esac
}
export -f sudo

uid=1000; gid=1000; user=admin

good_json() {
  cat >"$1" <<'JSON'
{"version":1,"eligible_cases":[{"operation_id":"reviewed-unpaid-invoice-charge-alignment","parameters_sha256":"aa","idempotency_sha256":"bb","requester_sha256":"cc","approver_sha256":"dd","approval_reference":"ref","valid_until":"2099-01-01T00:00:00Z"}]}
JSON
}
bad_json() { echo '{"version":1,"eligible_cases":[]}' >"$1"; }

seed_prior_live() {
  good_json "$LIVE"
  chmod 600 "$LIVE"
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

# Mirror workflow validate_mount_file — explicit returns, no set -e reliance.
validate_mount_file() {
  target="$1"
  if [ ! -f "$target" ]; then
    echo "validate_mount_file: missing file ${target}" >&2
    return 1
  fi
  if ! sudo -n chown "${uid}:${gid}" "$target"; then
    echo "validate_mount_file: chown failed on ${target}" >&2
    return 1
  fi
  mode_target=640
  if [ "$user" = "admin" ]; then mode_target=600; fi
  if ! sudo -n chmod "$mode_target" "$target"; then
    echo "validate_mount_file: chmod failed on ${target}" >&2
    return 1
  fi
  if ! mode="$(stat -c '%a' "$target")"; then
    echo "validate_mount_file: stat failed" >&2
    return 1
  fi
  if [ "$((mode % 10))" -ge 4 ]; then
    echo "refusing world-accessible mode ${mode}" >&2
    return 1
  fi
  if ! sudo -n runuser -u "$user" -- test -r "$target"; then
    echo "validate_mount_file: runuser cannot read ${target}" >&2
    return 1
  fi
  d="$(dirname "$target")"
  while [ "$d" != "/" ]; do
    if ! sudo -n runuser -u "$user" -- test -x "$d"; then
      echo "validate_mount_file: runuser cannot traverse ${d}" >&2
      return 1
    fi
    d="$(dirname "$d")"
  done
  if ! python3 - "$target" <<'PY'
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
  then
    echo "validate_mount_file: content failed" >&2
    return 1
  fi
  return 0
}

cleanup_stage_preserve_live() {
  rm -f "$STAGE" || true
  if [ ! -f "$LIVE" ] && [ -f "$PREV" ]; then
    mv -f "$PREV" "$LIVE"
    echo "restored_live_from_prev"
  fi
}

# 1) Happy: backup with LIVE still present, one STAGE→LIVE
seed_prior_live
good_json "$STAGE"
chmod 640 "$STAGE"
if ! validate_mount_file "$STAGE"; then echo "ERROR stage validate"; exit 1; fi
rm -f "$PREV"
cp -a "$LIVE" "$PREV"
cmp -s "$LIVE" "$PREV"
# LIVE still present before single replace
test -f "$LIVE"
mv -f "$STAGE" "$LIVE"
if ! validate_mount_file "$LIVE"; then echo "ERROR live validate"; exit 1; fi
rm -f "$PREV"
test ! -f "$STAGE"
echo "PASS happy-path cp-backup then one STAGE→LIVE"

# 2) Bad stage: LIVE untouched
seed_prior_live
bad_json "$STAGE"
if validate_mount_file "$STAGE" 2>/dev/null; then echo "ERROR bad should fail"; exit 1; fi
rm -f "$STAGE"
assert_prior_preserved "failing-stage"

# 3) chown failure leaves LIVE
seed_prior_live
good_json "$STAGE"
SUDO_FAIL_MODE=chown
if validate_mount_file "$STAGE" 2>/dev/null; then echo "ERROR chown should fail"; exit 1; fi
SUDO_FAIL_MODE=
rm -f "$STAGE"
assert_prior_preserved "chown-fail"

# 4) chmod failure leaves LIVE
seed_prior_live
good_json "$STAGE"
SUDO_FAIL_MODE=chmod
if validate_mount_file "$STAGE" 2>/dev/null; then echo "ERROR chmod should fail"; exit 1; fi
SUDO_FAIL_MODE=
rm -f "$STAGE"
assert_prior_preserved "chmod-fail"

# 5) runuser read failure leaves LIVE
seed_prior_live
good_json "$STAGE"
SUDO_FAIL_MODE=runuser_read
if validate_mount_file "$STAGE" 2>"$TMP/validation.err"; then echo "ERROR runuser should fail"; exit 1; fi
grep -Fxq 'stub sudo: runuser_read fail' "$TMP/validation.err" || { cat "$TMP/validation.err" >&2; exit 1; }
SUDO_FAIL_MODE=
rm -f "$STAGE"
assert_prior_preserved "runuser-read-fail"

# Parent traversal denial must also reject STAGE without replacing LIVE.
seed_prior_live
good_json "$STAGE"
SUDO_FAIL_MODE=runuser_traverse
if validate_mount_file "$STAGE" 2>"$TMP/validation.err"; then echo "ERROR traversal should fail"; exit 1; fi
grep -Fxq 'stub sudo: runuser_traverse fail' "$TMP/validation.err" || { cat "$TMP/validation.err" >&2; exit 1; }
SUDO_FAIL_MODE=
rm -f "$STAGE"
assert_prior_preserved "runuser-traverse-fail"

# 6) Interrupt after STAGE→LIVE but before validate: restore from PREV (LIVE was replaced)
seed_prior_live
good_json "$STAGE"
cp -a "$LIVE" "$PREV"
mv -f "$STAGE" "$LIVE"
# simulate bad post-swap content
bad_json "$LIVE"
if validate_mount_file "$LIVE" 2>/dev/null; then echo "ERROR post-swap should fail"; exit 1; fi
# restore
mv -f "$PREV" "$LIVE"
assert_prior_preserved "post-swap-interrupt-restore"

# 7) Interrupt with LIVE missing (legacy path) still restores PREV
seed_prior_live
cp -a "$LIVE" "$PREV"
rm -f "$LIVE"
cleanup_stage_preserve_live
assert_prior_preserved "live-missing-restores-prev"

# 8) Guards
cd "$ROOT"
# temporarily allow set +e for guard scripts
set +e
python3 scripts/check-pop-mount-remote-guards.py || exit 1
python3 scripts/check-pop-mount-remote-guards.py --self-test || exit 1
set -e
echo "pop-mount-atomic-stage test: OK"
