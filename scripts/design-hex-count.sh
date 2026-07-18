#!/usr/bin/env bash
# 計算各 .vue 檔案內 raw hex 色票數量，輸出 JSON
# 用途：追蹤 Epic #687 UI 去 AI 化治理 KPI
#
# 使用方式：
#   npm run metrics:design-hex          → 輸出 JSON 到 stdout
#   npm run metrics:design-hex > docs/design-hex-baseline-YYYY-MM-DD.json
#
# 不計入的情況：
#   - styles.css 中的 token 定義（:root { --ds-* }）
#   - var(--ds-*)、transparent、inherit
#   - Vue HTML、JS 單行／區塊、CSS 區塊註解中的色票或 issue reference

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FRONTEND_DIR="$ROOT/frontend/src"
GENERATED_AT="$(date '+%Y-%m-%d')"

# 統計單一檔案的 raw hex 數
count_file() {
  local file="$1"
  node "$ROOT/scripts/design-hex-counter.mjs" "$file"
}

pages_total=0
components_total=0
files_json=""

# Pages
while IFS= read -r -d '' file; do
  count=$(count_file "$file")
  rel="${file#$FRONTEND_DIR/}"
  if [ "$count" -gt 0 ]; then
    files_json="${files_json}{\"path\":\"frontend/src/${rel}\",\"count\":${count}},"
  fi
  pages_total=$((pages_total + count))
done < <(find "$FRONTEND_DIR/pages" -name "*.vue" -print0 2>/dev/null)

# Components
while IFS= read -r -d '' file; do
  count=$(count_file "$file")
  rel="${file#$FRONTEND_DIR/}"
  if [ "$count" -gt 0 ]; then
    files_json="${files_json}{\"path\":\"frontend/src/${rel}\",\"count\":${count}},"
  fi
  components_total=$((components_total + count))
done < <(find "$FRONTEND_DIR/components" -name "*.vue" -print0 2>/dev/null)

# 移除尾部逗號
files_json="${files_json%,}"

cat <<EOF
{
  "generated_at": "${GENERATED_AT}",
  "totals": {
    "pages": ${pages_total},
    "components": ${components_total},
    "grand_total": $((pages_total + components_total))
  },
  "files": [${files_json}]
}
EOF
