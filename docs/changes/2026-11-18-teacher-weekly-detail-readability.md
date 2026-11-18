## 2026-11-18 — fix(ui): keep salary weekly details readable at every text size
<!-- release-notes: staff_update=staff-2026-11-18-teacher-weekly-detail-readability -->

- 正職薪資要件展開老師明細時，週別、完整判定與逐日時數會依可用寬度換行，避免手機較大字級截斷「還沒結束，先不判定」或把說明擠成極窄欄。
- 只調整顯示，不改段數、倍率、獎金、薪資設定或權限；保留達標、未達與尚未結束三種原有判定及取消／改期說明。
- 沿用既有必跑 Playwright 版面測試，展開真實 Vue 明細，覆蓋三種字級與手機、筆電、桌面可用寬度；逐段檢查文字邊界，不只量整頁寬度。
