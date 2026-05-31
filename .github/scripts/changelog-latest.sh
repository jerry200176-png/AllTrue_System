#!/usr/bin/env bash
# #535 Phase 3.1 — 取出 docs/CHANGELOG.md「最新一節」（第一個 '## ' 標題到下一個 '## ' 之前）。
# 用途：release.yml 以此產生 GitHub Release notes / 解析日期當 CalVer tag。
# 本機可獨立執行驗證：./.github/scripts/changelog-latest.sh [path]
set -euo pipefail

CHANGELOG="${1:-docs/CHANGELOG.md}"

if [[ ! -f "$CHANGELOG" ]]; then
  echo "changelog-latest: file not found: $CHANGELOG" >&2
  exit 1
fi

awk '
  /^## / { if (seen) exit; seen = 1 }
  seen   { print }
' "$CHANGELOG"
