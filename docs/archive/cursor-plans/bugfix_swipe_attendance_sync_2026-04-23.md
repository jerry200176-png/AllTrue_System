# Bug Fix Plan — 學生刷卡點名未同步 ClassSession 狀態

**檔案 slug**：`bugfix_swipe_attendance_sync_2026-04-23`
**Branch**：`fix/swipe-classsession-sync`

---

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 邏輯錯誤（流程不完整）+ 欄位缺失（null TeacherID） |
| 根因摘要 | `SwipeRfidController::handleStudent()` 建立 `StudentSingIn` 後未呼叫任何 ClassSession 狀態更新邏輯；且當匹配的 `StudentClass.TeacherID = NULL` 時，寫入的 `StudentSingIn.TeacherID = NULL`，導致老師查詢時被過濾掉 |
| 錯誤行為（Bug A）| 學生刷卡 → `StudentSingIn` 建立成功，但 `ClassSession.Status` 仍為 `'scheduled'` → 老師在「今日待點名」清單仍看到該堂次；若再手動點名，duplicate guard 靜默失敗，造成混亂 |
| 錯誤行為（Bug B）| `StudentSingIn.TeacherID = NULL`（因 `StudentClass.TeacherID` 為 NULL）→ 老師呼叫 `GET /api/v1/attendance?date=today` 時，後端 `WHERE si.TeacherID = auth_teacher_id` 過濾掉此記錄 → 記錄從老師視角消失 |
| 預期行為 | 學生刷卡成功後：(1) `ClassSession.Status` 更新為 `attended`/`late`；(2) `StudentSingIn.TeacherID` 確保非 NULL（使用 ClassSession → StudentClass → TeacherID 作 fallback）|
| 影響範圍 | 所有刷卡自行點名的學生；影響 teacher role 的 `GET /api/v1/attendance` 與前端 AttendancePage 的「今日出缺勤紀錄」及「今日待點名」計數 |
| B1 偵查來源 | 本計畫整合 B1 內容（DB 直查 + 程式碼追蹤：SwipeRfidController L213-231, AttendanceController L58-63, L770-781） |

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 學生刷卡點名 ↔ ClassSession 狀態同步 |
| 版本 | v1.0 |
| 建立日期 | 2026-04-23 |
| 狀態 | 待執行 |
| 嚴重度 | P1（老師看不到學生刷卡記錄 + 待點名計數錯誤） |
| 目標角色 | teacher（受害者：看到錯誤 UI）、student（觸發者：刷卡） |
| 關聯 Bug | DB 記錄 `StudentSingIn.id = 696`（TeacherID=NULL, Memo='swipe-rfid'）；所有 swipe-rfid 記錄的 ClassSession.Status 未更新問題 |

---

## 2. 業務背景與影響

### 痛點描述
- 老師開啟 AttendancePage，看到「今日待點名 1 堂」，但學生其實已自行刷卡
- 老師點擊手動點名 → 因 duplicate guard（`StudentSingIn.ClassSessionID` 已存在）靜默失敗，老師誤以為系統異常
- 部分學生刷卡記錄（TeacherID=NULL）完全消失於老師視角，但 director 可見

### 修復後預期行為
1. 學生刷卡且比對到 ClassSession → ClassSession.Status 立即更新（attended / late）
2. 老師刷新 AttendancePage → 「今日待點名」移除已刷卡堂次；「今日出缺勤紀錄」出現刷卡記錄
3. StudentSingIn.TeacherID 永遠不為 NULL（有匹配課程時）

---

## 3. 範圍

### In Scope
- `SwipeRfidController::handleStudent()` — 新增 ClassSession 狀態同步邏輯
- `SwipeRfidController::backfillPresenceWindow()` — 同步補建的 StudentSingIn 也需更新 ClassSession.Status
- 新增共用 Service 方法（或 static utility）封裝 ClassSession 狀態解析邏輯
- 當 `$studentClass->TeacherID === null` 且有 `$classSessionId` 時，回退查詢 `StudentClass.TeacherID`

### Out of Scope
- `AttendanceController`（手動點名流程）不動
- `TeacherController`、`ProfileController` 不動
- 前端 AttendancePage.vue、TeacherHomePage.vue 不動（本 bug 純後端修復即可解決）
- Migration（無新欄位）
- TeacherSignIn / TeacherAttendance 相關邏輯不動
- RFID 硬體、`POST /api/v1/swipe-rfid` 路由 signature 不動

---

## 4. RACI

| 任務 | R（執行） | A（負責） | C（諮詢） | I（知會） |
|---|---|---|---|---|
| 後端修復 | AI Agent | AI Agent | — | 使用者 |
| 測試撰寫 | AI Agent | AI Agent | — | 使用者 |
| Code Review | AI Agent | AI Agent | — | 使用者 |
| 部署 | AI Agent | AI Agent | — | 使用者 |

---

## 4b. Dependencies

- **無前置 PR**：本修復獨立，不依賴其他 open PR
- **無 migration**：不新增/修改 DB schema
- **依賴**：`ClassSession` Model 已有 `Status` 欄位（已確認）；`StudentSignIn` 已有 `TeacherID` 欄位（已確認）

---

## 5. Acceptance Criteria

### AC-001：刷卡成功且比對到 ClassSession，ClassSession.Status 更新
- AC-001-a：學生刷卡（swipe_at 在 StartTime-30min ～ EndTime 窗口內），`POST /api/v1/swipe-rfid` 回傳 HTTP 201 後，`ClassSession.Status` 由 `'scheduled'` 更新為 `'attended'`（swipe_at < StartTime + threshold）或 `'late'`（swipe_at > StartTime + threshold）
- AC-001-b：刷卡前 `ClassSession.Status = 'scheduled'`，刷卡後查詢 DB 確認已改變

### AC-002：ClassSession 狀態更新為 late（遲到）
- AC-002-a：swipe_at 超過 SessionDate+StartTime + 15分鐘，`ClassSession.Status = 'late'`

### AC-003：ClassSession 已非 scheduled（attended/late/absent）時不覆寫
- AC-003-a：若 ClassSession.Status 已為 `'attended'`（例如老師已手動點名），刷卡後 ClassSession.Status 維持 `'attended'`，不被覆寫

### AC-004：StudentSingIn.TeacherID 不為 NULL（有匹配課程時）
- AC-004-a：刷卡比對到 `StudentClass.TeacherID != null` 的課程 → `StudentSingIn.TeacherID = StudentClass.TeacherID`
- AC-004-b：極端情境：`StudentClass.TeacherID = null` 但有 `classSessionId` → 透過 ClassSession 查回 StudentClass 再取 TeacherID；若仍為 null → 接受 null（並記 Log）

### AC-005：無匹配課程（self_study）時行為不變
- AC-005-a：刷卡無匹配 ClassSession、無匹配 StudentClass → `Memo = 'self_study'`，TeacherID = null，行為與修復前一致，ClassSession 無任何更新

### AC-006：老師查詢 API 可見刷卡記錄
- AC-006-a：刷卡建立 `StudentSingIn.TeacherID = T` 後，role=teacher (teacher_id=T) 呼叫 `GET /api/v1/attendance?date=today` → 該記錄出現在回傳 JSON

---

## 5b. UI/UX 規格

**不適用**：本次修復為純後端邏輯修復，前端程式碼不需更動。前端已有正確的資料驅動顯示邏輯，後端修復後即可自動呈現正確狀態。

---

## 6. 功能需求 FR

| # | 描述 |
|---|---|
| FR-001 | 修復後，`SwipeRfidController::handleStudent()` 在建立 `StudentSingIn` 且 `$classSessionId != null` 時，必須在同一 DB transaction 內更新對應 `ClassSession.Status` |
| FR-002 | ClassSession 狀態解析規則：swipe_at ≤ (SessionDate+StartTime + 15分鐘) → `'attended'`；swipe_at > (SessionDate+StartTime + 15分鐘) → `'late'` |
| FR-003 | ClassSession.Status 僅在當前值為 `'scheduled'` 時才允許更新；已有 `'attended'`/`'late'`/`'absent'`/`'leave'` 等值時不覆寫 |
| FR-004 | `backfillPresenceWindow()` 補建的 `StudentSingIn` 記錄對應的 `ClassSession.Status` 同步更新（依 FR-002 規則） |
| FR-005 | `SwipeRfidController::handleStudent()` 在 `$studentClass` 非 null 但 `$studentClass->TeacherID === null` 時，透過 `ClassSession::find($classSessionId)->studentClass->TeacherID` 嘗試回退取得 TeacherID |
| FR-006 | TeacherID 解析失敗（回退後仍 null）時，記錄 `Log::warning('[swipe] TeacherID resolved to null', [...])` |

---

## 7. 非功能需求 NFR

**不適用，說明理由**：本次修復為純邏輯 bug fix，在既有 DB transaction 內新增一個 `UPDATE` 語句（`ClassSession.Status`），效能影響可忽略（< 1ms）。無新增 N+1 查詢。

---

## 8. 技術方向

### 涉及檔案與方法

| 檔案 | 方法 | 改動說明 |
|---|---|---|
| `app/Http/Controllers/SwipeRfidController.php` | `handleStudent()` | 在 `StudentSingIn::create()` 的 transaction 內，於 `$classSessionId != null` 時新增 ClassSession 狀態更新邏輯（引用或 inline 狀態解析） |
| `app/Http/Controllers/SwipeRfidController.php` | `backfillPresenceWindow()` | 補建 StudentSingIn 時同步更新各 ClassSession.Status |
| `app/Services/AttendanceEffectsService.php`（新建） | `applySwipeStatus(ClassSession, Carbon)` | 從 AttendanceController 提取狀態解析邏輯（resolveSwipeStatus + applyAttendanceEffects）成 shared static service，供 SwipeRfidController 呼叫 |

### 架構取捨

**選擇：新建 `AttendanceEffectsService`**（而非直接 duplicate 邏輯進 SwipeRfidController）
- 理由：`resolveSwipeStatus` 和 `applyAttendanceEffects` 目前是 `AttendanceController` 的 private method，直接複製會造成 DRY 違反，未來維護需同步兩處
- 替代方案（直接 inline）：僅適用於臨時修復，但後續 Phase 3 Teacher Attendance 相關開發也會需要相同邏輯
- 決定：提取到獨立 Service，讓 Controller 都可呼叫

---

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-23 | 新建 `AttendanceEffectsService` 封裝狀態更新邏輯 | 在 SwipeRfidController 複製 AttendanceController 的 private method | 避免 DRY 違反；為未來多入口（教師刷卡、家長確認）預留擴充點 |
| 2026-04-23 | ClassSession.Status 僅在 `scheduled` 時才更新（guard condition） | 無條件覆寫 | 防止刷卡覆蓋老師已手動設定的 `absent`/`leave` 狀態，保護已有決策 |
| 2026-04-23 | 不覆寫 ClassSession.Status = attended/late（老師手動點名優先） | 刷卡優先 | 業界慣例：自動出席記錄不得覆蓋人工核准決策 |
| 2026-04-23 | TeacherID null 時做 fallback 查詢，仍失敗則 Log + 接受 null | 拒絕建立記錄 | 不應因邊緣資料問題阻斷正常刷卡流程；Log 保留可追蹤性 |

---

## 9. 資安與存取控制

**觸發條件滿足**（涉及 RFID 刷卡公開端點）：

- `POST /api/v1/swipe-rfid` 為公開端點（無 Bearer token），RFID card 偽造風險已在現有 SwipeRfidController 的 RFID 查表機制處理，本次修復不改動驗證邏輯
- ClassSession.Status 更新僅在「已比對到合法 ClassSession（屬於該 student 的 StudentClass）」後才執行，不存在越權修改他人 ClassSession 的風險
- TeacherID fallback 查詢使用 `ClassSession::find($classSessionId)->studentClass->TeacherID`，classSessionId 來源為 `findMatchingClass` 的 verified result，不接受外部輸入
- **STRIDE 結論**：Tampering 風險低（ClassSession 更新有 guard condition）；Elevation of Privilege 無（公開端點行為不變）

---

## 10. QA 驗收

### Happy Path
- [ ] 學生刷卡在 StartTime-30min～StartTime+15min 之間 → ClassSession.Status = `attended`
- [ ] 學生刷卡在 StartTime+15min～EndTime 之間 → ClassSession.Status = `late`
- [ ] 刷卡後 `GET /api/v1/attendance?date=today`（teacher role）回傳包含該記錄

### Edge Cases
- [ ] ClassSession.Status 已為 `attended` 時刷卡 → Status 維持 `attended`（不覆寫）
- [ ] `StudentClass.TeacherID = null` 但有 classSessionId → fallback 查詢後正確填入 TeacherID
- [ ] `$classSessionId = null`（僅 StudentClass 時序比對）→ 不更新任何 ClassSession
- [ ] 無匹配課程（self_study）→ ClassSession 不更新；Memo = 'self_study'

### Error Paths
- [ ] fallback TeacherID 查詢也回傳 null → Log::warning 記錄；StudentSingIn 仍建立（TeacherID=null）；HTTP 201 正常回傳
- [ ] ClassSession 更新在 transaction 中拋例外 → rollback StudentSingIn；回傳 500

### Revert-proof 驗證
- [ ] `git stash` 後重跑測試，AC-001、AC-004 對應 test case 至少各 1 failure（確認測試真正覆蓋了 bug，而非誤綠）

---

## 11. 上線與維運

### 部署步驟
1. Phase 2 [DEV]：`git checkout -b fix/swipe-classsession-sync`
2. 新建 `AttendanceEffectsService.php`（包含 test case）→ push → CI RED 確認
3. 修改 `SwipeRfidController`（handleStudent + backfillPresenceWindow）→ push → CI GREEN
4. Phase 7 [OPS]：PR merge → `git pull` → health check

### Migration
**無**：不新增/修改任何 DB schema

### Observability
- 新增 `Log::info('[swipe] classsession_status_updated', ['session_id'=>..., 'old'=>..., 'new'=>...])` 於每次狀態更新成功時
- 新增 `Log::warning('[swipe] TeacherID resolved to null', ...)` 於 fallback 仍失敗時

### 回滾方案
- `git revert <commit>` → push fix branch → PR → CI → merge
- 估計回滾時間：< 15 分鐘
- 現有 StudentSingIn 記錄不受影響（只有新刷卡才走新邏輯）

---

## 12. 優先級

| 面向 | 值 |
|---|---|
| 優先級 | P1（影響老師日常點名工作流程，但非資料遺失） |
| 執行 Agent | [DEV] → [TEST] → [REVIEW] (SEC) → [DOCS] → [OPS] |

---

## 13. 風險 / 假設 / 開放問題

> ⚠️ 本節依規則先查業界解法（WebSearch 已執行，來源：EDURFID academic paper 2025、vmedulife ERP blog、MiHCM best practices、IJSRED V8I1P146）

### 業界共識
- **Atomic update**：業界 RFID 出席系統（EDURFID、vmedulife）均在單一 transaction 內同步更新 AttendanceRecord 與 SessionStatus，確保一致性
- **Guard condition**：不覆寫人工審核決策（attended → 不被刷卡覆寫）是標準做法
- **Traceability**：記錄 registration method（RFID vs manual）於 AttendanceRecord（本系統 Memo 欄位已符合）

### 假設
- `ClassSession.Status` 欄位有 DB-level constraint（varchar），新狀態值 `attended`/`late` 已在現有允許值範圍內（已確認：AttendanceController 已使用這些值）
- Teacher.id = User.id（已確認：DB 查詢驗證 10 筆 teacher 皆吻合）

### 開放問題
1. **backfillPresenceWindow 的 late 判斷**：補建的 StudentSingIn SignInDT 是 session 的 StartTime，不是實際刷卡時間，因此 backfill 的 ClassSession 應統一設為 `'attended'`（非 late）。待確認是否符合業務需求。
2. **未來：是否需要 Telegram 通知老師「學生已自行刷卡」**？本次不做，登記 TECH_DEBT。

---

## 14. Definition of Done

- [ ] **FR-001** (ClassSession 狀態更新)：驗證方式：`mysql -e "SELECT Status FROM ClassSession WHERE id = <test_id>"` 回傳 `attended` 或 `late`（視 swipe_at 而定）
- [ ] **FR-002** (狀態解析規則)：驗證方式：新增 PHPUnit test `testSwipeStatusResolution`，`php artisan test --filter=testSwipeStatusResolution` 全部 pass
- [ ] **FR-003** (guard condition)：驗證方式：PHPUnit test `testSwipeDoesNotOverwriteAttended`，pass
- [ ] **FR-004** (backfill 同步)：驗證方式：PHPUnit test `testBackfillUpdatesClassSessionStatus`，pass
- [ ] **FR-005** (TeacherID fallback)：驗證方式：PHPUnit test `testSwipeTeacherIdFallback`，pass
- [ ] **Revert-proof**：`git stash && php artisan test --filter=testSwipeClassSessionSync` 至少 1 failure
- [ ] **CI 全綠**：`gh run view <run_id>` 顯示所有 job `completed / success`
- [ ] **CHANGELOG**：`git diff docs/CHANGELOG.md` 含 `2026-04-23` 新條目
- [ ] **Health check**：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `{"status":"ok",...}` HTTP 200
- [ ] **無 regression**：`php artisan test`（完整測試套件）在 CI 中全部 pass

---

## Todos（執行清單）

| 類別 | 任務 | Agent |
|---|---|---|
| [DEV] | 新建 `app/Services/AttendanceEffectsService.php`，提取 resolveSwipeStatus + applyAttendanceEffects 邏輯 | AI |
| [DEV] | 修改 `SwipeRfidController::handleStudent()`：transaction 內更新 ClassSession.Status + TeacherID fallback | AI |
| [DEV] | 修改 `SwipeRfidController::backfillPresenceWindow()`：補建 StudentSingIn 時同步更新 ClassSession.Status | AI |
| [TEST] | 新增 PHPUnit tests：testSwipeClassSessionSync（含 5 個 case 對應 AC-001~005） | AI |
| [TEST] | Revert-proof 驗證：git stash 後確認測試 fail | AI |
| [REVIEW] | SEC：確認公開端點無越權風險（STRIDE checklist） | AI |
| [REVIEW] | 逐條對照 FR-001~006 | AI |
| [DOCS] | 更新 docs/CHANGELOG.md | AI |
| [DOCS] | 更新 docs/AI_REGRESSION_LESSONS.md（如有新教訓） | AI |
| [OPS] | PR merge → health check | AI |
