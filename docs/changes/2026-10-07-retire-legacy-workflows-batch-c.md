## 2026-10-07 — chore(governance): retire lu-yue-1513, transferred-ledger, unattended-29212, wuaitong-signin workflows
<!-- release-notes: silent_ship=silent-2026-10-07-retire-legacy-workflows-c -->
<!-- silent-reason: 只移除已結案的一次性正式站修復 workflow（Founder 2026-10-06 決定），畫面與功能不變。 -->
- 退役 4 個一次性修復 workflow 與 `repair:unattended-session-29212` 指令／測試；通用的 `repair:transferred-session-ledger` 與其 #2833 守門測試保留。證據記於 `PRODUCTION_WORKFLOW_INVENTORY.json` `retired_workflows`；名稱保留在 `.github/pii-log-workflows.txt` 供每小時清理。
