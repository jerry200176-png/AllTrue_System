## 2026-10-08 — ci(land-queue): intentional-revert PRs never join a batch
<!-- release-notes: silent_ship=silent-2026-10-08-land-queue-serial-revert -->
<!-- silent-reason: 只改合併佇列流程，產品畫面不變 -->
- 帶 `intentional-revert` 的 PR 改為單獨合併（serial-only），不再進入批次：批次分支上的 Presubmit CHECK 0d 讀不到成員 PR 的標籤，會讓整批卡紅並擋住後面所有 PR。批次失敗過的成員仍維持 serial-only，不會以相同成員重組批次。
