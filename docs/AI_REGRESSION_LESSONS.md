# AI／工程師防再犯紀錄（必讀）

本檔記錄**已發生過的產品／實作缺口**，避免下次改壞或改漏。  
**任何 AI Agent 或新進開發者**：請與 `AGENTS.md` 的 First-read 順序一併閱讀；修改下列模組前**先對照本檔**。

**不同工具如何接到本檔：** **Cursor** 透過 `AGENTS.md` 與 `.cursorrules`；**Claude Code** 讀根目錄 **`CLAUDE.md`**；**GitHub Copilot**／在 GitHub 上工作的 AI 讀 **`.github/copilot-instructions.md`**；人類協作者請看 **`CONTRIBUTING.md`**（皆連回本檔與繳費規則）。

相關專項規格：

- 主任儀表板「繳費提醒」完整規則：`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`
- 內部聊天、Bug 回報、使用者頭像（**含禁止回歸項**）：**`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**
- **手動排課日期＝已上完（過去日）**：**`docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`**（勿擅自改語意）
- **主任「繳費／續課提醒」**：**`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`**（堂數制低堂數含已繳、月結「小於 5 天」等；**改動前必問使用者**）
- **固定排課／批次入班／學生課程列表「時段」／編輯課程改星期後未來堂**：見下方 **§2026-04-12 — 固定排課契約與堂次一致**（手動日、列表顯示、`PUT` 同步三項一次對照）
- **老師教學工作台**：見下方 **§2026-04-12 — 老師教學工作台（TeacherHome）**（預設頁、跨分校週課表、badge、deploy）

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
| **正確行為** | **原則：課程顯示的「時段」應以「未來 scheduled 堂次」為準，不應被已完成的過去堂次影響。** **(1)** `reconcileWeekTimeFieldsFromSessions`：先查 `Status='scheduled' AND SessionDate >= today` 的未來堂次；若不空，**只用這些**重建 week/time；若全部完課（空），再 fallback 到所有 `scheduled/completed/attended`（保持已結束課程仍顯示歷史時段）。**(2)** `index()` 的 `$sessionSlotsByClassId`：同樣邏輯——先抓有未來 scheduled 堂的 class id（`$classIdsWithFuture`），有的用未來堂，沒有的（已完課）才 fallback 全撈。**(3)** `ensurePastRecords`：`WHERE` 條件改為 `CONCAT(SessionDate, ' ', COALESCE(StartTime, '00:00:00')) <= now`（改用 `StartTime`）。 |
| **禁止回歸** | **(a)** 勿把 `reconcileWeekTimeFieldsFromSessions` 的 Session 查詢改回只查全部狀態（會讓手動補登的過去日期再次汙染時段顯示）。**(b)** 勿把 `index()` 的 `$sessionSlotsByClassId` 查詢改回不分新舊一律撈 `completed/attended`。**(c)** 勿把 `ensurePastRecords` 的判斷條件從 `StartTime` 改回 `EndTime`（評量表應從上課開始就能填）。**(d)** 出缺勤 `index()` 主查詢**必須**保留 `->whereNull('si.VoidedAt')`，否則補請假作廢的舊「到班」記錄會再度出現。 |
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
