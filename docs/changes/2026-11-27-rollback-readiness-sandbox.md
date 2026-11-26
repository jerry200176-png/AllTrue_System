## 2026-11-27 — 回滾就緒檢查不再動到工作中的檔案
<!-- release-notes: silent_ship=silent-2026-11-27-rollback-readiness-sandbox -->
<!-- silent-reason: Developer check script only; no product behaviour. -->

- `scripts/rollback-readiness.sh` 的「模擬 revert」改在暫時的 worktree 內執行，不會再把本機還沒 commit 的改動洗掉（#4056 第 5 項）。
