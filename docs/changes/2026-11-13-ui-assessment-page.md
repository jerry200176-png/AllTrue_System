## 2026-11-13 — feat(ui): 學習檢測 page on the template (#4989)
<!-- release-notes: staff_update=staff-2026-11-13-ui-assessment-page -->
- 「學習檢測」頁改用 shadcn-vue 樣板（`components/ui` 的 Card／Badge／Button／Table／Alert／Input／Textarea／Checkbox／Label／Skeleton，視窗用 `components/app-overlay` 的 FormDialog／ConfirmDialog／DetailSheet）：字更大、按鈕至少 48px、手機每份檢測一張卡片；讀取與寫入的 API、分校參數、角色限制與既有文字都不變。頁面移出 `scripts/ui-legacy-pages.json`，舊的頁面樣式區塊同一個 PR 刪除。這一頁目前沒有側欄入口（只能從新手教學完成後的功能地圖進入），入口這次沒有動。
- 「建立檢測」視窗打開時游標直接在「檢測名稱」；名稱空白（含只打空格）時，欄位下方直接說明原因、不送出（原本是按鈕反灰）。「關閉」檢測改在頁面內的確認視窗詢問，問句不變；檢測結果、補強追蹤、數位作答與登錄結果改在右側面板（手機為全螢幕），結果由表格改為一人一張卡片。
