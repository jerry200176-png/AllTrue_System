## 2026-11-12 — feat(ui): 綁定健康度 page on the template (#4989)
<!-- release-notes: staff_update=staff-2026-11-12-ui-binding-health -->
- 「綁定健康度」頁（設定 › 綁定健康度）改用 shadcn-vue 樣板（`components/ui` 的 Card／Button／Alert／Badge／Skeleton／ActivityRing／Progress／Sparkline）：字更大、按鈕至少 48px；讀取的 API、分校範圍與既有文字都不變，這一頁仍然只讀不寫。頁面移出 `scripts/ui-legacy-pages.json`，舊的頁面樣式區塊同一個 PR 刪除。
- 全校綁定率的圓環、各分校的長條與趨勢圖改用共用元件：圓環旁邊顯示百分比與已綁定人數（原本在圓環中間）；趨勢圖是一條線加底色，不再有格線與圓點，只有一筆資料時直接顯示那一筆的日期與數字。每個「查看」按鈕會念出是哪一位學生。
