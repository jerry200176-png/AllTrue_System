## 2026-11-07 — chore(ci): nightly PHPUnit run in random order, failures open one issue with the seed
<!-- release-notes: silent_ship=silent-2026-11-07-phpunit-random-nightly -->
<!-- silent-reason: 只新增夜間的測試檢查，教職員看到的畫面與功能完全不變，PR 的 CI 不變。 -->
- 新增 `phpunit-random-nightly.yml`：每晚以隨機順序（檔案洗牌＋`--order-by=random`，同一個 seed）跑整套 PHPUnit，並印出 seed；失敗時只開／更新一個 issue，附 seed 與重跑方式（`workflow_dispatch` 的 `seed` 參數）。PR CI 不動。
