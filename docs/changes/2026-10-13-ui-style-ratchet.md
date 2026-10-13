## 2026-10-13 — ui: 樣式只降不升檢查（字級／圓角／陰影／emoji／alert）與 AtIcon 圖示元件
<!-- release-notes: silent_ship=silent-2026-10-13-ui-style-ratchet -->
<!-- silent-reason: 只新增檢查腳本與尚未使用的圖示元件，沒有任何畫面改變。 -->
- 新增 `scripts/design-style-ratchet.mjs` 與基線 `docs/design-style-baseline.json`，併入 `npm run lint:design`：任何檔案的非 token 字級、圓角、陰影、模板 emoji、原生 alert/confirm 數量增加就失敗。新增 `AtIcon`（15 個 SVG 圖示）。Refs #3115
