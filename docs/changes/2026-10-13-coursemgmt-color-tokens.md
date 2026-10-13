## 2026-10-13 — chore(ui): 課程查找頁色碼改用 `--ds-*` 設計 token
<!-- release-notes: silent_ship=silent-2026-10-13-coursemgmt-color-tokens -->
<!-- silent-reason: 只把 CourseManagement 樣式裡寫死的色碼／陰影換成既有設計 token，淺色畫面幾乎相同，版面、字級與功能不變 -->
- `CourseManagement.vue` 樣式區寫死的 hex 色碼從 311 處降到 111 處，改用 `--ds-ink*`、`--ds-hairline`、`--ds-surface-2`、`--ds-success/warning/danger(-wash)` 等 token；5 個一般浮起陰影改用 `--ds-shadow-1/2`。
- `styles.css` 新增 4 個語意 token（含夜間值）：`--ds-ink-faint`、`--ds-success-line`、`--ds-warning-line`、`--ds-danger-line`。
- 夜間模式下，原本寫死淺色的狀態標籤與暫停列改跟著主題變深。
