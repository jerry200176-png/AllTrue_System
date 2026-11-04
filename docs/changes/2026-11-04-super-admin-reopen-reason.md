## 2026-11-04 — fix(course): 恢復課程 lets a super admin give the reason a mistaken close needs (in-app #484)
<!-- release-notes: silent_ship=silent-2026-11-04-super-admin-reopen-reason -->
<!-- silent-reason: 只有系統管理員（super admin）看得到的原因欄位，主任與老師畫面不變 -->
- Founder 2026-10-28：誤結案只能由系統管理員填原因後恢復，並留紀錄。後端早已接受 `reopen_reason` 並寫入稽核，但畫面沒有欄位可填。現在系統管理員在「恢復課程？」視窗會看到原因欄位，送出時一起帶上。Refs #4857
