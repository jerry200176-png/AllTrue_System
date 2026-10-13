## 2026-10-13 — fix(bugs): 標記已解決時，最新公開回覆必須同時寫出「確認已修好」與「問題仍存在」
<!-- release-notes: staff_update=staff-2026-10-13-resolve-reply-both-outcomes -->
- `POST /api/v1/bugs/{id}/status` 轉 `resolved` 時，最新一則公開留言缺任一句就回 422 `missing_both_outcomes_reply`（字串來源 `BugReportService::RESOLVE_REPLY_REQUIRED_PHRASES`）；只影響 API 路徑，Phase-C allowlist 仍由既有 contract test 把關。Bug 回報頁選「已解決」時預填含兩句的回覆範本。Refs #3750
