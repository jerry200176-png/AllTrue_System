## 2026-10-13 — ci: land queue 不再合併仍有未解決 review thread 的 PR
<!-- release-notes: silent_ship=silent-2026-10-13-land-queue-thread-gate -->
<!-- silent-reason: 只改 CI 合併佇列腳本，沒有任何使用者可見變更。 -->
- `.github/scripts/land-queue.mjs`：每個排隊 PR 以 GraphQL 讀 review thread；有未解決 thread 就移除 `queue` 並留言，數量不明（查詢失敗或超過 100 條）一律不合併，批次合併同規則。對齊 AGENTS.md 與 Founder 常設決定 ④。Refs #3899
