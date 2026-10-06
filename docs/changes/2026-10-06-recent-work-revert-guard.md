## 2026-10-06 — chore(ci): presubmit blocks PRs that silently revert recently merged work
<!-- release-notes: silent_ship=silent-2026-10-06-recent-work-revert-guard -->
<!-- silent-reason: 只新增合併前的 CI 檢查，教職員看到的畫面與功能完全不變。 -->
- 新增 `scripts/check-recent-work-revert.py`（presubmit CHECK 0d）：PR 對 current origin/main 刪除的行（`backend/app`、`frontend/src`、`scripts`、`.github`），若 `git blame` 顯示 7 天內由其他 PR 合進 main，即失敗並列表；純搬移（同 PR 內相同行重新出現）略過。例外：PR 標籤 `intentional-revert`＋內文理由，加標籤後需重跑 presubmit。
