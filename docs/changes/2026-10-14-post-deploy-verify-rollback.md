## 2026-10-14 — ci(deploy): 上線後自動驗證、失敗自動回滾（遇 migration 不回滾）（#4053）
<!-- release-notes: silent_ship=silent-2026-10-14-post-deploy-verify-rollback -->
<!-- silent-reason: 只改部署流程的上線後驗證與自動回滾，主任、老師與家長的畫面和功能都不變 -->
- 部署完成後，用有上限的重試確認 `/api/health`、`/api/v1/health` 正常，且 `deployment.json` 的 backend SHA（前端有改時連同 frontend SHA）就是這次的目標版，確認後才跑 smoke。
- 驗證或 smoke 失敗時，自動回滾到上一個部署版並重新驗證；範圍內有 migration 或已跑過 migration 時改為標紅警示、不回滾，部署流程也不再自動執行 `migrate:rollback`。
- GitHub runner 另外從外部重查一次，失敗時在 step summary 寫明結果（已回滾／回滾失敗／因 migration 暫停／SSH 斷線）。
