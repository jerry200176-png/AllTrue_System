## 2026-11-07 — chore(ops): POP operation to link two legacy void rows to their receipts (dry-run only, in-app #521)
<!-- release-notes: silent_ship=silent-2026-11-07-pop-legacy-void-link -->
<!-- silent-reason: 只準備 POP 營運操作與測試，不新增遠端寫入 adapter，尚未對正式站執行（需 Jerry 審核 digest），教職員看不到差異 -->
- 新增 POP 操作 `legacy-void-link-20261107`：把兩筆沒有對應收據的舊沖銷紀錄記上它沖掉的收據；不動金額、帳單狀態與收據，可回復。另一筆有歧義的列在 runbook，不自動處理。Refs in-app #521

- 審查修正：將預覽 digest 納入核准參數並在鎖內重驗，綁定帳單／付款／收據現況；保留原始時間戳，回復遇到後續變動即停止。移除擷取人員 session 的 SSH workflow，沿用既有合法 POP 控制面與 Pi 本機 executor。正式修復仍需最新預覽與逐案核准。
