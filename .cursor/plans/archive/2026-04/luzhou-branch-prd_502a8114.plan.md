---
name: luzhou-branch-prd
overview: 撰寫「新增蘆洲分校」的系統面 PRD，聚焦立即上線所需的後端、前端、資料與驗收項目。
todos:
  - id: prd-review
    content: 確認 PRD 範圍與驗收標準（僅系統面、立即上線）
    status: completed
  - id: impl-backend-branch
    content: 後端：新增蘆洲分校 migration 並更新 CampusController 分校白名單與 fallback
    status: completed
  - id: impl-frontend-branch
    content: 前端：更新 useBranches 官方清單與預設分校資料
    status: completed
  - id: qa-branch-isolation
    content: 執行 API + 角色權限 + 分校切換/隔離驗收
    status: completed
  - id: release-smoke-test
    content: 完成上線 smoke test 與回報結果
    status: completed
isProject: false
---

# PRD：新增蘆洲分校（立即上線）

## 目標
- 在不影響既有分校資料隔離與權限邏輯下，將「蘆洲分校」納入系統可選分校，並可在註冊、登入後分校切換、報表與通知流程中正常運作。
- 上線模式採一次性快速上線（MVP），以最低風險方式完成。

## 問題背景
- 目前分校清單採白名單機制：後端與前端各自維護「官方分校順序」，非白名單名稱會被過濾。
- 現況可見於：
  - 後端分校白名單：[CampusController.php](/home/admin/backend/app/Http/Controllers/CampusController.php)
  - 前端分校白名單與過濾：[useBranches.js](/home/admin/frontend/src/lib/useBranches.js)
  - 既有新增分校 migration 參考：[2026_04_14_000001_add_shipai_dunhua_campuses.php](/home/admin/backend/database/migrations/2026_04_14_000001_add_shipai_dunhua_campuses.php)

## 範圍（In Scope）
- 新增 `Campus` 資料列（name/code + 預設欄位）。
- 將蘆洲分校加入後端可回傳分校名單（公開 `/api/v1/branches` 與登入後 `/api/v1/campuses`）。
- 將蘆洲分校加入前端官方分校排序與可見名單。
- 驗證分校切換、資料隔離（`require_campus`）、主任註冊選校與基本頁面載入。

## 不在範圍（Out of Scope）
- 蘆洲分校初始學生/老師/課程資料批次匯入。
- LINE/Telegram 全部通知參數最終定稿（僅保留可後續填值欄位）。
- 商業邏輯規則調整（繳費提醒、堂數扣除、評量流程不改）。

## 使用者與場景
- **主任（director）**：可在註冊/登入後看到並切換蘆洲分校。
- **老師（teacher）**：依 `UserCampus` 指派後可在蘆洲分校資料範圍內操作。
- **超管（super_admin）**：可檢視含蘆洲分校在內的完整清單。

## 功能需求
- **FR-1 分校主檔建立**：系統必須建立 `Campus.name=蘆洲分校`，並提供唯一 `code`（建議 `luzhou`）。
- **FR-2 公開分校清單可見**：`GET /api/v1/branches` 必須回傳蘆洲分校。
- **FR-3 驗證後分校清單可見**：`GET /api/v1/campuses` 對 super_admin 顯示全部，director/teacher 依授權顯示可用分校（含蘆洲時可見）。
- **FR-4 前端分校切換可選**：App 啟動載入分校、分校下拉、本地 `app_branch` 解析皆可處理蘆洲分校。
- **FR-5 既有流程不回歸**：既有 10 間分校順序與顯示不被破壞。

## 非功能需求
- **相容性**：不得破壞既有 API 結構（仍回傳陣列欄位 `id/name/code`）。
- **安全性**：維持 `require_campus` 資料隔離，不得跨校讀寫。
- **可回滾性**：migration 需具 `down()`，可刪除蘆洲分校資料列。

## 方案設計
- **資料層**
  - 新增 migration，模式比照 [2026_04_14_000001_add_shipai_dunhua_campuses.php](/home/admin/backend/database/migrations/2026_04_14_000001_add_shipai_dunhua_campuses.php)。
  - `NEW_BRANCHES` 加入 `['name' => '蘆洲分校', 'code' => 'luzhou']`。
- **後端 API 層**
  - 在 [CampusController.php](/home/admin/backend/app/Http/Controllers/CampusController.php) 的 `BRANCH_NAMES` 納入「蘆洲分校」。
  - 確認 `listPublic()` fallback payload 也包含蘆洲分校，避免 API 例外時前端看不到新分校。
- **前端呈現層**
  - 在 [useBranches.js](/home/admin/frontend/src/lib/useBranches.js) 同步加入：
    - `OFFICIAL_BRANCH_ORDER`
    - `DEFAULT_BRANCHES`
    - （必要時）`LEGACY_DEFAULT_ID_TO_NAME` 對應
  - 確保 `mergeWithDefaults()` 不會過濾掉蘆洲分校。

## 驗收標準（Acceptance Criteria）
- 呼叫 `/api/v1/branches` 可看到 `蘆洲分校`。
- super_admin 呼叫 `/api/v1/campuses` 可看到蘆洲；director/teacher 若有綁定蘆洲，可看到蘆洲。
- 前端分校下拉可選蘆洲，重整後 `localStorage.app_branch` 仍能正確還原。
- 切到蘆洲後，學生/課程/出缺勤頁面不出現跨校資料（抽測至少 3 個模組：`students`、`attendance`、`course-mgmt`）。
- 既有分校（興隆、新店、大安、木柵、東湖、大直、汐止、內湖、石牌、敦化）仍可正常使用。

## 測試計畫
- **API 驗證**：`branches`、`campuses` 回傳內容與順序。
- **權限驗證**：super_admin 與 director/teacher 的可見分校差異。
- **UI 驗證**：登入後分校切換、重整後分校記憶、主要頁面資料隔離。
- **回歸驗證**：主任註冊流程分校選單、TeacherHome 分校資料不混校。

## QA 驗收測試（本次新增，建議至少執行 8 項）
1. **AT-01 公開分校 API 可見蘆洲**
   - 前置：完成 migration + 後端部署。
   - 步驟：呼叫 `GET /api/v1/branches`。
   - 預期：回傳陣列包含 `{ name: "蘆洲分校" }`，且欄位結構維持 `id/name/code`。

2. **AT-02 驗證後分校 API（super_admin）**
   - 前置：使用 super_admin token。
   - 步驟：呼叫 `GET /api/v1/campuses`。
   - 預期：可見蘆洲分校與既有分校，不出現重複項目。

3. **AT-03 驗證後分校 API（director/teacher）**
   - 前置：建立一位有綁定蘆洲 `UserCampus` 的 director（或 teacher）。
   - 步驟：以該帳號呼叫 `GET /api/v1/campuses`。
   - 預期：至少可見蘆洲；未綁定帳號不可見未授權分校。

4. **AT-04 前端分校下拉可切換蘆洲**
   - 前置：登入可見蘆洲的 director 帳號。
   - 步驟：在前端分校下拉切換到蘆洲。
   - 預期：UI 顯示當前分校為蘆洲，且後續查詢帶入蘆洲分校範圍。

5. **AT-05 localStorage 分校記憶正確**
   - 前置：已在 UI 切到蘆洲。
   - 步驟：重新整理頁面（hard reload）。
   - 預期：`localStorage.app_branch` 對應到蘆洲，頁面重載後仍停留蘆洲。

6. **AT-06 跨校資料隔離（Students）**
   - 前置：蘆洲與其他分校各有至少 1 筆學生資料。
   - 步驟：切換到蘆洲，進入 `students`。
   - 預期：只看到蘆洲學生，不看到其他分校學生。

7. **AT-07 跨校資料隔離（Attendance/Course）**
   - 前置：蘆洲與其他分校各有課程/點名資料。
   - 步驟：切換到蘆洲，抽測 `attendance`、`course-mgmt`。
   - 預期：資料維持分校隔離，不混入他校課程或點名紀錄。

8. **AT-08 回歸測試（既有分校與註冊流程）**
   - 前置：以全新瀏覽器 session 測試。
   - 步驟：檢查主任註冊分校清單 + 既有分校切換功能。
   - 預期：原 10 分校可正常顯示與切換，新增蘆洲不破壞既有流程。

## QA 通過門檻（Go/No-Go）
- **Go**：AT-01 ~ AT-08 全數通過，且未出現跨校資料洩漏。
- **Conditional Go**：僅 UI 排序或文案輕微問題，不影響分校隔離與 API 正確性（需列修補單）。
- **No-Go**：任一 API 不含蘆洲、分校切換失敗、或出現跨校資料混入。

## 風險與緩解
- **風險 1：白名單不同步（前後端一邊漏改）**
  - 緩解：PR checklist 強制檢查 `CampusController::BRANCH_NAMES` 與 `useBranches` 三處常數一致。
- **風險 2：分校 ID 與舊快取衝突**
  - 緩解：保持 `resolveSavedBranchChoice()` 名稱/代碼雙路徑解析；必要時補 legacy map。
- **風險 3：上線後 API fallback 缺新分校**
  - 緩解：同步更新 `listPublic()` fallback 陣列。

## 上線計畫（立即）
1. 先部署 migration，建立蘆洲分校主檔。
2. 部署後端分校清單更新。
3. 部署前端分校來源更新並執行前端 deploy。
4. 以 director/teacher/super_admin 三種角色做 smoke test。

## 後續擴充（下一階段）
- 蘆洲分校初始老師與教室模板建立（`UserCampus`、`rooms`）。
- 蘆洲分校通知參數（LINE/Telegram）正式設定與驗證。
- 蘆洲分校開班前資料建置 SOP（批次匯入學生與課程）。