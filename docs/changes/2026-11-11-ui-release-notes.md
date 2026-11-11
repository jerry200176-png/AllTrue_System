## 2026-11-11 — feat(ui): 版本更新 page on the template (#4989)
<!-- release-notes: staff_update=staff-2026-11-11-ui-release-notes -->
- 「版本更新」頁改用 shadcn-vue 樣板（`components/ui` 的 Card／Badge／Button）：字更大、按鈕至少 48px、最新一則用暖色主卡；文字與公告內容不變，沒有 API 變更。頁面移出 `scripts/ui-legacy-pages.json`。
- 「查看更早公告」的內容改成第一次展開時才建立（600 多則不再每次進頁面都先建好）；展開後的內容與原本相同。
