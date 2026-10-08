## 2026-10-08 — chore(ops): POP operation to cancel three past still-scheduled sessions of one early-ended course (dry-run only, in-app #341)
<!-- release-notes: silent_ship=silent-2026-10-08-pop-cancel-past-scheduled -->
<!-- silent-reason: 只新增 POP 營運操作、dry-run workflow 與測試，尚未對正式站執行（需 Jerry 審核 digest），教職員看不到差異 -->
- 新增 POP 操作 `cancel-past-scheduled-course2942-20261008`：把一門提前結束的合約底下、已過期卻仍標「預排」的三堂改為取消；只改堂次狀態、不動帳務，可回復。另加 dry-run workflow。Refs #3199
