## 2026-10-14 — feat(triage): AI 分診唯讀金鑰可讀「超級管理員指定的單一合約」帳務數字（#4100，預設關閉，待 Founder 核准開啟）
<!-- release-notes: silent_ship=silent-2026-10-14-triage-billing-snapshot -->
<!-- silent-reason: 只有超級管理員能用 API 建立／撤銷連結，讀取只給 AI 分診唯讀金鑰；預設關閉，主任、老師與家長的畫面都不變 -->
- 新表 `bug_report_contract_links`：超級管理員把一張未結案回報連結到一份課程合約（每張回報同時只能有一個有效連結），撤銷只標記時間、保留紀錄。
- 新增 `GET /api/v1/triage/student-classes/{id}/billing-snapshot?bug_report_id=N`：只回 ID 與數字；必須同時符合「功能開關開啟、連結有效、回報未結案、同分校」，其他一律 404。建立、撤銷、讀取都寫入稽核紀錄。
- 開關 `TRIAGE_BILLING_SNAPSHOT_ENABLED` 預設關閉；本 PR 不含前端畫面。
