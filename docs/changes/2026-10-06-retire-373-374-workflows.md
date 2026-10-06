## 2026-10-06 — chore(governance): retire students 373/374 one-off workflows
<!-- release-notes: silent_ship=silent-2026-10-06-retire-373-374-workflows -->
<!-- silent-reason: 只移除已結案的一次性修復 workflow 與腳本（內部治理／CI），教職員看不到差異 -->
- 移除 `ops-chinese-renewal-unpaid.yml`、`ops-move-aug05-to-chinese-renewal.yml`、`ops-session-entitlement-transfer.yml` 與其專屬腳本／測試；通用指令 `repair:transfer-session-entitlement` 保留；紀錄於 `PRODUCTION_WORKFLOW_INVENTORY.json` 的 `retired_workflows`。
