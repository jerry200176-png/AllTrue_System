## 2026-10-29 — test(rfid): 只有主任能綁學生卡片，老師一律 403（#2809）
<!-- release-notes: silent_ship=silent-2026-10-29-rfid-enrolment-director-only-test -->
<!-- silent-reason: 只新增測試，鎖住現有行為（綁卡路由本來就只有主任能用），沒有任何畫面或 API 行為改變 -->
- 新增 `RfidEnrolmentDirectorOnlyTest`：老師用綁卡（`bind-card`）、改學生卡號、建學生帶卡號、刷卡待處理指派學生四條路都回 403 且卡號不變；主任仍可綁卡。無程式碼變更，不需 migration；回滾＝還原這個 PR。
