## 2026-11-04 — fix(ui): 更多頁面載入時顯示進度線（in-app #493 / #4879）
<!-- release-notes: staff_update=staff-2026-11-04-loading-bar-main-pages -->
- Main director pages, their calendar/course-management composables and direct child components now call `trackedFetch` instead of raw `fetch`, so the top loading bar shows on slow requests there. No URL, option or error-handling change. Telemetry, polling and the remaining files are untouched.
