## 2026-10-06 — chore(ops): TD-076 Track B R-1 collision-keeper repair as a POP operation (dry-run only)
<!-- release-notes: silent_ship=silent-2026-10-06-td076-pr-e-repairs -->
<!-- silent-reason: 只新增 POP 營運操作與測試，尚未對正式站執行（需 Jerry 審核 digest），教職員看不到差異 -->
- 新增 POP 操作 `td076-r1-collision-keepers-20261006`：同一堂次有多筆有效排程時保留對應課堂時段的那一筆，其餘標為 `superseded`（可回復、不刪除）；有疑義的群組只列出不動。僅 dry-run，Founder 核對 digest 後才可執行。Refs #3590
