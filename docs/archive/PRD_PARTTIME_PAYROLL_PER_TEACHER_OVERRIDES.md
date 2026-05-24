> **[ARCHIVED 2026-05-24]** 此文件已移入 docs/archive/，僅供搜尋參考，不再維護。現行規格見 docs/INDEX.md。

# PRD — 兼職老師「個別費率／計價規則」（Per-Teacher Payroll Overrides）

> 版本：1.0 | 日期：2026-04-15 | 狀態：**已實作**
>
> 關聯文件：[PRD_PARTTIME_TEACHER_PAYROLL.md](PRD_PARTTIME_TEACHER_PAYROLL.md)（v1.0：分校級薪資規則）

---

## 1. 背景

後端已實作分校規則表 `payroll_branch_rules`，`FinanceController::buildSessionRow` 對同一 `branch_id`、同一月份內所有兼職老師使用相同費率結構。實務上常需為個別老師設定不同時薪。

## 2. 解決方案

在分校規則之上，新增 **每位兼職老師×分校** 的個別費率覆寫。未設定者仍自動使用分校預設規則。

### 產品決策（已定案）

| 決策項 | 結果 |
|--------|------|
| 覆寫維度 | `teacher_user_id` + `branch_id` |
| 覆寫語意 | 完整取代（方案 A）——有覆寫時完全不讀分校 `base_rates`/`headcount_bonus` |
| 編輯權限 | `director`（同分校）、`super_admin`（跨分校） |
| `effective_from` | MVP 不做，僅立即生效 + 版本歷史 |
| `employment_type` 邊界 | 採查詢當下 `User.employment_type`，與現行 `parttimeBaseQuery` 一致 |

## 3. 資料庫

### 新表：`payroll_teacher_branch_rules`

| 欄位 | 型態 | 說明 |
|------|------|------|
| `id` | bigint PK | 版本 ID（append-only，每次 PUT 新增一列） |
| `teacher_user_id` | unsignedInteger | `User.id` |
| `branch_id` | unsignedInteger | `Campus.id` |
| `base_rates` | JSON | `{ high, junior, elementary, tutoring }` |
| `headcount_bonus` | unsignedInteger | **已停用（v1.2）**：欄位保留但計算不再讀取。人數加成改為依實際同時段 LR 筆數自動計算（見 §4.2/§4.3） |
| `created_by` | unsignedInteger nullable | 操作者 `User.id` |
| `created_at` | timestamp | |

索引：`(branch_id, teacher_user_id, id)` — 利於取最新版本。

### 變更：`payroll_month_status`

新增 `teacher_rule_snapshots` (JSON nullable)：鎖帳時記錄 `{ "<teacher_user_id>": <payroll_teacher_branch_rules.id>, ... }`。

### 變更：`payroll_audit_log.action` ENUM

擴充：`teacher_rule_update`、`teacher_rule_revert`。

## 4. 規則解析順序

```
LearningRecord + branch
  → 月份已鎖帳？
    → 是 → 讀 payroll_month_status 快照（branch rule_version_id + teacher_rule_snapshots）
    → 否 → 該老師有個別規則？
              → 是 → 使用最新個別規則
              → 否 → 使用分校 payroll_branch_rules 最新版
                        → 無 → config/payroll.php fallback
```

鎖帳時以 `DB::transaction` 同時寫入 `rule_version_id`（分校）和 `teacher_rule_snapshots`（個別），確保快照一致。

## 5. API

### 新增

| Method | Path | 說明 |
|--------|------|------|
| `GET` | `finance/parttime-payroll/teacher-rules` | 查詢單一老師或全分校覆寫 |
| `PUT` | `finance/parttime-payroll/teacher-rules` | 新增/更新個別費率（append-only） |
| `DELETE` | `finance/parttime-payroll/teacher-rules` | 恢復分校預設（刪除所有覆寫列） |

### 變更

| 端點 | 變更 |
|------|------|
| `GET parttime-payroll` | teacher rows 新增 `rule_source` 欄位 |
| `GET .../sessions` | session rows 新增 `rule_source` 欄位 |
| `GET .../export` | CSV 新增「費率來源」欄位 |
| `POST .../lock` | 快照含 `teacher_rule_snapshots` |
| `POST .../reopen` | 清除 `teacher_rule_snapshots` |

`rule_source` 值：`branch_default` / `teacher_override`。

## 6. 前端

- 薪資頁教師列表名稱旁：有覆寫時顯示「自訂費率」小標籤
- 展開明細 footer 新增「個別費率」按鈕 → 開啟 modal
- Modal 可設定/編輯個別費率（同分校規則 modal 欄位），可「恢復分校預設」
- 儲存後自動重載數據，無需手動刷新

## 7. 測試

14 項 Pest Feature 測試（`PayrollTeacherOverrideTest.php`），覆蓋：

- TC-OVR-01：覆寫僅影響目標老師
- TC-OVR-02：跨分校隔離
- TC-OVR-03：分校規則變更不影響已覆寫老師
- TC-LOCK-01：鎖帳快照含個別規則
- TC-LOCK-02：重開清除快照，使用最新規則
- TC-REV-01：恢復分校預設
- TC-403-01：跨校區 PUT 被拒
- TC-422-01：驗證錯誤
- 非兼職老師被拒
- CSV 含 rule_source
- Sessions 含 rule_source
- GET teacher-rules
- PUT 建立版本 + audit log
- TC-REG-01：無覆寫時行為不變

既有 21 項測試（`ParttimePayrollTest` + `PayrollRulesTest`）全數通過，確認零回歸。
