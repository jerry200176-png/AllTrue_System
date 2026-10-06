## 2026-10-06 — chore(governance): retire 1387 grant, student9, learning-record-integrity and muzha-gaorui workflows
<!-- release-notes: silent_ship=silent-2026-10-06-retire-1387-student9-lr-muzha-workflows -->
<!-- silent-reason: 只移除已結案的一次性修復 workflow 與腳本（內部治理／CI），教職員看不到差異 -->
- 移除 `1387-db-grant-repair.yml`、`ops-founder-student9-attendance-repair.yml`（含 `repair:founder-student9-attendance` 與測試）、`ops-learning-record-integrity.yml`、`ops-muzha-gaorui-2026-07-30-containment.yml`（含其 PHP 腳本）；`repair:confirmed-attendance-assessment` 與 `learning-records:integrity-scan` 保留；紀錄於 `PRODUCTION_WORKFLOW_INVENTORY.json` 的 `retired_workflows`。
