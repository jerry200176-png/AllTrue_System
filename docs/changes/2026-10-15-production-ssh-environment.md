## 2026-10-15 — ci(security): Pi SSH 金鑰只給 main 上、宣告 `production-ssh` environment 的 job 使用（#4143）
<!-- release-notes: silent_ship=silent-2026-10-15-production-ssh-environment -->
<!-- silent-reason: 只改 GitHub Actions 工作流程、CI 規則與維運 runbook；網站與 App 的功能、畫面和資料都不變 -->
- 每個讀 Pi SSH secrets 的 job 都宣告 `environment: production-ssh`（只允許 `main`）；原本用 `production-activation` 的 job 保留該 environment，它也要放同一組 secrets 並設 main-only。
- control-plane-lint 新增 P2 規則（`scripts/ci/production-ssh-environment.mjs`），與 #4140 的 P1 觸發規則並行；兩者都能辨識 `secrets . X`、`secrets [ 'X' ]`、`secrets.*` 等寫法。
- 新 runbook `docs/RUNBOOK_PRODUCTION_SSH_ENVIRONMENT.md`：secret 格式、合併前先建 environment、main 上真的跑過 SSH 的驗證、非 main 被拒的驗證、回滾順序。
