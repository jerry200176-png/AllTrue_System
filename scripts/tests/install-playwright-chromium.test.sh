#!/usr/bin/env bash
# .github/scripts/install-playwright-chromium.sh: retry/probe behaviour, plus the wiring in both
# post-deploy smoke workflows. npx/node are mocked through PATH; no network, no browser.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SCRIPT="$ROOT/.github/scripts/install-playwright-chromium.sh"
fail() { echo "FAIL: $*" >&2; exit 1; }
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
BIN="$WORK/bin"; mkdir -p "$BIN"

# Mock npx: records calls. MOCK_INSTALL_RC / MOCK_DEPS_RC drive the exit codes; deps succeed from
# attempt MOCK_DEPS_OK_FROM on (default: never override).
cat > "$BIN/npx" <<'M'
#!/usr/bin/env bash
echo "npx $*" >> "$MOCK_LOG"
case "$*" in
  "playwright install chromium") exit "${MOCK_INSTALL_RC:-0}" ;;
  "playwright install-deps chromium")
    if [ -n "${MOCK_DEPS_OK_FROM:-}" ] && [ "$(grep -c install-deps "$MOCK_LOG")" -ge "$MOCK_DEPS_OK_FROM" ]; then exit 0; fi
    exit "${MOCK_DEPS_RC:-0}" ;;
esac
exit 99
M
# Mock node: the launch probe.
cat > "$BIN/node" <<'M'
#!/usr/bin/env bash
echo "node probe" >> "$MOCK_LOG"
exit "${MOCK_PROBE_RC:-0}"
M
chmod +x "$BIN/npx" "$BIN/node"

RC=0; OUT=""; LOG=""
run() { # run <VAR=val ...>; sets RC, OUT, LOG
  local mock_log="$WORK/log"; : > "$mock_log"
  set +e
  OUT="$(env PATH="$BIN:$PATH" MOCK_LOG="$mock_log" DEPS_BACKOFF_SECONDS=0 "$@" bash "$SCRIPT" 2>&1)"
  RC=$?
  set -e
  LOG="$(cat "$mock_log")"
}
count() { printf '%s\n' "$LOG" | grep -cF -- "$1" || true; }

# 1 deps ok: download once, deps once, no probe.
run X=1
[ "$RC" -eq 0 ] || fail "deps ok must exit 0 (rc=$RC)"
[ "$(count 'playwright install chromium')" -eq 1 ] || fail "deps ok: expected one browser download"
[ "$(count 'install-deps')" -eq 1 ] || fail "deps ok: expected one deps attempt"
[ "$(count 'node probe')" -eq 0 ] || fail "deps ok: probe must not run"

# 2 cache hit: no download.
run PLAYWRIGHT_CACHE_HIT=true
[ "$RC" -eq 0 ] || fail "cache hit must exit 0"
[ "$(count 'playwright install chromium')" -eq 0 ] || fail "cache hit must skip the download"

# 3 deps fail 3x + probe ok: success with a warning.
run MOCK_DEPS_RC=100
[ "$RC" -eq 0 ] || fail "deps fail + probe ok must exit 0 (rc=$RC)"
[ "$(count 'install-deps')" -eq 3 ] || fail "expected exactly 3 deps attempts"
[ "$(count 'node probe')" -eq 1 ] || fail "expected one probe"
printf '%s' "$OUT" | grep -q '::warning::System dependency install failed' || fail "missing warning"

# 3b attempts come from DEPS_ATTEMPTS.
run MOCK_DEPS_RC=100 DEPS_ATTEMPTS=2
[ "$(count 'install-deps')" -eq 2 ] || fail "DEPS_ATTEMPTS must drive the retry count"

# 3c deps recover on attempt 2: passes without the probe.
run MOCK_DEPS_RC=100 MOCK_DEPS_OK_FROM=2
[ "$RC" -eq 0 ] || fail "deps recovering on attempt 2 must pass"
[ "$(count 'node probe')" -eq 0 ] || fail "recovered deps must not need the probe"

# 4 deps fail + probe fail: distinct infrastructure failure.
run MOCK_DEPS_RC=100 MOCK_PROBE_RC=1
[ "$RC" -eq 70 ] || fail "deps+probe failure must exit 70 (rc=$RC)"
printf '%s' "$OUT" | grep -q 'INFRASTRUCTURE FAILURE' || fail "missing infrastructure message"
printf '%s' "$OUT" | grep -q 'not a failed acceptance' || fail "message must say it is not a failed acceptance"

# 5 browser download fails: non-zero, no deps attempt.
run MOCK_INSTALL_RC=1
[ "$RC" -eq 1 ] || fail "download failure must exit 1 (rc=$RC)"
[ "$(count 'install-deps')" -eq 0 ] || fail "download failure must stop before deps"
printf '%s' "$OUT" | grep -q 'INFRASTRUCTURE FAILURE' || fail "download failure must say infrastructure"

# 6 wiring: both workflows call the helper with the cache output, cache the browser keyed on the
# lockfile's Playwright version, and no longer run the apt-hitting --with-deps install.
for wf in ui-smoke calendar-course-acceptance; do
  f="$ROOT/.github/workflows/$wf.yml"
  grep -qF 'bash ../.github/scripts/install-playwright-chromium.sh' "$f" || fail "$wf must call the helper"
  grep -qF 'PLAYWRIGHT_CACHE_HIT: ${{ steps.playwright-cache.outputs.cache-hit }}' "$f" || fail "$wf must pass the cache-hit output"
  grep -qF 'path: ~/.cache/ms-playwright' "$f" || fail "$wf must cache ms-playwright"
  grep -qF 'steps.playwright-version.outputs.version' "$f" || fail "$wf cache key must carry the Playwright version"
  if grep -q 'playwright install --with-deps' "$f"; then fail "$wf still runs install --with-deps"; fi
done
echo "install-playwright-chromium.test.sh: OK"
