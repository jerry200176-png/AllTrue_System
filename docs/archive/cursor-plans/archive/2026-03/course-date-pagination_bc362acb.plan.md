---
name: course-date-pagination
overview: 在課程管理頁把「上課日期」從一次全部顯示，改為每頁 8 堂的分頁模式，避免學生課程累積後列表過長。保留原本請假/調課仍可使用完整堂次資料。
todos:
  - id: state-and-helpers
    content: 新增每門課日期分頁狀態與 helper（page size=8）
    status: completed
  - id: template-update
    content: 把日期展開區改成 pagedSessions 渲染並加入上一頁/下一頁控制
    status: completed
  - id: style-update
    content: 新增 pager 樣式並維持現有版面一致
    status: completed
  - id: regression-check
    content: 確認請假/調課仍使用完整 sessions(c) 且分頁切換正確
    status: completed
isProject: false
---

# CourseManagement 上課日期分頁方案

## 目標

- 解決課程堂數累積後，「上課日期」展開區塊過長、難讀、難操作的問題。
- 依你的偏好改為每頁 **8 堂**，並提供上一頁/下一頁切換。

## 影響範圍

- 前端頁面：`[/home/admin/frontend/src/pages/CourseManagement.vue](/home/admin/frontend/src/pages/CourseManagement.vue)`
- 日期計算工具：`[/home/admin/frontend/src/lib/sessionDates.js](/home/admin/frontend/src/lib/sessionDates.js)`（本次預計不改演算法）

## 現況重點

- 目前展開「上課日期」時，直接把 `sessions(c)` 全部渲染：
  - `sessions(c)` 來源是 `computeSessionDatesForCourse(...)`，會依購買堂數推算完整堂次日期。
  - 堂數增加後，日期 chip 數量會線性增加，導致展開區塊越來越長。
- `sessions(c)` 同時被請假/調課 modal 使用，不能因為顯示優化而影響其完整資料來源。

## 實作步驟

1. 在 `CourseManagement.vue` 新增「每門課分頁狀態」

- 新增 `const datePageByCourse = ref({})` 與 `const datePageSize = 8`。
- `toggleDates(c)` 展開時初始化該課程頁碼（建議預設到最後一頁，優先看到最近堂次）。

1. 新增日期分頁 helper（不動既有 `sessions(c)`）

- 保留 `sessions(c)` 回傳完整日期陣列，供請假/調課流程持續使用。
- 新增 `getDatePage(c)`, `setDatePage(c, page)`, `totalDatePages(c)`, `pagedSessions(c)`。
- `pagedSessions(c)` 只回傳當頁 8 筆，模板改用它渲染。

1. 調整「上課日期」展開列 UI

- 將 `v-for="(d, i) in sessions(c)"` 改為 `v-for="(d, i) in pagedSessions(c)"`。
- 顯示分頁資訊：`第 X / Y 頁`、`上一頁`、`下一頁`。
- 堂次編號改為全域序號（例如第 17 堂），而非每頁重新從 1 開始。

1. 補上樣式（同檔 `<style scoped>`）

- 新增簡潔的 pager 樣式（按鈕、頁碼、禁用狀態），避免破壞既有表格視覺。

1. 回歸檢查

- 手動驗證：
  - 8 堂以下只顯示 1 頁。
  - 9 堂以上可切頁，且頁碼、堂次序號正確。
  - 請假/調課下拉選單仍列出完整堂次，不受分頁顯示影響。
  - 切換分校/篩選後不出現舊課程殘留頁碼。

## 驗收標準

- 展開「上課日期」不再一次渲染全部日期。
- 每頁固定 8 堂，可穩定切換前後頁。
- 請假與調課功能行為維持既有邏輯與資料完整性。

