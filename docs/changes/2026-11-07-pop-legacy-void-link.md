## 2026-11-07 — chore(ops): POP operation to link two legacy void rows to their receipts (dry-run only, in-app #521)
<!-- release-notes: silent_ship=silent-2026-11-07-pop-legacy-void-link -->
<!-- silent-reason: 只新增 POP 營運操作、dry-run workflow 與測試，尚未對正式站執行（需 Jerry 審核 digest），教職員看不到差異 -->
- 新增 POP 操作 `legacy-void-link-20261107`：把兩筆沒有對應收據的舊沖銷紀錄記上它沖掉的收據；不動金額、帳單狀態與收據，可回復。另一筆有歧義的列在 runbook，不自動處理。Refs in-app #521
