## 2026-10-13 — ci(control-plane): shared production lock queues pending runs (`queue: max`)
<!-- release-notes: silent_ship=silent-2026-10-13-production-lock-queue-max -->
<!-- silent-reason: 只改 GitHub Actions 併發設定與 CI lint，不改任何使用者可見行為。 -->
- 所有使用 `alltrue-production-side-effects-v2` 的 workflow／job 一律加上 `queue: max`：後來的 run 依序排隊，不再取消已在 pending 的部署或修復。
