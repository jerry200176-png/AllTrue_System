---
name: xindian-student-import
overview: 支援新店主任上傳「學生收費總列表」CSV，自動抽取學生名單並以姓名+手機規則進行新增/更新，讓該檔案可直接建立學生資料。
todos:
  - id: design-import-api
    content: 設計新店收費總表匯入 API 與回傳摘要格式
    status: completed
  - id: backend-parser-upsert
    content: 後端實作 CSV 解析、姓名正規化與姓名+手機 upsert
    status: completed
  - id: frontend-upload-flow
    content: 前端匯入按鈕改為上傳檔案至後端並顯示摘要
    status: completed
  - id: branch-security-check
    content: 加上 branch_id 與 campus 權限檢查避免跨校匯入
    status: completed
  - id: verify-real-file
    content: 以實際新店 CSV 做首次與重複匯入驗收
    status: completed
isProject: false
---

# 新店收費總表學生匯入計畫

## 目標

讓主任可直接在學生管理頁上傳 `[全真新店學生課堂數 - 新版115-03月全真新店學生收費總列表.csv](/home/admin/全真新店學生課堂數 - 新版115-03月全真新店學生收費總列表.csv)`，系統自動抽取學生資料並寫入 `Student`（同分校）。

## 現況重點

- 前端目前只支援非常簡單的 `姓名,手機,年級` 直拆 CSV，無法處理此檔案的多欄位、多行備註、逗號/換行內容：`[StudentsList.vue](/home/admin/frontend/src/pages/StudentsList.vue)`。
- 後端現有匯入器期待 `name/campus_id/class_id` 結構，與此收費總表不相容：`[StudentsImport.php](/home/admin/backend/app/Imports/StudentsImport.php)`、`[ImportController.php](/home/admin/backend/app/Http/Controllers/ImportController.php)`。

## 實作方向

- **後端新增「收費總表轉學生」匯入流程（主邏輯放後端）**
  - 在 `[ImportController.php](/home/admin/backend/app/Http/Controllers/ImportController.php)` 新增 `studentsFromTuitionSummary`（或擴充既有 `students`）支援 multipart 上傳。
  - 使用 `fgetcsv` 逐列解析（可吃引號、逗號、跨行欄位），只讀必要欄位：
    - `學生` -> 學生姓名（清理如 `1對2/1v3` 這類尾碼）
    - `年級學校` -> 拆出年級代碼（P1~H3）與學校名
    - `手機` 若缺則留空
  - 去重規則採你指定的 **姓名+手機 upsert**：
    - 同分校 (`CampusID`) 下，先以 `name + phone` 找既有學生
    - 找到則 `update`（年級/學校/狀態）
    - 找不到則 `create`
  - 回傳匯入摘要：`created / updated / skipped / errors`（含前幾筆錯誤樣本）供前端提示。
- **前端匯入按鈕改成走新後端匯入 API**
  - 更新 `[StudentsList.vue](/home/admin/frontend/src/pages/StudentsList.vue)` 的 `importStudents`：
    - 改用 `FormData(file, branch_id)` 呼叫 `/api/v1/students/import`（或新路由）
    - 顯示後端摘要（新增幾筆、更新幾筆、略過幾筆）
    - 匯入成功後 refresh 學生列表
  - 保留舊簡易 CSV 流程可作 fallback（或由後端統一判斷檔案格式）。
- **路由與權限**
  - 在 `[api.php](/home/admin/backend/routes/api.php)` 讓 `director + require_campus` 下可呼叫此匯入。
  - 強制 `branch_id` 必填，且需落在 `auth_campus_ids` 內，避免跨校區匯入。

## 驗收與測試

- 用你這份新店 CSV 實測：
  - 第一次匯入：應大量 `created`
  - 第二次匯入：應以 `updated/skipped` 為主，不重複建立
- 手動驗證：
  - 新店分校學生清單數量變化
  - 任一重複學生（多科目）只保留一筆主檔
  - 年級顯示正確（J1/J2/J3/H1...）

## 風險與防呆

- 原始檔中 `學生` 可能含班型字樣（例：`邱致元1對2`），需正規化避免誤判新學生。
- 部分列無手機，`姓名+手機` 會退化成 `姓名+空值`，需在摘要中標示低信心匹配筆數。
- `Student.name` 長度限制 32，超長姓名需截斷或列錯誤清單。

