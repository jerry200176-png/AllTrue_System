# PRD — 兼職老師薪資計算與月結報表

> 版本：1.0 | 日期：2026-04-14 | 狀態：待開發
>
> **關聯**：個別老師費率覆寫見 [PRD_PARTTIME_PAYROLL_PER_TEACHER_OVERRIDES.md](PRD_PARTTIME_PAYROLL_PER_TEACHER_OVERRIDES.md)

---

## 1. 背景與問題

AllTrue 補習班目前有四間（即將五間）分校，各分校可自行聘用兼職老師授課。目前系統已記錄老師的 `employment_type`（`full_time` / `part_time`），但缺乏薪資計算與報表功能：

- 主任無法在系統內查詢兼職老師的月薪資總額與堂次明細。
- 薪資計算依賴 Excel 手工作業，容易出錯且無法追溯。
- 不同學段、不同授課人數的時薪加成規則散落在口頭約定中，缺乏系統化管理。

## 2. 產品目標與非目標

### 目標

- 主任可按月份、分校查詢兼職老師應付薪資總額與堂次級明細。
- 每筆堂次的計價來源透明可查（學段基礎價 + 人數加成 + 時數換算）。
- 可匯出薪資明細報表（CSV/Excel），供會計與稅務使用。
- 月結後可鎖帳，防止數字漂移；重算留有稽核紀錄。

### 非目標（本期不做）

- 跨分校調課/代課流程（不改動排課模組）。
- 獎金、罰款、津貼、交通費等非時薪項目。
- 自動發薪/銀行串接。
- 全歷史無限制匯出（初版僅允許單月份查詢與匯出）。
- 改動既有堂數扣除主流程。

## 3. 角色與權限

| 角色 | 可見範圍 | 可執行操作 |
|------|----------|------------|
| `super_admin` | 全部分校 | 查詢、匯出、鎖帳、重開、重算 |
| `admin` / `director` | 授權分校 | 查詢、匯出、鎖帳 |
| `teacher` | 不可見 | 無（薪資頁不對老師開放） |
| 家長 | 不可見 | 無 |

權限沿用既有 `role:director` + `require_campus` middleware，分校隔離透過 `CampusID` / `branch_id` 控制。

## 4. 薪資規則

### 4.1 基礎時薪（一對一）

| 學段 | GradeID 範圍 | 對應 level | 基礎時薪 (TWD/h) |
|------|-------------|------------|------------------|
| 高中 | 10, 11, 12 | `high` | 400 |
| 國中 | 7, 8, 9 | `junior` | 350 |
| 國小 | 1, 2, 3, 4, 5, 6 | `elementary` | 300 |
| 輔導 | （ClassType = `tutoring`） | — | 200 |

### 4.2 人數加成（v1.2 修訂，2026-04-15）

> **v1.2 變更**：不再依合約 ClassType 固定加成。改為依實際同時段 LearningRecord 筆數（=實際在場學生數）決定加成，統一納入「併堂加給」計算。

**舊規則（已廢除）**：一對二合約 +50/h、一對三 +100/h（不論實際到課人數）。

**新規則**：所有 ClassType 的基礎時薪 = 學段時薪，不加人數加成（headcountBonus = 0）。人數多時的加成全部透過 §4.3 併堂加給處理。

| ClassType | 基礎時薪 = 學段時薪 | 說明 |
|-----------|-------------------|------|
| `one_on_one` | 400 / 350 / 300 | 同學段時薪 |
| `one_on_two` | 400 / 350 / 300 | 同上，不再 +50 |
| `one_on_three` | 400 / 350 / 300 | 同上，不再 +100 |
| `tutoring` | 200 | 固定 |

### 4.3 併堂加給與層級優先（v1.3 修訂，2026-04-15）

當同一位老師在同一天有多筆已核准 LearningRecord 的時間重疊時，依**層級優先**規則分配加給。

> **v1.3 變更**：加入層級優先（level dominance）。不同層級的 LR 重疊時，高層 LR 獲得加給，低層 LR 在重疊段基本費歸 0。同層級維持原行為。

**層級權重**（`config/payroll.level_weights`）：

| 層級 | 權重 | 基礎時薪 |
|------|------|---------|
| 高中 (high) | 4 | 400 |
| 國中 (junior) | 3 | 350 |
| 國小 (elementary) | 2 | 300 |
| 輔導 (tutoring) | 1 | 200 |

**n 的定義**：某時段內同時重疊的 LR 筆數（= 實際在場學生數）。

**同層級重疊**（兩筆以上相同層級 LR 重疊）：
- 每筆 LR 獲得 `(n - 1) × 50 × Δt` 加給。
- n=2 時每筆得 50/h，n=3 時每筆得 100/h。

**不同層級重疊**（高低不同的 LR 重疊）：
- 最高層級 LR → **主導者**，獲得 `(n - 1) × 50 × Δt` 加給。
- 低層級 LR → **被覆蓋**，重疊段扣除 `base_rate × Δt`（相當於該段基本費歸 0）。
- 單堂薪資最低為 0（`max(0, ...)`）。

| 重疊時長 Δt | n=2 同層級（每筆） | n=2 不同層級（高層/低層） | n=3 同層級（每筆） |
|------------|-----------------|----------------------|-----------------|
| 0.5h | +25 / +25 | +25 / -base×0.5 | +50 / +50 / +50 |
| 1h | +50 / +50 | +50 / -base×1 | +100 / +100 / +100 |
| 2h | +100 / +100 | +100 / -base×2 | +200 / +200 / +200 |

**缺少時間資訊的 LR**：若某筆 LR 無有效 `StartTime`/`EndTime`，排除在重疊計算之外，`concurrency_bonus = 0`。

### 4.4 計算公式

```
單堂薪資 = 學段基礎時薪 × 授課時數(小時) + 併堂加給(該堂)
老師月薪  = Σ 該老師當月所有認列堂次的單堂薪資
```

### 4.5 時數換算

- 優先使用 LearningRecord 的 `StartTime` / `EndTime` 計算差值。
- 若缺少時間，fallback 至 `StudentClass.SessionDuration`（分鐘 ÷ 60）。
- 最終 fallback：2 小時（與現有 `calcHours` 一致）。

### 4.6 四捨五入規則

- 時數：保留 2 位小數。
- 金額：保留整數（四捨五入至元），最終老師月薪為整數。

### 4.7 計算範例

**範例一：無重疊（各為獨立 LR，不同時段）**

| 堂次 | 學段 | ClassType | 時數 | 基礎時薪 | 併堂加給 | 堂次薪資 |
|------|------|-----------|------|---------|---------|---------|
| A | 高中 | one_on_one | 2h | 400 | 0 | 800 |
| B | 高中 | one_on_two | 1.5h | 400 | 0 | 600 |
| C | 國中 | one_on_three | 2h | 350 | 0 | 700 |
| D | 國小 | one_on_one | 1h | 300 | 0 | 300 |
| E | 輔導 | tutoring | 2h | 200 | 0 | 400 |
| **合計** | | | **8.5h** | | **0** | **2,800** |

**範例二：同層級併堂（A 高中 17–19 + B 高中 18–20，重疊 18–19 共 1h，n=2）**

| 堂次 | 學段 | 時數 | 基礎時薪 | base薪資 | 併堂加給 | 堂次薪資 |
|------|------|------|---------|---------|---------|---------|
| A | 高中 | 2h | 400 | 800 | +50（(2-1)×50×1h） | 850 |
| B | 高中 | 2h | 400 | 800 | +50（(2-1)×50×1h） | 850 |
| **合計** | | **4h** | | **1,600** | **+100** | **1,700** |

**範例三：不同層級併堂（A 國中 17–19 + B 輔導 17–19，完全重疊 2h，n=2）**

| 堂次 | 學段 | 時數 | 基礎時薪 | base薪資 | 併堂調整 | 堂次薪資 |
|------|------|------|---------|---------|---------|---------|
| A（主導） | 國中 | 2h | 350 | 700 | +100（(2-1)×50×2h） | 800 |
| B（被覆蓋） | 輔導 | 2h | 200 | 400 | -400（-200×2h） | 0 |
| **合計** | | **4h** | | **1,100** | **-300** | **800** |

## 5. 資料來源與認列條件

### 5.1 唯一資料來源（Source of Truth）

採用 **`LearningRecord`（Status = `approved`，且未作廢 `VoidedAt IS NULL`）** 作為薪資認列的唯一來源，與既有科目數統計（`subjectUnits`）口徑一致。

不使用 `ClassSession(Status=attended)` 的理由：
- LearningRecord 已經過主任審核，具有「認列」語意。
- 與現有 `finance/subject-units` 口徑一致，避免財務數字衝突。

### 5.2 認列條件

一筆堂次被認列為薪資，須同時滿足：

1. `LearningRecord.Status = 'approved'`
2. `LearningRecord.VoidedAt IS NULL`（未作廢）
3. `LearningRecord.ExcludeFromSubjectCount = false`（非排除項）
4. `StudentClass.ClassType != 'trial'`（試聽不計薪）
5. `LearningRecord.SessionDate` 落在查詢月份範圍內
6. 老師的 `User.employment_type = 'part_time'`（僅計算兼職老師）

### 5.3 學段判定

透過 `StudentClass.GradeID` 對照 `gradeIdLevelMap`：

| GradeID | level | 學段 |
|---------|-------|------|
| 1–6 | `elementary` | 國小 |
| 7–9 | `junior` | 國中 |
| 10–12 | `high` | 高中 |
| 其他/null | `unknown` | 依 ClassType 判斷：tutoring → 輔導，否則國小 |

### 5.4 跨日課與時區

- 所有時間以 `Asia/Taipei` 為準。
- 跨日課（例如 23:00–01:00）以 `SessionDate` 為認列日期。

## 6. 月結狀態機

每月、每分校的薪資結算有三個狀態：

```
draft  →  reviewed  →  locked
  ↑                       |
  └─── reopen (audit) ────┘
```

| 狀態 | 說明 | 可執行者 |
|------|------|---------|
| `draft` | 預設狀態，可隨時重算 | 系統自動 |
| `reviewed` | 主任確認數字正確 | director |
| `locked` | 鎖帳，不可修改 | director / super_admin |
| reopen | 從 locked 退回 draft | super_admin 限定，留 audit trail |

鎖帳後：
- 查詢仍可看，但不可重算。
- 匯出會標註「已鎖帳」狀態與鎖帳時間。
- 若需修正，super_admin 可重開並記錄原因。

## 7. 功能需求

### 7.1 薪資查詢頁

- 篩選條件：月份（year-month picker）、分校（受權限限制）。
- 第一層（總覽卡）：
  - 應付薪資總額、總教學時數、兼職老師人數、平均時薪。
- 第二層（老師清單表格）：
  - 欄位：老師姓名、總時數、高中/國中/國小/輔導時數、應付薪資、堂次數。
  - 支援排序（按薪資、時數、姓名）。
  - 支援搜尋（姓名模糊搜尋）。
- 第三層（老師明細展開）：
  - 點擊老師列展開堂次明細。
  - 每筆顯示：日期、學生姓名、科目、學段、ClassType、時數、基礎時薪、加成、堂次薪資。
  - 明細底部顯示小計。

### 7.2 薪資匯出

- 匯出格式：CSV（優先）或 Excel。
- 匯出範圍：當前篩選條件（單月、單分校）。
- 匯出內容：
  - Sheet 1（或 CSV 第一段）：老師總覽（與第二層相同欄位）。
  - Sheet 2（或 CSV 第二段）：堂次明細（與第三層相同欄位）。
- 匯出檔名格式：`兼職薪資_{分校}_{YYYY-MM}.csv`。
- 匯出時使用同一批次查詢結果，確保畫面與檔案金額一致。

### 7.3 月結鎖帳

- 在總覽卡旁提供「確認」與「鎖帳」按鈕。
- 鎖帳後按鈕變為「已鎖帳（YYYY-MM-DD HH:mm 由 XXX 鎖定）」。
- super_admin 可見「重開」按鈕，需填寫原因。

## 8. API 設計

### 8.1 路由規劃

所有路由放在 `role:director` + `require_campus` middleware 群組內。

| 方法 | 路徑 | 用途 |
|------|------|------|
| GET | `/api/v1/finance/parttime-payroll` | 總覽 + 老師列表 |
| GET | `/api/v1/finance/parttime-payroll/{teacherId}/sessions` | 單一老師堂次明細 |
| GET | `/api/v1/finance/parttime-payroll/export` | 匯出 CSV |
| POST | `/api/v1/finance/parttime-payroll/lock` | 鎖帳 |
| POST | `/api/v1/finance/parttime-payroll/reopen` | 重開（super_admin） |

不改動既有 `GET /api/v1/finance/teacher-payroll`，避免回歸風險。

### 8.2 GET `/api/v1/finance/parttime-payroll`

**參數：**

| 參數 | 型別 | 必填 | 說明 |
|------|------|------|------|
| `month` | string | 是 | 格式 `YYYY-MM` |
| `branch_id` | int | 否 | 不填則依權限回傳所有授權分校 |
| `search` | string | 否 | 老師姓名模糊搜尋 |
| `sort` | string | 否 | `salary_desc`（預設）、`salary_asc`、`hours_desc`、`name_asc` |

**回傳：**

```json
{
  "summary": {
    "total_salary": 45600,
    "total_hours": 120.5,
    "teacher_count": 8,
    "avg_hourly_rate": 378,
    "month": "2026-04",
    "branch_id": 1,
    "branch_name": "興隆分校",
    "lock_status": "draft",
    "locked_at": null,
    "locked_by": null
  },
  "teachers": [
    {
      "teacher_id": 12,
      "teacher_name": "王小明",
      "total_hours": 24.5,
      "high_hours": 10.0,
      "junior_hours": 8.5,
      "elementary_hours": 4.0,
      "tutoring_hours": 2.0,
      "total_salary": 9350,
      "session_count": 15
    }
  ]
}
```

### 8.3 GET `/api/v1/finance/parttime-payroll/{teacherId}/sessions`

**參數：**

| 參數 | 型別 | 必填 | 說明 |
|------|------|------|------|
| `month` | string | 是 | 格式 `YYYY-MM` |
| `branch_id` | int | 否 | |
| `page` | int | 否 | 預設 1 |
| `per_page` | int | 否 | 預設 50，上限 200 |

**回傳：**

```json
{
  "teacher": {
    "teacher_id": 12,
    "teacher_name": "王小明",
    "total_salary": 9350,
    "total_hours": 24.5,
    "session_count": 15
  },
  "sessions": [
    {
      "learning_record_id": 456,
      "session_date": "2026-04-03",
      "student_name": "李同學",
      "subject": "Math",
      "level": "high",
      "level_label": "高中",
      "class_type": "one_on_two",
      "student_count": 2,
      "start_time": "14:00",
      "end_time": "16:00",
      "hours": 2.0,
      "base_rate": 400,
      "headcount_bonus": 50,
      "effective_rate": 450,
      "session_salary": 900
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 50,
    "total": 15
  }
}
```

### 8.4 GET `/api/v1/finance/parttime-payroll/export`

**參數：** 同 8.2（`month`、`branch_id`）。

**行為：**
- 同步回傳 CSV 檔案（`Content-Type: text/csv`）。
- 若單月堂次超過 5,000 筆，回傳 422 並建議縮小範圍。
- 回傳 header 包含 `Content-Disposition: attachment; filename="兼職薪資_興隆_2026-04.csv"`。

### 8.5 POST `/api/v1/finance/parttime-payroll/lock`

**Body：** `{ "month": "2026-04", "branch_id": 1 }`

**行為：** 將該月該分校狀態設為 `locked`，記錄操作者與時間。

### 8.6 POST `/api/v1/finance/parttime-payroll/reopen`

**Body：** `{ "month": "2026-04", "branch_id": 1, "reason": "發現漏計一筆代課" }`

**權限：** 僅 `super_admin`。

**行為：** 將狀態退回 `draft`，記錄操作者、時間、原因。

## 9. 資料模型

### 9.1 新增資料表：`payroll_month_status`

用於記錄月結鎖帳狀態。

| 欄位 | 型別 | 說明 |
|------|------|------|
| `id` | bigint PK | |
| `branch_id` | int | 分校 ID |
| `month` | char(7) | `YYYY-MM` |
| `status` | enum | `draft` / `reviewed` / `locked` |
| `locked_by` | int nullable | User ID |
| `locked_at` | datetime nullable | |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

索引：`UNIQUE(branch_id, month)`。

### 9.2 新增資料表：`payroll_audit_log`

用於記錄鎖帳/重開操作。

| 欄位 | 型別 | 說明 |
|------|------|------|
| `id` | bigint PK | |
| `branch_id` | int | |
| `month` | char(7) | |
| `action` | enum | `lock` / `reopen` / `reviewed` |
| `user_id` | int | 操作者 |
| `reason` | text nullable | 重開時填寫 |
| `created_at` | timestamp | |

### 9.3 既有資料表使用（唯讀）

| 資料表 | 讀取欄位 | 用途 |
|--------|---------|------|
| `LearningRecord` | Status, VoidedAt, TeacherID, StudentClassID, SessionDate, StartTime, EndTime, ExcludeFromSubjectCount | 認列來源 |
| `StudentClass` | GradeID, ClassType, SessionDuration, StudentID | 學段/課型/時數 fallback |
| `Student` | CampusID, name | 分校隔離、學生姓名 |
| `User` | Name, employment_type | 老師姓名、兼職篩選 |
| `Teacher` | T_Name | 老師姓名 fallback |
| `Campus` | name | 分校名稱 |

### 9.4 建議索引

| 索引 | 用途 |
|------|------|
| `LearningRecord(TeacherID, Status, SessionDate)` | 薪資查詢主要路徑 |
| `LearningRecord(StudentClassID, SessionDate)` | 已存在（perf migration） |
| `User(employment_type, type)` | 快速篩選兼職老師 |

## 10. 前端設計

### 10.1 頁面掛載

- 新增獨立頁面 `ParttimePayrollPage.vue`。
- `active` key：`parttime-payroll`。
- 側欄入口：放在「財務」區塊，僅 director / super_admin 可見。
- 不塞入 `TeachersList.vue`，避免老師管理頁過重。

### 10.2 資訊架構

```
第一層（總覽卡區，最高優先）
  ├ 應付薪資總額（大字，高對比）
  ├ 總教學時數
  ├ 兼職老師人數
  ├ 平均時薪
  └ 月結狀態標籤 + 操作按鈕

第二層（老師清單表格）
  ├ 搜尋列
  ├ 排序控制
  └ 老師卡片/表格行 × N

第三層（展開明細，點擊老師後）
  ├ 堂次明細表格（分頁）
  └ 底部小計
```

### 10.3 篩選與操作流程

```
進入薪資頁
  → 月份選擇器（預設當月）
  → 分校選擇器（預設當前分校）
  → 點「查詢」或自動載入
  → 總覽卡顯示
  → 老師清單顯示
  → 點擊老師行展開堂次明細
  → 點「匯出」下載 CSV
  → 確認無誤後點「鎖帳」
```

### 10.4 狀態管理

- `payrollMonth`：當前選擇的月份。
- `payrollBranch`：當前選擇的分校。
- `summary`：總覽卡資料。
- `teacherRows`：老師列表。
- `expandedTeacherId`：當前展開明細的老師。
- `sessionRows`：堂次明細（分頁）。
- `exporting`：匯出中狀態。
- `lockStatus`：月結狀態。

### 10.5 UI 狀態矩陣

| 狀態 | 畫面表現 | 文案 |
|------|---------|------|
| Loading | Skeleton 卡片 + 表格佔位 | — |
| Empty（無兼職老師） | 插圖 + 文字 | 「本月該分校無兼職老師授課紀錄」 |
| Empty（有老師但無認列堂次） | 插圖 + 文字 | 「本月尚無已核准的評量紀錄，薪資為 0」 |
| Error（API 失敗） | 錯誤卡片 + 重試按鈕 | 「載入薪資資料失敗，請稍後再試」 |
| 無權限 | 鎖定圖示 + 文字 | 「您無權限查看此分校的薪資資料」 |
| 已鎖帳 | 鎖帳標籤 + 鎖帳資訊 | 「已鎖帳（2026-04-30 由 陳主任 鎖定）」 |
| 匯出中 | 按鈕 disabled + spinner | 「匯出中...」 |
| 匯出完成 | toast 提示 | 「薪資報表已下載」 |
| 匯出失敗 | toast 錯誤 + 重試 | 「匯出失敗，請稍後再試」 |

## 11. 視覺設計規範

### 11.1 色彩語意

| 用途 | 色彩 | 說明 |
|------|------|------|
| 薪資總額 | 系統主色（藍） | 高對比大字 |
| 鎖帳狀態 | 綠色 badge | locked |
| 草稿狀態 | 灰色 badge | draft |
| 錯誤/警告 | 紅色/橘色 | 異常或未鎖帳提醒 |

### 11.2 字級層級

| 層級 | 使用場景 | 大小 |
|------|---------|------|
| H1 | 頁面標題「兼職老師薪資」 | 1.5rem / bold |
| 總額數字 | 總覽卡內的金額 | 2rem / bold |
| 表格標題 | 老師列表 header | 0.875rem / semibold |
| 表格內容 | 老師列表 body | 0.875rem / normal |
| 明細內容 | 堂次明細 body | 0.8125rem / normal |
| 次資訊 | 計算說明、備註 | 0.75rem / normal / 灰色 |

### 11.3 間距與版面

- 總覽卡：4 欄 grid，卡片間距 16px，內距 20px。
- 老師表格：行高 48px，hover 背景淺灰。
- 明細展開：縮排 24px，背景微灰（#f9fafb），與父行視覺區隔。
- 手機斷點（640px 以下）：總覽卡改 2 欄，表格改卡片模式。

### 11.4 元件一致性

- 篩選器、按鈕、表格、badge 沿用現有後台 design language。
- 匯出按鈕樣式同「科目數」頁匯出。
- 搜尋列樣式同 `TeachersList` 搜尋列。

## 12. 可用性與無障礙驗收

### 12.1 可用性指標

| 指標 | 目標 | 量測方式 |
|------|------|---------|
| 任務完成時間 | 首次使用者 < 1 分鐘完成「選月→看明細→匯出」 | 情境任務測試 |
| 計價理解時間 | < 5 秒理解單堂計價來源 | 口頭詢問測試 |
| 任務成功率 | >= 95% 正確完成查詢與匯出 | 5–8 人測試 |
| 主觀清晰度 | >= 4/5 | 問卷 |

### 12.2 無障礙最低要求

- 文字與背景對比比率 >= 4.5:1。
- 所有按鈕可透過鍵盤 Tab/Enter 觸達。
- 錯誤訊息為文字化描述，不僅靠顏色區分。
- 表格有適當的 `aria-label` 與 `role`。
- 展開/收合明細有 `aria-expanded` 狀態。

## 13. Raspberry Pi 容量保護

### 13.1 容量預算

| 資源 | 預算 | 說明 |
|------|------|------|
| 單次查詢 RAM | < 30MB | 避免觸發 swap |
| 單次匯出 RAM | < 50MB | CSV 串流輸出 |
| API 回應時間 P95 | < 2,000ms | 總覽 + 老師列表 |
| 明細查詢 P95 | < 1,500ms | 單老師堂次 |
| 匯出耗時 | < 10s | 單月單分校 |

### 13.2 記憶體安全查詢策略

- API 強制分頁：`per_page` 預設 50，上限 200。
- 明細查詢必須「先選老師，再展開堂次」，禁止一次回傳全老師全堂次。
- 匯出採 CSV 串流（`StreamedResponse`），不在記憶體中組裝完整檔案。
- 後端 SELECT 使用欄位白名單，不 `SELECT *`。
- 若 Eloquent eager-load 導致記憶體壓力，改用 `DB::table()` + `chunk()`。

### 13.3 匯出任務安全

- 同步回傳 CSV 串流（不走 queue），但設定 PHP `max_execution_time` 上限 30s。
- 單次匯出超過 5,000 筆堂次時回傳 422，提示縮小範圍。
- 前端匯出按鈕在請求中 disabled，防止重複點擊。

### 13.4 安全閥與降載

- 後端 config：`payroll.max_per_page`（預設 200）、`payroll.max_export_rows`（預設 5000）。
- Feature flag：`PAYROLL_FEATURE_ENABLED`（`.env`），一鍵關閉整個薪資功能。
- 慢查詢超過 3s 寫入 `perf` log channel（沿用現有 `LogSlowRequests` middleware）。

### 13.5 上線前 IT 驗收門檻（No-Go 條件）

| 項目 | 門檻 | 不達標處置 |
|------|------|-----------|
| OOM-killer | 壓測期間 0 次觸發 | 降低 per_page / 加 chunk |
| swap 占用 | 不可持續 > 80% 超過 60s | 檢查查詢是否 eager-load 過多 |
| API P95 | < 2,000ms | 加索引或降低回傳量 |
| error rate | < 1%（30 分鐘壓測） | 檢查 timeout 與 memory_limit |
| 匯出穩定性 | 連續 10 次匯出無失敗 | 調整 max_export_rows |

### 13.6 監控與告警

- 薪資 API 納入現有 `LogSlowRequests` middleware，超過 SLO 寫入 `perf` log。
- 監控腳本：

```bash
# 最近 1 小時薪資 API 慢請求
grep 'parttime-payroll' /home/admin/backend/storage/logs/perf-$(date +%Y-%m-%d).log | grep -c 'slow-request'

# 記憶體使用
free -m | awk 'NR==2{printf "Used: %sMB / %sMB (%.1f%%)\n", $3, $2, $3*100/$2}'
```

### 13.7 回退步驟（5 分鐘內）

```bash
# 1. 關閉功能
echo "PAYROLL_FEATURE_ENABLED=false" >> /home/admin/backend/.env
cd /home/admin/backend && php artisan config:clear

# 2. 若需回退 migration
cd /home/admin/backend && php artisan migrate:rollback --step=1

# 3. 若前端已部署，重新部署移除薪資頁入口
cd /home/admin/frontend && npm run deploy
```

## 14. 測試與回歸清單

### 14.1 薪資計算金樣本（Golden Cases）

| # | 情境 | 輸入 | 預期薪資 | 說明 |
|---|------|------|---------|------|
| G1 | 高中一對一 2h | GradeID=10, one_on_one, 2h | 800 | 400×2 |
| G2 | 高中一對二 1.5h（單筆 LR） | GradeID=11, one_on_two, 1.5h | 600 | 400×1.5（無人數加成） |
| G3 | 高中一對三 2h（單筆 LR） | GradeID=12, one_on_three, 2h | 800 | 400×2（無人數加成） |
| G4 | 國中一對一 1h | GradeID=7, one_on_one, 1h | 350 | 350×1 |
| G5 | 國中一對二 2h（單筆 LR） | GradeID=8, one_on_two, 2h | 700 | 350×2（無人數加成） |
| G6 | 國中一對三 1.5h（單筆 LR） | GradeID=9, one_on_three, 1.5h | 525 | 350×1.5（無人數加成） |
| G7 | 國小一對一 2h | GradeID=1, one_on_one, 2h | 600 | 300×2 |
| G8 | 國小一對二 1h（單筆 LR） | GradeID=4, one_on_two, 1h | 300 | 300×1（無人數加成） |
| G9 | 國小一對三 2h（單筆 LR） | GradeID=6, one_on_three, 2h | 600 | 300×2（無人數加成） |
| G10 | 輔導 2h | tutoring, 2h | 400 | 200×2 |
| G11 | 試聽排除 | ClassType=trial | 0（不計入） | |
| G12 | 時數 fallback（無時間，SessionDuration=90min） | — | 按 1.5h 計 | |
| G13 | 時數 fallback（全缺，預設 2h） | — | 按 2h 計 | |
| G14 | 一對二兩人都來 2h（2 筆 LR 完全重疊） | one_on_two ×2, 同時段 2h | 1,600 | 同層級：各 350×2+(2-1)×50×2=800 |
| G15 | 分校規則 headcount_bonus=200 不影響 | one_on_two 單筆 LR | 700 | 350×2，headcount_bonus 被忽略 |
| G16 | 國中+輔導並堂 2h（層級優先） | 國中 17-19 + 輔導 17-19 | 800 | 國中主導：350×2+100=800；輔導被覆蓋：max(0,400-400)=0 |
| G17 | 同層級（國中×2）並堂 2h | 國中 14-16 + 國中 14-16 | 1,600 | 各 350×2+(2-1)×50×2=800 |
| G18 | 三筆同層高中完全重疊 1h | 高中×3, 18-19 | 1,500 | 各 400×1+(3-1)×50×1=500 |
| G19 | 國小+輔導部分重疊 1h | 國小 17-19 + 輔導 18-20 | 850 | 國小主導段 +50；輔導重疊段 -200，其餘正常 |

### 14.2 API 契約測試（Pest Feature Tests）

| # | 測試案例 | 驗證項目 |
|---|---------|---------|
| T1 | 正常查詢回傳結構 | `summary` 欄位齊全、`teachers` 為 array |
| T2 | 分頁參數 | `per_page=10` 回傳正確 meta |
| T3 | 超出上限 per_page | `per_page=999` 被截斷為 200 |
| T4 | 缺少 month 參數 | 回傳 422 |
| T5 | 無效 month 格式 | 回傳 422 |
| T6 | 排序參數 | `sort=name_asc` 回傳按姓名排序 |
| T7 | 搜尋參數 | `search=王` 僅回傳姓名含「王」的老師 |
| T8 | 金額型別 | salary 為 int，hours 為 float |

### 14.3 分校與角色隔離測試

| # | 測試案例 | 預期 |
|---|---------|------|
| R1 | director A 查詢分校 B（無權限） | 回傳空或僅 A 分校資料 |
| R2 | director A 查詢自己分校 | 正常回傳 |
| R3 | super_admin 查詢任意分校 | 正常回傳 |
| R4 | teacher 角色存取薪資 API | 403 |
| R5 | 未認證請求 | 401 |
| R6 | 跨分校老師只計算該分校的堂次 | 薪資僅含目標分校學生的堂次 |

### 14.4 月結鎖帳測試

| # | 測試案例 | 預期 |
|---|---------|------|
| L1 | 鎖帳後查詢 | 正常回傳，`lock_status=locked` |
| L2 | 鎖帳後嘗試重新鎖帳 | 回傳 422「已鎖帳」 |
| L3 | director 嘗試重開 | 403 |
| L4 | super_admin 重開 | 成功，狀態回 draft |
| L5 | 重開後 audit log | 記錄操作者、時間、原因 |
| L6 | 未鎖帳的月份嘗試重開 | 回傳 422「尚未鎖帳」 |

### 14.5 匯出一致性測試

| # | 測試案例 | 預期 |
|---|---------|------|
| E1 | 匯出後比對畫面金額 | 老師薪資總額一致 |
| E2 | 匯出後比對堂次筆數 | 明細筆數一致 |
| E3 | 匯出含中文檔名 | 檔名正確、不亂碼 |
| E4 | 超過 5,000 筆匯出 | 回傳 422 |

### 14.6 非功能測試

| # | 測試案例 | 門檻 |
|---|---------|------|
| N1 | 單月 200 筆堂次查詢回應時間 | < 2,000ms |
| N2 | 連續 10 次查詢無失敗 | error rate = 0% |
| N3 | 匯出 3,000 筆堂次 | < 10s、無 OOM |
| N4 | 並發 3 人同時查詢 | 回應時間 < 3,000ms |

### 14.7 回歸測試

| # | 驗證項目 | 關聯模組 |
|---|---------|---------|
| REG1 | 科目數統計不受影響 | `SubjectUnitsPage` |
| REG2 | 原 `teacher-payroll` API 不受影響 | `DirectorDashboard` |
| REG3 | 學習評量審核流程不受影響 | `LearningRecordsPage` |
| REG4 | 出缺勤與堂數扣除不受影響 | `AttendancePage` |
| REG5 | 繳費提醒不受影響 | `AlertController::tuition` |

## 15. UAT 驗收清單

### 15.1 前置條件

- 至少 2 間分校有兼職老師資料（`employment_type=part_time`）。
- 至少 3 位兼職老師有當月已核准的 LearningRecord。
- 至少包含高中、國中、國小、輔導四種學段。
- 至少包含 one_on_one、one_on_two、one_on_three 三種課型。

### 15.2 測試項目

- [ ] 主任登入 → 側欄可見「兼職薪資」→ 進入薪資頁。
- [ ] 選擇月份與分校 → 總覽卡正確顯示。
- [ ] 老師清單排序正常。
- [ ] 搜尋老師姓名正常。
- [ ] 點擊老師展開堂次明細 → 每筆顯示計價公式。
- [ ] 明細小計與老師列的薪資一致。
- [ ] 匯出 CSV → 開啟後金額與畫面一致。
- [ ] 鎖帳 → 狀態變為「已鎖帳」。
- [ ] 鎖帳後匯出 → 報表標註已鎖帳。
- [ ] 老師帳號登入 → 側欄不可見「兼職薪資」。
- [ ] 切換至未授權分校 → 資料不可見。
- [ ] 手機端查看 → 版面正常、可操作。
- [ ] 手動計算 3 位老師薪資 → 與系統一致。

### 15.3 Go / No-Go 條件

- **Go**：所有 UAT 項目通過，且手動驗算薪資 100% 一致。
- **No-Go**：任一金額計算錯誤、任一權限隔離失敗、Pi 壓測不達標。

### 15.4 簽核

- [ ] PM 簽核  日期：________
- [ ] 財務人員簽核  日期：________
- [ ] 主任簽核「可接受」  日期：________
- [ ] Pi 壓測通過  日期：________

## 16. 風險清單

| 風險 | 等級 | 緩解措施 |
|------|------|---------|
| 薪資金額計算錯誤 | Critical | 金樣本自動測試 + UAT 手動驗算 |
| 分校資料洩漏 | Critical | 沿用既有 `require_campus`，隔離測試覆蓋 |
| Pi 記憶體不足 | High | 分頁上限 + CSV 串流 + 匯出筆數上限 |
| 月結後數字漂移 | High | 鎖帳機制 + audit log |
| LearningRecord 口徑與 ClassSession 不一致 | Medium | PRD 固定單一來源，不混用 |
| 兼職老師 employment_type 未正確設定 | Medium | 上線前資料健檢，提供批次修正入口 |
| 使用者不理解計價邏輯 | Low | 每筆堂次展示完整計價公式 |

## 17. 上線策略

### 17.1 階段

| 階段 | 內容 | 時程 |
|------|------|------|
| P1 | 後端 API + migration + 測試 | — |
| P2 | 前端薪資頁 + 整合測試 | — |
| P3 | Pi 壓測 + UAT | — |
| P4 | 全量上線（四分校同時） | — |

### 17.2 上線後觀察

- 上線後 7 天觀察期：
  - 監控 perf log（慢查詢、SLO 違規）。
  - 收集主任回饋（計算正確性、操作流暢度）。
  - 確認無 OOM 或 swap thrashing 事件。

### 17.3 回滾方案

- Feature flag `PAYROLL_FEATURE_ENABLED=false` → 即時關閉。
- 前端 deploy 移除側欄入口 → 1 分鐘內。
- Migration rollback → 5 分鐘內。

## 18. 影響檔案清單（預估）

| 檔案 | 變更類型 |
|------|---------|
| `backend/app/Http/Controllers/FinanceController.php` | 新增 parttime-payroll 方法群 |
| `backend/app/Models/PayrollMonthStatus.php` | 新增 Model |
| `backend/app/Models/PayrollAuditLog.php` | 新增 Model |
| `backend/database/migrations/xxxx_create_payroll_tables.php` | 新增 migration |
| `backend/routes/api.php` | 新增路由 |
| `backend/config/payroll.php` | 新增設定檔 |
| `frontend/src/pages/ParttimePayrollPage.vue` | 新增頁面 |
| `frontend/src/App.vue` | 側欄新增入口 |
| `backend/tests/Feature/ParttimePayrollTest.php` | 新增測試 |
