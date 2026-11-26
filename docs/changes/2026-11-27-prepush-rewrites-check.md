## 2026-11-27 — chore(prepush): 推送前檢查 Rewrites 行
<!-- release-notes: silent_ship=silent-2026-11-27-prepush-rewrites-check -->
<!-- silent-reason: 只改開發工具（推送前檢查腳本），教職員看不到差異 -->
- `scripts/agent-prepush.sh` 多跑一項 `scripts/check-recent-work-revert.py`（presubmit CHECK 0d）：改到別的 PR 最近 7 天合併的程式行、又沒有 `Rewrites: #N — 原因` 時，推送前就擋下並列出要補的 PR 編號。PR 還沒開時，寫在 commit 訊息也算數；開 PR 時同一行要放進 PR 說明。
