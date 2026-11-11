#!/usr/bin/env bash
# Install Playwright Chromium for the post-deploy production smoke workflows
# (ui-smoke.yml, calendar-course-acceptance.yml) without letting an apt mirror outage
# masquerade as a failed acceptance, and without eating the job's time budget
# (retro #5201 row 15; first real run on main: 3 hung install-deps attempts took ~14 min).
#
# Order: browser install (bounded, no-op when cached) -> launch probe -> only if the probe
# fails: install-deps (max 2 bounded attempts) -> probe again.
#
# Run from the directory that has Playwright installed (frontend/).
# Env (each must be a positive integer, otherwise the default is used, never "skip"):
#   DOWNLOAD_TIMEOUT_SECONDS  default 90   bound for `playwright install chromium`
#   PROBE_TIMEOUT_SECONDS     default 20   bound for one launch probe
#   DEPS_TIMEOUT_SECONDS      default 40   bound for one `install-deps` attempt
#   DEPS_ATTEMPTS             default 2    capped at 2
#   DEPS_BACKOFF_SECONDS      default 5    (0 allowed), slept between attempts
#   PLAYWRIGHT_CACHE_HIT      informational only
# Worst case: 90 + 20 + 2*40 + 5 + 20 = 215 s, plus a 5 s kill grace on each of the 5 bounded
# calls = 240 s (4 min).
# Exit: 0 chromium launches | 1 browser download failed | 70 chromium cannot launch.
# It never runs a test, so it can never mark a smoke as passed.
set -uo pipefail

KILL_GRACE=5

pos_int() { # pos_int <value> <default>: positive integer or the default
  if [[ "${1:-}" =~ ^[1-9][0-9]{0,5}$ ]]; then echo "$1"; else echo "$2"; fi
}
nonneg_int() {
  if [[ "${1:-}" =~ ^[0-9]{1,5}$ ]]; then echo "$((10#$1))"; else echo "$2"; fi
}

download_timeout="$(pos_int "${DOWNLOAD_TIMEOUT_SECONDS:-}" 90)"
probe_timeout="$(pos_int "${PROBE_TIMEOUT_SECONDS:-}" 20)"
deps_timeout="$(pos_int "${DEPS_TIMEOUT_SECONDS:-}" 40)"
attempts="$(pos_int "${DEPS_ATTEMPTS:-}" 2)"
[ "$attempts" -le 2 ] || attempts=2
backoff="$(nonneg_int "${DEPS_BACKOFF_SECONDS:-}" 5)"

probe_js='const { chromium } = require("@playwright/test");
(async () => { const b = await chromium.launch(); await b.close(); })()
  .catch((e) => { console.error(String(e.message).split("\n")[0]); process.exit(1); });'
probe() { timeout -k "$KILL_GRACE" "$probe_timeout" node -e "$probe_js"; }

echo "Playwright browser cache hit: ${PLAYWRIGHT_CACHE_HIT:-unknown}"
# Always run: a no-op when the browser is present, repairs a bad cache entry otherwise.
if ! timeout -k "$KILL_GRACE" "$download_timeout" npx playwright install chromium; then
  echo "::error::INFRASTRUCTURE FAILURE: Playwright chromium download failed or timed out after ${download_timeout}s. No smoke test ran; this is not a failed acceptance. Re-run the workflow."
  exit 1
fi

if probe; then
  echo "Chromium launches headless; skipping install-deps."
  exit 0
fi

deps_ok=false
i=1
while [ "$i" -le "$attempts" ]; do
  if timeout -k "$KILL_GRACE" "$deps_timeout" npx playwright install-deps chromium; then
    deps_ok=true
    break
  fi
  echo "::warning::playwright install-deps chromium failed or timed out after ${deps_timeout}s (attempt $i/$attempts)"
  if [ "$i" -lt "$attempts" ]; then sleep "$backoff"; fi
  i=$((i + 1))
done

if probe; then
  if [ "$deps_ok" = "true" ]; then
    echo "System dependencies installed; chromium launches headless."
  else
    echo "::warning::System dependency install failed (package mirror unreachable?), but chromium launches headless; continuing. Tests still decide pass/fail."
  fi
  exit 0
fi

echo "::error::INFRASTRUCTURE FAILURE: chromium cannot launch (system dependencies unavailable, package mirror unreachable?). No smoke test ran; this is not a failed acceptance. Re-run the workflow."
exit 70
