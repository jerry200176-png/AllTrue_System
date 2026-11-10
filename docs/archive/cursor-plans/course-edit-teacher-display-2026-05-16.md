---
todos:
  - id: "course-teacher-test"
    content: "新增 regression test：編輯課程時既有老師即使不在 async teachers 清單也不能被清空"
    status: pending
  - id: "course-teacher-dev"
    content: "修正 CourseManagement/CourseEditForm 老師選項保留邏輯，必要時注入目前授課老師 option"
    status: pending
  - id: "course-teacher-release"
    content: "低頻率跑必要檢查；批准後一次 push PR，CI 綠才 merge/deploy"
    status: pending
isProject: false
---
# Bug Fix Plan — 編輯課程授課老師未顯示

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 前端狀態 / async options race |
| 根因摘要 | `CourseManagement.loadTeachers()` 會在 teachers async 清單不含目前 `editForm.teacher_id` 時清空該值；`CourseEditForm` 的 `<select>` 也沒有目前授課老師 fallback option，因此編輯課程時老師欄位顯示空白且無法儲存。 |
| 錯誤行為 | 主任開啟既有課程編輯，授課老師未顯示；因老師必填，後續無法調整/儲存。 |
| 預期行為 | 編輯既有課程時一定保留目前授課老師；若老師不在目前分校清單，仍顯示為「目前授課老師」並允許改選合法老師。 |
| 影響範圍 | `course-mgmt`、`CourseManagement.vue`、`CourseEditForm.vue`；不改 DB / auth / billing。 |

## 1. 文件資訊
- 關聯：GitHub #365 / 正式站 Bug #94。
- 目標角色：主任。
- 狀態：B1 完成，待批准 DEV。

## 2. 業務背景與影響
主任無法在課程編輯畫面確認或更換授課老師，會卡住課程維護流程。

## 3. 範圍
- In Scope：修正編輯 modal 的老師選項保留、顯示與 validation。
- Out of Scope：不改老師權限模型、不改 `TeacherID` 寫入規則、不改代課/調課邏輯。

## 4. RACI
- R/A：AI Agent
- I：Jerry

## 4b. Dependencies
- 無 migration。
- 無前置 PR。

## 5. Acceptance Criteria
- AC-001：開啟既有課程編輯時，`teacher_id` 有值就必須顯示目前授課老師。
- AC-002：若 `/api/v1/teachers` 暫時未回傳該老師，前端不得清空 `editForm.teacher_id`。
- AC-003：主任改選 teachers 清單中的其他老師後可正常儲存。

## 6. 功能需求
- FR-001：`CourseManagement` 應為編輯中的課程建立包含目前老師的 options。
- FR-002：`loadTeachers()` 不得因 async 清單缺少目前老師而清空編輯表單。
- FR-003：`CourseEditForm` 老師 select 必須可顯示 fallback option。

## 7. 非功能需求
- 不適用；此 bug 非效能問題。

## 8. 技術方向
- `frontend/src/pages/CourseManagement.vue`：新增 computed teacher options for edit，合併 `teachers` 與 `editingCourseRaw.teacher_id/teacher_name`。
- `frontend/src/components/CourseEditForm.vue`：使用傳入 options 顯示目前老師；避免 required validation 誤判。
- 新增純 JS regression helper/test，避免為 UI 小修反覆燒 Actions。

## 8b. Decision Log
- 2026-05-16：選擇前端保留目前老師 fallback，不改後端 teachers API；理由是正式站 read-only 比對未證明後端缺老師資料，問題更像 async options / 前端清空。

## 9. 資安與存取控制
- 不新增端點、不放寬權限、不暴露 PII。老師清單仍來自既有 authenticated API。

## 10. QA 驗收
- Happy：目前老師在清單內，顯示並可改選。
- Edge：目前老師不在清單內，仍顯示 fallback，未改選時不清空。
- Revert-proof：stash 修復後新增 test 至少 1 case failure。

## 11. 上線與維運
- 無 migration。
- 批准 DEV 後開小 PR；CI 綠才 merge；deploy 後 health/version check。
- 回滾：revert PR，預估 5 分鐘。

## 12. 優先級
- P1；下一個 DEV 候選。

## 13. 風險 / 假設 / 開放問題
- WebSearch 對齊：Vue async select 常見問題是 options 未載入或不含 model value 時顯示空白；業界做法是保留/注入目前值 option，或等 options resolved 後再渲染。
- 假設：Bug #94 沒有提供特定課程；修復先覆蓋通用 race/缺 option 情境。

## 14. Definition of Done
- [ ] Test：新增前端純測試，驗證 fallback teacher option 與不清空 `teacher_id`。
- [ ] Build：`npm run build` 通過。
- [ ] CI：PR checks 全綠。
- [ ] Deploy：`version.json` hash 更新且 `/api/v1/health` 回傳 `status: ok`。
- [ ] 正式站 bug #94：留言並標記 resolved。
