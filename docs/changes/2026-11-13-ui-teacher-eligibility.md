## 2026-11-13 — feat(ui): 正職薪資要件 page on the template (#4989)
<!-- release-notes: staff_update=staff-2026-11-13-ui-teacher-eligibility -->
- 「正職薪資要件」頁（報表與薪資 › 正職薪資要件）改用 shadcn-vue 樣板（`components/ui` 的 Card／Button／Alert／Table／Input／Skeleton）：字更大、按鈕至少 48px；結算、匯出、鎖定／重開、倍率修改與核准的 API、分校範圍與既有文字都不變。頁面移出 `scripts/ui-legacy-pages.json`，舊的頁面樣式區塊同一個 PR 刪除。
