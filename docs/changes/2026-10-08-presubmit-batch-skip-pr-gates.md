## 2026-10-08 — ci(presubmit): land-queue batch branches skip per-PR gates
<!-- release-notes: silent_ship=silent-2026-10-08-presubmit-batch-skip -->
<!-- silent-reason: 只改合併佇列 CI 流程，產品畫面不變 -->
- 合併佇列批次分支（`chore/land-queue-batch-*`，dispatch、無 PR）不再跑只屬於單一 PR 的檢查：CHECK 0d（標籤）、CHECK 2（大小）、CHECK 4B（Release-Impact 宣告）。這些已在每個成員 PR 自己的 head 上通過，合併時 ruleset 仍要求。之前每個批次都因 PR 內文為空而失敗。
