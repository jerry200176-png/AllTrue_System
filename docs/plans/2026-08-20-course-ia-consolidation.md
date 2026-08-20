> Canonical source: https://github.com/jerry200176-png/AllTrue_System/issues/1922 (this file is a local copy for review). Sub-issue of Epic #1600.

---
owner: jerry (CEO)
status: Plan — awaiting agent review before implementation
review_cycle: as-needed
last_reviewed: 2026-08-20
---

# [UX] 課程／合約資訊架構收斂：一筆紀錄一個主檔頁，其餘只做唯讀導覽

**Epic:** #1600 ｜ **前案:** #1601（已關閉，未解決本問題）、#1382 `RFC_COURSE_CONTINUITY.md`
**今日相關 PR:** #1915（後端 reassign-contract）、#1916（行事曆 🔀改派合約 前端）
**Risk-Class:** Phase A = R2（動堂數歸屬與計數）；Phase B 首刀 = R1（前端刪動作）；Phase C = R0（唯讀稽核）

---

## 0. 問題陳述（Root Cause）

同一筆「學生的某一門課／某一份合約」目前有 **三個以上互不相通的變更入口**，今天又新增了第四個。

| # | 入口 | 實證 | 性質 |
|---|---|---|---|
| 1 | 課程管理 `frontend/src/pages/CourseManagement.vue`（7127 行） | 頁首自稱「即時掌握學生課程、續報風險、排課狀態與營運節奏」(:9)，但檔內有約 25 個寫入端點：`transfer-sessions`(:1850)、`pause`(:1960,:1998)、`purchase-batch`(:2176)、`renew-monthly`(:2240)、`add-session`(:2369)、`manual-sessions`(:2452)、`undo-leave`(:2762)、`substitute`(:3420)、`PUT student-classes`(:3529,:3815)、`DELETE`(:4159)、`invoices/{id}/void`(:4096) | **不是唯讀 dashboard，是第二套完整 CRUD** |
| 2 | 學生管理 `frontend/src/pages/StudentsList.vue`（3724 行） | 課程列的 加購／續報加購 (:304-305) → `openAddSessionsForCourse` (:2502) → `POST /student-classes/{id}/purchase-batch` (:2562)；另有 繳費資訊／編輯／刪除 | **真正的主檔 CRUD** |
| 3 | 班級行事曆 `SmartCalendar.vue` + `components/calendar/modals/CalendarSessionEditModal.vue` | 單堂動作：請假／調課／換代課老師／取消本堂 | 單堂例外處理（合理） |
| 4 | **今天新增** 🔀改派合約 | `CalendarSessionEditModal.vue:58-94`、`SmartCalendar.vue:2183-2243`；後端 `ClassSessionController::reassignContract()` (:2087)、`reassignContractTargets()` (:2045)，路由 `backend/routes/api.php:569,572`，皆 `super_admin` only | **第四個入口；本計畫要撤掉** |

**兩個關鍵佐證（本 session 讀原始碼確認，非推論）：**

1. **今天上線的按鈕在正式站很可能根本看不到。** 按鈕的顯示條件是 `session.reassignTargets.length > 0`（`CalendarSessionEditModal.vue:58`），而 targets 來自 `course_contract_group` 關聯。全前端 `grep -rn "course-contract-groups" frontend/src` → **零結果**：`CourseContractGroupController`（`api.php:509-512`）只能用 API/DB 直接呼叫，沒有任何 UI 能建立群組關聯。**實作前務必先查正式站 `course_contract_groups` 是否有資料**（見第 7 節 Q1）。
2. **`purchaseBatch` 不會建立群組關聯。** `grep -n "CourseContractGroup" backend/app/Http/Controllers/StudentClassController.php` → 零結果。群組只在 `CourseContinuityService::createGroup()` 建立。因此「續報加購已自動 course_contract_group 連結」這個前提 **不成立**，Phase A 必須自己補上這一段。

**#1601 為何沒解決：** #1601（2026-08-01 關閉）範圍是 `CourseManagement.vue`／`ParentPortal.vue`／`DirectorDashboard.vue` 之間的**家長請假 deep-link 與案件摘要**——它是「加一條導覽路徑進主任收件匣」，從未移除任何重複的變更入口。所以它關閉後（8/20）同型反模式又長出第四個。這次要修的是**移除入口**，不是再加導覽。

---

## 1. 設計決策：一筆紀錄 = 一個主檔頁

**原則：** 一筆紀錄的狀態只在它的主檔頁被改變；dashboard 與行事曆是**唯讀鏡頭**，互動元素一律 deep-link 回主檔頁，不得自成變更路徑。

對 AllTrue 的對應：
- **StudentClass（課程／合約）的主檔頁 = 學生管理的學生展開列。** 加購／續報／編輯／刪除／繳費留在這裡。
- **課程管理 = 唯讀 triage lens**（搜尋、篩選、班型統計、續報風險）。
- **行事曆 = 單堂例外處理**（請假／調課／代課／取消本堂）。合約層級的動作不屬於這裡。

**證據層級（誠實標示）：**

| 來源 | 內容 | 驗證狀態 |
|---|---|---|
| **GibbonEdu/core**（GPL-3.0，624★，pushed 2026-08-08，**同領域**：學校管理） | `modules/Students/student_view_details.php` 是唯一的學生主檔頁，直接在同一頁 inline 組合跨模組視圖 —— `use Gibbon\UI\Timetable\Timetable;` 與 `use Gibbon\Module\Attendance\StudentHistoryView;` 與一般學生資料一起渲染，**不需要跳去獨立的 Timetable／Attendance 頂層頁**才看得到某學生的課表與出席歷程 | ✅ 原始碼驗證（本 session） |
| **frappe/ERPNext** | 每個 DocType 只有一個 form route，list／calendar view 一律導回 form 才能編輯；狀態變更動作掛在 form 上 | ⚠️ 本 session 未逐行複驗檔案／行號，寫進 PR 描述前請重新取得精確引用 |
| **Salesforce Lightning Record Page / HubSpot record page** | 「一筆紀錄一個頁面、related lists 唯讀導覽」 | ❌ **推論／訓練知識，未經現場驗證**。本 session 嘗試 live fetch，取得 200 但頁面是 JS SPA shell，時間內取不到可擷取的正文。**維持推論標示，不得升級為已驗證** |

Gibbon 是本計畫最強的引用：同一個領域（學校排課／出席），同樣的結論。

---

## 2. 範圍（分階段）

### Phase A — 撤回行事曆的改派合約，把能力併回續報加購 ✅ 一天內可完成

**A1（刪除，前端 R1）**
- `CalendarSessionEditModal.vue`：移除 :58-61 按鈕、:72-94 inline 表單、:233-234 props 欄位、:254 三個 emits、:258-264 local state、:435-471 CSS。
- `SmartCalendar.vue`：移除 :393-395 事件綁定、:2183-2243 `reassignTargets`／`reassignState`／`doConfirmReassign`／targets 載入 (:1986,:2196)、:2415-2416 reset。
- `CalendarSessionEditModal.test.js` 移除對應 3 個測項（reassign 相關），保留其餘。

**A2（後端重構，不改行為）**
`reassignContract()`（`ClassSessionController.php:2087-`）目前是一個把驗證與 `response()->json()` 混在一起的 controller method，**無法直接在 `purchaseBatch` 內重用**。抽出核心到 service（建議 `App\Services\CourseContinuityService`，群組邏輯已在那裡），簽章回傳結果／丟例外，而非 `JsonResponse`。必須完整保留現有行為：同學生同科目檢查、群組關聯檢查、`lockForUpdate`、`assertCourseIsMutable()`、`class_session_reassignments` 稽核列、`LearningRecord.StudentClassID` 同步（denormalized mirror，`LearningRecordDriftCheck` 依賴）、`SessionDeductionService::recomputeCounters()` 雙邊重算。

**A3（併入續報加購）**
`StudentClassController::purchaseBatch()`（:2463）已在 `DB::transaction` 內複製 week/time/durationN/TeacherID/SubjectID/room_id 等排課設定建立新 `StudentClass`。新增：
- 選填 `carry_forward_session_ids: array<int>`；
- 建立新合約後，**建立 `course_contract_group` 關聯**（新舊合約入同一群組；這是新行為，Q1 待確認）；
- 對每個 session id 跑 A2 抽出的核心邏輯，全在**同一個 transaction** 內；
- 任一堂失敗 → 整筆 rollback（不得留下「新合約已建但堂次沒搬」的中間狀態）。

前端：`StudentsList.vue` 加購 modal（:586-623）加一區「一併帶入已上課堂次」——列出舊合約已完成／已填評量的堂次，預設全不勾。

**A4（誠實的範圍界線）** 若 Q1 答案是「正式站已有群組資料且有人用過改派」，A1 的刪除要改成保留一個 super_admin 補救路徑，Phase A 就會超過一天，另開 follow-up。

### Phase B — 課程管理回歸唯讀 lens ⚠️ 一天只能做第一刀

一天內可完成：
- 移除 `CourseManagement.vue:25` 的「新增課程」按鈕與 :611 的排課 modal 入口；
- 修正 :594 自相矛盾的空狀態文案（只留「請在『學生管理』為學生建立課程」＋一個導向按鈕）；
- 導覽用**既有機制**：本 repo 沒有 vue-router，`App.vue` 用 `active` tab state；已有 `@navigate` → `onNavigateFromCourseManagement()`（`App.vue:318,1063`）與 `initial-teacher-id` prop 的先例，照抄即可，**不要引入 router**。

**一天內做不完，必須另開 follow-up issue：** 其餘約 20 個寫入端點（pause／renew-monthly／add-session／manual-sessions／transfer-sessions／substitute／PUT／DELETE／invoice void）。這是一個 7127 行檔案的多 PR 逐步下架，每個端點都要先確認學生管理側有對等入口才能移除。**不要在本 issue 假裝一天做得完。**

### Phase C — 學習評量表：先查證，不預設 🔍 稽核，不改碼

初步唯讀觀察（本 session）：`LearningRecordsPage.vue`（8697 行）有 16 個寫入呼叫，但對 `student-classes` 的呼叫只有兩處 **GET**（:2630、:4517）——即它的變更面**侷限在 LearningRecord 自己的領域**，可能本來就符合原則。

Phase C 交付**只有一份稽核報告**：逐一列出 16 個寫入端點、各自主檔歸屬、與學生管理／行事曆是否重疊，再決定要不要動。**不預設同一套修法適用。** 與 #1621 範圍重疊，結論要回寫到 #1621，不另闢戰場。

---

## 3. Non-goals

- ❌ **不做導覽重構**：不把 課程管理 併進 學生管理 變成單一頁面。那是更大的產品決策，另案處理（見 Q4）。
- ❌ **不改資料庫真相**：不動 Charge／Invoice／Payment，不動堂數扣除規則。Phase A 只重用既有的 `recomputeCounters()`。
- ❌ **不移除 `POST /class-sessions/{id}/reassign-contract` 端點**：#1915 的後端與稽核表保留（仍是 super_admin 補救工具），只撤掉行事曆 UI 入口。
- ❌ **不重寫行事曆的單堂例外流程**：請假／調課／代課／取消本堂維持現狀。
- ❌ **不在 Phase C 動任何程式碼**。

---

## 4. 驗收標準

**Epic #1600 固定驗收（原文照抄）：**
> 390/412/768/1280/1440、loading/empty/error/dense/long text、無水平溢出、鍵盤/focus/ARIA、權限/分校隔離、API/status contract、相關 regression family、Vite/lint/design guard、production health/version/desktop/mobile evidence。

**本計畫專屬回歸（檔名已 grep 確認存在）：**

| 測試檔 | 為何相關 |
|---|---|
| `backend/tests/Feature/ClassSessionReassignContractTest.php` | A2 抽 service 後行為必須逐項不變（含 `LearningRecord` byte-for-byte、雙邊計數、稽核列、403） |
| `backend/tests/Feature/StudentClassPurchaseBatchTest.php` | A3 不得破壞既有續報加購 |
| `backend/tests/Feature/PurchaseBatchClosesSourceTest.php` | 續報後舊合約結案語意 |
| `backend/tests/Feature/CourseContinuityGroupApiTest.php` | A3 新建群組關聯不得破壞既有群組 API |
| `backend/tests/Feature/StudentClassTransferSessionsTest.php`／`SessionEntitlementTransferTest.php` | 另一條搬堂數路徑，計數不得互相干擾 |
| `frontend/.../modals/__tests__/CalendarSessionEditModal.test.js` | A1 刪除後其餘測項全綠 |

**新增測試（最小集）：**
- 後端：續報加購 + 帶入 2 堂 → 新合約建立、群組關聯建立、2 堂 `StudentClassID` 已改、雙邊計數正確、`LearningRecord` 內容未變、稽核列 2 筆。
- 後端：帶入的堂次中有一堂不可變更 → **整筆 rollback**，新合約不存在。
- 前端：加購 modal 未勾任何堂次時 payload 不含 `carry_forward_session_ids`（既有流程零變化）。

**若出錯會壞什麼（給 reviewer 的紅線）：** 堂次歸屬錯 → 已用／剩餘堂數錯 → 帳務與續報風險判斷錯，且評量歷程掛到錯的合約。這是 R2，不是 UI 改動。

---

## 5. Rollout

- 走現有治理：feature branch → PR → CI 全綠 → squash-merge（`CLAUDE.md` R3/R6：禁止直接 push main、禁止 SSH 上 Pi 改碼）。
- 拆 PR：**A1（前端刪除）／A2（後端重構，行為不變）／A3（purchaseBatch 擴充＋前端 UI）／B 首刀** 各自獨立 PR。動到 `backend/app`、`frontend/src` 的 PR 有 700 行上限（#1867）。
- **本計畫會回退今天已上正式站的功能。** 消失的使用者可見行為：單堂檢視面板的 🔀改派合約 按鈕。影響評估：
  - 該按鈕是 **super_admin only**（`api.php:569,572`）→ 一般老師／主任本來就看不到；
  - 顯示條件需要 `course_contract_group` 關聯，而**沒有任何 UI 能建立該關聯** → 極可能從未在正式站顯示過。
  - **結論：先查 DB 確認 `course_contract_groups` 筆數；若為 0，不需要對外溝通**，PR 描述註明即可。若非 0，需通知曾使用者「改用 學生管理 → 續報加購 → 帶入已上課堂次」。
- Rollback：revert PR。A3 若已建立群組關聯資料，revert 程式碼不會刪資料 —— 群組關聯是 additive、既有 API 支援 unlink，可接受。
- 已知操作坑（#1867）：`.agent-session/manifest.json` 幾乎必衝突（直接 `git checkout --ours` 或 `git rm -f`）；PHPStan advisory 紅燈不擋合併，新的 undefined property 要手動加 `phpstan-baseline.neon` 條目、不要整檔重生成。

---

## 6. 交付分割與誠實的時程

| 項目 | 今天做得完？ |
|---|---|
| A1 撤回行事曆改派 UI | ✅ |
| A2 後端抽 service（行為不變） | ✅ |
| A3 purchaseBatch 帶入堂次（含群組關聯） | ⚠️ 勉強；Q1／Q2 未定則不動工 |
| B 首刀（移除新增課程＋修空狀態） | ✅ |
| B 其餘約 20 個寫入端點下架 | ❌ **另開 follow-up issue** |
| C 稽核報告 | ✅（只有報告） |
| C 依報告修改 | ❌ **視結論另議** |

---

## 7. 給 review agent 的問題

1. **正式站 `course_contract_groups` 有沒有資料？** 若為 0，Phase A 的 A1 是純刪除、零使用者影響，而 A3 會是**史上第一個建立群組關聯的程式路徑**——那麼「每次續報加購都自動建群組」是可接受的預設，還是應該做成使用者勾選帶入堂次時才建？（自動建群組會改變 #1382 RFC 對群組語意的假設。）
2. **權限：** `reassignContract` 今天是 `super_admin` only，但 續報加購 是主任日常操作。帶入堂次會改動帳務歸屬與堂數計數 —— 要 (a) 主任可用（新開放的權限面）、(b) 只有 super_admin 看得到帶入區塊、還是 (c) 主任可提出、super_admin 核准？(c) 明顯超出一天。
3. **Phase B 的終局：** 課程管理有約 25 個寫入端點而非一顆按鈕。要 (a) 今天只移除 新增課程 並開 follow-up 逐步下架，還是 (b) 承認「唯讀 lens」在多 PR 下架完成前只是宣示，先寫進 issue 當北極星？
4. **課程管理 該不該存在？** 若它的正當範圍只剩搜尋／篩選／統計，那和 學生管理 的清單重疊度很高。本計畫**刻意把導覽合併列為 non-goal**——請 reviewer 確認這個切分正確，或指出 Phase B 沒有先回答 Q4 就做不下去。

### Critical Files for Implementation
- frontend/src/components/calendar/modals/CalendarSessionEditModal.vue
- frontend/src/pages/SmartCalendar.vue
- frontend/src/pages/CourseManagement.vue
- frontend/src/pages/StudentsList.vue
- backend/app/Http/Controllers/StudentClassController.php
- backend/app/Http/Controllers/ClassSessionController.php
- backend/app/Services/CourseContinuityService.php
