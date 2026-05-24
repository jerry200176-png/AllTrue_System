> **[ARCHIVED 2026-05-24]** 此文件已移入 docs/archive/，僅供搜尋參考，不再維護。現行規格見 docs/INDEX.md。

# 分校學收月報 — CTO／產品／資安／UI·UX 規格

> **狀態**：Draft — 等待產品／CTO 定案後由工程實作。  
> **日期**：2026-04-13  
> **關聯**：`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`（繳費提醒規則）、`docs/CHANGELOG.md`（2026-04-13 (K) 催繳名單）

---

## 1. 背景與目標

**業務痛點**：主任想在系統內一眼看到「某分校、某月份、每位學生×科目的上課堂數與應收學費」，用以對帳、管理營收。  
目前系統有「催繳名單」（哪些課程即將到期或未繳）與「帳單列表」（`Invoice` 正式開帳記錄），但**缺少按月彙整、每學生每科目之堂數與試算學收明細**。

**目標**：新增「分校學收月報」頁面，列出分校所有進行中學生的課程，以指定月份為範圍，顯示：
- 學生姓名
- 上課科目
- 該月有效課堂數
- 每堂課費率
- 月學收試算金額

**非目標**：
- 不取代或刪除現有 `Invoice`／`BillingController` 帳單功能（帳單未來可能另行啟用正式帳務流程）。
- 不修改核准評量扣堂邏輯（`ApprovalSessionSyncService`／`SessionDeductionService`）；本報表**僅讀取**既有資料。

---

## 2. 現況系統盤點

| 模組 | 檔案 | 說明 |
|------|------|------|
| 帳單列表（現名） | `frontend/src/pages/BillingList.vue`、`backend/app/Http/Controllers/BillingController.php` | 綁 `Invoice` 表；多數分校若從未在系統開帳單則列表空。 |
| 財務總覽 | `backend/app/Http/Controllers/FinanceController.php` `summary`、`revenue` | 分校加總堂數（sold / remaining / used），**非**「學生×科目×月」明細。 |
| 科目數統計 | `frontend/src/pages/SubjectUnitsPage.vue`、`FinanceController::subjectUnits` | 以老師為軸，計算加權科目數；非學生學費口徑。 |
| 催繳名單 | `frontend/src/pages/TuitionCollectionPage.vue`、`AlertController::tuition` | 列出堂數不足或月結將屆之課程；已繳者標「續課聯繫」；可出催繳圖。 |
| 課程主檔 | `backend/app/Models/StudentClass.php` | 含 `Rate`（單價）、`Charge`（總收費）、`SessionCount`、`RemainingSessions`、`UsedSessions`、`ScheduleMode`（count/date）、`ClassType`（one_on_one…）、`settlement_day`、`monthly_sessions`、`Stop` 等。 |

---

## 3. 名詞定義

| 名詞 | 定義 | 備註 |
|------|------|------|
| **月有效堂數** | 指定月份內，學生在該課程「已完成」的堂數 | 來源待定（見 §4 決策 1） |
| **每堂費率** | 該課程單堂單價 | 對應 `StudentClass.Rate`；若為空或 0，可 fallback `Charge / SessionCount`（契約均價） |
| **月學收（試算）** | `月有效堂數 × 每堂費率` | 月結制可能為固定月費而非按堂乘（見 §4 決策 2） |
| **進行中課程** | `StudentClass.Stop = 0` | 暫停或結案不列入 |
| **分校** | 依學生 `CampusID` 隸屬，與 `branch_id` 對齊 | 所有查詢須受 `auth_campus_ids` 限制 |

---

## 4. 開放決策（須產品／CTO 會前定案）

### 決策 1：月有效堂數口徑

| 選項 | 來源 | 優點 | 缺點 |
|------|------|------|------|
| **A** | `ClassSession`（`SessionDate` 在指定月 且 `Status in ('attended','completed','late')`） | 最直觀；不需依賴評量 | 若未點名但已上完，不計入 |
| **B** | `StudentSingIn`（`SignInDT` 在指定月 且 `SessionDeducted = 1`） | 嚴格依實際刷卡扣堂 | 補點名時間可能不在當月 |
| **C** | `LearningRecord`（approved 且對應 `ClassSession.SessionDate` 在月內） | 與科目數口徑一致 | 核准時間可能晚於上課月份 |

**建議**：預設採 **A**（ClassSession Status），未來可提供切換。

### 決策 2：月結制課程如何計算

- **選項 X**：月結制同樣按堂數 × Rate（與堂數制統一）。
- **選項 Y**：月結制直接顯示「月費 = `monthly_sessions × Rate`」或 `Charge`，不逐堂計算。
- **選項 Z**：月結制顯示「契約月費」欄與「實際上課堂數」欄並列，兩者不乘。

### 決策 3：列維度

- **細粒度**：每列 = 一筆 `StudentClass`（學生×科目×老師×班型）。
- **彙總版**：每列 = 學生×科目（多個老師或班型合併），各堂數加總。

**建議**：預設採細粒度（`StudentClass` 為單位），前端提供匯總 toggle。

### 決策 4：CSV 匯出

- 允許主任匯出 CSV（僅內部對帳用）？或因含個資而禁止？
- 由資安評估後產品決定。

---

## 5. 功能需求

### 5.1 新 API

**`GET /api/v1/finance/branch-monthly-tuition`**

| 參數 | 類型 | 必填 | 說明 |
|------|------|------|------|
| `branch_id` | int | 是 | 分校 ID（受 `auth_campus_ids` 限制） |
| `year` | int | 是 | 年份 |
| `month` | int | 是 | 月份（1-12） |
| `page` | int | 否 | 分頁，預設 1 |
| `per_page` | int | 否 | 每頁筆數，預設 50 |

**回傳結構（草案）**：

```json
{
  "data": [
    {
      "student_class_id": 123,
      "student_id": 45,
      "student_name": "王小明",
      "subject": "數學",
      "teacher_name": "李老師",
      "class_type": "one_on_one",
      "schedule_mode": "count",
      "rate": 600,
      "monthly_sessions": 8,
      "monthly_tuition": 4800,
      "remaining_sessions": 12,
      "paid": false
    }
  ],
  "summary": {
    "total_students": 30,
    "total_sessions": 245,
    "total_tuition": 147000
  },
  "meta": {
    "current_page": 1,
    "last_page": 2,
    "per_page": 50,
    "total": 78
  }
}
```

### 5.2 實作要點

- **堂數查詢**：依決策 1 口徑，以 `ClassSession` 為例：

```sql
SELECT StudentClassID, COUNT(*) as monthly_sessions
FROM ClassSession
WHERE SessionDate BETWEEN '{year}-{month}-01' AND '{year}-{month}-{lastDay}'
  AND Status IN ('attended', 'completed', 'late')
GROUP BY StudentClassID
```

- **費率**：`StudentClass.Rate`；若 null 或 0，fallback 為 `Charge / SessionCount`（避免除以零）。
- **月結制**（依決策 2）：若選項 Y，直接取 `monthly_sessions * Rate` 或 `Charge` 作為月費。
- **分校隔離**：與 `FinanceController::getCampusIds` 一致（`Student.CampusID`）。
- **分頁**：Laravel `paginate()`，回傳 `meta`。
- **Summary**：在查詢結果上做 `SUM`，與分頁無關（全量彙總）。

### 5.3 前端頁面

- **頁面名稱建議**：「學收月報」（`active = 'tuition-report'`）。
- **篩選**：年＋月切換器（預設當月）。
- **表格欄位**：學生、科目、老師、班型、月堂數、費率、月學收（試算）、繳費狀態（已繳／未繳）。
- **表尾 summary 列**：全分校的當月總堂數、總學收。
- **空狀態**：「本分校指定月份無有效課程紀錄」。

---

## 6. 與既有功能之分界

```
┌─────────────────────┬──────────────────────┬──────────────────────────┐
│ 催繳名單             │ 學收月報（新）        │ 帳單列表（既有）          │
│ TuitionCollectionPage│ TuitionReportPage    │ BillingList              │
├─────────────────────┼──────────────────────┼──────────────────────────┤
│ 資料源：             │ 資料源：              │ 資料源：                  │
│ alerts/tuition       │ finance/branch-      │ invoices                 │
│ (StudentClass 條件)  │ monthly-tuition      │ (Invoice 表)             │
│                     │ (ClassSession+SC)    │                          │
├─────────────────────┼──────────────────────┼──────────────────────────┤
│ 用途：               │ 用途：                │ 用途：                    │
│ 哪些課程需催繳／續課 │ 本月各學生實際堂數   │ 正式帳單查詢、對帳         │
│ → 產生催繳圖片      │ × 單價 = 學收試算    │ → 產生正式繳費單          │
├─────────────────────┼──────────────────────┼──────────────────────────┤
│ 繳費單：             │ 繳費單：              │ 繳費單：                  │
│ 催繳通知圖           │ 無（僅報表）          │ Invoice 繳費通知單        │
│ (tuition-slip)       │                      │ (invoices/slip-data)     │
└─────────────────────┴──────────────────────┴──────────────────────────┘
```

**不損壞原則**：
- `Invoice`／`BillingController` 路由與 API 保留不動。
- 新 API 與新頁為**增量**，不修改 `SessionDeductionService`、`LearningRecordController` approve 等高風險路徑。
- 催繳名單（`TuitionCollectionPage`）與學收月報為**獨立頁面**，資料源不同（前者依提醒規則，後者依月份堂數），不互相覆蓋。

---

## 7. 側欄資訊架構建議

### 7.1 現況

```
營運總覽
  總覽儀表板、通知中心、內部聊天

教務核心
  班級行事曆、課程管理、學生管理、
  催繳名單、帳單列表、老師管理、教室管理、科目管理

考勤與評量
  出缺勤管理、學習評量表、科目數統計

系統管理
  家長 LINE 通知、Bug 回報
```

### 7.2 問題

「催繳名單」「帳單列表」「未來學收月報」混在**教務核心**，與「課程／學生管理」並列，語意上更像**財務類**功能。主任找「營收相關」需在教務核心翻找，不直覺。

### 7.3 建議方案

**短期（立即可做、改動極小）**：
- 「帳單列表」改名為「帳單記錄」或「開立帳單」（避免誤以為是學收報表）。
- 催繳名單與帳單記錄維持在教務核心，位置不動。

**中期（與學收月報上線同步）**：
- 新增分組 **「財務與收款」**（sidebar key: `finance`），包含：
  - 催繳名單
  - 學收月報（新）
  - 帳單記錄（原 Invoice 列表）
- **教務核心**不再含財務類項目，回歸純教務：班級行事曆、課程管理、學生管理、老師管理、教室管理、科目管理。
- **科目數統計**：留在「考勤與評量」（與評量核准連動），或移入「財務與收款」——由產品決定。

**手機底欄**：新增 `active` key 時，檢查 `mobileTabPages` 是否需把新頁納入（或歸入「更多」），避免底欄超過五格。

---

## 8. 資安與合規

- 報表含學生姓名、金額、堂數；須受**分校隔離**（`auth_campus_ids`）與**主任權限**限制。
- CSV 匯出是否允許：由資安評估後產品決定。
- 不在 log 中寫完整個資，沿用現有 `[TuitionSlip]`／`[InvoiceSlip]` 粒度。

---

## 9. 里程碑建議

| 階段 | 內容 | 依賴 |
|------|------|------|
| M0 | 繳費單預覽空白修復（已完成，`PaymentSlipModal` 載入順序修正） | — |
| M1 | 本規格定案（產品選定堂數口徑、月結算法、列維度、CSV 政策） | 產品／CTO |
| M2 | 後端 `GET /api/v1/finance/branch-monthly-tuition` + Pest 測試 | M1 |
| M3 | 前端「學收月報」頁面 + 側欄重組為「財務與收款」 | M2 |
| M4 | （可選）「帳單記錄」改名 + 保留功能入口 | M3 |

---

## 10. 參考檔案索引

| 檔案 | 用途 |
|------|------|
| `backend/app/Models/StudentClass.php` | 課程主檔：Rate、Charge、SessionCount、ScheduleMode 等 |
| `backend/app/Models/ClassSession.php` | 排課堂次：SessionDate、Status |
| `backend/app/Http/Controllers/FinanceController.php` | 既有 `summary`、`revenue`、`subjectUnits` |
| `backend/app/Http/Controllers/AlertController.php` | 催繳 `tuition`、`tuitionSlipData` |
| `backend/app/Http/Controllers/BillingController.php` | Invoice `slipData` |
| `frontend/src/pages/TuitionCollectionPage.vue` | 催繳名單 |
| `frontend/src/pages/BillingList.vue` | 帳單列表（未來可能改名） |
| `frontend/src/App.vue` | 側欄 `sidebarNavGroups`、`mobileTabPages` |
| `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` | 繳費提醒規則（變更管制） |
| `docs/AI_REGRESSION_LESSONS.md` | AI 防再犯紀錄 |
