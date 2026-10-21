## 2026-10-21 — feat(billing): 「需核對：舊資料標記已收」可直接補登收款或改成未繳
<!-- release-notes: staff_update=staff-2026-10-21-legacy-paid-flag-resolve -->

- Founder GO 2026-10-21（`docs/DIRECTOR_PAYMENT_ALERT_RULES.md` §只有舊「已收」標記）：課程只有舊 `Paid=1` 標記、沒有任何收款紀錄時，學生帳務的合約卡原本只顯示「需核對」，登記收款又因「已收齊」被擋，主任無法處理。
- 合約卡改成說明下一步，並提供兩個動作（主任／超級管理員，限單一堂數制課程）：
  - 「已收過，補登收款」：`POST /api/v1/payment-reports/director-record` 加 `legacy_flag_backfill=true`＋`idempotency_key`；沒有帳單就依填寫金額開帳單，並建立已確認的回報與收款（與一般登記＋確認入帳相同），`Paid` 維持 1，「需核對」依既有規則消失。同一個 key 重送回傳第一次的結果，不會多收一筆。
  - 「其實沒收，改成未繳」：`POST /api/v1/payment-reports/legacy-flag-clear`（必填原因）只把 `Paid`／`Pay`／`PayDate` 清掉，不動帳單或收款；稽核事件只存原因的雜湊。
- 有淨收款、待核對或已確認的回報、月結、共用方案、輔導／免費、確認不收、其他校區時一律 422／403，不變更任何資料。不需 migration。
