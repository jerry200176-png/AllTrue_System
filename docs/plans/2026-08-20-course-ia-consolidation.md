> Canonical source: https://github.com/jerry200176-png/AllTrue_System/issues/1922 (created from this file, 2026-08-20). This file is a local copy for review.

Title: `[UX] 課程/合約 IA 收斂：一筆紀錄一個主頁，行事曆與儀表板只做唯讀導向`
Parent: #1600

> 上層 Epic：#1600。前案：#1601（已關閉但問題未解）、#1618、#1621。
> Risk-Class：本文件 R0（docs only）；Phase A1 實作 R1；Phase A2/B 實作 R2。

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1（IA／可維護性，非資料錯誤） |
| 根因類型 | 資訊架構：同一筆 StudentClass 有多個互不相通的變更入口 |
| 根因摘要 | 「課程／合約」沒有唯一的 record 主頁。學生管理、課程管理、行事曆各自長出一套 mutation，新需求就往最近的畫面加，於是入口愈長愈多。 |
| 錯誤行為 | 2026-08-20 三個 PR（#1915／#1916）在行事曆單堂面板再加第三個合約管理入口 🔀改派合約。 |
| 預期行為 | 一筆課程合約＝一個主頁（學生管理的課程列）＝唯一會改狀態的地方；儀表板與行事曆是唯讀鏡頭，只做 deep-link。 |
| 歷史比對 | #1601「課程管理＋家長請假完整流程」2026-08-01 關閉，但 8/20 又新增同型入口 ⇒ #1601 只補了單一 deep-link，沒有處理「誰可以 mutate」這條規則。 |
| 根因層級 | 產品規則缺口，不是一次性 bug。 |

### 現況證據（本 session 實查，勿再重推）

**入口 1 — 課程管理 `frontend/src/pages/CourseManagement.vue`（7127 行）**
- 自稱是「即時掌握學生課程、續報風險、排課狀態與營運節奏」的儀表板（`:9`）。
- 實際上有 **27 個 API 呼叫點，其中約 20 個是 mutation**：`transfer-sessions`（`:1850`）、`pause`（`:1960`/`:1998`）、`purchase-batch`（`:2176`）、`renew-monthly`（`:2240`）、`add-session`（`:2369`）、`manual-sessions`（`:2452`）、`undo-leave`（`:2762`）、`class-sessions/{id}/substitute`（`:3420`）、`PUT /student-classes/{id}`（`:3529`/`:3815`/`:4338`）、`DELETE`（`:4159`）、invoice void（`:4096`）。
- 它自己的空狀態文案就自相矛盾：`:594`「請在「學生管理」為學生建立課程，或使用上方「新增課程」快速建立課程。」——同一句話同時說「去別頁做」和「在這裡做」。

**入口 2 — 學生管理 `frontend/src/pages/StudentsList.vue`（3724 行）**
- 真正的 per-course CRUD 在這裡：加購／續報加購按鈕 `:304-305` → `openAddSessionsForCourse` `:2502` → `POST /student-classes/{id}/purchase-batch` `:2562`。
- 後端 `StudentClassController::purchaseBatch`（`backend/app/Http/Controllers/StudentClassController.php:2463`）已在單一 transaction 內複製舊合約的 week/time/duration/teacher/subject/room/rate 建立新 StudentClass ⇒ 續報精靈的骨架已經存在。
- 缺點：課程列要點學生列才展開，沒有可視 affordance。但它是離「record 主頁」最近的東西。

**入口 3 — 行事曆 `frontend/src/pages/SmartCalendar.vue` + `frontend/src/components/calendar/modals/CalendarSessionEditModal.vue`**
- 既有單堂動作：請假／調課／換代課老師／取消本堂。
- 2026-08-20 新增 🔀改派合約：`CalendarSessionEditModal.vue:58-94`（按鈕＋inline 表單）、`SmartCalendar.vue:2183-2243`（targets 載入＋送出）、`:393-395`（事件接線）。
- 後端：`ClassSessionController::reassignContractTargets`（`:2045`）、`reassignContract`（`:2087`），路由 `backend/routes/api.php:569`／`:572`，皆 `role:super_admin`。

**本次規劃新發現（背景敘述有誤，請一併採納）**

1. **`purchaseBatch` 並不會建立 `course_contract_group` 關聯。** 全 repo 搜尋顯示 group 只由 `CourseContinuityService::createGroup`／`CourseContractGroupController`（`backend/routes/api.php:509-512`）建立。所以 Phase A2 不是「沿用既有自動關聯」，而是要**新增**自動關聯。
2. **前端沒有任何地方呼叫 `course-contract-groups`。** `grep -rn "course-contract-group" frontend/src` 無結果。而 🔀改派合約 按鈕的顯示條件是 `session.reassignTargets.length > 0`（`CalendarSessionEditModal.vue:58`），targets 又要求兩張合約已 group-linked ⇒ **這顆按鈕在正式站很可能從來沒有出現過**。落地前請先確認：`SELECT COUNT(*) FROM course_contract_group_members WHERE unlinked_at IS NULL;`
3. **本專案沒有 vue-router。** 導覽是 `App.vue` 的 `active` tab state（`:312` StudentsList、`:318` CourseManagement）。「deep-link」在這裡的意思是 `@navigate` 事件＋ focus prop，既有前例：`initialTeacherIdForNav` 與 `onNavigateFromCourseManagement`（`App.vue:1063`）。Phase B 直接沿用這個 pattern，不要引入 router。
4. **`reassignContract` 無法直接被 `purchaseBatch` 呼叫。** 它是一個把驗證、`response()->json()` 錯誤回傳、audit 寫入、`SessionDeductionService::recomputeCounters()` 全綁在一起的 controller closure。要重用必須先抽成 service method（回傳結果或丟 exception，不要回傳 HTTP response）。

## 1. 設計決策

**規則：一筆紀錄＝一個主頁＝唯一會改它狀態的地方。清單、儀表板、行事曆是唯讀鏡頭，只能導向該主頁，不得成為獨立的變更路徑。**

研究依據：
- **ERPNext／Frappe**（source-verified，本次與稍早 research pass）：renewal 動作是掛在被續約的紀錄本身上的單一 toolbar 按鈕（`toolbar.js:599 add_auto_repeat()`），依紀錄自身狀態決定是否顯示，不會出現在另一個獨立畫面。
- **GibbonEdu/core**（GPL-3.0，624★，2026-08-08 才 push，source-verified，本次新增）：學生的單一記錄頁 `modules/Students/student_view_details.php` 直接把 `Gibbon\UI\Timetable\Timetable`、`Gibbon\Module\Attendance\StudentHistoryView` 等跨模組元件組合進同一頁渲染，而不是叫使用者跳到獨立的 Timetable／Attendance 頁面。這是第二個、且是同業（學校/補習班排課系統）的原始碼驗證證據，比通用 CRM 更貼近 AllTrue 的實際場景。
- Salesforce Lightning record page／HubSpot record page 的「single record page」架構：**標記為 inferred／training knowledge**——本次曾用 Cloudflare Browser Run 實際取得 Salesforce Help 頁面（200 回應，非封鎖），但該頁是 JS SPA 殼，未能在時間內取出可驗證的條文內容，故維持 inferred 標記，不升級。
- Repo 內既有同向論述：`docs/architecture/RFC_PLATFORM_OPTIMIZATION_FROM_STARS_2026.md:130`「ERPNext（文件關聯不亂 merge）」。
- Design System SSOT：`docs/RULE_DESIGN_SYSTEM.md`。

## 2. 範圍（分階段）

### Phase A1 — 撤回今天的行事曆改派合約【今天可完成，1 個 PR】

**In**
- 移除 `CalendarSessionEditModal.vue` 的 `:58-94`（按鈕＋inline 表單）、`:233-234`（props 預設）、`:254`（emits）、`:258-264`（local refs／watch）、`:435-471`（樣式）。
- 移除 `SmartCalendar.vue` 的 `:393-395`、`:1986` 附近的 targets 預載、`:2183-2243`、`:2415-2416` 重置。
- 移除 `CalendarSessionEditModal.test.js` 內 `:14`／`:19`／`:64` 起的 reassign 相關案例與 fixture 欄位。
- 後端 `reassignContract`／`reassignContractTargets`／`class_session_reassignments` **暫時保留不動**（見 Open Question 1）。

**Out**：不動請假／調課／代課／取消本堂任何一條既有路徑。

### Phase A2 — 續報加購順帶結轉已上完堂次【今天做不完，需另開 issue】

誠實評估：這不是一個 session 的量。需要依序做完
1. 把 `reassignContract` 的驗證＋搬移＋recompute 抽成 service（例如 `SessionContractReassignService`），controller 只剩薄殼，既有 `ClassSessionReassignContractTest` 必須維持全綠。
2. 決定 `purchaseBatch` 是否要自動建立／掛入 `course_contract_group`（目前完全沒有），還是乾脆讓結轉不依賴 group（見 Open Question 2）。
3. `purchaseBatch` 新增 optional `carry_forward_session_ids: array`，在同一個 `DB::transaction` 內對每個 id 跑 service，最後一次 `recomputeCounters`。
4. `StudentsList.vue` 加購 modal 內新增「一併轉入新合約的已上堂次」多選（預設全不選）。

建議拆成兩個 PR（後端 service 抽取＋端點擴充／前端 modal），符合本 repo 正式程式碼 700 行上限。

### Phase B — 課程管理降級為唯讀 triage【今天只做最小的一刀】

**今天可完成**
- 移除頁首「新增課程」按鈕（`:25`）與 `:207`「為此學生新增課程」。
- 改寫 `:594` 空狀態文案，去掉自相矛盾的後半句，改成導向學生管理的按鈕（透過 `@navigate` 事件，沿用 `App.vue:1063`）。
- 新增「在學生管理中開啟」的 row-level deep-link，帶 student id 讓 `StudentsList` 自動展開該學生的課程列。

**今天做不完，需另開 issue**：其餘約 19 個 mutation 呼叫點（pause／transfer-sessions／renew-monthly／add-session／manual-sessions／substitute／PUT／DELETE／invoice void）的遷移。每一個都要先確認學生管理側有對等入口，沒有的要先補，否則就是把功能砍掉而不是搬家。這是多 PR、跨數個 session 的工作。

### Phase C — 學習評量表【只做調查，不做改動】

`frontend/src/pages/LearningRecordsPage.vue`（8697 行）**尚未取得證據**，僅憑 pattern 推測可能有相同問題。初步掃描顯示它有 16 個 mutation 呼叫點，但對 `student-classes` 只有兩處唯讀查詢（`:2630`、`:4517`），**沒有**直接改課程／合約的跡象 ⇒ 它的 mutation 很可能都是 LearningRecord 自身的狀態，屬於合理的 record-page 行為。

本階段交付物是一份調查結論（可作為本 issue 的 comment），不是程式碼。並且要先確認是否與 open 中的 #1621 重疊。

## 3. Non-goals

- **不做導覽重構**：不把課程管理併入學生管理成為單一頁面。那是更大的決策，另案討論。
- 不改資料庫真相：不動 `Charge`／`Invoice`／`Payment`、不動扣堂規則。
- 不動 `course_contract_group` 的既有語意（Phase A2 才會碰，且需先回答 Open Question 2）。
- 不做 `LearningRecordsPage` 的 IA 改動（那是 #1621 的範圍）。
- 不重寫行事曆的請假／調課／代課路徑。

## 4. 測試與驗收

**沿用 #1600 的固定驗收（原文）**：390/412/768/1280/1440、loading/empty/error/dense/long text、無水平溢出、鍵盤/focus/ARIA、權限/分校隔離、API/status contract、相關 regression family、Vite/lint/design guard、production health/version/desktop/mobile evidence。

**本 issue 專屬的回歸紅線（以下檔案必須全綠）**

| 檔案 | 為什麼 |
|---|---|
| `backend/tests/Feature/ClassSessionReassignContractTest.php` | Phase A1 若順手刪後端會直接紅；A2 抽 service 後語意必須不變 |
| `backend/tests/Feature/StudentClassPurchaseBatchTest.php` | Phase A2 主要修改對象 |
| `backend/tests/Feature/PurchaseBatchClosesSourceTest.php` | 續報會結案舊合約，結轉不得破壞這個順序 |
| `backend/tests/Feature/SessionEntitlementTransferTest.php` | 堂數歸屬移轉的既有契約 |
| `backend/tests/Feature/StudentClassTransferSessionsTest.php` | 同型的 session 搬移端點，語意須一致 |
| `backend/tests/Feature/CourseContinuityGroupApiTest.php` | Phase A2 若動 group 建立邏輯 |
| `frontend/src/components/calendar/modals/__tests__/CalendarSessionEditModal.test.js` | Phase A1 直接修改；同時確保請假／調課／代課／取消四顆按鈕的既有案例不受波及 |
| `frontend/src/composables/calendar/__tests__/useCalendarLeaveExtra.test.js`、`useCalendarReschedule.test.js`、`useCalendarSubstitute.test.js` | 確認移除改派沒有波及同一面板的其他動作 |
| `npm run test:calendar`、`npx vitest run` 全綠 | #1916 的基準是 249/249，移除後案例數會下降，須說明差額來源 |

**失敗會怎樣（給 reviewer 的風險清單）**
- Phase A1 若順手刪掉後端端點但沒刪 migration／test ⇒ CI 紅。
- Phase A2 若把 `recomputeCounters` 漏在 transaction 外 ⇒ 兩張合約的剩餘堂數不一致，直接影響繳費。
- Phase A2 若忘了同步 `LearningRecord.StudentClassID`（`reassignContract` 目前有做，見 `ClassSessionController` 內註解與 `LearningRecordDriftCheck`）⇒ 帳務／審核查詢會漂移。
- Phase B 若移除按鈕但沒補 deep-link ⇒ 直接是功能退化，不是 IA 改善。

## 5. Rollout

- 沿用現行治理：feature branch → PR → CI 全綠 → agent squash-merge（`CLAUDE.md`：required checks 綠即可 R0–R3 自行合併，不等人類 review）。禁止直推 main、禁止 SSH 上 Pi 改碼。
- 若 Phase A2 產生 migration：合併後才 `php artisan migrate --force`（CLAUDE.md R5）。
- 正式程式碼 PR 上限 700 行 ⇒ Phase A2、Phase B 必須拆。
- `.agent-session/manifest.json` 幾乎必衝突，直接 `git checkout --ours` 或 `git rm -f`（#1867）。
- PHPStan Advisory 紅燈不擋合併；新的 undefined property／static method 錯誤照現有格式手動加進 `backend/phpstan-baseline.neon`，不要整檔重生成（#1867）。

**本計畫會撤回今天已上線的功能，使用者影響評估：**
- 消失的是 `單堂檢視` 面板中的 🔀改派合約 按鈕。
- 影響範圍極小：該端點是 `role:super_admin` only，且按鈕只在該堂次的合約已被 `course_contract_group` 關聯時才渲染，而**前端沒有任何地方能建立這種關聯**。合理推論是正式站上根本沒人看得到它。
- **落地前必做**：查 `course_contract_group_members` 實際筆數。若為 0 ⇒ 不需要任何對外溝通，PR 描述註明即可。若 >0 ⇒ 需在 `ReleaseNotesPage` 補一則說明，並在 Phase A2 上線前提供替代路徑（續報加購時勾選結轉）。
- Rollback：revert PR 即可，Phase A1 不含 schema 變更。

## 6. 給 reviewer 的未決問題

1. **Phase A1 要不要一起刪後端？** 選項：(a) 只刪前端，`reassignContract` 留著給 A2 當基礎，`reassignContractTargets` 變成死碼；(b) 前後端全刪（含 `class_session_reassignments` 表與 migration），A2 從零寫。(b) 比較乾淨但會丟掉一份已通過測試的正確邏輯與 audit 表。
2. **A2 到底要不要建立在 `course_contract_group` 之上？** 既然正式站可能一個 group 都沒有，堅持 group-link 前置條件等於要求主任先做一個 UI 上根本不存在的動作。是否改成：續報加購時「同一學生＋同一科目＋來源合約」這個上下文本身就足以授權結轉，完全不引入 group？
3. **Phase B 的驗收標準要訂在哪？** 「課程管理變成真正唯讀」是約 20 個 mutation 的搬遷，跨數個 session。今天只拿掉「新增課程」＋修文案＋加 deep-link，是否可接受作為本 issue 的 Phase B 完成定義，其餘另開子 issue？還是應該一次訂死完整目標、分批交付但不分開結案（#1600 有「不以半成品結案」的規定）？
4. **Phase C 與 #1621 重疊怎麼處理？** #1621 已經 open 且明確擁有 `LearningRecordsPage` 的資訊架構。本 issue 是只交付一份「有／沒有重複 mutation 入口」的調查結論後就把球傳給 #1621，還是直接不設 Phase C、把這個問題寫成 #1621 的一則 comment？

### Critical Files for Implementation
- frontend/src/components/calendar/modals/CalendarSessionEditModal.vue
- frontend/src/pages/SmartCalendar.vue
- frontend/src/pages/CourseManagement.vue
- backend/app/Http/Controllers/StudentClassController.php
- backend/app/Http/Controllers/ClassSessionController.php
