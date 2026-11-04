## 2026-11-04 — chore(ops): POP operation to repair three invoices' total and item amount/course link (dry-run only, GitHub #4850 #4863)
<!-- release-notes: silent_ship=silent-2026-11-04-pop-invoice-header-item-repair -->
<!-- silent-reason: 只新增 POP 營運操作、dry-run workflow 與測試，尚未對正式站執行（需 Jerry 審核 digest），教職員看不到差異 -->
- 新增 POP 操作 `invoice-header-item-repair-20261104`：把三張帳單的總額、明細金額與明細所屬合約改成已確認的值；不動付款、已付金額與狀態，可回復。另加 dry-run workflow。Refs #4850 #4863
