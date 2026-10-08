## 2026-10-08 — chore(ops): POP operation to correct three stale values of one paid monthly invoice (dry-run only, in-app #369)
<!-- release-notes: silent_ship=silent-2026-10-08-pop-invoice1997-stale-values -->
<!-- silent-reason: 只新增 POP 營運操作、dry-run workflow 與測試，尚未對正式站執行（需 Jerry 審核 digest），教職員看不到差異 -->
- 新增 POP 操作 `invoice1997-stale-values-20261008`：把一張已繳清月結帳單的明細金額、帳單快照與課程費用改成與 5 堂已上課一致（7500）；不動付款與帳單總額，可回復。另加 dry-run workflow。Refs #3356
