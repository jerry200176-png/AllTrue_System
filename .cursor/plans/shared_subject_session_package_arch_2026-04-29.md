# 多科共用堂數方案 ARCH

## 1. 結論

本功能沿用既有 `CoursePackage` 架構，不新增新的 credits schema，也不把多科合併成單一 `StudentClass`。

推薦模型：

```text
CoursePackage（方案主檔：總堂數 / 已用 / 剩餘 / 付款狀態）
  ├─ StudentClass A（數學：老師、排課、評量、出勤）
  ├─ StudentClass B（英文：老師、排課、評量、出勤）
  └─ StudentClass C（自然：老師、排課、評量、出勤）

package_session_ledger（方案扣堂事件）
```

主任畫面上看到「一筆多科共用堂數方案」，底層仍保留多筆科目成員，以保護排課、出勤、評量、老師權限與報表邊界。

## 2. 現況盤點

| 能力 | 位置 | ARCH 判定 |
|---|---|---|
| 方案主檔 | `course_packages` / `CoursePackage` | 可沿用 |
| 成員課程關聯 | `StudentClass.PackageID`, `PackageTotalSessions`, `PackageName` | 可沿用 |
| 扣堂事件 | `package_session_ledger` / `PackageDeductionService` | 可沿用 |
| 建立多科方案 | `POST /api/v1/course-packages/create-multi-subject` | 可沿用，需入口與文案調整 |
| 方案加購 | `PUT /api/v1/course-packages/{id}` with `total_sessions` | 可作為加購合約 |
| 舊課程綁定 | `POST /api/v1/course-packages/{id}/bind-courses` | 需補強 guard |
| 方案管理頁 | `CoursePackagesPage.vue` | 頁面存在，但 `App.vue` 目前未 render，疑似 orphan |
| 列表顯示方案池 | `CourseManagement.vue`, `StudentsList.vue`, `useCourseSessionsDisplay.js` | 已有部分支援 |
| 禁止直接改方案成員剩餘 | `PackageDisplayAndGuardTest` | 已有測試 guard |

主要缺口：

- `CoursePackagesPage.vue` 尚未接入主應用入口，不能只依賴它作為主任流程。
- `bind-courses` 目前只略過已屬其他 package 的課程，尚未完整擋跨學生、跨分校、月結、停用課程。
- `StudentsList` 與 `CourseManagement` 的方案成員「加購 / 續報」仍可能走 `student-classes/{id}/purchase-batch`，會建立單科新批次，違反共用池語意。
- 建立入口文案需要遵守 R24：一般多科固定時段優先走一般課程，共用方案只能作為清楚說明後的進階選項。

## 3. DB 設計

本階段不建議新增 migration。

理由：

- `course_packages` 已有 `total_sessions`, `remaining_sessions`, `used_sessions`, `billing_mode`, `rate`, `paid`, `paid_at`, `stop`。
- `StudentClass` 已有 `PackageID`, `PackageTotalSessions`, `PackageName`。
- `package_session_ledger` 已能記錄 package 層扣堂與 reverse。
- 方案加購可透過更新 `CoursePackage.total_sessions`，再同步成員 `SessionCount` / `PackageTotalSessions`。

權威資料來源：

| 資料 | Source of Truth |
|---|---|
| 方案總堂數 | `CoursePackage.total_sessions` |
| 方案已用堂數 | `CoursePackage.used_sessions` from ledger recompute |
| 方案剩餘堂數 | `CoursePackage.remaining_sessions` from ledger recompute |
| 科目/老師/排課 | `StudentClass` + `ClassSession` |
| 扣堂事件 | `package_session_ledger` |
| 方案成員 | `StudentClass.PackageID` |

## 4. API 合約

### 4.1 建立多科共用堂數方案

沿用：`POST /api/v1/course-packages/create-multi-subject`

必要行為：

- 新建 1 筆 `CoursePackage`。
- 新建多筆 `StudentClass` 成員。
- 可依各科固定時段建立初始 `ClassSession`。

Guard：

- `payment_type=session` 時 `total_sessions >= 1`。
- UI 至少要求 2 科；後端可保守允許 1 科以相容既有測試，但需提示單科通常使用一般課程。
- `student.CampusID == branch_id`。
- director/admin 必須有 branch access。

Response 保留：

- `package_id`
- `package.total_sessions`
- `package.remaining_sessions`
- `members[].student_class_id`
- `members_scheduled[]`

### 4.2 方案加購 / 續報

沿用：`PUT /api/v1/course-packages/{id}`

Request:

```json
{ "total_sessions": 16 }
```

前端語意：

- 使用者輸入「加購 N 堂」。
- 前端計算 `new_total = current_total_sessions + N`。
- API 只收到新的總堂數，不收到 delta。

後端行為：

- 僅 `billing_mode=count` 可改總堂數。
- `new_total >= used_sessions`。
- 同步所有成員 `SessionCount` 與 `PackageTotalSessions`。
- 不碰成員 `Charge`, `Rate`, `RemainingSessions`。
- 執行補排或取消超額未來 `scheduled`。
- 更新 `CoursePackage.remaining_sessions = max(0, new_total - used_sessions)`。

### 4.3 舊課程綁定到方案

沿用並補強：`POST /api/v1/course-packages/{id}/bind-courses`

Request:

```json
{
  "student_class_ids": [101, 102],
  "dry_run": true
}
```

必補 guard：

- ids 數量 1-10。
- package 存在且 caller 有 `campus_id` 權限。
- 所有課程存在；若 ids 有缺漏，回 422。
- 所有課程 `StudentID == package.student_id`。
- 所有課程學生 `CampusID == package.campus_id`。
- 所有課程 `ScheduleMode == count`。
- 所有課程未屬於其他不同 package。
- 預設只允許 active 課程：`Stop = 0`。
- 不允許月結課程綁入堂數制 package。

Apply 行為：

- 只更新 `PackageID`, `PackageTotalSessions`, `PackageName`。
- 不刪除舊課程。
- 不直接重寫 `RemainingSessions`。
- 不直接重寫歷史 `ClassSession` / `LearningRecord` / `StudentSingIn`。

## 5. 前端設計

### 主入口

- `CourseManagement.vue` 新增課程 modal 仍以一般課程為預設。
- 「多科共用堂數方案」放進進階卡片或次要 CTA。
- 卡片文案需清楚說明：多科共用同一包總堂數，各科仍分別排課、老師、評量。

建議文案：

> 多科共用堂數方案：適合學生同時報多科，但只購買一包總堂數。例如買 8 堂，數學上 3 堂、英文上 2 堂後，方案剩餘 3 堂。各科仍可分別指定老師、時間與評量。

### 方案成員加購

`StudentsList.vue` 與 `CourseManagement.vue` 都需分流：

- 非方案課程：維持 `student-classes/{id}/purchase-batch`。
- 方案成員：開方案加購 modal，呼叫 `PUT /course-packages/{PackageID}`。

Modal 必須提示：

> 此課程屬於「{PackageName}」。加購會增加整個方案的總堂數，所有科目共用。

### 方案管理頁

`CoursePackagesPage.vue` 可保留作為進階管理頁，但需決定：

- 接入 `App.vue` nav active key；或
- 不接 nav，只從課程管理 / 學生管理跳入方案詳情。

ARCH 建議 P1 先從 `CourseManagement` / `StudentsList` 完成主任日常入口，P2 再整理獨立方案管理頁。

## 6. 分校隔離

所有新/補強 API 必須符合：

- director/admin 只能操作 `auth_campus_ids` 內 package。
- 建立方案時 `branch_id` 必須等於 `Student.CampusID`。
- 綁定舊課程時所有課程必須同學生、同分校。
- super_admin 可跨分校操作，但不可跨學生綁定。

## 7. 測試計畫

後端 PHPUnit 必補 / 複核：

- 建立 2 科共用 8 堂方案：1 package + 2 members。
- 出席其中 1 科後，package remaining 8 → 7，列表兩科都顯示 7。
- `PUT /course-packages/{id}` total 8 → 16，同步所有成員 `SessionCount` / `PackageTotalSessions`。
- 方案成員不可透過 `PUT /student-classes/{id}` 改 `remaining_sessions`。
- `bind-courses` dry-run 不寫 DB。
- `bind-courses` apply 只更新 package 欄位。
- `bind-courses` 拒絕跨學生、跨分校、月結、停用、已屬其他 package。
- 他校 director 查詢 / 更新 / 綁定 package 回 403。

前端驗證：

- `npm run build` 成功。
- 方案成員加購 modal 文案正確。
- 一般課程加購仍建立新批次。
- 方案入口不是預設主路線，文案符合 R24。

## 8. 實作切分

建議一個 PR 完成，但嚴格分層提交：

1. 後端 guard + tests：補強 `bind-courses`、確認 package total update。
2. 前端入口與文案：`UniversalClassScheduler` / `CourseManagement`。
3. 方案加購分流：`StudentsList` + `CourseManagement`。
4. 文件：CHANGELOG + R24 補充「進階入口例外」。

若 scope 變大，可拆成兩個 PR：

- PR A：恢復清楚建立入口 + 方案加購分流。
- PR B：舊課程綁定 UI + guard 強化。

## 9. 風險與控制

| 風險 | 等級 | 控制 |
|---|---|---|
| 方案加購誤走單科 `purchase-batch` | 高 | 前端依 `PackageID` 分流，後端測試覆蓋 |
| 舊課程跨學生/跨校誤綁 | 高 | `bind-courses` guard + campus tests |
| 主任誤用共用方案 | 中 | 一般課程預設，共用方案為進階入口，文案用例清楚 |
| 剩餘堂數顯示不一致 | 中 | 列表顯示 `package_remaining_sessions`，禁改成員 remaining |
| 月結方案被混入堂數邏輯 | 中 | 本次主軸 count package，月結路徑不重設計 |

## 10. ARCH Exit Checklist

- [x] DB 變更清單：不建議新增 migration。
- [x] API 合約：建立、加購、舊課程綁定、列表/明細已定義。
- [x] 前端元件規劃：入口、方案加購分流、方案管理頁定位已定義。
- [x] 多校區隔離：建立 / 查詢 / 更新 / 綁定規則已定義。
- [x] 高風險邏輯標記：堂數扣除、加購、舊資料綁定需測試先行。
- [x] 設計問題：已停用歷史課程是否允許綁定仍需產品取捨，預設不允許。
