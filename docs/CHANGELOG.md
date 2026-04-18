# AllTrue Changelog

此檔記錄「已上線或已合併」的重要變更，讓後續 AI / 工程師可以快速理解最近的系統行為。

## 2026-04-18 — PRD-F：兼職薪資改為 30-min slice-based 重疊計算（覆蓋 2026-04）

### Problem

兼職老師薪水算法過去使用 `CONCURRENCY_START_TOLERANCE_MINUTES=15` — 兩堂課開始時間相差 > 15 分鐘即視為獨立排課，兩人分別照時薪計。結果 游靜鈴 在 4 月出現與 Ruth蔣 錯開 30–90 分鐘的多人並堂卻完全沒吃到「每多一人 +50 / 小時」 headcount bonus，實領 8400 元；但主任實際認知是「重疊時段就是並堂、加 headcount bonus，不重疊就各自 solo」，兩人工時一致時她倆不該差那麼多。

### Change

- **`backend/app/Http/Controllers/FinanceController.php`**
  - 移除 `CONCURRENCY_START_TOLERANCE_MINUTES` 常數。
  - `buildConcurrencyBonusMap($records, $rateMap, $ruleCtx)` 重寫為 sweep-line：
    1. 以 (teacher, date) 分組，用每堂合約 contracted duration 或 StartTime/EndTime 計算 interval；
    2. 取所有 interval 端點排序切出 atomic segments；
    3. 每個 segment 找出 active intervals，segment 時薪 = `max(base_rate) + headcount_bonus * (n-1)`，pay = 時薪 × (b-a)/60（按真實分鐘 pro-rata）；
    4. 把 segment pay 歸戶給 active 內 base_rate 最高的 LR（並堂時 tie-break 最小 LR id），其餘 LR 只拿 solo 段；
    5. `bonusMap[lr] = attributed - baseline(base_rate × hours)` → 可正可負，提供前端/匯出層顯示 delta。
  - `parttimePayroll` / `parttimePayrollSessions` / `buildParttimePayrollData` 全部改呼叫新簽名，把分行 rule 的 `headcount_bonus` 與 teacher override 併入 `$ruleCtx`。
- **測試全綠**：`PayrollConcurrencyTest` 10/10、`PayrollRateConsistencyTest` 2/2、`ParttimePayrollTest` 25/25、`PayrollTeacherOverrideTest` 14/14、`PayrollRulesTest` 10/10（共 61 tests / 184 assertions）。已更新 6 個舊期望值：從 15-min tolerance 模型（全獨立或全並堂）改為 slice-based（重疊段並堂、非重疊段 solo）。

### Impact

- 2026-04 still-draft 月份立即生效。核對實數：
  - 游靜鈴：8400 → 7350（-1050）— 錯開的非重疊段原本被誤認為「無並堂」吃了 400/hr × 全時數；現在非重疊段正常 solo、重疊段依 headcount bonus 累計。
  - Ruth蔣：6200 維持不變（她的並堂剛好都屬完全重疊段，新舊算法結果一致）。
- 分行主任仍可透過 `PayrollBranchRule.headcount_bonus` 覆寫預設 +50/hr；teacher override 也照常吃。
- sessions 詳列 API 的 `concurrency_bonus_amount` 現在可能為負（代表該 LR 是非主攤位，只領 solo 段），前端顯示金額仍以 total_salary 為準，不會讓老師看到「扣款」。

### Auto-verification

1. `php artisan test tests/Feature/Payroll*Test.php tests/Feature/ParttimePayroll*Test.php` → 全 PASS。
2. Tinker 模擬：以 admin 身份呼叫 `/api/v1/finance/parttime-payroll?month=2026-04&branch_id=17`，確認 游靜鈴 7350 / 24h、Ruth蔣 6200 / 26h。
3. 範例驗證 (grade=高中 400/hr, bonus=+50)：A 17:00-19:00、B 18:00-20:00 → 17-18 solo 400 + 18-19 並堂 450 + 19-20 solo 400 = 1250（原 15-min tolerance 算成 2 × 800 = 1600）。

### Resolution classification

PAYROLL-ALGORITHM-CHANGE（財務規則變更）。資料表 schema 不變；歷史已 frozen 月份不重算（PayrollMonthStatus=frozen 的月份仍維持舊 snapshot）。

---

## 2026-04-18 — PRD-E：SmartCalendar 多日多時段課程基底格被單一 scheduled 例外抹除修正（8c1673b9）

### Problem

興隆主任回報：鄭翔祐 × 吳艾潼 2026-04-19（日）15:00-17:00 堂次在班級行事曆/課表上未顯示。資料端 `ClassSession` 與 `/api/v1/class-sessions` 均正確返回該堂次，卻在 SmartCalendar 畫面被整格吃掉。

### Root cause

`frontend/src/pages/SmartCalendar.vue` 的 `filteredCourses` computed 第 1885–1902 行以 `hasScheduledExc`（boolean）檢查該日該課程是否存在任何 status='scheduled' 的 schedules 例外：若為 true 即整個日期的基底 weekly pattern 都不渲染。

SC#382 `week=3/time=16:00` + `week1=7/time1=15:00` 是多日多時段課程：週日 15:00 是基底 weekly slot，同日 17:00-19:00 是由 4/22（三）調過來的 scheduled 例外（schedules #622↔#623）。舊邏輯將 17:00 例外的存在視為「此日所有基底格都已被例外接手」，連帶把週日 15:00 的基底格也抹掉 → 吳艾潼 15:00 未渲染。但在「add extras」迴圈只會把 schedules 例外渲染出來（17:00 有、15:00 沒有），導致週日 15:00 堂次完全消失。

### Change

- **`frontend/src/pages/SmartCalendar.vue`**（filteredCourses 邏輯修正，無樣式變動）
  - 將 `hasScheduledExc`（boolean）改為 `scheduledExcStartSet`（Set<HH:MM>）收集該日該課程全部 scheduled 例外的 start_time。
  - 渲染基底 weekly 時段時，改為逐一比對：只有當基底 `start_time` 與例外 `start_time` 完全重疊才跳過（代表那格由例外接手）；其他時段照常渲染。
  - 移除原本整日抹除的跳過條件（`!hasScheduledExc`），保留 `hasReschedule`（rescheduled-from）原語意。
- **`backend/tests/Feature/MultiSlotCourseWithExceptionTest.php`** 新增資料契約測試（1 test / 12 assertions）：驗證多日 SC 同日同時具 weekly 基底與 scheduled 例外時，`/api/v1/class-sessions`、`/api/v1/schedules`、`/api/v1/student-classes` 三條 API 皆提供前端渲染所需完整資料，防止後端回歸把資料吃掉的情況。

### Impact

- 修復：吳艾潼 2026-04-19 15:00-17:00 堂次正常顯示於 SmartCalendar；類似 `days_of_week=[a,b]` 多日多時段課程遇到 scheduled 例外時基底格不再消失。
- 不影響：單日課程、純 rescheduled 例外、leave 例外、absent/completed 堂次渲染；chaotic reschedule stack 仍維持既有 sessionSet / hasReschedule 判定。
- 無後端 API 變動；backend 現有 `/api/v1/class-sessions`、`/api/v1/schedules`、`/api/v1/student-classes` 契約不變。

### Auto-verification

1. `php artisan test tests/Feature/MultiSlotCourseWithExceptionTest.php` → 1 test / 12 assertions PASS。
2. `GET /api/v1/class-sessions?branch_id=16&start=2026-04-19&end=2026-04-19` 回傳 CS#3070（吳艾潼 15:00-17:00）與 CS#2145（陳嘉軒 15:00-17:00）雙堂次。
3. `npm run deploy` 完成後 browser-use agent 於 `https://daan.lifenet.com.tw/smart-calendar` 切到 2026-04-19，確認日檢視下 15:00 欄位同時看到陳嘉軒與吳艾潼兩格；17:00 欄位吳艾潼（schedules 例外接手）仍正常顯示。
4. 回歸檢查：`php artisan test tests/Feature/ClassSessionApiTest.php` 全 PASS。

### Resolution classification

UI-RENDER-FIX（純前端邏輯），Root cause 限於 SmartCalendar.vue filteredCourses 對多時段基底的抹除，無資料損失；無需後端資料回補或遷移。

### Rollback plan

若前端邏輯造成回歸，於 `SmartCalendar.vue` 將 `scheduledExcStartSet` 改回舊版 `hasScheduledExc` boolean 檢查並在 `if (!hasReschedule)` 加回 `&& !hasScheduledExc`；重新 `npm run deploy` 即可恢復舊行為。`MultiSlotCourseWithExceptionTest.php` 保留（回歸守護仍有效）。

---

## 2026-04-18 — PRD-D：平板檢視 SmartCalendar toggle 文字溢出修正（8c1673b9）

### Problem

興隆主任於 iPad / Android 平板（768–1024px 寬度）打開「日檢視」SmartCalendar 時，工具列右上「只顯示今日有課老師」toggle 文字會溢出 toggle 格子邊界，造成：

- 文字可能被裁切（視 grid 左欄實際寬度）
- 操作體驗差，使用者不確定是否點到 checkbox

### Root cause

`frontend/src/pages/SmartCalendar.vue` 的 `.toolbar-filters .toolbar-hide-empty-toggle` 使用固定 `height: 38px` + `white-space: nowrap`。在平板的 `.toolbar-secondary-line--filters`（`grid-template-columns: minmax(0, 1fr) auto`）中，左欄 `1fr` 必須同時容納室內選單、老師搜尋、學生搜尋、老師請假按鈕，再加上 toggle，寬度不足時 toggle 因 `flex-shrink: 0` 不縮小、`nowrap` 不換行 → 文字撐出邊界。

### Change

- **`frontend/src/pages/SmartCalendar.vue`**（style block only）
  - `.toolbar-hide-empty-toggle`：`height: 38px` → `min-height: 38px`（允許自然撐高）；`white-space: nowrap` → `white-space: normal` + `word-break: keep-all` + `overflow-wrap: anywhere` 讓中文於容器邊界自然斷行；新增 `max-width: 100%` 防止水平溢出父 grid。
  - 子 `> span` 顯式 `display: inline-block; max-width: 100%` 讓文字遵守 toggle 邊界。
  - checkbox 明確 `flex-shrink: 0` 讓核取方塊在 wrap 情境仍保持完整。
  - 新增 `@media (max-width: 1024px)`：toggle 於平板寬度下 `flex: 1 1 100%` 佔獨立一行，`min-height: 44px`（WCAG / iOS touch target）。

### Impact

- 修復：768–1024px 平板下 toggle 文字完整顯示、不再裁切；換行時 toggle 自動撐高，checkbox 與文字仍可點擊。
- 桌機（≥1025px）：toggle 維持原本 inline 外觀（38px 高、單行文字），無視覺退化。
- 無 JS 邏輯變動，`hideEmptyTeacherColumns` 綁定與 `localStorage` 持久化完全不受影響。

### Auto-verification

- ✓ `frontend && npx vite build` 成功（TypeScript / template lint 綠燈）
- ✓ cursor-ide-browser 於 768px / 900px / 1024px 截圖 SmartCalendar 日檢視，toggle 文字完整可見、checkbox 可點擊、toggle 高度 ≥ 44px；1280px 桌機視圖 toggle 仍為 inline 38px 高。
- ✓ `diff` 比對 `backend/public/index.html` 的 JS chunk hash 與 `backend/public/assets/` 目錄檔名一致（npm run deploy 產生物同步）

---

## 2026-04-18 — PRD-C：興隆分校兼職薪資偵錯（8c1673b9, no code change）

### Complaint

興隆分校主任回報「游靜鈴 (ID 83) 兼職薪資有誤、Ruth 蔣 (ID 146) 計算結果正確」。

### Investigation

AI Agent 逐項檢查 PRD-C FR-C-001 ~ FR-C-004：

| 檢查項 | 游靜鈴 (ID 83) | Ruth 蔣 (ID 146) | 結論 |
|---|---|---|---|
| `LearningRecord.SessionDate` 完整 | ✓ 所有已核准 LR 皆填 | ✓ | 無異常 |
| `StudentClass.SessionDuration` ≥ 30 | ✓ 所有 sc 皆為 120 分鐘 | ✓ | 無異常 |
| `duration1~6 / week1~6` 設定 | sc#619/#730 有設 week1=5/7、duration1=120；其餘僅 fallback `SessionDuration` | sc#620/#621/#622 同模式 | 兩人資料結構一致 |
| `payroll_teacher_branch_rules` 個人費率 | 無 | 無 | 兩人同 fallback 至 branch_default (rule id 24) |
| `payroll_branch_rules` 分校預設 (branch_id=17) | `base_rates={high:400, junior:350, elementary:350, tutoring:200}`, `headcount_bonus=50` | 同上 | ✓ 完全一致 |
| 相同 class_type + grade_level 的 effective_rate | junior 700/2h、high 800/2h | 同左 | ✓ DoD 滿足 |

AI Agent 透過 reflection 呼叫 `FinanceController::buildParttimePayrollData` 重跑 2026-03 與 2026-04：

- 2026-03：游 NT$4,900 / Ruth NT$7,000
- 2026-04：游 NT$8,400 / Ruth NT$6,200

每筆 `session_salary` 均符合 `round(base_rate × contracted_hours) + concurrency_bonus_amount`；兩位老師經過完全相同的計算路徑（branch_default rule + `buildConcurrencyBonusMap` v1.4 tie-break + v1.5 `CONCURRENCY_START_TOLERANCE_MINUTES=15`），無邏輯分支差異。

### Root cause of perceived discrepancy

Ruth 教的 StudentClasses 恰好是兄弟姊妹（張家 sc#620/#621/#622 同排 Fri 19:30；陳則佑/張靖禹 sc#559/#573 同排 17:30）且 LR 實登時間都精確對齊排定時段，觸發 `buildConcurrencyBonusMap` tie-break（primary +headcount_bonus、其餘 net=0）。游教的 one_on_two/one_on_three 班次排定時段不同（17:30、18:30、19:30、16:30、09:30），LR 時間也各自不同，故每堂都屬獨立課次（無併堂）——這是符合 v1.5 PRD 薪資計算與調課按鈕修正「錯開排課不應扣薪」的預期行為。

主任的主觀「游比 Ruth 高」來自：游全都是獨立課次各 700 / 800 元全額，Ruth 幾堂併堂被 tie-break 歸零只留 primary 800。這不是 bug，是兩種排課型態的自然結果；費率與邏輯完全一致。

### Conclusion

**無程式錯誤、無資料錯誤；PRD-C 結案為「NOT A BUG / AS DESIGNED」**。AI Agent 評估後未變更任何程式碼或資料，以避免回歸 2026-04-18 稍早 PRD `薪資計算與調課按鈕修正` 剛剛修好的「錯開 session 誤扣薪」問題。

### Auto-verification

- ✓ `GET /api/v1/finance/parttime-payroll/sessions?branch_id=17&month=2026-04&teacher_id=83` 每筆 `session_salary = round(base_rate × hours) + concurrency_bonus_amount`
- ✓ 相同 `class_type + grade_level` 對照：游 one_on_two junior (GradeID 7/8) effective_rate=350，Ruth 同 one_on_two junior 也為 350
- ✓ 回歸：`php artisan test tests/Feature/PayrollConcurrencyTest.php`、`ParttimePayrollTest.php`、`PayrollRulesTest.php`、`PayrollTeacherOverrideTest.php` 全綠（詳見 §PRD-C automation block）

### Follow-up recommendation (out of scope)

若主任仍希望游的 sc#178/sc#730（兩位排定 Sun 09:30 的 one_on_three 學生）在「makeup 日 LR 時間錯開」的情境下被視為同一組併堂，須由業務端建立新 PRD 推翻 v1.5「錯開 >15min 視為獨立」規則，再以 `StudentClass.time` 排定時段作為併堂鍵重做邏輯。不在本 PRD 範圍內。

---

## 2026-04-18 — PRD-B：家長端登入隔離與資料顯示完善（8c1673b9）

### Problem

家長端存在跨家庭 PII 洩漏風險：當兩戶不同家庭的 `Student.Phone` 相同（例如代班老師誤填、舊資料殘留），家長以姓名 + 手機登入後可能透過 `login` 回傳或 `dashboard` 回傳的 `students` 清單看到另一家學生；`switch-student` 也僅以相同手機就放行。此外家長端出缺勤顯示資訊不足（缺少科目、老師、時段）。

### Change（後端）

- **`backend/app/Http/Controllers/ParentPortalController.php`**
  - `login()`：改為嚴格「姓名 + Phone 精確單筆配對」。找不到回 `404`；姓名相同但手機不同且有空手機欄位時提示分校補登；姓名 + 手機同時精確配到兩筆以上（極罕見）回 `409` 並引導改用 LINE 綁定或 StudentID。移除「自動帶出相同手機的其他學生」，改為只有當登入學生有 `StudentLineBinding` 且該 LINE 帳號已明確綁定其他學生時才在回傳中帶 `students`。成功登入寫入 `parent.login.success` info log（含 student_id、ip）。
  - `switchStudent()`：移除「相同手機即放行」分支，僅允許透過共享 `line_user_id` 的 `StudentLineBinding` 切換。
  - `dashboard()`：siblings 一律只透過 LINE 綁定解析；不再以 `Student.Phone` 比對其他學生作為 siblings。
  - `dashboard()`：attendance_history 的每筆 `StudentSignIn` 新增 `date`、`time`、`subject`、`teacher_name` 欄位（FR-B-003），以符合家長端顯示「日期 / 時間 / 科目 / 老師 / 狀態」格式；`ClassSession` 批次查詢避免 N+1。
- **`backend/routes/api.php`**
  - `POST /api/v1/parent/login` 綁 `throttle:5,10`（5 次 / 10 分鐘，per IP + route）。
  - `POST /api/v1/parent/login-line` 綁 `throttle:30,10`（LIFF 重試友善）。
- **`backend/app/Http/Middleware/ThrottleRequestsByIp.php`（新增）+ `Http/Kernel.php`**
  - 本專案未配置 Laravel auth guard，內建 `ThrottleRequests` 會在 `resolveRequestSignature` 呼叫 `Auth::user()` 失敗。新增 `ThrottleRequestsByIp` 繼承並改以 `route + ip` 作為 signature；註冊為 `throttle` 別名，供家長/LINE 登入與未來其他公開路由使用。

### Change（前端）

- **`frontend/src/pages/ParentPortal.vue`**
  - 出缺勤卡片改採 FR-B-003 格式：每列顯示日期 + 狀態徽章 + 次行「時段 / 科目 / 老師」；新增狀態色彩 `present` 綠 / `late` 橘 / `absent` 紅 / `leave` 灰；缺席列加淡紅底色提示。
  - Token 存在但 dashboard 尚未載入時顯示 skeleton loader（三張佔位卡），避免空白畫面與 layout shift。
  - Attendance 空狀態：新增圖示 + 輔助文字「老師完成點名後將自動顯示於此」。
  - 首張卡片淡入動畫 0.25s，切換學生時有視覺過場。

### Impact

- 修復：兩戶不同家庭填寫同一手機時，彼此不再互見；跨家庭 PII 洩漏路徑已關閉。
- 相容：合法家長以 LINE 綁定多位孩子的情境完全不受影響，`students` 清單改透過 `StudentLineBinding` 正確解析。
- 測試：`tests/Feature/ParentPortalLoginIsolationTest.php`（7 tests）涵蓋：未提供姓名時 422；同手機不同家庭不互見；姓名錯但手機對回 404；dashboard 不漏 siblings；switch-student 跨家庭 403；LINE 綁定 siblings 正常；dashboard attendance_history schema。
- 回歸：既有 `AttendanceStatusReconcileTest`、`AuthLoginThrottleTest`、`Attendance*` 全家族測試綠燈。

### Auto-verification checklist（本 commit）

- [x] Pest 新測試 `ParentPortalLoginIsolationTest` 7/7 通過
- [x] Throttle 別名 `throttle` 已註冊並可用（`ThrottleRequestsByIp`）
- [x] `POST /api/v1/parent/login` 與 `POST /api/v1/parent/login-line` 已掛載 throttle middleware
- [x] 成功登入會寫入 `parent.login.success` log（student_id + ip）

---

## 2026-04-18 — PRD-A：今日課表出缺勤狀態同步修正（8c1673b9）

### Problem

主任儀表板「今日課表」widget 中，沈宇璿 17:00 這堂雖已完成刷卡/點名（`StudentSingIn.Status=present`, `VoidedAt IS NULL`），但行顯示仍為「缺席」。

### 根因

`ClassSessionController::index()` 組 JSON 時直接使用 `cs.Status`。當點名寫入路徑曾因任何原因把 `ClassSession.Status` 留在 `scheduled` 或 `absent`（例如 ended-sessions 補點名先標 absent 再被改為 present、或並行寫入順序），`StudentSignIn` 已是 `present` 但 `cs.Status` 未被同步，API 就回傳舊狀態。

### Change（後端）

- **`backend/app/Http/Controllers/ClassSessionController.php`**
  - `index()` SELECT 新增 `effective_status` 衍生欄位：在既有 `si` derived join 基礎上，當 `cs.Status ∈ {scheduled, absent}` 且存在 `VoidedAt IS NULL` 的 `StudentSingIn` 時，以 `si.Status` 對應映射輸出（`present→attended`、`late→late`、`excused/leave→leave`）；其他狀態一律維持 `cs.Status`，不動 `cancelled/leave/leave_adjusted/completed` 的既有語意。
  - Transform 中 `$row->status = $row->effective_status ?? $row->Status`；unset 時移除 `effective_status` 內部欄位不外露。
- **`backend/app/Console/Commands/BackfillSessionStatus.php`（新增）**
  - 命令 `attendance:reconcile-session-status`。支援 `--dry-run` / `--days` / `--auto-threshold` / `--force`。
  - 比對最新有效 `StudentSingIn.Status` 與 `ClassSession.Status`，僅修正 `cs.Status ∈ {scheduled, absent}` 且對應 sign-in 屬 `present/late` 的情境。
  - 自動執行條件（無人介入）：`inWindow > 0` 且 `inWindow ≤ auto-threshold`（預設 500）。超過 `--days`（預設 7）的歷史紀錄只寫入 `storage/logs/backfill-report-{date}.csv`，永不自動補正。

### Impact

- 修復：有有效 sign-in 的堂次再也不會在今日課表顯示缺席。
- 相容：`cancelled / leave / leave_adjusted / completed / late / attended` 等既有狀態在 SELECT 分支不受覆寫，原流程完全不變。
- 相容：`applyAttendanceEffects()`、`SessionDeductionService` 既有堂數扣除邏輯未動。
- 測試：`tests/Feature/AttendanceStatusReconcileTest.php`（5 tests）涵蓋顯示層 override、VoidedAt 忽略、store() 同步、backfill in-window/out-of-window、dry-run 保護。
- 回歸：既有 `ClassSessionApiTest`、`AttendanceLeaveStatusContractTest`、`AttendanceBatchMarkTest`、`MakeupAttendanceEndedSessionsTest`、`AttendanceRemainingSessionsRegressionTest`、`ClassSessionUpdateTest`、`ClassSessionDuplicateStatusTest` 全部維持綠燈。

### Auto-verification checklist（本 commit）

- [x] Pest 新測試 `AttendanceStatusReconcileTest` 5/5 通過
- [x] 相關既有測試全綠（無 regression）
- [x] `attendance:reconcile-session-status --dry-run` 會輸出 CSV 至 `storage/logs/backfill-report-{date}.csv`

## 2026-04-18 — Bug Fix：老師評量表開啟錯誤（同天同學生多堂課）（PRD 3baa154f）

### Problem

老師（鄭翔祐）反映：學生沈宇璿 4/18 有兩堂課 15:00–17:00（已填待審）與 17:00–19:00（未填）。從課表點 17:00 堂的「編輯評量」時，系統卻開啟第五堂（15:00）的評量記錄；儲存第五堂後，回來點第六堂又跳回第五堂。

### 根因（兩個連環 bug，純前端邏輯）

**Bug 1（主因）**：`LearningRecordsPage.vue` 的 `buildEvents()` 為每堂尋找對應 LR 時有三層 fallback（`cs:<id>` → `classId|date|startTime` → `classId|date`）。當 API 回傳第六堂 `learning_record_id = null` 時，進入 fallback 分支並命中第三層的 `classId|date` key，錯誤地把「同一 StudentClass 當天已存在的第五堂 LR」指派給第六堂的 `recordId`，使 `openFromSchedule()` 走入 `openRecordAction(existing)` 開啟第五堂記錄。

**Bug 2（次因）**：即使 Bug 1 修掉，`openFromSchedule()` 新增路徑設完 `form.StudentID` 後會觸發 `watch([form.StudentID, ...])` → `applyTeacherFormDefaults()`，該函式以「同日最早一堂」為預設值，會覆蓋剛剛正確設好的 17:00 時段。第五堂（有 LR）進的是 `editRecord()`，`isEditing=true` 使 watch 直接 return，所以只有新增路徑受影響。

### Change（純前端，後端零異動）

**`frontend/src/pages/LearningRecordsPage.vue`**

- `buildEvents()`：`recordId` 計算移除 `record?.id` fallback。API 回傳 `learning_record_id = null` 即代表該堂確實無 LR，不再以 `classId|date` 模糊比對覆寫。`record` 變數仍保留供 `baseFormStatus` 顯示狀態標籤使用
- 新增 `_openedFromScheduleSession` ref，於 `openFromSchedule()` 設為 `ev.classSessionId || -1`
- `watch([form.StudentID, form.SessionDate, form.Subject, form.TeacherID])` 的 teacher 分支：若 `_openedFromScheduleSession !== 0` 則清旗標後 return，跳過 `applyTeacherFormDefaults()`；後續老師在 modal 內手動改動欄位仍會觸發 defaults 自動填入
- `closeModal()`：清旗標為 0，防止使用者直接關閉 modal 而未觸發 watch 的邊際情況

### Impact

- 同天同學生多堂課情境：每堂評量表開啟 100% 對應正確 `ClassSessionID`
- 不影響路徑：單堂課老師、主任新增/審核評量、代課標記堂次、請假/取消堂次（既有守門條件不變）
- 無新 API 請求、無存取控制變更、後端零改動

### Regression 守門

- `LEAVE_STATUSES` 攔截（2026-04-17 修正）：請假/取消堂次 `recordId` 本就強制設為 null，本次修改不影響
- 代課老師情境（`isSubstituted=true`）：`recordId` 亦強制設為 null，不受 Bug 1 修正影響

---

## 2026-04-18 — UI/UX：主任儀表板代課紀錄精緻化 + 平板行事曆欄位溢出修復（PRD 4d7bdef8）

### Problem

1. **總覽「近 7 天代課記錄」不夠精緻**
   - 日期顯示為原始 `YYYY-MM-DD HH:mm~HH:mm`，資訊層次不清，主任難以快速掃視「今天/昨天有哪些代課」
   - 原老師 → 代課老師以純 ASCII 箭頭呈現，無色彩差異化，辨識度低
   - 「含換時」chip 使用灰色 `#f1f5f9`，與一般中性標籤外觀相同，複合操作不易辨識
   - 代課原因僅靠 `title` tooltip 顯示完整內容，觸控裝置（平板、手機）完全失效
   - 空狀態使用 emoji `🌤️`，與其他模組 Material Symbols 視覺風格不一致
   - Skeleton 僅 2 行，展開後實際卡片為 3 行（meta / flow / reason），造成 layout shift

2. **平板行事曆「只顯示今日有課老師」會超出格子**
   - iPad 直式（768px viewport，側欄展開後內容寬 ≈ 580px），開啟「只顯示今日有課老師」時老師欄位標頭溢出格子邊界
   - 根因：`@media (max-width: 1100px)` 只設 `.teacher-col { min-width: 0; }` 解除欄位底限，但未在該斷點與 ≤900px 斷點宣告 `.teacher-grid { min-width: max-content; }`；該規則僅存在於 ≤768px。結果是欄位被壓縮至 `minmax(140px, 1fr)` 以下，頭像/姓名/教室文字外溢

### Change（純前端 UI/CSS，後端零異動）

**`frontend/src/components/substitute/RecentSubstitutesCard.vue`**
- 新增 `formatDate()` 相對日期：當天「今天」、前一天「昨天」、更早「M/D（週X）」；解析失敗 fallback 至原字串避免白畫面
- 新增 `formatTimeRange()` 格式化為 `HH:mm ～ HH:mm`（全形波浪號）
- Meta 行改為三段式：日期（粗體 `#111827` / 13px）、時間段（`#4b5563` tabular-nums）、學生·科目（右側 ellipsis 截斷）
- 代課流向改為有語意色彩：原老師灰（`#6b7280`）、代課老師主色粗體（`var(--primary)`）、中間以 `material-symbols-outlined arrow_forward` 圖示替代純 ASCII 箭頭
- 「含換時」chip 改為琥珀色系（`#fef3c7` / `#92400e`），與現有「跨分校」badge 同調色，語意一致
- 代課原因改為 `<button>` 可點擊展開：預設單行 `-webkit-line-clamp: 1`、展開後完整換行顯示；`min-height: 44px` 確保觸控目標符合 WCAG；`reactive(new Set)` `expandedReasons` 記錄各筆展開狀態，`branchId` 切換時清空
- 空狀態改用 `event_available` Material Symbols（36px、`#9ca3af`）+ 標題 + 副文字，移除 emoji
- Skeleton 調整為 3 行（60% / 80% / 40%）並加 `rsc-skel-pulse` 動畫，結構對應真實卡片
- 新增摘要列「共 N 筆代課」
- `@media (max-width: 360px)` 讓最窄寬度下學生資訊換行，老師名稱最大寬度縮至 7em

**`frontend/src/pages/SmartCalendar.vue`**
- `@media (max-width: 1100px)` 補上 `.teacher-grid { min-width: max-content; }`
- `@media (max-width: 900px)` 補上同一條規則，並為 `.teacher-col-header` 加 `overflow: hidden`，固定 56px 高度內無視覺外溢
- `.teacher-grid-wrapper` 既有 `overflow-x: auto`，規則生效後欄位維持 minmax 最小值、多餘空間改為水平捲動

### 業界參照
- 相對日期（today/yesterday/M/D）為 Gmail、Slack、Notion 等標準做法（Nielsen Norman：Readable Date Formats）
- 觸控展開代替 hover tooltip 符合 iOS HIG「No hover, use tap to reveal」
- `min-width: max-content` 搭配 wrapper `overflow-x: auto` 為 CSS Grid 於 responsive 下避免欄位壓縮的標準手法（Rachel Andrew, CSS Grid in Production）

### 注意
- 兩項均為純 CSS / 前端 script 改動，不新增 API、路由或資料表；`role:director + require_campus` 權限不變
- 不影響 SmartCalendar 週覽（`.week-overview-grid`）排版；≤768px mobile 規則仍優先適用
- 回滾方式：`git revert` 該 commit 後重新 `cd frontend && npm run deploy`

---

## 2026-04-18 — Fix：兼職薪資錯開時段不再誤扣 + 課程管理合成 chip 靜默失敗（PRD 1b8d93cc）

### Problem

興隆主任回報兩件事：

1. **兼職薪資計算只有 Ruth 蔣是對的，其他老師都算少了**
   - Ruth 蔣恰好都是開始時間完全相同的真正 group class，公式對她正確
   - 其他老師（游靜鈴、黃芝琳、邱御碩等）有大量「開始時間錯開 30~60 分鐘」的排課，例如 09:30 + 10:00，v1.4 的 `buildConcurrencyBonusMap` 把這類錯開當成同步教學，non-primary 被扣掉 `base_rate × 重疊時長`（每日低薪 350~525 元）
   - 生產資料確認：campus_id=17 在 2026-04 有明確案例

2. **「調課」按鈕無法點擊**
   - 課程管理頁面的 session chip 有兩種來源：真實 `ClassSession` 行（有 id）與合成物件（`_synthetic: true`，無 id）
   - 合成 chip 點擊後 `openSessionEdit → getSessionDisplayRow` 回傳 null，函式 `if (!row) return;` 靜默結束 → 主任以為「按鈕壞了」

### Change

**後端（`backend/app/Http/Controllers/FinanceController.php`）**
- 新增 class constant `CONCURRENCY_START_TOLERANCE_MINUTES = 15`
- `buildConcurrencyBonusMap()` 判斷同時段時，除了「區間重疊」外再加一層「開始時間差距 ≤ 15 分鐘」的檢查；錯開 > 15 分鐘的 session 不進入同一 concurrent 組，各自全額計薪
- Ruth 蔣（相同 start time）行為**完全不變**；錯開 session 老師（游靜鈴、黃芝琳…）從「被扣薪」回到「各自全額」
- `tests/Feature/PayrollConcurrencyTest.php` 新增 2 條守護：`test_staggered_30min_no_concurrency`、`test_staggered_10min_still_concurrent`
- `tests/Feature/ParttimePayrollTest.php` 把 7 條原本帶 v1.3 期望值的測試改為 v1.5 新語意（錯開 60 min 不觸發 concurrency，各自 full base），一併修正本就已經因 v1.4 升級而失敗的 3 條同 start time 測試

**前端（`frontend/src/pages/CourseManagement.vue` + `composables/course-management/useSessionEditFlow.js`）**
- 課程管理 session chip：`_synthetic: true` 的 chip 不套 `date-chip-clickable`、改套 `date-chip-synthetic`（opacity 0.45、cursor: default、hover 無升起效果）、`@click` 前置 guard；`title` tooltip 改為「此堂次資料載入中，請重新整理後再試」
- `openSessionEdit` 找不到 row 時，改為 `alert('此堂次資料尚未載入，請重新整理頁面後再試。')` 取代舊的 silent return，避免任何呼叫路徑靜默失敗

### 業界參照
- 薪資規則容忍度是 HR 系統常見做法（記錄誤差 ≤ N 分鐘視為同時段；超過則各自計算）
- 「按了沒反應」是可用性禁區（Nielsen Norman：Visibility of System Status）；以灰化 + tooltip 代替看起來可按但按了無事的 chip

### 注意

- 此修正**不改動**任何 API 路由或 middleware；`role:director + require_campus` 權限不變
- `CONCURRENCY_START_TOLERANCE_MINUTES` 為 class constant，CTO 可調整（目前 15 分鐘已覆蓋興隆回報的真實 stagger 範圍 30~60 min）
- 歷史月份薪資是否回補由主任人工觸發，不在此 PRD 範圍

---

## 2026-04-18 — Feature：主任/老師端臨時補建堂次點名（PRD dff8695b）

### Problem
主任透過 LINE / 口頭改排課但未同步進系統，老師點名時在「今日待點名堂次」找不到學生，或時段與現場不符。既有「點此回報」只能通知主任回補，老師當下無法自行完成點名。

### Change（前端純改動，後端零異動）

**主任端（`frontend/src/pages/AttendancePage.vue`）**
- 「手動登記」區塊新增模式切換 Tab：「**已排課程學生**」/ 「系統外人員」
- 已排課程模式：選學生後動態載入該學生的課程下拉（`GET /api/v1/student-classes?student_id=X`）並帶出老師/科目；填入日期、開始/結束時間、點名狀態後送出 `POST /api/v1/attendance`（無 `ClassSessionID`），後端自動建立 `ClassSession` + `StudentSignIn`
- 送出成功後今日出缺勤紀錄列表即時局部更新

**老師端（`frontend/src/pages/AttendancePage.vue`）**
- 「有課不在列表中？」區塊新增「**補建並點名**」按鈕（藍色，保留「點此回報」按鈕不受影響）
- 點擊後在原位以 `max-height` 動畫（≤150ms）展開 inline 表單：課程（SearchableSelect，自動帶入自己任教的課程）、時間段（預設當前整半小時後時段）、點名狀態
- 送出走 `POST /api/v1/attendance`（`mark_mode: 'arrival'`，無 `ClassSessionID`），成功後收合表單、重置欄位、局部刷新今日出缺勤紀錄、顯示 toast

**UI/UX 精緻化**
- 課程下拉載入中顯示 shimmer skeleton，無 layout shift
- 結束時間 ≤ 開始時間時 inline 顯示錯誤訊息，不觸發 submit
- 送出中按鈕顯示 spinner 且 disabled，防重複送出
- 響應式：≤768px 表單單欄；≤480px 兩按鈕換行
- 觸控目標 ≥ 44px

### 注意
- 此功能 **不新增後端 API**，完全復用 `POST /api/v1/attendance` 的 nullable `ClassSessionID` 路徑
- 老師端課程清單透過 `GET /api/v1/student-classes`（後端自動以 `auth_teacher_id` scope）取得，無越權風險
- 補建成功的 `ClassSession` 已有有效 `StudentSignIn`，不會出現在「待補點名（已結束節次）」列表

## 2026-04-18 — Enhancement：代課 + 換時 UI 即時更新（PRD f0cce4d5 P2）

### Problem
合併代課+換時送出後，課程管理頁雖然最終會由 `loadCourses()` 重載並顯示新時段，但重載期間（數百 ms 至 1s）UI 仍顯示舊時段，主任看到「送出後時段沒變」的心理錯覺。

### Change
- `composables/course-management/useCourseSessionsDisplay.js::updateLocalSessionRow`
  - 改為「只覆寫 payload 中實際提供的欄位」（避免過去只帶 `teacher_id`/`teacher_name` 的 patch 誤把 `status` / `start_time` / `end_time` 蓋成 undefined 的潛在 bug）。
  - 白名單新增 `session_date`、`teacher_id`、`teacher_name`、`learning_record_status`、`attendance_sign_in_at`。
- `pages/CourseManagement.vue::onSubstituteV2Submit`
  - 在顯示 Toast 之前，立刻呼叫 `updateLocalSessionRow` 把代課老師寫入本地 row；若為合併模式（`rescheduled === true` 或 `operation_type === 'substitute_with_reschedule'`），一併寫入新 `session_date`/`start_time`/`end_time`，讓列表即時反映新時段。
  - `onUndo` 同步立即還原本地 row（代課回原老師 + 若含換時則還原原時段），再 `loadCourses()` 作為伺服器對帳。
- `loadCourses()` 保留作為最終 reconciliation，避免任何本地/伺服器漂移。

### 業界參照
- Linear / Notion：樂觀更新（optimistic UI）先寫入本地狀態、背景做伺服器同步，失敗再回滾。
- Google Calendar：事件拖拉後視覺立即更新，伺服器回應才決定是否回滾。

---

## 2026-04-18 — Feature：代課 + 換時間合併操作（PRD f0cce4d5）

### Problem
主任處理「老師請假、需要同時換代課老師並調整上課時間」的情境時，現行流程必須分兩步：先調課、再換代課（或反過來）。兩步操作存在：（1）衝堂驗證盲區（先代課再調課時，新時段不會再驗衝堂）；（2）部分失敗風險（兩步之間網路中斷落在半完成狀態）；（3）操作效率低（步驟多、認知負擔高）。

### Change
**Backend**
- `ClassSessionController::substitute` 新增三個選填參數：`new_date` / `new_start_time` / `new_end_time`
  - 三欄必須同填同省（FR-003），只填一部分回 422；新日期不可為過去；驗證 start < end。
  - 有填時，**衝堂檢查（跨分校 + 同分校）以新時段為準**（FR-004），不會被繞過。
  - `runSubstituteTransaction` 內新增合併路徑：在同一 DB transaction 內更新 `ClassSession.{SessionDate,StartTime,EndTime}`、同步 `LearningRecord` 時間欄位、遷移 `schedules` 既有 rescheduled/scheduled 列至新日期與新時段，再套用代課老師；全部成功或全部回滾（FR-005）。
  - API 回應新增 `operation_type`（`substitute` / `substitute_with_reschedule`）、`rescheduled`、`session_date` / `start_time` / `end_time`（生效時段）、`original_session_date` / `original_start_time` / `original_end_time`。
  - 稽核 log 新增 `rescheduled_from_date` / `rescheduled_from_start_time` / `rescheduled_to_date` / `rescheduled_to_start_time`。
- `SubstituteService::createParentNotification`
  - 合併換時模式：Title 改為「{student} {subject} 課程異動通知」，Body 改為「原定 {old_date} {old_start}~{old_end} 的課程已調整至 {new_date} {new_start}~{new_end}，由 {new_teacher} 代課。」（FR-006）。
  - 純代課維持「代課通知」Title 與原 Body 格式（回歸）。
  - Payload 新增 `operation_type` / `original_session_date` / `original_start_time` / `original_end_time` 供 Undo 還原使用。
  - 冪等策略改為「既有通知 → 更新 Title/Body/Payload」，確保合併換時後資訊一致。
- `SubstituteController::undo`
  - 從通知 Payload 讀取 `original_session_date/start/end`，若為合併操作則在同一交易內還原 `ClassSession` 時間、同步還原 `LearningRecord` 時間欄位（FR-008）。
  - 純代課的 Undo 路徑不受影響（回歸）。
  - 回應新增 `restored_time` / `restored_session_date` / `restored_start_time` / `restored_end_time`。
- `SubstituteController::recent` 回應新增 `operation_type` 與 `original_session_date/start/end`，供儀表板顯示「含換時」chip（P1）。

**Frontend**
- `components/substitute/SubstituteTeacherPickerModal.vue`
  - 原因欄位上方新增可展開的「同時調整上課時間（選填）」disclosure 區塊：新日期 + 新開始時間選單（07:00~22:30 / 30 分鐘檔次）+ 唯讀新結束時間（依原課時長自動計算）。
  - 日期或開始時間變更時，即時重新查詢所有老師的可用性（以新時段為準），race condition 由請求 token 防護。
  - 展開/收合使用 `transition: max-height 0.2s ease`；重查期間老師清單上方顯示細進度條（linear progress bar）而非整體 skeleton 刷新，避免 layout shift。
  - Inline 錯誤措辭採正向引導（「請同時填寫新日期與新開始時間」）；送出按鈕文字在合併模式改為「確認代課 + 換時」，純代課維持「確認代課」。
  - 底部送出區預留 summary 行空間（避免 layout shift），有填新時段時顯示「將調整至 {new_date} {new_start}~{new_end}，由 {老師} 代課」。
  - 響應式：≤480px 時欄位垂直排列，觸控目標 ≥ 44px。
- `pages/SmartCalendar.vue` + `pages/CourseManagement.vue`
  - 代課提交流程傳遞 `new_date` / `new_start_time` / `new_end_time` 至 API；Toast 標題在合併模式顯示「已指派 X 代課並調整時間」，描述顯示新時段。
- `components/substitute/RecentSubstitutesCard.vue`
  - `operation_type === 'substitute_with_reschedule'` 時於代課老師名稱右側顯示灰色 chip「含換時」，tooltip 顯示原時段與新時段對照。

**Test**
- `tests/Feature/SubstituteWithRescheduleTest.php` 新增 8 組 Feature Test：合併成功、新時段跨分校衝堂 422、新時段同分校衝堂 409、Undo 還原時間、純代課回歸、半填欄位 422、過去日期 422、儀表板 `operation_type` 欄位。

### 業界參照
- ClassDojo / Seesaw：複合異動以「單一通知涵蓋全部變更」不拆兩次推播。
- Google Calendar / GitHub：複合操作以視覺 chip 標記操作類型，不增加獨立列。
- Stripe：關鍵狀態變更以單一 atomic transaction 包住，杜絕半成功風險。

---

## 2026-04-18 — Enhancement：代課 Undo 時間窗設定化（Gmail Undo Send 模式）

### Problem
代課 Undo 時間窗原為硬編碼（UI 5 秒 / 後端 300 秒），PRD 第 13 節開放問題 #3 留待後續處理。升級需求：允許全域調整但仍防呆、對齊業界成熟做法。

### Change
- **後端**
  - 新增 `SubstituteController::{ALLOWED_UNDO_WINDOWS, resolveUiUndoWindow(), resolveServerUndoWindow()}`；單一事實來源 `SystemSetting` key=`substitute.undo_window_seconds`，預設 5 秒。
  - Server grace = UI 值 + 60 秒，容忍網路/時鐘偏差。
  - 新增 `GET/PUT /api/v1/system/settings/substitute-undo`；GET 開放 director+、PUT 僅 `super_admin`。
  - `ClassSessionController::substitute` 回應新增 `undo_window_seconds` 欄位，`undo_deadline_ms` 改為 `now + server_window * 1000`。
- **前端**
  - `lib/substituteApi.js` 新增 `getUndoSetting()` / `setUndoSetting()`。
  - `SmartCalendar.vue` 取 `json.undo_window_seconds * 1000` 傳給 `ToastWithUndo.show({ durationMs })`；無回應時 fallback 5000。
- **測試**
  - `SubstituteUxV2Test` 新增 3 組：GET 預設、PUT super_admin only、回應 reflect 設定且逾 server window 回 410。

### 業界參照
- Gmail Undo Send（5/10/20/30 秒固定檔次、server-side 保留寬限）
- Stripe idempotency window（60 秒 grace）
- Slack scheduled message undo（client timer + server deadline）

---

## 2026-04-18 — Feature：代課流程 UX 優化（PRD 9c058f19）

### Problem
單堂代課流程需在 `<select>` 中逐個比對老師，主任無法一眼看到：衝堂、是否綁分校、是否授此科目；老師請假需主任逐堂操作，操作成本高；家長缺乏代課通知，經常問「今天誰上？」；同時若代課老師在**其他分校**同時段另有課，系統未攔截 → 物理不可分身衝堂會漏掉。

### Change
**Backend**
- 新增 `App\Services\SubstituteService`：集中 `collectTeacherBusySlots()`、`detectCrossCampusConflict()`、`teacherBoundToAny()`、`createParentNotification()`、`voidParentNotificationForSession()`。
- 擴充 `ClassSessionController::substitute`：
  - 放寬 teacher binding → 綁任一 operator 管理分校即可（FR-003）。
  - 寫入前做跨分校（物理）衝堂檢查；同分校衝堂仍走既有 `ScheduleGuardService`（FR-004a）。
  - 同一交易建立 `Notifications.Type=substitute` 家長通知（FR-010，冪等：以 `SourceKey=substitute:{sessionId}` 為界）。
  - 回應新增 `notification_id`、`cross_campus`、`undo_deadline_ms`。
- 新增 `SubstituteController`：
  - `GET  /api/v1/teachers/{id}/availability` 回跨分校忙碌時段（只含 start/end/campus_id）。
  - `POST /api/v1/class-sessions/{id}/substitute/undo` 5 分鐘內可撤銷；回復 schedules / LearningRecord.TeacherID；將通知 `ResolvedAt` 標記並於 Payload 加 `voided` 旗標（FR-011）。
  - `GET  /api/v1/substitutes/recent?branch_id=` 近 7 天代課記錄，受 `auth_campus_ids` 隔離（FR-012）。
- 新增 `TeacherLeaveController`：
  - `POST /api/v1/teacher-leaves/preview` 區間 ≤30 天、最多 50 堂預覽。
  - `POST /api/v1/teacher-leaves/batch-substitute` 預檢 + 整批交易（atomic=true 預設）。

**Frontend**
- 新增 `src/lib/substituteApi.js`、`components/substitute/{ToastWithUndo,SubstituteTeacherPickerModal,TeacherLeaveBatchModal,RecentSubstitutesCard}.vue`。
- `SmartCalendar.vue` 以 `VITE_FEATURE_SUBSTITUTE_V2` 旗標切換新舊代課 Modal，並新增「🗓️ 老師請假」批次按鈕。
- `DirectorDashboard.vue` 加入「近 7 天代課記錄」卡片。

### Test
新增 `backend/tests/Feature/SubstituteUxV2Test.php`（8 組）涵蓋單堂成功/跨分校阻擋/Undo/Preview/Batch 成功/Batch 衝堂回滾/Availability 欄位白名單/Recent 分校隔離。舊有 14 組 `SubstituteTeacherTest` 全數通過。

### Risk & Rollback
- 前端以 feature flag 控制，預設關閉；若發現新 Modal 嚴重問題，設 `VITE_FEATURE_SUBSTITUTE_V2=false` 重建即回退。
- 後端 API 為新增路由；`substitute` 原有欄位全部保留，新欄位不影響舊 caller。

---

## 2026-04-18 — Feature + Bugfix：多科共用方案月結制支援 & 建立後課程管理提示改善

### Problem
1. **多科共用方案月結制無法建立**：大直主任反映建立多科共用方案後在課程管理看不到課程。根本原因是 `createMultiSubject` 後端驗證 `total_sessions` 為必填，但前端月結模式（`payment_type=monthly`）不送 `total_sessions`，導致 422 靜默失敗。
2. **建立成功後 toast 消失過快**：成功 toast 顯示在 `UniversalClassScheduler` modal 內部；modal 關閉時 toast 一起消失，主任看不到成功通知。
3. **月結制財務報表 & 提醒缺少方案層聚合**：月結多科方案應在月結報表顯示為單一方案行，在繳費提醒顯示為方案層行，成員課程（Charge=0）不應重複列出。

### Root Cause
- `createMultiSubject` 驗證：`total_sessions` 以 `required` 標記，月結方案無法通過。
- `handleUniversalBackfillSuccess` 在 modal 關閉後才 reload，但 toast 屬於被 unmount 的 modal 元件。
- `FinanceController::branchMonthlyTuition` 及 `AlertController::tuition` 均未處理 `billing_mode='date'` 的 CoursePackage。

### Change

**Backend**
- `CoursePackageController::createMultiSubject`：
  - 新增 `payment_type`（nullable, session/monthly）與 `settlement_day` 欄位驗證。
  - `total_sessions` 改為 `nullable`；session 制仍需填寫，monthly 制不需。
  - monthly 額外校驗：至少 2 科、rate > 0、settlement_day 必填。
  - monthly 方案：`CoursePackage.billing_mode='date'`、`settlement_day` 寫入、`StudentClass.ScheduleMode='date'`、`total_sessions=0`。
- `CoursePackageController::update`：新增 `settlement_day` 與 `rate` 欄位；若更新這兩個欄位，自動 cascade 至所有 PackageID 相同的 StudentClass 成員（`settlement_day`、`Rate`）。
- `CoursePackage` model：新增 `billing_mode`、`settlement_day` 至 `$fillable`；新增 `BILLING_MODE_SESSION='count'`、`BILLING_MODE_MONTHLY='date'` 常數。
- `FinanceController::branchMonthlyTuition`：識別 `billing_mode='date'` 的月結方案，在回傳 data 中以 `row_type='package'` 的方案行顯示（含 `package_id`、`monthly_sessions`、`monthly_tuition`）；排除方案成員課程避免重複行。
- `AlertController::tuition`：加入月結方案層級提醒；聚合所有成員出席堂數計算當月 charge；方案成員（Charge=0）從個別 date-mode 提醒中排除。

**Frontend**
- `CourseManagement.vue`：
  - 新增 `creationSuccessBanner` reactive state 及 `showCreationBanner()` helper。
  - `handleUniversalBackfillSuccess(result)` 接收 `emit('success', result)` 的 payload；reload 後顯示「方案已建立，共 N 個科目已加入課程管理列表」banner（6 秒自動消失，可手動關閉）。
  - Banner 使用綠色樣式（`#d1fae5` background）置於課程列表上方，確保主任不會錯過成功通知。

### Regression Notes（禁止反悔清單）
- `createMultiSubject` 仍對堂數制方案驗證 `total_sessions >= 1`；此路徑未改變。
- `CoursePackageController::update` 的 cascade 僅在 `settlement_day` 或 `rate` 有提供時才執行；不影響其他欄位。
- `branchMonthlyTuition` 月結方案行只在 `billing_mode='date'` 且 `stop=false` 時顯示；堂數制方案及一般課程不受影響。

## 2026-04-18 — Bugfix：代課 + 調課組合操作後課程管理顯示錯誤老師（PRD `3972a088`）

### Problem
當一堂課在短時間內「先代課後調課」或「先調課後代課」（老師請假情境最常發生），`CourseManagement` 的 tooltip「授課老師」欄仍顯示原任老師姓名，而非實際代課老師。具體案例：
- 游家豫數學課（StudentClass=143）4/22：主任先指定陳章華代課，再將時段調至週三 → 課程管理顯示鄭翔祐（原任老師）
- 先前 4/18 吳宏逸案例（§2026-04-18 Substitute hotfix）已修一部分，但 SmartCalendar 拖曳調課路徑仍會重複觸發此 bug

### Root Cause
`SmartCalendar.submitReschedule` 與 `LearningRecordController::syncSchedulesForRescheduledSession` 對「代課 scheduled 行 + 調課新建 scheduled 行」的協作缺少約束：
1. **前端雙寫**：submitReschedule 無視既有代課 scheduled 行，無條件 `INSERT` 第二筆 `schedules{ status: scheduled, teacher_id: 原任老師, original_schedule_id: anchor }` 到新日期
2. **後端 sync 僅更新不去重**：`syncSchedulesForRescheduledSession` 把既有代課行的 `schedule_date/start_time/end_time` 改為新值，但不處理同 anchor 下的其他重複行
3. **`ClassSessionController::index` 子查詢以 `MAX(id)` 選代課老師**：因前端後寫入的 row id 較大，子查詢挑中的是「原任老師」那筆，導致 `teacher_name` 解析錯誤

### Change

**Backend**
- `backend/app/Http/Controllers/LearningRecordController.php::syncSchedulesForRescheduledSession`：移動代課行到新日期後（`Schedule::whereIn('id', $substituteIds)->update($updates)` 之後），以 `$substituteIds` 回推 `anchorIds`（`original_schedule_id`），接著刪除 `student_course_id = $classId AND schedule_date = $newDateOnly AND status = 'scheduled' AND original_schedule_id IN $anchorIds AND id NOT IN $substituteIds` 的殘留行；刪除後補寫 `Log::info('reschedule_session.duplicate_scheduled_rows_deleted', ['student_class_id', 'new_date', 'anchor_ids', 'deleted_ids'])` 稽核 log。五重 scope 確保不誤刪他課、他 anchor、非 scheduled 狀態、正在更新的代課行（FR-002）。

**Frontend**
- `frontend/src/pages/SmartCalendar.vue::submitReschedule`：取得 `originalId` 後，新增 `alreadySubstituted` 偵測——用 `exceptions.value.some(ex => ex.status === 'scheduled' && ex.original_schedule_id != null && String(ex.original_schedule_id) === String(originalId) && String(ex.student_course_id) === String(...))` 判斷該 anchor 是否已有代課 scheduled 行。若有，**完全跳過 payload2 的 Supabase insert**（包含 rollback 與衝突提示區塊），直接進入後端 `/reschedule-session` 呼叫，由 `syncSchedulesForRescheduledSession` 把既有代課行移到新日期。Fallback：`exceptions` 未載入或 row 缺 `original_schedule_id` 時照舊插入，由後端 FR-002 清理（FR-001）。

**Data Hotfix**
- 直接刪除 `schedules.id = 606`（游家豫 4/22 19:00 鄭翔祐 stale scheduled 行，orig_id=604），使 derived subquery 改選 id=605（陳章華）。刪除前以 `student_course_id=143 AND schedule_date='2026-04-22' AND teacher_id=17 AND status='scheduled' AND original_schedule_id=604 AND id=606` 五重條件驗證後才執行（FR-003）。

**Tests**
- `backend/tests/Feature/SubstituteReschedulesCombinationTest.php`（新檔）：4 個 case——先代課後調課（scheduled 行僅 1 筆且為代課老師）、先調課後代課（同）、注入重複 scheduled 行後呼叫 reschedule-session 驗證 FR-002 清除、無代課純調課回歸。Helper `doReschedule()` 內含 FR-001 的鏡像 guard，使兩道防線在 Feature Test 中同時驗證。

### Regression Notes（禁止反悔清單）
- **submitReschedule 必須偵測既有代課 scheduled 行並跳過重複插入**：不能回退為無條件插入 payload2；任何「為了簡化」的回退都會立刻重現此 bug。
- **syncSchedulesForRescheduledSession 的去重刪除 scope 必須維持五重條件**：（1）`student_course_id = $classId`（2）`schedule_date = $newDateOnly`（3）`status = 'scheduled'`（4）`original_schedule_id IN $anchorIds`（5）`id NOT IN $substituteIds`。不可省略任何一項——任一項缺失都可能誤刪他課/他 anchor/本次更新的目標行。
- **`$anchorIds` 必須來自 `Schedule::whereIn('id', $substituteIds)->pluck('original_schedule_id')`**：不可改用 `request('anchor_id')` 或其他外部輸入，否則會成為 IDOR。
- **`ClassSessionController::index` 子查詢仍以 MAX(id) 挑代課老師**：此邏輯本次未動；治本靠「防止重複行產生」，不是改查詢。若未來需改查詢，必須先重讀本條。

### 受影響檔案
- `backend/app/Http/Controllers/LearningRecordController.php`
- `frontend/src/pages/SmartCalendar.vue`
- `backend/tests/Feature/SubstituteReschedulesCombinationTest.php`（新）
- `docs/AI_REGRESSION_LESSONS.md`（新增 §2026-04-18 代課+調課組合）

---

## 2026-04-18 — Feature：課程回報管理分校隔離 & 老師回報「正確時間」（PRD `a7c91e3b`）

### Problem
1. **課表回報管理分校視野過寬**：`ScheduleDiscrepancyController::resolveCampusScope` 對多校主任回傳聚合 campus_ids，使「A/B 兩校都掛名的主任」在 A 分校畫面可看到 B 分校的老師回報（含學生姓名、時段爭議）。分校隔離完全依賴前端記得帶 `branch_id` query，前端漏帶就靜默退化為跨校聚合。
2. **老師回報「上課時間有誤」但無法指出正確時間**：`wrong_time` 類型只有「系統記錄的舊時段」與自由文字 `notes`，老師若知道應為 14:00–16:00，只能塞進備註或電話／LINE 另外告訴主任，導致處理循環拉長。

### Root Cause
- **分校隔離**：`resolveCampusScope` 對 director 角色在未帶 `branch_id` 時 `return $campusIds`（聚合）；未在後端強制「director 必須指定一個分校」。
- **缺少 corrected_time 欄位**：`schedule_discrepancies` schema 只有 `time_range`（原時段），沒有老師「建議修正為」的欄位，整個回報→修正循環缺一環。

### Change

**Backend**
- `ScheduleDiscrepancyController::resolveCampusScope`：新增 `requireBranch` 參數；`index()` 以 `requireBranch: true` 呼叫。director 未帶 `branch_id` 且 `auth_campus_ids` 多於 1 校時回 422 `請指定分校（branch_id 必填）`；單校 director fallback 為單校；跨校 `branch_id` 回 403；super_admin 保留 legacy 跨校視野（帶 `branch_id` 則降為單校）（FR-001/002）。
- 新增 migration `2026_04_19_100000_add_corrected_time_range_to_schedule_discrepancies.php`：`schedule_discrepancies` 新增 `corrected_time_range varchar(32) NULL`，放在 `time_range` 之後（FR-003）。migration 含完整 `down()`、`Schema::hasColumn` 冪等 guard。
- `ScheduleDiscrepancyController::store`：接受 `corrected_time_range`，內部 helper `normalizeCorrectedTimeRange` 做 trim / whitespace strip / HH:MM-HH:MM regex / 起訖順序檢查，非法回 422（FR-004）。`hasCorrectedTimeRangeColumn()` 守門——欄位不存在時 silent skip，讓前端早於 migration 完成的情境不會爆掉。
- `formatOne()` / `formatListRow()`：兩個 response formatter 都新增 `corrected_time_range`，使 `GET /index`、`GET /mine`、`GET /active-for-session` 都回傳此欄位。
- `ScheduleDiscrepancyNotifier::buildMessage`：若 `corrected_time_range` 有值，LINE 推播訊息於「時段」下方插入一行「正確時間應為：14:00-16:00」（FR-005）；欄位空或欄位不存在時沿用舊格式，不產生多餘空白行。
- `ScheduleDiscrepancy` model：`corrected_time_range` 加入 `$fillable`。

**Frontend**
- `components/ReportDiscrepancyModal.vue`：新增「正確時間應為（選填）」輸入框；`discrepancy_type` 切換到 `wrong_time` 時觸發 `nextTick` + `scrollIntoView` + `.focus()`（FR-006）。類型為 `wrong_time` 時外框改用主色 + 「建議填寫」tag；inline 格式驗證、非法時顯示「格式應為 HH:MM-HH:MM，例：14:00-16:00」；本地 `normalizeCorrected()` 與後端 regex 對齊，按「送出」前再驗證一次並只在合法時帶入 payload。
- `pages/ScheduleDiscrepancyPage.vue`：列表時段欄在有 `corrected_time_range` 時改為「10:00-12:00 → 14:00-16:00」對照（原時段灰色刪除線、正確時段主色粉底 pill）；桌面 expanded detail 新增獨立「老師建議修正為」區塊含一鍵複製 button（FR-007）；載入時 `hasBranch` computed 守門，`branchId` 為空時顯示「請先選擇要管理的分校」空狀態 + 說明文字，**不發 API 請求**（FR-002/008）。

**Tests**
- `tests/Feature/ScheduleDiscrepancyBranchIsolationTest.php`（新檔）：6 個 case 覆蓋單校 director 自動 scope、多校 director 422、多校 director 指定合法 branch_id、director 指定他校 403、super_admin 不傳 branch_id 跨校、super_admin 傳 branch_id 單校。
- `tests/Feature/ScheduleDiscrepancyCorrectedTimeTest.php`（新檔）：9 個 case 覆蓋合法儲存、whitespace tolerant、omit/null、non-time 字串 422、反序時段 422、超長字串 422、list response 含欄位、LINE 推播含「正確時間應為」行、留空時不新增該行。
- 既有 `tests/Feature/ScheduleDiscrepancyApiTest.php` 14 test 全綠（跨校 403 既有 regression 不受影響）。

### Regression Notes（禁止反悔清單）
- **分校隔離必須在後端強制**：director 若跨多校，呼叫 `GET /schedule-discrepancies` 沒帶 `branch_id` 必回 422 而非聚合回列表——這屬資料最小化原則。不可只在前端加 guard 就算事。
- **`corrected_time_range` 屬使用者輸入**：儲存前必須走 `normalizeCorrectedTimeRange`（regex 限定數字/冒號/連字號；絕不可寬鬆接受自由文字）。
- **migration 必須含 `Schema::hasColumn` 冪等 guard**：這個檔先前有「schedule-discrepancies 路由靜默回退」事件（§2026-04-17），對此模組 migration 需特別防守。

### 受影響檔案
- `backend/app/Http/Controllers/ScheduleDiscrepancyController.php`
- `backend/app/Models/ScheduleDiscrepancy.php`
- `backend/app/Services/ScheduleDiscrepancyNotifier.php`
- `backend/database/migrations/2026_04_19_100000_add_corrected_time_range_to_schedule_discrepancies.php`（新）
- `backend/tests/Feature/ScheduleDiscrepancyBranchIsolationTest.php`（新）
- `backend/tests/Feature/ScheduleDiscrepancyCorrectedTimeTest.php`（新）
- `frontend/src/components/ReportDiscrepancyModal.vue`
- `frontend/src/pages/ScheduleDiscrepancyPage.vue`

---

## 2026-04-18 — Bug Fix：調課代課課表顯示錯誤 & 老師端科目數視野控制（PRD `4a9edeae`）

### Problem
1. **調課＋代課後，原時段格仍顯示舊時間、代課老師無點名入口（Bug A）**：黃芝琳老師的學生吳宏逸 10:00–12:00 課程改為李維老師代課、時段挪到 17:00–19:00，`POST /api/v1/learning-records/reschedule-session` 回傳成功，但 `SmartCalendar` / `TeacherHomePage` 仍顯示 10:00–12:00，代課老師看不到新時段，無法點名。
2. **老師端看到所有同事科目數數字（Bug B）**：`SubjectUnitsPage.vue` 對老師角色完整展示他人的 `one_on_one_hours`、`share_pct`、`subject_count_*` 與分校 totals，屬公司內部敏感資訊。

### Root Cause
- **FR-001**：`LearningRecordController::rescheduleSession` 以 `whereDate('SessionDate', ...)` + `first()` 定位 ClassSession，同日多時段時會誤選錯 row，導致更新到另一個時段（或原封不動）。
- **FR-002/003**：前端 `useSessionEditFlow.js` / `useRescheduleAndMakeup.js` / `SmartCalendar.vue` 三處皆使用 `.catch(() => {})` 靜默吞掉 `reschedule-session` 失敗錯誤，使用者看到「調課成功」toast 但 API 其實回 422/500。
- **FR-004**：ClassSession 更新後，`schedules` 代課行（`status='scheduled'` + `original_schedule_id IS NOT NULL`）的 `start_time/end_time/schedule_date` 未同步，造成 `ClassSessionController::index` JOIN 出現時段錯位，代課老師週課表讀不到新格子。
- **FR-005**：`SmartCalendar::findSessionRowForCell` 在 `is_exception` 情況下若時間沒 100% 對齊就 fallback 到「舊 StartTime 格」，導致點名 badge 與新格脫鉤。
- **FR-008/009**：`FinanceController::subjectUnits` 對所有角色回傳完整 `teachers[].*` 數字欄位與 `totals`，前端 `SubjectUnitsPage.vue` 不分角色全部渲染。

### Change

**Backend**
- `LearningRecordController::rescheduleSession`：新增 `old_start_time` 參數，與 `old_date` / `student_class_id` 三鍵精確定位 ClassSession；提供時若找不到完全匹配 row 回 422（FR-001）。ClassSession 更新與 LearningRecord 對齊包入 `DB::transaction`，保證失敗完整 rollback。
- `LearningRecordController::syncSchedulesForRescheduledSession`（新增 private helper）：在同一 transaction 內同步更新對應代課 schedules row 的 `schedule_date/start_time/end_time`，scope 嚴格限於 `status='scheduled'` + `original_schedule_id IS NOT NULL` 的「代課行」——`rescheduled` 錨點行（原時段歷史紀錄）刻意不動（FR-004）。
- `FinanceController::subjectUnits`：對 `auth_role='teacher'` 的請求，(a) 他人的 `one_on_one_hours/one_on_two_hours/one_on_three_hours/tutoring_hours/total_hours/subject_count_with/subject_count_without/share_pct` 遮蔽為 `null`、`level_breakdown` 清空為 `[]`，(b) 不回傳 `totals` / `level_breakdown_totals`，(c) 每筆 teacher row 新增 `rank`（1-based int）、`is_self` 旗標，response 頂層加 `total_teachers`（FR-008）。主任/ super admin 回傳保持不變。

**Frontend**
- `useSessionEditFlow.js` / `useRescheduleAndMakeup.js` / `SmartCalendar.vue`（三處）：移除 `.catch(() => {})` 靜默吞錯；調課 API 請求新增 `old_start_time: form.start_time` payload（FR-002）；失敗時以 `alert()` 顯示後端 `message`（FR-003）。
- `SmartCalendar::findSessionRowForCell`：`is_exception` row 的匹配邏輯加入 fallback——先嘗試 date + start_time 精準對齊，無命中時 fallback 同日任一 session row，確保點名 badge 不在 time-sync 邊界條件下消失（FR-005）。
- `TeacherHomePage.vue`：無需修改。資料源 `ClassSession.StartTime` 在 FR-004 完成後會自動反映新時段（smoke test 已驗證，OQ-01 結案）。
- `SubjectUnitsPage.vue`（老師視野）：
  - `v-if="!isTeacherView"` 隱藏「Summary Cards」、「Subject-count calculation」、`<tfoot>` totals 行。
  - 新增「Teacher-only helper card」顯示「你的排名是第 X 名（共 Y 名）」。
  - 老師表格新增「排名」欄（左側）；自身 row 加 `.self-row`（`rgba(primary, 0.08)` 高亮）與「(你)」tag；他人數字欄以 `displayNumber()` helper 輸出 `—`（`text-muted` 色）（FR-009）。

**Tests**
- `tests/Feature/RescheduleSessionPrecisionTest.php`（新增，5 tests）：同日雙時段精確定位、`old_start_time` mismatch 回 422、缺省時 fallback by date、atomic transaction 同步代課 schedules row、無代課情境不受影響。
- `tests/Feature/FinanceSubjectUnitsTest.php`：(a) 原 `test_teacher_sees_branch_wide_subject_units` 改寫為驗證 teacher 視野——自身 row 數字可見、他人 row 數字 null、無 `totals` / `level_breakdown_totals`、`rank` / `total_teachers` 存在；(b) 新增 `test_director_view_is_unaffected_by_teacher_redaction` 確保主任/ super admin 視野完全不受影響。
- 專案既有 `SubstituteRescheduleRegressionTest` 全綠（6 tests），驗證 `rescheduled` 錨點行 schedule_date/start_time **不被** sync helper 碰觸。
- 測試矩陣：`RescheduleSessionPrecisionTest`（5）、`SubstituteRescheduleRegressionTest`（6）、`FinanceSubjectUnitsTest`（3）、`SmartCalendar*`（0）共 14 測試全綠；全站測試較本次異動前多 30 個通過、未新增失敗。

### Regression Notes
- `syncSchedulesForRescheduledSession` 僅更新 substitute rows（`original_schedule_id IS NOT NULL`），`rescheduled` anchor row 保留在原時段，downstream 歷史/報表不受影響。
- `SmartCalendar::findSessionRowForCell` 的 fallback 僅在 `is_exception=true` 路徑生效，非例外 cell 的 session 匹配行為完全不變。
- `SubjectUnits` 後端遮蔽發生於 response 組裝末段，前端無法透過 query string 或 header 繞過（`auth_role` 來自 `AttachAuthUser` middleware，非 request body）。

### Affected Files
- `backend/app/Http/Controllers/LearningRecordController.php`
- `backend/app/Http/Controllers/FinanceController.php`
- `frontend/src/composables/course-management/useSessionEditFlow.js`
- `frontend/src/composables/course-management/useRescheduleAndMakeup.js`
- `frontend/src/pages/SmartCalendar.vue`
- `frontend/src/pages/SubjectUnitsPage.vue`
- `backend/tests/Feature/RescheduleSessionPrecisionTest.php`（新增）
- `backend/tests/Feature/FinanceSubjectUnitsTest.php`
- `docs/CHANGELOG.md`
- `docs/AI_REGRESSION_LESSONS.md`

## 2026-04-18 — Bug Fix：方案剩餘堂數、月學收結算課程、薪資回歸、繳費狀態、課表回報 404（PRD `多 Bug 修正批次_4a9edeae`）

### Problem
1. **方案（CoursePackage）剩餘堂數不正確（FR-001）**：共用方案 18 堂，數學 7 + 物理 8 + 化學 8 = 23 堂已上，顯示「剩餘 12 堂（方案共用）」，應為 0。根因：`PackageSessionLedger` 快照值未即時反映，`StudentClassController::index` 讀取快照而非動態聚合。
2. **月學收漏算已結課課程（FR-002）**：`branchMonthlyTuition` / `branchMonthlyTuitionExport` 含 `->where('Stop', 0)` 過濾，已結課但當月有出席堂次的課程被排除在學收報表外。
3. **兼職薪資以實際時長計算（FR-005/FR-006 回歸）**：`calcHours` 優先順序被 Claude Code 翻回「實際時長優先」，違反「契約排定時長為準」的業務共識。`buildConcurrencyBonusMap` 的 interval end 也被還原為實際 EndTime。
4. **繳費狀態 Paid=0 強制回寫（FR-007 回歸）**：`SessionDeductionService::recomputeCounters` 內 `RemainingSessions <= 2 → Paid=0` 三行被 Claude Code 重新寫入，再次干擾財務狀態。
5. **課表回報管理 HTTP 404（FR-008）**：主任開啟「課表回報管理」回 404，根因為 7 條 `schedule-discrepancies` 路由被 Claude Code 靜默回退（路由缺失，Controller 與 Migration 均存在）。

### Change

**Backend**
- `StudentClassController::index`：改用 `SessionDeductionService::batchObservedUsedSessions` 動態聚合所有方案成員的已上堂次，覆寫 `CoursePackage.used_sessions` / `.remaining_sessions`（FR-001）。遵守 CQRS：批次載入全方案成員，不受當頁分頁截斷。
- `FinanceController::branchMonthlyTuition` / `::branchMonthlyTuitionExport`：移除 `->where('Stop', 0)` 過濾（FR-002）；API response 新增 `is_stopped: bool` 欄位；CSV 匯出加「課程狀態」欄（進行中／已結課）。
- `FinanceController::calcHours`：優先順序恢復為「`resolveSessionDurationForWeekday` → `SessionDuration` → 實際 StartTime/EndTime → 2.0h default」（FR-005）；新增 `contractedDurationMinutes` private helper；更新三處呼叫端同步傳 `$sessionDate`。
- `FinanceController::buildConcurrencyBonusMap`：interval end 恢復使用 `contractedDurationMinutes` 計算（FR-006）。
- `SessionDeductionService::recomputeCounters`：移除 `if (RemainingSessions <= 2) { Paid = 0; }` 三行（FR-007）。
- `routes/api.php`：補回 7 條 `schedule-discrepancies` 路由，靜態路由（`/my`、`/summary`、`/active-for-session`）置於動態路由（`/{id}`）之前；修正方法映射（`mine`、`updateStatus`）（FR-008）。

**Frontend**
- `TuitionReportPage.vue`：`is_stopped=true` 列顯示「已結課」badge 與降低 opacity（`tr-stopped-row` class）；月份選擇器下方加入 OQ-04 disclaimer「歷史月份數字以實際出席記錄為準，如有補登將即時反映」；空狀態加強（標題 + 說明 + CTA）（FR-004、OQ-03、OQ-04）。

**Tests**
- `tests/Feature/RouteRegistrationTest.php`（新增）：對 7 條 `schedule-discrepancies` 路由做存在性斷言 + 靜態路由在動態路由之前的順序斷言，防止 Claude Code 再次靜默回退路由。
- `tests/Feature/ParttimePayrollTest.php`：修正 8 個測試的預期值，對齊 v1.4 tie-break 邏輯（主導者 lr_id 最小才得加給，非主導者重疊段扣基礎薪）。
- 測試矩陣全綠：`BranchMonthlyTuitionHistoricalTest`（7）、`ParttimePayrollTest`（25）、`PayrollConcurrencyTest`（8）、`StudentClassPaidStatusTest`（12）、`ScheduleDiscrepancyApiTest`（14）、`RouteRegistrationTest`（8）。

### Regression Notes
- `TuitionAlertsApiTest` 中 9 個測試失敗為**本次修改前即存在**的既有問題，非本次引入。
- `alerts/tuition` 的 `Stop=0` 過濾**不受本次異動**（催繳語意不同，已結課課程不應出現在催繳清單）。
- 方案批次成員查詢（`StudentClass::whereIn('PackageID', $packageIds)`）透過 `$packageIds` 來源的 campus-filter 隱性隔離，無跨校資料洩漏。
- `schedule-discrepancies` 所有路由均保有 `require_campus` 分校隔離。

### Affected Files
- `backend/app/Http/Controllers/StudentClassController.php`
- `backend/app/Http/Controllers/FinanceController.php`
- `backend/app/Services/SessionDeductionService.php`
- `backend/routes/api.php`
- `backend/tests/Feature/RouteRegistrationTest.php`（新增）
- `backend/tests/Feature/ParttimePayrollTest.php`
- `frontend/src/pages/TuitionReportPage.vue`

---

## 2026-04-17 — Bug Fix：建立課程繳費日期失效（PRD `建立課程繳費日期失效修正_930095c1`）

### Problem
使用者回報：「我再輸入課程明明就有輸入繳費日期，建立完還未繳費」。主任在「新增課程」填入「繳費日期」（`paid_at`）後，課程清單的繳費狀態仍顯示橘色「未繳費」，必須再手動點「切換繳費狀態」改為「已繳費」。對 1–2 堂短期課程或補登導致剩餘堂數 ≤ 2 的情境，bug 每次必現。

### Root Cause
`SessionDeductionService::recomputeCounters` 含有歷史殘留邏輯：

```php
if ($sc->RemainingSessions <= 2) {
    $sc->Paid = 0;
}
```

此服務在課程建立、簽到、請假、LR 核准、補登後都會被 `syncCounters` 呼叫。`EnrollmentService::store` 依 `paid_at` 正確寫入 `Paid=1` / `PayDate`，但 `syncCounters` 緊接著把 `Paid` 覆寫回 0。此設計把「教務統計（剩餘堂數）」錯誤地與「財務事實（繳費狀態）」耦合。

### Change

**Backend（`backend/app/Services/SessionDeductionService.php`）**
- 移除 `recomputeCounters` 中 `if ($sc->RemainingSessions <= 2) { $sc->Paid = 0; }` 三行（原 204–206 行）。
- 加入行內註解明列 `Paid` / `PayDate` 的三條合法寫入路徑（`POST /api/v1/class-sessions/batch` paid_at、`PUT /api/v1/student-classes/{id}` paid_at/payment_status、Invoice 付款），引用 `docs/AI_REGRESSION_LESSONS.md`。
- 其餘堂數計算邏輯（`UsedSessions` / `RemainingSessions`）完全不變。

**Tests（`backend/tests/Feature/`）**
- `StudentClassPaidStatusTest.php` 新增：
  - `test_sync_counters_does_not_clear_paid_when_remaining_lte_2`：`Paid=1` / `RemainingSessions=2` 呼叫 `syncCounters`，斷言 `Paid` 仍為 1、`PayDate` 未被清空。
  - `test_sync_counters_does_not_mutate_paid_for_unpaid_course`：反向守護，確保服務不會把 `Paid=0` 錯誤抬升為 1。
- `EnrollmentApiTest.php` 新增（透過 `POST /api/v1/class-sessions/batch`）：
  - `test_create_course_with_paid_at_shows_paid_status`：建立課程帶 `paid_at`，後續 `GET /api/v1/student-classes` 的 `payment_status` 必為 `"paid"`。
  - `test_create_short_course_with_paid_at_shows_paid`：1 堂短期課程（直接觸發舊 bug 路徑），`Paid=1`、`SessionCount=1` 皆正確落地。
  - `test_create_course_without_paid_at_stays_unpaid`：負向守護，確保未填 `paid_at` 的課程仍為未繳費。
- 全部 18 個 `StudentClassPaidStatusTest` 測試及 3 個新 `EnrollmentApiTest` 測試通過（`phpunit --filter 'StudentClassPaidStatusTest'`、`phpunit --filter 'test_create_course_with_paid_at_shows_paid_status|test_create_short_course_with_paid_at_shows_paid|test_create_course_without_paid_at_stays_unpaid'`）。

**Docs**
- `docs/AI_REGRESSION_LESSONS.md`：新增「§2026-04-17 — 建立課程繳費日期失效」禁止回歸項，明列 `recomputeCounters` 不得觸碰 `Paid/PayDate`、三條合法寫入路徑、守護測試位置、業務原則。
- 本 `CHANGELOG.md` 條目。

### Business Rule
- **剩餘堂數屬教務統計，繳費狀態屬財務事實，兩者不得互相推導**。
- `Paid` / `PayDate` 只能由三條專屬路徑寫入：課程建立 `paid_at`、課程編輯 `paid_at`/`payment_status`、Invoice 付款。
- 「堂數快用完」屬催繳提醒責任（`AlertController::tuition`），不得透過清 `Paid` 欄位實作。

### Deployment Notes
- 部署後**必須清 PHP-FPM opcache**：執行 `./deploy.sh` 或 `php artisan opcache:reset`。
- 無 DB migration，純後端邏輯移除，可 `git revert` 單一 commit 回滾，無資料損失風險。
- Smoke test：登入主任帳號 → 新增課程填入繳費日期 → 建立後課程清單應立即顯示「已繳費」（綠色）。
- **歷史資料汙染**：線上可能已存在若干 `PayDate IS NOT NULL` 但 `Paid=0` 的記錄（被此 bug 誤清）。部署後 3 個工作日內由 PM 執行 read-only audit query 統計筆數；< 50 筆請主任手動修，> 50 筆則另提 backfill script。

---

## 2026-04-17 — Bug Fix：兼職老師薪資改用契約排定時長（PRD `薪資改用契約時長_2e4e79e1`）

### Problem
使用者回報「兼職老師薪資那邊，兩小時的課會變成 1.5 小時」：主任在「備註 / 調整時段」把實際結束時間從 20:00 改成 19:30 記錄老師早離時，當月薪資就被縮短為 `350 × 1.5 = 525`（應為 `350 × 2 = 700`）。反方向（實際 17:00-20:00、契約 2h）則會多付 1 小時。與「備註調整不影響薪酬」的業務共識相悖，也與學收端「單堂費用固定不隨時長」完全不對稱。

### Root Cause
- `FinanceController::calcHours` 原本**優先**用 `LearningRecord.StartTime`/`EndTime`，`StudentClass.SessionDuration` 只在 actual 兩者皆缺時才 fallback。
- `buildConcurrencyBonusMap` 也用 actual `StartTime`/`EndTime` 作為重疊 interval 端點，讓併堂加給跟著被縮短／拉長。
- 兩個函式把「事實紀錄」欄位（實際時間）誤解為「薪資輸入」欄位。

### Change

**Backend（`backend/app/Http/Controllers/FinanceController.php`）**
- **`calcHours`**：翻轉優先順序 —
  1. `resolveSessionDurationForWeekday($sessionDate->isoWeekday())`（per-weekday duration，≥ 30min 才採用）
  2. `StudentClass.SessionDuration`（≥ 30min）
  3. 實際 `StartTime`/`EndTime`（fallback，契約資料缺失時）
  4. `2.0h` default
  - 簽名新增 `$sessionDate` 參數供 per-weekday 解析。
- **三處 `calcHours` 呼叫端**同步新增 `$r->SessionDate` / `$cs->SessionDate`：主薪資彙總、老師 session 清單、sessionRow builder。
- **新增 `contractedDurationMinutes($lr)` helper**：回傳契約排定分鐘數，failsafe（缺資料 / <30min 回 0）。
- **`buildConcurrencyBonusMap`**：interval end 改用 `startMin + contractedDurationMinutes($lr)`；僅在契約時長不可用時才 fallback 到 actual `$endMin`。併堂加給／扣款計算全面對齊契約時段。

**Tests（`backend/tests/Feature/`）**
- **`ParttimePayrollTest.php`**：
  - 新增 `test_contracted_duration_used_when_actual_shorter`：契約 2h / 實際 1.5h → 800（不得縮為 600）。
  - 更新 `test_concurrency_bonus_half_hour_overlap` 期望值 1225 → **1425**（B 實際 1.5h 但契約 2h）。
  - 更新 `test_concurrency_bonus_two_hour_overlap` 期望值 1300 → **1250**（A 實際 3h 但契約 2h）。
  - 更新 `test_concurrency_bonus_three_sessions_overlap` 期望值 500 → **1000**（3 × 實際 1h、契約 2h）。
- **`PayrollConcurrencyTest.php`**：既有 8 個 tie-break 守護測試皆用 actual = contracted（2h）的 fixture，期望值不變；v1.4 同層級 tie-break 行為繼續受守護。
- 全部 34 tests（`phpunit --filter 'ParttimePayrollTest|PayrollConcurrencyTest'`）通過。

**Docs**
- `docs/AI_REGRESSION_LESSONS.md`：新增「薪資以契約排定時長為準」禁止回歸項（含 calcHours 優先順序、buildConcurrencyBonusMap interval 規則、三個 `calcHours` 呼叫端必同步傳 `$sessionDate`、測試守護位置、業務原則）。
- 本 `CHANGELOG.md` 條目。

### Business Rule
- **薪資 = 契約排定時長 × 時薪**。「備註 / 調整時段」是事實記錄、不是薪資輸入。
- 與學收端「按堂計費＝固定堂費」原則一致：契約為準、實際為記錄。
- 未來若要引入「超時加給」或「提早下課扣錢」，必須做成獨立規則，不得修改 `calcHours` fallback 順序。

### Deployment Notes
- 部署後**必須清 PHP-FPM opcache**（見 §2026-04-17 opcache 條目）。可跑 `./deploy.sh` 或 `php artisan opcache:reset`。
- 本月已鎖定（`payroll_month_status = locked`）的薪資不會自動重算；需由超級管理員執行 `reopen` 流程後重新計算。

---

## 2026-04-17 — Bug Fix：單堂費用固定不隨時長縮放（PRD `單堂費用固定不隨時長_98ceeacf`）

### Problem
主任在「單堂檢視 → 備註 / 調整時段」調整上課時間後，「此堂費用」顯示的金額與排課設定的每堂金額不符。例如：合約設定每堂 NT$5,000，UI 預覽與後端寫入的金額卻會隨時段長度縮放（120→90 分變成 NT$3,750），連帶 `StudentClass.Charge` 被錯誤扣款。

### Root Cause
`ClassSessionController::syncSessionChargeForTimeChange` 對 `rate_unit='session'`（按堂計費）與 `rate_unit='hour'`（按時計費）一律套用比例縮放公式 `Rate × actual_minutes / SessionDuration`，違反業界慣例：按堂計費本應為固定堂費，時段調整只是記錄用途。前端 `SessionEditModal.chargePreview` 與 `SmartCalendar.currentSessionChargeDisplay` 也都走同套時間比例邏輯。

### Change

**Backend（`backend/`）**
- **`app/Http/Controllers/ClassSessionController.php::syncSessionChargeForTimeChange`**：分離 session / hour 兩個計費分支。
  - `session` mode：`session_charge` 固定為 `round(Rate)`，時段調整不觸發 `Charge` delta。
  - `hour` mode：保持 `Rate × actual_minutes / 60` 的時長比例計費，行為不變。
- **Backfill Migration（`database/migrations/2026_04_17_500000_backfill_session_charge_for_session_mode.php`，新增）**：
  - 找出所有 `rate_unit='session'`（含 null 預設）且 `session_charge != ROUND(Rate)` 的 `ClassSession`，批次將 `session_charge` 改回 `Rate`。
  - 以 `StudentClass` 為單位累加 delta 後回補 `Charge`。
  - 每筆異動同步寫入 `session_charge_backfill_log` 稽核表（含 old/new 值、delta、applied_at），支援 `down()` 回滾。

**Frontend（`frontend/`）**
- **`src/components/course-management/SessionEditModal.vue`**：
  - `chargePreview` computed 為 session mode 直接回傳 `rate`（固定），hour mode 保留原比例公式。
  - session mode 不顯示 delta chip（「高於／低於標準」），不觸發「明顯偏離」確認對話框。
  - 「此堂費用」下方提示文字依計費模式條件顯示：session mode 顯示「按堂計費：每堂固定金額，時段調整不影響收費」；hour mode 顯示「實際 X 分鐘 / 標準 Y 分鐘」。
  - 底部欄位說明同步依計費模式顯示對應語句。
- **`src/pages/SmartCalendar.vue`**：
  - `currentSessionChargeDisplay` 套用 Single Source of Truth：session mode 直接以合約 `rate_per_30min`（= `Rate`）作為「本堂費用」顯示，不讀取可能落伍的 `session_charge`；hour mode 保留 `session_charge` 優先的既有邏輯，fallback 標準費改為 `rate × duration_hours`。
  - `mapCourse`、`openEditCourse` 新增 `rate_unit` 欄位。

**Tests（`backend/tests/Feature/`）**
- **`ClassSessionChargeTest.php`**：session mode 的原有比例縮放測試重寫為「費用固定 = Rate、Charge 不變」。hour mode 測試保持不變。10 個測試全部通過。
- **`SessionChargeBackfillTest.php`**（新增）：2 個測試守護 —
  - session mode 錯誤值修正為 Rate、`Charge` delta 正確加總、稽核記錄存在。
  - hour mode 不受影響。
  - Migration 冪等性：重跑不重複產生 delta。

### Deployment（順序不可顛倒）
1. 先跑 `php artisan migrate`（執行 Backfill Migration 與 `session_charge_backfill_log` 建表）。
2. 後端程式碼 deploy + `php-fpm` 重啟（清 OPcache）。
3. `cd frontend && npm run deploy`。
4. 驗證 API health + 抽樣 SmartCalendar 費用顯示。

### Regression Guards
- session mode 不得再套用任何「時長比例縮放」邏輯；任何計費分支變更必須同步更新 `ClassSessionChargeTest`。
- SmartCalendar session mode 的「本堂費用」禁止回退成讀 `session_charge`，否則 Backfill 完成前的資料會再度顯示錯誤值。
- Backfill Migration 禁止在部署程式碼之後才執行；必須先修資料再上線程式。

### Affected Files
- `backend/app/Http/Controllers/ClassSessionController.php`
- `backend/database/migrations/2026_04_17_500000_backfill_session_charge_for_session_mode.php` (new)
- `backend/tests/Feature/ClassSessionChargeTest.php`
- `backend/tests/Feature/SessionChargeBackfillTest.php` (new)
- `frontend/src/components/course-management/SessionEditModal.vue`
- `frontend/src/pages/SmartCalendar.vue`

---

## 2026-04-17 — Bug Fix：歷史課程漏算當月學收（PRD `歷史課程排除學收修正_0ff99e77`）

### Problem
主任反映一旦學生課程被設為「歷史課程／結案」（`StudentClass.Stop = 1`），即使該課程在查詢月份內有 attended 堂次，也不會出現在「學收月報」頁面（`TuitionReportPage`），導致月學收試算金額偏低、對帳有缺漏。例：學生 4/15 上完最後一堂課即結案，4/1～4/14 的堂次完全從 4 月報表消失。

### Root Cause
- **`FinanceController::branchMonthlyTuition` 與 `::branchMonthlyTuitionExport`**：查詢 `StudentClass` 時以 `->where('Stop', 0)` 過濾，等同於「以當前狀態快照過濾歷史月份的事實」。這是語意錯誤——學收應以「當月實際出席堂次」為事實依據，課程結案屬後續行政動作，不應影響已發生的財務事實。

### Change
**Backend（`backend/`）**
- **`app/Http/Controllers/FinanceController.php::branchMonthlyTuition`**：移除 `->where('Stop', 0)` 過濾；INNER JOIN `sc_counts` 已確保只返回當月有堂次的課程，歷史課程無堂次者自動排除。
- **`app/Http/Controllers/FinanceController.php::branchMonthlyTuitionExport`**：同上修正，維持 CSV 與頁面一致。
- **API response 新增欄位**：course 行新增 `is_stopped: bool`（取自 `StudentClass.Stop`），讓前端區分進行中與歷史課程。
- **CSV 匯出新增欄位**：最右側新增「課程狀態」欄（進行中／已結課／月結方案），方便主任對帳識別。

**Frontend（`frontend/`）**
- **`src/pages/TuitionReportPage.vue`**：
  - `is_stopped=true` 的行顯示灰色 badge「已結課」（`#6B7280` 背景、白色文字），旁附 title 說明「此課程已結案，但當月曾有上課堂次，仍計入學收」。
  - 整列套用 `.tr-stopped-row { opacity: 0.65 }`（hover 提升至 0.85），保持視覺識別而不妨礙閱讀。
  - badge `white-space: nowrap` 防止小螢幕換行。

**Tests（`backend/tests/Feature/`）**
- **`BranchMonthlyTuitionHistoricalTest.php`**（新增）：7 個案例守護 —
  - FR-001 Happy：Stop=1 + 當月有堂次 → 出現在報表，金額正確、`is_stopped=true`。
  - FR-001 Edge：Stop=1 + 當月 0 堂 → 不出現。
  - FR-001 Edge：Stop=1 + 堂次在其他月份 → 查詢當月時不出現。
  - FR-003：Stop=0 + 當月有堂次 → `is_stopped=false`（回歸守護）。
  - FR-001：summary（total_students / total_sessions / total_tuition）正確加總已結案貢獻。
  - FR-002：CSV 匯出包含已結案學生、金額與「已結課」狀態文字。
  - 分校隔離回歸：他校歷史課程不進入本分校報表。

### Affected Files
- `backend/app/Http/Controllers/FinanceController.php`
- `frontend/src/pages/TuitionReportPage.vue`
- `backend/tests/Feature/BranchMonthlyTuitionHistoricalTest.php` (new)

### Regression Notes
- **`alerts/tuition`（催繳名單）**：仍保留 `Stop = 0` 條件（催繳語意為「進行中課程才需要被提醒」，與學收口徑不同），本次未動。
- **`finance/revenue` / `summary`**：原本就沒有 `Stop` 過濾，行為不變。
- **月結方案（`row_type: 'package'`）**：邏輯不受影響；方案成員若 Stop=1 但當月有堂次，仍正確彙整至方案聚合行。

### Known Considerations
- **快照式報表（長期架構）**：理想方案是將每月「有效課程清單」作為 snapshot 存檔，避免「查歷史月份卻用現時 Stop 值」的語意分歧；本次修正為最小範圍 Bug Fix，snapshot 架構留待 P2 評估（OQ-04）。
- **月結方案在月中結案**：若 `CoursePackage.stop=true`，月費計算的語意（按整月 vs. 按出席堂數）尚待產品定案；本次未動月結方案路徑（OQ-02）。
- **測試環境**：`phpunit.xml` 的 `APP_ENV=testing` 被 `bootstrap/cache/config.php` 快取覆蓋。跑測試前需先 `php artisan config:clear`（既有基礎建設問題，非本次造成）。

---

## 2026-04-17 — Bug Fix：請假後不再要求填評量（PRD `請假後不再要求填評量_5093e1fb`）

### Problem
老師在出缺勤點名將學生標記為「請假」後，進入評量頁（`LearningRecordsPage` 週 / 今日課表）仍看到該堂顯示「未填」並被要求填寫評量表。未填評量計數（`missingCount`）因此失真、老師被迫填寫明明已請假的堂次。

### Root Cause
1. **前端**：`LearningRecordsPage.vue::buildEvents` 僅依 `rawSession.learning_record_status` 判斷；請假 cascade (`CourseLeaveCascadeService::applyLeaveCascade`) 作廢 LR（`VoidedAt`）後，`GET /api/v1/class-sessions` LEFT JOIN 因 `VoidedAt IS NULL` 過濾帶不回 LR，前端將 `formStatus` fallback 為 `'missing'` → 顯示「未填」。未像 `SmartCalendar.vue::evalBadge` 以 `LEAVE_STATUSES` 比對堂次狀態。
2. **後端**：`LearningRecord::scopeExcludeLeaveSessionPendingReview` 只涵蓋 `ClassSession.Status in ['leave', 'leave_adjusted']`，漏掉 `excused`（與前端 `SESSION_STATUS_PRIORITY` / `LEAVE_STATUSES` 不對齊）。

### Change
**Backend（`backend/`）**
- **`app/Models/LearningRecord.php::scopeExcludeLeaveSessionPendingReview`**：`ClassSession.Status` 判斷清單加入 `excused`，與前端 `LEAVE_STATUSES` 對齊，確保「excused 點名 → leave 後 cascade」情境下 pending / changes_requested 評量不再出現於 `learning-records` 待審列表與 `NotificationSyncService` 通知。

**Frontend（`frontend/`）**
- **`src/pages/LearningRecordsPage.vue::buildEvents`**：引入 `LEAVE_STATUSES = {leave, leave_adjusted, excused}`；請假堂次 `formStatus='leave'`、取消堂次 `formStatus='cancelled'`、`fillLocked=true`、`recordId=null`（避免誤入 `canEdit` 分支），並附 `isLeave` / `isCancelled` flag 供模板樣式使用。
- **`scheduleStatusLabel`**：補上 `leave` / `leave_adjusted` / `excused` → `請假`、`cancelled` → `取消` 分支。
- **`scheduleActionLabel` / `openFromScheduleMaybe`**：請假、取消堂次永遠不可開啟評量 modal，按鈕顯示為「已請假 / 已取消」並 `disabled`。
- **週視圖樣式**：新增 `.ts-event-leave`、`.ts-event-cancelled`（灰藍調、`cursor: not-allowed`）、`.status-leave`（灰藍 chip）、`.status-cancelled`（刪除線）；新增 `.ts-week-allclear`「本週無待填評量」空狀態橫幅（當 `weekTotalMissingCount === 0` 且週內仍有課程時顯示）。
- **`weekDays`**：`missingCount` 因 `formStatus` 改動自動排除請假／取消堂次，無須調整計算公式。

**Tests（`backend/tests/Feature/`）**
- **`LearningRecordLeaveExclusionTest.php`**（新增）：5 個案例守護 scope —
  - `leave` pending 不出現
  - `leave_adjusted` pending 不出現
  - `excused` pending 不出現（**FR-005 回歸守護**）
  - `leave` + `approved` 仍可查（已核准保留）
  - `attended` pending 正常顯示（leave→attended 恢復路徑回歸）

### Affected Files
- `backend/app/Models/LearningRecord.php`
- `frontend/src/pages/LearningRecordsPage.vue`
- `backend/tests/Feature/LearningRecordLeaveExclusionTest.php` (new)

### Regression Notes
- **`deduplicateSessionsBySlot` 未動**（AI_REGRESSION_LESSONS §2026-04-15 守護）；僅在其輸出後附加狀態判斷。
- **leave → attended 恢復路徑**：`rawSession.status = 'attended'` 時 `isLeaveSession = false`，不進入本次攔截分支，評量可正常填寫（AI_REGRESSION_LESSONS §2026-04-13）。
- **主任端待審列表**：原本已有 `excludeLeaveSessionPendingReview` 保護，本次僅擴充 `excused` 覆蓋面，行為僅變更至「更不容易出現」方向，無反向退化。
- **TeacherHomePage「今日待填」**：`missingToday` 原僅計算 `scheduled` / `attended`，請假不會進入，本次未動。

### Known Considerations
- **前後端 `LEAVE_STATUSES` 語意一致性**：目前以常數分散於 `SmartCalendar.vue`、`LearningRecordsPage.vue`、`LearningRecord::scopeExcludeLeaveSessionPendingReview`。未來新增請假類 `ClassSession.Status` 時，三處必須同步更新；建議後續可抽成共用 composable（P2）。
- **根本解法（未納入本次）**：理想上 `ClassSessionController::index` 在請假堂次回傳 `learning_record_status: 'na'` / `'not_required'`，讓所有消費端由 server 統一驅動語意；本次以前端過濾為優先修復，降低 LEFT JOIN 相關回歸風險，根本方案留待後續 sprint 評估。
- **測試環境**：本專案 `phpunit.xml` 下 `RefreshDatabase::migrate:fresh` 需 `APP_ENV=testing` auto-confirm，目前本地環境仍會要求互動確認（為既有基礎建設問題，非本次修改造成；新測試檔遵循 `LearningRecordApprovalDeductionTest` 同一份 pattern）。

---

## 2026-04-17 — Feature：多科共用方案 月結制（PRD `多科共用方案月結制_251591de`）

### Problem
「多科共用方案」（`CoursePackage`）先前僅支援堂數制，長期穩定上課的學生每次用完都要重建方案；家長習慣的「每月固定繳費」無法套用到多科共用情境，主任只能對每個科目個別建月結單科課程再手動加總，流程繁瑣易出錯。

### Change
**Backend（`backend/`）**
- **`database/migrations/2026_04_17_300000_add_billing_mode_to_course_packages.php`**（新增）：在 `course_packages` 追加 `billing_mode`（`count` / `monthly`，預設 `count` 向後相容）、`monthly_fee`（unsigned int, nullable）、`settlement_day`（tiny int, nullable）。
- **`app/Models/CoursePackage.php`**：新增三欄位到 `$fillable`、`$casts`；新增 `BILLING_MODE_COUNT` / `BILLING_MODE_MONTHLY` 常數與 `isMonthlyBilling()` helper。
- **`app/Http/Controllers/CoursePackageController.php`**：
  - `createMultiSubject`：支援 `payment_type='monthly'`；月結方案 `monthly_fee`、`settlement_day` 必填驗證，成員 `StudentClass` 以 `ScheduleMode='date'`、`Charge=0`、`settlement_day` 繼承方案層建立。
  - `update`：允許修改 `monthly_fee` / `settlement_day`；月結方案 `settlement_day` 與 `Paid` 變動會 cascade 至成員 `StudentClass`（修正 R-001）。
  - `index` / `show` 回傳新增 `billing_mode` / `monthly_fee` / `settlement_day`。
- **`app/Http/Controllers/FinanceController.php::branchMonthlyTuition`**：月結多科方案以「方案行」聚合呈現（`row_type='package'`，金額=`monthly_fee`），成員 `StudentClass`（Charge=0）不獨立列出以避免重複計算；即使該月無出席，只要方案仍啟用就會出現在報表。
- **`app/Http/Controllers/AlertController.php::tuition`**：月結多科方案成員（Charge=0）從舊有 per-course 流程中過濾掉，改由新 private method `collectMonthlyPackageAlerts()` 以 `CoursePackage` 層為單位產生一條 `monthly_due_soon` 提醒（金額 = `monthly_fee`，按 `settlement_day` 倒數，`settlement_day=31` 於短月自動 fallback 到當月最後一天）。

**Frontend（`frontend/`）**
- **`src/components/UniversalClassScheduler.vue`**：建立多科方案 modal 新增「計費方式」切換（堂數制 / 月結制），月費 & 結算日欄位以 `pkg-fade-slide` 動畫展開；inline 錯誤訊息（月費 > 0、結算日 1–31）；切換計費方式時若已補登日期跳出確認 dialog；成功建立後以綠色右上角 toast 顯示 3 秒摘要；提交按鈕顯示 spinner、多科月結下少於 2 科時 disabled + tooltip。
- **`src/pages/TuitionReportPage.vue`**：新增方案行展開 / 收合（chevron 旋轉 150ms、淺藍背景 + `月結方案` 藍色 badge）；載入中改為多列 skeleton loader 避免 layout shift。

**Tests（`backend/tests/Feature/`）**
- **`CoursePackageMonthlyBillingTest.php`**（新增）：覆蓋 FR-001～FR-006 — 月結方案建立 happy path / edge（< 2 科、月費 0、結算日 32、缺月費）、出缺席扣帳寫入 `package_session_ledger`、`branchMonthlyTuition` 方案行聚合、`alerts/tuition` 方案級提醒 + 成員不重複出現、堂數制方案回歸不變、`update` cascade `settlement_day`。

### Affected Files
- `backend/database/migrations/2026_04_17_300000_add_billing_mode_to_course_packages.php` (new)
- `backend/app/Models/CoursePackage.php`
- `backend/app/Http/Controllers/CoursePackageController.php`
- `backend/app/Http/Controllers/FinanceController.php`
- `backend/app/Http/Controllers/AlertController.php`
- `frontend/src/components/UniversalClassScheduler.vue`
- `frontend/src/pages/TuitionReportPage.vue`
- `backend/tests/Feature/CoursePackageMonthlyBillingTest.php` (new)

### Regression Notes
- 現有堂數制多科方案行為不變（`billing_mode` 為 null 或 `count` 時 fallback 原邏輯）；`CoursePackageTest` 全部案例未受影響。
- 現有月結單科課程（`StudentClass.ScheduleMode='date'` 且無 `PackageID`）提醒 / 報表邏輯未異動。
- `PackageDeductionService` 在月結多科方案下維持寫入 `-1` 至 `package_session_ledger`，老師薪資仍以 `LearningRecord` 為依據，不受計費模式影響。
- 新增欄位為 additive 變更，rollback migration 只刪欄位、不破壞現有資料。

### Known Considerations
- **Q-2 / Q-3（月中退費 / 取消）**：本次未實作退費流程，暫沿用手動處理（標記 `Paid` 與金額由主任手動決定）；下版本再由 PM 確認業務規則。
- **稽核 log（Q-1 / Repudiation）**：現行 `CoursePackage` 沒有通用 audit log（僅 `PayrollAuditLog` 存在）；本次保留現行行為不新增 log surface，可於後續統一處理。
- **`ParentPortalController::resolveUnitPrice` / `resolveSubtotal`**：月結方案成員 `Charge=0`、`Rate=0` 時回傳 0，為 defensive 行為；家長端目前不顯示方案月費（out of scope），無資訊外洩風險。
- **部署順序**：必須先跑 migration（`php artisan migrate`）再部署後端程式碼，避免 `billing_mode` 欄位缺失導致舊碼讀取報錯；`php artisan config:cache` 後執行前端 `npm run deploy`。

---

## 2026-04-17 — Bug Fix：兼職老師薪資同層級併堂重複計算（PRD §4.3 v1.4）

### Problem
主任查詢 Ruth蔣 04-03 薪資時，同一老師在同一個物理授課時段有 3 名學生（陳則佑/一對三 + 張正甫/張正崗/一對二），薪資卻被計算為 750 + 825 + 825 = **2,400 元**——老師實際只教了一個時段，卻被計了 3 份基礎薪（350×2h×3 次）。

### Root Cause
`PRD_PARTTIME_TEACHER_PAYROLL.md §4.3` v1.3 規則對同層級重疊 LR 採「每筆各得全額基礎薪 + 各得加給」，缺少不同層級已有的「重疊段扣基本費」保護，導致同層級老師薪資虛報為 N 倍。

### Change
**Backend（`backend/`）**
- **`app/Http/Controllers/FinanceController.php::buildConcurrencyBonusMap`**：改以「層級優先 + lr_id tie-break」統一規則——主導者為「最高層級內 `lr_id` 最小者」；同層非主導與低層 dominated 皆在重疊段扣除 `base_rate × Δt`（基本費歸 0，由 `buildSessionRow` 的 `max(0, ...)` 保底）。
- **`tests/Feature/PayrollConcurrencyTest.php`**（新增）：8 tests 專責守護 v1.4 規則（n=2/n=3 完全重疊、部分重疊、不同層回歸、多最高層 tie-break、交換順序總薪不變、無重疊、缺時間）。
- **`tests/Feature/ParttimePayrollTest.php`**：同步更新 v1.3 時代的同層測試預期值為 v1.4（7 個案例）。

**Docs**
- **`docs/PRD_PARTTIME_TEACHER_PAYROLL.md §4.3`**：升級為 v1.4，新增主導者決定規則、`lr_id` tie-break 理由（對照 Workday 規則引擎設計原則）、具體範例表格（n=2/n=3/部分重疊）。
- **`docs/AI_REGRESSION_LESSONS.md`**：新增禁止回歸項「同層級重疊必須以 lr_id tie-break，嚴禁每筆 LR 各自保留基礎薪」。

### Affected Files
- `backend/app/Http/Controllers/FinanceController.php`
- `backend/tests/Feature/PayrollConcurrencyTest.php` (new)
- `backend/tests/Feature/ParttimePayrollTest.php`
- `docs/PRD_PARTTIME_TEACHER_PAYROLL.md`
- `docs/AI_REGRESSION_LESSONS.md`

### Regression Notes
- **不同層級重疊行為完全不變**（`test_different_level_regression_unchanged` 等 7 個回歸測試守護）：高中+國中+國小 dominant chain、輔導被覆蓋、無重疊、缺時間，金額全部與 v1.3 相同。
- **同層級重疊金額會下調**：未鎖帳月份若重新計算，同層級重疊老師的薪資總額下降（修正重複計算）。已鎖帳月份不受影響（`PayrollMonthStatus` 鎖帳層獨立於計算邏輯）。
- **Tie-break 以 `lr_id` 決定**：不同 tie-break 取法下老師總薪資不變（`test_tie_break_swap_preserves_total_salary` 守護），僅決定哪筆在明細顯示為主導行。
- 無 migration；僅需 `php artisan config:cache`。

### Known Considerations
- 部分重疊情境（如 A:14:00-16:00、B:15:00-17:00）修正後 B 課非整數時薪倍數，建議主任對照 PRD §4.3 v1.4 範例表格理解 breakdown。
- 後續可評估於 `payroll_audit_log` 補記「計算規則版本（PRD v1.x）」欄位（參考 Gusto 薪資稽核實踐），本次不做。

---

## 2026-04-17 — Feature：內部聊天強化與 Bug 修正（Chat Enhancement）

### Problem
1. **Bug**：老師帳號在「新對話」載入聯絡人時一律顯示「載入聯絡人失敗，請確認網路後重試」，根本原因是 `AttachAuthUser` middleware 僅從 `UserCampus` 查詢校區，部分老師只在 `teacher_branches` 表有記錄，導致 `auth_has_campus = false`，`require_campus` 回 403，但前端顯示錯誤的「網路錯誤」訊息。
2. **功能落差**：缺乏已讀回執、打字指示、訊息搜尋等 LINE/微信基本功能。
3. **發現問題**：老師首頁無聊天入口，行動版底部導覽把聊天藏在「更多」。

### Change

#### Backend（`backend/`）
- **`app/Http/Middleware/AttachAuthUser.php`**：新增 `teacher_branches` fallback。對 type=`T` 且 `UserCampus` 為空的使用者，自動查 `teacher_branches` 補設 `auth_campus_ids` 與 `auth_has_campus=true`。此為系統唯一合法 campus 解析入口，禁止 Controller 再自行查詢校區（見 AI_REGRESSION_LESSONS）。
- **`app/Services/ChatService.php`**：`getMessages()` 新增 `read_count` 計算（aggregated cursor model：讀過此訊息的其他成員數，排除發送者），`messageToArray()` 加入 `read_count` 欄位。
- **`app/Http/Controllers/ChatController.php`**：新增 `typing()` action，對應 `POST /api/v1/chat/threads/{id}/typing`；驗證 user 為 thread 成員，廣播 `ChatTyping` event（`typing.start` / `typing.stop`）到 private channel。
- **`app/Events/ChatTyping.php`**（新增）：廣播打字指示事件至 `private-chat.thread.{id}`。
- **`routes/api.php`**：新增 `POST chat/threads/{threadId}/typing` 路由。
- **`tests/Feature/ChatEnhancementTest.php`**（新增）：9 個 feature tests 覆蓋 FR-001 (teacher_branches fallback)、FR-003 (read_count)、FR-004 (typing 成員驗證)。

#### Frontend（`frontend/src/`）
- **`lib/chatApi.js`**：`json()` helper 改用 `ApiError` class 保留 HTTP status code；新增 `sendTyping(threadId, isTyping)` function。
- **`pages/ChatPage.vue`**：
  - `loadStaff` 依 HTTP status (401/403 campus/403 other/network) 分類顯示具體 Toast，不再一律說「請確認網路後重試」。
  - 訊息氣泡右下角顯示「已讀」（DM）/ 「已讀 N」（群組），有 0.3s 淡入動畫。
  - 打字指示欄（typing bar）：WebSocket `typing.start/stop` 事件，顯示「XXX 正在輸入…」三點動畫，3 秒 timeout 自動消失；前端 throttle 300ms + debounce 2s。
  - 訊息搜尋（搜尋列 slide-down 動畫，關鍵字 highlight，上/下箭頭跳轉，無結果空狀態）。
  - skeleton loader 改良（3 行 skeleton card）、錯誤 toast 顯示分類圖示。
- **`lib/chatRealtime.js`**：`subscribeThread` 新增 `onTyping` callback，監聽 `.typing.start` / `.typing.stop` events。
- **`pages/TeacherHomePage.vue`**：Quick Links 區新增「內部聊天」卡片，顯示未讀 badge，點擊導航至聊天頁；監聽 `alltrue-refresh-badges` 事件即時更新未讀數。
- **`App.vue`**：老師行動版底部導覽將「聊天」(forum) 提升為主要 tab（取代行事曆），badge 顯示未讀數。

### Affected Files
- `backend/app/Http/Middleware/AttachAuthUser.php`
- `backend/app/Services/ChatService.php`
- `backend/app/Http/Controllers/ChatController.php`
- `backend/app/Events/ChatTyping.php` (new)
- `backend/routes/api.php`
- `backend/tests/Feature/ChatEnhancementTest.php` (new)
- `frontend/src/lib/chatApi.js`
- `frontend/src/lib/chatRealtime.js`
- `frontend/src/pages/ChatPage.vue`
- `frontend/src/pages/TeacherHomePage.vue`
- `frontend/src/App.vue`

### Regression Notes
- `AttachAuthUser` fallback 只在 `type='T'` 且 `UserCampus` 確實為空時觸發，不影響主任/其他角色。
- `teacher_branches` table 不存在時（`Schema::hasTable` 返回 false），fallback 不執行，行為與修改前完全相同。
- 已讀回執使用現有 `chat_thread_members.last_read_message_id`，不新增 DB table。
- Typing 事件純 WebSocket，無 DB 持久化；WS 不通時 typing bar 靜默不顯示。

### Follow-up Fix (2026-04-17 同日)
- **根因補洞**：老師帳號打開聊天仍出現「無存取權限」。再次 trace 發現 `routes/api.php` 的 `GET /api/v1/profiles` 被重複註冊 — 第 271 行在 `role:director,teacher` 群組、第 338 行又在 `role:director` 群組，Laravel 後寫覆蓋先寫，實際上老師一律被 `RequireRole` 擋成 403（message="Forbidden" 不含 "campus"），前端才會 fallback 到泛用「無存取權限」訊息。
- **修正**：移除 director-only 群組中重複的 `GET profiles` 登記；所有 profile 的寫入動作（`POST/PUT/DELETE/reset-password/bulk-teachers`）與 `teacher_branches` 維護仍保留 director-only。
- **回歸守護**：`tests/Feature/ChatEnhancementTest.php` 新增 2 個測試：
  - `test_teacher_can_call_get_profiles_endpoint`：老師呼叫 `GET /api/v1/profiles` 不得為 403。
  - `test_teacher_cannot_post_profiles`：老師呼叫 `POST /api/v1/profiles` 必須為 403（確認寫入動作仍是 director-only）。
- 測試總數：11 passed, 2 skipped。

---

## 2026-04-17 — Feature：課表出入回報系統（Schedule Discrepancy Reports）

### Problem
主任課（由主任排班但由其他老師授課）經常發生課後臨時調整，主任在紙本或 LINE 處理但未即時更新系統，導致老師點名時遇到 4 種常見狀況：
1. 系統顯示有課但現場沒學生
2. 現場有學生但系統找不到堂次
3. 時段與實際上課不符
4. 學生名單錯誤

過去老師只能私下傳 LINE 給主任，訊息散落、無稽核軌跡，也無法追蹤處理進度。

### Change
新增一套「老師回報 → 主任處理」工作流，既讓現場問題有正式入口，也保留完整稽核歷史。

1. **資料層**（`backend/database/migrations/`）：
   - `2026_04_17_200000_create_schedule_discrepancies_table.php`：新增 `schedule_discrepancies` 表，欄位含 `class_session_id`（nullable，for missing_session）、`reporter_id`、`branch_id`、`discrepancy_type`（enum）、回報上下文（日期/時段/科目/學生）、`status`（pending/acknowledged/resolved/withdrawn）、稽核欄位（acknowledged_by/_at, resolved_by/_at, resolution_note, withdrawn_at）、`archived_at`（12 個月自動封存）。
   - `2026_04_17_200001_add_staff_line_group_id_to_campus.php`：`Campus` 新增 `staff_line_group_id` 欄位（因 LINE Notify 於 2025-03-31 終止，改用 LINE Messaging API 的 Push Message，推送到每校一個員工群組）。
2. **後端 API**（`backend/app/Http/Controllers/ScheduleDiscrepancyController.php`）：
   - `POST /api/v1/schedule-discrepancies`：建立回報。`reporter_id` 強制從 token 注入（不接受 body 覆蓋，FR-005）。綁定 `class_session_id` 時驗證堂次所屬分校一致，防跨校偽造。同 session 已有 pending/acknowledged 紀錄時回 200 + `duplicate:true` 附現有紀錄（FR-003）。
   - `POST /api/v1/schedule-discrepancies/{id}/withdraw`：老師軟取消自己尚未被確認的回報（FR-011），`status=withdrawn`。
   - `GET /my`：老師查看自己的回報（for 徽章顯示）。
   - `GET /active-for-session?class_session_id=N`：回傳該堂次最新有效回報（for 出勤頁「已回報」徽章）。
   - `GET /schedule-discrepancies`：主任列表，`require_campus` scope 限制。
   - `GET /summary`：儀表板摘要計數（排除已封存）。
   - `PUT /{id}`：主任更新狀態；resolved 需 `resolution_note ≥ 10` 字（FR-008），已結案再改回 pending/acknowledged 回 409（FR-009）。
3. **LINE 通知**（`backend/app/Services/ScheduleDiscrepancyNotifier.php`）：
   - 使用 `Campus.messaging_channel_token` + `staff_line_group_id` 呼叫 LINE Messaging API Push。
   - 最多 3 次重試（backoff 1s/2s），timeout 8s；4xx 非 429 直接中止；失敗僅 log，不阻擋 API 回應。
   - 透過 `dispatch(fn...)->afterResponse()` 非同步發送，API p95 < 500ms 不受影響。
4. **資料保留**（`backend/app/Console/Commands/ArchiveScheduleDiscrepancies.php`）：
   - Artisan 指令 `schedule-discrepancies:archive --months=12`：將超過 12 個月的 resolved/withdrawn 紀錄 `archived_at` 標記為 now。
   - `Console/Kernel` 排程每月 1 日 03:40 執行。
5. **前端（老師端）**：
   - `frontend/src/components/ReportDiscrepancyModal.vue`：回報 Modal，支援 `session`（指定堂次）與 `missing`（此課不在系統）兩種模式，radio 以大卡片呈現，備註有 200 字計數器，提交後顯示 loading spinner；已存在回報時自動切換為唯讀檢視並顯示「撤銷回報」按鈕（pending 狀態）。
   - `frontend/src/pages/AttendancePage.vue`：
     - 每一堂待點名課新增「回報出入」按鈕；若已有回報顯示「已回報・待處理/處理中」徽章。
     - 頁尾新增「有課不在列表中？點此回報」CTA（missing_session 入口，FR-004）。
     - 初始化與切換分校時透過 `fetchMyDiscrepancies` 預載自己的回報映射表。
   - `frontend/src/lib/scheduleDiscrepanciesApi.js`：前端 API client，封裝所有 CRUD 與列舉常數（類型、狀態）。
6. **前端（主任端）**：
   - `frontend/src/pages/DirectorDashboard.vue`：新增「課表回報」卡片，顯示待處理數字 + skeleton loader；有 pending 時卡片邊框警示色，點擊跳管理頁。
   - `frontend/src/pages/ScheduleDiscrepancyPage.vue`：新管理頁，tabs (pending/acknowledged/resolved)、展開明細、處理說明字數計數 (≥10)、「標記已確認」與「標記已修正」動作、toast 回饋、行動裝置卡片排版。
   - `frontend/src/App.vue`：註冊新頁面與路由；sidebar 新增「課表回報管理」項（導航徽章 = `summary.pending`，透過 `mergeScheduleDiscrepancyBadge()` 整合進既有 badge polling 機制）。
7. **測試**：
   - 新增 `backend/tests/Feature/ScheduleDiscrepancyApiTest.php`，14 tests / 56 assertions，覆蓋 FR-001/003/004/005/006/007/008/009/011、跨校 403、session 跨校綁定、LINE 缺設定降級、封存指令。
8. **文件**：
   - `docs/schedule-discrepancy-qa-checklist.md`：QA 手動驗收清單（含 UI/UX 子節）。
   - `docs/schedule-discrepancy-security-review.md`：STRIDE + 授權矩陣 + fail-safe。
   - `docs/schedule-discrepancy-code-review.md`：duplicate guard、LINE 降級、存取控制三大焦點審查。

### 受影響檔案
- 新增：
  - `backend/database/migrations/2026_04_17_200000_create_schedule_discrepancies_table.php`
  - `backend/database/migrations/2026_04_17_200001_add_staff_line_group_id_to_campus.php`
  - `backend/app/Models/ScheduleDiscrepancy.php`
  - `backend/app/Http/Controllers/ScheduleDiscrepancyController.php`
  - `backend/app/Services/ScheduleDiscrepancyNotifier.php`
  - `backend/app/Console/Commands/ArchiveScheduleDiscrepancies.php`
  - `backend/tests/Feature/ScheduleDiscrepancyApiTest.php`
  - `frontend/src/components/ReportDiscrepancyModal.vue`
  - `frontend/src/lib/scheduleDiscrepanciesApi.js`
  - `frontend/src/pages/ScheduleDiscrepancyPage.vue`
  - `docs/schedule-discrepancy-qa-checklist.md`
  - `docs/schedule-discrepancy-security-review.md`
  - `docs/schedule-discrepancy-code-review.md`
- 修改：
  - `backend/routes/api.php`（新增 7 條路由）
  - `backend/app/Console/Kernel.php`（註冊封存排程）
  - `frontend/src/pages/AttendancePage.vue`（按鈕、徽章、missing CTA、toast）
  - `frontend/src/pages/DirectorDashboard.vue`（摘要卡片）
  - `frontend/src/App.vue`（路由、sidebar、badge merge）

### Regression Notes
- 既有「出缺勤管理」頁點名、請假、LINE 家長通知流程 100% 保留，新增 UI 僅覆加按鈕/徽章/文案，不改點名 API。
- 既有 `Campus.messaging_channel_token` 與家長通知流程無影響；`staff_line_group_id` 為新欄位，空值時略過推播不報錯。
- 主任儀表板其他卡片（今日課表、繳費提醒）位置不變，新卡片安插其間。
- sidebar 新增項目於 director 側；teacher 側不顯示。

### 已知限制（列入風險追蹤）
- 無 per-user rate limit，目前倚賴 FR-003 duplicate guard 防濫用。
- `activeForSession` 目前不額外檢查 session 是否在 user 的 campus；作業流程上老師只會查自己校區 session，實際可利用性低，已列入下一輪迭代 follow-up。

## 2026-04-17 — Bug Fix：繳費日期清除 & 聊天手機搜尋

### Problem
1. **繳費日期無法清除（Bug A）**：主任在課程列表點擊「已繳費 → 未繳費」切換按鈕後，資料庫 `Paid` 確實變為 0，但 `PayDate` 仍保留舊日期；前端 `course.last_paid_at` 繼續顯示舊繳費日期，讓主任誤以為切換失敗、帳務狀態混亂、催繳提醒誤判。
2. **老師手機聊天搜尋失效（Bug B）**：老師用 iOS Safari 進入「新對話」modal，點擊搜尋輸入框時因 `.form-input` `font-size: 14px` 觸發 iOS 自動縮放 viewport，modal 跑版、搜尋列表無法正常顯示；另外 `loadStaff` API 失敗時僅 `console.error`，UI 無任何提示，老師誤以為沒有聯絡人。

### Change
1. **前端（Bug A）**：
   - `frontend/src/pages/StudentsList.vue::togglePaymentStatus` 切換至 `unpaid` 時 PUT payload 加入 `paid_at: null`；成功回應後同步將本地 `course.paid_at` 與 `course.last_paid_at` 設為 `null`，日期欄位即時清空不閃舊值。
   - 切換至 `paid` 時從 API 回應解析新 `paid_at` / `last_paid_at` 回寫本地課程物件。
   - `frontend/src/pages/CourseManagement.vue::togglePaymentStatus` 以相同規則更新，避免另一個列表頁走舊行為。
   - 按鈕新增 loading spinner（`.payment-spinner`）與 `處理中` 文字，`min-height: 32px` / `min-width: 64px` 確保觸控目標尺寸。
2. **後端（Bug A 防守層）**：
   - `backend/app/Http/Controllers/StudentClassController.php::mapFrontendPayload` 當 `payment_status === 'unpaid'` 且 payload 不含 `paid_at` key 時，自動將 `PayDate` 設為 `null`。僅作為防守：若有其他呼叫路徑忘了帶 `paid_at`，也能確保資料一致。
   - `paid_at` 明確傳入時仍優先（保留 `test_payment_status_wins_over_paid_at` 既有行為：`paid_at=date + payment_status=unpaid` → `Paid=0, PayDate=date`）。
3. **前端（Bug B）**：
   - `frontend/src/pages/ChatPage.vue` 新增 `@media (max-width: 768px)` 覆蓋：`.form-input, .form-select { font-size: 16px }`，避免 iOS Safari 在手機 focus 時縮放 viewport。同時加上 `.modal-card { max-height: 80vh; overflow-y: auto }` 與 `.staff-item, .thread-row { min-height: 44px }`。
   - `loadStaff` 加入 `staffLoading` / `staffError` 狀態；失敗時呼叫 `showToast()` 顯示紅色 toast，內含「重試」按鈕可重新載入，持續 4 秒。
   - DM 搜尋列表：載入中顯示 3 個 skeleton row；無結果時顯示圖示 + 描述 +「清除搜尋」/「重新載入」CTA（視情境）。
   - 新增通用 toast 元件 `.chat-toast`（右上角、animate-in、自動消失可設 duration）。
4. **測試**：
   - `backend/tests/Feature/StudentClassPaidStatusTest.php` 強化 `test_explicit_payment_status_unpaid_sets_paid_zero` 與 `test_toggle_unpaid_then_edit_memo_keeps_unpaid` 額外斷言 `PayDate=null`。
   - 新增 `test_toggle_unpaid_with_explicit_null_paid_at_clears_both` 驗證前端新 payload 格式。
   - 全套 StudentClassPaidStatusTest 13 tests / 42 assertions 通過；`--filter=StudentClass` 39 tests / 171 assertions 通過；`ChatApiTest|ProfileController` 6 tests 通過。

### 受影響檔案
- `frontend/src/pages/StudentsList.vue`（`togglePaymentStatus` payload + 本地狀態同步 + 按鈕 loading UI + `.payment-spinner` CSS）
- `frontend/src/pages/CourseManagement.vue`（`togglePaymentStatus` 一致化）
- `frontend/src/pages/ChatPage.vue`（toast 機制、skeleton loader、空狀態 CTA、`loadStaff` 錯誤處理、mobile CSS override）
- `backend/app/Http/Controllers/StudentClassController.php`（`mapFrontendPayload` 防守層）
- `backend/tests/Feature/StudentClassPaidStatusTest.php`（新增 + 強化斷言）

### Regression Notes
- `payment_status=paid` + 任何 paid_at 行為 100% 保留。
- 桌機（>= 768px）`.form-input` 仍為 14px，視覺不變。
- `loadStaff` 失敗改為 UI 可見 toast，不再只沉默 `console.error`，但仍記錄到 dev console。
- PRD：`.cursor/plans/繳費清除與聊天搜尋修復_b56a5efb.plan.md`

### PRD 開放問題的解答（業界做法落實）

PRD 第 13 節標示的三個 `[TODO: 需確認]` 本次一併處理：

1. **清空繳費前是否需檢查發票關聯？** → **是**，加上 HTTP 409 guard。
   - 後端（`StudentClassController::update`）於 `Paid 1 → 0` 時掃 `Invoice.StudentClassID` 與 `InvoiceItem.StudentClassID`，若有 `Payment` 記錄則回傳 HTTP 409：
     ```
     { "error": "payment_linked",
       "message": "此課程已有發票收款記錄，直接改為未繳費將與發票不同步。建議改用「付款報表 → 作廢」流程。",
       "warnings": { "invoice_count": N, "payment_count": M, "total_paid_amount": X,
                     "recommended_action": "void_via_payment_report",
                     "force_field": "force_clear_paid" } }
     ```
   - 前端（`StudentsList.vue` / `CourseManagement.vue` `togglePaymentStatus`）收到 409 時彈出詳細 confirm dialog（列出發票數、付款數、總金額、建議改走作廢流程），使用者按確認才帶 `force_clear_paid: true` 重試。使用者取消則直接 return。
   - **已核准學習評量**不屬於財務關聯（LearningRecord 扣堂走 `SessionDeducted` 欄位，與 `PayDate` 無耦合），故不納入 guard。
2. **是否需要 audit log for clear_paid_at？** → **是**，用結構化 Laravel log。
   - 本專案無 `spatie/activitylog` 等通用 audit package，僅 `PayrollAuditLog` 為薪資專用。依 `BillingController` 現有做法以 `Log::warning('[StudentClass] clear_paid_at', [...])` 寫入結構化 payload：`student_class_id`、`actor_user_id`、`actor_role`、`old_pay_date`、`linked_invoice_ids`、`payment_count`、`total_paid_amount`、`force_used`、`ip`。後續有需要再升級為獨立 table。
3. **confirmPayment 反向操作是否需另行清除？** → **不需要**。
   - `confirmPayment` 為單向 quick action，反向流程即修好後的 `togglePaymentStatus('unpaid')`；正規帳務沖銷走 `PaymentReportController::void`（會建負 `Payment`、重算 `Invoice` 狀態、並在 Invoice 變 unpaid 時同步清 `StudentClass.Paid/PayDate`），已有完整實作。

### 新增測試（Pest）
- `test_clear_paid_with_linked_invoice_payment_returns_409_without_force`：有 Payment → 409 + warnings 結構、Paid/PayDate 保持不變。
- `test_clear_paid_with_force_flag_succeeds`：帶 `force_clear_paid=true` → 成功，Invoice/Payment 故意保留（由作廢流程自行沖銷）。
- `test_clear_paid_without_invoice_has_no_guard`：無發票 → guard no-op，直接清除成功。

`StudentClassPaidStatusTest` 共 16 tests / 57 assertions 全過。

---

## 2026-04-17 — 單堂時間費率自動計算（session_charge）

### Problem
單堂時間調整（加時／縮時）後，費用未隨實際時長同步，造成課程總費用（`StudentClass.Charge`）與實際上課時數脫節。主任與家長需事後手動補帳。

### Change
1. **DB**：`ClassSession` 新增 `session_charge`（nullable INT）欄位（migration `2026_04_17_100000_add_session_charge_to_class_session.php`）。null 表示採標準費用、非 null 表示已依實際時長調整。
2. **後端**：`ClassSessionController::applyTimeAndNoteUpdates` 在 `start_time` / `end_time` 有異動時自動：
   - 以 `actual_minutes / SessionDuration`（session 模式）或 `actual_minutes / 60`（hour 模式）× `Rate` 計算 `session_charge`。
   - 依 `delta = new_session_charge - (old_session_charge || standard_charge)` 同步更新 `StudentClass.Charge`（至少為 0）。
   - `Rate`、`SessionDuration` 任一未設定時視為 no-op；`rate_unit` 未知值自動退回 `session`。
3. **API 回應**：`PATCH /api/v1/class-sessions/{id}` 回傳 `session.session_charge`；`GET /api/v1/class-sessions` 每筆 row 新增 `session_charge`、`contract_rate`、`contract_session_duration`、`contract_rate_unit`，供前端計算標準／實際費用預覽。
4. **前端（課程管理）**：`SessionEditModal` 的「備註 / 調整時段」分頁新增「開始時間」欄位（原本僅有結束時間），並即時顯示本堂費用預覽卡片：
   - 三種視覺狀態：高於標準（橘）／低於標準（藍）／等於標準（淺藍）／費率未設定（灰）。
   - 結束時間早於開始時間時顯示 inline 錯誤並停用儲存。
   - 費用偏離標準 ±50% 以上時，儲存前彈出二次確認 dialog。
   - 觸控目標 ≥ 44px、行動裝置 stacked 排列。
5. **前端（智慧排課）**：`SmartCalendar` 單堂檢視 modal 新增「本堂費用」row，有調整過的堂顯示「已依實際時長調整」標記，未調整者顯示「標準費用」。

### 受影響檔案
- `backend/database/migrations/2026_04_17_100000_add_session_charge_to_class_session.php`（新增）
- `backend/app/Models/ClassSession.php`（`$fillable`、`$casts`、docblock）
- `backend/app/Http/Controllers/ClassSessionController.php`（`applyTimeAndNoteUpdates` + `syncSessionChargeForTimeChange` + `minutesBetween`；`index` select + transform；`sessionUpdateResponse`）
- `frontend/src/composables/course-management/useSessionEditFlow.js`（`sessionEditForm` 擴充、`openSessionEdit` 帶入 contract rate、`doEditNoteTime` 送 `start_time`）
- `frontend/src/components/course-management/SessionEditModal.vue`（開始時間欄位、費用預覽、inline 錯誤、二次確認 dialog、樣式）
- `frontend/src/pages/SmartCalendar.vue`（`currentSessionChargeDisplay` computed + 單堂 modal row）
- `backend/tests/Feature/ClassSessionChargeTest.php`（7 case：session/hour 模式、縮時／延時、baseline 接續、SessionDuration=0 no-op、僅改備註不動費用、回應含 `session_charge`）

### 回歸防護（勿回退）
- `syncSessionChargeForTimeChange` 須在 `$hasTimeChange` 成立時才觸發；**僅改 note** 或狀態不得污染 `session_charge` / `Charge`（已有測試 `test_note_only_update_does_not_touch_charge`）。
- baseline 取捨：`old_session_charge != null` 用舊值、否則用標準費用；**勿改成每次都用標準費用**，否則重複編輯會一直以標準為基準而漏算先前差額（見 `test_second_edit_uses_previous_session_charge_as_baseline`）。
- `Rate` 或 `SessionDuration` 為 0/null 時必須 no-op；勿把 `SessionDuration=0` 當分母。
- `ClassSessionController::index` 的 derived table LEFT JOIN 架構（`sub_sched` / `lr` / `si` 皆以 `MAX(id)` 去重）**必須維持**；新加欄位不得回退成裸 LEFT JOIN（見 2026-04-15 (I) 課程管理 chip 重複一節）。
- `session_charge` 是財務敏感欄位：`PATCH /api/v1/class-sessions/{id}` 只接受 `start_time` / `end_time`，**不接受前端直接傳 `session_charge`**；計算永遠在後端。

### 2026-04-17 補：關閉計畫第 13 點三個待解項

| 項目 | 決策（業界慣例） | 實作位置 |
|------|------------------|----------|
| per-day duration fallback（每日時長不同） | standard duration 優先採用堂次當日的 `duration{N}`（`week{N}` 對應 ISO weekday），fallback 到 `SessionDuration` | `StudentClass::resolveSessionDurationForWeekday()`（新增 public helper）、`ClassSessionController::syncSessionChargeForTimeChange()` |
| 課程 Rate/SessionCount 變更時 session_charge 累積 delta 保留 | **保留原始金額**（會計系統慣例）：新 Charge = `Rate_new × Count_new` + `preserved_delta`，其中 `preserved_delta = 舊 Charge − 舊 Rate × 舊 Count` | `StudentClassController::update()` |
| SmartCalendar 單堂時間編輯入口 | **顯示-only**：單堂時間調整統一走課程管理 `SessionEditModal`，維持單一編輯入口；SmartCalendar 只顯示 `session_charge` 供檢視 | `SmartCalendar.vue` `currentSessionChargeDisplay` |

### 2026-04-17 補：回歸防護（三項決策）

- **per-day duration**：若課程有填 `duration1~duration6`（例如 Mon 120min、Fri 90min），`syncSessionChargeForTimeChange` 必須依 `SessionDate` 的 ISO weekday 查對應 `duration{N}`；**勿回退成一律使用 `SessionDuration`**，否則多日異時長課程會算錯基準（測試 `test_per_day_duration_is_used_when_set_on_session_weekday`）。
- **Rate 變更 preserve delta**：`StudentClassController::update` 動 `Rate` 或 `SessionCount` 時，不得把 `Charge` 直接覆寫為 `Rate × Count`；必須 snapshot 舊值、計算 `preserved_delta` 再加上新 base，否則單堂時間調整累積的手動金額會被洗掉（測試 `test_course_rate_update_preserves_accumulated_session_charge_delta`）。
- **SmartCalendar 入口**：禁止在 SmartCalendar 加入單堂時間編輯表單；所有 `PATCH /api/v1/class-sessions/{id}` + `session_charge` 重算入口必須走 `SessionEditModal`，避免多入口邏輯分歧。

## 2026-04-16 (V7) — 智慧排課日檢視：有課老師欄自動置左 + 可選隱藏空白老師欄

### Problem
- 日檢視（teacher-grid）老師欄依「教室 → 姓名」字母排序，與「今天是否有課」無關。空白老師佔據最左側，主任與老師都必須橫向捲動才能看到有意義的排課資訊。

### Change
1. **P0 — `visibleTeachers` sort 新增第一排序鍵 `_hasCourseToday`**（`frontend/src/pages/SmartCalendar.vue`）
   - 判斷依據：`filteredCourses` 中存在 `teacher_id === t.id && day_of_week === selectedDow` 且未被 `isSessionCancelledOnDate` 排除
   - 排序：`hasCoursesToday`（有課=0）→ `roomLabel`（localeCompare）→ `username`（localeCompare）
   - 週檢視不受影響（`dowForSort = null` → 全部 flag 為 false → 退化為舊排序）
2. **P1 — 新增「只顯示今日有課老師」開關**（純覽模式）
   - 新增 `hideEmptyTeacherColumns` ref + `smart_calendar_hide_empty_teachers` localStorage 持久化
   - 日檢視篩選列（`.toolbar-filters`）加入 checkbox label，僅 `!isWeekOverview && !isTeacher` 時顯示
   - 開啟後過濾掉 `!_hasCourseToday` 老師欄；若全部被過濾，空狀態顯示「今日無已排課老師」+「顯示全部老師」按鈕
   - Tooltip 明示：「開啟後只顯示今日有排課的老師欄；此模式下無法點空格快速排課」
   - 預設 **OFF**：因日檢視空格（`@click="onSlotClick"`）是主任快速排課的主要操作點，預設隱藏會破壞排課工作流程

### 受影響檔案
- `frontend/src/pages/SmartCalendar.vue`
  - `visibleTeachers` computed：sort 邏輯改為 3 層（有課 → roomLabel → username）
  - 新增 `hideEmptyTeacherColumns` ref 與 `watch` 寫入 localStorage
  - 範本加入 `.toolbar-hide-empty-toggle` checkbox
  - `.teacher-empty` 空狀態針對 `hideEmptyTeacherColumns` 顯示友善引導
  - 新增 `.toolbar-hide-empty-toggle` 樣式

### 回歸防護（勿回退）
- `teacherHasCourseToday` 必須以 **`filteredCourses`** 為判斷來源（已含取消過濾），勿改回 raw `courses.value`，否則「全天 cancelled」的老師會誤判為有課。
- 週檢視（`isWeekOverview=true`）必須讓 `dowForSort = null`，避免 sort 以「今天是週幾」污染週檢視；勿移除該判斷。
- 隱藏開關只在 `!isWeekOverview && !isTeacher` 顯示；`hideEmptyTeacherColumns` 在 computed 內也用 `!isWeekOverview.value` 守門，**兩處都要保留**。
- 預設 **OFF** 是刻意決策（見 plan 第 13 節）；若未來改為預設 ON，必須同步移除「點空格快速排課」入口或調整流程，勿單獨切換預設值。

## 2026-04-16 (V6) — 智慧排課：修 rc-tag 縱向長條 bug + 再縮緊湊容量徽章

### Problem
- 承 `(V5)` 改為數字徽章後出現兩個問題：
  1. **「評」角標變成從卡片上緣到下緣的紅色長條**（本該是小圓角 chip）。根因：`(V4)` 加入的 `.teacher-grid-compact .rc-tag { top: 2px; right: 2px }` 特異性（0-2-0）**高於** `.rc-tag-second { top: auto; bottom: 2px }`（0-1-0），造成第二角標同時套用 `top: 2px` + `bottom: 2px` → 元素被撐成縱向滿高度長條。
  2. **一對二 split 第一張卡姓名仍被截**：`2/2` 數字徽章 min-width 20px + padding 仍比理想值大。

### Fixed
1. **修 rc-tag 縱向撐開**：
   - 移除 `.teacher-grid-compact .rc-tag { top: 2px }`（維持 base `.rc-tag` 的 `top: 2px` 即可）。
   - 新增 `.teacher-grid-compact .rc-tag.rc-tag-second { top: auto; bottom: 2px }`（同等特異性 0-2-0），確保第二角標在底部而非撐滿。
2. **再縮緊湊容量徽章**：
   - `font-size: 9 → 8px`
   - `min-width: 20 → 16px`
   - `padding: 0 3px → 0 2px`
   - `border-radius: 4 → 3px`
   - 新增 `letter-spacing: -0.3px`（讓 `2/2` 更緊湊）
   - `.cb-student` 讓位 `padding-left: 22 → 18px`

### 實際可用文字寬（緊湊 140px 欄 × 一對二 split × 第一張卡）
- 卡寬 66px − 卡 padding 8px − 左讓位 18px − 右讓位 13px = **27px 可用文字**
- 緊湊字體 11px → **≈ 2.5 字中文**
- 較 `(V5)` 版（21px / 1.9 字）改善；較 `(V4)` dot 版（35px / 3.2 字）仍略窄但主任可讀「X/Y」。

### 回歸防護（勿回退）
- `.teacher-grid-compact .rc-tag.rc-tag-second { top: auto; bottom: 2px }` **不可**移除，否則 `.rc-tag-second` 會被 compact 規則的 `top: 2px` 覆蓋，重現縱向長條 bug。
- 若日後調整 `.teacher-grid-compact .rc-tag`，**不要**把 `top: 2px` 加回去（base `.rc-tag` 本身就有）。
- `.capacity-badge-compact` 的 `letter-spacing: -0.3px` 與 `min-width: 16px` 為搭配 `2/2` 格式最小可辨識寬度的實驗值，勿加大。

### 受影響檔案
- `frontend/src/pages/SmartCalendar.vue`（`.teacher-grid-compact .rc-tag`、新增 `.rc-tag-second` compact override、`.capacity-badge-compact`）

## 2026-04-16 (V5) — 智慧排課：容量徽章統一改用數字（取消緊湊模式圓點）

### Change
- 主任要求：容量徽章改為**全部顯示數字 `1/3`、`2/3`、`3/3`**，不再用緊湊模式的圓點替代。
- 容量徽章的「班型 vs 目前人數」資訊更直接，不用猜色彩語意。

### Fixed
- **Template**：移除緊湊模式的條件渲染（`isTeacherGridCompact ? '' : label`），一律顯示 `getSlotOccupancy(...).label`。
- **`.capacity-badge-compact` 重新樣式化**：
  - 從 `8×8 圓點 font-size:0` 改為 **`font-size: 9px; padding: 0 3px; min-width: 20px; border-radius: 4px; border-width: 1px`** — 小字矩形膠囊，仍比標準模式（font 10px / 6px padding / min 22px）緊湊。
- **手機斷點（≤ 768px）`.capacity-badge`**：從 8px 圓點改為 `font-size: 8px; min-width: 18px; padding: 0 2px`（小字矩形），手機也顯示數字。
- **`.slot:has(.capacity-badge-compact) .cb-student` padding-left**：`10px → 22px`（讓位給較寬的數字徽章）；標準模式 `28px → 30px`（配合 border 調整）。

### 權衡說明（給工程師與 PM）
- 換成數字後，緊湊 140px 欄 × 一對二 split 的**第一張卡**可用文字寬由 35px → 21px（`66 − 8 − 22 − 13`），約 **2 字中文** — 比圓點版（3 字）略窄。
- 但主任可以**直接讀到「X/Y」** 不需記憶顏色意義（色盲／色彩辨識困難使用者亦更友善），業務判斷仍值得。
- 第二張卡（無容量徽章 padding-left）不受影響，仍可顯示 4 字中文。
- 若日後要再優化空間，可評估：a) 緊湊模式改顯示單一數字（只 `1`、`3`，不含分母），寬度可降至 17px；b) 緊湊模式 split ≥ 2 時隱藏徽章第一張卡只顯示在「整點第二張卡」。目前先以最直覺的 `1/3` 格式上線。

### 回歸防護（勿回退）
- 容量徽章 template **不可**再恢復 `isTeacherGridCompact ? '' : label` 條件（主任已確認要數字）。
- 若要改動 `.capacity-badge-compact` 的 font-size / padding，需同步檢查 `.slot:has(.capacity-badge-compact) .cb-student` 的 padding-left 避免遮擋 regression。
- `getSlotOccupancy` 的 `label` 格式 `"X/Y"` 為唯一真實來源，勿改為 `"X / Y"` 或其他格式（會讓徽章變寬且破壞緊湊計算）。

### 受影響檔案
- `frontend/src/pages/SmartCalendar.vue`（template 190-193、`.capacity-badge-compact`、`.slot:has(...)` padding、手機斷點 `.capacity-badge`）

## 2026-04-16 (V4) — 智慧排課：緊湊模式 split 卡片第一張仍截斷（雙邊 padding 夾擊）

### Problem
- 承 `(V3)` 修正後，緊湊模式（≥ 10 位老師，木柵／新店）下一對二的**第一張卡**仍顯示「張...」「林...」，但**第二張卡**可完整顯示「顧汶勳」「朱昱齊」。
- 根因：第一張卡**同時**有「容量徽章」（左上）+「到班角標」（右上），`.cb-student` 被左右雙邊 padding 夾擊：
  - `padding-left: 14px`（讓位給 `.capacity-badge-compact`）
  - `padding-right: 18px`（讓位給 `.rc-tag`）
  - 加上卡片自身 `padding: 4px 4px` = **共 40px 被吃掉**
  - 緊湊 130px 欄 × 一對二 split = 每卡 61px → 扣除後僅剩 21px，只夠 1 字 + 省略號
- 第二張卡沒有容量徽章 padding-left，所以多出 14px 顯示空間，姓名顯得完整。

### Fixed（三管齊下）
1. **欄寬再加 10px**：緊湊 130 → 140px、標準 140 → 150px。一對二 split 緊湊每卡由 61 → 66px。
2. **緊湊容量徽章再縮小**：`.capacity-badge-compact` 寬高由 `10px` → `8px`、`border-width: 1px`、`left: 2px`；左側讓位 padding-left 由 `14px` → `10px`（省 4px）。
3. **緊湊 rc-tag（到班/漏點/請假/評量）角標縮字縮邊**：新增 `.teacher-grid-compact .rc-tag { font-size: 8px; padding: 0 2px; top: 2px; right: 2px }`，右側讓位 padding-right 由 `18px` → `13px`（省 5px）。

### 實際可用文字寬（緊湊 140px 欄、一對二 split、第一張卡）
- 卡寬 66px − 卡 padding 8px − 左讓位 10px − 右讓位 13px = **35px 可用文字**
- `.teacher-grid-compact .cb-student { font-size: 11px }` → **≈ 3 字中文**（可顯示「張嘉軒」「林昱誠」）
- 第二張卡（無左讓位）：66 − 8 − 0 − 13 = **45px** ≈ 4 字中文

### 業界對照
- 緊湊模式下 rc-tag 縮為 8px 字體 + 2px padding 的做法，與 Google Calendar 窄欄位的 attendance indicator、Notion Calendar 的 status pill 一致 — 僅保留辨識色彩與字元，不佔無謂寬度。
- 容量徽章從 10px 圓點縮為 8px 圓點，仍符合 Material Design 「8px grid system」最小可辨識圖示規範（8px × 8px）。

### 回歸防護（勿回退）
- `.capacity-badge-compact` 寬高 **不可**再放大回 10px，否則 padding-left 需增加、雙邊夾擊 regression 重現。
- `.teacher-grid-compact .rc-tag` 的 `font-size: 8px; padding: 0 2px` **不可**回退，否則角標寬變回 17px、padding-right 需加大、split 卡文字區再次被壓縮。
- 緊湊欄寬 140px、標準欄寬 150px **不可**再降，已為「一對二 split 可顯示 3 字」的最低值。
- 非緊湊模式的 `.rc-tag`（font-size: 9px）未動，維持原本外觀。

### 受影響檔案
- `frontend/src/pages/SmartCalendar.vue`（`gridTemplateStyle` min col width、`.capacity-badge-compact`、`.teacher-grid-compact .rc-tag`、`.cb-student` padding-left/right）

## 2026-04-16 (V3) — 智慧排課：到班／請假／漏點角標遮擋學生姓名

### Problem
- 承 `(V2)` 修正後，split 卡片的姓名寬度擴大，但仍回報「到班綠勾」「請假」等 `.rc-tag` 角標壓到姓名末字（例：`黃秉澤✓` 的「澤」被綠勾蓋住）。
- 根因：`.rc-tag` 為 `position: absolute; top: 2px; right: 3px`，寬度約 20px，與 `.cb-student` 文字自然延伸到卡片右緣時產生視覺重疊（雖然 rc-tag 有自己的背景色，但半透明疊在姓名上仍讓主任看不清末字）。

### Fixed
- 新增 CSS 規則，讓帶有 `.rc-tag` 的課程卡自動給 `.cb-student` 預留右側空間：
  - 標準模式：`padding-right: 20px`
  - 緊湊模式（`.teacher-grid-compact`）：`padding-right: 18px`
- 使用 CSS `:has()` 選擇器自動偵測（不需改 template），卡片沒有角標時不受影響（維持原本寬度）。

### 回歸防護（勿回退）
- `.course-block:has(.rc-tag) .cb-student` 的 `padding-right` **不可**移除，否則「到班 ✓」「漏點 !」「請假 假」「未填評量 評」會遮擋學生姓名末字。
- `.rc-tag` 本身的定位（`top: 2px; right: 3px`）**不要**改動；此修正只處理文字閃避，不動角標。
- 容量徽章的 `padding-left` 規則（`.slot:has(.capacity-badge) .course-block:first-of-type .cb-student`）未改動，與 rc-tag padding-right 可並存。

### 受影響檔案
- `frontend/src/pages/SmartCalendar.vue`（capacity-badge padding 規則之後新增 `.course-block:has(.rc-tag)` 兩條）

## 2026-04-16 (V2) — 智慧排課：多老師排版可讀性 hotfix（一對二/一對三 split 仍被截斷）

### Problem
- `(V)` 修正落地後，木柵等老師多的分校仍回報卡片文字顯示「張…」「顧…」「林…」「朱…」（單字 + 省略號）。
- 根因：同一老師在同一時段有多位學生（一對二 / 一對三）時，`getTeacherCourseBlockStyle` 會把卡片水平等分為 50/50（或 33/33/33）**在同一欄位內**。緊湊模式 100px 欄寬下，一對二 split 後每張卡只剩 ~50px，扣掉 `course-block` 左右 padding 8px（共 16px），可用文字寬僅 ~34px，只夠顯示 1 個中文字。

### Fixed
- **標準最小欄寬 120 → 140px**：一對二 split 後每卡 ~66px，可用文字 ~50px（約 4 字中文）。
- **緊湊最小欄寬 100 → 130px**：一對二 split 後每卡 ~61px，可用文字 ~47px（約 3-4 字中文）。
- **緊湊模式 `.course-block` padding 由 `4px 6px` → `4px 4px`**：左右各省下 2px（共 4px）給文字，讓緊湊模式的 split 卡片能多擠出 1 個字的寬度。

### 業界數據（僅緊湊模式 split 後的每卡寬度）
| 情境 | 每卡寬 | 文字寬 | 中文字數 @ 11px font |
|---|---|---|---|
| 130px 欄位 × 單一學生 | 130px | 118px | ≥ 10 字 |
| 130px 欄位 × 一對二 split | 61px | 49px | ~4 字 |
| 130px 欄位 × 一對三 split | 40px | 32px | ~3 字 |
| 140px 欄位（標準）× 一對二 split | 66px | 54px | ~5 字 |

### 回歸防護（勿回退）
- 最小欄寬 **不可**再降回 100px / 120px，否則一對二／一對三 split 會重現截斷 bug。
- `.teacher-grid-compact .course-block` 的 `padding: 4px 4px` **不可**再放寬至 `4px 6px`，否則 split 後文字區會縮回原本寬度。
- `getTeacherCourseBlockStyle` 的 split 邏輯未變動（一對二 = 50/50、一對三 = 33/33/33），勿改。

### 受影響檔案
- `frontend/src/pages/SmartCalendar.vue`（`gridTemplateStyle` min col width、`.teacher-grid-compact .course-block` padding）

## 2026-04-16 (V) — 智慧排課：多老師排版可讀性（橫向捲動 + sticky 時間欄）

### Problem
- 新店等老師多的分校，日檢視 `gridTemplateColumns: 56px repeat(N, minmax(0, 1fr))` 會把每欄無限壓縮（老師越多欄位越細），配上 `.teacher-grid-wrapper { overflow-x: hidden }` 完全禁止橫向捲動，導致課程卡片縮到 ~40px、學生姓名只顯示「數…」「理…」兩個字，主任根本無法辨識是誰的課。

### Fixed
- **老師欄最小寬度**：`gridTemplateStyle` computed 改為 `56px repeat(N, minmax(120px, 1fr))`（標準模式）／ `minmax(100px, 1fr)`（`isTeacherGridCompact` 緊湊模式，老師 ≥ 10 人）。6 位老師以下欄位仍會撐滿（不出現捲軸）；超出可容納數量時，欄位維持最小寬度並觸發橫向捲動。
- **開啟橫向捲動**：`.teacher-grid-wrapper` 由 `overflow-x: hidden` 改為 `overflow-x: auto`，配合 `-webkit-overflow-scrolling: touch`。
- **時間欄 sticky 生效**：`.week-view` 由 `overflow: hidden` 改為 `overflow: clip`（CSS 2021，Chrome 90+／Safari 16+ 已全面支援）— 保留裁切外觀但**不建立 scroll container**，因此不會讓子孫 `position: sticky` 失效，也不截斷 `.teacher-grid-wrapper` 的橫向捲動。
- **時間欄固定左側**：`.time-col` 原本已宣告 `position: sticky; left: 0; z-index: 5; background: var(--bg-muted, #f8fafc)`，過去因 `.week-view` 的 `overflow: hidden` 失效；改為 `overflow: clip` 後自動生效。
- **左上角 corner cell 固定**：`.col-header-blank` 新增 `position: sticky; top: 0; z-index: 6; background: var(--bg-muted, #f8fafc)`，讓時間欄與老師欄標題的交集永遠可見（避免橫向捲動 + 縱向捲動時出現撕裂視覺）。
- **手機斷點**：`@media (max-width: 768px)` 的 `.teacher-col` 由 `min-width: 0` 改為 `min-width: 80px`，手機也能橫滑。

### 業界對照（120px/100px 最小欄寬依據）
- **120px（標準）**：與 Google Calendar 桌機資源欄預設、Calendly 主持人欄等寬，可顯示 5 字中文姓名（欄寬 120 − 22 card padding − 28 badge padding = 70px ≥ 5 字 @ 12px）。
- **100px（緊湊，≥ 10 人）**：與 Notion Calendar 資源欄最小值一致，至少顯示 4 字，仍可辨識。
- **80px（手機）**：與 Google Calendar 行動版一致，顯示 2–3 字 + 橫滑。

### 為何用 `overflow: clip` 而非 `hidden`
- `overflow: hidden` 會建立 scroll container，使子孫 `position: sticky` 的「最近 scroll container」指向 `.week-view`，但 `.week-view` 沒有捲動行為 → sticky 名義上存在但永不觸發。
- `overflow: clip`（CSS Overflow Module Level 3，2021）同樣裁切內容維持外觀，但**不建立 scroll container、不影響子孫 sticky**，是本場景最小改動解法。相容性：Chrome 90+（2021.4）、Firefox 81+、Safari 16+（2022.9），目標用戶為主任桌機 Chrome，完全支援。
- 放棄方案：JS 同步雙面板（改動大）、`transform: translateX` 補位（repaint lag）、改寫為 `<table>` 結構（破壞性大）。

### 回歸防護（勿回退）
- `gridTemplateColumns` **不可**改回 `minmax(0, 1fr)`（會讓欄位無限壓縮，重現「姓名顯示 2 字」的 regression）。
- `.teacher-grid-wrapper` **不可**改回 `overflow-x: hidden`（會截斷橫向捲動）。
- `.week-view` **不可**改回 `overflow: hidden`（會讓 `.time-col` 與 `.col-header-blank` 的 sticky 全部失效）。
- `.time-col` 的 `background: var(--bg-muted)` **不可**移除（否則捲動時會透視背後課程卡片）。
- `.col-header-blank` 的 `sticky top: 0` **不可**移除（否則橫向 + 縱向捲動時左上角會撕裂）。
- 容量徽章（`.capacity-badge` / `.capacity-badge-compact`）位置與外觀未變動，勿誤觸。

### 受影響檔案
- `frontend/src/pages/SmartCalendar.vue`（`gridTemplateStyle` computed + `.week-view` / `.teacher-grid-wrapper` / `.col-header-blank` / 手機斷點 `.teacher-col` CSS）

### 驗收
- ≤ 6 位老師：欄位撐滿不出現捲軸（視覺與修改前一致）。
- ≥ 10 位老師：橫向捲軸出現，時間欄（08:00、09:00…）捲動時固定左側；老師欄標題（姓名 + 頭像）固定頂部；左上角 corner 固定。
- 課程卡片拖放（drag & drop）、容量徽章、`slot-room-full` 斜線視覺均正常。
- 手機橫滑正常，每欄 ≥ 80px。

## 2026-04-16 (U) — 智慧排課：老師時段容量徽章

### Added
- **容量徽章**：SmartCalendar 日檢視中，每位老師有課的**起始整點**格子右上角顯示「X/Y」徽章（X=目前學生人數、Y=班型上限）。
- **動態分母**：分母依班型自動決定 — 一對一 = 1、一對二 = 2、其餘（一對三／輔導／試聽）= 3。一對一課永遠顯示 1/1（已滿）。
- **三色語意**：綠 = 還可再收多位；橘 = 剩 1 位；紅 = 已滿。
- **說明 Legend**：toolbar 右側新增「班型容量」圖例（1/3 可加 / 2/3 剩 1 位 / 3/3 已滿），讓主任一眼看懂徽章意義。
- **清楚 tooltip**：hover 顯示「此時段學生 X 位（上限 Y 位，已滿／可再收 N 位）」。
- **緊湊模式降級**：老師欄 ≥ 10 時徽章縮為彩色小圓點，hover 顯示 tooltip。

### 商業規則裁決（主任 2026-04-16 確認）
- **容量上限寫死於程式**：一對一=1、一對二=2、一對三/輔導/試聽=3。與班型命名對應，不做成可設定（無維護成本）。未來若某校區需要不同容量規則，再評估新增 `Campus` 欄位。
- **半小時起始時間（08:30、09:30）** 以該整點 row 分組（`parseHour(08:30) = 8`），與既有日檢視視覺一致。徽章為視覺概略指示；真正時段衝突仍由 `checkConflict` 的重疊演算法把關。
- **每筆 `StudentClass` = 1 位學生**：`count` 直接取 `coursesAtSlot.length`，不用 `CAPACITY_MAP` 加權（`CAPACITY_MAP` 保留給 `checkConflict` 使用，不可混淆）。

### 回歸防護（勿回退）
- `getSlotOccupancy` 的 count **不可**改回用 `CAPACITY_MAP` 加權（曾在 2026-04-16 造成 1 位一對三學生顯示 3/3 紅色滿員的 bug）。
- 徽章**只在起始整點**顯示；勿改回跨整點每格都顯示（會造成跨小時課程徽章重複）。
- 分母須依班型動態決定；勿改回一律 3/3（一對一會誤顯示 1/3 綠色，讓主任誤以為可加學生）。
- `CAPACITY_MAP`、`getCoursesForTeacherAt`、`isSlotRoomFull`、`checkConflict` 均未修改，勿誤觸動。

### 受影響檔案
- `frontend/src/pages/SmartCalendar.vue`（新增 `getSlotOccupancy` helper + `capacity-badge` template/CSS）

### 回歸防護
- `CAPACITY_MAP`、`getCoursesForTeacherAt`、`isSlotRoomFull`、`checkConflict` 均未修改。
- 純前端 computed 顯示，無後端 API 或資料表異動。

## 2026-04-16 (T) — 催繳名單：幽靈課程偵測與結案功能

### Added
- **「已有新課程」偵測**：`GET /api/v1/alerts/tuition` 每筆 `renew_needed` 記錄新增 `has_newer_course`（布林）、`newer_course_id`、`newer_course_remaining`、`newer_course_start_date` 欄位。偵測同分校、同學生、同科目（`SubjectID`）、`Stop=0` 的其他活躍課程。
- **催繳名單「結案」按鈕**：`TuitionCollectionPage.vue` 的 `renew_needed` 行新增綠色「已有新課程」badge（偵測到時）與紫色「結案」按鈕。點擊後顯示確認 dialog（含學生名、科目、課程 ID、剩餘堂數、新課程資訊），確認後呼叫 `POST /api/v1/student-classes/{id}/pause` with `reason='settled'`。
- **`closed_reason='settled'` 支援**：`StudentClassController::togglePause` 新增 `settled` 結案理由，設定 `Stop=1`、`closed_reason='settled'`、`EndDate=today`，並取消未來排課（標記 `[結案取消]`）。
- **3 個新測試**：`TuitionAlertsApiTest` 新增 `has_newer_course` true/false 偵測、settle 結案後從催繳名單消失。

### 背景
- 工作人員在學生需續課時，未使用「續報加購」（`purchase-batch`）而直接「新增課程」，導致舊課程未自動關閉、永久出現在催繳名單。
- 資料調查：全四間分校共 3 筆幽靈課程（新店 2 筆、興隆 1 筆）。

### 受影響檔案
- `backend/app/Http/Controllers/AlertController.php`（新增 `newerCourseByStudentClassIds`、tuition 回傳新欄位）
- `backend/app/Http/Controllers/StudentClassController.php`（`togglePause` 支援 `settled` 理由）
- `frontend/src/pages/TuitionCollectionPage.vue`（結案 UI：badge、按鈕、dialog、toast）
- `backend/tests/Feature/TuitionAlertsApiTest.php`（3 個新測試）

### 回歸防護
- 結案僅設 `Stop=1` 和 `closed_reason='settled'`，**不改 `Paid` 欄位**，不影響財務記錄。
- 重用現有 `pause` 端點，受 `auth`、`role`、`require_campus` middleware 保護。
- 「已有新課程」偵測使用 batch query（`newerCourseByStudentClassIds`）避免 N+1。

## 2026-04-16 (S) — 催繳名單：已續課自動抑制舊課程續課提醒

### Fixed
- **已續課仍出現在催繳名單**：堂數制課程 `low_sessions` 提醒（`RemainingSessions <= 2`）未檢查同一學生是否已有同科目的續課。當主任為學生新建續課後，舊課程仍因剩餘堂數不足而出現在催繳名單，造成混淆。
- **修正**：`AlertController::tuition` 新增 `suppressRenewedLowSessionAlerts` 後處理邏輯：若同一學生同一科目已有另一筆 `Stop=0` 且 `RemainingSessions > 2` 的課程，則舊課程的 `low_sessions` 提醒自動抑制。`unpaid` 類型不受影響。

### 受影響檔案
- `backend/app/Http/Controllers/AlertController.php`（新增 `suppressRenewedLowSessionAlerts`）
- `docs/DIRECTOR_PAYMENT_ALERT_RULES.md`（新增「續課抑制」段落）

### 回歸防護
- 續課抑制僅影響 `low_sessions`（已繳需續課），不影響 `unpaid`（未繳費）提醒。
- 判定續課的條件：同一 `StudentID` + `SubjectID`、`Stop=0`、`RemainingSessions > 2`。

## 2026-04-16 (R) — 課程管理固定排課星期同步修正

### Fixed
- **新增星期未觸發 remap**：`syncFutureScheduledSessionTimes` 原本只在「現有堂次在契約外星期」時觸發 remap；當使用者**新增**星期（如一二→一二四），所有既有堂次仍在契約內，導致不觸發 remap、新星期無堂次。現在雙向偵測：契約有但堂次沒有的星期也觸發 remap。
- **`force_partial_rebuild` reconcile 覆寫**：第二次 PUT（`force_partial_rebuild: true`）無條件呼叫 `reconcileWeekTimeFieldsFromSessions`，即使 sync 回傳 0 筆更新也執行，導致舊 ClassSession 的星期回寫覆蓋使用者剛存的新契約。現在 `reconcile` 僅在 `updatedCount > 0` 時才執行。
- **前端同步計數不完整**：成功訊息只計算第二次 PUT 的 `updated_future_sessions`，忽略第一次 PUT 已同步的堂次。現在加總兩次 PUT 的計數。
- **課程列表刷新時機**：`loadCourses()` 在 `alert()` 之前 await，使用者確認訊息時列表已是最新資料。

### 受影響檔案
- `backend/app/Http/Controllers/StudentClassController.php`（`syncFutureScheduledSessionTimes`、`force_partial_rebuild` 分支）
- `backend/tests/Feature/StudentClassUpdateScheduleReconcileTest.php`（新增 2 個測試案例：加星期 remap、force_partial_rebuild 不覆寫）
- `frontend/src/pages/CourseManagement.vue`（同步計數加總、await loadCourses）

### 回歸防護
- 禁止將 `$needsRemap` 偵測改回只看「堂次在契約外」；必須同時檢查「契約有但堂次沒有」的方向。
- `force_partial_rebuild` 路徑的 reconcile 必須有 `updatedCount > 0` 守衛，與主路徑 `$skipReconcile` 邏輯一致。

---

## 2026-04-16 (Q) — 老師評量表草稿續填（本機暫存）

### Added
- **草稿自動暫存**：老師在學習評量表填寫途中離開（切換學生、關閉 modal），系統自動將表單內容保存到本機 localStorage，回來時自動恢復。
- **草稿管理模組**：新增 `frontend/src/lib/learningRecordDrafts.js`，獨立於 `LearningRecordsPage.vue` 之外，負責草稿 key 建立、版本控制（v1）、7 天過期策略、容量限制與批次清理。
- **草稿清單入口**：老師可從頁面標題列「草稿」按鈕查看所有未完成草稿，含學生、科目、日期、時段與儲存時間。可手動清除（含二次確認）。
- **草稿狀態列**：在 modal 表單頂部顯示「草稿已於 HH:MM 自動儲存」或儲存失敗提示，含清除草稿按鈕。
- **登出清除**：`App.vue` logout handler 於登出時清除該老師所有本機草稿，防止共用裝置下的資料外洩。
- **舊版草稿遷移**：自動清除舊格式 `lr_draft_{userId}_{studentId}_{date}` key。

### Changed
- **草稿 key 格式**：從 `lr_draft_{userId}_{studentId}_{date}` 改為 `lr_draft_v1_{teacherId}_{classSessionId}`（含 teacherId 防碰撞），過期時間從 1 天延長至 7 天。
- **節流策略**：自動保存改為 1.5 秒節流（throttle），取代逐次按鍵同步寫入，降低 localStorage 操作頻率。
- **關閉時 flush**：modal 關閉前先 flush 尚在節流中的草稿，確保最後一次編輯不遺失。
- **已核准記錄不存草稿**：`approved` 狀態的評量在編輯時不會觸發 saveDraft，也不會載入草稿。

### 受影響檔案
- `frontend/src/lib/learningRecordDrafts.js`（新增）
- `frontend/src/pages/LearningRecordsPage.vue`（草稿整合、草稿清單 UI、CSS）
- `frontend/src/App.vue`（logout 清除草稿）

---

## 2026-04-16 (P) — 催繳名單頁面優化：付款狀態精確化 + 快速操作 + 撤銷收款

### Added
- **`payment_status` 六種狀態值**：`GET /api/v1/alerts/tuition` 每筆新增 `payment_status`（`unpaid` / `partial` / `pending_report` / `paid` / `renew_needed` / `monthly_due_soon`）、`charge`（應繳）、`paid_amount`（已繳）、`outstanding`（未結清）、`latest_payment_report_id`（最近待核帳 report id）。狀態由後端計算，前端不自行推導。
- **撤銷收款 API**：`PUT /api/v1/payment-reports/{id}/void`（僅限 director/admin/super_admin）。建立負值 Payment 沖銷、重算 Invoice.PaidAmount/Status、重置 StudentClass.Paid=0/PayDate=null、report 標記 voided。全程 DB transaction + `Log::info('[PaymentVoid]')`。
- **Migration**：`payment_reports` 表新增 `voided_by`、`voided_at`、`void_reason` 欄位。

### Changed
- **催繳名單表格重設計**：移除語意不清的「繳費日期」欄，新增「應繳」「已繳」「未結清」三個金額欄位 + 六種狀態標籤色彩系統。
- **快速操作按鈕**：根據 `payment_status` 動態顯示「核帳登記」「確認入帳」「退回」「撤銷收款」按鈕。所有操作完成後自動刷新名單。
- **Summary cards**：新增第四張「未結清總額」卡片（紅色）。
- **排序邏輯**：改為按 `payment_status` 優先序排列（未繳→部分→待核帳→月結→續課→已繳），取代原有 paid/unpaid 二分法。
- **Skeleton loading**：首次載入改為 5 列骨架 + shimmer 動畫，取代舊有 spinner。
- **Toast 系統**：支援成功（灰）、警告（橘）、錯誤（紅）三種顏色。
- **響應式**：手機版（< 768px）改為卡片式佈局。
- **撤銷確認彈窗**：含原因 textarea（必填 max 500 字）、danger 按鈕、二次確認。

### 受影響檔案
- `backend/app/Http/Controllers/AlertController.php`（`tuition()` 補充欄位 + `computePaymentStatus()` + 批次查詢 helpers）
- `backend/app/Http/Controllers/PaymentReportController.php`（新增 `void()` 方法）
- `backend/app/Models/PaymentReport.php`（新增 void 相關 fillable + cast + relationship）
- `backend/routes/api.php`（新增 void 路由）
- `backend/database/migrations/2026_04_16_210000_add_void_fields_to_payment_reports_table.php`
- `frontend/src/pages/TuitionCollectionPage.vue`（完整重構）
- `backend/tests/Feature/TuitionAlertsApiTest.php`（新增 7 筆 payment_status 測試）
- `backend/tests/Feature/PaymentReportApiTest.php`（新增 4 筆 void API 測試）

### 禁止回歸
- `alerts/tuition` 的列入條件不得因 payment_status 補充欄位而改變（依 `DIRECTOR_PAYMENT_ALERT_RULES.md`）
- 已繳（`paid=true`）不得產出催繳通知單圖片（`tuitionSlipData` 的 422 guard 不得移除）
- `payment_status` 計算必須集中在後端 `AlertController::computePaymentStatus`，前端禁止自行推導
- void API 的 DB transaction 不得拆開（Payment/Invoice/StudentClass 三表一致性）
- void API 僅限 `role:director,admin,super_admin`，teacher 角色不可呼叫
- void 操作必須有 `voided_by` + `voided_at` + `void_reason` 稽核欄位

---

## 2026-04-16 (O) — 學習評量顯示堂次序號（第 X 堂）

### Added
- **後端 `session_number` 欄位**：`LearningRecordController::batchSessionNumbers()` 靜態方法，批次計算每筆 learning record 在所屬課程中的堂次序號。口徑：以 `ClassSession` 按日期時間排序，排除 `cancelled/leave/leave_adjusted/excused` 後累計編號。
- **老師/主任端 API**：`GET /api/v1/learning-records` 回傳新增 `session_number` 欄位（整數或 null）。
- **家長端 API**：`GET /api/v1/parent/dashboard` 的 `learning_records` 回傳新增 `session_number` 欄位。
- **匯出評量圖**：匯出圖片每筆評量標題列從 `#1/#2`（匯出順序）改為「第 X 堂」（課程堂次序號），無法判定時降級回 `#index`。
- **老師端列表與 Modal**：評量列表日期欄旁顯示藍色「第 X 堂」標籤；Modal header 也顯示堂次。
- **家長端學習評量卡片**：科目名稱旁顯示藍色「第 X 堂」標籤，展開收合時固定可見。

### 受影響檔案
- `backend/app/Http/Controllers/LearningRecordController.php`（新增 `batchSessionNumbers`，`index()` 附加欄位）
- `backend/app/Http/Controllers/ParentPortalController.php`（`dashboard()` 附加欄位）
- `frontend/src/lib/learningRecordExport.js`（`drawRecordSection` 使用 `session_number`）
- `frontend/src/pages/LearningRecordsPage.vue`（列表 + Modal 顯示堂次 + CSS）
- `frontend/src/pages/ParentPortal.vue`（卡片 header 顯示堂次 + CSS）

### 備註
- 堂次口徑與課程管理 `useCourseSessionsDisplay.js` 的 `getSessionNumber` 一致：請假/取消堂不佔序號。
- 後端統一計算，避免家長端分頁或老師端批次匯出時前端自算不一致。
- 無新增 migration，堂次序號為查詢時動態計算。

---

## 2026-04-16 (N) — UI 精緻化：課程管理與學生頁「已完課/已結算」呈現重構

### Changed
- **進行中 vs 歷史課程分區**：`CourseManagement.vue` 與 `StudentsList.vue` 的課程列表不再將已完課/已結算課程以灰底行混在進行中課程中。改為：
  - 主表格只顯示進行中及已暫停的課程。
  - 已完課/已結算課程收進可展開的「歷史課程」區塊（卡片式佈局），預設收合。
- **歷史課程卡片**：以左側色帶 + 結構化卡片呈現歷史課程，包含科目、老師、費用、堂數摘要，並可展開查看堂次 chip。已結算標籤為綠色、已完課標籤為藍色，不再共用灰色 `tag-settled`。
- **學生分組 header 改善**：顯示「N 筆進行中 · N 筆歷史」取代原本的「N 筆課程」。
- **空狀態**：學生只有歷史課程時，主表格顯示「目前沒有進行中的課程」+ 引導下方歷史區塊。
- **暗色模式支援**：新增歷史區塊、卡片、標籤的 dark theme 樣式。
- **操作精簡**：歷史課程僅保留「查看堂次」「編輯」「恢復課程」「刪除」入口，不再顯示「新增堂次」「切換繳費」等不適用於歷史課程的操作。

### 受影響檔案
- `frontend/src/pages/CourseManagement.vue`（template 分區 + JS helpers + styles）
- `frontend/src/pages/StudentsList.vue`（template 分區 + JS helpers + styles）

### 備註
- 無後端改動，不影響 `closed_reason`、`Stop`、堂數扣除等商業邏輯。
- 已暫停課程仍留在主表格（非歷史區塊），維持原有暫停提示與恢復操作。

---

## 2026-04-16 (M) — Bug Fix：智慧排課近期課堂不顯示

### Fixed
- **孤兒 `schedules` 調課記錄遮蔽已上課堂**：鄭翔祐 × 黃品皓 4/16 課堂（ClassSession status=attended）因 `schedules` 表存在 `status=rescheduled` 但無對應目標日期的孤兒記錄，SmartCalendar 的 `hasReschedule` 判斷將該堂隱藏。已刪除孤兒記錄（schedule id=272），並在 `filteredCourses` 中新增防禦邏輯：若 ClassSession 已為 `attended`，則忽略 `rescheduled` 遮蔽。
- **SmartCalendar `fetchClassSessions` 未傳日期範圍**（預防性修復）：`GET /api/v1/class-sessions` 以 `SessionDate ASC` 排序分頁（每頁 2000），歷史堂次多的課程近期日期可能被截斷。現已補上 `start`/`end` 參數，與 schedules 窗口（顯示月份 ±2 個月）對齊。
- **LearningRecordsPage 同問題**：老師端與主任端的 `fetchClassSessions` 呼叫（`perPage: 500`）同樣無日期範圍，已補上 ±2 個月窗口。
- **CourseManagement 批次載入**：`useCourseSessionsDisplay` 的 `loadClassSessionsForCourses` 同樣缺少日期範圍，已補上 ±2 個月窗口（單課程展開仍載入全量，不受影響）。

### 受影響檔案
- `frontend/src/pages/SmartCalendar.vue`（`hasReschedule` 增加 attended 防禦 + `fetchClassSessions` 加 `start`/`end`）
- `frontend/src/pages/LearningRecordsPage.vue`（`fetchTeacherSessionDates`、`fetchDirectorSessionsForCourses` 加日期範圍）
- `frontend/src/composables/course-management/useCourseSessionsDisplay.js`（`loadClassSessionsForCourses` 加日期範圍）
- DB：刪除 `schedules` id=272（孤兒 rescheduled 記錄，student_course_id=64, date=2026-04-16）

---

## 2026-04-16 (L) — Bug Fix：LINE 綁定徽章解除後仍顯示

### Fixed
- **前端快取未更新**：`StudentsList.vue::removeLineBinding()` 解除成功後，現在會同步將 `students.value` 中對應學生的 `line_bound` 更新為 `lineBindings.value.length > 0`，不再需要重整頁面。
- **後端資料來源不準**：`StudentController::transformStudent()` 改用 `student_line_bindings` 表判斷 `line_bound`（`index()` 批次 `whereIn`，`show()` 用 `exists()`），取代只看 `Student.LineID`。修正了多家長情境下「解除最後綁定者 → LineID 清空但另一方綁定仍存在 → 應顯示 true 卻顯示 false」的錯誤。

### 受影響檔案
- `backend/app/Http/Controllers/StudentController.php`（`transformStudent()` 接受 `$boundIds` 參數；`index()` 批次查詢；`show()` 改 `exists()`）
- `frontend/src/pages/StudentsList.vue`（`removeLineBinding()` 成功後更新 `students.value[idx].line_bound`）

---

## 2026-04-16 (K) — 主任後台：學生 LINE 綁定管理介面

### Added
- **`GET /api/v1/students/{id}/line-bindings`**（director 限定）：回傳該學生的 LINE 綁定清單，`line_user_id` 以 masked 格式（前 8 碼 + … + 後 4 碼）回傳，不曝露完整值。
- **`DELETE /api/v1/students/{id}/line-bindings/{bindingId}`**（director 限定）：解除單筆 LINE 綁定。若被解除的 `line_user_id` 與 `Student.LineID` 相同，一併清空 `Student.LineID`。操作記錄 `Log::info`。
- **`StudentsList.vue` 編輯 modal**：RFID 區塊下方新增「LINE 綁定家長」section，顯示 masked LINE ID + 綁定時間 + 逐筆「解除」按鈕。無綁定時顯示空狀態文字；解除需 confirm dialog 確認，成功後 toast 提示。

### 受影響檔案
- `backend/app/Http/Controllers/StudentController.php`（新增 `lineBindings()`、`removeLineBinding()`）
- `backend/routes/api.php`（新增兩條路由）
- `frontend/src/pages/StudentsList.vue`（modal 新增 LINE 綁定 section + fetch/delete 邏輯 + toast）

---

## 2026-04-16 (J) — 多家長 LINE 綁定（爸媽各自綁定同一學生）

### Added
- **`student_line_bindings` 表**：新增關聯表（`student_id`, `line_user_id`, `campus_id`, `bound_at`），`(student_id, line_user_id)` UNIQUE。Migration 自動將既有 `Student.LineID` 反轉寫入新表，零遺失。
- **多家長綁定**：同一學生可被多個 LINE 帳號（爸爸、媽媽各自）綁定，互不覆蓋。
- **`StudentLineBinding` model**：對應新表的 Eloquent model。

### Changed
- **`LineWebhookController`**：
  - `bindStudent()` 改為向 `student_line_bindings` 寫入（`insertOrIgnore` 防重複），同時保留 `Student.LineID` 向下相容。
  - 所有「已綁定」判斷改查 `student_line_bindings.where(student_id, line_user_id).exists()`。
  - `handleFollow` 的「歡迎回來」改查新表找綁定學生。
  - `buildStatus` 的 `bound_count` 改查新表。
- **`ParentPortalController`**：
  - `loginWithLine()` 改查 `student_line_bindings` 取得學生列表。
  - `dashboard()` sibling 查詢改用新表找共享 `line_user_id` 的學生。
  - `switchStudent()` 授權檢查改查新表（兩學生是否有共用 `line_user_id`）。
  - `line_linked` 改查新表 `exists()`。

### 受影響檔案
- `backend/database/migrations/2026_04_16_200000_create_student_line_bindings_table.php`（新增）
- `backend/app/Models/StudentLineBinding.php`（新增）
- `backend/app/Http/Controllers/LineWebhookController.php`
- `backend/app/Http/Controllers/ParentPortalController.php`

### 備註
- `Student.LineID` 欄位保留不刪，新綁定仍同步寫入，確保其他讀取 `Student.LineID` 的地方（如 CSV export）不受影響。
- 重複綁定（同一家長對同一學生）由 UNIQUE index + `insertOrIgnore` 防呆，回傳「已經綁定過了」友善提示。

---

## 2026-04-16 (I) — LINE 家長入口 LIFF 直登（免輸入姓名手機）

### Added
- **LIFF 直登流程**：已綁定 LINE 的家長從 LINE 官方帳號 LIFF 入口進入時，自動以 `lineUserId` 建立家長 session，直接進入 dashboard，不再停在姓名+手機登入頁。
- **`GET /api/v1/parent/resolve-liff`**：公開端點，根據請求 hostname 比對 `Campus.URL` 回傳對應的 `liff_id`，讓多分校部署的前端可動態取得正確的 LIFF ID。
- **前端 `resolveParentLiffIdAsync`**：`ParentPortal.vue` 在 LINE 瀏覽器中啟動時，若本地無 liffId，自動呼叫 `resolve-liff` API 取得，再初始化 LIFF SDK 並執行自動登入。

### 受影響檔案
- `backend/app/Http/Controllers/ParentPortalController.php`（新增 `resolveLiff` 方法）
- `backend/routes/api.php`（新增 `GET parent/resolve-liff` 公開路由）
- `frontend/src/pages/ParentPortal.vue`（新增 `resolveParentLiffIdAsync`、`onMounted` 流程調整）

### 備註
- 後端 `loginWithLine` API（`POST /api/v1/parent/login-line`）已於先前版本實作，本次僅補齊前端 LIFF ID 動態解析。
- 未綁定的家長仍自動回退至姓名+手機手動登入表單。

---

## 2026-04-16 (H) — 學生改名後 LINE 綁定 / 家長登入找不到學生

### Fixed
- **名字 trim**：`StudentController::store()` 與 `update()` 存入 DB 前對 `name` 執行 `trim()`，避免帶空白的名字導致後續查詢失敗。前端 `StudentsList.vue` `submitStudent` 同步 trim 並驗證空值。
- **編輯不覆蓋校區**：`StudentController::update()` 移除 `branch_id → CampusID` 的無條件覆蓋邏輯；`StudentsList.vue` PUT payload 不再帶 `branch_id`。防止編輯學生名字/電話等基本資料時意外移動學生到另一校區（導致 LINE 綁定以校區過濾而找不到）。
- **名字查詢容錯**：`ParentPortalController::login` 與 `LineWebhookController`（`handleBindingByNameOnly` / `handleBindingByName`）改用 `whereRaw('TRIM(name) = ?', [...])` 查詢，相容 DB 中既有含前後空白的舊資料。

### 受影響檔案
- `backend/app/Http/Controllers/StudentController.php`
- `backend/app/Http/Controllers/ParentPortalController.php`
- `backend/app/Http/Controllers/LineWebhookController.php`
- `frontend/src/pages/StudentsList.vue`

---

## 2026-04-16 (G) — 學習評量單筆下載圖檔 + 學生姓名修正 + 彈窗 cursor 提示

### Added
- **單筆評量下載 PNG**：「檢視評量」readonly modal header 新增「下載圖檔」按鈕，呼叫 `generateStudentCardPng` 生成含學生姓名、科目、授課老師、成績等完整資訊的 PNG 圖檔。含 loading 狀態與成功/失敗 toast。手機版縮為 icon only。

### Fixed
- **學生姓名顯示**：`currentStudentName` computed 原先僅查 `studentList`，若快取無該學生則顯示「學生 #666」。修正為優先取 `studentList`，再 fallback `_activeRecordRef.student_name`（record 本身帶回的名稱）。
- **彈窗 cursor 提示**：全域 `.modal-overlay` 新增 `cursor: pointer`，子元素重設 `cursor: default`，讓使用者看到可點擊背景關閉的視覺提示。

### 受影響檔案
- `frontend/src/pages/LearningRecordsPage.vue`
- `frontend/src/styles.css`

---

## 2026-04-16 (F) — 家長入口登入修復 + 錯誤訊息改善

### Fixed
- **資料修復**：黃品皓 `Student.Phone` 為 null 導致家長手機登入失敗；已補登。`Student.LineID` 被測試帳號佔用導致 LINE 綁定異常；已清除。
- **前端錯誤訊息**：`ParentPortal.vue` `login()` 的 `catch` 原先寫死「登入失敗，請確認學生姓名及手機號碼是否正確」，遮蓋後端精確原因。改為優先顯示 `error.message`（如「此學生尚未設定聯絡手機，請聯繫分校補登後再登入」），fallback 到原有預設文字。
- **登入 loading 防護**：登入按鈕在 API 請求期間顯示「登入中…」並禁用，防止重複送出。
- **錯誤訊息樣式**：`.pp-error` 色彩更新為 `#FEE2E2` 背景 + `#DC2626` 文字，與系統設計語言一致。

### 受影響檔案
- `frontend/src/pages/ParentPortal.vue`
- `Student` 資料表（id=4，Phone + LineID 欄位修復）

---

## 2026-04-16 (E) — 手機出缺勤頁 UI 修復（底部遮擋 + 滑動閃爍）

### Fixed
- **滑動閃爍**：`.mobile-bottom-nav` 加 `will-change: transform; transform: translateZ(0)` 升至 GPU 合成層，減少滑動時主執行緒 repaint。`body` 移除 `-webkit-overflow-scrolling: touch`（僅保留在 `html`）。
- **批次列遮擋**：`.att-sticky-batch` 的 `bottom` 從硬編碼 `68px` 改為 `calc(56px + env(safe-area-inset-bottom, 0px))`，動態適應 safe-area 裝置。批次列顯示時，`.att-cards` 動態加 `padding-bottom: 72px` 讓最後一張卡片可捲出批次列上方。
- **確認 sheet 被底欄遮擋**：`AttendancePage.vue` 的 `.att-confirm-overlay` 以 `<Teleport to="body">` 渲染至 root stacking context，使 `z-index: 10100` 正確蓋過底欄的 `z-index: 10000`。確認 sheet 樣式移至非 scoped `<style>` block。

### 受影響檔案
- `frontend/src/styles.css`
- `frontend/src/pages/AttendancePage.vue`

---

## 2026-04-16 (D) — 重複課程保護邏輯修正（SubjectID + ClassType）

### Fixed
- **後端 `EnrollmentService::store`**：重複課程保護原先僅比對 `SubjectID`，導致同科目不同教學類型（如數學一對一 vs 數學輔導）也被 409 擋下。修正為 `SubjectID + ClassType` 均相同才視為重複。`conflicts[]` 回應新增 `class_type` 欄位。
- **前端強制建立流程**：`StudentsList.vue` 與 `CourseManagement.vue` 的確認彈窗「我知道，仍要新增課程」按鈕原先僅重開空表單（再次送出仍 409，形成死循環）。修正為以 `originalPayload + force: true` 直接重送 API，成功後顯示建立結果並重整列表。

### Added
- **測試**：`tests/Feature/DuplicateCourseGuardTest.php`（3 案例：同科目不同 ClassType 允許建立、同科目同 ClassType 回 409、force=true 回 201）。

---

## 2026-04-16 (C) — 新建課程開課日設定優化（明確開課日 + 防誤標已上）

### Added
- **「開課日」欄位**：`UniversalClassScheduler.vue`（`mode="create"`）新增 date picker，預設今天。
  - 自動排課（`futureSessionOccurrences`）從 `max(今天, 開課日)` 起算，開課日前不產生預排堂次。
  - 設定開課日後月曆自動跳轉至該月。
  - 手動點選早於開課日的未來日期時，彈出確認框提示「將視為補登」。
- **後端 `course_start_date` 防禦驗證**：`POST /api/v1/class-sessions/batch` 接受 optional `course_start_date`；`EnrollmentService::store` 驗證 `kind='future'` 的堂次日期不早於開課日（違反回 422）。缺失時向下相容。
- **月曆視覺優化**：
  - 補登已上日期（`kind=confirmed`）：綠底 + 「補登」標籤。
  - 手動預排未來日期：紫框 + 「預排」標籤（與系統預排的藍框區分）。
  - 開課日格子底部顯示橙色「開課」旗標。
- **摘要面板優化**：送出前顯示「補登已上 N 堂」vs「預排未上 N 堂」，取代原先的「手動選定 N 堂」模糊標示。
- **測試**：`tests/Feature/CourseStartDateTest.php`（3 案例：遠未來開課日正確排課、早於開課日的 future 被 422 拒絕、無開課日向下相容）。

### Changed
- `docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`：新增§5「開課日欄位」語意說明。
- `isManualDateConfirmed` 語意**不受影響**（過去日仍為補登，未來日仍為預排）。

---

## 2026-04-16 (B) — 樹莓派儲存與 Log 寫入優化

### Added
- **Log Rotation**（FR-001）：`backend/config/logging.php` 的 `laravel.log` 從 `single`（永不輪轉）改為 `daily`（14 天保留），與 `perf.log` 一致。
- **Health 端點擴充**（FR-006）：`GET /api/v1/health` 新增 `log_pipeline` 區段，回傳 log driver、tmpfs 啟用狀態、使用率與健康判斷。
- **基礎設施腳本**：
  - `scripts/infra/baseline-capture.sh`（FR-000）：建立效能基線快照。
  - `scripts/infra/storage-inventory.sh`（FR-002）：盤點節點儲存介質，偵測 SD 卡。
  - `scripts/infra/setup-log-tmpfs.sh`（FR-004/005）：掛載 128 MB tmpfs + systemd flush timer（每 5 分鐘）+ 監控 timer（每 1 分鐘）+ 80% 自動降級。
  - `scripts/infra/rollback-log-tmpfs.sh`（FR-007）：一鍵回滾（< 5 分鐘），冪等。
  - `scripts/infra/test-log-infra.sh`（TEST）：自動化測試套件（17+ 案例）。
- **文件**：
  - `docs/baselines/baseline_20260416_143105.md`：首份基線快照。
  - `docs/QA_LOG_INFRA_ACCEPTANCE.md`：QA 驗收矩陣。
  - `docs/SECURITY_REVIEW_LOG_INFRA.md`：資安審查報告（無阻擋項）。

### Changed
- `ClassSessionController::index`、`LogSlowRequests`：`Log::channel('single')` → `Log::channel('daily')`，配合 logging.php 更名。
- `docs/OPERATIONS_RUNBOOK.md`：新增 §L（Log 管理與 Tmpfs 緩衝）。
- `docs/deploy-raspberry-pi.md`：新增 Log 管理段落。

### Security
- 發現既有 SQL error log 含 PII（Teacher 手機、姓名），建議後續收緊檔案權限與 mask SQL 參數（非阻擋項）。

---

## 2026-04-16 (A) — 資料庫效能優化 P0（索引補齊 + N+1 修復 + P1 讀寫分離準備）

### Added
- **Migration `2026_04_16_100000_add_core_perf_indexes`**：為 8 張核心表新增 17 個索引
  - `Student`: `(CampusID, name)`, `(CampusID, status)`, `(RFID)`
  - `StudentClass`: `(StudentID)`, `(TeacherID)`, `(Stop, StudentID)`
  - `ClassSession`: `(Status)`
  - `StudentSingIn`: `(StudentID)`, `(StudentClassID)`, `(SignInDT)`
  - `Invoice`: `(StudentID)`, `(StudentClassID)`, `(Status)`
  - `Payment`: `(InvoiceID)`
  - `UserCampus`: `(UserID)`, `(CampusID, Approved)`
- **`config/database.php`**：新增 P1 讀寫分離框架（`read/write/sticky`）與 persistent PDO 選項
  - 預設關閉（`DB_READ_HOST` 未設定時全走 primary）
  - `sticky = true` 確保寫後讀一致性
- **效能基線報告**：`docs/DB_PERF_BASELINE_2026-04-16.md`
- **資安審查報告**：`docs/DB_PERF_SECURITY_REVIEW_2026-04-16.md`
- **測試**：`tests/Feature/DatabasePerfTest.php`（25 案例，驗證索引存在與 EXPLAIN 命中）

### Changed
- **`StudentController::activeCourses`**：修復 N+1 查詢，從迴圈內逐筆查 Subject 改為批次 `whereIn` 查詢

### Performance
- 優化前：8 張核心表中 9 組常用查詢全部走 `type: ALL`（全表掃描）
- 優化後：全部降為 `type: ref`（索引查詢），查詢計畫複雜度從 O(n) 降至 O(log n)
- 索引總額外空間：約 200 KB

### Rollback
- `php artisan migrate:rollback --step=1` 即可移除所有新增索引
- 完整備份：`backups/alltrue_pre_perf_optimization_2026-04-16.sql`

## 2026-04-15 (J) — 學收核銷系統（家長繳費回報 + 主任核帳 + 電子收據）

### Added
- **新資料表 `payment_reports`**：家長繳費回報紀錄（匯款/現金、後5碼、申報金額、核帳狀態）
- **`Payment` 表**新增 `receipt_url`、`payment_report_id` 欄位
- **`Invoice` 表**新增 `reconciled_at`、`reconciled_by` 欄位
- **`PaymentReportTokenService`**（`backend/app/Services/`）：HMAC-SHA256 簽署 token 產生/驗證/防重送
- **`PaymentReportController`**（`backend/app/Http/Controllers/`）：
  - `POST /api/v1/payment-reports/generate-link`（director）：產生家長回報連結
  - `GET /api/v1/pay-report?token=`（public）：家長取得表單資料
  - `POST /api/v1/pay-report`（public）：家長提交繳費回報
  - `GET /api/v1/payment-reports`（director）：待核帳列表
  - `PUT /api/v1/payment-reports/{id}/confirm`（director）：確認入帳 → 建 Payment + 更新 Invoice
  - `PUT /api/v1/payment-reports/{id}/reject`（director）：退回回報
  - `GET /api/v1/payment-reports/{id}/receipt`（director）：收據資料
- **`PayReportPage.vue`**（`frontend/src/pages/`）：家長無登入獨立頁，Token 驗證後填寫繳費回報
- **`ReceiptModal.vue`**（`frontend/src/components/`）：Canvas 繪製正式電子收據（綠色主題）
- **`TuitionCollectionPage.vue`** 大幅改版：新增「催繳名單」/「待核帳」雙 Tab、「回報連結」按鈕、核帳/退回/收據操作
- **`ParentPortal.vue`**：帳單列表增加「已核帳」徽章
- **`App.vue`**：新增 `/pay-report` URL 路由偵測，導向 PayReportPage

### Security
- Token 72 小時有效，HMAC-SHA256 簽署，DB 存 hash 防重送
- 帳號後5碼驗證（僅數字、max 5 碼）
- 繳費日期不可超過今天、金額上限 999,999
- 分校隔離：payment_reports 列表依 student.CampusID 過濾

### Tests
- `PaymentReportApiTest`：13 tests / 51 assertions 全部通過
  - generate-link、formData、submit（匯款/現金/重複送出/後5碼驗證）、list、confirm、reject、receipt

### Notes
- 電子收據 ≠ 法定電子發票，僅供內部繳費確認用途
- 第二期規劃：LINE 推送收據、核帳月報匯出、老師收款入口

## 2026-04-15 (I) — 課程管理 chip 重複修正（LEFT JOIN 行乘積）

### Fixed
- **`backend/app/Http/Controllers/ClassSessionController.php`**
  - `index` 方法的 `sub_sched`（代課 schedules）、`LearningRecord`、`StudentSingIn` 三個 LEFT JOIN 改為 Derived Table Subquery，每組合只取 `MAX(id)` 一筆，消除同一 `ClassSession` 因 1:N 關係被重複回傳的問題。
  - 案例：木柵校林宥彣理化（course 70）1/12 有 3 筆代課 schedule，導致該 ClassSession 回傳 3 次。
- **`frontend/src/lib/classSessionsApi.js`**
  - `normalizeClassSessionsPayload` 新增 id 去重防禦（`byClass[key].some(r => r.id === item.id)`），防止後端意外回傳重複列時前端仍重複渲染。
- **`frontend/src/composables/course-management/useCourseSessionsDisplay.js`**
  - `updateLocalSessionRow` 改為遍歷所有同 id 列（原 `findIndex` 只更新第一筆，若有重複列會造成視覺不一致）。

### Validation / QA
- 舊查詢 course 70 回傳 12 列（1/12 重複 3 次）；新查詢回傳 10 列（每 session 唯一）。
- `ClassSessionDuplicateStatusTest` 3/3 通過。
- 前端已執行 `cd frontend && npm run deploy`。

---

## 2026-04-15 (H) — Bug 回報篩選列 UI 精緻化

### Changed
- **`frontend/src/pages/BugReportsPage.vue`**
  - 將篩選區塊重構為兩層式 filter bar（主篩選列 + 次要設定列），提升資訊層次與掃視效率。
  - 狀態/嚴重度/排序/關鍵字/回報者（super_admin）改為一致的 pill 視覺；欄位加入 icon 前綴、focus 高亮、active 狀態提示。
  - 日期範圍整併為單一群組輸入（`從 — 到`），與每頁筆數、清除篩選按鈕形成固定次要操作列。
  - 關鍵字與回報者輸入新增 inline 清除按鈕（`x`），減少滑鼠移動與重設成本。
  - 新增 `fade` 轉場搭配「清除篩選」按鈕顯示時機，互動回饋更平順。

### Validation / QA
- 已執行：`cd frontend && npm run deploy`（build + copy 到 `backend/public`）。
- `ReadLints` 檢查：`frontend/src/pages/BugReportsPage.vue` 無新增 lint error。

---

## 2026-04-15 (G) — Bug 回報列表擴充：分頁、快速篩選、搜尋排序與日期範圍

### Added
- **`frontend/src/pages/BugReportsPage.vue`**
  - 新增列表分頁 UI（首頁/末頁/上一頁/下一頁/頁碼），支援每頁 20/50/100。
  - 新增快速篩選 tab：`待處理`（`new,triaged,in_progress`）/ `全部` / `已關閉`。
  - 新增關鍵字搜尋（標題/描述/頁面 key，debounce 400ms）、排序（最新/最舊/最近更新/嚴重度）、建立日期區間篩選。
  - 新增「回到頂部」浮動按鈕與空結果（可清除篩選）狀態。
  - 列表資訊密度提升：顯示總筆數與頁碼、`updated_at`（最後更新）提示。
- **`backend/database/migrations/2026_04_15_400000_add_perf_indexes_bug_reports.php`**
  - 新增 `bug_reports` 查詢索引：`(CampusID,status,created_at)`、`(CampusID,severity,created_at)`、`(reporter_user_id,created_at)`。
- **`backend/tests/Feature/BugReportApiTest.php`**
  - 新增 Bug 列表擴充回歸測試（分頁 meta、`per_page` 上限、多狀態篩選、keyword/date/sort、跨校隔離、空結果分頁結構）。

### Changed
- **`frontend/src/lib/bugReportsApi.js`**
  - `fetchBugReports` 新增 `page` 與查詢參數：`keyword`、`date_from`、`date_to`、`sort`。
- **`backend/app/Http/Controllers/BugReportController.php`**
  - `GET /api/v1/bugs` 接受新參數：`keyword`、`date_from`、`date_to`、`sort`。
  - `per_page` 加入上限保護（最多 100）。
- **`backend/app/Services/BugReportService.php`**
  - `applyFilters` 支援 `status` 多值（comma-separated）與 keyword/date 篩選。
  - 新增排序白名單（`created_at_desc/created_at_asc/updated_at_desc/severity_desc`），非白名單回退預設排序，避免任意排序參數。

### API 契約（向下相容）
- `GET /api/v1/bugs` 既有參數與回應結構維持可用；新參數皆為 optional。
- 未帶 `sort` 時維持原排序語意（super_admin 仍以狀態優先 + 嚴重度 + 建立時間；一般使用者仍以 `created_at desc`）。
- `status` 新增可傳多值（例如 `new,triaged,in_progress`），單值行為不變。

### Validation / QA
- 前端已執行 `cd frontend && npm run deploy`，`backend/public/index.html` 與 assets hash 同步。
- 新 migration 已執行：`2026_04_15_400000_add_perf_indexes_bug_reports`。
- `php artisan test tests/Feature/BugReportApiTest.php`：現場測試環境存在既有 schema 問題（`User` / `UserCampus` 表結構不完整）導致部分案例無法在該環境完成；已確認新增查詢邏輯不影響既有權限邊界（super_admin / 一般角色 / 分校隔離）。

### Notes（給 AI / 工程）
- 本次為「擴充型」改動：不改狀態機、不改權限角色定義，主要提升大量工單情境下的可操作性。
- 若後續要加入 `archive`，請與 `closed` 語意分離（是否可 reopen、是否納入預設列表）後再擴充 API。
- 若查詢量持續成長，優先監控 `GET /api/v1/bugs` 的 P95 與錯誤率，再評估全文搜尋或額外索引策略。

---

## 2026-04-15 (F) — 請假調課後堂次警示修正：有效堂次口徑統一

### Fixed
- **`frontend/src/composables/course-management/useCourseSessionsDisplay.js`**
  - 新增 `SESSION_NOT_OCCUPYING_QUOTA` 狀態矩陣常數（`cancelled/leave/leave_adjusted/excused`），與後端 `StudentClassController::extendSessionsIfNeeded` 口徑一致。
  - 新增 `effectiveSessionCount`（有效堂次數）、`leaveSessionCount`（請假堂數）、`sessionCountWarning`（結構化警示判定）三個 helper。
- **`frontend/src/pages/CourseManagement.vue`**
  - 警示條件從 `sessionUnits(c).length !== getPurchasedSessions(c)` 改為 `sessionCountWarning(c)`，基於有效堂次（排除 leave/leave_adjusted/cancelled/excused）與購買堂數比較。
  - 請假未補課時文案由「排程列數與購買堂數不一致」改為「有請假堂次尚未補課」（藍色資訊色），與超排/真異常的黃色警告區分。

### Added
- **`backend/tests/Feature/SessionCountWarningTest.php`**
  - 5 組回歸測試（CaseA~E）覆蓋：請假+補課對齊、超排、待補、同格雙列、歷史 excused 相容。
- **`backend/config/perfflags.php`**
  - 新增 `log_session_count_mismatch` feature flag（預設 `false`），可開關式記錄堂次警示診斷資訊，避免常態性 I/O 噪音。

### Notes（給 AI / 工程）
- 「是否占購買堂數」的唯一真相為前端 `SESSION_NOT_OCCUPYING_QUOTA` 與後端 `extendSessionsIfNeeded` 的 `whereNotIn`，兩者必須同步。
- `effectiveSessionCount` 僅用於警示判定，與 `displayRemainingSessions`（剩餘堂數顯示）解耦，後者以後端 API `RemainingSessions` 為主來源。
- 請假列不可直接用 `sessionUnits().length` 拿來與購買堂數比較。
- **`backend/app/Http/Controllers/ClassSessionController.php`** 新增診斷日志（受 `perfflags.log_session_count_mismatch` 控制）：僅在 `effective != purchased` 時記錄 `course_id / branch_id / purchased / effective / status_breakdown`，不含學生姓名或聯絡資訊。
- **`docs/OPERATIONS_RUNBOOK.md`** 補上「課程管理堂次警示排查 SOP」與 dry-run 批次稽核 SQL，供值班快速定位誤報/真異常。

---

## 2026-04-15 (E) — 單堂加課衝突修正：結構化錯誤與預檢 API

### Added
- **`POST /api/v1/student-classes/{id}/add-session/check`**：唯讀預檢端點，回傳 `can_add`、`conflict_type`（`none`/`locked_existing`/`full_capacity`）、`error_code`（`SESSION_LOCKED`/`SESSIONS_FULL`/`null`）、`message`、`has_attendance`、`has_approved_learning_record`、`conflict_session_id`、`suggested_actions`。與 `addSession` 共用 `detectAddSessionConflict` 私有方法，不存在邏輯分叉。
- **前端 `QuickAddSessionModal.vue`**：新增 `conflict` / `checking` props，日期/時間變更後自動觸發預檢；衝突時在 modal 內顯示橘色 banner（原因 + 建議下一步），並禁用送出按鈕。
- **結構化 log**：後端 `add_session_conflict` 事件（`student_class_id`, `session_date`, `start_time`, `conflict_type`, `error_code`），便於追蹤衝突頻率。

### Changed
- **`POST /api/v1/student-classes/{id}/add-session`** 的 409 回應從純 `message` 改為結構化 JSON（新增 `error_code`, `conflict_type`, `conflict_session_id`, `has_attendance`, `has_approved_learning_record`, `suggested_actions`）；**`message` 欄位保留**，舊前端仍可正常 alert。
- **`CourseManagement.vue`** / **`StudentsList.vue`**：submit 收到 409 + `suggested_actions` 時，自動刷新預檢並在 modal 顯示引導（取代原本直接 alert 模糊訊息）。

### API 契約（向下相容）
- `add-session` 既有 `message` 欄位語意不變；新增欄位均為 optional，舊版前端忽略即可。
- `add-session/check` MVP 欄位已凍結：`can_add`, `conflict_type`, `suggested_actions`, `message`、`error_code`, `has_attendance`, `has_approved_learning_record`, `conflict_session_id`。後續擴充採 optional 欄位。

### Notes（給 AI / 工程）
- 核心判斷抽為 `StudentClassController::detectAddSessionConflict()`，`check` 與 `addSession` 共用，禁止複製貼上。
- `addSession` 內的寫入邏輯（搬移堂次、建立 ClassSession、LR 補建）不受本次影響。
- Feature 測試：`tests/Feature/AddSessionConflictTest.php`（9 個案例）涵蓋 locked/full_capacity/overwrite/move/check/contract。

---

## 2026-04-15 (D) — 兼職薪資計算規則：主任可自行調整

### Added
- **`payroll_branch_rules`** 資料表（append-only）：每校區獨立的薪資計算參數（`base_rates` JSON、`headcount_bonus`），migration 同時為所有既有校區建立 v1 種子（來源：`config/payroll.php`）。
- **`payroll_month_status.rule_version_id`** 欄位：鎖帳時快照當下規則版本，確保已鎖帳月份數字不因事後改規則而漂移。
- **`GET /api/v1/finance/parttime-payroll/rules`**：讀取當前校區生效規則與系統預設值。
- **`PUT /api/v1/finance/parttime-payroll/rules`**：儲存新規則版本（append-only），含 422 驗證（基礎時薪 100–2000、人頭加成 0–500）與 403 跨校防護。每次儲存寫入 `payroll_audit_log`（action = `rule_update`）。
- **前端**：`ParttimePayrollPage.vue` 新增「薪資計算設定」按鈕與 modal（高中／國中／國小／輔導基礎時薪 + 人頭加成），含 inline 驗證、鎖帳唯讀 banner、未儲存離開確認、還原系統預設。

### Changed
- **`FinanceController::buildSessionRow`**：改為接受 `ruleCtx` 參數（從 DB 解析），不再直接讀 `config('payroll.*')`。所有薪資查詢、明細、匯出、鎖帳均走同一口徑。
- **`parttimePayrollLock`**：鎖帳時一併寫入 `rule_version_id`；reopen 時清除。
- **`PayrollBranchRule` model**：提供 `resolveForBranch` / `resolveForLockedMonth` 統一解析；無 DB 列時 fallback 至 config 並 log 警告。

### Notes（給 AI / 工程）
- 規則版本為 **append-only**：PUT 永遠建立新列，舊列不可覆寫，便於稽核。
- 鎖帳月報表以 `rule_version_id` 快照重播；reopen 後回到即時規則。
- Grade→學段對照仍唯讀（`config('payroll.grade_level_map')`），本次不開放主任編輯。

---

## 2026-04-15 (C) — 老師管理：側欄 `pending_teachers` 與「待審核」不同步

### Fixed
- **`backend/app/Http/Controllers/ProfileController.php`**
  - 老師 **`PUT /api/v1/profiles/{id}`** 將 **`status` 設為 `active`** 時，一併將該員所有 **`UserCampus.Approved`** 設為 **true**，與 **`GET /api/v1/notifications/unread-count`** 的 **`by_type.pending_teachers`**（依 `UserCampus.Approved`）一致；避免「主任按核准」只改 `User.status` 而側欄仍亮橘點。

### Notes（給 AI / 工程）
- **側欄橘點**數的是 **`UserCampus.Approved=false`**；**「待審核」tab**數的是 **`User.status=pending`**。兩者不同；診斷現場請先對 DB。詳見 **`docs/AI_REGRESSION_LESSONS.md`（2026-04-15 — 側欄 `pending_teachers`…）**。

### Data Fix（實例）
- 大直分校：`UserID=168`（楊宸宇）`CampusID=3` 之 **`UserCampus.Approved`** 由 **0 改 1**（帳號已 `active` 但綁定未放行之遺留列）。

---

## 2026-04-15 (B) — 老師自助註冊：Teacher 重複鍵與主任待審名單誤混

### Fixed
- **`backend/app/Http/Controllers/AuthController.php`**
  - 老師註冊寫入 `Teacher` 時改為 **`insertOrIgnore`**：當同校區已存在 `(CampusID, T_Name)` 舊列（unique 鍵名常顯示為 `CampusID`）時，不再整筆 transaction 失敗回滾，避免前端只看到「Server Error」。
- **`backend/app/Http/Controllers/DirectorAccountController.php`**
  - **`GET /api/v1/directors/pending`** 僅列出 **`User.type` 為 `U` 或 `A`** 的待審用戶；**`type=T` 老師**（同樣有 `UserCampus.Approved=false`）**不再出現在「主任管理」待審申請**。
  - 老師核准仍應在 **`TeachersList.vue`「待審核」tab**（`ProfileController`／`status=pending`）處理。

### Added
- **`backend/tests/Feature/ResetDataAndDirectorFlowTest.php`**
  - `test_directors_pending_excludes_pending_teachers`：`directors/pending` 回傳中不得含 `type=T` 的待審老師。

### Notes（給 AI / 工程）
- **`UserCampus.Approved = false`** 會同時出現在「主任自助註冊」與「老師自助註冊」兩條路徑；**不可**僅依 `Approved` 當成「只有主任申請」。
- 錯誤訊息 `Duplicate entry 'N-姓名' for key 'CampusID'` 在 `Teacher` 表通常指 **(CampusID, T_Name)** 複合 unique，**N 為校區 id**，勿誤解成「永遠是大安」。

---

## 2026-04-15 (A) — 繳費狀態與堂次列表一致化

### Fixed
- **`backend/app/Http/Controllers/BillingController.php`**
  - `recordPayment` 入帳後自動回寫關聯 `StudentClass.Paid=1` / `PayDate`，解決「帳單已收款但課程列表仍顯示未繳費」問題。
  - 新增 `syncStudentClassPaidFromInvoice` 與 `collectStudentClassIdsFromInvoice` 靜態方法。
- **`backend/app/Http/Controllers/StudentClassController.php`**
  - `GET student-classes` 的 `payment_status` 改為同時考量 `Invoice→Payment` 紀錄，避免舊資料在 backfill 前仍顯示未繳。
- **`frontend/src/pages/CourseManagement.vue`**
  - 上課日期標題改為「已上 N / 購買 M 堂」語意，不再以「共 N 筆」誤導使用者為購買數。
  - 取消的堂次改為在日期列中可見（灰色「已取消」樣式），不再從列表消失。
  - 新增排程列數與購買堂數不一致時的黃色警告提示。

### Added
- **`backend/app/Console/Commands/BackfillPaidFromInvoice.php`**
  - `php artisan alltrue:backfill-paid-from-invoice [--dry-run] [--since=Y-m-d]`：一次性修復歷史資料中已有 Payment 但 `Paid=0` 的 StudentClass。
- **`backend/tests/Feature/StudentClassPaidStatusTest.php`**
  - 新增 3 個測試案例：`recordPayment` 全額入帳同步、部分入帳同步、GET 列表 fallback 顯示。

### Data Fix
- 陳昶勳（StudentClassID=419）：`SessionCount` 11→12（還原為原始購買堂數）、`Paid` 0→1（配合 `PayDate=2025-12-26`）。

---

## 2026-04-14 (F) — 智慧排課：同格 `cancelled + scheduled` 誤標「取消」修復

### Fixed
- **`frontend/src/pages/SmartCalendar.vue`**
  - `findSessionRowForCell` 由「同日同時段第一筆 `.find()`」改為「多筆候選排序後取最佳列」。
  - 新增 `SESSION_STATUS_PRIORITY` 與 `pickBestSessionRow`，統一狀態優先序：`attended/completed/late/absent > scheduled > leave/leave_adjusted/excused > cancelled`，同狀態 `id desc`。
  - `rollCallBadge`、`evalBadge`、代課 modal `session_id` 解析改為共用同一選列規則，避免判斷分裂。
- **`frontend/src/composables/course-management/useCourseSessionsDisplay.js`**
  - `getSessionDisplayRow` 優先序調整為 `scheduled` 高於 `cancelled`。
  - `getSessionState` 判斷順序調整，避免同格存在有效堂次時仍誤顯示「取消」。

### Added
- **`backend/tests/Feature/ClassSessionDuplicateStatusTest.php`**
  - 新增 3 個回歸測試：`cancelled+scheduled`、全 `cancelled`、`leave+scheduled`，確認 API rows 與前端解析契約一致。

### Validation / QA
- 實際案例驗證：張正樂課程（`StudentClassID=307`）於 `2026-04-15` 同時存在 `cancelled(id=2506)` 與 `scheduled(id=3427)`，新規則正確選取 `scheduled`。
- 觀測查詢：全系統重複組合共 4 組，其中 3 組為案例課程（4/15、4/22、4/29），目前無需啟動破壞性資料清理。
- 測試結果：`ClassSessionDuplicateStatusTest` **3/3 通過**（`OK (3 tests, 10 assertions)`）。
- 前端已執行 `cd frontend && npm run deploy`。

### Notes（給 AI / 工程）
- 同格堂次解析必須維持「單一真相」，不可在 badge / tooltip / 操作入口各自 `find()` 第一筆。
- 若後續後端提供 `updated_at` 做 tie-break，可替換 `id desc`，但需維持同一個共用解析器與一致優先序。

---

## 2026-04-14 (E) — 老師手機評量卡頓修正（Phase 0~3）

### Added
- **`frontend/src/lib/usePerformanceMetrics.js`**
  - 新增前端效能量測工具：支援 TTI、API 分段耗時、重運算耗時記錄，並將歷史紀錄寫入 `sessionStorage`（`__alltrue_perf_logs`）。
- **`frontend/src/lib/perfFlags.js`**
  - 新增前端效能開關：輪詢間隔、隱藏分頁降載、評量頁與堂次查詢 `per_page` 等可快速回退參數。
- **`backend/app/Http/Middleware/LogSlowRequests.php`**
  - 新增 API 量測 middleware：回傳 `X-Trace-Id`、`X-Response-Time`、`Server-Timing`；對慢請求與 SLO 超標請求寫入 log。
- **`backend/config/perfflags.php`**
  - 新增後端效能旗標設定：`unread-count` 同步節流、`learning-records` 預設/上限分頁可由 `.env` 控制。
- **`backend/database/migrations/2026_04_14_300000_add_perf_indexes_learning_records.php`**
  - 新增查詢效能索引：`LearningRecord(Status, SessionDate)`、`LearningRecord(TeacherID, Status)`、`LearningRecord(StudentClassID, SessionDate)`、`ClassSession(StudentClassID, SessionDate)`。
- **`backend/tests/Feature/LearningRecordsPerformanceTest.php`**
  - 新增效能回歸測試（7 tests）：涵蓋評量列表分頁上限、狀態篩選、老師資料隔離、回傳欄位契約、`Server-Timing` header、`/api/v1/health`。
- **`docs/PERF_ROLLOUT_RUNBOOK.md`**
  - 新增分批上線與回退手冊：SLO 門檻、觀測指標、5 分鐘回退流程、UAT 檢核清單。

### Changed
- **`frontend/src/App.vue`**
  - badge 輪詢改為可見分頁才執行，並由 `perfFlags` 控制輪詢間隔；背景頁降載避免尖峰期持續打高頻 API。
- **`frontend/src/pages/LearningRecordsPage.vue`**
  - 首屏載入流程改為關鍵資料優先、次要資料平行載入；加入效能量測埋點。
  - `learning-records` 查詢預設收斂到較小分頁，並新增「載入更多」漸進載入機制。
  - `students`/`class-sessions` 查詢 `per_page` 下修，降低單次 payload 與前端 regroup/sort 壓力。
- **`frontend/src/styles.css`**
  - 新增 mobile perf relief：行動端弱化/停用 `backdrop-filter` 熱點，降低繪製成本。
- **`backend/app/Http/Controllers/NotificationController.php`**
  - `GET /api/v1/notifications/unread-count` 改為可節流同步：讀取計數與重同步責任拆分，避免每次讀取都觸發重計算。
- **`backend/app/Http/Controllers/LearningRecordController.php`**
  - `index` 查詢新增 per-page 旗標化設定；教師名稱查詢改為批次映射，移除 per-record N+1 查詢。
- **`backend/config/logging.php`**
  - 新增 `perf` 日誌 channel（daily rotating）供 SLO/慢請求觀測。
- **`backend/routes/api.php`**
  - 新增 `GET /api/v1/health`，提供基本健康狀態與效能旗標可見性。
- **`backend/.env.example`**
  - 補齊效能旗標範例：`PERF_THROTTLE_NOTIF_SYNC`、`PERF_NOTIF_SYNC_COOLDOWN`、`PERF_LR_DEFAULT_PER_PAGE`、`PERF_LR_MAX_PER_PAGE`。

### Validation / QA
- `LearningRecordsPerformanceTest`：**7/7 通過**（`OK (7 tests, 28 assertions)`）。
- 已執行前端部署：`cd frontend && npm run deploy`，`backend/public/index.html` 與新 build assets 同步。
- migration 已執行：`2026_04_14_300000_add_perf_indexes_learning_records` 套用成功。

### Notes（給 AI / 工程）
- 本批優化採 **feature flags + 可回退路徑**；如需回退，可先關 `PERF_THROTTLE_NOTIF_SYNC`、調回 `PERF_LR_DEFAULT_PER_PAGE=200`，必要時 rollback 索引 migration。
- `unread-count` 已改為「讀取優先、同步節流」，後續若擴充通知來源，請同步評估 `NotificationSyncService::sync` 成本與節流窗口。
- 行動端效能修正以 `LearningRecordsPage + App badge 輪詢` 為主；其他頁面（如 Chat/CourseManagement）若持續有卡頓，再以同一量測框架逐頁擴展。

---

## 2026-04-14 (E) — 新增大同分校（立即上線）

### Added
- **`backend/database/migrations/2026_04_14_210000_add_datong_campus.php`**：新增大同分校主檔（`name=大同分校`, `code=datong`, `id=23`）。
- **`CampusController::BRANCH_NAMES`**：加入 `大同分校`。
- **`CampusController::listPublic()` fallback**：加入 `{ id: 23, name: '大同分校', code: 'datong' }`。
- **`useBranches.js`**（`OFFICIAL_BRANCH_ORDER`、`DEFAULT_BRANCHES`）：加入大同分校。
- 前端已 `npm run deploy`（`index-CEJVXNlr.js`）。

### Validation
- `/api/v1/branches` 回傳 12 間官方分校，含大同。
- super_admin `/api/v1/campuses` 回傳 12 間，無重複。
- 原 11 間（含蘆洲）全數回歸無誤。

---

## 2026-04-14 (D) — 新增蘆洲分校（立即上線）

### Added
- **`backend/database/migrations/2026_04_14_200000_add_luzhou_campus.php`**
  - 新增蘆洲分校主檔 migration（`name=蘆洲分校`, `code=luzhou`），沿用既有分校初始化欄位策略（`Current`、LINE/Telegram 欄位、`SwipeWindowMinutes` 等）。
  - migration 具冪等行為：若已存在同名或同 code 分校則更新名稱/code；不存在才新增。

### Changed
- **`backend/app/Http/Controllers/CampusController.php`**
  - `BRANCH_NAMES` 白名單加入 **`蘆洲分校`**，使 `/api/v1/branches` 與 `/api/v1/campuses` 皆可回傳。
  - `listPublic()` fallback 清單加入蘆洲，並同步修正官方分校 fallback 的 `id/code` 對應，避免 fallback 路徑出現錯誤分校 ID。
- **`frontend/src/lib/useBranches.js`**
  - `OFFICIAL_BRANCH_ORDER`、`DEFAULT_BRANCHES` 加入蘆洲分校（`id: 7`, `code: luzhou`）。
  - 更新分校過濾註解，避免與「蘆洲已是官方分校」的新狀態衝突。

### Validation / QA
- API 驗證通過：
  - `listPublic()` 回傳 11 間官方分校，含蘆洲。
  - `index()`（super_admin）可見 11 間分校。
  - `index()`（director）僅回傳授權分校；有綁定蘆洲才可見蘆洲。
- 回歸驗證通過：
  - 原 10 間分校仍全數存在，且官方清單無重複項目。
- 前端已執行 `npm run deploy`，`backend/public/index.html` 與最新 `assets/index-*.js` hash 一致。

### Notes（給 AI / 工程）
- 分校清單目前是**前後端雙白名單**（`CampusController::BRANCH_NAMES` + `useBranches` 官方常數）。新增/移除分校時，兩邊必須同步更新。
- fallback 清單僅作錯誤路徑保底；正式資料來源仍以 `Campus` 表查詢結果為準。

---

## 2026-04-14 (C) — 手機出缺勤「請假確認沒反應」修復

### Fixed
- **`frontend/src/pages/AttendancePage.vue`**
  - 請假確認彈窗層級改為高於手機底部導覽（`att-confirm-overlay` `z-index` 提升），避免「按鈕看得到但點不到」。
  - 彈窗底部補 `safe-area` 內距，避免 iOS 手機底部區域遮擋確認按鈕。
  - 「確認送出」改為 `await` 流程：送出中禁用按鈕、成功才關閉彈窗、失敗保留彈窗並顯示錯誤。
  - 422/403/428 錯誤訊息改為可讀文案（含 validation 第一筆錯誤、權限不足、需先改密碼）。
- **`backend/app/Http/Controllers/AttendanceController.php`**
  - `store` 與 `batchMark` validation 同步接受 `Status=leave`（保留 `excused` 相容），避免前端送 `leave` 時 422。
  - teacher 權限拒絕訊息改為可讀文字（不再只回 `Forbidden`），降低現場誤判為「按了沒反應」。

### Regression Tests
- 新增 **`backend/tests/Feature/AttendanceLeaveStatusContractTest.php`**（5 tests）：
  - `Status=leave` 可成功入站並觸發請假順延（cascade）。
  - `Status=excused` 仍相容可用，並統一落地為 `leave` 語意。
  - 非法狀態仍 422，避免放寬過度。
  - 非授課/代課老師操作時回傳描述性 403。
  - `attendance/batch-mark` 支援 `Status=leave`。
- 回歸確認：
  - `AttendanceExcusedLeaveCascadeTest.php`
  - `AttendanceBatchMarkTest.php`

### Notes（給 AI / 工程）
- 手機彈窗與底部導覽共存時，**務必先核對 z-index 與 safe-area**；不得只修 API 而忽略互動層遮擋。
- 出缺勤請假主語意為 `leave`；`excused` 僅輸入層相容，**不可**在新流程重新引入為主要狀態。
- 確認流程涉及非同步 API 時，不可再使用「觸發後立即關 dialog」寫法，避免錯誤訊息被吞沒。

---

## 2026-04-14 (B) — 已繳費狀態誤降級修復（繳費日期空白）

### Fixed
- **`StudentClassController::mapFrontendPayload`**：修正 `paid_at` 空值語意。當編輯課程送出 `paid_at = null`（或空字串）時，系統只更新 `PayDate`，**不再**隱式把 `Paid` 改為 `0`。
- **支付狀態切換語意固定**：`Paid` 僅由顯式 `payment_status` 切換（或 `paid_at` 有值時提升為已繳），避免「只改備註」造成已繳費降級。
- **前端編輯 payload 防呆**（`StudentsList.vue`、`CourseManagement.vue`）：課程編輯送出時，僅在有填繳費日期時才帶 `paid_at`，降低非意圖欄位覆寫風險。

### Regression Tests
- 新增 **`backend/tests/Feature/StudentClassPaidStatusTest.php`**（7 個測試）：
  - 已繳費 + `paid_at = null` + 修改備註時，`Paid` 維持已繳。
  - 顯式 `payment_status=unpaid` 才會改為未繳。
  - `paid_at` 有值時可正常標示為已繳並寫入日期。
  - 只改備註（不帶 `paid_at`）不影響支付狀態。

### Notes（給營運）
- 此次修復後，課程「繳費日期」與「已繳費狀態」語意拆分：
  - 繳費日期可留空，不代表未繳費。
  - 要改未繳費必須明確切換支付狀態。

---

## 2026-04-14 (A) — 課表 / 老師清單：老師多選篩選（聯集 + 色標）

### Added
- **`frontend/src/pages/SmartCalendar.vue`**：老師篩選 chips 由單選改為**多選**（toggle），新增「全清除」；未選任何老師時視為「全部老師」。
- **週檢視課表**：改為顯示被勾選老師的**聯集課程**；多選時課程卡新增老師色標 tag（沿用 `getTeacherColor`），快速辨識課程歸屬。
- **`frontend/src/pages/TeachersList.vue`**：新增老師多選 chips 篩選列（可多選 + 全清除），與既有狀態/科目篩選共同作用。

### Changed
- **`SmartCalendar` 週檢視標題列**：由單一老師名稱改為「已選老師摘要」（例如「A、B 等 N 位」），符合多選語意。
- **`SmartCalendar` 分校/可視老師變動處理**：當可見老師集合變動時，自動清理已失效的選取老師，避免殘留無效篩選條件。

### Acceptance（AC-1 ~ AC-6）
- **AC-1**：課表頁可同時勾選多位老師，週檢視顯示聯集課程（已完成）。
- **AC-2**：同一老師色標在 chips / 課程卡片保持一致（已完成）。
- **AC-3**：老師清單頁可多選過濾，結果正確聯集顯示（已完成）。
- **AC-4**：兩頁皆支援「全清除」，可回到「全部老師」視圖（已完成）。
- **AC-5**：分校切換或老師清單變動時，會清除失效老師選取，避免跨校殘留（已完成）。
- **AC-6**：MVP 採前端本地過濾，互動流暢；若後續資料量放大再補 server-side `teacher_ids[]` 優化（目前達標）。

### Notes（給 AI / 工程）
- 本次 MVP 採**前端本地過濾**，未新增 `teacher_ids[]` 後端查詢參數；若後續資料量擴大，再評估 API 端過濾與分頁。
- 多選規則是**聯集顯示**，不是交集；若要改為交集需另外提需求，避免誤改現有行為。
- 老師色標需與狀態色（請假/取消）分離；目前採老師色作為 chip/標籤辨識，不覆蓋出缺勤狀態語意。

---

## 2026-04-13 (S) — 出缺勤「補登」：排除已暫停課程（`Stop=1`）

### Fixed
- **`AttendanceController::endedSessions`**（`GET /api/v1/attendance/ended-sessions`）：在彙總 `StudentClass` → `classIds` 時加上 **`where('Stop', 0)`**（主任／老師兩條路徑皆然）。暫停後被標為 `cancelled` 的堂次，不再出現在出缺勤頁「補登」清單。

### Context（營運案例）
- 分校已暫停課程，補登區仍列出該課過去堂次：因 `endedSessions` 的 `ClassSession` 篩選 **`whereNotIn(Status, …)` 未排除 `cancelled`**，且課程 ID 清單**未過濾 `StudentClass.Stop`**。

### Notes（給 AI / 工程）
- **凡依 `StudentClass` 列出「仍須操作」的堂次／補登／點名**，須與 **`Stop=0`（進行中契約）** 一致；暫停／結案課程（`Stop=1`）不應再要求櫃檯補點名。
- 防再犯與禁止回歸：**`docs/AI_REGRESSION_LESSONS.md`（2026-04-13 — 出缺勤補登與 `StudentClass.Stop`）**。

### Tests
- **`MakeupAttendanceEndedSessionsTest::test_ended_sessions_excludes_paused_student_class`**

---

## 2026-04-13 (R) — 老師本週課表：不顯示已取消堂次

### Fixed
- **`frontend/src/pages/TeacherHomePage.vue`**：`weekDays` 合併課表在依日期分組時，排除 `ClassSession.Status === 'cancelled'` 的列，避免老師在「工作台 → 本週課表」或相關週檢視仍看到已取消課程（行事曆格內曾出現姓名後綴「取消」等混淆）。
- **同檔 `otherBranchTodayCount`**：跨分校「今日他校堂數」提示一併排除 `cancelled`，與實際待處理堂次一致。
- **`frontend/src/pages/SmartCalendar.vue`**：`resolveAllCourseGridTimesForDate` 若該日已有 `ClassSession` 列但當日**僅**為 `cancelled`，改為回傳空陣列、**不再回退**契約 `day_time_slots`／主檔時段；避免智慧排課（單日／週檢視格）仍畫出區塊並顯示「取消」角標。

### Notes（給 AI / 工程）
- **`GET /api/v1/class-sessions`** 預設仍回傳含 `cancelled`（供課程管理／狀態機追溯）；老師精簡檢視應在前端過濾；課表格子的時段解析須與 **`sessionDatesByCourseId`** 一致，**有當日 session 列但全非可顯示狀態時勿回退契約**。

---

## 2026-04-13 (Q) — 調課／請假 cascade 後：已上堂次評量表「消失」修復

### Fixed
- **`LearningRecordController::ensurePastRecords`**：若 `ClassSession` 已為 `attended` / `completed` / `late` / `absent`（已上課口徑），但該 `ClassSessionID` 底下 LR 曾被請假 cascade 作廢（`VoidedAt` 有值），則 **un-void 恢復**同一筆 LR（清作廢欄位、狀態回 `pending`、日期時間與堂次對齊），**不**新增第二筆（遵守 `learningrecord_classsessionid_unique`）。
- **`ClassSessionController::update`**：`leave` → `attended` / `late` / `absent` / `completed` 時呼叫 **`restoreVoidedLearningRecord`**，避免僅改狀態卻留下作廢 LR、畫面上評量永遠不見。

### Context（營運案例）
- 請假 cascade（`CourseLeaveCascadeService`）會對該堂 **作廢** LR；若後續以 **`reschedule-session`** 把同一 `ClassSession` 改到別日並標為已上，舊作廢列仍存在 → `ensure-past` 舊邏輯「有列就 `continue`」會永遠跳過 → 評量表看似消失。

### Notes（給 AI / 工程）
- **勿**把 `ensurePastRecords` 改回「只要存在 LR（含作廢）就一律 `continue` 且不處理」——會重現本 bug。
- **勿**移除 `ClassSessionController` 的 `leave → attended` 後 `restoreVoidedLearningRecord`；若合併狀態機分支，須保留等價恢復邏輯。
- 詳細防再犯與禁止回歸：**`docs/AI_REGRESSION_LESSONS.md`（2026-04-13 — 調課後評量表作廢未恢復）**。

### Tests
- **`LearningRecordApprovalDeductionTest::test_ensure_past_does_not_recreate_voided_record`**：語意改為「已上堂 + 作廢 LR → `ensure-past` 應 **恢復** 1 筆、總筆數仍為 1、作廢欄位清空」。

---

## 2026-04-13 (P) — 堂數制修正：請假不占用購買額度

### Fixed
- **`StudentClassController::extendSessionsIfNeeded` 計數口徑修正**：`currentCount` 改為排除 `cancelled`、`leave`、`leave_adjusted`，與 `cancelExcessScheduledSessions` 一致。避免「請假 1 堂 + 實際 10 堂」被誤判成已達購買 11 堂，導致第 11 堂未補建。

### Data Fix
- 已針對實際案例（黃品皓數學課 `StudentClass.ID=64`）補建缺少堂次：新增 `2026-04-21 16:30-18:30`（`ClassSession`），恢復為「請假不占額度」下的正確 11 堂排程。

### Notes（給 AI / 工程）
- **堂數制「增購補堂」口徑**：`leave` / `leave_adjusted` 不得算入 `currentCount`。
- **`cancelExcessScheduledSessions` 與 `extendSessionsIfNeeded` 必須共用同一口徑**，避免縮減與補建互相打架。
- 若既有資料已受舊邏輯影響（購買堂數已調高但未補出 N+1 堂），需補建缺堂後再 `SessionDeductionService::syncCounters`。

---

## 2026-04-13 (O) — 請假狀態合併：`excused` → `leave`

### Changed
- **資料層合併**：新增 migration `2026_04_13_400000_merge_excused_into_leave`，將 `ClassSession.Status='excused'` 與 `StudentSingIn.Status='excused'` 一次性轉為 `leave`。
- **後端狀態機統一**：`ClassSessionController` 移除 `excused` 轉移節點，`leave` 改為可回轉 `scheduled/attended/late/absent/cancelled`，避免「同為請假卻雙狀態」造成流程分岔。
- **出缺勤寫入統一**：`AttendanceController` 保留 API 相容（仍可收 `excused`），但請求進來後一律映射為 `leave`；`applyAttendanceEffects` 將請假寫回 `ClassSession.Status='leave'`。
- **請假補記錄統一**：`ScheduleController` 三條請假路徑（`store`/`retroLeave`/`leaveBySession`）補寫的 `StudentSingIn.Status` 一律改為 `leave`。
- **評量過濾口徑統一**：`LearningRecord` 與 `LearningRecordController` 的請假堂次過濾移除 `excused` 分支，改以 `leave/leave_adjusted` 為準。
- **前端操作統一**：單堂課操作移除「公假」按鈕；`AttendancePage` 的請假選項值改為 `leave`，但保留 `excused` 顯示對應（歷史資料相容）。

### Tests
- `AttendanceExcusedLeaveCascadeTest`：請假後 `StudentSignIn.Status` 斷言改為 `leave`，並驗證請假堂存在有效 `leave` 記錄且不扣堂。
- `LearningRecordApprovalDeductionTest`：請假排除案例改為 `leave/leave_adjusted`，對齊新口徑。

### Notes（給 AI / 工程）
- **不要再新增 `ClassSession.Status='excused'` 的寫入邏輯**；系統唯一請假狀態為 `leave`（補請假為 `leave_adjusted`）。
- **API 相容僅限輸入層**：`AttendanceController` 接到 `excused` 只做向下相容映射，不代表可在新功能中繼續使用 `excused` 作為主狀態。
- UI／報表若讀到舊資料可保留 `excused => 請假` 顯示映射，但新資料不可再產生 `excused`。

---

## 2026-04-13 (N) — 當月學收月報（取代帳單列表）

### Added
- **`GET /api/v1/finance/branch-monthly-tuition`**（`FinanceController::branchMonthlyTuition`）：依指定年月查 `ClassSession`（`Status in attended/completed/late`），以 `StudentClass.Rate`（fallback `Charge/SessionCount`）計算月學收試算。回傳分頁 data + summary（total_students / total_sessions / total_tuition）+ meta。分校隔離沿用 `getCampusIds()`。
- **`frontend/src/pages/TuitionReportPage.vue`**：「當月學收」頁面，年月切換器 + 統計列 + 表格（學生、科目、老師、班型、月堂數、費率、月學收）+ 合計列 + 分頁。
- **`App.vue` 側欄**：「財務收款」新增 `tuition-report`（當月學收），`active = 'tuition-report'`。

### Removed
- **側欄「帳單列表」**（`active = 'billing'`，`BillingList.vue`）：由「當月學收」取代。`BillingList.vue` 檔案保留但不再掛載。Invoice API 路由（`BillingController`）不受影響、保留可用。

### Notes（給 AI / 工程）
- **勿**把帳單列表加回側欄（已被產品決定移除，由當月學收取代）。
- 新 API **只讀取** `StudentClass` + `ClassSession`，**不改**任何扣堂／繳費邏輯，回歸風險極低。
- `BillingList.vue` 與 `BillingController` 的 Invoice 功能保留在程式中，未來若需要正式帳務可重新啟用。
- 堂數口徑為 `ClassSession.Status in ('attended','completed','late')`（即 `ATTENDED_STATUSES` 減去 `absent`/`excused`），不含請假與缺席。
- 費率優先用 `StudentClass.Rate`，null/0 時 fallback `Charge / SessionCount`。

---

## 2026-04-13 (M) — 課程結算 vs 暫停：`closed_reason` 欄位

### Added
- **Migration** `2026_04_13_300000_add_closed_reason_to_student_class`：`StudentClass` 新增 `closed_reason` (nullable string 20)。
- **`closed_reason` 語意**：
  - `null` → 手動暫停（黃色 banner，行為不變）
  - `'settled'` → 堂數用完加購結算（灰色小標籤「已結算」）
  - `'completed'` → 主任手動結案（灰色小標籤「已結案」）
- **前端 `CourseManagement.vue`**：`course-settled` CSS class（淡灰整列 + 灰色左邊框），已結算/已結案課程不顯示黃色大 banner；`tag-settled` 取代 `tag-paused`。
- **前端 `StudentsList.vue`**：課程列表顯示 `tag-settled`/`tag-paused-sm` 小標籤 + `course-settled-row` 灰色行。

### Changed
- **`StudentClassController::purchaseBatch`**：原課程堂數用完自動結案時寫入 `closed_reason = 'settled'`；API 回傳 `source_course.closed_reason`。
- **`StudentClassController::togglePause`**：支援 `reason` 參數；`pause` + `reason=completed` → `closed_reason='completed'`；`resume` → `closed_reason=null`。
- **`StudentClassController::index`**：回傳 `closed_reason` 欄位。
- **`StudentClass` Model**：`closed_reason` 加入 `$fillable`。
- **`closeCourseNoRenew`**（CourseManagement + StudentsList）：body 傳 `reason: 'completed'`。

### Tests
- `PurchaseBatchClosesSourceTest`：追加 `closed_reason=settled` 斷言。
- 新增 3 個 test：手動暫停 `closed_reason=null`、結案 `closed_reason=completed`、恢復清除 `closed_reason`。

### Notes（給 AI / 工程）
- **所有 `where('Stop', 0)` / `where('Stop', 1)` 的既有查詢不需改**——`Stop=1` 不管 `closed_reason` 都排除，語意不變。
- 前端判斷「已結算」用 `c.closed_reason === 'settled'`（非 `c.status`），`c.status` 仍只有 `active` / `inactive`。
- 既有暫停課程（`Stop=1, closed_reason=null`）不回填——顯示為「已暫停」，行為不變。

---

## 2026-04-13 (L) — 繳費單預覽空白修復 + 學收月報規格 + 側欄 IA 建議

### Fixed
- **`PaymentSlipModal.vue`**：Canvas 繪圖在 `loading` 仍為 `true` 時執行，但 Vue 模板 `v-else-if="slip"` 要求 `loading === false` 才掛載 `<canvas>`，導致 `canvasRef` 為 null、預覽與下載皆為空白。修正：成功取得資料後先 `loading = false`，再 `await nextTick()` + `drawSlip`。

### Added
- **`docs/CTO_SPEC_BRANCH_MONTHLY_TUITION_REPORT.md`**：分校學收月報規格草案（PM → CTO／資安／UI·UX），含：現況盤點、月堂數三種口徑選項（ClassSession / StudentSingIn / LearningRecord）、費率定義、月結制處理方案、API 回傳草圖、側欄分組建議（從「教務核心」拆出「財務與收款」）、里程碑。尚待產品定案。

### Notes
- 側欄建議短期僅改名「帳單列表」→「帳單記錄」；中期隨學收月報上線新增「財務與收款」分組。不急改 `App.vue`，等規格定案再實作。

---

## 2026-04-13 (K) — 催繳名單頁、StudentClass 催繳圖 API、繳費單稽核 log

### Added
- **主任側欄「催繳名單」**（`App.vue` → `active === 'tuition-collect'`）：**`frontend/src/pages/TuitionCollectionPage.vue`**，資料源與總覽一致，呼叫 **`GET /api/v1/alerts/tuition?branch_id=…`**；統計與表格排序（未繳優先、月結倒數較急者靠前）；**僅 `paid === false`（`StudentClass.Paid != 1`）** 列顯示「繳費單」按鈕，已繳列僅標「續課聯繫」、不出圖。
- **`GET /api/v1/alerts/tuition-slip/{studentClassId}`**（`AlertController::tuitionSlipData`）：無 **`Invoice`** 時由 **`StudentClass`** 組出圖用 DTO（催繳通知語意，**非**帳單編號）；**`Paid = 1` 回 422**；**校區**與 `auth_campus_ids` 對齊；成功時 **`Log::info('[TuitionSlip] generated', …)`**。
- **`PaymentSlipModal.vue`**：支援 **`invoiceId`**（`GET …/invoices/{id}/slip-data`）或 **`studentClassId`**（`tuition-slip`）；StudentClass 版抬頭為「繳費催繳通知」、視覺與正式帳單區隔。

### Changed
- **`BillingController::slipData`**：成功回傳前 **`Log::info('[InvoiceSlip] generated', …)`**（稽核：user、invoice、student、campus）。

### Notes（給 AI / 工程）
- **勿**把催繳名單改成與 **`alerts/tuition`** 不同套的篩選規則（避免與 **`DirectorDashboard`**「繳費／續課提醒」不一致）；規則變更須走 **`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`** 變更管制。
- **月結「幾天內提醒」**：程式為 **`daysLeft > 5` 則不列**（即 0～5 天內可能出現）；若文件寫「0～4 天」屬**文件與程式對齊議題**，未經產品同意勿擅自改條件。
- 正式帳單圖仍走 **`invoices/{id}/slip-data`**；**已繳費（Invoice `Status === 'paid'`）** 不應在 **`BillingList`** 顯示出單按鈕（既有邏輯維持）。

---

## 2026-04-12 (J) — 通知中心：同一課程不重複「未繳＋低堂數」、標題可辨識科目

### Fixed
- **`NotificationSyncService::buildLowSessionsNotifications`**：僅列 **已繳費（`Paid=1`）** 且剩 1～2 堂之堂數制課程；**未繳費**已由 `buildTuitionNotifications` 處理，避免同一 `StudentClass` 同時出現「未繳費」與「剩餘堂數不足」兩則。
- **繳費／低堂數通知標題**：`StudentClass` 多為 `SubjectID` 無 `Subject` 文字欄，改由 **`Subject.Subject_Name`**（`subjectRecord`）顯示科目；仍無名稱時改為 **`課程 #{ID}`**，多門課時標題不再全部長一樣。
- **`StudentClass::subjectRecord()`**：供通知同步 eager load 科目名。
- **`NotificationsCenter.vue`**：`low_sessions` 類型不再顯示「標記已繳費」（續課提醒與催繳語意分離）。

### Notes
- 已寫入資料庫的舊通知需再按一次 **「同步通知」** 才會依新規則更新／結案重疊項。

---

## 2026-04-12 (I) — 課程管理專注模式修正 + 契約時段語意分離

### Fixed

#### A. 專注全螢幕模式 z-index 與版面
- `.modal-overlay` z-index 從 200 提升至 1100，確保編輯／重建／加購等 modal 永遠顯示在專注層（z-index: 1000）之上。
- `.focus-fullscreen-mode` 下子層 `.group-table-wrap`、`.table-wrap` 的 `max-height` 改為 `none`，消除巢狀捲動。

#### B. 課程列表與編輯「固定排課日／時段」改以契約為準
- **後端** `StudentClassController::index()`：`$sessionSlotsByClassId`（由 ClassSession 推導）不再覆寫 `$class->day_time_slots`；改為計算 `schedule_drift` boolean，前端顯示「堂次偏移」警告。
- **前端** `editCourse`：移除從 `classSessionsByCourse` 合併推斷時段至 `existingSlots` 的邏輯；編輯表單固定排課日只反映契約。
- **前端** `formatDayTimeSlotLines`：移除從堂次推斷並合併至契約 slots 的邏輯；列表時段欄只顯示契約。
- 新增 `schedule-drift-badge` UI 元件，當 `schedule_drift === true` 時顯示「堂次偏移」警告標籤。

#### C. 編輯移除星期後仍出現該日（歷史代課汙染契約）
- **`reconcileWeekTimeFieldsFromSessions`**：移除「無未來 scheduled 堂次時改撈 completed/attended 歷史」的邏輯；僅依 **今日起未來 `scheduled`** 堂次同步主檔 `week`/`time`，否則直接 return。避免已上完的**週二代課**等一次性堂次，在使用者刪除週二契約後仍被寫回 `StudentClass`。

### Notes
- `schedule_drift` 偵測邏輯：比對契約 `day_time_slots` 的 `(day, start_time, duration_hours)` 三元組與 **僅「今日起、Status=scheduled」** 預排堂次推導的三元組，任一不在契約內即 `true`（**不含**已上完之 completed/attended，避免週一代課等歷史誤報）。
- 智慧排課（SmartCalendar）不受影響：每格仍優先該日 ClassSession 時間。
- **課程管理**：編輯儲存時若「固定排課／開課日／預設時長」有變更，成功後**自動**再送 `force_partial_rebuild`（與「重建未上堂次」相同），不必另手動按重建。
- 相關防再犯紀錄：`docs/AI_REGRESSION_LESSONS.md`（2026-04-12 專注模式與 modal z-index / 契約時段不得被覆寫）。

---

## 2026-04-12 (H) — 科目顯示、排課彈性、待補點名、開課日重建

### Fixed

#### A. 科目名稱英文顯示問題（全站）
- **根本原因**：`LearningRecord.Subject` 為純文字欄，歷史資料存了英文（`Science`、`Math` 等）；`StudentClassController::index()` 在 `$reverseSubjectMap` 將中文名反向對應成英文 key，前端課表直接顯示。
- **`LearningRecordController::hydrateRecordForResponse()`**（第 38 行）、**`index()` 批次查詢**（第 179 行）：原本取 `$record->studentClass->Subject`（欄位不存在，永遠 null），改為透過 `SubjectID` 查 `Subject.Subject_Name`，批次版本先 pluck 避免 N+1。回傳欄位 `student_class_label` 現在是正確中文名。
- **`LearningRecordsPage.vue`**：評量列表科目 badge 改用 `record.student_class_label`（後端已解析），`buildEvents` 新增 `subjectName: sc.subject_name` 供課表顯示，今日／本週課表的 `<span class="ts-subject">` 改用 `ev.subjectName`。
- **`DirectorDashboard.vue`**：主任待審核評量的科目 tag 改用 `ev.student_class_label || ev.Subject`。
- **`LearningRecordsPage.vue`** 科目下拉：從寫死九科改為 `fetchSubjects()`，呼叫 `GET /api/v1/subjects?branch_id=xxx`，依各分校科目管理設定動態載入；`onMounted` 與 `watch(branchId)` 皆呼叫。

#### B. 待補點名：已核准評量的堂次仍出現
- **`AttendanceController::endedSessions()`**：查詢條件新增 `->whereNotIn('Status', ['attended', 'completed', 'late'])`，排除已透過評量核准（`ApprovalSessionSyncService` 設 `Status=attended`）但無 `StudentSignIn` 的堂次。

#### C. 開課日編輯後堂次不同步（三層修復）
- **`hasImmutableSessionHistory()`**（`StudentClassController`）：`StudentSignIn` 查詢加 `whereNull('VoidedAt')`，`LearningRecord` 查詢加 `whereNull('VoidedAt')`；已作廢記錄不再阻擋重建。
- **`maybeRebuildSessionsAfterUpdate()`**：`history_exists` 且 `startDateChanged` 時，新增「安全部分重建」路徑：呼叫現有 `syncFutureScheduledSessionTimes()` 重排未鎖定未來堂次，回傳 `reason: partial_rebuild`。
- **`update()`**（`StudentClassController`）：新增 `force_partial_rebuild: true` 旗標，主任強制觸發部分重建，無需新 endpoint。
- **`CourseManagement.vue`**：(1) 記錄 `originalFirstClassDate`，`history_exists` 且開課日有變時保持 modal 開啟並顯示橘色警告。(2) 新增 `partial_rebuild` 結果訊息。(3) 操作選單新增「重建未上堂次」入口（橘色），點擊跳出確認 modal（說明保留與重排範圍、不可復原），確認後呼叫 `force_partial_rebuild: true`。
- **`CourseManagement.vue` modal**：移除 `@click.self="showEditModal = false"`，防止點擊遮罩意外關閉編輯表單。

#### D. 排課手動日期限制（UniversalClassScheduler）
- **前端 `onDateClick`**：過去日期不再檢查固定上課星期（`cell.ymd < todayYmd` 時跳過 `selectedDays` 驗證）。
- **前端 `sessionCountForYmd`**：手動日期若不在固定星期，改回傳 1（之前回傳 0，導致不被計入堂數）。
- **前端送出邏輯**：`getSlotIndicesForDay` 找不到時段時，改用全域預設 `start_time` 建立堂次，不再 alert 阻擋。
- **後端 `EnrollmentService`**：星期驗證加入 `$today` 判斷，`$row['date'] < $today` 的過去日期跳過固定星期限制。
- **說明文字**：兩處 hint 改為「手動日可自由選擇任意日期」。

### Notes
- `退回未上`（`attended → scheduled`）會呼叫 `voidAttendanceArtifacts()`，將 StudentSignIn 與 LearningRecord 的 `VoidedAt` 設為作廢。搭配 (C) 修復，退回後再編輯開課日即可觸發全量重建，無需刪除重建。
- 強制重建 modal 的 CSS class：`.rebuild-modal`、`.rebuild-modal__*`；JS：`openRebuildModal()`、`submitForceRebuild()`。

---

## 2026-04-12 (G) — 老師：教學工作台（預設首頁）與跨分校本週課表

### Added
- **`frontend/src/pages/TeacherHomePage.vue`**（`App.vue` 內 `active === 'teacher-home'`）：老師登入後預設首頁「**教學工作台**」— **今日待辦**（待點名／待填與需修改評量之 CTA）、**本週課表**（依 `teacherBranches` 對各 `branch_id` 並行呼叫 `fetchClassSessions` 後合併、排序、去重；每筆附分校標籤；週切換；單校失敗時其餘分校仍顯示並提示）、科目數與班級行事曆捷徑；多校時若他校當日有課則顯示輕提示。
- **`App.vue`**：`mergeTeacherAttendanceBadge()` — 老師輪詢時以 `GET /api/v1/class-sessions`（當日）計算待點名數，寫入 `badgeByType.attendance`，側欄「出缺勤管理」可顯示紅點（不依主任 `notifications/unread-count`）。

### Changed
- **老師導覽**：預設 `active` 由 `learning` 改為 `teacher-home`（登入、`fetchProfile`、改密碼完成後）；側欄順序為 教學工作台 → 出缺勤 → 課表與評量 → 班級行事曆 → 科目數…；手機底欄五格為 工作台／出勤／評量／行事曆／更多（科目數等入「更多」）。
- **`LearningRecordsPage.vue`（老師路徑 RWD）**：`.ts-fill-btn`、`.ts-event`、`.ts-tabs button` 觸控區與高度；`@media (max-width: 768px)` 內表單 `input`/`select`/`textarea` 設 `font-size: 16px`，降低 641–768px 寬度區間 iOS 自動縮放問題。

### Notes
- 從工作台週課表點「填評量」若堂次屬他校，會寫入 `localStorage.app_branch` 再切至「課表與評量」；`learningTargetRecordId` 仍由 `App.vue` 既有機制傳入（有 `recordId` 時）。
- 前端上線務必 **`cd frontend && npm run deploy`**，保持 `backend/public/index.html` 與 `assets` hash 一致（見本檔 **2026-04-12 (F)** 與 `docs/AI_REGRESSION_LESSONS.md`）。

### Docs（協作／防再犯索引）
- **`docs/AI_REGRESSION_LESSONS.md`**：新增 **「2026-04-12 — 老師教學工作台（TeacherHome）」** 專節（禁止回歸、關聯檔、搜尋關鍵字）。
- **`CONTRIBUTING.md`**、**`AGENTS.md`**、**`CLAUDE.md`**、**`.github/copilot-instructions.md`**、**`AI_QUICKSTART.md`**、**`docs/GITHUB_SYNC_WORKFLOW.md`**：補齊 `git pull`／新 clone 後閱讀順序與 TeacherHome 對照，供 GitHub 上人類與 Copilot／Claude／Cursor 一致遵循。

---

## 2026-04-12 (F) — 前端 deploy：`index.html` 與 `assets` 強制一致

### Fixed
- **整站白屏（`index-*.js` MIME type `text/html`）**：多為 `backend/public/index.html` 仍引用舊 hash 的 `./assets/index-*.js`，實體檔已換新名；請求 miss 時 SPA fallback 回傳 HTML。已執行完整 **`npm run deploy`** 修復線上檔案組合。

### Changed
- **`frontend/scripts/copy-to-backend.cjs`**：`index.html` 改以 **`writeFileSync` 整份寫入** `backend/public/`；部署結束後 **`verifyIndexHtmlReferencesAssets()`** 檢查 index 內所有 `./assets/` 引用是否皆存在，否則 **exit 1**，避免靜默留下不同步組合。

### Notes
- 詳見 **`docs/AI_REGRESSION_LESSONS.md`**（2026-04-11 — 前端上線 hash 不同步）及同檔 **2026-04-12 補強** 列。

---

## 2026-04-12 (E) — 課程編輯時段同步修正

### Fixed
- **編輯課程時段被舊堂次覆寫（兩處分支）**：`StudentClassController::update` → `maybeRebuildSessionsAfterUpdate` 有兩條路徑會略過未來堂次時間同步：(a) 無 `StartDate` 時若堂次全被鎖定，`reconcile` 會用舊時間覆寫新值；(b) 前端固定帶 `first_class_date`（開課日未變），後端直接回 `start_date_unchanged` 完全不跑 `syncFutureScheduledSessionTimes`，`reconcile` 再次覆寫。修正：路徑 (a) 當 `updated_future_sessions === 0` 時跳過 reconcile 並回傳 `reconcile_skipped` + `warning`；路徑 (b) 開課日未變但排程欄位有變時，仍呼叫 `syncFutureScheduledSessionTimes` 同步未來堂次時間。
- **智慧排課 410 主控台噪音**：`SmartCalendar.vue` 仍對已退役的 `POST /api/v1/student-classes/sync` 發請求（後端固定回 410），移除該呼叫。

### Added
- `scheduleFieldsPresentInMapped()` 輔助方法，判斷本次 PUT 是否含排程欄位變更。
- 前端 `CourseManagement.vue` 於 `session_sync.reconcile_skipped` 時顯示明確提示。
- **測試**：`StudentClassUpdateScheduleReconcileTest`（4 tests）涵蓋：有歷史 + 未來 scheduled 堂次正常同步、帶 `first_class_date` 但開課日未變 + 改時段仍同步、全鎖定時 reconcile 跳過、非排程變更仍 reconcile。

---

## 2026-04-12 (D) — 智慧排課：評量未填標示

### Added
- **課表格新增「評」小標**：在日檢視與週檢視的課程區塊上，若該堂次已結束且尚無學習評量紀錄，右下角顯示紅色「評」標籤，與既有的到班（✓）、漏點（!）、請假（假）小標並存。
- **圖例更新**：工具列圖例新增「評 未填評量」說明。
- **排除條件**：請假（leave / excused / leave_adjusted）、取消、未來堂次不顯示未填提示。

### Notes
- 資料來自現有 `GET /api/v1/class-sessions` 回傳的 `learning_record_id` / `learning_record_status`，無新增 API 請求。
- MVP 定義：無任何有效 `LearningRecord` 列（`learning_record_status === 'missing'`）。若需區分「有 pending 列但內容空白」，可在後續 Phase 2 擴充後端欄位。

---

## 2026-04-12 (C) — 事後補點名（Makeup Attendance）

### Added
- **出缺勤管理新增「待補點名（已結束節次）」區塊**：主任／櫃檯可依日期範圍查詢過去已結束但尚未點名的堂次，直接在頁面上補登出缺勤狀態（到班／遲到／缺席／請假），與現有「今日待點名」並存。
- **`GET /api/v1/attendance/ended-sessions` 強化**：新增 `start_date`／`end_date` 日期篩選（預設最近 7 天）、分頁（`per_page` 最大 200）、`VoidedAt` 過濾（已作廢的出缺勤不視為已點名，允許重新補登）。未帶 `branch_id` 且無校區時回傳 422。
- **測試**：`MakeupAttendanceEndedSessionsTest`（7 tests）涵蓋：列表回傳、active sign-in 排除、voided sign-in 可補登、super_admin 必帶 branch_id、老師僅自己課程、跨分校 403、補登後扣堂與狀態更新。

### Notes
- 補登走既有 `POST /api/v1/attendance`（`mark_mode` 省略，走預設 ended 模式），商業規則與扣堂邏輯完全一致。
- 請假（excused）補登同樣觸發順延。
- 「今日待點名」與「待補點名」的產品區隔：前者查 `class-sessions` 當日 scheduled、使用 `mark_mode=arrival`；後者查 `ended-sessions` 已過結束時間且無 active sign-in。

---

## 2026-04-12 (B) — 請假與學習評量一致性修復

### Fixed
- **請假後評量仍出現在待填／待審列表**：`LearningRecordController::index` 及所有批次操作（`batchApprove`、`batchReject`、`batchRequestChanges`）現一律排除已作廢（`VoidedAt` 有值）的評量列。修正前，`CourseLeaveCascadeService` 請假時僅設定 `VoidedAt` 但列表查詢未過濾，導致已請假的堂次仍出現待審評量。
- **`ensurePastRecords` 對請假堂次不再補建評量**：排除 `ClassSession.Status` 為 `leave`、`excused`、`leave_adjusted` 的堂次；若該堂次已有被作廢的評量列，也不會重複建立新的 pending 評量。
- **通知與科目數統計防呆**：`NotificationSyncService::buildLearningNotifications`、`FinanceController`（`subjectUnits`、`teacherPayroll`、`summary`）、`ParentPortalController` 核准評量列表均補上 `->active()` 排除作廢列。
- **唯一查找一致化**：`LearningRecordController::store`、backfill、`StudentClassController`、`ClassSessionController`、`EnrollmentService` 中以 `ClassSessionID` 查找既有評量改為 `active()->first()`，避免與作廢列衝突或在唯一約束下重複建立。
- **第二層（孤兒 pending）**：僅 `active()` 仍會漏掉「`VoidedAt` 為空、但堂次已是請假」的歷史錯誤列（多為舊版 `ensure-past` 在請假後仍補建）。已新增 **`LearningRecord::excludeLeaveSessionPendingReview()`**，套用在 `index`、`batchApprove`、`buildLearningNotifications`；並對已存在之孤兒筆執行一次性作廢（理由：堂次已請假，系統自動作廢孤兒評量）。

### Notes
- **驗收條件**：已請假且評量已作廢的列不出現在老師／主任的待填／待審列表，不進待審評量通知，不因 `ensure-past` 自動補建。
- **歷史資料**：上線後若原先列表中有因此消失的評量，屬預期行為（該堂實際已請假）。
- **防再犯**：`docs/AI_REGRESSION_LESSONS.md` 內 **2026-04-12 — 請假與學習評量** 一節；營運可偶跑該節所列稽核 SQL，確認孤兒筆數為 0。

---

## 2026-04-12 — 出缺勤科目顯示、待點名科目、Subject 中文化、曠改請假順延、舊 SubjectID 映射

### Fixed
- **`GET /api/v1/attendance`**（`AttendanceController::index`）：`subject_name` 改為 **`COALESCE(課程主檔 Subject, 簽到快照 Subject)`**（`sub_sc`／`sub_si` 兩次 left join）。修正歷史資料僅在 `StudentSingIn.SubjectID` 有值、但 `StudentClass.SubjectID` 為空或指到無效列時，「今日出缺勤紀錄」科目欄大量為「—」的問題。
- **`GET /api/v1/class-sessions`**（`ClassSessionController::index`）：新增 `leftJoin Subject on sc.SubjectID`，回傳 `subject_name`。修正「今日待點名堂次」表格科目欄全部顯示「—」的問題（前端已讀取該欄位，僅後端未回傳）。
- **老師手機／出缺勤**：將某堂次標為 **`excused`（曠改請假）** 且帶既有 `ClassSessionID` 時，與課程管理請假一致：建立對應 `schedules` 請假列，並呼叫 **`CourseLeaveCascadeService::applyLeaveCascade`** 順延後續堂次、必要時延伸 `EndDate`；再寫入一筆 `StudentSingIn(Status=excused)` 供列表顯示。

### Changed
- **`Subject` 表 `Subject_Name` 改為中文**：Chinese→國文、English→英文、Math→數學、Physics→物理、Chemistry→化學、Science→理化、Social→社會（生物不變）。所有透過 Subject JOIN 取得科目名的 API 均直接回傳中文，使用者無需額外對照。前端 `constants.js` 的 `SUBJECT_NAME_MAP` 已支援中英雙向映射。
- **`ScheduleController`**：請假／補請假／retro-leave 等路徑改以 **`CourseLeaveCascadeService`** 為單一實作，降低與出缺勤請假邏輯分歧。

### Added
- **`backend/app/Services/CourseLeaveCascadeService.php`**：請假後鎖定課程與堂次、標記請假堂、void 相關評量等、後續預排前移並補尾堂（與 `ScheduleController` 請假／補請假路徑共用）。
- **Migration `2026_04_12_200000_remap_orphaned_subject_ids`**：一次性將舊系統殘留的 `SubjectID`（1／14／15／21）映射到目前 `Subject` 表對應列，同步更新 `StudentClass` 與 `StudentSingIn`。**部署新後端後請執行 `php artisan migrate`**，否則 JOIN `Subject` 仍可能得不到名稱。
- **測試**：`AttendanceSubjectNameResolutionTest`、`AttendanceExcusedLeaveCascadeTest`。

### Notes
- **僅有 ClassSession 請假、尚無簽到列**的補充查詢（supplemental）仍只依 **`StudentClass.SubjectID`**；若仍顯示「—」請於學生課程補齊科目。
- 新增科目時 `Subject_Name` 請使用中文（與現有資料一致）。

### Docs
- `docs/FAQ.md`、`docs/AI_REGRESSION_LESSONS.md`、`docs/OPERATIONS_RUNBOOK.md` §K（含曠改請假 2a、回歸檢查 migration 項）已同步本批行為。

---

## 2026-04-11 (D) — 加購課程自動建立排課堂次（ClassSession）

### Fixed
- **`POST /api/v1/student-classes/{id}/purchase-batch`**：加購新批次後，系統現在會自動依來源課程的星期／時段設定建立 `ClassSession` 排課列（使用 `buildSessionsForCount`），並更新新課程 `EndDate` 與計數器。此修復前，加購只建立 `StudentClass` 而無堂次，導致智慧排課（SmartCalendar）看不到該課程。
- 一次性補齊兩筆既有孤兒課程（#262、#187）的 `ClassSession`。

### Changed
- `purchase-batch` API 回應新增 `created_sessions` 欄位與 `new_course.end_date`。

---

## 2026-04-11 (C) — 繳費／續課提醒：加購自動結案 + 結案 UI

### Changed
- **加購新批次（`purchase-batch`）**：若來源課程為堂數制、已繳（`Paid=1`）、剩餘 0 堂，加購成功後自動將來源設 **`Stop=1`**（`EndDate` 寫入當日），不再出現在主任總覽「繳費／續課提醒」中。
- **主任總覽**：標題改為「繳費／續課提醒」；`low_sessions` 徽章改為「已繳 · 堂數已用完」或「已繳 · 剩 N 堂」，與「未繳 · N 堂」明確區分；「複製通知」針對 `low_sessions` 改為續課／加購用語。

### Added
- **結案（不續報）UI**：課程管理（`CourseManagement.vue`）與學生課程（`StudentsList.vue`）對「堂數制 + 已繳 + 0 堂 + 進行中」提供「結案（不續報）」按鈕；confirm 後呼叫既有 `POST .../student-classes/{id}/pause`（`Stop=1`），該課程從繳費提醒消失。
- 測試：`PurchaseBatchClosesSourceTest`（3 tests）驗證自動結案與防誤關。

### Docs
- `docs/DIRECTOR_PAYMENT_ALERT_RULES.md`：新增「結案與不再提醒」段落、回歸測試項。
- `docs/FAQ.md`：新增上完不補／為何還提醒 → 結案操作。

---

## 2026-04-11 (B) — 核准評量 = 點名核課（重大架構變更）

### ⚠️ Breaking Change — 核准評量現在會扣堂

> **改動前必讀**：`docs/OPERATIONS_RUNBOOK.md` §K、`docs/AI_REGRESSION_LESSONS.md`（2026-04-11 核准評量扣堂）

### Changed
- **核准評量（`LearningRecordController::approve / batchApprove`）現在等同點名**：
  - 核准時透過 `ApprovalSessionSyncService::syncOnApprove` 建立 `StudentSignIn(Memo=lr_approve, SessionDeducted=true)`
  - 同步更新 `ClassSession.Status → attended`
  - 呼叫 `SessionDeductionService::deductOnAttendance`（與手動點名同一管線）
  - 堂數制：`RemainingSessions -1`；月結制：`UsedSessions +1`（`RemainingSessions` 恆 0）
- **退回核准（`rollbackApproval`）對稱沖回**：void `lr_approve` 型 SignIn → reverse ledger → 若無其他點名則 `ClassSession.Status → scheduled`
- **冪等保護**：若已有獨立點名（`SessionDeducted=true` SignIn），核准不重複扣堂；rollback 不影響獨立點名
- 核准後再手動 POST attendance → 回傳 409（已有 SignIn）

### Added
- **`backend/app/Services/ApprovalSessionSyncService.php`**（新服務）：`syncOnApprove` / `syncOnRollback`，含守衛規則（leave/cancelled/未來堂次 skip、冪等 skip）
- 測試新增 3 個情境：月結制、orphan LR 綁定、409 衝突
- 測試改寫 3 個情境：核准扣堂、已點名不重複扣、rollback 對稱沖回

### Docs
- `docs/OPERATIONS_RUNBOOK.md` §K 全面更新（口徑、禁忌、回歸清單 5 → 7 項）
- `docs/AI_REGRESSION_LESSONS.md` 新增防再犯條目
- `docs/CHANGELOG.md` 本節

### 受影響關鍵檔案（修改前必讀本節與 §K）
- `backend/app/Services/ApprovalSessionSyncService.php`
- `backend/app/Services/SessionDeductionService.php`
- `backend/app/Http/Controllers/LearningRecordController.php`（approve / batchApprove / rollbackApproval）
- `backend/app/Http/Controllers/AttendanceController.php`
- `backend/tests/Feature/LearningRecordApprovalDeductionTest.php`

---

## 2026-04-11

### Added
- 新增 **`docs/FAQ.md`**：專案常見問題（角色、部署、登入、GitHub 同步、文件索引）；**`docs/DIRECTOR_SCALING_FAQ.md`**：大分校／主任向效能與資料說明
- 新增內部聊天系統（`/api/v1/chat/*`）：
  - 1 對 1 聊天、群組聊天室、訊息列表、已讀標記、未讀統計；訊息／成員帶**頭像 URL**（根相對 `/storage/...`）
  - 資料表：`chat_threads`、`chat_thread_members`、`chat_messages`
  - 前端頁面：`frontend/src/pages/ChatPage.vue`
- 新增 Bug 回報系統（`/api/v1/bugs*`）：
  - 全系統可提交；**主任／老師僅能看自己的回報**；**僅 `super_admin` 可更新狀態與內部備註**
  - **截圖附件**：`bug_report_attachments`，`POST /bugs` 支援 `attachments[]`
  - **側欄紅點**：`GET /bugs/unread-badge`、`POST /bugs/mark-inbox-seen`（super_admin）、`bug_report_user_reads` 與 `User` 收件匣欄位
  - 資料表：`bug_reports`、`bug_report_comments`、`bug_report_status_logs`、`bug_report_attachments`、`bug_report_user_reads`
  - 前端：`frontend/src/pages/BugReportsPage.vue`、`BugReportLauncher.vue`；`App.vue` 合併 `badgeTypes: ['bugs']` 與 `alltrue-refresh-badges`
- **文件**：**`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**（後續 AI／工程師改動前必讀，含禁止回歸項）

### Changed
- **登入（`POST /api/v1/auth/login`）**：查詢候選使用者時排除 `User.status` 為 **`inactive`**、**`suspended`** 的列，避免帳號合併／停用後仍因 `LoginName`／`Name` 比對（含不分大小寫）而登入舊帳。測試：`tests/Feature/AuthInactiveUserLoginTest.php`。
- **登入（老師待審核）**：`type = T` 且尚無任一「已放行」分校（`UserCampus` 無 `Approved = 1` 或 `Approved IS NULL` 之列）時，回 **403**，`message` 提示聯繫主任審核，`code`：`teacher_pending_approval`（與舊版僅依 `require_campus` 擋 API 不同，登入階段即拒絕發 token）。無 `UserCampus.Approved` 欄位時仍僅依 `User.status === pending` 判斷。測試：`tests/Feature/AuthTeacherPendingApprovalLoginTest.php`。
- Bug：**已移除指派**（無 assign API／UI；詳情不再回傳承辦人欄位）
- Bug 狀態：`POST /bugs/{id}/status` 僅 **`middleware super_admin`**（`RequireSuperAdmin`）
- Bug 留言：恢復 `is_internal_note` 為「回報者不可見」；`super_admin` 可在詳情頁切換每則留言「內部 / 給回報者看」
- 使用者頭像：`User.AvatarUrl` 上傳後只存 **disk 相對路徑**；API 經 **`App\Support\PublicAvatarUrl`** 輸出，避免 `APP_URL=localhost` 造成聊天／側欄破圖
- UI：Bug 浮動鈕可拖曳；聊天選人顯示名稱正規化

### Infra / Notes
- Laravel Broadcasting：`backend/config/broadcasting.php`、`routes/channels.php`、`ChatMessageCreated`
- 測試：`ChatApiTest.php`、`BugReportApiTest.php`；頭像相關可搭配 `ProfileCenterApiTest.php`

### Follow-up
- 建議把 WebSocket（soketi）納入正式常駐程序。
- 建議修復 `frontend/node_modules` 權限問題（或沿用 vendor-modules alias）。

**完整行為與檢查清單**：`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`。
