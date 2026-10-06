#!/usr/bin/env bash
# #535 Phase 3.1 — 取出 CHANGELOG「最新一節」（第一個 '## ' 標題到下一個 '## ' 之前）。
# 用途：release.yml 以此產生 GitHub Release notes / 解析日期當 CalVer tag。
# 無參數：取 docs/changes/<date>-<slug>.md 中檔名最新的一份（若有），否則凍結的 docs/CHANGELOG.md。
# ponytail: 同日多份 fragment 取檔名排序最後者，不一定是剛合併的那份；tag 只看日期，影響僅 Release 說明文字。
# 本機可獨立執行驗證：./.github/scripts/changelog-latest.sh [path]
set -euo pipefail

CHANGELOG="${1:-}"
if [[ -z "$CHANGELOG" ]]; then
  CHANGELOG=$(ls docs/changes/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]-*.md 2>/dev/null | LC_ALL=C sort | tail -1 || true)
  CHANGELOG="${CHANGELOG:-docs/CHANGELOG.md}"
fi

if [[ ! -f "$CHANGELOG" ]]; then
  echo "changelog-latest: file not found: $CHANGELOG" >&2
  exit 1
fi

awk '
  /^## / { if (seen) exit; seen = 1 }
  seen   { print }
' "$CHANGELOG"
