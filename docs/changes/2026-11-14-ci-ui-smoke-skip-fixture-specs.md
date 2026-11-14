## 2026-11-14 — fix(ci): UI Smoke 略過只能在假資料伺服器跑的版面守門測試
<!-- release-notes: silent_ship=silent-2026-11-14-ci-ui-smoke-skip-fixture-specs -->
<!-- silent-reason: CI 測試設定調整，教職員看不到任何差異。 -->
- 版面守門與浮層圖庫測試只在假資料伺服器的 UI foundation 工作執行；對正式站的 UI Smoke 不再跑它們而逾時。
