#!/usr/bin/env bash
# Run the @visual Playwright suite in the official image matching the installed @playwright/test (#4952).
# Usage: scripts/visual-docker.sh [--update-snapshots]   (CI sets VISUAL_NO_DOCKER=1: it already runs in the image)
set -euo pipefail
cd "$(dirname "$0")/.."
if [ -z "${VISUAL_NO_DOCKER:-}" ]; then
  v=$(node -p "require('./frontend/package-lock.json').packages['node_modules/@playwright/test'].version")
  exec docker run --rm --ipc=host --user "$(id -u):$(id -g)" -e HOME=/tmp -e VISUAL_NO_DOCKER=1 \
    -v "$PWD:/work" -w /work "mcr.microsoft.com/playwright:v${v}-noble" bash scripts/visual-docker.sh "$@"
fi
cd frontend
npm ci --no-audit --no-fund --loglevel=error
npx playwright test -c playwright.visual.config.js "$@"
