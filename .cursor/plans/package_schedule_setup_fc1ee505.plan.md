---
name: Package Schedule Setup
overview: 三層優化：在方案建立時支援設定各科排課時段並自動補排、優化方案管理頁 UX（排課狀態顯示、健康度指示、分科排課摘要），使主任無需再跑到學生列表逐科補設時段。
todos:
  - id: preflight-read-schedule
    content: "[PRE-FLIGHT] 讀取確認程式位置：(1) CoursePackageController::createMultiSubject 行 155-352（確認 $weekSlots 行號、StudentClass::create 欄位列表、day_time_slots 驗證但未使用處）；(2) StudentClassController::extendSessionsIfNeeded 確認 currentCount 計算包含 attended 狀態（RISK-001 防範）；(3) resolveScheduleSlotsForRebuild 確認讀取 week/time/week1~week6/time1~time6 的邏輯；(4) CoursePackagesPage.vue emptyForm + submitCreate + subject-row template 的行號；(5) CoursePackageController::index 或 show 的 members 查詢方式（確認如何 eager load）。記錄所有實際行號後才進入下一步。"
    status: completed
  - id: backend-create-write-schedule
    content: "[BACKEND] CoursePackageController::createMultiSubject — 讓現有已驗證但死碼的排課欄位真正生效：⚠️ 現有 $weekSlots 轉換 ((int)$dow+6)%7 有誤（輸出 0–6，但排課系統期望 ISO 1–7，1=Mon）；必須刪除此轉換，直接將 days_of_week 的原始值（1–7）寫入 StudentClass 欄位。(A) 在 DB::transaction 內，StudentClass::create payload 加入排課欄位：若 day_time_slots 非空，slot[0]→{week1, time1}、slot[1]→{week2, time2}（最多 6 個），超過 6 截斷並 Log::warning；若只有 days_of_week（含 start_time），單日→{week: $dow, time: $startTime}，多日→{week1: $dows[0], time1: $startTime, week2: $dows[1], time2: $startTime, …}；(B) DB::transaction 內對「有排課欄位的成員」以 app()->make(StudentClassController::class)->extendSessionsIfNeeded($member, $pkg->total_sessions) 補排（與 update 路徑一致，在 transaction 內呼叫）；$member 為 StudentClass Eloquent 物件，需在 create 後 fresh() 取得；(C) 整理 per-member 補排結果：$memberScheduled[] = ['student_class_id'=>$sc->ID, 'subject'=>$subjectName, 'scheduled_count'=>補排後實際 ClassSession 數, 'first_session_date'=>最早 ClassSession date 的 Y-m-d]；(D) API 回應 members_scheduled: $memberScheduled；(E) Log::info 記錄方案建立含每科補排堂數。只修改 createMultiSubject；不動 update 路徑。"
    status: completed
  - id: backend-members-scheduled-count
    content: "[BACKEND] CoursePackageController::index（listPackages）— members 是用 StudentClass::where('PackageID',$pkg->id)->select(['ID','SubjectID','TeacherID','ClassType','Stop'])->get() 取出，無 Eloquent relation。修改方式：(A) 在 map 內取出所有 memberIds；(B) 一次查詢 scheduled_count：ClassSession::whereIn('StudentClassID',$memberIds)->whereNotIn('Status',['cancelled','leave','leave_adjusted'])->select('StudentClassID',DB::raw('COUNT(*) as cnt'))->groupBy('StudentClassID')->pluck('cnt','StudentClassID')；(C) 一次查詢 next_sessions（最近 3 堂未來排課日期）：ClassSession::whereIn('StudentClassID',$memberIds)->where('Status','scheduled')->where('Date','>=',now()->toDateString())->orderBy('Date')->get()->groupBy('StudentClassID') 後每組取前 3 筆 Date 組成陣列；(D) 一次查詢 has_schedule：StudentClass::whereIn('ID',$memberIds)->select('ID','week')->pluck('week','ID') 後 week >= 1 && week <= 7 即為 true；(E) members map 回傳新增欄位：scheduled_count、has_schedule、next_sessions（Y-m-d 陣列，最多 3 個，若無則 []）。不修改 show 路徑。"
    status: completed
  - id: frontend-create-modal-schedule
    content: "[FRONTEND] CoursePackagesPage.vue 建立 modal — (1) emptyForm() 的每個 subject 新增 days_of_week: []、start_time: '16:00' 欄位；dayOptions 使用與 StudentsList.vue 一致的規格：[{value:1,label:'一'}…{value:7,label:'日'}]（1=Monday…7=Sunday，不是 0-based）；(2) subject-row template 在時長後新增：星期幾多選 chip 組（一排 7 個），下方 start_time 下拉（30 分鐘間距 06:00~22:00）；月結方案（payment_type === 'monthly'）的科目行不顯示時段欄位；(3) submitCreate payload 的 subjects 加入 days_of_week（直接傳入 1–7 值陣列）、start_time；(4) 新增狀態 refs：const showSummaryModal = ref(false); const createSummaryData = ref([])；submitCreate 成功後：createSummaryData.value = response.data.members_scheduled ?? []; showSummaryModal.value = true; showCreateModal.value = false; form.value = emptyForm()；(5) 新增排課摘要 dialog（teleport to body）：迴圈 createSummaryData 顯示每科；scheduled_count > 0 顯示「{subject}：已排 {scheduled_count} 堂（首堂 {first_session_date}）」；scheduled_count === 0 顯示「{subject}：尚未設定排課時段」+ <a> 連結至課程管理；關閉按鈕將 showSummaryModal = false；(6) 送出期間 button disabled + loading spinner。"
    status: completed
  - id: frontend-member-chip-status
    content: "[FRONTEND] CoursePackagesPage.vue 成員格（member-chip）— (1) 利用 backend-members-scheduled-count 提供的 scheduled_count、has_schedule、next_sessions 計算每科狀態：(member.scheduled_count ?? 0) >= pkg.total_sessions → 已排齊（綠）；(member.scheduled_count ?? 0) > 0 && < total_sessions → 部分排課（黃）；(member.scheduled_count ?? 0) === 0 → 未排定（橘）；(2) 成員格第二行顯示狀態 tag（font-size 0.72rem），已排齊顯示「已排 {scheduled_count}/{pkg.total_sessions}」，部分排課同格式，未排定顯示「未排定」；(3) 成員格第三行顯示最近 3 堂日期（member.next_sessions 陣列以「·」分隔，若陣列空或未提供則顯示「-」）；(4) 「未排定」tag 可點擊，點擊前出現確認 confirm（「即將前往課程管理頁，是否繼續？」），確認後以 window.location.href 導向 `/course-management?student_class_id={member.student_class_id}`（依現有前端路由規格，若無此路由則導向主課程管理頁）；(5) 所有 member.scheduled_count/next_sessions 存取均加 ?? 0 或 ?? [] null guard。"
    status: completed
  - id: frontend-health-badge
    content: "[FRONTEND] CoursePackagesPage.vue 健康度 badge + 統計列 — (1) 計算每個方案的 healthLevel：'green'（所有成員 scheduled_count >= total_sessions）/ 'orange'（至少一科 0 堂）/ 'yellow'（至少一科未達 total_sessions）；(2) package-header 的 package-info 區域在方案名稱右側新增圓形 health badge（18px，綠色 ✓ / 橘色 ⚠ / 紅色 ✗），hover tooltip 顯示「X 科已排齊 / Y 科未排定」；(3) packages-header-card 下方新增統計列：計算 filteredPackages 中 healthLevel !== 'green' 的方案數，顯示「N 個方案排課不完整」橘底白字；(4) 統計列點擊切換 scheduleHealthFilter（'incomplete' 或清除），疊加在 statusFilter 篩選上；(5) filteredPackages computed 加入 scheduleHealthFilter 篩選邏輯；(6) 統計列無不完整方案時顯示綠色「✓ 所有方案排課已完整設定」。"
    status: completed
  - id: uiux-polish
    content: "[UI/UX 精緻化] 依第 5b 節規格實作：(1) 建立 modal 星期幾 chip：7 個方形 chip（24×24px），選中 var(--primary)，hover scale 1.05×，間距 4px；(2) 成員格 tag 使用 5b 節指定色票（#d1fae5/#fef3c7/#fed7aa），border-radius 4px；(3) health badge 18px 圓形，純色背景，transition 0.15s；(4) 統計列顯示/隱藏有 slide-down 動畫（max-height transition）；(5) 排課摘要 dialog 空狀態（所有科未填時段）有圖示 + 說明文字 + CTA 按鈕「前往課程管理設定」；(6) 成員格展開時排課資料用 skeleton 過渡，避免 layout shift；(7) UI/UX Designer 需按 第 10 節 UI/UX 驗收清單逐項確認並 sign-off。"
    status: completed
  - id: test-create-with-schedule
    content: "[TEST] 新增 backend/tests/Feature/PackageCreateWithScheduleTest.php，測試案例（參照 CoursePackageTest.php 的 helper 格式）：(1) test_create_with_days_of_week_generates_class_sessions：2 科含時段，各補排 total_sessions 堂 ClassSession；(2) test_create_without_schedule_no_sessions：不填時段，0 堂 ClassSession，200 OK；(3) test_create_partial_schedule：1 科有時段 1 科無，有時段科補排完整，無時段科 0 堂；(4) test_create_with_confirmed_dates_plus_schedule：confirmed_dates + 排課時段，補排數 = total - confirmed 數；(5) test_create_schedule_writes_week_fields：傳入 days_of_week:[1] (Monday)，斷言 StudentClass.week === 1（NOT 0）；傳入 days_of_week:[7] (Sunday)，斷言 StudentClass.week === 7（NOT 6）；此測試防止 ((int)$dow+6)%7 的舊轉換 bug 回歸；(6) test_create_multi_days_writes_week1_week2：傳入 days_of_week:[1,3]，斷言 StudentClass.week1===1, week2===3；(7) test_monthly_package_ignores_schedule：月結方案不補排；執行 ./vendor/bin/phpunit --filter PackageCreateWithScheduleTest 確認全部通過。"
    status: completed
  - id: test-regression-create
    content: "[TEST/REGRESSION] 執行 ./vendor/bin/phpunit --filter 'SessionCountWarningTest|PackageDisplayAndGuardTest|CoursePackageTest|PackageTotalSessionsSyncTest' 確認所有既有測試仍通過；若有失敗回查 createMultiSubject 修改是否影響 createPackageSetup helper；修正後再跑。"
    status: completed
  - id: security-review-schedule
    content: "[資安] 確認 createMultiSubject 新增排課欄位走現有 role guard（director/admin/super_admin + campus 隔離）；確認 days_of_week 後端已驗證為 array of 1–7 整數（防止注入）；確認 day_time_slots 的 start_time 格式為 H:i（已在 validation 中）；確認 Log::info 包含 member scheduled_count 統計。"
    status: completed
  - id: code-review-schedule
    content: "[REVIEW] 最終審查：(1) 確認 extendSessionsIfNeeded 的 currentCount 計算在 attended 狀態已存在時仍精確（RISK-001）；(2) 確認 week/time 欄位寫入不超過 week6/time6（RISK-002 slot 上限）；(3) 確認 listPackages 的 scheduled_count 查詢無 N+1（RISK-003）；(4) ReadLints 所有修改過的 .php 與 .vue 檔案；(5) 確認月結方案路徑未觸發排課延伸（FR-008 回歸）。"
    status: completed
  - id: docs-changelog-schedule
    content: "[DOCS] 更新 docs/CHANGELOG.md，新增條目（日期 2026-04-20）：(1) CoursePackagesPage 建立方案 modal 支援每科設定排課時段（星期幾 + 時間），建立後自動補排 ClassSession；(2) POST /api/v1/course-packages/create-multi-subject 新增排課欄位寫入 StudentClass + extendSessionsIfNeeded 補排；(3) CoursePackagesPage 成員格新增排課狀態 tag（已排齊/部分排課/未排定）；(4) 方案卡片新增健康度 badge + 統計篩選列。"
    status: completed
  - id: deploy-schedule
    content: "[部署] (1) 後端：git add -A && git commit && git push（無 migration）；(2) 前端：cd /home/admin/frontend && npm run deploy，確認 index.html + assets/ 同輪更新；(3) 驗收：建立一個含 2 科時段的測試方案，確認 ClassSession 自動生成、member-chip 顯示「已排 N/N」綠 tag；(4) 執行 php artisan packages:sync-session-counts --dry-run 確認歷史方案不受影響；(5) 確認月結方案建立流程仍正常（FR-008 回歸）。"
    status: completed
isProject: false
---

# PRD — 多科共用方案排課設定與管理頁 UX 優化

## 1. 文件資訊

| 欄位 | 內容 |
|------|------|
| 功能名稱 | 多科共用方案排課設定（建立時設時段 + 管理頁健康度視圖） |
| 版本 / 日期 | v1.0 / 2026-04-20 |
| 狀態 | Draft |
| 目標角色 | 主任（建立方案、設定排課、監控方案健康度） |

---

## 2. 目標與業務背景

### 痛點（三層）

**層一 — 建立斷點**：主任建立多科共用方案時，介面只允許填「科目 + 老師 + 時長」，沒有「上課星期幾 + 時間」欄位。完成後，系統無法自動排課，每科都顯示「未排定 / 0 堂排課」，必須跑到學生列表逐科點「編輯課程」才能補填時段，流程中斷至少需要 N 次額外操作（N = 方案科目數）。

**層二 — 後端死碼**：`createMultiSubject` API 已驗證 `days_of_week`、`day_time_slots`、`start_time` 等欄位，但建立的 `$weekSlots` 變數從未被寫入 `StudentClass`，且建立後也不呼叫 `extendSessionsIfNeeded`。API 接受排課資料卻靜默丟棄。

**層三 — 管理頁資訊不足**：方案管理頁（CoursePackagesPage）成員格只顯示「科目 + 老師 + 是否停課」，無法看到哪些科目已排課、哪些還是「未排定」狀態。主任必須切換到其他頁面才能確認方案健康度。

### 業務價值

- 主任建立方案的操作步驟從「建立 → 逐科編輯 N 次」縮短為「建立（含時段）→ 完成」
- 方案管理頁一眼看出方案是否完整就緒，不需要跨頁確認
- 「排程列數與購買堂數不一致」警告出現頻率大幅降低（因為建立時即補排完整）

### 成功指標 (KPI)

- 方案建立完成後，有填時段的科目：排課完整率 = 100%（`ClassSession` 列數 = `SessionCount`）
- 方案管理頁「排課未設定」科目有橘色警示，主任不需要跨頁才能發現問題
- 方案建立後跳至「排課確認摘要」，主任可一次確認所有科目的排課日期

---

## 3. 範圍

### In Scope

**Level 1（建立時設時段）**
- `CoursePackagesPage` 建立 modal 每科行新增「星期幾 + 上課時間」欄位（可選填）
- 後端 `createMultiSubject` 將 `days_of_week` + `start_time` + `day_time_slots` 正確寫入 `StudentClass` 對應的 `week`/`time`/`week1~week6`/`time1~time6` 欄位
- 建立後對每個有時段設定的成員呼叫 `extendSessionsIfNeeded`，補排 `ClassSession`
- 建立成功後顯示「排課確認摘要」彈窗，列出每科補排的日期數

**Level 2（排課確認摘要 + 成員健康度）**
- 方案管理頁成員格（member-chip）新增排課狀態標籤：已排（綠）/ 未排定（橘）/ 部分排課（黃）
- 點展開方案後，成員格顯示「下 N 堂上課日期」（最近 3 堂）

**Level 3（方案健康度指示器）**
- 每個方案卡片 header 加入「方案健康度」icon badge：全部已排（綠色勾）/ 部分未排（橘色警示）/ 全部未排（紅色叉）
- 方案管理頁頂部加入統計欄：未完整設定的方案數量，點擊快速篩選

### Out of Scope

- 月結方案（billing_mode = date）的排課設定邏輯不涉及 SessionCount，本次不修改
- `CourseEditForm`（學生列表/課程管理頁的編輯課程）不修改，仍是微調排課的主入口
- 行事曆視圖（Calendar View）本次不做，列表摘要為主
- 家長端、老師端不受影響

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|------|------|---------|
| PM | 產品負責人 | A（負責簽核） |
| CTO / 工程 Lead | 後端 + 前端工程師 | R（實作） |
| UI/UX Designer | 設計師 | R（5b 節精緻化規格與 sign-off） |
| QA | 測試工程師 | R（驗收） |
| 資安 | 資安工程師 | C（新 API 欄位的存取控制審查） |
| IT / Ops | 運維人員 | I（部署通知） |

---

## 5. User Stories

### US-001 — 建立方案時一次設定各科排課時段

> **As a** 主任，**I want** 在建立方案時就能為每科填上「上課星期幾 + 時間」，**so that** 建立後系統自動生成所有排課，不需要再跑到學生列表逐科補設。

Acceptance Criteria：
- [ ] 建立 modal 每科行新增「星期幾（多選）」與「預設上課時間」欄位（均為選填）
- [ ] 未填時段的科目，行為與現有相同（建立 StudentClass 但不產生 ClassSession）
- [ ] 已填時段的科目，儲存後系統自動補排 `ClassSession`（堂數等於 `total_sessions`）
- [ ] 建立成功後顯示摘要：「物理：已排 8 堂（首堂 2026/05/07）」

### US-002 — 管理頁一眼看出哪些科目未排課

> **As a** 主任，**I want** 在方案管理頁看到每科的排課狀態，**so that** 不需要跨頁才能發現「未排定」的問題。

Acceptance Criteria：
- [ ] 展開方案後，成員格顯示排課狀態 tag：「已排 8/8」（綠）/ 「未排定」（橘）/ 「已排 3/8」（黃）
- [ ] 方案卡片 header 顯示整體健康度 icon（全綠 / 部分橘 / 全橘）
- [ ] 「未排定」tag 可點擊，導向該科課程的編輯頁（`CourseEditForm`）

### US-003 — 主任確認建立後的排課摘要

> **As a** 主任，**I want** 建立方案後看到各科排課日期摘要，**so that** 確認排課是否符合預期、不需要另外查詢。

Acceptance Criteria：
- [ ] 建立成功後顯示摘要 dialog，列出每科補排的堂數及前 3 個日期
- [ ] 若某科未填時段，顯示「尚未設定排課時段（點擊編輯）」並附快速連結
- [ ] 主任可從摘要 dialog 直接關閉或導向課程編輯

---

## 5b. UI/UX 精緻化需求

### 建立方案 modal — 科目設定行（Level 1）

| 面向 | 要求描述 |
|------|---------|
| **版面層次** | 每科行由現有 3 欄（科目/老師/時長）擴展為 5 欄（科目/老師/時長/星期幾/時間）；星期幾使用 compact 多選 chip 組（一排 7 個小方塊，選中高亮藍色），不使用下拉以節省空間；時間使用 30 分鐘間距的下拉選單（06:00–22:00） |
| **色彩一致性** | 星期幾 chip 選中色沿用 `var(--primary, #4f46e5)`；未選中為 `#f1f5f9`；時間下拉沿用既有 `.form-control` 樣式 |
| **互動回饋** | 星期幾 chip hover 時輕微 scale 效果（1.05×）；建立按鈕送出期間顯示 loading spinner，disabled 防重複；成功後 3 秒 toast「方案已建立，已自動排課 N 堂」 |
| **空狀態設計** | 摘要 dialog 無排課科目時顯示提示圖示 + 「所有科目未設定排課，可在課程管理頁逐科編輯」+ 「立即前往」按鈕 |
| **載入狀態** | 建立送出期間：整個 modal 按鈕 disabled + loading；建立成功後直接替換為摘要 dialog（無 layout shift） |
| **防呆設計** | 若填了「星期幾」但沒填「時間」，自動補預設值 `16:00`（不報錯，業界慣例）；科目與老師必填（現有驗證），時段選填，不強制 |
| **響應式** | 主任桌面端操作，modal 寬度維持 `max-width: 640px`；星期幾 chip 行在 modal 寬度下完整顯示，不換行 |

### 方案管理頁 — 成員格排課狀態（Level 2）

| 面向 | 要求描述 |
|------|---------|
| **版面層次** | 成員格（member-chip）：第一行保留「科目 + 老師」；第二行新增排課狀態 tag（小字，`font-size: 0.72rem`）和「最近 3 堂」日期（以 `·` 分隔）；若無排課僅顯示橘色「未排定」tag |
| **色彩一致性** | 已排齊：`#d1fae5` 底 `#065f46` 字（與系統已繳色系一致）；部分排課：`#fef3c7` 底 `#92400e` 字；未排定：`#fed7aa` 底 `#9a3412` 字 |
| **互動回饋** | 「未排定」tag hover 顯示 tooltip「點擊前往設定排課時段」，點擊導向課程編輯 |
| **空狀態設計** | 成員格資料尚未載入時顯示 skeleton（2 行）；成員無任何科目時顯示「尚無科目」提示 |
| **載入狀態** | 成員排課資料在展開方案時 lazy load；展開動畫期間顯示 skeleton，避免閃爍 |
| **防呆設計** | 點「未排定」導向前需確認：「即將前往課程管理頁，是否繼續？」（避免主任誤觸） |
| **響應式** | 成員格 chip 流排，超過 3 個時自動換行；最近 3 堂日期在窄版面（< 480px）時隱藏，只顯示 tag |

### 方案管理頁 — 健康度 badge（Level 3）

| 面向 | 要求描述 |
|------|---------|
| **版面層次** | 方案卡片 header 的 `package-info` 區域，在方案名稱右側新增 health badge（圓形 icon，直徑 18px）；頂部加入統計列「N 個方案排課不完整」（橘底白字），點擊篩選 |
| **色彩一致性** | 全部已排：綠色勾 ✓（`#22c55e`）；部分未排：橘色驚嘆號 ⚠（`#f59e0b`）；全部未排：紅色叉 ✗（`#ef4444`）；色彩沿用 progress bar 系統色 |
| **互動回饋** | badge hover 時 tooltip 說明「X 科已排課 / Y 科未排定」 |
| **空狀態設計** | 篩選「排課不完整」後無結果時顯示：✓ 圖示 + 「所有方案排課已完整設定」 |
| **載入狀態** | 健康度計算在 `listPackages` API 回應時一併計算，不另發請求；若資料未載入，badge 顯示灰色 loading dot |
| **防呆設計** | 篩選器「排課不完整」與既有「狀態」篩選（使用中/全部/已暫停）可疊加，不互斥 |
| **響應式** | 統計列在窄版面下折疊為 icon badge，點擊展開 |

---

## 6. 功能需求 (FR)

**FR-001 — 建立 modal 每科支援填排課時段**

系統應在建立多科共用方案的 modal 中，為每個科目行提供「上課星期幾（多選）」與「預設上課時間」欄位。兩者均為選填，不填時建立行為與現有相同。

**FR-002 — 後端寫入 StudentClass 排課欄位**

`POST /api/v1/course-packages/create-multi-subject` 應將每科的 `days_of_week`、`start_time`、`day_time_slots` 正確寫入對應 `StudentClass` 的 `week`/`time`（及 `week1~week6`/`time1~time6`）欄位。

**FR-003 — 建立後自動補排 ClassSession**

對每個有排課時段設定（至少一個 weekday）的成員 `StudentClass`，`createMultiSubject` 應在建立後呼叫排課延伸邏輯（Append-Only，不整刪重建），補排至 `SessionCount` 堂。

**FR-004 — 建立成功後顯示排課確認摘要**

建立方案後，API 回應應包含各成員的補排堂數與首堂日期。前端以摘要 dialog 展示，主任可確認或直接關閉。

**FR-005 — 成員格顯示排課狀態**

方案管理頁展開方案後，每個成員格應顯示排課狀態 tag（已排齊 / 部分排課 / 未排定）及最近 3 堂日期。

**FR-006 — 方案健康度 badge**

每個方案卡片 header 應顯示整體排課健康度 badge，依成員排課完整率計算：全部已排齊（綠）/ 至少一科未達 SessionCount（橘）/ 全部未排（紅）。

**FR-007 — 排課不完整快速篩選**

方案管理頁頂部應顯示「N 個方案排課不完整」統計，點擊後篩選出健康度非綠的方案。

**FR-008 — 非方案課程與月結方案不受影響**

所有現有課程邏輯、月結方案邏輯，與修改前完全相同（回歸保護）。

---

## 7. 非功能需求 (NFR)

**NFR-001 — 效能**

`createMultiSubject` 在 transaction 內補排 ClassSession：預估最多 5 科 × 最多 30 堂 = 150 筆 INSERT，執行目標 < 5 秒；前端 loading 持續期間顯示 spinner。

**NFR-002 — 可選排課（降級）**

排課時段為選填；若主任不填，建立成功但不產生 ClassSession，行為與現有完全一致，API 正常 200 OK。

**NFR-003 — 冪等排課延伸**

對已有足夠 ClassSession 的成員，呼叫排課延伸應為 no-op（不重複建立）。

**NFR-004 — 健康度計算不另發 API**

成員排課狀態由 `listPackages` 回應中的 `members[].scheduled_count` 欄位計算，不額外呼叫 API。

---

## 8. 技術方向（給 CTO）

### 受影響頁面、API、資料表

| 層 | 位置 | 修改說明 |
|----|------|---------|
| 前端 UI | `frontend/src/pages/CoursePackagesPage.vue` | 建立 modal 每科行新增 days_of_week chips + start_time 下拉；submitCreate payload 擴充；成員格新增狀態 tag；header 新增健康度 badge；統計列 |
| 後端 API | `backend/app/Http/Controllers/CoursePackageController.php::createMultiSubject` | 將已驗證但未使用的 days_of_week/day_time_slots/start_time 欄位寫入 StudentClass 對應的 week/time 欄位；建立後呼叫排課延伸；API 回應新增 per-member 補排摘要 |
| 後端 API | `backend/app/Http/Controllers/CoursePackageController.php::index`（或 `show`） | members 回應新增 `scheduled_count`（該成員的有效 ClassSession 數）供前端計算健康度 |
| 資料表 | `StudentClass`（`week`、`time`、`week1~week6`、`time1~time6`） | 無 migration（欄位已存在） |
| 資料表 | `ClassSession` | 無 migration（補排現有欄位） |

### 架構選擇理由

- **days_of_week 對應 StudentClass.week/week1~week6**：現有 `resolveScheduleSlotsForRebuild` 讀取這些欄位；只需在建立時寫入，即可直接複用現有的排課延伸邏輯，零重複實作。
- **單一 `day_time_slots` 物件對應多欄**：StudentClass 表採舊式欄位設計（week1~week6, time1~time6），無法用 JSON 欄位替代。寫入時按陣列索引依序對應（slot[0] → week1/time1，依此類推），最多支援 6 個時段。
- **健康度不另發 API**：`listPackages` 已查詢 `members`，擴充查詢附帶各成員的 `ClassSession` 有效數即可，避免 N+1。
- **無 migration**：所有欄位已存在，只是過去建立時沒有填入排課欄位。

### 子任務派發

- `[FEATURE]` → 後端 API 修改（排課欄位寫入 + 延伸呼叫 + members 擴充回應）
- `[FEATURE]` → 前端 UI（建立 modal 擴充 + 成員格狀態 + 健康度 badge + 統計列）
- `[TEST]` → Feature test：建立含時段的方案 → ClassSession 正確補排
- `[REVIEW]` → 確認排課延伸為 Append-Only、不整刪重建（AI_REGRESSION_LESSONS §2026-04-12）
- `[DOCS]` → 更新 CHANGELOG.md

---

## 9. 資安與存取控制

**存取控制**：`POST /api/v1/course-packages/create-multi-subject` 現有保護（director/admin/super_admin + campus 隔離）適用於新增的排課欄位，不需新增 middleware。

**PII**：排課時段（星期幾、時間）不含個人身份資料。

**稽核 log**：建立方案時已有 Log 記錄；新增補排堂數至 log 資訊（`scheduled_count` per member）即可。

**STRIDE 快評**：

| 威脅 | 評估 |
|------|------|
| Spoofing | 低：沿用現有 role + campus 驗證 |
| Tampering | 低：排課欄位寫入 StudentClass；`days_of_week` 最多 7 個值（1–7），後端已驗證格式，無 injection 風險 |
| Repudiation | 低：補排動作含入既有 Log::info，可追溯 |
| Information Disclosure | 低：members 新增 `scheduled_count`，無個資 |
| Denial of Service | 低：最多 5 科 × 30 堂 = 150 筆 INSERT；transaction 包裹，不構成 DoS |
| Elevation of Privilege | 低：沿用現有 role 體系 |

---

## 10. QA 驗收標準與測試計畫

### FR-001 / FR-002 — 建立含時段的方案

| 路徑 | 測試案例 | 預期結果 |
|------|---------|---------|
| Happy Path | 建立 2 科方案，科 A 填星期一 16:00，科 B 填星期三 14:00 | 兩科 StudentClass 的 week/time 正確寫入；各自生成 `total_sessions` 堂 ClassSession |
| Happy Path | 建立 3 科方案，全部不填時段 | 建立成功（200 OK），無 ClassSession 產生，行為與現有相同 |
| Edge Case | 方案 2 科，1 科填時段，1 科不填 | 有時段的科補排 N 堂；無時段的科 0 堂；API 回傳摘要清楚區分兩種狀態 |
| Edge Case | `days_of_week = [1, 3]`（兩個上課日）且 `total_sessions = 8` | 生成 8 堂 ClassSession，交替週一/週三分佈 |
| Error Case | `days_of_week` 包含無效值（如 8） | 後端驗證拒絕（422） |
| 回歸測試 | 對照 AI_REGRESSION_LESSONS §2026-04-12 | 排課延伸為 Append-Only，不整刪重建，補建數精確等於差額 |

### FR-003 — extendSessionsIfNeeded 呼叫驗證

| 路徑 | 測試案例 | 預期結果 |
|------|---------|---------|
| Happy Path | 建立 8 堂方案含時段 → ClassSession 數 = 8 | assert 8 筆 scheduled/completed ClassSession |
| Edge Case | 方案建立時填 `confirmed_dates`（部分堂已上） + 填時段 | 已上課的 ClassSession 保留；剩餘堂數補排至 `total_sessions` |
| 回歸測試 | 建立後再次 PUT total_sessions 加購 | extendSessionsIfNeeded 仍正確（無重複補排） |

### FR-005 / FR-006 — 健康度顯示

| 路徑 | 測試案例 | 預期結果 |
|------|---------|---------|
| Happy Path | 方案 2 科均已完整排課 | 成員格顯示「已排 8/8」綠 tag；header badge 綠色勾 |
| Edge Case | 方案 1 科已排 3/8，1 科已排 8/8 | 前者顯示「已排 3/8」黃 tag；header badge 橘色警示 |
| Edge Case | 方案所有科均未排課 | 所有成員格顯示「未排定」橘 tag；header badge 紅叉；統計列 +1 |

### UI/UX 驗收清單

- [ ] 建立 modal 星期幾 chip 選中色為系統主色，未選中色與背景有足夠對比
- [ ] 建立期間按鈕 disabled + loading spinner，不可重複點擊
- [ ] 建立成功後摘要 dialog 顯示每科堂數與首堂日期（有填時段者）
- [ ] 摘要 dialog 未填時段的科目顯示「尚未設定排課時段」+ 快速連結，非空白
- [ ] 成員格排課狀態 tag 顏色符合 5b 節色彩規格（綠/黃/橘）
- [ ] 「未排定」tag hover 顯示 tooltip 說明文字
- [ ] 方案 header 健康度 badge hover tooltip 顯示「X 科已排 / Y 科未排定」
- [ ] 統計列「N 個方案排課不完整」篩選功能正常，無結果時顯示綠色勾 + 說明文字
- [ ] 月結方案（billing_mode = date）的建立 modal 不顯示排課時段欄位
- [ ] 非方案課程 UI 與修改前完全一致（無視覺回歸）

---

## 11. 上線與維運

### 部署步驟

1. 後端無 migration，直接部署 Laravel
2. `cd frontend && npm run deploy`，確認 `index.html` 與 `assets/` 同輪同步
3. 驗證：建立一個含時段的方案，確認各科 ClassSession 自動生成
4. 驗證：管理頁顯示健康度 badge 正確
5. 執行 `php artisan packages:sync-session-counts --dry-run` 確認現有歷史方案不受影響

### 監控

建立方案含排課時段的 API 呼叫建議在 `Log::info` 中記錄補排堂數，供後續排查。

### 回滾方案

後端：`git revert <commit>` 後重新部署；已建立的 StudentClass 排課欄位與 ClassSession 需手動比對（低風險，新欄位僅為新增，不修改現有資料）。前端：`git revert` 後 `npm run deploy`。

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 Agent |
|--------|---------|---------|-----------|
| P0 | FR-001/002 建立 modal 新增時段欄位 + 後端寫入 StudentClass 排課欄位 | 1h | `[FEATURE]` |
| P0 | FR-003 建立後呼叫排課延伸（extendSessionsIfNeeded） | 0.5h | `[FEATURE]` |
| P0 | FR-004 建立成功後排課摘要 dialog | 0.5h | `[FEATURE]` |
| P1 | FR-005 成員格排課狀態 tag + 最近 3 堂 | 1h | `[FEATURE]` |
| P1 | FR-006/007 健康度 badge + 統計篩選列 | 1h | `[FEATURE]` |
| P1 | UI/UX 精緻化（5b 節） | 1h | UI/UX Designer |
| P2 | Feature Test 補充 | 1h | `[TEST]` |

---

## 13. 風險、假設、開放問題

### 13a. 風險登錄

#### RISK-001 — 排課延伸呼叫與現有 `createMultiSubject` 的 `confirmed_dates` 路徑衝突 ★★☆ 中

| 項目 | 內容 |
|------|------|
| **可能性** | 中（createMultiSubject 若傳入 `confirmed_dates` 會預建 attended ClassSession 並執行 ledger 扣堂；之後再呼叫 extendSessionsIfNeeded 可能把這些 attended 堂算進 currentCount 而少補） |
| **業界參照** | **Append-Only + currentCount 精確計算**：extendSessionsIfNeeded 已有「排除 cancelled/leave/leave_adjusted 後計算 currentCount」的邏輯；attended 堂也算入 currentCount，因此補排數 = `SessionCount - currentCount`，行為精確 |
| **具體緩解** | `[REVIEW]` 確認 `extendSessionsIfNeeded` 的 `currentCount` 計算包含 attended 狀態；建立後呼叫的補排數應等於 `total_sessions - confirmed_dates.length` |
| **殘留風險** | 低 |

#### RISK-002 — StudentClass week1~week6 欄位對應複數時段的寫入邏輯 ★☆☆ 低

| 項目 | 內容 |
|------|------|
| **可能性** | 低（欄位已存在；只是建立時沒有寫） |
| **業界參照** | **Positional mapping + 最大限制**：slot[0] → week1/time1，最多 6 個；第 7 個起截斷並 log warning（與系統現有 CourseEditForm 最多 7 段的前端限制相容） |
| **具體緩解** | 後端寫入時 assert `day_time_slots.length <= 6`；前端限制每科最多 6 個時段 |
| **殘留風險** | 極低 |

#### RISK-003 — listPackages N+1 查詢（健康度計算） ★☆☆ 低

| 項目 | 內容 |
|------|------|
| **可能性** | 低（方案數量預計 < 100） |
| **業界參照** | **Eager Loading + Sub-select 聚合**：在現有 `with(['members'])` 上再 eager load `members.classSessions` count；或使用 `selectRaw('COUNT(ClassSession.id) as scheduled_count')` 一次 JOIN 取得 |
| **具體緩解** | 在 `CoursePackage::index` 的 members 查詢中加入 `withCount('validClassSessions')`（或等效 sub-select），不允許 N+1 |
| **殘留風險** | 極低 |

### 13b. 假設

**假設 A — StudentClass 的 week/time/week1~week6/time1~time6 欄位在現有系統中是 `resolveScheduleSlotsForRebuild` 的唯一讀取來源**
- 驗證：`resolveScheduleSlotsForRebuild` 已確認讀取這些欄位；寫入後立即可被排課延伸使用

**假設 B — 主任建立方案時最多同時設定 6 個上課時段（如週一/三/五各兩段）**
- 依據：CourseEditForm 前端限制最多 7 段；StudentClass 表最多有 week1~week6（6 欄）；後端取最小值 6
- 若需求超過：升級路徑為新增 `day_time_slots JSON` 欄位（migration），本次不做

**假設 C — 健康度計算以「有效 ClassSession 數 ≥ SessionCount」為「已排齊」標準**
- 依據：與現有 `sessionCountWarning` 的 `effectiveSessionCount` 口徑一致（排除 cancelled/leave/leave_adjusted）

---

## 14. AI 執行前已確認的程式細節（Gap Analysis 結果）

以下是 2026-04-20 Pre-flight 讀碼後確認的關鍵細節，AI 執行時**必須遵守**：

### GAP-01 ★ CRITICAL — $weekSlots 轉換 bug

`CoursePackageController::createMultiSubject` 現有的死碼（行 262）：
```php
$weekSlots[] = ['weekday' => ((int) $dow + 6) % 7, 'time' => $startTime];
```
**此轉換輸出 0–6，但 `resolveScheduleSlotsForRebuild` 使用 ISO 1–7（1=Mon, 7=Sun）**。

- `days_of_week: [1]` (Monday) → 舊碼輸出 `0` → 排課系統找不到匹配 → 補排失敗
- `days_of_week: [7]` (Sunday) → 舊碼輸出 `6` → 對應到週六而非週日

**修正：刪除此轉換，直接將 days_of_week 的 1–7 原始值寫入 StudentClass.week / week1~weekN 欄位。**

### GAP-02 — `next_sessions` 欄位需後端提供

`frontend-member-chip-status` 需要「最近 3 堂日期」，由 `backend-members-scheduled-count` 在 members map 內一次批次查詢提供（格式：`next_sessions: ['2026-05-05', '2026-05-12', '2026-05-19']`）。前端使用 `member.next_sessions ?? []`。

### GAP-03 — Summary dialog 的狀態 refs

`frontend-create-modal-schedule` 需新增：
- `const showSummaryModal = ref(false)` 
- `const createSummaryData = ref([])` （存放後端 `members_scheduled` 陣列）
- submitCreate 成功後：關閉建立 modal → 填入 createSummaryData → 開啟 summary dialog

### GAP-04 — extendSessionsIfNeeded 在 transaction 內呼叫

確認：update 路徑在 `DB::transaction` **內部**呼叫 `extendSessionsIfNeeded`。`createMultiSubject` 也應在 transaction 內呼叫，並在 `StudentClass::create` 後使用 `$sc->fresh()` 取得含 ID 的完整 model 物件再傳入。

### GAP-05 — members 是原始查詢，非 Eloquent relation

`index()` 的 members 用 `StudentClass::where('PackageID', $pkg->id)->select([...])->get()` 取出，**非** Eloquent `with('members')`。`backend-members-scheduled-count` 必須在 `$members->map(...)` 外先批次預查，再在 map 內 lookup，避免 N+1。

### GAP-06 — dayOptions 規格確認

前端 chip 值使用 `1–7`（1=Monday…7=Sunday），與 `StudentsList.vue` 的 `dayOptions` 完全一致。API payload 傳入 1–7 原始值，後端直接寫入 StudentClass 欄位，無需轉換。

---

## 15. Definition of Done

- [ ] 所有 FR（FR-001 ～ FR-008）通過 QA 驗收
- [ ] `PackageCreateWithScheduleTest` 全部通過（含 GAP-01 weekday 值斷言）
- [ ] `SessionCountWarningTest|PackageDisplayAndGuardTest|CoursePackageTest|PackageTotalSessionsSyncTest` 回歸測試全部通過
- [ ] UI/UX 驗收清單（第 10 節）逐項以程式碼確認（AI 自行 sign-off）
- [ ] 資安 STRIDE 快評確認無阻擋風險（`security-review-schedule` todo 完成）
- [ ] `[REVIEW]` 確認排課延伸邏輯對照 AI_REGRESSION_LESSONS.md 無 regression（`code-review-schedule` todo 完成）
- [ ] ReadLints 所有修改過的 .php 與 .vue 檔案零 linter 錯誤
- [ ] `npm run deploy` 執行成功，index.html 與 assets 同輪同步，API health 正常
- [ ] `docs/CHANGELOG.md` 已更新
