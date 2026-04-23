# PRD：出缺勤管理改善
## 功能一：老師月度出缺勤匯出 ｜ 功能二：今日出缺勤紀錄修正（刪除 / 狀態編輯 / 自修轉換）

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 出缺勤管理改善（月度匯出 + 今日記錄修正） |
| 版本 | v1.1 |
| 狀態 | Draft |
| 建立日期 | 2026-04-23 |
| 目標角色 | 主任（director）、super_admin（功能一）；主任、老師（功能二） |
| 相關頁面 | `AttendancePage.vue` → 老師打卡 Tab（功能一）；學生出缺勤 Tab 的「今日出缺勤紀錄」（功能二） |

---

## 2. 目標與業務背景

### 痛點（非技術語言）

**功能一 — 老師月度匯出**

主任目前若要了解整個月每位老師的出缺勤狀況，只能一天一天翻查打卡記錄，沒有一鍵匯出整月完整報表的功能。現有的「匯出 CSV」按鈕只能匯出**單日**打卡流水帳，欄位也未含遲到分鐘數或缺席統計，無法直接用於薪資計算或行政報告。

**功能二 — 今日出缺勤紀錄修正**

今日出缺勤紀錄表格有三個明顯缺陷：

1. **無法刪除**：測試產生的假記錄或誤刷記錄，目前沒有任何刪除入口，只能直接進 DB 清除。
2. **自修記錄（`Memo='self_study'`）無法修改狀態**：程式碼 `v-else` bug 導致自修記錄意外顯示編輯下拉選單，但點儲存時因缺少 `ClassSessionID` 而跳出 `alert('此記錄缺少堂次關聯，無法修改狀態')`，完全無法操作。
3. **無科目選擇**：將自修轉換為「到班」時，系統不知道要扣哪一門課的堂數，缺少課程選擇步驟。

### 業務價值

| 功能 | 改善前 | 改善後 |
|---|---|---|
| 月度匯出 | 主任人工整理估計 1-2 小時/月 | < 5 分鐘一鍵下載 XLSX |
| 刪除錯誤紀錄 | 需請工程師直接改 DB | 主任/admin 自助刪除，操作 < 30 秒 |
| 自修轉到班 | 無法操作 | 選課程 → 自動扣堂，操作 < 60 秒 |

### 可量化 KPI

| KPI | 目標 |
|---|---|
| 月報匯出觸發到下載完成 | < 3 秒（百人以下分校） |
| 老師月度出勤資料準確率 | 100%（與 DB 記錄一致） |
| 格式相容性 | Excel 2016+、LibreOffice 7+ 可正常開啟 |
| 主任操作月報步驟數 | ≤ 3 步（選月份 → 點匯出 → 下載） |
| 刪除紀錄操作步驟數 | ≤ 3 步（點刪除 → 填原因 → 確認） |
| 自修轉到班操作步驟數 | ≤ 4 步（點轉換 → 選課程 → 確認 → 完成） |

---

## 3. 範圍

### In Scope

**功能一：老師月度出缺勤匯出**

- 主任選取年月（YYYY-MM），一鍵匯出該月所有老師出缺勤月報
- 匯出格式：**XLSX**（兩個工作表：月報摘要 + 明細流水帳）
- 月報摘要：交叉表格（老師 × 日期，格值 = 出勤狀態碼）+ 右側統計欄（出勤天、遲到天、請假天、缺席天）
- 明細流水帳：每筆打卡記錄一列（含簽到/簽退時間、遲到分鐘、補卡資訊）
- 前端：老師打卡 Tab 新增「匯出月報」按鈕，含月份選擇器
- 分校隔離：主任只能匯出自己分校的老師資料
- 狀態對應：`present`=出勤(●)、`late`=遲到(△)、`leave`=請假(○)、`absent`=缺席(✕)、`adjusted`=補卡(◇)、無記錄=空白
- 遲到分鐘計算：`SignInDT` vs 當日首堂課 `start_time`（若無課表則 vs 分校設定上班時間）

**功能二：今日出缺勤紀錄修正**

- **刪除記錄**：director/super_admin 可軟刪除（void）任何 `StudentSingIn` 記錄；需填寫刪除原因；若該記錄已扣堂（`SessionDeducted=true`），自動沖回堂數；若有連結 `ClassSession`，自動將其狀態退回 `scheduled`
- **自修記錄修正**：修正 `v-else` bug，自修記錄不再意外顯示一般狀態編輯 UI；改為獨立「轉換」按鈕
- **自修轉到班**：提供「轉換為到班」操作，使用者選擇目標 `StudentClass`（課程合約），系統建立對應 `ClassSession` 並執行堂數扣除
- **無 ClassSessionID 記錄的狀態編輯**：允許直接修改 `StudentSingIn.Status`（僅更新記錄本身，不觸發堂數邏輯），限 director/super_admin

### Out of Scope

- 學生出缺勤月度匯出（另立計劃）
- 自動寄送 Email 月報（Phase 2）
- PDF 格式（Phase 2）
- 老師自查月報（本次僅主任/super_admin）
- 年度彙整報告
- 勞動部 API 自動申報
- 批次刪除多筆記錄（Phase 2）
- 自修轉到班後的學習評量補建（Phase 2）

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[FEATURE]` Agent | R/A |
| AI Agent（測試） | `[TEST]` Agent | R/A |
| AI Agent（審查） | `[REVIEW]` Agent | R/A |
| AI Agent（文件） | `[DOCS]` Agent | R/A |
| AI Agent（部署） | `[OPS]` Agent | R/A |
| 人類（可選閱讀） | 主任 / 管理員 | I |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 後端套件 | `maatwebsite/excel` 已安裝（`InvoicesExport.php` 已使用） | 已存在 |
| 資料表（功能一） | `TeacherSingIn`、`Teacher`、`User`、`UserCampus`、`schedules`、`teacher_signin_adjustments` | 已存在 |
| 資料表（功能二） | `StudentSingIn`（含 `VoidedAt`、`VoidedByUserID`、`VoidReason` 欄位已存在）、`ClassSession`、`StudentClass` | 已存在 |
| 軟刪除基礎設施 | `StudentSignIn::scopeActive()`、`ClassSessionController::voidAttendanceArtifacts()` 已存在 | 已存在 |
| 堂數沖回服務 | `SessionDeductionService::reverseForSession()` 已存在 | 已存在 |
| 路由 | 功能一：新增 `GET /api/v1/teacher-attendance/export-monthly`；功能二：新增 `DELETE /api/v1/attendance/{id}`、`POST /api/v1/attendance/{id}/convert-to-attended` | 待建立 |
| 前端 | `AttendancePage.vue`（兩個 Tab 均需修改） | 待修改 |
| 環境 | 無需新 env 變數 | N/A |

> 本次**無外部第三方 API 依賴**。

---

## 5. User Stories

### 功能一：老師月度匯出

#### US-01：主任匯出整月老師出缺勤月報

> As a 主任，  
> I want to 選擇年月後一鍵下載該月所有老師出缺勤的 XLSX 月報，  
> so that 我可以直接作為薪資計算附件，不需要人工整理。

**AC**：
- AC-01a：主任在老師打卡 Tab 可見「匯出月報」按鈕與月份選擇器（預設當月）
- AC-01b：點擊「匯出月報」後，3 秒內瀏覽器觸發 XLSX 下載，檔名格式 `teacher-attendance-2026-04.xlsx`
- AC-01c：XLSX 第一工作表「月報摘要」：第一行=日期（1-31），第一欄=老師姓名，格值=狀態碼
- AC-01d：XLSX 第二工作表「明細記錄」：每行=一筆打卡，含老師名、日期、簽到、簽退、狀態、遲到分鐘、補卡原因
- AC-01e：選擇非當月未來月份時，系統回傳空表（非錯誤）

#### US-02：分校資料隔離

> As a 主任，  
> I want to 只看到並匯出我自己分校的老師資料，  
> so that 不同分校資料不互相洩漏。

**AC**：
- AC-02a：主任匯出的 XLSX 僅包含其 `CampusID` 下的老師
- AC-02b：super_admin 可匯出所有分校（無分校過濾）
- AC-02c：老師角色嘗試呼叫此 API 時，回傳 HTTP 403

#### US-03：遲到分鐘正確計算

> As a 主任，  
> I want to 在明細記錄中看到每位老師當天的遲到分鐘數，  
> so that 我可以客觀評估出勤紀律。

**AC**：
- AC-03a：有課表時：遲到分鐘 = `SignInDT` - 當日最早 `schedules.start_time`（正值=遲到，負值=提早=顯示 0）
- AC-03b：無課表時：遲到分鐘欄顯示「-」（不適用）
- AC-03c：狀態為 `adjusted` 時：使用 `teacher_signin_adjustments` 最新一筆的 `new_signin_dt` 計算

---

### 功能二：今日出缺勤紀錄修正

#### US-04：主任刪除錯誤出缺勤紀錄

> As a 主任，  
> I want to 刪除今日出缺勤紀錄中的測試或誤登資料，  
> so that 不需要麻煩工程師直接操作資料庫，且堂數能同步沖回。

**AC**：
- AC-04a：今日出缺勤紀錄每一行有「刪除」按鈕（icon），director/super_admin 才看得到
- AC-04b：點擊「刪除」後彈出確認 dialog，顯示該筆記錄資訊（學生姓名、時間、狀態）並要求填寫刪除原因（必填，最少 2 字）
- AC-04c：確認後：系統設定 `VoidedAt=now()`、`VoidedByUserID=當前用戶ID`、`VoidReason=輸入原因`
- AC-04d：若該記錄 `SessionDeducted=true`，系統自動沖回一堂（呼叫 `SessionDeductionService::reverseForSession`）
- AC-04e：若該記錄有 `ClassSessionID`，系統自動將對應 `ClassSession.Status` 退回 `scheduled`
- AC-04f：刪除成功後，該筆記錄從列表消失（因 `whereNull('VoidedAt')` 過濾），右上角顯示成功 toast

#### US-05：主任修改無課表關聯的出缺勤狀態

> As a 主任，  
> I want to 直接修改沒有堂次關聯的出缺勤記錄的狀態，  
> so that 不再看到無法操作的錯誤提示。

**AC**：
- AC-05a：無 `ClassSessionID` 的記錄（非 self_study），「修改」按鈕正常顯示
- AC-05b：選擇新狀態後儲存，直接更新 `StudentSingIn.Status`（不觸發堂數扣除邏輯）
- AC-05c：儲存成功後，列表即時更新狀態標籤，右上角顯示成功 toast

#### US-06：主任將自修記錄轉換為到班並指定科目

> As a 主任，  
> I want to 將自修刷卡記錄轉換為正式到班並指定要扣的課程，  
> so that 自修的學生能正確計入出勤並扣除對應堂數。

**AC**：
- AC-06a：自修記錄（`Memo='self_study'`）不顯示一般「修改」編輯 UI（修正 v-else bug）
- AC-06b：自修記錄顯示「轉換為到班」按鈕（director/super_admin 才看得到）
- AC-06c：點擊後彈出 Modal，列出該學生目前進行中的 `StudentClass` 課程合約清單（科目名稱 + 老師 + 剩餘堂數）
- AC-06d：選擇目標課程後確認，系統：①建立或更新對應日期的 `ClassSession`（Status=`attended`）②更新 `StudentSingIn.ClassSessionID`、`Memo=null`，設定 `SessionDeducted=true` ③呼叫 `SessionDeductionService` 扣除一堂
- AC-06e：操作完成後，記錄狀態從「自修」變為「到班」，顯示對應科目名稱，toast 確認

---

## 5b. UI/UX 精緻化需求

### 功能一：老師打卡 Tab — 月報匯出

| 面向 | 規格 |
|---|---|
| 版面層次 | 「匯出月報」按鈕置於現有「今日完整打卡記錄」卡片右上角，與現有「匯出 CSV（單日）」按鈕並排，兩按鈕間距 8px |
| 按鈕樣式 | `class="ghost small"`，左側 `material-symbols-outlined` 圖示 `calendar_month`（18px），文字「月報」 |
| 月份選擇器 | `<input type="month">` 元素，樣式沿用 `.att-date-input`，寬度 140px，預設值 = 當月（YYYY-MM 格式） |
| 互動回饋 | 點擊後按鈕顯示 loading spinner（`disabled` 狀態），下載完成或失敗後恢復；失敗時右上角 toast `匯出失敗，請稍後再試`（3 秒消失） |
| 空狀態設計 | 該月無任何打卡記錄時，仍觸發下載（空 XLSX，含表頭），不彈錯誤 |
| 防呆設計 | 若選擇未來月份，仍允許匯出（空表），不阻擋 |
| 響應式 | 行動裝置（< 768px）：月份選擇器與兩個匯出按鈕不溢出，可換行或收合 |
| 無障礙 | 月份選擇器有 `aria-label="選擇月份"`；按鈕有 `title="匯出老師月度出缺勤 XLSX"`；對比度 ≥ 4.5:1 |

### 功能二：今日出缺勤紀錄 — 操作欄改造

| 面向 | 規格 |
|---|---|
| 版面層次 | 操作欄分為三個入口（由左至右）：「修改」按鈕（一般記錄）/ 「轉換」按鈕（自修記錄）/ 「刪除」icon 按鈕（所有記錄）；自修記錄隱藏「修改」 |
| 刪除按鈕 | `class="ghost xs danger-icon"`，使用 `material-symbols-outlined` 的 `delete` 圖示（16px），文字 label 隱藏，僅 icon；僅 director/super_admin 可見 |
| 刪除 Dialog | 使用既有 `<dialog>` 或 Vue modal 模式；內容：「確定刪除此記錄？」+ 記錄摘要（學生名、時間、狀態）+ `<textarea>` 填寫刪除原因（placeholder: `請說明刪除原因，例如：測試資料`，必填 ≥ 2 字）+ 「確認刪除」（危險紅色）/ 「取消」按鈕 |
| 刪除確認按鈕 | 填寫原因前：disabled 灰色；填寫後：啟用，點擊後 loading，防止重複點擊 |
| 轉換 Modal（自修→到班） | 標題「將自修轉換為到班」；課程清單每行顯示：科目 + 老師 + 剩餘堂數；剩餘堂數 = 0 的課程：disabled + 灰色 + 標示「堂數已滿」；選中後「確認轉換」啟用 |
| 空狀態設計 | 學生無進行中課程時，Modal 顯示圖示 + 「此學生目前無進行中的課程合約」說明，不顯示確認按鈕 |
| Toast 規格 | 成功：綠色 toast，右上角，2 秒消失；失敗：紅色 toast，3 秒消失 |
| 互動回饋 | 所有非同步操作（刪除、轉換儲存）期間，操作按鈕 disabled + spinner；完成後立即刷新今日記錄列表 |
| 響應式 | Dialog/Modal 在手機上全螢幕覆蓋；觸控目標 ≥ 44px |
| 無障礙 | Dialog 有 `role="dialog"` + `aria-modal="true"`；focus trap；ESC 關閉；顏色對比度 ≥ 4.5:1 |

---

## 6. 功能需求（FR）

### 功能一：老師月度匯出

| # | 需求 | 優先級 |
|---|---|---|
| FR-001 | 系統應提供 `GET /api/v1/teacher-attendance/export-monthly?year_month=YYYY-MM` 端點，回傳 XLSX 串流 | P0 |
| FR-002 | 端點需驗證 `year_month` 格式（YYYY-MM），無效格式回傳 HTTP 422 | P0 |
| FR-003 | 回傳的 XLSX 含兩個工作表：「月報摘要」（交叉表）與「明細記錄」（流水帳） | P0 |
| FR-004 | 月報摘要第一欄=老師姓名，後續欄=該月 1 日至最後一日（動態，不含未來日期），最後四欄=出勤天/遲到天/請假天/缺席天統計 | P0 |
| FR-005 | 狀態碼映射：`present`→`●`、`late`→`△`、`leave`→`○`、`absent`→`✕`、`adjusted`→`◇`、無記錄→空白 | P0 |
| FR-006 | 明細記錄含欄位：老師ID、老師姓名、分校ID、日期、簽到時間、簽退時間、狀態、遲到分鐘、補卡原因 | P0 |
| FR-007 | 主任（director）僅能匯出自身 `CampusID` 列表下的老師；super_admin 無限制 | P0 |
| FR-008 | 老師角色（teacher）呼叫此端點回傳 HTTP 403 | P0 |
| FR-009 | XLSX 使用 `maatwebsite/excel` 產出，格式為 `.xlsx`（OOXML），中文字符正確顯示 | P0 |
| FR-010 | 前端月份選擇器預設值為當月，可任意選取歷史月份 | P1 |
| FR-011 | 前端匯出期間按鈕呈 disabled+loading，避免重複下載 | P1 |
| FR-012 | 月報摘要第一行「日期」欄位格式為「1日」「2日」…「31日」（中文），超出該月天數的欄位不輸出 | P1 |
| FR-013 | 遲到分鐘計算：有課表時取 `schedules` 首堂 `start_time`；`adjusted` 狀態取補卡調整後時間 | P1 |
| FR-014 | XLSX 月報摘要工作表對遲到（`△`）格加黃底色、缺席（`✕`）格加橙底色，便於視覺識別 | P2 |
| FR-015 | 檔名格式：`teacher-attendance-{YYYY-MM}.xlsx` | P1 |

### 功能二：今日出缺勤紀錄修正

| # | 需求 | 優先級 |
|---|---|---|
| FR-016 | 系統應提供 `DELETE /api/v1/attendance/{id}` 端點，執行軟刪除（Void）；限 director/super_admin | P0 |
| FR-017 | 刪除端點接受 `void_reason`（string，必填，min:2，max:500）；無原因回傳 HTTP 422 | P0 |
| FR-018 | 刪除端點應設定 `VoidedAt=now()`、`VoidedByUserID=當前用戶ID`、`VoidReason=輸入值` | P0 |
| FR-019 | 若被刪記錄 `SessionDeducted=true`，刪除端點應自動呼叫 `SessionDeductionService::reverseForSession` 沖回堂數 | P0 |
| FR-020 | 若被刪記錄有 `ClassSessionID`，刪除端點應將對應 `ClassSession.Status` 從 `attended/late/absent` 退回 `scheduled`（若當前狀態確為出勤類） | P0 |
| FR-021 | 刪除端點需分校隔離：director 只能刪除自身 CampusID 的記錄；super_admin 無限制 | P0 |
| FR-022 | 前端今日出缺勤紀錄操作欄：director/super_admin 看到「刪除」icon 按鈕；teacher 角色不顯示 | P0 |
| FR-023 | 前端點擊「刪除」彈出確認 Dialog，顯示記錄摘要 + 必填原因 textarea，填寫後「確認刪除」按鈕才啟用 | P0 |
| FR-024 | 自修記錄（`Memo='self_study'`）不顯示一般「修改」狀態下拉（修正現有 v-else bug） | P0 |
| FR-025 | director/super_admin 看到自修記錄旁有「轉換為到班」按鈕 | P1 |
| FR-026 | 系統應提供 `POST /api/v1/attendance/{id}/convert-to-attended` 端點，接受 `student_class_id`；限 director/super_admin | P1 |
| FR-027 | 轉換端點：①建立當日 `ClassSession`（Status=`attended`）②更新 `StudentSingIn` 的 `ClassSessionID`、清空 `Memo`、`SessionDeducted=true`  ③呼叫 `SessionDeductionService` 扣除一堂 | P1 |
| FR-028 | 轉換 Modal 中的課程清單：列出該學生所有進行中且 `RemainingSessions > 0` 的 `StudentClass`，顯示科目 + 老師 + 剩餘堂數 | P1 |
| FR-029 | 無 `ClassSessionID` 且非 self_study 的記錄（手動登記），允許直接修改 `StudentSingIn.Status`（不觸發堂數邏輯）；限 director/super_admin | P2 |

---

## 7. 非功能需求（NFR）

| 面向 | 指標 | 降級策略 |
|---|---|---|
| 效能（功能一） | 百人分校、30 天資料 → 端點回應 < 3 秒 | 超過 5 秒改為非同步產檔 + 下載 URL（Phase 2） |
| 資料量上限（功能一） | 單次匯出最多 500 筆老師 × 31 天（< 500KB） | 超出時回傳 HTTP 413 並提示拆分時間範圍 |
| 效能（功能二） | 刪除/轉換 API 回應 < 500ms | N/A（單筆操作，輕量） |
| 資料一致性（功能二） | 刪除操作必須在 DB transaction 內完成（Void + 堂數沖回 + ClassSession 更新） | 任何步驟失敗 → rollback，回傳 HTTP 500 + 錯誤訊息 |
| 並發（功能一） | 同時 10 主任觸發匯出，無 timeout | 依賴 PHP-FPM 並發限制 |
| 相容性（功能一） | Excel 2016+、LibreOffice 7+、Google Sheets 可正常開啟 | N/A |
| 安全 | Sanctum Bearer Token 驗證；分校隔離強制執行 | 未帶 Token → HTTP 401 |

---

## 8. 技術方向

### 功能一後端（Laravel）

- **新增 Export 類別**：`TeacherMonthlyAttendanceExport`，實作 `maatwebsite/excel` 的 `WithMultipleSheets` 介面，包含：
  - `TeacherMonthlySummarySheet`（交叉表：老師 × 日）
  - `TeacherMonthlyDetailSheet`（流水帳：每筆打卡一列）
- **新增 Controller 方法**：`TeacherAttendanceController::exportMonthly()`，驗證 `year_month` → 計算月份首末日 → 查詢 `TeacherSingIn` JOIN `Teacher`/`User`/`schedules`/`teacher_signin_adjustments` → 回傳 Excel 串流
- **新增路由**：`GET /api/v1/teacher-attendance/export-monthly`，掛 `role:director,super_admin` middleware
- **不修改**現有 `export` 端點（向後相容）

### 功能二後端（Laravel）

- **刪除端點**：`AttendanceController::destroy()` — 查找 `StudentSingIn`（已有 `whereNull('VoidedAt')` scope）→ 分校隔離檢查 → DB transaction：①設定三個 Void 欄位 ②若 `SessionDeducted=true` 呼叫 `SessionDeductionService::reverseForSession` ③若 `ClassSessionID` 存在且 `ClassSession.Status in [attended,late,absent]` 則退回 `scheduled`
- **轉換端點**：`AttendanceController::convertToAttended()` — 查找 `StudentSingIn`（必須是 self_study，`Memo='self_study'`） → 查找 `StudentClass`（`RemainingSessions > 0`）→ DB transaction：①建立 `ClassSession` ②更新 `StudentSingIn` ③呼叫 `SessionDeductionService` 扣堂
- **新增路由**：`DELETE /api/v1/attendance/{id}`、`POST /api/v1/attendance/{id}/convert-to-attended`，均掛 `role:director,super_admin` middleware

### 功能二前端（Vue 3）

- 修正 `v-if="!record._editing && record.Memo !== 'self_study'"` 的 v-else bug：改為三分支邏輯（一般記錄 / self_study 記錄 / editing 狀態）
- 新增 `deleteRecord(record)` 函式：彈出 dialog → 填原因 → 呼叫 `DELETE /api/v1/attendance/{id}` → 重整列表
- 新增 `openConvertModal(record)` 函式：呼叫 `GET /api/v1/student-classes?student_id=...&active=1` 取得課程清單 → 顯示 Modal → 呼叫轉換端點 → 重整列表
- 新增 `deleteDialog` ref（含 `visible`、`record`、`reason`、`loading` 狀態）
- 新增 `convertModal` ref（含 `visible`、`record`、`courses`、`selectedCourseId`、`loading` 狀態）

### 架構取捨

| 決策 | 替代方案 | 選擇理由 |
|---|---|---|
| 新增獨立端點 `/export-monthly` | 擴充現有 `/export` 端點 | 避免破壞現有 CSV 單日匯出行為；月報與流水帳格式差異大，分離更清晰 |
| XLSX 格式（maatwebsite/excel） | CSV + 手動組字串 | 已有依賴；XLSX 支援多工作表、格式顏色；CSV 無法表達交叉表 |
| 同步串流回傳 | 非同步產檔 + 下載 URL | 資料量在可接受範圍（< 3s），同步實作簡單，無需引入 Queue |
| Soft delete（VoidedAt）而非 Hard delete | 直接 `DELETE FROM StudentSingIn` | 業界標準（PowerSchool Audit Trail）：刪除記錄應可稽核；已有 VoidedAt 基礎設施；Hard delete 無法還原 |
| 刪除時自動沖回堂數 in Transaction | 手動沖回 | 確保資料一致性；`SessionDeductionService::reverseForSession` 已存在，可直接複用 |
| 轉換 Modal 中列出所有進行中課程 | 讓使用者輸入課程 ID | 避免輸入錯誤；UX 更清晰；剩餘堂數即時顯示可防呆 |

## 8b. Decision Log

| 日期 | 決策 | 考慮過的替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-23 | 使用 `maatwebsite/excel` WithMultipleSheets 產出兩個工作表 | 單一工作表 / 純 CSV | 月報需要摘要+明細，兩張表格式不同，多工作表是業界標準（OrangeHRM、edsembli 均採多 sheet 月報） |
| 2026-04-23 | 不修改現有 `/export` 端點 | 擴充現有端點加 `format=xlsx` 參數 | 單日 CSV 匯出是獨立使用場景，避免回歸風險 |
| 2026-04-23 | Soft delete + VoidReason | Hard delete | PowerSchool SIS 標準：出勤刪除必須留 audit trail，管理員方可操作（Delete Invalid Attendance 文件） |
| 2026-04-23 | 刪除 + 堂數沖回在同一 DB Transaction | 分開兩步操作 | 確保 ACID，避免「刪了但堂數沒沖回」的資料不一致 |
| 2026-04-23 | FR-029（無 ClassSessionID 直接改狀態）列為 P2 | P0 | 此場景較少見，且不涉及堂數；先解決 P0 刪除和自修轉換 |

---

## 9. 資安與存取控制

### Role 存取矩陣

| 角色 | `/export-monthly` | `DELETE /attendance/{id}` | `/attendance/{id}/convert-to-attended` |
|---|---|---|---|
| super_admin | 允許，無分校過濾 | 允許，無分校過濾 | 允許 |
| director | 允許，分校過濾 | 允許，分校過濾 | 允許，分校過濾 |
| teacher | HTTP 403 | HTTP 403 | HTTP 403 |
| parent / 未驗證 | HTTP 401 | HTTP 401 | HTTP 401 |

### PII 管控

- 月報含老師姓名、打卡時間 → 屬個資，分校隔離強制執行
- 刪除記錄保留 `VoidedByUserID` → 可稽核操作者身份
- `void_reason` 欄位為明文（不加密），儲存在 DB；不對外展示（除審計報告）

### STRIDE 快評（合併）

| 威脅 | 風險 | 緩解 |
|---|---|---|
| Spoofing | 偽造 Token 存取他校資料 | Sanctum 驗證 + `CampusID` whitelist 強制過濾 |
| Tampering | 竄改 `year_month`/`id` 參數 | Validation + DB 參數綁定防 SQL Injection |
| Repudiation | 否認刪除/匯出行為 | Laravel log `Log::info` 記錄每次操作；刪除保留 `VoidedByUserID` |
| Information Disclosure | 匯出/查看他校老師/學生資料 | CampusID whitelist 過濾（director 角色強制） |
| Denial of Service | 大量匯出或刪除請求 | 依賴現有 API throttle（`60/min`） |
| Elevation of Privilege | teacher/parent 呼叫 admin 端點 | `role:director,super_admin` middleware 攔截 |

---

## 10. QA 驗收

### 功能一 Happy Path

- [ ] 主任選擇 2026-04，點擊「匯出月報」→ 下載 `teacher-attendance-2026-04.xlsx`
- [ ] XLSX 第一工作表「月報摘要」：第一欄=老師姓名，後續欄=1日…30日（4月），最後四欄=統計
- [ ] XLSX 第二工作表「明細記錄」：每行=一筆打卡記錄，欄位完整
- [ ] 遲到（`late`）老師當天格值為 `△`，有課表時明細含正確遲到分鐘
- [ ] 補卡（`adjusted`）老師格值為 `◇`，明細含補卡原因

### 功能一 Edge Cases

- [ ] 選擇該月無任何老師打卡記錄 → 下載空 XLSX（含表頭），不報錯
- [ ] 選擇 2 月（28/29 天）→ 月報摘要不輸出 29/30/31 日欄位
- [ ] 主任屬於多個分校 → 匯出包含所有所屬分校的老師

### 功能一 Error Cases

- [ ] `year_month` 格式錯誤（如 `2026-4`）→ HTTP 422
- [ ] 未帶 Token → HTTP 401；老師角色呼叫 → HTTP 403

### 功能二 Happy Path

- [ ] 主任點擊「刪除」icon → 彈出 dialog，顯示記錄摘要，「確認刪除」disabled
- [ ] 填寫原因（≥ 2 字）→「確認刪除」啟用 → 點擊 → 記錄從列表消失 → toast「刪除成功」
- [ ] 被刪記錄 `SessionDeducted=true` → 刪除後對應 `StudentClass.RemainingSessions` +1
- [ ] 被刪記錄有 `ClassSessionID` → 刪除後對應 `ClassSession.Status` 退回 `scheduled`
- [ ] 自修記錄：不顯示「修改」下拉，顯示「轉換為到班」按鈕
- [ ] 點擊「轉換」→ Modal 列出該學生進行中課程（含剩餘堂數）→ 選擇課程 → 確認 → 記錄狀態變「到班」，科目欄顯示正確科目，toast 確認

### 功能二 Edge Cases

- [ ] 學生無進行中課程 → 轉換 Modal 顯示空狀態說明，無確認按鈕
- [ ] 學生所有課程 `RemainingSessions=0` → 課程清單全 disabled，提示「堂數已滿」
- [ ] 刪除時 `void_reason` 為空 → 「確認刪除」disabled，無法送出

### 功能二 Error Cases

- [ ] teacher 角色嘗試刪除 → HTTP 403
- [ ] director 嘗試刪除他校記錄 → HTTP 403
- [ ] `id` 不存在 → HTTP 404
- [ ] 已被刪除的記錄（`VoidedAt` 非 null）→ 前端 `whereNull` 過濾不顯示，無法二次刪除

### UI/UX 驗收清單（合併）

- [ ] 月份選擇器預設為當月，可選歷史月份
- [ ] 匯出月報期間按鈕 disabled+loading，完成後恢復
- [ ] 刪除 Dialog focus trap：ESC 可關閉，確認按鈕有 loading 狀態
- [ ] 轉換 Modal 有空狀態圖示 + 說明（無課程時）
- [ ] 所有非同步操作有 toast 回饋（成功綠色 2s，失敗紅色 3s）
- [ ] 危險操作（刪除）有二次確認 dialog，確認按鈕明確標示危險（紅色系）
- [ ] 行動裝置（< 768px）：Dialog/Modal 全螢幕，觸控目標 ≥ 44px
- [ ] 顏色對比度 ≥ 4.5:1；無障礙：aria-label、role、focus 管理正確

---

## 11. 上線與維運

### 部署步驟

1. **後端（功能一）**：
   - 新增 `app/Exports/TeacherMonthlyAttendanceExport.php`（含兩個 Sheet 子類別）
   - 修改 `TeacherAttendanceController.php`（新增 `exportMonthly` 方法）
   - `routes/api.php` 新增 `GET teacher-attendance/export-monthly`
   - 無 migration
2. **後端（功能二）**：
   - 修改 `AttendanceController.php`（新增 `destroy`、`convertToAttended` 方法）
   - `routes/api.php` 新增 `DELETE attendance/{id}`、`POST attendance/{id}/convert-to-attended`
   - 無 migration（`VoidedAt` 等欄位已存在）
3. **前端**：
   - 修改 `frontend/src/pages/AttendancePage.vue`
   - `cd frontend && npm run deploy`（build → copy-to-backend）
4. CI 全綠後 → `git push` → GitHub Actions 自動部署

### Feature Flag 策略

- **功能一**（月報匯出）：純新增按鈕，無 Flag，不影響現有功能
- **功能二**（刪除/轉換）：純新增按鈕，不影響既有流程；teacher 角色看不到新按鈕，低風險

### Observability

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| 月報匯出次數 | Laravel log：`[INFO] teacher-monthly-export` | N/A（觀察） | `[OPS]` |
| 出缺勤記錄刪除次數 | Laravel log：`[INFO] attendance-void` | N/A（觀察） | `[OPS]` |
| 自修轉到班次數 | Laravel log：`[INFO] attendance-convert` | N/A（觀察） | `[OPS]` |
| HTTP 5xx 錯誤 | Laravel log `ERROR` + stack trace | 任一 5xx → 告警 | `[OPS]` |
| 匯出 API 回應時間 | Nginx access log response_time | > 5s → 告警 | `[OPS]` |

### 回滾方案

- 後端：`git revert <commit>` → push → CI 自動部署（< 5 分鐘）
- 前端：`git revert <commit>` → `npm run deploy`（< 3 分鐘）
- **無需 migration rollback**（本次無新資料表）
- 回滾後軟刪除的記錄（`VoidedAt` 已設定）仍為 voided 狀態；如需還原個別記錄，須手動清空 `VoidedAt`（工程師操作，透過 tinker）

---

## 12. 里程碑與優先級

| 優先級 | 任務 | 執行 Agent |
|---|---|---|
| P0 | `[FEATURE]` 建立 `TeacherMonthlyAttendanceExport.php`（含兩 Sheet） | `[FEATURE]` |
| P0 | `[FEATURE]` `TeacherAttendanceController::exportMonthly()` 方法 + 路由 | `[FEATURE]` |
| P0 | `[FEATURE]` 前端老師打卡 Tab：月份選擇器 + 匯出月報按鈕 + `exportTeacherMonthly()` | `[FEATURE]` |
| P0 | `[FEATURE]` `AttendanceController::destroy()`（軟刪除 + 堂數沖回 + ClassSession 退回）+ 路由 | `[FEATURE]` |
| P0 | `[FEATURE]` 前端今日記錄：刪除 icon 按鈕 + 確認 Dialog（`deleteDialog` ref）+ `deleteRecord()` | `[FEATURE]` |
| P0 | `[FEATURE]` 修正自修記錄 v-else bug（隱藏一般修改 UI） | `[FEATURE]` |
| P1 | `[FEATURE]` 遲到分鐘計算邏輯（JOIN schedules） | `[FEATURE]` |
| P1 | `[FEATURE]` `AttendanceController::convertToAttended()` + 路由 | `[FEATURE]` |
| P1 | `[FEATURE]` 前端今日記錄：「轉換為到班」按鈕 + 課程選擇 Modal + `openConvertModal()` | `[FEATURE]` |
| P1 | `[TEST]` Pest Feature Test：功能一（匯出 XLSX、分校隔離、格式驗證） | `[TEST]` |
| P1 | `[TEST]` Pest Feature Test：功能二（刪除、堂數沖回、轉換、403/404 防護） | `[TEST]` |
| P1 | `[TEST]` 自動化 QA 驗收（執行第 10 節所有情境） | `[TEST]` |
| P2 | `[FEATURE]` FR-014：月報摘要條件格式（遲到黃底、缺席橙底） | `[FEATURE]` |
| P2 | `[FEATURE]` FR-029：無 ClassSessionID 記錄直接改狀態 | `[FEATURE]` |
| P2 | `[REVIEW]` 資安靜態審查（STRIDE 九個端點逐一確認） | `[REVIEW]` |
| P2 | `[REVIEW]` Code Review（逐條對照 FR-001 至 FR-029） | `[REVIEW]` |
| P2 | `[DOCS]` CHANGELOG 更新 | `[DOCS]` |
| P2 | `[OPS]` 部署後 curl health check + log 確認（新三個端點） | `[OPS]` |

---

## 13. 風險 / 假設 / 開放問題

### 風險（業界解法來源：WebSearch 2026-04-23）

| 風險 | 等級 | 業界標準解法（來源） | 本專案採行方式 |
|---|---|---|---|
| 大資料量匯出超時（> 5s） | 中 | 非同步產檔 + 下載 URL（OrangeHRM、edsembli 均採 Queue 非同步） | Phase 1 同步（< 百人），Phase 2 改 Queue；超時回 HTTP 504 + toast |
| Excel 中文字符亂碼 | 中 | UTF-8 BOM 或 charset=UTF-8（MSOfficeGeek 模板標準） | maatwebsite/excel XLSX 格式本身支援 Unicode，無 BOM 問題 |
| 交叉表欄位數動態（2月 vs 12月） | 低 | 動態計算月份天數（The Analytics Doctor） | 後端 `Carbon::daysInMonth()` 動態輸出欄位 |
| 遲到判斷標準不統一 | 中 | HR 系統以合約起始時間或班表時間為基準（OrangeHRM） | 有課表時取 `schedules.start_time`（最早堂）；無課表顯示「-」 |
| 刪除記錄無法稽核 | 高 | Soft delete + audit trail（PowerSchool SIS：AU_ATTENDANCE table；ChangeHistory 記錄 365 天） | Soft delete（VoidedAt/VoidedByUserID/VoidReason）+ Laravel log；符合業界標準 |
| 刪除後堂數沖回不一致（Transaction 失敗） | 高 | 使用 DB Transaction 保證 ACID（Classter 2026 強調 data consistency） | `DB::transaction()` 包含 Void + reverseForSession + ClassSession 更新；任何步驟失敗全部 rollback |
| 自修轉到班時課程選錯（使用者誤操作） | 中 | HR 系統通常有確認摘要 + 二次確認（OrangeHRM） | Modal 顯示課程資訊（科目、老師、剩餘堂數）+ 確認按鈕標示「轉換後無法直接復原」 |

### 假設

1. `maatwebsite/excel` 版本支援 `WithMultipleSheets`：**若不成立，AI Agent 自動降級為兩次 CSV 下載**
2. `schedules` 表有 `teacher_id`、`schedule_date`、`start_time`：**已確認存在**
3. `StudentSingIn` 的 `VoidedAt`、`VoidedByUserID`、`VoidReason` 欄位已存在：**已確認**（`StudentSignIn.php` model）
4. `SessionDeductionService::reverseForSession` 可接受「soft delete 後呼叫」的時序：**[AI-RESOLVABLE]** 讀 Service 程式碼確認參數

### 開放問題

| 問題 | 狀態 |
|---|---|
| 是否在月報中加「分校名稱」欄（主任多分校時）？ | **[AI-RESOLVABLE]** 加於老師姓名右側，明細亦含 campus_id（FR-006 已含） |
| 自修轉到班後是否需補建學習評量？ | **[BLOCKED: 待業務確認]** 暫列 Out of Scope（Phase 2） |
| 刪除後需不需要發 LINE 通知給相關老師或學生？ | **[BLOCKED: 待業務確認]** 暫不通知 |

---

## 14. Definition of Done

**功能一**

- [ ] **FR-001 端點存在**：`curl "GET /api/v1/teacher-attendance/export-monthly?year_month=2026-04"` → HTTP 200，Content-Type 含 `spreadsheetml`
- [ ] **FR-007 分校隔離**：Pest Test `it('director cannot see other campus teachers in export')` → pass
- [ ] **FR-008 老師角色 403**：Pest Test `it('teacher role returns 403 on export-monthly')` → pass
- [ ] **FR-002 格式驗證**：`curl ... ?year_month=bad` → HTTP 422
- [ ] **FR-003 兩工作表**：Pest Test 解析 XLSX 確認 sheet 數量 = 2
- [ ] **FR-015 檔名格式**：Response header `Content-Disposition` 含 `teacher-attendance-2026-04.xlsx`

**功能二**

- [ ] **FR-016 刪除端點**：`curl -X DELETE /api/v1/attendance/{id}` → HTTP 200，`VoidedAt` 已設定
- [ ] **FR-019 堂數沖回**：Pest Test 確認刪除 SessionDeducted=true 記錄後 `RemainingSessions` +1
- [ ] **FR-020 ClassSession 退回**：Pest Test 確認刪除後 ClassSession.Status = `scheduled`
- [ ] **FR-021 分校隔離**：Pest Test `it('director cannot void other campus attendance')` → pass
- [ ] **FR-024 v-else bug 修正**：`[REVIEW]` Agent 確認自修記錄不顯示一般修改 UI
- [ ] **FR-026 轉換端點**：`curl -X POST /api/v1/attendance/{id}/convert-to-attended` → HTTP 200，ClassSession 建立，SessionDeducted=true
- [ ] **FR-027 堂數扣除**：Pest Test 確認轉換後 `RemainingSessions` -1

**通用**

- [ ] **UI/UX 精緻化**：`[REVIEW]` Agent 逐條對照第 5b 節規格，無 ❌
- [ ] **STRIDE 審查**：`[REVIEW]` Agent 靜態分析，無 HIGH 風險
- [ ] **CHANGELOG**：`[DOCS]` Agent 確認 diff 含版本條目

---

## Todos（9 類）

### 後端 API / 資料

- [ ] `[FEATURE]` 建立 `app/Exports/TeacherMonthlyAttendanceExport.php`（WithMultipleSheets）
- [ ] `[FEATURE]` 建立 `app/Exports/TeacherMonthlySummarySheet.php`（交叉表）
- [ ] `[FEATURE]` 建立 `app/Exports/TeacherMonthlyDetailSheet.php`（流水帳）
- [ ] `[FEATURE]` `TeacherAttendanceController::exportMonthly()` 方法（含遲到分鐘計算）
- [ ] `[FEATURE]` `routes/api.php` 新增 `GET teacher-attendance/export-monthly`
- [ ] `[FEATURE]` `AttendanceController::destroy()`（Void + 堂數沖回 + ClassSession 退回，DB Transaction）
- [ ] `[FEATURE]` `AttendanceController::convertToAttended()`（建立 ClassSession + 扣堂）
- [ ] `[FEATURE]` `routes/api.php` 新增 `DELETE attendance/{id}`、`POST attendance/{id}/convert-to-attended`

### 前端 UI 功能

- [ ] `[FEATURE]` `AttendancePage.vue` 老師打卡 Tab：月份選擇器 ref + loading ref + `exportTeacherMonthly()` + 「匯出月報」按鈕
- [ ] `[FEATURE]` `AttendancePage.vue` 今日記錄：修正 v-else bug（三分支邏輯）
- [ ] `[FEATURE]` `AttendancePage.vue` 今日記錄：刪除 icon 按鈕 + `deleteDialog` ref + `deleteRecord()` 函式
- [ ] `[FEATURE]` `AttendancePage.vue` 今日記錄：「轉換為到班」按鈕 + `convertModal` ref + `openConvertModal()` 函式

### UI/UX 精緻化

- [ ] `[FEATURE]` 刪除確認 Dialog（危險按鈕樣式 + focus trap + ESC + loading）
- [ ] `[FEATURE]` 轉換 Modal（課程清單 + 剩餘堂數 + 空狀態設計 + loading）
- [ ] `[FEATURE]` 所有操作成功/失敗 toast（依第 5b 節規格）
- [ ] `[FEATURE]` 行動裝置響應式（Dialog/Modal 全螢幕，觸控目標 ≥ 44px）

### 測試與自動 QA

- [ ] `[TEST]` Pest：`export-monthly` happy path（HTTP 200 + XLSX content-type）
- [ ] `[TEST]` Pest：`export-monthly` 分校隔離、teacher 403、格式驗證 422、空月空 XLSX
- [ ] `[TEST]` Pest：`DELETE attendance/{id}` happy path（Void + 堂數沖回 + ClassSession 退回）
- [ ] `[TEST]` Pest：`DELETE attendance/{id}` 分校隔離 403、不存在 404、teacher 403
- [ ] `[TEST]` Pest：`convert-to-attended` happy path（ClassSession 建立 + 扣堂）
- [ ] `[TEST]` Pest：`convert-to-attended` 無剩餘堂數 422、非 self_study 記錄 422
- [ ] `[TEST]` 自動化 QA 驗收（執行第 10 節所有情境）

### 資安靜態審查

- [ ] `[REVIEW]` STRIDE 逐條確認（尤其三個新端點的 CampusID 隔離 SQL）

### Code Review

- [ ] `[REVIEW]` 逐條對照 FR-001 至 FR-029，每條標 ✓ / ✗

### 文件更新

- [ ] `[DOCS]` CHANGELOG.md 新增功能一、功能二版本條目

### 部署與 health check

- [ ] `[OPS]` CI 全綠後 push → GitHub Actions 部署
- [ ] `[OPS]` `curl` 確認三個新端點均可達（401 for unauthenticated）
- [ ] `[OPS]` 確認 Laravel log 無 5xx 錯誤
