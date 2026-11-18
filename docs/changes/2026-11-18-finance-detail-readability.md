## 2026-11-18 — fix(ui): keep summary amounts and weekly explanations readable
<!-- release-notes: staff_update=staff-2026-11-18-finance-detail-readability -->

- 月對帳表摘要依金額需要的寬度分配欄位，空間不足便換列，保留全部金額尾數、原字級與顏色；不再以固定等寬欄裁掉數字。
- 正職薪資要件展開老師明細時，週別、完整判定與逐日時數會依可用寬度換行，避免手機較大字級截字或把說明擠成極窄欄。
- 只調整顯示，不改帳務資料、學收／薪資計算、權限或操作；保留原有達標／未達／尚未結束與取消／改期說明。
- 沿用既有必跑 Playwright，覆蓋三字級與手機、筆電、桌面可用寬度；檢查每格金額及明細的實際文字邊界，不只量整頁寬度。
