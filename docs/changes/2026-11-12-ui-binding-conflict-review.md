## 2026-11-12 — feat(ui): 衝突審查 page on the template (#4989)
<!-- release-notes: staff_update=staff-2026-11-12-ui-binding-conflict-review -->
- 「衝突審查」頁（設定 › 綁定衝突審查）改用 shadcn-vue 樣板（`components/ui` 的 Card／Button／Table／Alert／Label／Skeleton，確認視窗用 `components/app-overlay` 的 ConfirmDialog）：字更大、按鈕至少 48px、手機每位學生一張卡片；既有文字、分校範圍與 API 都不變。頁面移出 `scripts/ui-legacy-pages.json`，舊的頁面樣式區塊同一個 PR 刪除。
- 確認視窗打開時游標在「取消」，點視窗外不會關閉；處理失敗時視窗會關閉，原因以紅字顯示在該位學生的資料下方（螢幕報讀器會念出），可以再按一次重試。學生姓名前的圓形字首圖示移除。
