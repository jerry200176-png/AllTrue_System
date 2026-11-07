## 2026-11-07 — fix(billing): voiding an already-reversed receipt no longer cancels another transfer (in-app #521)
<!-- release-notes: staff_update=staff-2026-11-07-inapp-521-transfer-void-double-reversal -->
- 撤銷收款時，如果這張收據的錢早就被舊流程沖掉了，不會再多扣一次；以前會連帶抵銷同一張帳單上另一筆真的匯款，結果明明有匯款卻顯示未繳，再收一次又變多收。
- 只改「撤銷收款」的寫入規則，不改未繳／已繳判斷；既有資料不動。
