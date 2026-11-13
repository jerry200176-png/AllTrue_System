## 2026-11-13 — feat(ui): 當月學收 page on the template (#4989)
<!-- release-notes: staff_update=staff-2026-11-13-ui-tuition-report -->
- 「當月學收」頁（報表與薪資 › 當月學收）改用 shadcn-vue 樣板（`components/ui` 的 Card／Button／Alert／Table／Checkbox／Skeleton）：字更大、按鈕至少 48px；讀取與匯出的 API、分校範圍與既有文字都不變，這一頁仍然只讀不寫。頁面移出 `scripts/ui-legacy-pages.json`，舊的頁面樣式區塊同一個 PR 刪除。
