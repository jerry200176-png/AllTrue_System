## 2026-10-06 — chore(ops): TD-076 Track B R-2 history-pin repair as a POP operation (dry-run only)
<!-- release-notes: silent_ship=silent-2026-10-06-td076-pr-e2-history-pins -->
<!-- silent-reason: 只新增 POP 營運操作、dry-run workflow 與測試，尚未對正式站執行（需 Jerry 審核 digest），教職員看不到差異 -->
- 新增 POP 操作 `td076-r2-history-pins-20261006`：為已授課的過去堂次補上「當時授課老師」的釘選紀錄，證據互相矛盾的堂次只列出不動；可回復。另加 dry-run workflow。Refs #3590
