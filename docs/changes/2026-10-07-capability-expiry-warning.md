## 2026-10-07 — chore(governance): warn 7 days before a high-risk capability review expires (#3491)
<!-- release-notes: silent_ship=silent-2026-10-07-capability-expiry-warning -->
<!-- silent-reason: 只改 CI 治理檢查（提早警告權限複驗到期），畫面與功能不變。 -->
- `validate-capability-registry.py` 在高風險能力 `review_after` 前 7 天發 CI warning，避免到期當天所有 PR 必要檢查突然失敗（10-04、10-08 兩次）。
