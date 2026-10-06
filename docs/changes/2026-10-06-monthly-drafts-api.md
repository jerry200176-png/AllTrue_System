## 2026-10-06 — feat(billing): read-only monthly drafts list API (plan B step 1)
<!-- release-notes: silent_ship=silent-2026-10-06-monthly-drafts-api -->
<!-- silent-reason: 只新增唯讀 API，畫面在下一個 PR 才出現 -->
- 新增唯讀 API `GET /api/v1/accounting/monthly-drafts`，列出主任校區內本月待續開帳單的月繳合約與預估金額；月結續約與預覽共用同一個期間／金額計算。
