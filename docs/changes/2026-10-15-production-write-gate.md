## 2026-10-15 — ci(security): 會寫入正式環境的 Pi SSH job 一律等 Founder 核准（`production-activation`，Founder Q2）
<!-- release-notes: silent_ship=silent-2026-10-15-production-write-gate -->
<!-- silent-reason: 只改 GitHub Actions 工作流程的 environment、CI 規則與維運 runbook；網站與 App 的功能、畫面和資料都不變 -->
- 25 個會寫入正式環境的 job 從 `production-ssh`（無 reviewer）改為 `production-activation`（Founder 為 required reviewer），每次執行都要 Founder 在該 run 核准；唯讀 job 留在 `production-ssh`。
- `docs/governance/PRODUCTION_WORKFLOW_INVENTORY.json` 新增 `job_gates`：每個讀 Pi SSH secrets 的 job 都有 writes／gate／file:line 證據。
- control-plane-lint 新增 P3 規則（`scripts/ci/production-write-gate.mjs`）：寫入 job 沒宣告 `production-activation`、未分類、或唯讀 job 帶寫入標記即失敗；兩個排程的 in-app 回報寫入與 Founder 1A 自動部署列為明示的 founder-decision 例外（每筆需附授權、範圍、冪等、停止開關、稽核與隔離證據）。
- `ops-leave-hc-review-tracker.yml` 的 LINE 推播 job 不再隨每日排程執行（排程只跑 track）。
- `backup-restore-test.yml`（每月 1 日 02:00 UTC）改為 `production-activation`：沒有 Founder 決議紀錄，且正式 MySQL 上暫存還原的隔離與失敗清理尚未證明，排程執行會等 Founder 核准（不是發現已污染）。
