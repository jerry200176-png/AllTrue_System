# Phase 2 — Student Presence Window Attendance
## PRD v1.1 | 狀態：Draft | 2026-04-23

---

## §1 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | Student Presence-Window Attendance |
| 版本 | v1.1 |
| 狀態 | Draft |
| 作者 | PM Agent |
| 目標角色 | PM → ARCH → DEV → TEST → SEC → REVIEW → DOCS → OPS |
| 前置計畫 | Phase 1 Teacher Attendance Integration（PR #10 已 merge main） |

---

## §2 目標與業務背景

### 業務背景

本系統服務的是**一對一補習班**，學生刷卡行為有以下特性：
- 到班刷一次、離班刷一次，**中途（含上廁所、換課）不刷卡**
- 一天有多堂課時，全部上完才刷退，不在課與課之間刷卡
- **忘記刷退是常態**（學生和老師都有此問題）
- 現有老師手動點名功能是「忘刷」的 fallback，目標是學生有刷卡就不需要老師手動補

### 痛點

1. **多堂課記錄遺失**：學生一天有 A（10:00-12:00）和 B（12:00-14:00）兩堂課，10:00 刷進、14:00 刷退 → 系統只關閉 A 課 SignOutDT，B 課完全沒有出勤記錄、沒有扣堂。
2. **自修無語意標記**：學生在沒有課的時間進教室，系統建立的 `StudentSignIn` 記錄 `Memo = 'swipe-rfid'`，無法與正常上課記錄區分。
3. **家長資訊不透明**：家長目前無法即時得知孩子的到離時間與課程對應情況。

### 業務價值

- 多堂課場景出勤準確率：0% → 100%
- 自修紀錄可被管理端識別與報表使用
- 家長信任度提升（透明化）
- 老師不需手動補登漏掉的課堂

### 可量化 KPI

| 指標 | 目標 |
|---|---|
| 同日多堂課場景：所有課程均有 `StudentSignIn` 記錄 | 100% |
| 自修刷卡記錄標記 `self_study` | 100% |
| 刷卡 API 回應時間（p99） | 維持 < 500ms，不因新邏輯退化 |
| Presence Window 補建幂等：重複觸發不重複扣堂 | 100% |

---

## §3 範圍

### In Scope

| # | 功能 | 優先 |
|---|---|---|
| FR-001 | **Self-study 標記**：`findMatchingClass` 回傳 null 時，`Memo = 'self_study'`，不扣堂 | P0 |
| FR-002 | **Presence Window at Sign-out（刷退回溯）**：刷退時掃描整個在場時段（`SignInDT` ～ `SignOutDT`），對所有尚未有 `StudentSignIn` 紀錄的課堂補建記錄並扣堂 | P0 |
| FR-003 | **家長 Telegram 推播**：刷進/刷退時推播給 `TelegramID`/`TelegramID1`/`TelegramID2`，含課程名稱、時間 | P1 |

### Out of Scope（本版不做）

- **Smart Swipe（課間刷卡偵測）**：一對一補習班學生不在課間刷卡，此功能不適用（已決定移除）
- 家長入口網頁查詢歷史記錄（已有 `ParentPortal.vue`，排入 P2）
- Laravel Scheduler 即時掃描方案（排入 P1，本版只做刷退回溯）
- `week/time` 排課模式支援（無 ClassSession 記錄的課不補建，已決定）
- 老師視角查看學生到離紀錄
- 補建紀錄的前端管理介面

---

## §4 RACI

| 角色 | 職責 |
|---|---|
| PM（AI） | 撰寫並維護本 PRD |
| Tech Lead（AI） | ARCH 技術設計文件 |
| DEV（AI） | 後端實作（`SwipeRfidController`） |
| TEST（AI） | Pest Feature Test + 幂等邊界測試 |
| SEC（AI） | RFID 端點資安審查 |
| REVIEW（AI） | Code Review（逐條 FR） |
| DOCS（AI） | CHANGELOG 更新 |
| OPS（AI） | 部署與 health check |
| **使用者（CEO）** | 每 Phase 批准 |

---

## §4b Dependencies

| 項目 | 狀態 |
|---|---|
| Phase 1 PR #10 squash merge 至 main | ✅ 完成 |
| `ClassSession` 有 `StartTime` / `EndTime` 欄位（`time` 型別） | ✅ 確認（migration 2026_02_07） |
| `SessionDeductionService::deductOnAttendance` 幂等（同 `class_session_id` 不重複扣） | ✅ 確認（ledger 機制） |
| `Campus.TelegramToken` 用於 Telegram Bot 發送 | ✅ 現有欄位 |
| 無需 DB schema 異動 | ✅ 確認 |
| 無需前端 Vue 異動 | ✅ 確認 |

---

## §5 User Stories

### US-01：多堂課自動完整記錄（核心場景）

**As a** 家長，  
**I want** 我孩子到班刷一次、離班刷一次，系統自動記錄當天所有課堂的出勤，  
**so that** 我能看到完整上課紀錄，不需要孩子在課與課之間額外刷卡。

**Acceptance Criteria：**
- GIVEN 學生 10:00 刷進，有 A 課（10:00-12:00）和 B 課（12:00-14:00）
- WHEN 學生全程不刷卡，14:00 刷退（**唯一一次刷退**）
- THEN DB 有兩筆 `StudentSignIn`：
  - A 課：`SignInDT = 10:00`（原始刷進），`SignOutDT = A課 EndTime（12:00）`，`SessionDeducted = true`
  - B 課：`SignInDT = B課 StartTime（12:00）`，`SignOutDT = 14:00`（刷退時間），`SessionDeducted = true`
- AND 老師不需要手動點任何課

### US-02：忘記刷退時老師補點不衝突

**As a** 老師，  
**I want** 學生忘記刷退時我手動點名，之後若學生補刷退，系統不會重複扣堂，  
**so that** 手動點名和刷卡兩種方式可以共存不衝突。

**Acceptance Criteria：**
- GIVEN 學生 10:00 刷進（A 課），B 課老師已手動點名（`StudentSignIn` 已建立）
- WHEN 學生事後補刷退（Presence Window 執行）
- THEN B 課已有 active `StudentSignIn` → 不重複建立、不重複扣堂
- AND 原有老師手動點名記錄保持不變

### US-03：自修標記

**As a** 主任，  
**I want** 學生在無課時間刷卡時記錄標記為「自修」，  
**so that** 管理報表能清楚區分出勤類型，不與課堂出勤混淆。

**Acceptance Criteria：**
- GIVEN 學生在今日無任何課堂的時間刷卡
- THEN 建立 `StudentSignIn`，`Memo = 'self_study'`，`StudentClassID = null`，`SessionDeducted = false`

### US-04：家長即時推播（P1）

**As a** 家長，  
**I want** 孩子刷進或刷退時收到 Telegram 通知，  
**so that** 我能即時掌握孩子到離時間，不需打電話詢問。

**Acceptance Criteria：**
- GIVEN `TelegramID1` 或 `TelegramID2` 不為空
- WHEN 學生刷進（`sign_in`）或刷退（`sign_out`）
- THEN 推播含：學生姓名、動作（到校/離校）、時間、課程名稱（若有）

---

## §5b UI/UX 精緻化

**本版無任何前端 Vue 頁面異動。**

API response 結構（`sign_in` / `sign_out`）維持不變，無 Breaking Change。

→ **UX Phase（Phase 2b）可跳過。**

---

## §6 功能需求 FR

| 編號 | 描述 | 優先 | 涉及位置 |
|---|---|---|---|
| FR-001 | **自修標記**：`findMatchingClass` 回傳 null 時，`Memo = 'self_study'`（原為 `'swipe-rfid'`），`SessionDeducted = false` | P0 | `handleStudentSwipe` |
| FR-002 | **Presence Window at Sign-out**：刷退時，掃描 `SignInDT`～`SignOutDT` 整段在場時間內所有屬於該學生的 `ClassSession`；對尚無 active `StudentSignIn` 的 ClassSession 補建記錄（`SignInDT = session.StartTime`，`SignOutDT = session.EndTime`）並呼叫 `deductOnAttendance` | P0 | `handleStudentSwipe`，新增 `backfillPresenceWindow()` |
| FR-003 | **家長 Telegram 推播**（P1）：`sign_in` / `sign_out` 後發送 Telegram 訊息給 `TelegramID`/`TelegramID1`/`TelegramID2`，含學生姓名、時間、課程名稱 | P1 | `handleStudentSwipe` |

---

## §7 非功能需求 NFR

| 編號 | 描述 |
|---|---|
| NFR-001 | 刷卡 API p99 < 500ms：`backfillPresenceWindow` 在同一 DB transaction 內執行，不發額外 HTTP call（Telegram 推播在 transaction 之後非同步發送） |
| NFR-002 | 補建幂等保護：`backfillPresenceWindow` 補建前先 check 同一 `ClassSessionID` 是否已有 active `StudentSignIn`（含老師手動建立的記錄）；`deductOnAttendance` 本身已幂等（ledger 層保護） |
| NFR-003 | 多校區隔離：所有新 query 必須帶 `CampusID` 條件 |
| NFR-004 | 不破壞老師刷卡邏輯：`handleTeacherSwipe` 完全不動 |
| NFR-005 | 不破壞老師手動點名：`backfillPresenceWindow` 偵測到已有 `StudentSignIn`（不論來源）→ 跳過，不重複建立 |

---

## §8 技術方向

### 異動檔案

| 檔案 | 異動說明 |
|---|---|
| `backend/app/Http/Controllers/SwipeRfidController.php` | 修改 `handleStudentSwipe()`；新增 private `backfillPresenceWindow()` |
| `backend/app/Services/SessionDeductionService.php` | **唯讀，不修改**（幂等邏輯已存在） |

### 無 DB schema 異動 → DBA Phase 可跳過

### 無前端異動 → UX Phase 可跳過

### API 合約（無 Breaking Change）

```
POST /api/v1/swipe-rfid
  action: 'sign_in'   → 不變
  action: 'sign_out'  → 不變（sign_out 時 Presence Window 在後端靜默補建，不影響 response 結構）

Breaking Change: 無
```

### 核心邏輯設計概要（ARCH 詳述）

```
handleStudentSwipe 新流程（一對一補習班版）：

1. 查 openRecord（今日 SignOutDT = null，取最新一筆）

2. 若有 openRecord（刷退流程）：
   → close openRecord（SignOutDT = swipeAt）
   → backfillPresenceWindow(student, openRecord.SignInDT, swipeAt, campusId)
   → return action = 'sign_out'

3. 若無 openRecord（刷進流程）：
   a. findMatchingClass() 原有邏輯（不動）
   b. 若找到課堂 → sign_in + deductOnAttendance（原有邏輯，不動）
   c. 若找不到課堂 → sign_in with Memo = 'self_study'，不扣堂（FR-001 新增）

backfillPresenceWindow(student, signInDT, signOutDT, campusId)：
  → 查 ClassSession：
      SessionDate = today
      AND StartTime >= TIME(signInDT)
      AND StartTime <= TIME(signOutDT)
      AND StudentClassID IN (該學生當日有效的 ClassSession)
  → 排除：已有 active StudentSignIn（含老師手動建立）的 ClassSessionID
  → 排除：就是觸發刷進那筆 openRecord 對應的 ClassSession（已有記錄）
  → 對每個缺失的 ClassSession 補建：
      StudentSignIn（
        SignInDT    = session.StartTime（當日日期 + StartTime）
        SignOutDT   = session.EndTime
        SessionDeducted = false（由 deductOnAttendance 設定）
        Memo        = 'presence-window'
        CampusID    = campusId
      ）
      deductOnAttendance(studentClass, newSignIn)
```

---

## §8b Decision Log

| 日期 | 問題 | 選項 | 選擇 | 理由 |
|---|---|---|---|---|
| 2026-04-23 | 多堂課補建時機 | A：刷退時回溯 / B：Scheduler 每分鐘掃 | **A（刷退回溯）** | 無需新基礎設施，現有架構完全支援；B 排 P1 |
| 2026-04-23 | Smart Swipe 是否實作 | 實作 / 不實作 | **不實作（移除）** | 一對一補習班學生不在課間刷卡，功能不適用此場景 |
| 2026-04-23 | Presence Window 排課模式支援 | 只支援 ClassSession / 同時支援 week/time 模板 | **只支援 ClassSession** | week/time 逆推當日課堂邏輯複雜且易出錯；業界標準是出勤掛在離散 session 上 |
| 2026-04-23 | Presence Window 補建 SignInDT | ClassSession.StartTime / 學生實際刷進時間 | **ClassSession.StartTime** | 業界標準：紀錄對應到實際課堂起始，便於報表與扣堂計算 |
| 2026-04-23 | Telegram 推播時機 | transaction 內同步 / transaction 後非同步 | **transaction 後** | 避免網路失敗導致 DB rollback，刷卡體驗不應依賴外部服務 |

---

## §9 資安與存取控制

| 面向 | 分析 |
|---|---|
| 端點認證 | `POST /api/v1/swipe-rfid` 無使用者認證（現有設計），以 `Campus.Token` Bearer 驗證校區歸屬，本版不改動 |
| PII 最小化 | `backfillPresenceWindow` 補建的記錄欄位與現有 `sign_in` 一致，不新增 PII 欄位 |
| 多校區隔離 | 所有新 query 帶 `CampusID`，補建紀錄帶 `CampusID = $campus->id` |
| STRIDE 快評 | Spoofing / Tampering / DoS 風險與現有端點相同，本版邏輯改動不新增威脅面 |
| Telegram 推播 | Token 存於 `Campus.TelegramToken`（現有），不在 response 中暴露 |

---

## §10 QA 驗收

### Happy Path

| 場景 | 預期結果 |
|---|---|
| 10:00 刷進（A 課），14:00 刷退，B 課 12:00-14:00 存在 | A 課 SignOutDT = 14:00；Presence Window 補建 B 課（SignInDT=12:00, SignOutDT=14:00）；兩筆均 `SessionDeducted = true` |
| 10:00 刷進（A、B、C 三堂課），16:00 刷退 | A 課已有記錄；Presence Window 補建 B 課 + C 課；三筆均 `SessionDeducted = true` |
| 無課時間刷進 | `Memo='self_study'`，`StudentClassID=null`，`SessionDeducted=false` |
| 有課時間刷進 | 原有邏輯不變：`Memo='swipe-rfid'`，`SessionDeducted=true` |

### Edge Cases

| 場景 | 預期結果 |
|---|---|
| 今日只有一堂課，14:00 刷退 | 正常 sign_out，A 課 SignOutDT=14:00；Presence Window 掃不到其他課，不補建 |
| 老師已手動點 B 課，學生之後補刷退 | Presence Window 發現 B 課已有 active `StudentSignIn` → 跳過，不重複建立、不重複扣堂（NFR-002、NFR-005） |
| Presence Window 幂等：學生刷退兩次（誤觸） | 第二次刷退時 B 課已有記錄 → 跳過 |
| `ClassSession` 不屬於該學生（其他學生的課） | 不補建（query 以 StudentClassID 過濾） |
| 刷進時對應的 ClassSession 已有 SignIn 紀錄 → Presence Window 不應重複補建 | 排除 openRecord 對應的 ClassSessionID，跳過 |

### Error Cases

| 場景 | 預期結果 |
|---|---|
| Telegram 推播失敗（網路斷線） | 不影響刷卡主流程，catch exception 記錄 `Log::error`，response 仍 200 |
| `backfillPresenceWindow` 補建時部分 session 扣堂失敗 | 繼續處理其餘 session，記錄 `Log::warning`，不 rollback 整個 transaction |

### Regression

| 場景 | 預期結果 |
|---|---|
| 老師刷卡 | `handleTeacherSwipe` 行為完全不變，debounce 邏輯保留 |
| 學生單次刷進（無 openRecord，有課） | 原有 sign_in + deductOnAttendance 行為不變 |
| 學生單次刷進（無 openRecord，無課） | `Memo='self_study'`，不扣堂（FR-001 新增） |

---

## §11 上線與維運

| 項目 | 說明 |
|---|---|
| **Migration** | 無，不需執行 |
| **前端部署** | 無前端異動，不需 `npm run deploy` |
| **Route Cache** | 無新增路由，不需 `route:cache` |
| **Feature Flag** | 無，邏輯改善直接上線 |
| **回滾方案** | `git revert <commit>`，無 DB 破壞性操作，可立即回滾 |
| **Smoke Test** | 刷卡端點 `POST /api/v1/swipe-rfid` 正常回傳 200 |
| **Observability** | `backfillPresenceWindow` 補建時寫 `Log::info('presence_window_backfill', [...])` |

---

## §12 里程碑與優先級

| 優先 | 功能 | 執行 Agent | 備註 |
|---|---|---|---|
| P0 | FR-001 Self-study 標記 | [DEV] | 最小改動，Memo 欄位值修改 |
| P0 | FR-002 Presence Window | [DEV] | 新增 `backfillPresenceWindow()`，核心邏輯 |
| P1 | FR-003 Telegram 推播 | [DEV] | 可選，不影響核心功能 |

---

## §13 風險 / 假設 / 開放問題

### 假設

- `ClassSession.StartTime` 和 `EndTime` 均為非 null（`time` 型別，migration 已確認）
- 同一學生同日同一 `ClassSessionID` 最多一筆 active `StudentSignIn`（幂等靠 ClassSessionID 保護）
- 學生一天最多課堂數 ≤ 10（Raspberry Pi 效能考量，Presence Window query 量可控）
- 學生只在到班/離班刷卡，不在課間刷卡（一對一補習班特性）

### 風險

| 風險 | 可能性 | 影響 | 緩解 |
|---|---|---|---|
| Presence Window 遺漏「week/time 排課」模式的課（無 ClassSession） | 中 | 中（回溯不完整） | 已決定：只支援 ClassSession 模式；促使管理端補齊排程資料 |
| 學生刷進時對應 A 課，刷退時 Presence Window 重複補建 A 課 | 低 | 高（重複扣堂） | 補建前排除 openRecord 對應的 ClassSessionID |
| Telegram 推播超時 | 低 | 高（刷卡卡頓） | Transaction 後非同步發送 |

### 開放問題

無（Q1、Q2 均已在 §8b Decision Log 決定）

---

## §14 Definition of Done

所有條件均為 AI 可自動驗證的客觀指標：

- [ ] **FR-001**：無課時刷進 → DB `Memo = 'self_study'`，`StudentClassID IS NULL`，`SessionDeducted = 0`
- [ ] **FR-002a**：10:00 刷進（A 課）+ 14:00 刷退（B 課 12:00-14:00）→ DB 有兩筆 `StudentSignIn`，B 課 `Memo = 'presence-window'`，`SessionDeducted = 1`
- [ ] **FR-002b**：Presence Window 幂等：老師已手動建立 B 課 `StudentSignIn` → 刷退時不重複建立、不重複扣堂
- [ ] **FR-002c**：Presence Window 幂等：學生刷退後再次刷退 → B 課記錄不重複
- [ ] **NFR-004**：老師刷卡回歸：`handleTeacherSwipe` 測試 PASS（debounce 保留）
- [ ] **API**：`sign_in` / `sign_out` response 格式不變，無 Breaking Change
- [ ] **CI**：GitHub Actions PHPUnit / Pest 全部 green

---

*PRD 版本歷史*

| 版本 | 日期 | 說明 |
|---|---|---|
| v1.0 | 2026-04-23 | 初版，含 Smart Swipe、Presence Window、Self-study、Telegram 推播（P1） |
| v1.1 | 2026-04-23 | 移除 Smart Swipe（一對一補習班學生不在課間刷卡，功能不適用）；確定 Presence Window 只支援 ClassSession 模式；新增 US-02（老師手動點名 + 刷卡共存）；更新所有受影響章節 |
