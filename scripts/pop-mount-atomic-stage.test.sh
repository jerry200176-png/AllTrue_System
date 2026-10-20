#!/usr/bin/env bash
# Local simulation: cp backup while LIVE stays; one STAGE→LIVE; explicit-fail validate.
# Runs the workflow's own validate_mount_file with real ACLs; stub setfacl
# injects setfacl/read/traversal failures.
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

POP_DIR="$TMP/pop"
LIVE="$POP_DIR/reviewed-financial-cases.json"
STAGE="$POP_DIR/reviewed-financial-cases.json.staging"
PREV="$POP_DIR/reviewed-financial-cases.json.prev"
mkdir -p "$POP_DIR"

# Serving-worker identity for the simulation: an unprivileged account that
# owns nothing here, so access must come from the named ACL.
user=nobody; uid="$(id -u nobody)"; gid="$(id -g nobody)"; fpm_gids="$(id -G nobody)"
command -v setfacl >/dev/null 2>&1 || { echo "SKIP: setfacl unavailable (install acl)"; exit 1; }

# Stub setfacl to inject failures; otherwise call the real one.
SETFACL_FAIL_MODE="${SETFACL_FAIL_MODE:-}"
setfacl() {
  local last="${!#}"
  case "${SETFACL_FAIL_MODE}" in
    file) if [ -f "$last" ]; then echo "stub setfacl: file fail" >&2; return 1; fi ;;
    file_noop) if [ -f "$last" ]; then echo "stub setfacl: file_noop" >&2; return 0; fi ;;
    dir_noop) if [ -d "$last" ]; then echo "stub setfacl: dir_noop" >&2; return 0; fi ;;
  esac
  command setfacl "$@"
}

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

# The exact fpm_can + validate_mount_file from the workflow (no hand copy).
WF="$ROOT/.github/workflows/pop-mount-reviewed-financial-once.yml"
eval "$(awk '/^          # fpm_can <r\|x> PATH/{on=1} on{print} on && /^          }$/{n++; if(n==2) exit}' "$WF" | sed 's/^          //')"
declare -F fpm_can >/dev/null && declare -F validate_mount_file >/dev/null \
  || { echo "ERROR could not extract validate_mount_file from workflow" >&2; exit 1; }

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

# 3) setfacl failure leaves LIVE
seed_prior_live
good_json "$STAGE"
SETFACL_FAIL_MODE=file
if validate_mount_file "$STAGE" 2>"$TMP/validation.err"; then echo "ERROR setfacl should fail"; exit 1; fi
grep -q 'validate_mount_file: setfacl failed' "$TMP/validation.err" || { cat "$TMP/validation.err" >&2; exit 1; }
SETFACL_FAIL_MODE=
rm -f "$STAGE"
assert_prior_preserved "setfacl-fail"

# 4) Missing acl tools fail closed
seed_prior_live
good_json "$STAGE"
if (unset -f setfacl; PATH=/nonexistent validate_mount_file "$STAGE") 2>"$TMP/validation.err"; then echo "ERROR missing acl should fail"; exit 1; fi
grep -q 'setfacl/getfacl missing' "$TMP/validation.err" || { cat "$TMP/validation.err" >&2; exit 1; }
rm -f "$STAGE"
assert_prior_preserved "acl-tools-missing"

# 5) Worker cannot read (ACL not applied) leaves LIVE
seed_prior_live
good_json "$STAGE"
chmod 600 "$STAGE"
SETFACL_FAIL_MODE=file_noop
if validate_mount_file "$STAGE" 2>"$TMP/validation.err"; then echo "ERROR read denial should fail"; exit 1; fi
grep -q 'validate_mount_file: fpm cannot read' "$TMP/validation.err" || { cat "$TMP/validation.err" >&2; exit 1; }
SETFACL_FAIL_MODE=
rm -f "$STAGE"
assert_prior_preserved "fpm-read-denied"

# Parent traversal denial must also reject STAGE without replacing LIVE.
seed_prior_live
good_json "$STAGE"
setfacl -b "$POP_DIR" "$TMP"; chmod 700 "$POP_DIR" "$TMP"
SETFACL_FAIL_MODE=dir_noop
if validate_mount_file "$STAGE" 2>"$TMP/validation.err"; then echo "ERROR traversal should fail"; exit 1; fi
grep -q 'validate_mount_file: fpm cannot traverse' "$TMP/validation.err" || { cat "$TMP/validation.err" >&2; exit 1; }
SETFACL_FAIL_MODE=
rm -f "$STAGE"
assert_prior_preserved "fpm-traverse-denied"

# fpm_can must agree with the kernel (needs root to switch identity).
if [ "$(id -u)" = 0 ] && command -v runuser >/dev/null 2>&1; then
  good_json "$STAGE"
  validate_mount_file "$STAGE" >/dev/null || { echo "ERROR kernel cross-check setup"; exit 1; }
  runuser -u nobody -- test -r "$STAGE" || { echo "ERROR kernel denies read that fpm_can allowed"; exit 1; }
  setfacl -x "u:${uid}" "$STAGE"
  if runuser -u nobody -- test -r "$STAGE" || fpm_can r "$STAGE"; then echo "ERROR read allowed without ACL"; exit 1; fi
  rm -f "$STAGE"
  echo "PASS fpm_can matches kernel access for ${user}"
fi

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
