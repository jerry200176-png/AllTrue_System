#!/usr/bin/env bash
# Install Playwright Chromium for the post-deploy production smoke workflows
# (ui-smoke.yml, calendar-course-acceptance.yml) without letting an apt mirror outage
# masquerade as a failed acceptance (retro #5201 row 15, 2026-11-11).
#
# Run from the directory that has Playwright installed (frontend/).
# Env: PLAYWRIGHT_CACHE_HIT  "true" => ~/.cache/ms-playwright restored, skip the download
#      DEPS_ATTEMPTS         install-deps attempts (default 3)
#      DEPS_BACKOFF_SECONDS  base backoff, multiplied by the attempt number (default 10)
#      PROBE_TIMEOUT_SECONDS launch probe limit (default 90)
# Exit: 0 ok | 1 browser download failed | 70 deps unreachable AND chromium cannot launch.
# It never runs a test, so it can never mark a smoke as passed.
set -uo pipefail

attempts="${DEPS_ATTEMPTS:-3}"
backoff="${DEPS_BACKOFF_SECONDS:-10}"
probe_timeout="${PROBE_TIMEOUT_SECONDS:-90}"

if [ "${PLAYWRIGHT_CACHE_HIT:-}" = "true" ]; then
  echo "Playwright browser cache hit; skipping chromium download."
else
  if ! npx playwright install chromium; then
    echo "::error::INFRASTRUCTURE FAILURE: Playwright chromium download failed. No smoke test ran; this is not a failed acceptance. Re-run the workflow."
    exit 1
  fi
fi

deps_ok=false
i=1
while [ "$i" -le "$attempts" ]; do
  if npx playwright install-deps chromium; then
    deps_ok=true
    break
  fi
  echo "::warning::playwright install-deps chromium failed (attempt $i/$attempts)"
  if [ "$i" -lt "$attempts" ]; then sleep "$((backoff * i))"; fi
  i=$((i + 1))
done

if [ "$deps_ok" = "true" ]; then
  echo "System dependencies installed."
  exit 0
fi

probe='const { chromium } = require("@playwright/test");
(async () => { const b = await chromium.launch(); await b.close(); })()
  .catch((e) => { console.error(String(e.message).split("\n")[0]); process.exit(1); });'
if timeout "$probe_timeout" node -e "$probe"; then
  echo "::warning::System dependency install failed after $attempts attempts (package mirror unreachable?), but chromium launches headless; continuing. Tests still decide pass/fail."
  exit 0
fi

echo "::error::INFRASTRUCTURE FAILURE: package mirror unreachable and chromium cannot launch. No smoke test ran; this is not a failed acceptance. Re-run the workflow."
exit 70
