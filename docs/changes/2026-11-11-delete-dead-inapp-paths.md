## 2026-11-11 — chore(in-app): delete retired write paths
<!-- release-notes: silent_ship=silent-2026-11-11-delete-dead-inapp-paths -->
<!-- silent-reason: 只刪除已停用、沒有呼叫端的 workflow 與 script；教職員畫面與現行回寫路徑不變 -->

- 刪除已停用的 SSH allowlist 回寫（`bug-phase-c-allowlist.yml`、`bug-phase-c-autodispatch.yml`、request 檔）、in-app #289 用完的 4 個 preflight workflow。
- 現行路徑不變：分類 `bug-phase-a-triage.yml`、留言 `bug-followup-comment.yml`、結案 POP `inapp-report-resolve-after-deploy`。
