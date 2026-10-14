## 2026-10-14 — feat(triage): AI 分診唯讀金鑰可讀單一課程的帳務數字（#4100，待 Founder 核准）
<!-- release-notes: silent_ship=silent-2026-10-14-triage-billing-snapshot -->
<!-- silent-reason: 只給持有超級管理員核發之 AI 分診唯讀金鑰的 AI 工作階段使用；主任、老師與家長的畫面都不變 -->
- 新增 `GET /api/v1/triage/student-classes/{id}/billing-snapshot?bug_report_id=N`：只回 ID 與數字（費率、堂數、總費用、費率×數量、差額、帳單與付款金額），不含姓名、備註或任何文字。
- 只能讀「未結案回報（new／triaged／in_progress）所在分校」的課程；其他一律 404。每次呼叫都寫入既有的分診稽核紀錄。
