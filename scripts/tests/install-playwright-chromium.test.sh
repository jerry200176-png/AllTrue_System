#!/usr/bin/env bash
# .github/scripts/install-playwright-chromium.sh: probe-first, bounded install-deps, exit codes, and the
# wiring in both post-deploy smoke workflows. npx/node are mocked through PATH; no network, no browser.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SCRIPT="$ROOT/.github/scripts/install-playwright-chromium.sh"
fail() { echo "FAIL: $*" >&2; exit 1; }
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
BIN="$WORK/bin"; mkdir -p "$BIN"

# Mock npx: records calls. MOCK_INSTALL_RC / MOCK_DEPS_RC drive exit codes; MOCK_DEPS_SLEEP makes
# install-deps hang (the real failure mode: apt waiting on an unreachable mirror).
cat > "$BIN/npx" <<'M'
#!/usr/bin/env bash
echo "npx $*" >> "$MOCK_LOG"
case "$*" in
  "playwright install chromium") exit "${MOCK_INSTALL_RC:-0}" ;;
  "playwright install-deps chromium")
    [ -z "${MOCK_DEPS_SLEEP:-}" ] || sleep "$MOCK_DEPS_SLEEP"
    exit "${MOCK_DEPS_RC:-0}" ;;
esac
exit 99
M
# Mock node (the launch probe): MOCK_PROBE_SEQ is a comma list of exit codes, one per probe call;
# the last entry repeats.
cat > "$BIN/node" <<'M'
#!/usr/bin/env bash
n="$(grep -c '^node probe' "$MOCK_LOG" || true)"
echo "node probe" >> "$MOCK_LOG"
IFS=, read -ra seq <<< "${MOCK_PROBE_SEQ:-0}"
idx=$n; [ "$idx" -lt "${#seq[@]}" ] || idx=$(( ${#seq[@]} - 1 ))
exit "${seq[$idx]}"
M
chmod +x "$BIN/npx" "$BIN/node"

RC=0; OUT=""; LOG=""; ELAPSED=0
run() { # run <VAR=val ...>; sets RC, OUT, LOG, ELAPSED
  local mock_log="$WORK/log"; : > "$mock_log"
  local t0=$SECONDS
  set +e
  OUT="$(env PATH="$BIN:$PATH" MOCK_LOG="$mock_log" DEPS_BACKOFF_SECONDS=0 "$@" bash "$SCRIPT" 2>&1)"
  RC=$?
  set -e
  ELAPSED=$((SECONDS - t0))
  LOG="$(cat "$mock_log")"
}
count() { printf '%s\n' "$LOG" | grep -cF -- "$1" || true; }

# 1 probe-first: launch works => deps skipped.
run X=1
[ "$RC" -eq 0 ] || fail "probe ok must exit 0 (rc=$RC)"
[ "$(count 'playwright install chromium')" -eq 1 ] || fail "expected one browser install"
[ "$(count 'install-deps')" -eq 0 ] || fail "probe ok must skip install-deps"
[ "$(count 'node probe')" -eq 1 ] || fail "expected exactly one probe"

# 2 browser install runs even on a cache hit (self-heals a bad cache entry).
run PLAYWRIGHT_CACHE_HIT=true
[ "$RC" -eq 0 ] || fail "cache hit must exit 0"
[ "$(count 'playwright install chromium')" -eq 1 ] || fail "cache hit must still run the (no-op) browser install"

# 3 probe fails, deps ok, probe ok.
run MOCK_PROBE_SEQ=1,0
[ "$RC" -eq 0 ] || fail "deps ok + probe ok must exit 0 (rc=$RC)"
[ "$(count 'install-deps')" -eq 1 ] || fail "expected one deps attempt"
[ "$(count 'node probe')" -eq 2 ] || fail "expected a probe before and after deps"

# 4 probe fails, deps fail twice (max 2), final probe ok => success with a warning.
run MOCK_PROBE_SEQ=1,0 MOCK_DEPS_RC=100
[ "$RC" -eq 0 ] || fail "deps fail + final probe ok must exit 0 (rc=$RC)"
[ "$(count 'install-deps')" -eq 2 ] || fail "expected exactly 2 deps attempts"
printf '%s' "$OUT" | grep -q '::warning::System dependency install failed' || fail "missing warning"

# 4b attempts are capped at 2 even when the env asks for more.
run MOCK_PROBE_SEQ=1,0 MOCK_DEPS_RC=100 DEPS_ATTEMPTS=9
[ "$(count 'install-deps')" -eq 2 ] || fail "DEPS_ATTEMPTS must be capped at 2"

# 5 everything fails: distinct infrastructure failure.
run MOCK_PROBE_SEQ=1 MOCK_DEPS_RC=100
[ "$RC" -eq 70 ] || fail "deps+probe failure must exit 70 (rc=$RC)"
printf '%s' "$OUT" | grep -q 'INFRASTRUCTURE FAILURE' || fail "missing infrastructure message"
printf '%s' "$OUT" | grep -q 'not a failed acceptance' || fail "message must say it is not a failed acceptance"

# 5b deps succeed but chromium still cannot launch: still 70, never a pass.
run MOCK_PROBE_SEQ=1
[ "$RC" -eq 70 ] || fail "deps ok but probe fail must exit 70 (rc=$RC)"

# 6 browser download fails: exit 1, nothing else runs.
run MOCK_INSTALL_RC=1
[ "$RC" -eq 1 ] || fail "download failure must exit 1 (rc=$RC)"
[ "$(count 'install-deps')" -eq 0 ] && [ "$(count 'node probe')" -eq 0 ] || fail "download failure must stop before probe/deps"
printf '%s' "$OUT" | grep -q 'INFRASTRUCTURE FAILURE' || fail "download failure must say infrastructure"

# 7 hung install-deps is killed by the timeout and the script still reaches the final probe in bounds.
run MOCK_PROBE_SEQ=1,0 MOCK_DEPS_SLEEP=30 DEPS_TIMEOUT_SECONDS=1 DEPS_ATTEMPTS=2
[ "$RC" -eq 0 ] || fail "hung deps + final probe ok must exit 0 (rc=$RC)"
[ "$(count 'install-deps')" -eq 2 ] || fail "both hung attempts must be tried"
[ "$ELAPSED" -lt 15 ] || fail "hung install-deps was not bounded by its timeout (took ${ELAPSED}s)"
printf '%s' "$OUT" | grep -q 'timed out after 1s' || fail "timeout must be reported"

# 8 non-numeric / zero / negative env falls back to the default (never skips or disables the step).
run MOCK_PROBE_SEQ=1,0 MOCK_DEPS_RC=100 DEPS_TIMEOUT_SECONDS=abc DEPS_ATTEMPTS=zero PROBE_TIMEOUT_SECONDS=0 DOWNLOAD_TIMEOUT_SECONDS=-5
[ "$RC" -eq 0 ] || fail "invalid env must still run normally (rc=$RC)"
[ "$(count 'install-deps')" -eq 2 ] || fail "invalid DEPS_ATTEMPTS must fall back to the default of 2, not skip"
printf '%s' "$OUT" | grep -q 'timed out after 40s' || fail "invalid DEPS_TIMEOUT_SECONDS must fall back to 40"

# 9 wiring: both workflows restore + save the browser cache around a successful install, call the
# helper, no apt-hitting --with-deps, and no continue-on-error anywhere (install or test steps).
for wf in ui-smoke calendar-course-acceptance; do
  f="$ROOT/.github/workflows/$wf.yml"
  grep -qF 'bash ../.github/scripts/install-playwright-chromium.sh' "$f" || fail "$wf must call the helper"
  grep -qF 'uses: actions/cache/restore@' "$f" || fail "$wf must use actions/cache/restore"
  grep -qF 'uses: actions/cache/save@' "$f" || fail "$wf must use actions/cache/save"
  grep -qF 'path: ~/.cache/ms-playwright' "$f" || fail "$wf must cache ms-playwright"
  grep -qF 'steps.playwright-version.outputs.version' "$f" || fail "$wf cache key must carry the Playwright version"
  if grep -q 'restore-keys' "$f"; then fail "$wf must not use restore-keys"; fi
  if grep -q 'playwright install --with-deps' "$f"; then fail "$wf still runs install --with-deps"; fi
  if grep -q 'continue-on-error' "$f"; then fail "$wf must not use continue-on-error"; fi
  # save runs after the install step and only on a cache miss
  awk '/Install Playwright/{i=NR} /actions\/cache\/save@/{s=NR} END{exit !(i && s && s>i)}' "$f" || fail "$wf: cache save must come after the install step"
  grep -qF "steps.playwright-cache.outputs.cache-hit != 'true'" "$f" || fail "$wf: cache save must be skipped on a hit"
done
echo "install-playwright-chromium.test.sh: OK"
