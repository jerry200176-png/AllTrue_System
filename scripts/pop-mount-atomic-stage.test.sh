#!/usr/bin/env bash
# Local simulation of the reviewed-financial mount: cp backup while LIVE stays,
# one STAGE→LIVE, explicit fail-closed validation. Runs the workflow's own
# fpm_can / acl_exact / restore_dir_acls / validate_mount_file /
# cleanup_stage_preserve_live on real ACLs; a setfacl stub injects failures.
#
# Limits: the worker's access is decided statically from getfacl (fpm_can).
# The kernel cross-check below only runs as root; it cannot model LSMs
# (AppArmor/SELinux), mount options or capabilities on the production host.
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
chmod 755 "$TMP"

# Layout mirrors production: <ancestor>/private/pop, where only private and
# pop are managed by the workflow.
ANCESTOR="$TMP/backend"
POP_DIR="$ANCESTOR/private/pop"
LIVE="$POP_DIR/reviewed-financial-cases.json"
STAGE="$POP_DIR/reviewed-financial-cases.json.staging"
PREV="$POP_DIR/reviewed-financial-cases.json.prev"
mkdir -p "$POP_DIR"
chmod 755 "$ANCESTOR"
DIR_ACL_BACKUP="$TMP/dir-acl.backup"
: >"$DIR_ACL_BACKUP"

# Serving-worker identity for the simulation: an unprivileged account that
# owns nothing here, so access must come from the named ACL.
user=nobody; uid="$(id -u nobody)"; gid="$(id -g nobody)"; fpm_gids="$(id -G nobody)"
command -v setfacl >/dev/null 2>&1 || { echo "ERROR: setfacl unavailable (install acl)"; exit 1; }
# A second principal that must never end up on the file ACL.
OTHER_UID="$(id -u daemon 2>/dev/null || echo 1)"

# Stub setfacl to inject failures; otherwise call the real one.
SETFACL_FAIL_MODE="${SETFACL_FAIL_MODE:-}"
setfacl() {
  local last="${!#}"
  case "${SETFACL_FAIL_MODE}" in
    file) if [ -f "$last" ]; then echo "stub setfacl: file fail (EPERM, non-owner)" >&2; return 1; fi ;;
    file_noop) if [ -f "$last" ]; then echo "stub setfacl: file_noop" >&2; return 0; fi ;;
    dir_noop) if [ -d "$last" ]; then echo "stub setfacl: dir_noop" >&2; return 0; fi ;;
  esac
  command setfacl "$@"
}

WF="$ROOT/.github/workflows/pop-mount-reviewed-financial-once.yml"
eval "$(awk '/^          # fpm_can <r\|x> PATH/{on=1} on{print} on && /^          }$/{n++; if(n==6) exit}' "$WF" | sed 's/^          //')"
for fn in fpm_can acl_exact restore_dir_acls validate_mount_file abort_mount cleanup_stage_preserve_live; do
  declare -F "$fn" >/dev/null || { echo "ERROR could not extract $fn from workflow" >&2; exit 1; }
done

good_json() {
  cat >"$1" <<'JSON'
{"version":1,"eligible_cases":[{"operation_id":"reviewed-unpaid-invoice-charge-alignment","parameters_sha256":"aa","idempotency_sha256":"bb","requester_sha256":"cc","approver_sha256":"dd","approval_reference":"ref","valid_until":"2099-01-01T00:00:00Z"}]}
JSON
}
bad_json() { echo '{"version":1,"eligible_cases":[]}' >"$1"; }
acl_of() { getfacl -cpn -- "$1" 2>/dev/null | sed '/^$/d' | sort; }

reset_dirs() {
  # Managed dirs start without traverse for the worker; each case decides more.
  command setfacl -b "$ANCESTOR/private" "$POP_DIR"
  chmod 700 "$ANCESTOR/private" "$POP_DIR"
  chmod 755 "$ANCESTOR"
  : >"$DIR_ACL_BACKUP"
}

seed_prior_live() {
  reset_dirs
  rm -f "$STAGE" "$PREV"
  good_json "$LIVE"
  chmod 600 "$LIVE"
  PRIOR_HASH="$(sha256sum "$LIVE" | awk '{print $1}')"
  PRIOR_MODE="$(stat -c '%a' "$LIVE")"
  PRIOR_OWNER="$(stat -c '%u:%g' "$LIVE")"
  PRIOR_ACL="$(acl_of "$LIVE")"
  PRIOR_DIR_ACL="$(acl_of "$ANCESTOR/private"; acl_of "$POP_DIR")"
}

assert_prior_preserved() {
  local label="$1" hash mode owner
  test -f "$LIVE" || { echo "ERROR $label: LIVE missing"; exit 1; }
  hash="$(sha256sum "$LIVE" | awk '{print $1}')"
  mode="$(stat -c '%a' "$LIVE")"
  owner="$(stat -c '%u:%g' "$LIVE")"
  if [ "$hash" != "$PRIOR_HASH" ] || [ "$mode" != "$PRIOR_MODE" ] || [ "$owner" != "$PRIOR_OWNER" ] \
     || [ "$(acl_of "$LIVE")" != "$PRIOR_ACL" ]; then
    echo "ERROR $label: prior LIVE not preserved (contents/mode/owner/ACL)" >&2
    exit 1
  fi
  echo "PASS $label preserves LIVE contents+owner+mode+ACL"
}

assert_dirs_restored() {
  local label="$1"
  if [ "$(acl_of "$ANCESTOR/private"; acl_of "$POP_DIR")" != "$PRIOR_DIR_ACL" ]; then
    echo "ERROR $label: managed directory ACLs not restored" >&2
    exit 1
  fi
  echo "PASS $label restores managed directory ACLs"
}

expect_fail() {   # expect_fail <label> <stderr marker>
  local label="$1" marker="$2"
  if validate_mount_file "$STAGE" >/dev/null 2>"$TMP/validation.err"; then
    echo "ERROR $label: validation should fail"; exit 1
  fi
  grep -q -- "$marker" "$TMP/validation.err" || { echo "ERROR $label: missing marker '$marker'" >&2; cat "$TMP/validation.err" >&2; exit 1; }
  cleanup_stage_preserve_live >/dev/null
}

# 1) Happy path: exact ACL on the file, traverse added on managed dirs only.
seed_prior_live
good_json "$STAGE"
chmod 644 "$STAGE"   # sloppy upload mode must be replaced
validate_mount_file "$STAGE" >/dev/null 2>&1 || { echo "ERROR stage validate"; cat "$TMP/validation.err" 2>/dev/null; exit 1; }
want="$(printf 'group::---\nmask::r--\nother::---\nuser::rw-\nuser:%s:r--' "$uid" | sort)"
[ "$(acl_of "$STAGE")" = "$want" ] || { echo "ERROR happy: unexpected ACL"; acl_of "$STAGE"; exit 1; }
cp -a "$LIVE" "$PREV"; cmp -s "$LIVE" "$PREV"; test -f "$LIVE"
mv -f "$STAGE" "$LIVE"
validate_mount_file "$LIVE" >/dev/null 2>&1 || { echo "ERROR live validate"; exit 1; }
rm -f "$PREV"
test ! -f "$STAGE"
[ "$(stat -c '%a' "$ANCESTOR")" = 755 ] || { echo "ERROR happy: unmanaged ancestor changed"; exit 1; }
echo "PASS happy-path exact ACL, cp-backup then one STAGE→LIVE"

# 2) Extra principals / inherited default ACL / wide mask are replaced by --set.
seed_prior_live
command setfacl -m "d:u:${OTHER_UID}:rwx" "$POP_DIR"   # default ACL would be inherited
good_json "$STAGE"
command setfacl -m "u:${OTHER_UID}:r--,m::rwx" "$STAGE"
validate_mount_file "$STAGE" >/dev/null 2>&1 || { echo "ERROR extra-entries: --set should normalise"; exit 1; }
if acl_of "$STAGE" | grep -q "user:${OTHER_UID}:"; then echo "ERROR extra principal survived"; exit 1; fi
acl_of "$STAGE" | grep -qx 'mask::r--' || { echo "ERROR mask not narrowed"; exit 1; }
cleanup_stage_preserve_live >/dev/null
echo "PASS extra-principal and wide-mask entries replaced"

# 3) If the ACL cannot be replaced, leftover entries are refused, LIVE kept.
seed_prior_live
good_json "$STAGE"
command setfacl -m "u:${uid}:r--,u:${OTHER_UID}:r--,m::rwx" "$STAGE"; chmod o-rwx "$STAGE"
SETFACL_FAIL_MODE=file_noop
expect_fail "extra-entries-kept" "extra ACL entries"
SETFACL_FAIL_MODE=
assert_prior_preserved "extra-entries-kept"
assert_dirs_restored "extra-entries-kept"

# 4) setfacl EPERM (non-owner of the file) fails closed.
seed_prior_live
good_json "$STAGE"
SETFACL_FAIL_MODE=file
expect_fail "setfacl-fail" "validate_mount_file: setfacl failed"
SETFACL_FAIL_MODE=
assert_prior_preserved "setfacl-fail"

# 5) Missing acl tools fail closed before any change.
seed_prior_live
good_json "$STAGE"
if (unset -f setfacl; PATH=/nonexistent validate_mount_file "$STAGE") 2>"$TMP/validation.err"; then echo "ERROR missing acl should fail"; exit 1; fi
grep -q 'setfacl/getfacl missing' "$TMP/validation.err" || { cat "$TMP/validation.err" >&2; exit 1; }
cleanup_stage_preserve_live >/dev/null
assert_prior_preserved "acl-tools-missing"
assert_dirs_restored "acl-tools-missing"

# 6) Bad content is rejected before any permission change.
seed_prior_live
bad_json "$STAGE"
expect_fail "bad-content" "content validation failed"
assert_prior_preserved "bad-content"
assert_dirs_restored "bad-content"

# 7) Untraversable unmanaged ancestor: refused without touching managed dirs.
seed_prior_live
good_json "$STAGE"
chmod 700 "$ANCESTOR"
expect_fail "ancestor-untraversable" "fpm cannot traverse ${ANCESTOR}"
chmod 755 "$ANCESTOR"
assert_prior_preserved "ancestor-untraversable"
assert_dirs_restored "ancestor-untraversable"

# 8) Managed dir grant not effective: refused and directory ACLs rolled back.
seed_prior_live
good_json "$STAGE"
SETFACL_FAIL_MODE=dir_noop
expect_fail "managed-dir-traverse" "fpm cannot traverse ${POP_DIR}"
SETFACL_FAIL_MODE=
assert_prior_preserved "managed-dir-traverse"
assert_dirs_restored "managed-dir-traverse"

# 9) Read denied after dir grants (file ACL ineffective): dirs rolled back.
seed_prior_live
good_json "$STAGE"; chmod 600 "$STAGE"
SETFACL_FAIL_MODE=file_noop
expect_fail "fpm-read-denied" "ACL entries\|fpm cannot read"
SETFACL_FAIL_MODE=
assert_prior_preserved "fpm-read-denied"
assert_dirs_restored "fpm-read-denied"

# 9b) Real directory grants applied, then the read check fails: grants rolled back.
seed_prior_live
good_json "$STAGE"
eval "orig_$(declare -f fpm_can)"
fpm_can() { [ "$1" = r ] && return 1; orig_fpm_can "$@"; }
expect_fail "rollback-after-grant" "fpm cannot read"
eval "$(declare -f orig_fpm_can | sed '1s/orig_fpm_can/fpm_can/')"
assert_prior_preserved "rollback-after-grant"
assert_dirs_restored "rollback-after-grant"

# 9c) Grant keeps the directory mask to group:: plus execute only.
seed_prior_live
good_json "$STAGE"
chmod 750 "$POP_DIR"   # group r-x
validate_mount_file "$STAGE" >/dev/null 2>&1 || { echo "ERROR mask setup"; exit 1; }
acl_of "$POP_DIR" | grep -qx 'mask::r-x' || { echo "ERROR dir mask not group::+x"; acl_of "$POP_DIR"; exit 1; }
cleanup_stage_preserve_live >/dev/null
echo "PASS directory grant sets mask to group:: plus x"

# 9d) A masked named entry on a managed dir is never activated: refuse, keep ACL.
seed_prior_live
command setfacl -m "u:${OTHER_UID}:rwx,m::---" "$POP_DIR"
PRIOR_DIR_ACL="$(acl_of "$ANCESTOR/private"; acl_of "$POP_DIR")"
good_json "$STAGE"
expect_fail "masked-named-entry" "unexpected named ACL entries"
assert_dirs_restored "masked-named-entry"
command setfacl -b "$POP_DIR"

# 9d2) Worker write on a managed dir is removed (owner chmod go-w), never kept.
seed_prior_live
chmod 777 "$POP_DIR"
good_json "$STAGE"
validate_mount_file "$STAGE" >/dev/null 2>&1 || { echo "ERROR writable-dir setup"; exit 1; }
fpm_can w "$POP_DIR" && { echo "ERROR worker can still write pop"; exit 1; }
[ "$(( $(stat -c '%a' "$POP_DIR") % 10 & 2 ))" = 0 ] || { echo "ERROR pop kept other-write"; exit 1; }
cleanup_stage_preserve_live >/dev/null
echo "PASS writable managed dir narrowed"

# 9d4) Worker already traverses, but the owning group can write: narrowed anyway.
seed_prior_live
chmod 775 "$POP_DIR"   # other r-x lets the worker traverse; group rwx
good_json "$STAGE"
validate_mount_file "$STAGE" >/dev/null 2>&1 || { echo "ERROR group-write setup"; exit 1; }
[ $(( 8#$(stat -c '%a' "$POP_DIR") & 8#022 )) = 0 ] || { echo "ERROR pop kept group write"; exit 1; }
cleanup_stage_preserve_live >/dev/null
echo "PASS group-writable managed dir narrowed even when worker traverses"

# 9d5) Worker already traverses, another named principal has rwx: refused.
seed_prior_live
chmod 755 "$POP_DIR"
command setfacl -m "u:${OTHER_UID}:rwx" "$POP_DIR"
PRIOR_DIR_ACL="$(acl_of "$ANCESTOR/private"; acl_of "$POP_DIR")"
good_json "$STAGE"
expect_fail "named-writer-with-traverse" "unexpected named ACL entries"
assert_dirs_restored "named-writer-with-traverse"
command setfacl -b "$POP_DIR"

# 9d3) A managed dir the worker itself owns (cannot be narrowed) is refused.
if [ "$(id -u)" = 0 ]; then
  seed_prior_live
  chown nobody "$POP_DIR"; chmod 700 "$POP_DIR"
  PRIOR_DIR_ACL="$(acl_of "$ANCESTOR/private"; acl_of "$POP_DIR")"
  good_json "$STAGE"
  expect_fail "worker-owned-dir" "worker can write"
  chown root "$POP_DIR"
  echo "PASS worker-owned managed dir refused"
fi

# 9e) Interrupted after the swap: abort_mount restores PREV, dirs and stage.
seed_prior_live
good_json "$STAGE"
validate_mount_file "$STAGE" >/dev/null 2>&1 || { echo "ERROR abort setup"; exit 1; }
cp -a "$LIVE" "$PREV"; mv -f "$STAGE" "$LIVE"
( MOUNT_PHASE=swapped; abort_mount ) >/dev/null 2>&1 && { echo "ERROR abort must exit non-zero"; exit 1; }
test ! -f "$STAGE" && test ! -f "$PREV" || { echo "ERROR abort left stage/prev"; exit 1; }
assert_prior_preserved "abort-after-swap"
assert_dirs_restored "abort-after-swap"

# 9f) Interrupted before the swap: stage and dir grants removed, LIVE kept.
seed_prior_live
good_json "$STAGE"
validate_mount_file "$STAGE" >/dev/null 2>&1 || { echo "ERROR abort2 setup"; exit 1; }
( MOUNT_PHASE=staged; abort_mount ) >/dev/null 2>&1
test ! -f "$STAGE" || { echo "ERROR abort left stage"; exit 1; }
assert_prior_preserved "abort-before-swap"
assert_dirs_restored "abort-before-swap"

# 9f2) A real SIGTERM after the swap runs the same rollback through the trap.
seed_prior_live
good_json "$STAGE"
validate_mount_file "$STAGE" >/dev/null 2>&1 || { echo "ERROR sigterm setup"; exit 1; }
cp -a "$LIVE" "$PREV"; mv -f "$STAGE" "$LIVE"
( MOUNT_PHASE=swapped; trap 'abort_mount' EXIT HUP INT TERM; sleep 30 & wait ) >/dev/null 2>&1 &
victim=$!
sleep 0.5; kill -TERM "$victim"; wait "$victim" 2>/dev/null
test ! -f "$PREV" || { echo "ERROR sigterm left PREV"; exit 1; }
assert_prior_preserved "sigterm-after-swap"
assert_dirs_restored "sigterm-after-swap"

# 9g) A completed mount is never undone by the exit trap.
seed_prior_live
( MOUNT_PHASE=done; abort_mount; echo still-here ) | grep -q still-here || { echo "ERROR done phase aborted"; exit 1; }
echo "PASS done phase leaves the mount alone"

# 10) fpm_can must agree with the kernel (needs root to switch identity).
if [ "$(id -u)" = 0 ] && command -v runuser >/dev/null 2>&1; then
  seed_prior_live
  good_json "$STAGE"
  validate_mount_file "$STAGE" >/dev/null 2>&1 || { echo "ERROR kernel cross-check setup"; exit 1; }
  runuser -u nobody -- test -r "$STAGE" || { echo "ERROR kernel denies read that fpm_can allowed"; exit 1; }
  command setfacl -x "u:${uid}" "$STAGE"
  if runuser -u nobody -- test -r "$STAGE" || fpm_can r "$STAGE"; then echo "ERROR read allowed without ACL"; exit 1; fi
  cleanup_stage_preserve_live >/dev/null
  echo "PASS fpm_can matches kernel access for ${user}"
fi

# 11) Post-swap failure restores PREV and the managed directory ACLs.
seed_prior_live
good_json "$STAGE"
validate_mount_file "$STAGE" >/dev/null 2>&1 || { echo "ERROR swap setup"; exit 1; }
cp -a "$LIVE" "$PREV"
mv -f "$STAGE" "$LIVE"
bad_json "$LIVE"   # simulate a corrupted swap
if validate_mount_file "$LIVE" >/dev/null 2>&1; then echo "ERROR post-swap should fail"; exit 1; fi
restore_dir_acls >/dev/null
mv -f "$PREV" "$LIVE"
assert_prior_preserved "post-swap-interrupt-restore"
assert_dirs_restored "post-swap-interrupt-restore"

# 12) Interrupt with LIVE missing still restores PREV.
seed_prior_live
cp -a "$LIVE" "$PREV"
rm -f "$LIVE"
cleanup_stage_preserve_live >/dev/null
assert_prior_preserved "live-missing-restores-prev"

# 12b) probe-identity readiness report is read-only and reports blockers.
eval "$(awk '/^          acl_readiness\(\) \{/{on=1} on{print} on && /^          }$/{exit}' "$WF" | sed 's/^          //')"
declare -F acl_readiness >/dev/null || { echo "ERROR could not extract acl_readiness" >&2; exit 1; }
seed_prior_live
chmod 700 "$ANCESTOR"
acl_readiness >"$TMP/ready.out" 2>&1
chmod 755 "$ANCESTOR"
grep -q "tool_setfacl=present" "$TMP/ready.out" && grep -q "ancestor_traverse ${ANCESTOR} DENIED" "$TMP/ready.out" \
  && grep -q "deploy_owns=yes" "$TMP/ready.out" || { echo "ERROR readiness report incomplete"; cat "$TMP/ready.out"; exit 1; }
if grep -q 'reviewed-unpaid' "$TMP/ready.out"; then echo "ERROR readiness printed file contents"; exit 1; fi
assert_prior_preserved "acl-readiness-read-only"
assert_dirs_restored "acl-readiness-read-only"

# 13) Guards
cd "$ROOT"
python3 scripts/check-pop-mount-remote-guards.py || exit 1
python3 scripts/check-pop-mount-remote-guards.py --self-test || exit 1
echo "pop-mount-atomic-stage test: OK"
