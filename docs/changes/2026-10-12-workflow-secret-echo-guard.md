## 2026-10-12 — ci: 新增檢查，禁止 workflow 把資料庫密碼或 .env 內容印到公開日誌
<!-- release-notes: silent_ship=silent-2026-10-12-workflow-secret-echo-guard -->
<!-- silent-reason: 只新增 CI 檢查腳本，沒有任何使用者可見變更。 -->
- 新增 `scripts/ci/workflow-secret-echo.test.mjs`（併入 `npm run test:gov`）：任何 workflow 出現 `set -x`、`printenv`、`cat .env`、echo 密碼變數，或寫死非 CI 範本的密碼，檢查就失敗。Refs #1387
