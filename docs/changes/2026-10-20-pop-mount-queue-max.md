## 2026-10-20 — fix(ci): pop-mount shared lock queues instead of replacing pending runs
<!-- release-notes: silent_ship=silent-2026-10-20-pop-mount-queue-max -->
<!-- silent-reason: 只補齊既有 production lock 的 queue:max，不改帳務與使用者可見行為。 -->
- `pop-mount-reviewed-financial-once.yml` 加入 `alltrue-production-side-effects-v2` 其他成員已有的 `queue: max`，讓 Deploy contract 不再因這個 workflow 失敗。
