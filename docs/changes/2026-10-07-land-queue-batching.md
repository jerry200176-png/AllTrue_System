## 2026-10-07 — chore(ci): land queue tests 2-4 queued PRs together on one batch branch
<!-- release-notes: silent_ship=silent-2026-10-07-land-queue-batching -->
<!-- silent-reason: 只改合併佇列的內部流程，教職員看到的畫面與版本更新內容完全不變。 -->
- `land-queue` 在 ruleset 關閉 strict（require up-to-date）後，會把最舊的 2-4 個綠燈 `queue` PR 一起合到 `chore/land-queue-batch-<run>`，在合併結果上跑全部 required checks；全綠才依序 squash merge，紅燈則成員改回逐一 update-branch → 等 → merge。strict 仍開啟時行為與以前完全相同。
