# AI／工程師防再犯紀錄（必讀）

本檔記錄**已發生過的產品／實作缺口**，避免下次改壞或改漏。  
**任何 AI Agent 或新進開發者**：請與 `AGENTS.md` 的 First-read 順序一併閱讀；修改下列模組前**先對照本檔**。

**不同工具如何接到本檔：** **Cursor** 透過 `AGENTS.md` 與 `.cursorrules`；**Claude Code** 讀根目錄 **`CLAUDE.md`**；**GitHub Copilot**／在 GitHub 上工作的 AI 讀 **`.github/copilot-instructions.md`**；人類協作者請看 **`CONTRIBUTING.md`**（皆連回本檔與繳費規則）。

相關專項規格：

- 主任儀表板「繳費提醒」完整規則：`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`
- 內部聊天、Bug 回報、使用者頭像（**含禁止回歸項**）：**`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**
- **手動排課日期＝已上完（過去日）**：**`docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`**（勿擅自改語意）
- **主任「繳費／續課提醒」**：**`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`**（堂數制低堂數含已繳、月結「小於 5 天」等；**改動前必問使用者**）
- **課程 Stop 語意與 `closed_reason`**：見下方 **§2026-04-13 — 課程 Stop 語意：closed_reason 區分暫停 vs 結算**（勿移除 `settled` 寫入；勿用 `closed_reason` 影響 alert 篩選；resume 務必清除）
- **催繳名單與繳費單圖**：見下方 **§2026-04-13 — 催繳名單、tuition-slip 與 PaymentSlipModal**（名單須與 `alerts/tuition` 同源；**已繳不產圖**；無 Invoice 用 `tuition-slip`，勿與帳單編號語意混用）
- **固定排課／批次入班／學生課程列表「時段」／編輯課程改星期後未來堂**：見下方 **§2026-04-12 — 固定排課契約與堂次一致**（手動日、列表顯示、`PUT` 同步三項一次對照）
- **老師教學工作台**：見下方 **§2026-04-12 — 老師教學工作台（TeacherHome）**（預設頁、跨分校週課表、badge、deploy）
- **課程管理專注模式與 modal 層級**：見下方 **§2026-04-12 — 專注模式與 modal z-index / 契約時段不得被覆寫**
- **老師管理「授課學段」**：見下方 **§2026-04-13 — 老師管理須含授課學段（subject_level_scopes）**
- **當月學收取代帳單列表**：見下方 **§2026-04-13 — 當月學收月報（取代帳單列表）**（勿加回 `billing` 側欄項；API 只讀不改扣堂）
- **調課後評量表「消失」**（請假 cascade 作廢 LR + 堂次改日後已上）：見下方 **§2026-04-13 — 調課／請假 cascade 後評量表作廢未恢復**
- **出缺勤「補登」仍列出已暫停課程**：見下方 **§2026-04-13 — 出缺勤補登（`ended-sessions`）須排除 `StudentClass.Stop=1`**
- **手機出缺勤「請假確認沒反應」**：見下方 **§2026-04-14 — 手機請假確認：彈窗層級 + `leave/excused` 契約 + 非同步確認流程**
- **智慧排課誤標「取消」**（同日同時段 `cancelled + scheduled`）：見下方 **§2026-04-14 — 智慧排課角標誤判（張正樂 4/15）**
- **老師自助註冊 vs 主任待審／Teacher 重複鍵**：見下方 **§2026-04-15 — 老師註冊 Server Error 與 `directors/pending` 誤列老師**
- **老師管理側欄橘點 vs「待審核」**：見下方 **§2026-04-15 — 側欄 `pending_teachers` 與 `TeachersList`「待審核」不同步**
- **單堂加課衝突（已有出缺勤/核准評量）**：見下方 **§2026-04-15 — 單堂加課衝突修正**（`detectAddSessionConflict` 共用邏輯、前端預檢、結構化 409）
- **課程管理堂次警示誤報（請假/調課後）**：見下方 **§2026-04-15 — 請假調課後堂次警示假陽性**（`sessionUnits().length` 不可與購買堂數比較；須用 `effectiveSessionCount`）
- **課程管理同日 chip 重複（LEFT JOIN 行乘積）**：見下方 **§2026-04-15 — ClassSessionController::index LEFT JOIN 行乘積導致 chip 重複**（`sub_sched`／`LearningRecord`／`StudentSingIn` 的 LEFT JOIN 必須用 Derived Table 去重；前端 `normalizeClassSessionsPayload` 須有 id 去重防禦層）
- **評量頁課表同時段重複卡片**（`cancelled + scheduled` 同格兩張卡）：見下方 **§2026-04-15 — LearningRecordsPage 課表 widget 同格重複卡片（buildEvents 未去重）**

---

## 2026-04-15 — LearningRecordsPage 課表 widget 同格重複卡片（buildEvents 未去重）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 老師評量頁（`LearningRecordsPage.vue`）的課表 widget，鄭翔祐 4/15 19:30-21:30 看到張正樂出現兩張「國文 未填」評量卡。 |
| **根本原因** | 同一 `StudentClassID` 同日同時段存在兩筆 `ClassSession`（`cancelled` + `scheduled`），`buildEvents` 直接迭代所有 `rawSessions` 而無 status 過濾或去重，每筆 ClassSession 都生成一張卡片。此問題與 §2026-04-14 智慧排課角標誤判（張正樂 4/15）**同源**，但 SmartCalendar 的修正（`SESSION_STATUS_PRIORITY` + `pickBestSessionRow`）未同步至 LearningRecordsPage。 |
| **正確行為** | `buildEvents` 對每門課的 rawSessions 先以 `(session_date, start_time)` 分組，同組多筆用統一優先序（`attended/completed/late/absent > scheduled > leave/leave_adjusted/excused > cancelled`；同狀態 `id desc`）只保留最優一筆，再生成卡片。 |
| **禁止回歸** | **(a)** 勿移除 `buildEvents` 中的 `deduplicateSessionsBySlot` 呼叫。**(b)** 勿把優先序改為 `cancelled` 高於 `scheduled`。**(c)** `SESSION_STATUS_PRIORITY` 與 `pickBestSession` 須與 `SmartCalendar.vue` 的同名常數保持一致。**(d)** 新增其他消費 `sessionDatesByClassId` 的路徑時，同樣必須套用去重。 |
| **關聯檔案** | `frontend/src/pages/LearningRecordsPage.vue`（`SESSION_STATUS_PRIORITY`、`pickBestSession`、`deduplicateSessionsBySlot`、`buildEvents`）、`frontend/src/pages/SmartCalendar.vue`（同名常數）、`backend/tests/Feature/ClassSessionDuplicateStatusTest.php` |
| **測試** | 既有 `ClassSessionDuplicateStatusTest`（DB 層）；前端可手動驗證同格 `cancelled+scheduled` 時評量頁只顯示一張卡。 |

---

## 2026-04-15 — ClassSessionController::index LEFT JOIN 行乘積導致 chip 重複

| 項目 | 說明 |
|------|------|
| **曾發生的問題** | 課程管理頁木柵校林宥彣理化課程，1/12 同一時段（16:00-18:00）顯示 3 個相同的「取消」chip。點任一個標記「已上」或「取消」，3 個全部同步變動。 |
| **根因** | `ClassSessionController::index`（L85–139）對 `schedules`（`sub_sched`）使用 `leftJoin`，當同一 `(student_course_id, DATE(schedule_date), start_time)` 組合有多筆 `status=scheduled AND original_schedule_id IS NOT NULL` 的記錄時，SQL Cartesian Product 使同一 `ClassSession` 出現 N 次。案例：course 70 在 2026-01-12 有 3 筆符合條件的 substitute schedule。前端 `normalizeClassSessionsPayload` 無 id 去重，照單全收 push 進 `byClass`，導致 Vue `v-for` 渲染重複 chip（`:key=id:XXX` 三個完全相同）。 |
| **修正** | (1) 後端 `sub_sched`、`LearningRecord`、`StudentSingIn` 三個 LEFT JOIN 全改為 **Derived Table Subquery**，每組合只取 `MAX(id)` 一筆。(2) 前端 `normalizeClassSessionsPayload` 加 `id` 去重（`.some()` 檢查）作為防禦層。(3) `updateLocalSessionRow` 改為遍歷所有同 id 列（非只 `findIndex` 第一筆）。 |
| **禁止回歸** | **(a)** 勿把 `sub_sched`/`LearningRecord`/`StudentSingIn` 的 LEFT JOIN 改回直接 join（不加 Derived Table 去重）。**(b)** 勿移除 `normalizeClassSessionsPayload` 中的 id 去重邏輯。**(c)** 新增 LEFT JOIN 到 `ClassSessionController::index` 時，必須評估是否會造成行乘積（1:N 關係必須用 Derived Table 或 subquery 限定為 1:1）。 |
| **關聯檔案** | `backend/app/Http/Controllers/ClassSessionController.php`（`index` 方法）、`frontend/src/lib/classSessionsApi.js`（`normalizeClassSessionsPayload`）、`frontend/src/composables/course-management/useCourseSessionsDisplay.js`（`updateLocalSessionRow`） |
| **資料稽核** | `LearningRecord`/`StudentSingIn` 無重複非作廢列；`schedules` 有 2 組重複（course 70 × 3、course 190 × 4）。重複資料不需清理——Derived Table 已在 query 層面處理。 |

---

## 2026-04-15 — 請假調課後堂次警示假陽性

| 項目 | 說明 |
|------|------|
| **曾發生的問題** | 課程管理頁展開上課日期面板時，購買 8 堂、已上 6、剩餘 2（含請假與調課）的課程仍顯示「⚠ 排程列數與購買堂數不一致」。使用者（主任）誤以為系統資料異常，實際數據正確。 |
| **根因** | 前端 `CourseManagement.vue` 用 `sessionUnits(c).length !== getPurchasedSessions(c)` 做警示判定。`sessionUnits` 只排除 `cancelled`，**仍包含 `leave/leave_adjusted`**（請假列），導致「請假原堂 + 補課新堂」使總列數 > 購買堂數，觸發假陽性。而後端 `extendSessionsIfNeeded` 已明確排除 `cancelled/leave/leave_adjusted`。 |
| **修正** | (1) 在 `useCourseSessionsDisplay.js` 新增 `SESSION_NOT_OCCUPYING_QUOTA` 狀態矩陣常數，與後端口徑一致。(2) 新增 `effectiveSessionCount`（排除非占額狀態的堂次數）。(3) 新增 `sessionCountWarning` 結構化警示判定（`over`/`under_leave`/`under_other`）。(4) 前端警示改用 `sessionCountWarning(c)` 取代原始列數比較。(5) 請假未補課時文案改為「有請假堂次尚未補課」（藍色資訊色），與真異常黃色警告區分。 |
| **禁止回歸** | **(a)** 勿把警示條件改回 `sessionUnits().length !== purchased` 或任何包含請假列的計數。**(b)** `SESSION_NOT_OCCUPYING_QUOTA` 與後端 `extendSessionsIfNeeded` 的 `whereNotIn` 必須同步維護。**(c)** 勿讓 `effectiveSessionCount` 影響 `displayRemainingSessions`——兩者解耦。 |
| **狀態矩陣** | 占購買額度：`scheduled`, `attended`, `completed`, `late`, `absent`。不占：`cancelled`, `leave`, `leave_adjusted`, `excused`。 |
| **測試** | `backend/tests/Feature/SessionCountWarningTest.php`（5 案例：CaseA~E）。 |
| **關聯檔案** | `frontend/src/composables/course-management/useCourseSessionsDisplay.js`、`frontend/src/pages/CourseManagement.vue`、`backend/app/Http/Controllers/StudentClassController.php`（`extendSessionsIfNeeded`） |

---

## 2026-04-15 — 單堂加課衝突修正

| 項目 | 說明 |
|------|------|
| **曾發生的問題** | 主任在課程管理/學生管理「加課／補登」選了一個已有出缺勤或核准評量的日期＋時段，系統只彈出「該堂已有出缺勤或核准評量，無法重覆補登」，使用者不知如何解決，只能反覆嘗試或致電客服。 |
| **根因** | `StudentClassController::addSession()` 偵測到同日時段有 `ClassSession` 且在鎖定集合（`StudentSingIn` / approved `LearningRecord`）時，回傳單一 `message` 的 409，前端只做 `alert(json.message)`，無引導。 |
| **修正** | (1) 抽出 `detectAddSessionConflict()` 為 check 與 addSession 共用的私有方法；(2) 409 改為結構化 JSON（`error_code`, `conflict_type`, `has_attendance`, `has_approved_learning_record`, `conflict_session_id`, `suggested_actions`）；(3) 新增 `POST add-session/check` 唯讀預檢端點；(4) 前端 `QuickAddSessionModal` 日期/時間變更後自動預檢，衝突時顯示橘色 banner 並禁用送出。 |
| **禁止回歸** | (a) 勿將 `check` 與 `addSession` 的衝突判斷拆成兩份邏輯——必須共用 `detectAddSessionConflict`。(b) 409 回應必須保留 `message` 欄位（舊前端相容）。(c) 鎖定堂次（有出缺勤或核准評量）不可被覆寫，此為硬規則。 |
| **測試** | `tests/Feature/AddSessionConflictTest.php`（11 案例）：locked by attendance、locked by LR、overwrite unlocked、full capacity、movable session、check endpoint (locked/ok/full)、backward compat message、race condition、check vs add-session error_code 一致性。 |

---

## 2026-04-15 — 側欄 `pending_teachers` 與 `TeachersList`「待審核」不同步

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 主任切到大直分校：側欄 **「老師管理」** 顯示 **橘點（例如 1）**，進入頁面後 **「待審核」** 分頁與摘要卡皆為 **0**，正式老師列表卻看得到該員（**在職**）。現場以為通知壞掉。 |
| **根本原因（雙來源）** | **(1)** `GET /api/v1/notifications/unread-count` 的 `by_type.pending_teachers` 由 **`NotificationController::unreadCount`** 計算： **`User.type=T` 且 `UserCampus.Approved=false`**（分校人員綁定尚未放行）。**(2)** `TeachersList.vue` 的「待審核」只數 **`User.status === 'pending'`**（帳號層級）。兩者**不同欄位**；可能出現 **`status=active` 但某分校 `UserCampus.Approved` 仍為 0**（例如早期只把帳號核准、未寫回 `Approved`，或手動／遷移資料不一致）。**(3)** 前端 **`approveTeacher`** 只送 **`PUT /api/v1/profiles/{id}` + `{ status: 'active' }`**，若後端未一併處理 `UserCampus`，**核准後橘點仍不會消**。 |
| **正確行為** | **產品語意**：側欄若要代表「有分校綁定待放行」，頁面應有對應提示（或與 `status=pending` 對齊）；**技術上**將老師設為 **`active` 時應同步** 該員所有 **`UserCampus.Approved=true`**（`ProfileController::update`）。**診斷**：`User` join `UserCampus` where `type=T` and `Approved=0` and `CampusID=分校`。 |
| **禁止回歸** | **(a)** 勿假設 `pending_teachers` 等於「待審核 tab 人數」；改 badge 或改列表前須先對齊產品定義。**(b)** 勿移除「`status` 改 `active` 時釋出 `UserCampus.Approved`」邏輯（除非改由專用 API 核准且全路徑覆蓋）。**(c)** 解讀現場問題時先查 **DB 是否 `active` + `Approved=0`**，勿只盯前端 tab。 |
| **關聯檔案** | `backend/app/Http/Controllers/NotificationController.php`（`unreadCount`、`pending_teachers`）、`frontend/src/App.vue`（`badgeTypes: pending_teachers`）、`frontend/src/pages/TeachersList.vue`（`pendingCount`、`approveTeacher`）、`backend/app/Http/Controllers/ProfileController.php`（`update`） |
| **資料修復（一次性）** | 若已上線累積 **`User.status=active`** 且 **`UserCampus.Approved=0`**：可 **`UPDATE UserCampus SET Approved=1 WHERE UserID IN (...)`** 限縮在已確認可放行之列；或請主任對該員再觸發一次帶 `active` 的 **`PUT profiles`**（後端已補同步時會清掉）。 |
| **搜尋用關鍵字** | pending_teachers、unread-count、UserCampus Approved、老師管理橘點、待審核 0、楊宸宇（案例：大直 `CampusID=3`） |

---

## 2026-04-15 — 老師註冊 Server Error 與 `directors/pending` 誤列老師

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | **(1)** 老師自助註冊回傳 **Server Error**：`Teacher` 表已有同 `(CampusID, T_Name)` 舊列時，`INSERT` 觸發 unique（MySQL 錯誤鍵名常顯示為 `CampusID`），整筆 `register` transaction 回滾。**(2)** 超級管理員「主任管理」→「待審申請」出現 **老師**（例如預設分校為大直、姓名楊宸宇）：與產品預期不符。 |
| **根本原因** | **(1)** `AuthController::register` 只以 `Teacher.id = User.id` 判斷是否存在，未涵蓋「同校同名不同 id」的歷史 `Teacher` 列。**(2)** `GET /api/v1/directors/pending` 用 **`UserCampus.Approved = false`** 撈人，**未過濾 `User.type`**；老師註冊也會寫入 `Approved=false` 的 `UserCampus`，故與主任申請（`type=U`）混在同一 API。 |
| **正確行為** | **(1)** 寫入 `Teacher` 使用 **`insertOrIgnore`**（或等價：先查 `(CampusID, T_Name)` 再決定 insert/update），避免舊資料阻擋新 `User` 建立。**(2)** `directors/pending` **僅回傳** `User.type` 為 **`U` 或 `A`** 的待審者；**老師待審**由 **`TeachersList`「待審核」**、`User.status=pending`、`ProfileController` 核准路徑處理。 |
| **禁止回歸** | **(a)** 勿把 `directors/pending` 改回「只依 `UserCampus.Approved` 不過濾 type」。**(b)** 勿在 `AuthController::register` 對 `Teacher` 改回裸 `insert()` 而無衝突處理（除非產品改為明確拒絕並回傳可讀訊息）。**(c)** 解讀 `1062 Duplicate entry '3-某某'` 時：**數字為 `Campus.id`**，勿口頭誤稱校名。 |
| **關聯檔案** | `backend/app/Http/Controllers/AuthController.php`（`register`、`Teacher`）、`backend/app/Http/Controllers/DirectorAccountController.php`（`pending`）、`frontend/src/pages/DirectorAccountsPage.vue`、`frontend/src/pages/TeachersList.vue` |
| **測試** | `tests/Feature/ResetDataAndDirectorFlowTest.php` — `test_directors_pending_excludes_pending_teachers` |
| **搜尋用關鍵字** | directors/pending、待審申請、Teacher insertOrIgnore、Duplicate entry CampusID、UserCampus Approved、老師註冊 |

---

## 2026-04-14 — 智慧排課角標誤判（張正樂 4/15）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 行事曆同一格有課程卡，但角標顯示「取消」。典型案例：張正樂 4/15（同課程同時段存在 `cancelled` 與 `scheduled` 兩筆 `ClassSession`）。 |
| **根本原因** | `SmartCalendar.vue` 的 `findSessionRowForCell` 只用日期+起始時間 `find()` 第一筆；當資料排序先遇到舊 `cancelled` 列時，會覆蓋掉同格的有效堂次。另有代課 modal 兩處 `sessions.find(...)` 也可能抓到錯列。 |
| **正確行為** | 同格多筆堂次必須走**統一解析器**：狀態優先序 `attended/completed/late/absent > scheduled > leave/leave_adjusted/excused > cancelled`；同狀態用 `id desc`（較新優先）作 tie-break。`rollCallBadge`、`evalBadge`、tooltip/操作入口與代課 session 選取都要共用同一規則。 |
| **禁止回歸** | **(a)** 勿把 `findSessionRowForCell` 改回單純 `.find()`。**(b)** 勿在 `rollCallBadge` / `evalBadge` / 右鍵操作各自重寫判斷邏輯（必須共用解析器）。**(c)** 勿在代課 modal 用「同日第一筆」直接取 `session_id`。**(d)** 勿讓 `useCourseSessionsDisplay` 優先顯示 `cancelled` 高於 `scheduled`。 |
| **關聯檔案** | `frontend/src/pages/SmartCalendar.vue`（`SESSION_STATUS_PRIORITY`、`pickBestSessionRow`、`findSessionRowForCell`、代課 session_id 解析）、`frontend/src/composables/course-management/useCourseSessionsDisplay.js`（`getSessionDisplayRow`、`getSessionState`）、`backend/tests/Feature/ClassSessionDuplicateStatusTest.php` |
| **測試** | `ClassSessionDuplicateStatusTest`（`cancelled+scheduled`、全 `cancelled`、`leave+scheduled`） |
| **觀測口徑** | 監控 `ClassSession` 同 `StudentClassID + SessionDate + StartTime` 多筆比例；若新增異常集中再進入資料整併評估。 |

---

## 2026-04-14 — 手機請假確認：彈窗層級 + `leave/excused` 契約 + 非同步確認流程

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 老師手機在出缺勤頁把堂次改為「請假」後，點「確認送出」看似無反應。 |
| **根本原因（雙因子）** | **(1) UI 層級**：`AttendancePage` 的確認彈窗 `z-index` 低於全域 `mobile-bottom-nav`，按鈕被底部導覽覆蓋。**(2) API 契約**：前端送 `Status='leave'`，但 `AttendanceController` 驗證僅允許 `excused`（未含 `leave`），導致 422。另有 **(3) 互動流程**：確認按鈕觸發後立即關閉 dialog，錯誤不易被看見。 |
| **正確行為** | **UI**：確認彈窗層級高於手機底部導覽，並有 `safe-area` 底距。**API**：`AttendanceController::store` 與 `batchMark` 同時接受 `leave` 與 `excused`（輸入相容），內部統一為 `leave` 語意。**互動**：確認按鈕必須 `await` API，送出中禁用；成功才關閉，失敗保留彈窗並顯示可讀錯誤。 |
| **禁止回歸** | **(a)** 勿把確認彈窗 `z-index` 改回低於 `.mobile-bottom-nav`。**(b)** 勿在出缺勤 API 驗證移除 `leave`（會讓手機前端再度 422）。**(c)** 勿把確認送出改回「呼叫後立即關閉 dialog」。**(d)** 勿只回傳泛用 `Forbidden`，至少要有可讀權限訊息，避免現場誤判。 |
| **關聯檔案** | `frontend/src/pages/AttendancePage.vue`、`backend/app/Http/Controllers/AttendanceController.php`、`frontend/src/styles.css` |
| **測試** | `tests/Feature/AttendanceLeaveStatusContractTest.php`、`tests/Feature/AttendanceExcusedLeaveCascadeTest.php`、`tests/Feature/AttendanceBatchMarkTest.php` |
| **搜尋用關鍵字** | mobile-bottom-nav、att-confirm-overlay、leave status 422、confirmDialog await、attendance batch leave |

---

## 2026-04-13 — 出缺勤補登（`ended-sessions`）須排除 `StudentClass.Stop=1`

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 課程已在課程管理 **暫停**（`StudentClass.Stop = 1`，`togglePause` 會將未來堂次改為 `cancelled`），但主任在 **出缺勤 → 補登** 仍看到該課堂次（`AttendancePage` 呼叫 **`GET /api/v1/attendance/ended-sessions`**）。 |
| **根本原因** | `endedSessions` 組 `classIds` 時 **`StudentClass::whereIn(StudentID, …)->pluck('ID')` 未加 `where('Stop', 0)`**；且堂次查詢 **`whereNotIn('Status', ['attended','completed','late'])` 仍會納入 `cancelled`**，暫停取消的堂次符合「已結束、無有效簽到」條件而被列出。 |
| **正確行為** | 補登清單只應包含 **進行中契約**（`Stop = 0`）的課程；`Stop = 1` 的課程（手動暫停、結算、結案，`closed_reason` 不影響此處）**一律不列入** `classIds`（主任與老師代課彙總路徑皆同）。 |
| **禁止回歸** | **(a)** 勿在 `endedSessions` 移除 **`where('Stop', 0)`**（或等價篩選），否則暫停課程的 `cancelled` 堂次會再回到補登。**(b)** 新增其他「依課程列可操作堂次」的 API 時，預設應與 **`Stop=0`** 對齊，除非產品明確要查歷史／稽核並另開參數。 |
| **關聯檔案** | `backend/app/Http/Controllers/AttendanceController.php`（`endedSessions`）、`frontend/src/pages/AttendancePage.vue`（`fetchMakeupSessions` → `attendance/ended-sessions`） |
| **測試** | `tests/Feature/MakeupAttendanceEndedSessionsTest.php` — `test_ended_sessions_excludes_paused_student_class` |
| **搜尋用關鍵字** | ended-sessions、補登、MakeupAttendance、StudentClass Stop、togglePause、暫停 |

---

## 2026-04-13 — 調課／請假 cascade 後評量表作廢未恢復

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 營運流程：某堂先走 **請假 cascade**（`CourseLeaveCascadeService::applyLeaveCascade`）→ 該堂 `LearningRecord` 被標 **`VoidedAt`**（作廢）；之後以 **`POST /api/v1/learning-records/reschedule-session`** 把 **同一筆** `ClassSession` 改到別日並標 **已上**（`attended` 等）。結果：`ClassSession` 顯示已上，但列表／補建邏輯只看到「該 `ClassSessionID` 已有一筆 LR」→ **`ensurePastRecords` 舊版對作廢列直接 `continue`** → 評量表在 UI 上永遠不見。另：`ClassSessionController::update` 允許 **`leave → attended`**，但落入「通用只改狀態」分支時 **不會** 恢復作廢 LR。 |
| **正確行為** | **`ensurePastRecords`**：堂次狀態為 **`attended` / `completed` / `late` / `absent`**（已上課口徑，且查詢已排除 `leave` / `leave_adjusted` / `cancelled`）時，若唯一 LR 為作廢列 → **un-void**（清 `VoidedAt` / `VoidedByUserID` / `VoidReason`，`Status=pending`，日期時間與 `ClassSession` 對齊），**不得**再 `INSERT` 第二筆（unique 約束）。**`leave → attended`（及 `late`/`absent`/`completed`）**：在 `ClassSessionController::update` 成功存檔後呼叫 **`restoreVoidedLearningRecord`**，立即恢復同一筆作廢 LR。 |
| **禁止回歸** | **(a)** 勿把 `ensurePastRecords` 改回「只要 `LearningRecord::where(ClassSessionID)->first()` 存在就一律 `continue`」而忽略「堂次已已上、LR 仍作廢」的 self-heal。**(b)** 勿在 `leave → attended` 路徑拿掉 `restoreVoidedLearningRecord`（或等價邏輯）。**(c)** 仍須遵守 2026-04-12「請假與學習評量」：`excludeLeaveSessionPendingReview`、`ensurePastRecords` **不對請假堂補建**；本節僅補「**請假後堂次已不再是請假、且已上**」時的 LR 恢復，與「請假堂不顯示 pending」不衝突。 |
| **關聯檔案** | `LearningRecordController.php`（`ensurePastRecords`）、`ClassSessionController.php`（`update`、`restoreVoidedLearningRecord`）、`CourseLeaveCascadeService.php`（作廢 LR）、`LearningRecordController.php`（`rescheduleSession` 會改 `ClassSession.SessionDate`）、`tests/Feature/LearningRecordApprovalDeductionTest.php` |
| **測試** | `LearningRecordApprovalDeductionTest::test_ensure_past_does_not_recreate_voided_record`（斷言改為：應恢復 1 筆、不新增列、作廢欄位清空） |
| **搜尋用關鍵字** | ensure-past、作廢 LR、un-void、reschedule-session、調課、restoreVoidedLearningRecord、leave→attended |

---

## 2026-04-13 — 請假狀態單一化：`excused` 併入 `leave`

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 系統同時存在 `excused` 與 `leave` 兩種「請假」語意：課程管理多走 `leave`，出缺勤可寫 `excused`，導致狀態機、查詢過濾、UI 按鈕與測試口徑分岔。AI 常在新改動時只修其中一條路徑，造成回歸。 |
| **正確行為** | **唯一一般請假狀態為 `leave`**；補請假維持 `leave_adjusted`。`AttendanceController` 可為相容性接受 `excused` 輸入，但必須立即映射成 `leave` 後續處理。`ClassSession` / `StudentSingIn` 新資料不可再寫入 `excused`。 |
| **禁止回歸** | **(a)** 勿在 `ClassSessionController::STATUS_TRANSITIONS` 重新加入 `excused`。**(b)** 勿在 `ScheduleController` / `AttendanceController` 新增或恢復 `StudentSingIn.Status='excused'` 寫入。**(c)** 勿在課程管理單堂操作加回「公假」按鈕。**(d)** 勿把 `leave` 顯示文案改成「離班」（本域語意應為「請假」）。 |
| **關聯檔案** | `backend/app/Http/Controllers/ClassSessionController.php`、`backend/app/Http/Controllers/AttendanceController.php`、`backend/app/Http/Controllers/ScheduleController.php`、`backend/app/Models/LearningRecord.php`、`frontend/src/components/course-management/SessionEditModal.vue`、`frontend/src/composables/course-management/useSessionEditFlow.js`、`frontend/src/pages/AttendancePage.vue`、`backend/database/migrations/2026_04_13_400000_merge_excused_into_leave.php` |
| **測試** | `tests/Feature/AttendanceExcusedLeaveCascadeTest.php`、`tests/Feature/LearningRecordApprovalDeductionTest.php` |

---

## 2026-04-13 — 增加購買堂數後第 N+1 堂起未自動產生

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 主任在課程編輯介面將「購買堂數」從 8 改成 10 後，`StudentClass.SessionCount` 正確更新為 10，但課程列表底下仍只顯示第 1～8 堂，沒有出現第 9、10 堂。 |
| **根本原因** | `StudentClassController::update` 在 `SessionCount` 異動時只呼叫了 `cancelExcessScheduledSessions`（縮減邏輯），**完全沒有處理增加的情況**。`maybeRebuildSessionsAfterUpdate` 僅在排課欄位（week/time）或 `StartDate` 改變時才觸發重建，單純改 `SessionCount` 不會進入任何補建分支。 |
| **正確行為** | 若 `SessionCount` 增加且 `ScheduleMode = 'count'`，必須從現有最後一堂的隔日起，按固定星期繼續往後補建缺少的 `ClassSession` 記錄，並同步 `UsedSessions` / `RemainingSessions`（`SessionDeductionService::syncCounters`）。 |
| **實作位置** | `StudentClassController::update`（在 `cancelExcessScheduledSessions` 之後呼叫 `extendSessionsIfNeeded`）+ 新增私有方法 `extendSessionsIfNeeded`。 |

### 禁止回歸

- **勿移除或繞過 `extendSessionsIfNeeded` 呼叫**：`cancelExcessScheduledSessions` 之後必須緊接呼叫，否則增堂時仍會靜默失效。
- **勿在 `extendSessionsIfNeeded` 中改用「從 `StartDate` 重建全部堂次」**：若前面的堂次已有出缺勤 / 評量記錄，整刪重建會連帶清掉歷史資料；應**只補差額**（`newCount - currentCount` 筆），從最後一堂隔日開始排。
- **`currentCount` 必須排除 `cancelled` 與 `leave`／`leave_adjusted`**（請假不佔用購買額度，與 `cancelExcessScheduledSessions` 口徑一致）；勿只算 `scheduled`，否則補建數量偏多。
- **補建堂次若日期已過去**，狀態應設 `completed`（非 `scheduled`），並自動建立 `Status=pending` 的 `LearningRecord`，與新建課程時的行為保持一致。

### 相關檔案

| 檔案 | 角色 |
|------|------|
| `backend/app/Http/Controllers/StudentClassController.php` | `update`（新增 `extendSessionsIfNeeded` 呼叫）、`extendSessionsIfNeeded`（新增私有方法）、`cancelExcessScheduledSessions` |
| `backend/app/Services/SessionDeductionService.php` | `syncCounters`（補建後重新計算 RemainingSessions / UsedSessions） |
| `frontend/src/components/CourseEditForm.vue` | 送出 `sessions_purchased` → 後端 `mapFrontendPayload` 映射至 `SessionCount` |

---

## 2026-04-13 — 當月學收月報（取代帳單列表）

| 項目 | 說明 |
|------|------|
| **背景** | 帳單列表（`BillingList.vue`）綁 `Invoice` 表，多數分校從未在系統開帳單故列表空。產品決定以「當月學收」取代帳單列表，直接顯示各學生每門課的月堂數 × 費率試算。 |
| **架構** | 後端 `FinanceController::branchMonthlyTuition`（`GET /api/v1/finance/branch-monthly-tuition`）；前端 `TuitionReportPage.vue`（`active = 'tuition-report'`）。 |
| **堂數口徑** | `ClassSession` 在指定月 + `Status in ('attended','completed','late')`，不含 `absent`/`excused`/`leave`/`cancelled`。 |
| **費率** | `StudentClass.Rate`；null/0 fallback `Charge / SessionCount`。 |
| **分校隔離** | 沿用 `getCampusIds()`（`auth_campus_ids` + `branch_id`），與其他 finance API 一致。 |

### 禁止回歸

- **勿把「帳單列表」（`active = 'billing'`、`BillingList.vue`）加回側欄**——已被產品決定由「當月學收」取代。`BillingList.vue` 檔案與 `BillingController` Invoice API 保留在程式中，但不掛載。
- **勿修改本 API 使其寫入** `StudentClass` / `ClassSession` / `LearningRecord` 等表——此 API 為**純讀取**報表。
- **堂數口徑勿擅自改**（例如改成只算 `attended` 或加入 `absent`）——除非產品明確要求。
- **費率 fallback 勿移除**——部分舊課程 `Rate` 為 null，仍需 `Charge / SessionCount` 作為備援。

### 相關檔案

| 檔案 | 角色 |
|------|------|
| `backend/app/Http/Controllers/FinanceController.php` | `branchMonthlyTuition` 方法 + `resolveRate` helper |
| `backend/routes/api.php` | director 區塊 `finance/branch-monthly-tuition` 路由 |
| `frontend/src/pages/TuitionReportPage.vue` | 當月學收頁面 |
| `frontend/src/App.vue` | 側欄 `tuition-report` 項目 + `v-if` 掛載 |
| `frontend/src/pages/BillingList.vue` | 保留但不再掛載（未來可重新啟用正式帳務） |

---

## 2026-04-13 — 課程 Stop 語意：`closed_reason` 區分暫停 vs 結算

| 項目 | 說明 |
|------|------|
| **背景** | `StudentClass.Stop = 1` 過去同時代表「手動暫停」和「堂數用完加購結算」。加購結算的課程顯示黃色大 banner「課程暫停中」，使用者反應視覺不適切。 |
| **方案** | 新增 `closed_reason` (nullable string 20) 欄位：`null` = 手動暫停（黃色 banner）、`'settled'` = 堂數用完結算（灰色小標「已結算」）、`'completed'` = 主任手動結案（灰色小標「已結案」）。**`Stop = 1` 語意不變**——所有 `where('Stop', 0)` / `where('Stop', 1)` 查詢不受影響。 |
| **後端寫入點** | `purchaseBatch` → `settled`；`togglePause(action=pause, reason=completed)` → `completed`；`togglePause(action=pause)` → `null`；`togglePause(action=resume)` → 清為 `null`。 |
| **前端判斷** | `c.closed_reason === 'settled'` or `'completed'` → `.course-settled` class + `tag-settled`；`c.status === 'inactive' && !c.closed_reason` → 現行 `.course-paused` + `tag-paused`。 |
| **禁止回歸** | **(a)** 勿移除 `closed_reason` 寫入——加購結算必須標 `settled`，否則使用者看到「暫停」黃色 banner。**(b)** 勿將 `closed_reason` 用來決定是否從 `AlertController::tuition` 列入（alert 只看 `Stop`）。**(c)** 恢復課程（resume）務必清 `closed_reason = null`。**(d)** 不要在 `where('Stop', 0)` 之外額外檢查 `closed_reason`，以免排除已結算課程的已有資料查詢被意外修改。 |

---

## 2026-04-13 — 催繳名單、tuition-slip 與 PaymentSlipModal

| 項目 | 說明 |
|------|------|
| **模組目的** | 主任在專頁檢視與 **`GET /api/v1/alerts/tuition`** 相同規則的「待聯繫／待繳」課程；**僅未繳費（`StudentClass.Paid != 1`）** 可產出傳家長用的圖片；**已繳**列可出現在名單（續課／月結將屆）但**不得**出「繳費單」按鈕或呼叫出圖 API。 |
| **名單資料源** | **`TuitionCollectionPage.vue`** 只應呼叫 **`GET /api/v1/alerts/tuition?branch_id=…`**，與 **`DirectorDashboard.vue`** 一致，避免兩處規則分叉。 |
| **兩種出圖路徑** | **(1)** 有帳單：**`GET /api/v1/invoices/{id}/slip-data`** + **`PaymentSlipModal` 的 `invoiceId`**（正式「繳費通知單」、含帳單編號）。**(2)** 無帳單：**`GET /api/v1/alerts/tuition-slip/{studentClassId}`** + **`studentClassId`**（**催繳通知**語意，抬頭／樣式與 Invoice 版區隔，**無**帳單編號）。 |
| **後端強制** | **`tuitionSlipData`**：`Paid === 1` → **422**；學生 **`CampusID`** 須在 **`auth_campus_ids`**（非 super_admin）；成功寫 **`[TuitionSlip] generated`** log。**`BillingController::slipData`** 成功寫 **`[InvoiceSlip] generated`**。 |
| **禁止回歸** | **(a)** 勿在催繳名單對 **`paid === true`** 顯示出圖按鈕或略過後端 Paid 檢查。**(b)** 勿把 **`tuition-slip`** 回傳格式偽裝成帳單（含假 `invoice_id`）。**(c)** 勿移除 **`tuition-slip`** 的校區檢查（避免以 ID 枚舉他校）。**(d)** 改 **`AlertController::tuition`** 的列入條件前必讀 **`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`** 並經產品同意。 |
| **關聯檔案** | `backend/app/Http/Controllers/AlertController.php`（`tuition`、`tuitionSlipData`）、`backend/routes/api.php`、`backend/app/Http/Controllers/BillingController.php`（`slipData`）、`frontend/src/pages/TuitionCollectionPage.vue`、`frontend/src/components/PaymentSlipModal.vue`、`frontend/src/pages/BillingList.vue`、`frontend/src/App.vue`（`tuition-collect`） |
| **搜尋用關鍵字** | tuition-collect、TuitionCollectionPage、tuition-slip、TuitionSlip、PaymentSlipModal、InvoiceSlip、催繳名單 |

### PaymentSlipModal 繪圖時序坑

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `PaymentSlipModal` 的 `watch(show)` 中，`slip.value = …` 後立即 `await nextTick()` + `drawSlip(canvasRef)`，但此時 `loading` 仍為 `true`（`finally` 未執行），模板的 `v-else-if="slip"` 不渲染 canvas → `canvasRef.value` 為 null → 預覽與下載皆空白圖。 |
| **正確行為** | 成功取得資料後：先 `slip.value = …`，再 **`loading.value = false`**，然後 `await nextTick()`，最後 `drawSlip`。`catch` 分支同理須自行設 `loading = false`，不依賴 `finally`。 |
| **禁止回歸** | **(a)** 勿把 `loading = false` 移回 `finally`（會讓 canvas 在 draw 後才掛載）。**(b)** 勿把 `drawSlip` 提前到 `loading = false` 之前。 |

---

## 2026-04-13 — 老師管理須含授課學段（subject_level_scopes）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 老師的「授課學段」（國小／國中／高中，依科目勾選）存在 **`teacher_subject_levels`**，由 **`TeacherScopeService`** 與 **`PUT /api/v1/me`**（`AuthController::updateMe`）維護；但 **`GET /api/v1/teachers`**／**`GET /api/v1/profiles`** 的列表與 **`PUT /api/v1/profiles/{id}`**（主任老師管理）長期**未帶出、未接受、未寫入** `subject_level_scopes`，且 **`TeachersList.vue`** 表單與列表**沒有學段 UI**，導致主任在「老師管理」看不到、改不到學段，與老師自行在帳號中心設定的資料脫節；排課／入班學段提示也失去可視性。 |
| **正確行為** | **`ProfileController::index`**：`buildTeacherExtras`（或等價路徑）須合併 **`TeacherScopeService::getScopesForTeachers`**，每位老師回傳 **`subject_level_scopes`**（`{ subject_id, level }[]`，`level` ∈ `elementary`/`junior`/`high`）。**`ProfileController::update`**：當請求帶有 **`subject_level_scopes`** 時須 **`TeacherScopeService::replaceScopes`**（與 `me` 一致）；**`getTeacherExtra`**／**`update` 回傳**須含 **`subject_level_scopes`**。**`ProfileController::store`**（主任新增老師）：可選接受 **`subject_level_scopes`** 並寫入。**`TeachersList.vue`**：編輯／新增 modal 須有與 **`TeacherProfilePage.vue`** 相同語意的**科目×學段**矩陣；列表（卡片／表格）須顯示學段摘要；儲存時 **`PUT`/`POST` `profiles`** 須送出 **`subject_level_scopes`**。 |
| **禁止回歸** | **(a)** 勿從老師列表 API 再移除 **`subject_level_scopes`**。**(b)** 勿讓主任 **`PUT /api/v1/profiles/{id}`** 只更新科目而不處理學段（若前端送學段，後端必須持久化）。**(c)** 勿在 **`TeachersList.vue`** 拿掉學段表單或僅顯示科目不顯示學段（與 **`TeacherScopeService`**／**`docs/AI_REGRESSION_LESSONS.md`（2026-04-11 學段提示）** 一條龍）。 |
| **關聯檔案** | `backend/app/Http/Controllers/ProfileController.php`（`index` 的 `buildTeacherExtras`、`getTeacherExtra`、`update`、`store`）、`backend/app/Services/TeacherScopeService.php`、`frontend/src/pages/TeachersList.vue`、`frontend/src/pages/TeacherProfilePage.vue`、`backend/app/Http/Controllers/AuthController.php`（`updateMe` 對照） |
| **搜尋用關鍵字** | subject_level_scopes、teacher_subject_levels、授課學段、TeachersList、profiles |

---

## 2026-04-12 — 專注模式與 modal z-index / 契約時段不得被覆寫

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | **(1)** `CourseManagement.vue` 的「專注模式」（`.focus-fullscreen-mode`）設 `z-index: 1000`，全域 `.modal-overlay`（`styles.css`）僅 `z-index: 200`，導致編輯／重建／加購等 modal 被蓋在專注層底下，使用者看不到。**(2)** 專注層 `overflow-y: auto` + 內層 `.group-table-wrap { max-height: 56vh }` 形成巢狀捲動，視窗利用率差。**(3)** `StudentClassController::index` 的 `$sessionSlotsByClassId`（ClassSession 推導）會**覆寫** `$class->day_time_slots`，導致列表與編輯表單的「固定排課日」不反映契約。**(4)** 前端 `editCourse` 從 `classSessionsByCourse` 合併推斷時段至 `existingSlots`，同樣污染固定排課。**(5)** `reconcileWeekTimeFieldsFromSessions` 在「沒有未來 scheduled 堂次」時 fallback 到 **completed/attended** 全歷史，把**週二代課**等一次性堂次的星期寫回 `StudentClass.week*`，使用者編輯移除週二後儲存仍被蓋回。 |
| **正確行為** | **(1)** `.modal-overlay` z-index 設為 1100，始終高於專注層。**(2)** `.focus-fullscreen-mode` 下子層 `.group-table-wrap`、`.table-wrap` 的 `max-height` 改為 `none`，避免雙重捲軸。**(3)** `index()` 的 `$sessionSlotsByClassId` 不覆寫 `day_time_slots`，**僅**用「今日起 `scheduled`」堂次組槽與契約比對 `schedule_drift`（**勿**併入 completed/attended，否則已結案課程會因週一代課歷史誤報偏移）。**(4)** `editCourse` 與 `formatDayTimeSlotLines` 只使用契約資料，不從堂次合併。**(5)** `reconcileWeekTimeFieldsFromSessions` **僅**依 `Status=scheduled` 且 `SessionDate >= today` 的堂次調整主檔；若無未來預排則直接 return，**不得**用歷史堂次覆寫契約。 |
| **禁止回歸** | **(a)** 勿把 `.modal-overlay` z-index 降回 200 或低於專注層 1000。**(b)** 勿在 `index()` 中讓 `$sessionSlotsByClassId` 覆寫 `$class->day_time_slots`（僅供 drift 偵測）。**(b2)** 勿把 `index()` 組 `$sessionSlotsByClassId` 時改回合併 completed/attended fallback（僅能自「今日起 scheduled」）。**(c)** 勿在前端 `editCourse` 或 `formatDayTimeSlotLines` 中用堂次資料覆寫契約 slots。**(d)** 勿把 `reconcileWeekTimeFieldsFromSessions` 改回「無未來堂時用 completed/attended 重建 week/time」。 |
| **關聯檔案** | `frontend/src/styles.css`（`.modal-overlay` z-index）、`frontend/src/pages/CourseManagement.vue`（`.focus-fullscreen-mode`、`editCourse`、`formatDayTimeSlotLines`、`schedule-drift-badge`）、`backend/app/Http/Controllers/StudentClassController.php`（`index()` `$sessionSlotsByClassId` → `schedule_drift`；`reconcileWeekTimeFieldsFromSessions`） |
| **測試** | `SameDayMultiSlotTest::test_index_day_time_slots_reflect_contract_not_sessions`、`SameDayMultiSlotTest::test_index_schedule_drift_detected_when_sessions_differ`、`StudentClassUpdateScheduleReconcileTest::test_update_removed_weekday_not_restored_from_history_when_no_future_scheduled`、`StudentClassUpdateScheduleReconcileTest::test_memo_only_update_keeps_contract_when_no_future_scheduled` |
| **搜尋用關鍵字** | 專注模式、z-index、modal、focus-fullscreen、契約、schedule_drift、day_time_slots 覆寫 |

---

## 2026-04-12 — 科目顯示、排課彈性、待補點名、開課日重建

### A. 科目名稱顯示

| 項目 | 說明 |
|------|------|
| **問題** | `LearningRecord.Subject` 歷史資料含英文；`StudentClassController` 將中文名反向 map 成英文 key；前端課表、評量列表、主任待審核區直接顯示英文。 |
| **正確做法** | `hydrateRecordForResponse()` 與 `index()` 批次版本透過 `SubjectID → Subject.Subject_Name` 解析，回傳 `student_class_label` 中文名。課表用 `ev.subjectName`（`sc.subject_name`）顯示，badge 用 `record.student_class_label`。科目下拉呼叫 `GET /api/v1/subjects`，不可寫死任何固定清單。 |
| **禁止回歸** | **(a)** 勿把 `student_class_label` 改回 `studentClass->Subject`（欄位不存在）。**(b)** 勿把科目下拉改回寫死陣列。**(c)** 勿用 `record.Subject` 直接顯示（可能是英文）。 |
| **關聯檔案** | `LearningRecordController.php`（`hydrateRecordForResponse`、`index`）、`LearningRecordsPage.vue`（`fetchSubjects`、`buildEvents`、badge）、`DirectorDashboard.vue`（待審核 tag）、`TeacherHomePage.vue`（`ev.subject` → 已更正） |

### B. 待補點名不應顯示已核准堂次

| 項目 | 說明 |
|------|------|
| **問題** | `ApprovalSessionSyncService` 核准評量時設 `ClassSession.Status = attended`，但不建 `StudentSignIn`；`endedSessions()` 只看 `SignIn`，導致已核准堂次仍列入待補點名。 |
| **修正** | `AttendanceController::endedSessions()` 加 `->whereNotIn('Status', ['attended', 'completed', 'late'])`。 |
| **禁止回歸** | 勿移除此 `whereNotIn` 條件。 |
| **關聯檔案** | `AttendanceController.php`（`endedSessions`）、`ApprovalSessionSyncService.php` |

### C. 開課日編輯後堂次不同步

| 項目 | 說明 |
|------|------|
| **問題** | 課程有歷史記錄時，修改開課日後系統靜默不重建堂次，前端僅顯示小字提示，造成主檔與堂次資料不一致。 |
| **正確行為** | `hasImmutableSessionHistory()` 排除已作廢（`VoidedAt IS NOT NULL`）的 `StudentSignIn` 與 `LearningRecord`。開課日有變且有歷史時走「安全部分重建」（`reason: partial_rebuild`）：鎖定已點名／已核准堂次，重排未鎖定未來堂次。主任可從操作選單「重建未上堂次」強制觸發（`force_partial_rebuild: true`）。 |
| **退回未上解法** | `attended → scheduled` 會 void SignIn＋LR，再編輯開課日即可觸發全量重建，無需刪除重建。 |
| **禁止回歸** | **(a)** 勿把 `hasImmutableSessionHistory()` 的 StudentSignIn 查詢改回不過濾 VoidedAt。**(b)** 勿移除 `partial_rebuild` 路徑。**(c)** 勿把 `CourseManagement.vue` 的 `@click.self` 加回 modal-overlay（會讓使用者誤觸關閉）。 |
| **關聯檔案** | `StudentClassController.php`（`hasImmutableSessionHistory`、`maybeRebuildSessionsAfterUpdate`、`update` 的 `force_partial_rebuild`）、`CourseManagement.vue`（`originalFirstClassDate`、`openRebuildModal`、`submitForceRebuild`、`.rebuild-modal`） |

### D. 手動排課日期不限固定星期

| 項目 | 說明 |
|------|------|
| **問題** | `UniversalClassScheduler` 前端驗證與後端 `EnrollmentService` 均要求手動日期必須在固定上課星期，導致補登歷史課程被阻擋。 |
| **正確行為** | **過去日期**（`< today`）不限固定星期，可自由選擇任意日期；**今天（含）之後**的日期仍須符合固定上課星期。`sessionCountForYmd` 對不在固定星期的手動日回傳 1（不回傳 0）；送出時找不到 slot 改用全域 `start_time` fallback。 |
| **禁止回歸** | **(a)** 勿把 `onDateClick` 的 `cell.ymd >= todayYmd` 條件移除（會讓未來也不限星期）。**(b)** 勿把 `sessionCountForYmd` 改回對非固定星期回傳 0。**(c)** 勿把後端 `EnrollmentService` 的 `$today` 跳過邏輯移除。 |
| **關聯檔案** | `UniversalClassScheduler.vue`（`onDateClick`、`sessionCountForYmd`、送出邏輯、hint text）、`EnrollmentService.php`（星期驗證迴圈） |

---

## 2026-04-12 — 老師教學工作台（TeacherHome）

| 項目 | 說明 |
|------|------|
| **產品行為** | 老師（`role=teacher`）登入後預設 **`active=teacher-home`**（**教學工作台**）：今日待辦 CTA、**本週課表為所屬全部分校合併**（每筆標分校）、科目數／行事曆捷徑。側欄「出缺勤」可顯示**今日待點名數紅點**（不依主任 `notifications/unread-count`）。 |
| **曾易犯的設計錯誤** | **(1)** 週課表只查 `currentBranch`，與「跨分校一覽」規格矛盾。**(2)** 從他校堂次開評量卻不切 `localStorage.app_branch`／`currentBranch`，導致評量 API 仍打錯校。**(3)** 改 `refreshUnreadNotifications` 時刪掉或漏呼叫 **`mergeTeacherAttendanceBadge`**，老師側欄紅點永遠沒有。**(4)** 把老師預設頁改回 `learning` 卻未同步文件／導覽，現場又忘記點名。 |
| **正確行為** | **週課表**：對 `teacherBranches`（或 `App.vue` 傳入的 `teacherBranchIds`）每個 `branch_id` 並行 `fetchClassSessions`，合併去重、排序；單校失敗不白屏。**跨校填評量**：寫入 `app_branch` 再 `setActivePage('learning')`，必要時帶 `learningTargetRecordId`。**紅點**：`refreshUnreadNotifications` 結尾須保留 `await mergeTeacherAttendanceBadge()`（老師專用，與主任 `badgeByType` 來源分離）。**上線**：凡動 `frontend/src/**`，整輪 **`npm run deploy`**。 |
| **禁止回歸** | **(a)** 勿把老師預設 `active` 改回僅 `learning` 而未經產品確認。**(b)** 勿移除工作台跨校合併或改回僅單校卻不更新 UI 文案。**(c)** 勿讓老師 badge 依賴主任專用 unread API（權限／格式不同）。**(d)** 勿只複製 `assets` 或讓 `index.html` 與 chunk 脫鉤。 |
| **關聯檔案** | `frontend/src/pages/TeacherHomePage.vue`、`frontend/src/App.vue`（`teacher-home` 掛載、`sidebarNavGroups`／`mobileTabItems`、`mergeTeacherAttendanceBadge`、`handleLoginSuccess`／`fetchProfile` 預設頁）、`frontend/src/lib/classSessionsApi.js`（`fetchClassSessions`）、`frontend/src/pages/LearningRecordsPage.vue`（老師 RWD 與本頁「本週」widget 若與工作台不一致須標示或對齊） |
| **文件** | `docs/CHANGELOG.md` **2026-04-12 (G)**、`docs/FAQ.md`（老師登入預設）、`docs/OPERATIONS_RUNBOOK.md` §A 第 6 點 |
| **搜尋用關鍵字** | `teacher-home`、`TeacherHomePage`、`mergeTeacherAttendanceBadge`、`teacherBranchIds` |

---

## 2026-04-12 — 固定排課契約與堂次一致（手動日、列表幽靈時段、改星期未同步）

**營運情境（濃縮）**：(1) 固定星期只有週六日，手動卻點到週三仍建成堂次。(2) 主檔已只剩週六，列表仍多一條週日（孤兒預排覆寫顯示）。(3) 改成僅週日後，摘要變週日但底下未來堂仍停在週六。

### 技術摘要表

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | **(A)** `UniversalClassScheduler.vue` 組 `session_plan` 時，若使用者點選的日曆日之星期幾**未**在 `day_time_slots`／`days_of_week` 內，`getSlotIndicesForDay` 回傳空陣列，程式卻走 **fallback**：仍用該**錯誤日曆日** + 全域 `start_time` 送後端 → 出現「固定排課寫週六日、第 1 堂卻在週三」等與現場固定排課矛盾的堂次。**(B)** 學生課程列表 `GET /api/v1/student-classes` 曾用「未來 `ClassSession` 推導的星期」**整段覆寫** `day_time_slots`，若主檔已改為僅週六、但庫裡仍留週日預排，畫面會多顯示一個週日時段。 |
| **正確行為** | **(A)** 手動勾選的日期必須落在已勾選的固定上課星期；`EnrollmentService::store` 驗證堂次日曆星期。**(B)** `StudentClassController::index`：課程主檔 `week*`／`time*` 組出的時段為**契約**；用預排堂次覆寫顯示時，須**過濾掉契約中沒有的星期幾**，避免孤兒預排多出幽靈時段。**(C)** `PUT /api/v1/student-classes` 有歷史堂次時，`syncFutureScheduledSessionTimes` 僅「同星期改時間」不足；若未來 `scheduled` 堂仍落在**已從契約移除的星期**（例：改為僅週日後仍留週六預排），須依 `buildSessionsForCount` 節奏**重算 SessionDate** 並同步未作廢 `LearningRecord` 的日期／時間。 |
| **禁止回歸** | **(a)** 勿恢復 `session_plan` 的錯誤日曆日 fallback。**(b)** 勿移除 `EnrollmentService` 星期驗證。**(c)** 勿把 `index` 的 session 覆寫改回「不經契約星期過濾」整包取代。**(d)** 勿把 `syncFutureScheduledSessionTimes` 改回「只改 Start/End、遇到契約外星期就略過」而讓未來堂永遠卡在舊星期。 |
| **關聯檔案** | **前端**：`frontend/src/components/UniversalClassScheduler.vue`（`onDateClick`、`sessionCountForWeekday`、`submit`）。**後端**：`backend/app/Services/EnrollmentService.php`（`store` 堂次日曆星期驗證）；`backend/app/Http/Controllers/StudentClassController.php`（`index` 契約過濾 session 覆寫；`syncFutureScheduledSessionTimes`／`remapFutureScheduledSessionsToContract`／`buildSlotsByWeekdayMap`／`snapDateToContractWeekday`） |
| **測試** | `ClassSessionBatchApiTest::test_batch_rejects_session_plan_on_weekday_outside_fixed_schedule`、`SameDayMultiSlotTest::test_index_day_time_slots_ignore_future_sessions_outside_contract_weekdays`、`StudentClassUpdateScheduleReconcileTest::test_update_weekday_remaps_future_sessions_from_saturday_to_sunday` |
| **搜尋用關鍵字** | 幽靈星期、契約、`session_plan`、週三誤排、`day_time_slots`、`syncFutureScheduledSessionTimes` |

---

## 2026-04-12 — 調課失敗後孤兒 `rescheduled` 紀錄導致課堂消失

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 智慧排課的調課流程分**兩步**寫入 `schedules`：(1) 把原堂次 insert 為 `status=rescheduled`（標記原日消失）；(2) insert 新堂次 `status=scheduled`（新日出現）。前端 `supabase.js` 的 `insert([row])` 會將 body 序列化為 **JSON 陣列** `[{...}]`，而 `ScheduleController::store` 的 `$request->validate()` 預期**根層物件**，導致第二步 422 失敗。結果：原堂被標 `rescheduled`（行事曆不顯示），新堂未建立 → **課堂憑空消失**。此 bug 造成吳艾潼 4/12 理化課兩度消失。 |
| **修復** | **(A) 前端**：`supabase.js` POST 分支在序列化前，若 `_body` 為「僅含一筆 plain object 的陣列」，unwrap 為該物件再 `JSON.stringify`。**(B) 後端防禦**：`ScheduleController::store` 開頭偵測若 `$request->all()` 根是 `[0 => [...]]` 單元素數值陣列，先 `$request->replace($all[0])` 再 validate。兩層保險確保新舊前端皆可正確寫入。 |
| **禁止回歸** | **(a)** 勿把 `supabase.js` POST 的 body 改回直接 `JSON.stringify(this._body)` 不做 unwrap。**(b)** 勿移除 `ScheduleController::store` 開頭的陣列 unwrap 防禦。**(c)** 調課前端流程若第一步（寫 `rescheduled`）成功但第二步（寫 `scheduled`）失敗，應回滾第一步或提示使用者；目前靠雙層 unwrap 避免第二步失敗，但未來若重構調課流程須注意此原子性問題。 |
| **關聯檔案** | `frontend/src/supabase.js`（POST unwrap）、`backend/app/Http/Controllers/ScheduleController.php`（store 防禦性 unwrap）、`frontend/src/pages/SmartCalendar.vue`（`submitReschedule` 兩步寫入）、`frontend/src/composables/course-management/useRescheduleAndMakeup.js`（同路徑） |

---

## 2026-04-11 — 「手動補登日期」汙染課程時段顯示（三處同性質缺口）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤（三層）** | **(1) `reconcileWeekTimeFieldsFromSessions()`**：編輯課程固定時間後，DB 欄位先被正確存入，卻馬上被 `reconcileWeekTimeFieldsFromSessions` 用**舊 `completed/attended` 堂次（手動補登的過去日）**覆寫回去，導致課程卡片時段永遠不更新。**(2) `StudentClassController::index()` → `$sessionSlotsByClassId`**：課程列表 API 撈 `['scheduled','completed','attended']` 全部堂次建立 `day_time_slots`，再用它蓋掉 DB 的 `week/time` 欄位；使用者只在「固定上課星期」設了週日，但手動補登了週五、週六兩天，結果課程管理顯示三個時段（週五、週六、週日），多出兩個無中生有。**(3) `ensurePastRecords()` EndTime 條件**：評量表的自動建立條件用 `EndTime`（下課時間）而非 `StartTime`（上課時間），導致老師在課程開始後、下課前開不了評量表填寫。 |
| **正確行為（2026-04-12 修訂）** | **原則：課程管理列表與編輯的「時段」以 `StudentClass` 契約（DB `week`/`time`/`week1..`/`time1..`）為唯一準，不由 `ClassSession` 覆寫。** `index()` 的 `$sessionSlotsByClassId` 仍查未來 scheduled 堂，但**僅用於計算 `schedule_drift` boolean**（前端顯示「堂次偏移」警告），不再覆寫 `day_time_slots`。前端 `editCourse` 與 `formatDayTimeSlotLines` 同樣不再從堂次合併推斷。智慧排課（`SmartCalendar`）每格仍優先該日 `ClassSession`（見下方「智慧排課」節），語意不同、不衝突。**(1)** `reconcileWeekTimeFieldsFromSessions`：先查 `Status='scheduled' AND SessionDate >= today`；若不空只用這些重建 week/time；否則 fallback。**(3)** `ensurePastRecords`：條件改用 `StartTime`。 |
| **禁止回歸** | **(a)** 勿把 `reconcileWeekTimeFieldsFromSessions` 的 Session 查詢改回只查全部狀態。**(b)** 勿把 `index()` 的 `$sessionSlotsByClassId` 改回**覆寫** `day_time_slots`（應僅用於 `schedule_drift` 偵測）。**(b2)** 勿在前端 `editCourse` 或 `formatDayTimeSlotLines` 中用堂次推斷覆寫契約 slots。**(c)** 勿把 `ensurePastRecords` 改回 `EndTime`。**(d)** 出缺勤 `index()` 必須保留 `->whereNull('si.VoidedAt')`。 |
| **出缺勤補請假（retro-leave）** | 出缺勤頁面對**已到班（present/late）**記錄改請假，前端須呼叫 `POST /api/v1/schedules/retro-leave`（帶 `student_course_id` + `session_date`），**不可**繼續呼叫 `leave-by-session`（後者對 attended 狀態直接拒絕）。`retro-leave` 會作廢 StudentSignIn + LearningRecord 並沖回堂數。 |
| **關聯檔案** | `StudentClassController.php`（`reconcileWeekTimeFieldsFromSessions`、`index()` 的 `$sessionSlotsByClassId`）、`LearningRecordController.php`（`ensurePastRecords`）、`AttendanceController.php`（`index()` `whereNull('si.VoidedAt')`）、`AttendancePage.vue`（retro-leave 分支） |

---

## 2026-04-12 — 請假與學習評量：作廢列、孤兒 pending、`ensure-past`（改動前必讀）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤（兩層）** | **(1)** 請假 cascade 只把評量標 `VoidedAt`（`Status` 仍可能是 `pending`），但 **`GET /api/v1/learning-records` 未過濾作廢列** → 主任總覽「待審核評量」仍顯示已請假堂次。**(2)** 僅補 `->active()` **仍不足**：`ensurePastRecords` 曾在 `ClassSession` 已是 **`leave`/`excused`/`leave_adjusted`** 時仍建立 **未作廢** 的 `pending` 評量（`VoidedAt` 為 null）→ 畫面上與「請假後不應有評量」矛盾；DB 上 `learningrecord_classsessionid_unique` 也禁止在已有作廢列時再 insert。**(3)** 出缺勤 **`excused` 且無 `ClassSessionID`** 時不跑 cascade，堂次可能長期停在 `excused`，與「請假＝leave」路徑不一致（見計畫／`AttendanceController::store`）。 |
| **正確行為** | **列表與待辦**：`LearningRecord::active()`（排除 `VoidedAt`）**加上** `LearningRecord::excludeLeaveSessionPendingReview()`：對 `pending`/`changes_requested`，若關聯 **`ClassSession.Status` ∈ `leave`,`excused`,`leave_adjusted`** 則不列出（與 `ApprovalSessionSyncService` 不扣堂語意對齊）。**`ensurePastRecords`**：上述狀態的堂次**不補建**評量；若該 `ClassSessionID` **已有任一筆**評量（含作廢），**不得**再 `create`（避免 unique 衝突；作廢列只做 sync 或略過）。**批次核准**：與 index 同一套篩選，避免一鍵核准誤核請假堂。**通知**：`NotificationSyncService::buildLearningNotifications` 同樣套用 `excludeLeaveSessionPendingReview`。**既有孤兒**：營運庫內曾出現「`pending` + `VoidedAt` null + `cs.Status=leave`」者應 **void** 或依產品決策刪除（一次性修復可寫 migration 或 runbook SQL）。 |
| **禁止回歸** | **(a)** 勿只依 `VoidedAt` 過濾待審列表而忽略「堂次已請假但評量未作廢」的孤兒。**(b)** 勿把 `ensurePastRecords` 的 `ClassSession` 查詢改回只排除 `cancelled`。**(c)** 勿在 `where('ClassSessionID')->first()` 找「是否已有評量」時忽略作廢列與 unique 約束（應區分：有 active → 不重建；僅 voided → 不 insert）。**(d)** 修改請假／評量／財務讀取路徑時，勿移除 `FinanceController`／`ParentPortal` 等處的 `->active()`。 |
| **營運／稽核 SQL（建議上線後偶跑）** | 孤兒 A：`LearningRecord lr JOIN ClassSession cs ON cs.id=lr.ClassSessionID WHERE lr.Status IN ('pending','changes_requested') AND lr.VoidedAt IS NULL AND cs.Status IN ('leave','excused','leave_adjusted')` → 應為 **0**。孤兒 B：pending + `ClassSession` 為 `cancelled` → 應為 **0**。 |
| **關聯檔案** | `LearningRecord.php`（`scopeActive`、`scopeExcludeLeaveSessionPendingReview`）、`LearningRecordController.php`（`index`、`ensurePastRecords`、`batchApprove` 等）、`NotificationSyncService.php`、`CourseLeaveCascadeService.php`、`ApprovalSessionSyncService.php`（skip 狀態對照） |
| **測試** | `LearningRecordApprovalDeductionTest.php`（含 `ensure-past` 跳過 leave、作廢不重建、作廢不進 index／batch） |

---

## 2026-04-12 — 出缺勤「科目」欄、待點名科目、舊 Subject 主鍵、Subject 中文化

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | **(1)** `AttendanceController::index` 只 join `Subject ON sc.SubjectID`，主檔空或 id 在 `Subject` 表已不存在時，**簽到上的 `si.SubjectID` 無法回填**，API 的 `subject_name` 為 null → 前端顯示「—」。**(2)** `ClassSessionController::index`（供待點名用的 `GET /api/v1/class-sessions`）**完全沒有 join Subject**，回傳無 `subject_name` → 前端待點名表格科目欄全部顯示「—」。**(3)** 舊庫殘留 Subject id（1、14、15、21）與重建後字典表 id（64-71）不一致，JOIN 失敗。**(4)** `Subject.Subject_Name` 存英文（Chinese、English…），台灣補教業使用者無法一眼辨識。 |
| **正確行為** | `AttendanceController::index` 主查詢 **leftJoin `Subject as sub_sc`（主檔）與 `sub_si`（簽到）**，`subject_name = COALESCE(sub_sc.Subject_Name, sub_si.Subject_Name)`（**主檔優先**）。`ClassSessionController::index` 須 **leftJoin Subject on sc.SubjectID** 並 select `subject_name`。`Subject.Subject_Name` 儲存中文（國文、英文、數學、物理、化學、理化、社會、生物）。部署後執行 migration **`2026_04_12_200000_remap_orphaned_subject_ids`** 修正歷史 id。 |
| **禁止回歸** | **(a)** 勿改回「只依 `sc.SubjectID` 單一 join」而忽略 `si.SubjectID`。**(b)** 勿移除 `ClassSessionController::index` 的 Subject join（否則待點名科目又空白）。**(c)** 勿把 `Subject.Subject_Name` 改回英文；前端 `constants.js` 的 `SUBJECT_NAME_MAP` 已支援雙向，但使用者期望直接看到中文。**(d)** 新增科目時 `Subject_Name` 須用中文。**(e)** 勿在出缺勤請假路徑繞過 `CourseLeaveCascadeService`。 |
| **關聯檔案** | `AttendanceController.php`（`index`、`store` excused 分支）、`ClassSessionController.php`（`index`）、`ScheduleController.php`、`CourseLeaveCascadeService.php`、`backend/database/migrations/2026_04_12_200000_remap_orphaned_subject_ids.php`、`Subject` 表（中文名）、`frontend/src/lib/constants.js`（`SUBJECT_NAME_MAP`） |
| **測試** | `AttendanceSubjectNameResolutionTest.php`、`AttendanceExcusedLeaveCascadeTest.php` |

---

## 2026-04-11 — 主任「繳費／續課提醒」：`AlertController::tuition`（變更前必問使用者）

| 項目 | 說明 |
|------|------|
| **產品規則（摘要）** | 見 **`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`**。堂數制：`Stop=0` 且（`Paid!=1` **或** `RemainingSessions<=2` **含 0**）→ 已繳低堂數仍會出現（`alert_type`：`low_sessions`）。月結制：須 `settlement_day`；提醒窗口為距**本次計算之繳費日** **0～4 天**（小於 5 天）；未繳且**已過**當月繳費日則逾期期間**一律**提醒。 |
| **禁止擅自** | 改成「僅未繳才列出」、只查堂數制漏月結、`remaining>0 && <=2` 漏 0 堂、或任意放寬／收緊天數門檻而不經產品確認。 |
| **改動前必做** | **先取得使用者（產品）明示同意**；更新 **`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`**；跑過／補齊 **`TuitionAlertsApiTest`**、**`LargeBranchDataHandlingTest`** 等相關測試。 |
| **關聯檔案** | `backend/app/Http/Controllers/AlertController.php`、`frontend/src/pages/DirectorDashboard.vue`、`backend/tests/Feature/TuitionAlertsApiTest.php`、`backend/tests/Feature/LargeBranchDataHandlingTest.php` |

---

## 2026-04-11 — 主任總覽「待審核評量」：`only_due=1` 造成核准一筆後其餘「整批消失」

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `DirectorDashboard.vue` 載入待審評量時呼叫 `GET /api/v1/learning-records?...&only_due=1`。`only_due` 只回傳「`SessionDate` + `EndTime`（無則 23:59）≤ 現在」的筆數。主任在總覽核准**一筆已過下課時間**的待審後，`loadData()` 重抓清單；若佇列裡其餘待審多為**同一天但尚未到下課**的堂次，會全部被 `only_due` 濾掉 → 畫面變成「無待審核評量」。使用者重新整理後（時間已過下課或與快取無關的完整重載）又看到待審，誤以為資料遺失。 |
| **正確行為** | **主任總覽**應列出分校內所有需審核的評量（`pending` + `changes_requested`），**勿**對總覽卡片套用 `only_due=1`（該參數僅適合「只想看已下課可審」的**明確子功能**，且須產品同意後才可加開關）。核准後可對該筆做樂觀移除並再 `loadData()`，避免重載前短暫空白。 |
| **關聯檔案** | `frontend/src/pages/DirectorDashboard.vue`（`loadData` 內 `learning-records` 查詢）、`backend/app/Http/Controllers/LearningRecordController.php`（`only_due` 參數語意） |
| **禁止回歸** | 勿在主任總覽待審卡片上**默默**加回 `only_due=1` 或僅查 `status=pending` 而漏掉 `changes_requested`；若需「只顯示已下課」請另做**可切換的篩選**並寫入本檔與 CHANGELOG。 |

---

## 2026-04-11 — ⚠️ 核准評量 = 點名核課（架構級變更，改動前必問使用者）

| 項目 | 說明 |
|------|------|
| **架構決策** | 2026-04-11 起，**核准評量（LR approved）等同點名**：`ApprovalSessionSyncService::syncOnApprove` 會建立 `StudentSignIn(Memo=lr_approve)`、更新 `ClassSession.Status=attended`、呼叫 `deductOnAttendance`。rollback 對稱沖回。**此為產品方明確要求的重大架構變更**。 |
| **禁止回退此行為** | 任何 AI 或工程師**不得**將核准評量改回「不扣堂」、不得移除 `syncOnApprove` 呼叫、不得在 `approve/batchApprove/rollbackApproval` 內繞過 `ApprovalSessionSyncService`。如有疑慮，**必須先詢問使用者**後才可改動。 |
| **關鍵守衛規則** | leave/cancelled 跳過、未來堂次不預扣、已有扣堂 SignIn 則冪等跳過（不重複扣）、rollback 只 void `Memo='lr_approve'` 型 SignIn（不影響獨立點名） |
| **月結制** | `RemainingSessions` 恆 0，`UsedSessions` 透過 `recomputeCounters` 累加 |
| **改動前必讀** | `docs/OPERATIONS_RUNBOOK.md` §K（強制口徑）、`docs/CHANGELOG.md`（2026-04-11 B）、本檔本節 |
| **關聯檔案（改動前必問使用者）** | `ApprovalSessionSyncService.php`、`SessionDeductionService.php`、`LearningRecordController.php`（approve / batchApprove / rollbackApproval）、`AttendanceController.php`、`LearningRecordApprovalDeductionTest.php` |
| **測試** | `./vendor/bin/phpunit --filter=LearningRecordApprovalDeductionTest`（17 tests, 95 assertions，必須全綠） |

---

## 2026-04-11 — 前端上線：`index.html` 與 Vite hashed chunk 不同步（整站無法載入，嚴重）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `backend/public/index.html` 仍引用**舊 build** 的 `./assets/index-*.js`（Vite 產生的 hash 檔名），但 `backend/public/assets/` 內已是**另一輪** build 的檔名（或曾**只覆寫部分** `assets`、未一併更新 `index.html`）。瀏覽器請求不存在的 `.js` 時，Laravel SPA fallback（`routes/web.php` 的 `/{path?}`，`^(?!api)`）改回傳**同一個** `index.html`，`Content-Type` 為 **`text/html`**。ES module 載入器預期 JavaScript → 主控台出現 **`Failed to load module script... MIME type of "text/html"`**，**整個後台白屏／無法使用**。 |
| **正確行為** | 每次要上線前端變更，一律在 repo 內執行 **`cd frontend && npm run deploy`**（`vite build` + `node scripts/copy-to-backend.cjs`），讓 **`index.html` 與整個 `assets/` 目錄同一輪、一併覆寫**（copy 腳本會清空後再拷貝 `assets`）。**禁止**只手動複製部分 chunk、或只更新 `assets` 忘記 `index.html`、或讓邊緣快取長期持有**舊** `index.html` 卻打到**新**檔名的路徑。部署後**抽查**：`index.html` 裡 `<script type="module" ... src="./assets/index-….js">` 的檔名，**必須**實際存在於 `backend/public/assets/`。 |
| **2026-04-12 補強（防靜默不同步）** | `copy-to-backend.cjs` 對 `index.html` 改為 **`readFileSync` + `writeFileSync` 整份覆寫**（避免少數環境 `cpSync` 未真正更新目標檔）。拷貝結束後執行 **`verifyIndexHtmlReferencesAssets()`**：若 `index.html` 內任一 `./assets/…` 檔在 `backend/public/assets/` 不存在，腳本 **throw → process exit 1**，禁止留下「舊 index 引用舊 hash + assets 已是新一輪」的組合。若仍見 MIME 錯誤，請在伺服器上 **`head backend/public/index.html`** 與 **`ls backend/public/assets/index-*.js`** 交叉比對。 |
| **關聯檔案** | `frontend/scripts/copy-to-backend.cjs`、`frontend/vite.config.js`（`base: './'`）、`backend/public/index.html`、`backend/routes/web.php`；Cursor 規則 **`.cursor/rules/auto-frontend-deploy.mdc`**（改 `frontend/src` 等後須 deploy）。 |

---

## 2026-04-11 — 手動「過去日期」必須維持「已上完」語意

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | AI 為處理「隔天建課、首堂在昨天」誤扣堂，將 **過去手動日改為預排**、並放寬 `EnrollmentService` 對 `future_dates` 的驗證，**違反營運既定邏輯**。 |
| **正確行為** | 使用者在月曆**手動點選今天以前**的日期＝**已上完／補登**（進 `confirmed_dates`、後端 `completed`＋扣堂流程）。**不得**在未經產品同意的情況下改為「錨點預排」。目前產品**僅**透過 **`UniversalClassScheduler.vue`**（排課 modal）操作；**前端正向入口已無「新生入班精靈」**（舊元件已自 repo 移除）。 |
| **關聯檔案** | `docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`、`UniversalClassScheduler.vue`、`EnrollmentService.php` |

---

## 2026-04-11 — 新建課程「學段／科目」提示：前後端與 Vue ref

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | **(1)** `UniversalClassScheduler.vue` 的 `scopeWarning` 把 **`subjectOptions` ref 物件**傳給 `checkTeacherScope`，未傳 **`subjectOptions.value`**，在 `<script setup>` 內不會自動 unwrap，導致比對邏輯對不到陣列、**畫面學段黃條形同失效**（載入 API 科目後尤其嚴重）。**(2)**（歷史、精靈已移除）舊版前端正向「入班精靈」元件曾用**寫死科目、無 `Subject.id`**，導致 `checkTeacherScope` 與 `POST /api/v1/enrollments` 後端語意不一致。**(3)** 前端只比對「選到的那一筆科目的單一 `id`」；`Subject` 表內**同名科目多筆 id**（歷史／分校資料）與老師授課設定裡的 `subject_id` 不一致時，出現**假陽性**：「老師設定沒有數學」其實有。後端已用 `TeacherScopeService::resolveEquivalentSubjectIds` 處理等價 id。 |
| **正確行為** | 所有**目前產品內**「新建課程」入口（學生管理、課程管理、智慧排課之 **`UniversalClassScheduler`**，以及 **`CourseEditForm`** 等）的**事前**學段提示，應與後端同一套語意：**同名科目多 id 一併納入比對**；傳入 `checkTeacherScope` 的科目列表必須是**陣列**（`ref` 請 `.value`）；科目選項須含 **`id`**（例如 `fetchSubjectOptions()`）。成功建立後仍應保留 **`class-sessions/batch`** 回傳的 **`scope_warning`**（alert）。後端 **`POST /api/v1/enrollments`** 仍存在（測試／整合）；若日後重做精靈 UI，須符合上列並與 `EnrollmentService` 一致。 |
| **關聯檔案** | `frontend/src/lib/constants.js`（`checkTeacherScope`）、`frontend/src/components/UniversalClassScheduler.vue`、`frontend/src/components/CourseEditForm.vue`、`frontend/src/lib/subjectsApi.js`、`backend/app/Services/TeacherScopeService.php`、`backend/app/Services/EnrollmentService.php` |

---

## 2026-04-11 — 智慧排課：同一門課「不同週幾、不同時段」不得只複製 `start_time`

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 學生同一 `StudentClass` 登記週二 17:00～19:00 與週六 10:00～12:00；**點名／出缺勤**依 **該日 `ClassSession`** 顯示正確，但 **智慧排課課表圖**在週六仍把區塊畫在 17:00～19:00。根因：`GET /api/v1/student-classes` 將 `start_time`／`end_time` 設為 **`day_time_slots` 排序後第一筆**（常為週序較前的那一天）；`SmartCalendar.vue` 的 `filteredCourses` 在依 **堂次日期集合**（`ClassSession` 載入的 `sessionDatesByCourseId`）展開格子時，只複製課程主檔時段，**未依該日 session 或星期幾覆寫時段**。 |
| **正確行為** | 課表格上每一格顯示的 **開始／結束／時長** 須與 **該日實際堂次**一致：優先該日 `ClassSession`（與點名、課程管理一致）；若無則用後端 **`day_time_slots` 對應 `dow`**；最後才退回主檔 `start_time`。勿假設「一門課全週同一 `start_time`」。 |
| **關聯檔案** | `frontend/src/pages/SmartCalendar.vue`（`resolveCourseGridTimes`、`filteredCourses` 合併）、`frontend/src/lib/classSessionsApi.js`、`backend/app/Http/Controllers/StudentClassController.php`（`day_time_slots`、主檔 `start_time` 語意） |

---

## 使用方式

1. 實作或重構觸及下方「關聯檔案」時，逐項確認行為是否仍符合「正確行為」。
2. 若引入新的高風險 regression，於本檔**以日期新增一節**（簡短：缺口 → 正確行為 → 關聯檔案／測試）。

---

## 2026-04-11 — 聊天頭像、Bug 附件／權限／紅點

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 頭像存成含 `APP_URL` 的完整 URL，區網開網頁時聊天／側欄破圖；Bug 主任誤以為能看全校；指派與狀態權限混在 `director` 路由。 |
| **正確行為** | 詳見 **`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**：`PublicAvatarUrl`、只存 disk 路徑、主任／老師僅自己的 bug、僅 super_admin 狀態／mark-inbox、無指派、未讀紅點規則與路由順序。 |
| **關聯測試** | `ChatApiTest.php`、`BugReportApiTest.php`、`ProfileCenterApiTest.php`（頭像相關） |

---

## 2026-04-10 — 暫停課程、評量待審、繳費提醒、課程列表 UI

### A. 暫停課程（`StudentClass.Stop = 1`）仍出現在「待審評量」

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 課程已暫停，主任儀表板與學習評量頁仍出現該課的 `pending`／`changes_requested` 評量，誤以為還要填寫／審核。 |
| **正確行為** | 暫停課程的待審／需修改評量**不應列入**待審佇列與相關通知；**已核准、已退回等歷史**仍可查。 |
| **實作要點** | `LearningRecord` scope `excludePausedCoursePendingReview`；`LearningRecordController::index` 套用；`batchApprove` 僅限未暫停之 `StudentClass`；`NotificationSyncService::buildLearningNotifications` 排除暫停課程。 |
| **測試** | `tests/Feature/LearningRecordApprovalDeductionTest.php`（`test_paused_course_hides_pending_learning_record_from_index_but_keeps_approved_visible`）。 |

### B. 課程管理列表：暫停狀態「看不出來」

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | 僅小標「已暫停」，整列與操作區與正常課程幾乎相同，主任沒有「真的暫停」的感受。 |
| **正確行為** | 整列背景／左側色條、科目欄上方 **明確 callout**（暫停說明）、學生群組標題 **「含暫停課程」**、展開的上課日期區塊視覺一致；**恢復**按鈕仍清楚可點。 |
| **關聯檔案** | `frontend/src/pages/CourseManagement.vue` |

### C. 主任儀表板「繳費提醒」漏提醒（堂數 0 堂、整類月結消失）

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `GET /api/v1/alerts/tuition` 只查 `ScheduleMode = 'count'`，**整個月結制（`date`）被略過**；堂數制用 `RemainingSessions > 0 && <= 2`，**漏掉 0 堂**；畫面顯示「全數已繳」易誤導。 |
| **正確行為** | **必須**與 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 一致（堂數制 ≤2 含 0、月結 `settlement_day`、距繳費日 &lt; 5 天、逾期未繳等）。 |
| **關聯檔案** | `backend/app/Http/Controllers/AlertController.php`、`frontend/src/pages/DirectorDashboard.vue` |
| **測試** | `tests/Feature/TuitionAlertsApiTest.php`、`tests/Feature/NotificationApiTest.php`（`test_tuition_alert_endpoint_includes_low_sessions_even_when_paid`） |
| **營運手冊** | `docs/OPERATIONS_RUNBOOK.md`（繳費提醒／tuition API 說明需與上列規格文件同步） |

### D. 通知 API 測試與 `unread-count` 內建 sync

| 項目 | 說明 |
|------|------|
| **曾發生的錯誤** | `GET /notifications/unread-count` 會先執行 `NotificationSyncService::sync`，手動建立的 `Type=tuition` 等**託管類型**可能被自動結案；測試預期的 `active_count` 與實際 sync 來源數不一致。 |
| **正確行為** | 測試用手動通知時使用**非** `managedTypes` 的 `Type`；或斷言與目前 `buildTuition`／`buildLowSessions` 等合併後筆數一致。 |
| **關聯檔案** | `backend/app/Http/Controllers/NotificationController.php`、`backend/tests/Feature/NotificationApiTest.php` |

---

## 檢查清單（快速）

- **前端 bundle 有變**（`frontend/src/**` 等）→ 上線前／Agent 任務結束前必跑 **`cd frontend && npm run deploy`**；**切勿**留下「舊 `index.html` 引用舊 hash + `assets` 已是新 hash」或相反組合。異常徵兆：主控台 **`MIME type of "text/html"`** on `index-*.js` → 先對照本檔 **「index.html 與 Vite hashed chunk 不同步」**。

修改以下路徑時，至少重跑相關 Feature tests：

- `ApprovalSessionSyncService.php` / `SessionDeductionService.php` / `LearningRecordController.php`（approve/batchApprove/rollbackApproval）→ **改動前必問使用者**；`LearningRecordApprovalDeductionTest`（17 tests 全綠）
- `LearningRecordController.php` / `LearningRecord.php` → LearningRecord 測試
- `AlertController.php`（`tuition`）→ `TuitionAlertsApiTest` + `NotificationApiTest`（tuition 相關）
- `NotificationSyncService.php` → `NotificationApiTest`
- `ChatService.php` / `ChatController.php` / `PublicAvatarUrl.php` / `AuthController.php`（`uploadAvatar`、`toAvatarUrl`）→ `ChatApiTest` + `ProfileCenterApiTest`
- `BugReportService.php` / `BugReportController.php` → `BugReportApiTest`
- `CourseManagement.vue` → 手動確認暫停列 UI；有腳本則 `npm run deploy`
- `EnrollmentService.php` / `UniversalClassScheduler.vue` → 必讀 **`docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`**，勿改「過去手動＝已上完」；學段提示見本檔 **2026-04-11 — 新建課程「學段／科目」提示**（`ref` 傳 `.value`、科目選項須含 `id`）。**前端正向已無入班精靈**；勿在文件或回覆中假設仍有 `EnrollmentWizard.vue`
- `checkTeacherScope` / `TeacherScopeService.php` → 科目多 id、前後端等價比對一致；勿只比對單一 `subject_id`
- `SmartCalendar.vue`（`filteredCourses`、堂數制與 `sessionDatesByCourseId`）→ 多日／多時段須對齊 **該日 `ClassSession` 或 `day_time_slots`**，勿全週套用主檔 `start_time`；見本檔 **2026-04-11 — 智慧排課：同一門課「不同週幾、不同時段」**；變更後 `npm run deploy`
- `DirectorDashboard.vue`（總覽待審評量 API）→ 勿加回 **`only_due=1` 當唯一清單**；見本檔 **「主任總覽待審核評量：only_due」**；變更後 `npm run deploy`
- `ClassSessionController.php`（`index`）→ 勿移除 Subject left join，否則待點名科目空白；見本檔 **2026-04-12 — 出缺勤「科目」欄**
- `Subject` 表 → `Subject_Name` 須為中文（國文、英文…）；新增科目亦同；勿改回英文。前端 `SUBJECT_NAME_MAP` 已支援雙向
- `StudentClassController.php`（`update`）→ Rate 或 SessionCount 異動後須同步 `Charge`；見本檔 **2026-04-15 — 編輯課程費率後 Charge 未同步**

---

## 2026-04-15 — 編輯課程費率後 Charge（總費用）未同步至催繳通知

### 現象

主任在「課程管理」編輯單堂費率（Rate: 1000 → 1100），8 堂課總費用應為 8800，但催繳通知單（`PaymentSlipModal`）仍顯示 NT$8,000。

### 根因

`StudentClass.Charge` 是**建課時的快照欄位**——`EnrollmentService::store()` 與 `purchaseBatch()` 會在建立課程時計算 `Charge = Rate × SessionCount`（或 `Rate × TotalHours`）並寫入 DB。

然而 `StudentClassController::update()` 經 `mapFrontendPayload()` 只映射 `Rate` 與 `SessionCount`，**從未重算 `Charge`**。催繳單 API（`AlertController::tuitionSlipData`）直接回傳 `StudentClass.Charge`，因此金額永遠停留在建課時的數字。

### 修正

在 `StudentClassController::update()` 的 `$studentClass->refresh()` 之後，新增 Charge 同步區塊：

- `rate_unit = 'session'`：`Charge = Rate × SessionCount`
- `rate_unit = 'hour'`：`Charge = Rate × TotalHours`
- 月結制（`SessionCount = 0`）：`newCharge` 為 0 → guard `> 0` 保護不覆寫

### 高風險區塊（修改前必對照）

| 檔案 | 方法 | 注意 |
|------|------|------|
| `StudentClassController.php` | `update()` | Rate/SessionCount 異動後必須同步 `Charge`；勿移除重算區塊 |
| `StudentClassController.php` | `mapFrontendPayload()` | 若新增映射 `Charge` 欄位，確認不與重算邏輯衝突 |
| `EnrollmentService.php` | `store()` | 建課的 Charge 計算邏輯為權威來源，update 應與其保持一致 |
| `StudentClassController.php` | `purchaseBatch()` | 加購時的 Charge 計算也須與 update 同口徑 |
| `AlertController.php` | `tuitionSlipData()` | 直接讀 `Charge` 欄位，不做額外計算；仰賴上游正確 |

### QA 驗收

1. 建課 1000/堂 × 8 堂 → `Charge = 8000`
2. 編輯費率改為 1100/堂（堂數不變）→ `Charge` 應更新為 `8800`
3. 開啟催繳通知單 → 金額顯示 NT$8,800
4. 僅改 SessionCount（8 → 10，Rate 不變 1100）→ `Charge = 11000`
5. 月結制課程修改 Rate → `Charge` 不應被清零
6. 時數制課程修改 Rate → `Charge = Rate × TotalHours`
