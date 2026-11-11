## 2026-11-11 — feat(ui): 課表回報管理 page on the template (#4989)
<!-- release-notes: staff_update=staff-2026-11-11-ui-schedule-discrepancy -->
- 「課表回報管理」頁改用 shadcn-vue 樣板（`components/ui` 的 Card／Badge／Button／Table／Tabs）：字更大、按鈕至少 48px、窄螢幕左右留白一致；文字、分頁、處理流程與 API 都不變。頁面移出 `scripts/ui-legacy-pages.json`。
- 舊的頁面樣式區塊暫留（已不再使用），下一個純刪除的 PR 會移除。
