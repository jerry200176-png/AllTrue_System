## 2026-11-13 — feat(ui): 字級「稍大」 + page template from 今日工作台 (#4989, in-app #544)
<!-- release-notes: staff_update=staff-2026-11-13-text-size-medium -->
- 字級新增「稍大」（內文 18、標題 31），介於「標準」與「大」之間；預設仍是「標準」，選擇一樣記在這台裝置的瀏覽器，沒有後端欄位。
- 新增頁面樣板 `docs/design/PAGE_TEMPLATE.md`（以今日工作台為準：外框、標題列、摘要列、區塊卡片、清單或表格、姓名不換行、每個 UI PR 要附的截圖）與共用元件 `frontend/src/components/ui/page/`；這個 PR 沒有改任何既有頁面的版面。
