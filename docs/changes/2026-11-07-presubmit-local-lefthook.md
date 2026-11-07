## 2026-11-07 — chore(ci): local pre-push (lefthook) mirrors presubmit; feature PRs no longer rewrite ratchet baselines
<!-- release-notes: silent_ship=silent-2026-11-07-presubmit-local-lefthook -->
<!-- silent-reason: 只改開發流程（本機 push 前檢查與 CI 規則），教職員看到的畫面與功能完全不變。 -->
- `lefthook.yml` pre-push 在本機跑與 CI presubmit 相同的檢查（preflight、700 行 size gate、UI ratchet、eslint unused baseline、PR declaration），只跑有變動路徑的項目；`npm ci`（根目錄）自動安裝，`LEFTHOOK=0` 可略過。
- Presubmit 新增 CHECK 0c3：非 `chore/` 分支若只是改小 `docs/design-style-baseline.json`／`scripts/controller-db-baseline.json`（數字沒有上升）會被擋，改由每週 `ratchet-baseline-weekly.yml` 開單一 PR 鎖定進步。
