## 2026-10-07 — chore(privacy): retire name-bearing one-off course-ops workflow and scrub names from test identifiers (#3627)
<!-- release-notes: silent_ship=silent-2026-10-07-privacy-names-in-source -->
<!-- silent-reason: 只移除已執行完畢的一次性正式站 workflow／腳本並改測試變數名稱，畫面與功能不變。 -->
- 退役 `wuaitong-course-ops-20260827` workflow 與其 PHP 腳本（內含人名）；前端測試的變數名稱改為課程編號。證據記於 `PRODUCTION_WORKFLOW_INVENTORY.json` `retired_workflows`；名稱保留在 `.github/pii-log-workflows.txt` 供每小時清理。
