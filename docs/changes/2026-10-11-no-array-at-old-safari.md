## 2026-10-11 — fix: 舊版 Safari 不再因 Array.at 出錯
<!-- release-notes: silent_ship=silent-2026-10-11-no-array-at-old-safari -->
<!-- silent-reason: 只把 6 處 arr.at(-1) 改寫成等價索引，行為不變，並加測試禁止再用。 -->
- 舊版 Safari（15.4 以前）沒有 `Array.prototype.at`，帳務明細會丟 `$.at is not a function`。改成 `arr[arr.length - 1]`，並加測試禁止正式程式再用 `.at(`。Closes #3845
