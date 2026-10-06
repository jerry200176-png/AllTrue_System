## 2026-10-06 — chore(governance): retire completed in-app #173 repair workflows
<!-- release-notes: silent_ship=silent-2026-10-06-retire-173-repair-workflows -->
<!-- silent-reason: 只移除已完成且已驗證的 #173 一次性修復 workflow 與指令（內部治理／CI），教職員看不到差異 -->
- 移除 `173-lr-merge-repair.yml`、`173-supersede-repair.yml` 與 `repair:merge-renewal-learning-record`（含測試）；證據記錄於 `PRODUCTION_WORKFLOW_INVENTORY.json` 的 `retired_workflows`。
