## 2026-11-07 — fix(ci): review carries over when only main was merged into the PR
<!-- release-notes: silent_ship=silent-2026-11-07-review-carry-trivial-rebase -->
<!-- silent-reason: 只改合併前審查閘門的判斷方式，教職員看到的畫面與功能完全不變。 -->
- `review-gate.mjs`：PR 只是把 main 合進來（PR 自己的 diff `git patch-id --stable` 與逐行內容不變，且之後新增的 commit 全是 main 的 merge）時，較早 head 上的 Codex 完成狀態、`independent-review` 標記與 R3 標記沿用到新 head；解衝突改動、非 merge commit、二進位檔變動或任何 API 錯誤都要重新審查。
