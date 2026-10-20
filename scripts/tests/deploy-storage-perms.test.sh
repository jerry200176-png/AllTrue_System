#!/usr/bin/env bash
# deploy.yml [2b/7] must keep the historical 775 for writable Laravel dirs but
# never widen storage/app/private (POP machine key, reviewed financial manifest).
# Runs scripts/deploy-storage-perms.sh (called by deploy.yml) on a synthetic tree.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DEPLOY="$ROOT/.github/workflows/deploy.yml"
fail() { echo "FAIL: $*" >&2; exit 1; }

if grep -qE 'chmod -R 775 storage' "$DEPLOY"; then
  fail "deploy.yml still runs a recursive chmod 775 over storage/app/private"
fi

grep -qF 'bash /home/admin/scripts/deploy-storage-perms.sh || abort_deploy' "$DEPLOY" \
  || fail "deploy.yml [2b/7] must call scripts/deploy-storage-perms.sh and abort the deploy on failure"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
APP="$WORK/backend"
mkdir -p "$APP"/storage/{logs,framework/cache,app/public,app/private/pop} "$APP"/bootstrap/cache
touch "$APP"/storage/logs/laravel.log "$APP"/bootstrap/cache/packages.php
chmod 755 "$APP"/storage/logs; chmod 644 "$APP"/storage/logs/laravel.log
P="$APP/storage/app/private"
chmod 750 "$P"; chmod 750 "$P/pop"
printf 'pop_machine_x\n' > "$P/pop-machine.key"; chmod 775 "$P/pop-machine.key"   # drift from old deploys
printf '{}' > "$P/pop/reviewed-financial-cases.json"; chmod 640 "$P/pop/reviewed-financial-cases.json"
printf '{}' > "$P/pop/legacy.json"; chmod 775 "$P/pop/legacy.json"

ACL=0
if command -v setfacl >/dev/null && setfacl -m u:nobody:r-- "$P/pop/reviewed-financial-cases.json" 2>/dev/null; then
  ACL=1
fi

run_step() { bash "$ROOT/scripts/deploy-storage-perms.sh" "$APP" >/dev/null; }
mode() { stat -c '%a' "$1"; }
expect() { [ "$(mode "$1")" = "$2" ] || fail "$1 mode $(mode "$1") != $2"; }

for pass in 1 2; do   # second pass proves idempotence
  run_step
  expect "$APP/storage/logs" 775
  expect "$APP/storage/logs/laravel.log" 775
  expect "$APP/bootstrap/cache/packages.php" 775
  expect "$P" 750
  expect "$P/pop" 750
  expect "$P/pop-machine.key" 600
  expect "$P/pop/reviewed-financial-cases.json" 640
  expect "$P/pop/legacy.json" 770
  if [ "$ACL" = 1 ]; then
    getfacl -cp "$P/pop/reviewed-financial-cases.json" | grep -qx 'user:nobody:r--' \
      || fail "named-user ACL lost (pass $pass)"
    getfacl -cp "$P/pop/reviewed-financial-cases.json" | grep -qx 'mask::r--' \
      || fail "ACL mask widened (pass $pass)"
  fi
done
# A private file the deploy user cannot fix (simulated EPERM via a chmod shim)
# must fail the step with exit 3 instead of being reported as hardened.
SHIM="$WORK/shim"; mkdir -p "$SHIM"
cat >"$SHIM/chmod" <<'SH'
#!/usr/bin/env bash
for a in "$@"; do case "$a" in *foreign*) echo "chmod: Operation not permitted" >&2; exit 1;; esac; done
exec /bin/chmod "$@"
SH
/bin/chmod +x "$SHIM/chmod"
printf 'x' > "$P/foreign.json"; chmod 644 "$P/foreign.json"
set +e
PATH="$SHIM:$PATH" bash "$ROOT/scripts/deploy-storage-perms.sh" "$APP" >"$WORK/out" 2>&1
rc=$?
set -e
[ "$rc" = 3 ] || fail "unfixable world-readable private file must exit 3 (got $rc)"
grep -q 'world-accessible' "$WORK/out" || fail "missing ::error:: for unfixable private file"
rm -f "$P/foreign.json"
bash "$ROOT/scripts/deploy-storage-perms.sh" "$APP" >/dev/null || fail "clean tree must exit 0"

echo "deploy storage perms: ok (acl_checked=$ACL)"
