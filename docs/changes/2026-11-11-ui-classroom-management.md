## 2026-11-11 — feat(ui): 教室管理 page on the template (#4989)
<!-- release-notes: staff_update=staff-2026-11-11-ui-classroom-management -->
- 「教室管理」頁改用 shadcn-vue 樣板（`components/ui` 的 Card／Badge／Button／Table／Alert／Input／Textarea／Checkbox／Label／Skeleton，視窗用 `components/app-overlay` 的 FormDialog／ConfirmDialog）：字更大、按鈕至少 48px、手機每間教室一張卡片；既有文字、分校參數與 API 都不變。頁面移出 `scripts/ui-legacy-pages.json`，舊的頁面樣式區塊同一個 PR 刪除。
- 新增／編輯視窗打開時游標直接在「教室名稱」欄位；教室名稱空白（含只打空格）或容量小於 1 時，欄位下方直接顯示原因、不送出；儲存中按鈕顯示「儲存中…」。停用的教室標籤改為灰色（紅色只留給刪除）。
