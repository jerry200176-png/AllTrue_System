## 2026-11-15 — fix(billing): 零堂數結案合約重算後保留結案狀態
<!-- release-notes: staff_update=staff-2026-11-15-prepaid-closure-recount -->
- 堂數制合約已明確結案且堂數為零時，重算堂數不再嘗試重新啟用；已退費合約也不再因此觸發禁止變更的錯誤。
- 收款、退費與月結既有行為保留。共用帳務轉移及預覽鎖定邏輯供後續跨科轉用重用；本批不新增執行端點或稽核資料表。
- Refs #5197；SourceRef: alltrue:bug_report:535。完整跨科轉用仍未提供。
