## 2026-11-25 — chore(ops): POP 單案結清合約 2201 沒用到的堂數（in-app #594，尚未執行）
<!-- release-notes: silent_ship=silent-2026-11-25-pop-unused-lessons-closeout-5617 -->
<!-- silent-reason: 只新增一個受審批的內部單案 POP 操作與測試，尚未對正式站執行（需 Jerry 審核），教職員看不到差異 -->
- 新 POP 操作 `unused-lessons-contract-closeout-5617`：合約 2201 只留實際上過的 2 堂。6 堂已過日期、沒點名的預排課改為取消；合約 8 堂改 2 堂、剩餘 0、合約金額改成單價 × 2、標記合約調整結束；唯一一張帳單跟著改成同一金額，付款狀態由既有核對規則算出。已上的課、收款、回報與收據都不動；任何條件不符就整筆不做。沒有正式站的一鍵回復；執行紀錄保留原值，要還原需另開一個經 Jerry 審核的操作。Runbook：`docs/incidents/RM-UNUSED-LESSONS-CLOSEOUT-5617.md`。Refs #5617
