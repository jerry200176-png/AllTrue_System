#!/usr/bin/env bash
# Design raw-hex guard（Epic #687 / #689）：只擋「新增」raw hex 色票，允許治理既有頁面時下降。
#
# 機制：以 docs/design-hex-baseline.json（由 scripts/design-hex-count.sh 產生的快照）為基準，
#       重算當前每個 .vue 檔的 raw hex 數，任何檔「高於 baseline」即視為新增違規。
#
# 用法：
#   npm run lint:design            （從 frontend/ 執行 → bash ../scripts/check-no-raw-hex.sh）
#   bash scripts/check-no-raw-hex.sh
#
# 治理既有頁面使 hex 下降後：請同步更新 baseline
#   bash scripts/design-hex-count.sh > docs/design-hex-baseline.json
#
# 退出碼：有新增違規 → 1（CI 首期以 advisory / continue-on-error 呈現，不擋 merge）。

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASELINE="$ROOT/docs/design-hex-baseline.json"

if [ ! -f "$BASELINE" ]; then
  echo "[design-hex-guard] 找不到 baseline：$BASELINE"
  echo "  請先執行：bash scripts/design-hex-count.sh > docs/design-hex-baseline.json"
  exit 1
fi

CURRENT="$(bash "$ROOT/scripts/design-hex-count.sh")"

node -e '
  const fs = require("fs");
  const base = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
  const cur = JSON.parse(process.argv[2]);
  const bmap = Object.fromEntries((base.files || []).map((f) => [f.path, f.count]));
  let violations = 0;
  for (const f of cur.files || []) {
    const b = bmap[f.path] || 0;
    if (f.count > b) {
      console.log(`  \u2717 ${f.path}: ${b} \u2192 ${f.count} (+${f.count - b})`);
      violations++;
    }
  }
  const curTotal = (cur.totals && cur.totals.grand_total) || 0;
  const baseTotal = (base.totals && base.totals.grand_total) || 0;
  if (violations > 0) {
    console.log("");
    console.log(`[design-hex-guard] ${violations} 個檔案新增 raw hex 色票（總計 ${baseTotal} \u2192 ${curTotal}）。`);
    console.log("修法：改用 var(--ds-*) token，見 docs/RULE_DESIGN_SYSTEM.md \u00A711。");
    console.log("若為「治理既有頁使 hex 下降」，請更新 baseline：");
    console.log("  bash scripts/design-hex-count.sh > docs/design-hex-baseline.json");
    process.exit(1);
  }
  console.log(`[design-hex-guard] OK：無新增 raw hex（總計 ${curTotal}，baseline ${baseTotal}）。`);
' "$BASELINE" "$CURRENT"
