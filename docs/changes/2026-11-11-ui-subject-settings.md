## 2026-11-11 — feat(ui): 科目管理 page on the template (#4989)
<!-- release-notes: staff_update=staff-2026-11-11-ui-subject-settings -->
- 「科目管理」頁改用 shadcn-vue 樣板（`components/ui` 的 Card／Badge／Button／Table／Alert／Input／Label，視窗用 `components/app-overlay` 的 FormDialog／ConfirmDialog）：字更大、按鈕至少 48px、手機改成一張一張的卡片；文字、權限（共用科目僅超級管理員可更名／刪除）與 API 都不變。頁面移出 `scripts/ui-legacy-pages.json`，舊的頁面樣式區塊同一個 PR 刪除。
- 新增／更名視窗打開時游標直接在「科目名稱」欄位；「最多 16 個字。」移到視窗標題下方；送出中按鈕顯示「儲存中…」。
