## 2026-10-13 — ops: 部署時 migration 失敗即中止並回滾（D1）
<!-- release-notes: silent_ship=silent-2026-10-13-deploy-migration-abort -->
<!-- silent-reason: 只改部署控制面，沒有使用者可見變更。 -->
- `deploy.yml`：`migrate` 或 `migrate:status` 失敗改為 `abort_deploy`（既有 rollback 路徑），不再讓新 code 繼續上線、run 結尾才標紅；`test_deploy_activation_state.py` 新增實際執行 migration 區塊的 contract test（九大面向計畫 D1）。
