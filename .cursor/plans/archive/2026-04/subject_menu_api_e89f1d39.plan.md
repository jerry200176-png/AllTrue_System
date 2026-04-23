---
name: Subject menu API
overview: 後端已有全域科目 API 與部分前端串接；本計畫補齊「依分校可見／可維護」、主任專用變更、完整 CRUD（含更名）、抽出 Controller、全站選單改走 API，並在側欄新增「科目管理」頁。
todos:
  - id: migration-campusid
    content: "Migration: Subject.CampusID nullable + backfill NULL；更新 Subject model fillable"
    status: completed
  - id: subject-controller
    content: 新增 SubjectController，搬移 api.php closure；GET 依分校／共用過濾；POST/DELETE 僅 director；PUT 更名；調整 routes 中介層
    status: completed
  - id: tests-subjects
    content: Pest/Feature：bootstrap、分校隔離、teacher 不可變更、刪除保護、PUT
    status: completed
  - id: frontend-api-pages
    content: subjectsApi 補 update + branch_id；SmartCalendar/ClassesList 改載入 API；新 SubjectSettingsPage + App.vue 側欄
    status: completed
  - id: dedupe-course-mgmt
    content: CourseManagement 科目 modal 與新頁共用邏輯或連結，避免雙實作；npm run deploy
    status: completed
isProject: false
---

# 科目選單與主任管理（依分校）

## 現況（已存在，避免重複造輪）

- [`backend/routes/api.php`](backend/routes/api.php)：`GET/POST /api/v1/subjects`、`DELETE /api/v1/subjects/{id}`、公開 `GET /api/v1/subjects-public`；含「缺 8 科就 seed」與名稱正規化 `$normalizeSubjectName`。
- [`frontend/src/lib/subjectsApi.js`](frontend/src/lib/subjectsApi.js)：`fetchSubjectOptions`、`createSubject`、`deleteSubject`。
- [`frontend/src/pages/CourseManagement.vue`](frontend/src/pages/CourseManagement.vue)：已有「管理科目」modal（新增／刪除）。
- [`frontend/src/pages/StudentsList.vue`](frontend/src/pages/StudentsList.vue)、[`UniversalClassScheduler.vue`](frontend/src/components/UniversalClassScheduler.vue)、[`TeachersList.vue`](frontend/src/pages/TeachersList.vue) 已部分打 API。
- **仍寫死**：[`SmartCalendar.vue`](frontend/src/pages/SmartCalendar.vue)、[`ClassesList.vue`](frontend/src/pages/ClassesList.vue) 使用 [`constants.js`](frontend/src/lib/constants.js) 的 `SUBJECTS`。
- **資料模型**：[`Subject` 表 migration](backend/database/migrations/2026_02_07_000006_create_subjects_table.php) 僅有 `School_id`、`Grade_no`、`Subject_Name`，**沒有分校欄位**；`StudentClass.SubjectID` 指到整庫共用的 `Subject.id`。

## 設計決策：「per 分校」的建議語意

- **列表可見範圍**：每個分校可看「全分校共用」科目 +「僅該分校」自訂科目（避免 A 分校加的「圍棋」出現在 B 分校選單，除非改為共用）。
- **實作方式**：新增可為 `NULL` 的 **`CampusID`（或 `campus_id`，與專案其處 `Student.CampusID` / `branch_id` 命名對齊）** 於 `Subject`。
  - 既有 8 筆（與 seed）：`CampusID = NULL` = 全分校可用。
  - 主任新增：`CampusID` = 目前操作分校（由 token 的 `auth_campus_ids` 或請求的 `branch_id` 決定，與 [`StudentController::index`](backend/app/Http/Controllers/StudentController.php) 的 `branch_id` / `super_admin` 行為一致）。
- **多分校主任**：`GET` 條件建議為 `CampusID IS NULL OR CampusID IN (使用者可管分校)`，避免漏列自訂科目。
- **`schedules.subject`**：後端已是 `string|max:32`（[`ScheduleController`](backend/app/Http/Controllers/ScheduleController.php)），可沿用 API 回傳的 `value`（英文鍵或中文名）；[`getSubjectLabel`](frontend/src/lib/constants.js) 已有未知值 fallback。

若產品上堅持「科目表完全共用、只要求主任能改 API」：可略過 `CampusID` migration，但仍建議完成「主任專用 POST/DELETE + 全站載入 API」以下各項。

## 後端

1. **Migration**：`Subject` 表新增 `CampusID`（nullable unsigned），既有資料一律 `NULL`（視為共用）。
2. **抽出 [`SubjectController`](backend/app/Http/Controllers/SubjectController.php)**（或 `SubjectApiController`）：搬移 `api.php` 內 closure 的 normalize / seed / CRUD，便於測試與重用。
3. **`index`（GET）**
   - 依角色組合 `WHERE (CampusID IS NULL OR CampusID IN (...))`；`super_admin` 與現有一致可帶 `branch_id` 篩「該分校 + 共用」。
   - 保留「缺 canonical 8 科則補齊」行為（僅對 `CampusID IS NULL` 的共用列 seed，避免每分校重複 seed）。
4. **`store`（POST）**：僅 **`role:director`**（含 `super_admin` 若需建科目）；寫入 `CampusID`；**自 `director,teacher` 群組移出**，修正目前註解寫「director only」但實際老師也可 POST 的問題。
5. **`update`（PUT/PATCH）**：新增更名；檢查同分校範圍內名稱唯一（含與共用科 display name 衝突時的規則，建議與現有 `LOWER(Subject_Name)` 一致或加上 `CampusID` 維度 unique 語意）。
6. **`destroy`（DELETE）**：僅 director；**禁止刪除 `CampusID IS NULL` 的共用列**（或僅 `super_admin` 可刪，二選一寫進測試）；分校自訂列維持「有 `StudentClass` 引用則 409」。
7. **`subjects-public`**：維持給 [`Register.vue`](frontend/src/pages/Register.vue) 等公開註冊使用；可回傳「全部共用 + 各校專屬的聯集」或僅共用——建議 **至少含全部 `CampusID IS NULL`**，必要時再聯集各校（若不想暴露他校專屬名稱，可只回共用）。
8. **Model**：更新 [`backend/app/Models/Subject.php`](backend/app/Models/Subject.php) 的 `$fillable`。
9. **測試**：擴充 [`backend/tests/Feature/ProfileStoreTeacherTest.php`](backend/tests/Feature/ProfileStoreTeacherTest.php) 或新增 `SubjectApiTest.php`：bootstrap、多分校可見性、`teacher` 不可 POST/DELETE、`PUT` 更名、`CampusID` 隔離。

## 前端

1. **API client**：在 [`subjectsApi.js`](frontend/src/lib/subjectsApi.js) 增加 `updateSubject(id, name)`；`fetchSubjectOptions` 對 `super_admin`／多分校情境與其他頁一致帶上 **`branch_id`**（與 `currentBranch` / `props.branchId` 對齊）。
2. **移除硬編碼選單**
   - [`SmartCalendar.vue`](frontend/src/pages/SmartCalendar.vue)：onMounted 呼叫 `fetchSubjectOptions`；表單預設科目改為列表第一筆或空字串；`<option>` 改迭代 `subjectOptions`。
   - [`ClassesList.vue`](frontend/src/pages/ClassesList.vue)：同上（若該頁仍活躍使用）。
3. **主任「科目管理」入口**
   - 新增頁面（例如 [`SubjectSettingsPage.vue`](frontend/src/pages/SubjectSettingsPage.vue)）：列表、新增、更名、刪除（重用 [`CourseManagement.vue`](frontend/src/pages/CourseManagement.vue) modal 的 UI 邏輯即可抽成元件或複製精簡版）。
   - [`App.vue`](frontend/src/App.vue)：在主任側欄「教務核心」區塊（與「教室管理」同層）加入 **「科目管理」**；`v-if` 掛載新頁並傳 `branch-id`。
4. **常數**：保留 [`SUBJECTS`](frontend/src/lib/constants.js) 作 offline fallback 即可；`getSubjectLabel` / `checkTeacherScope` 繼續支援動態列表（已部分支援 `subjectsList`）。
5. **部署**：變更 `frontend/src` 後依專案規則執行 `cd frontend && npm run deploy`。

## 風險與相容

- **`teacher_subject_levels.subject_id`**：仍以 `Subject.id` 為準；分校專屬科目 ID 僅在該分校課程／排課使用，跨分校老師若支援多校，列表 API 已用 `IN (可管分校)` 即可選到。
- **長度**：DB `Subject_Name` 為 16 字元；API 驗證應與 DB 一致（目前 closure 有 20→截 16 的不一致，可一併收斂）。
- **與現有「課程管理」內管理科目**：可保留按鈕並改為 `router` 式導覽到同一頁，或保留雙入口；避免兩套邏輯分歧（優先共用 composable 或共用子元件）。
