# 資料修復計畫：in-app #190 週日月結歷史帳務（Sunday renew SessionCount=0）

> **狀態**：Draft — **禁止在核准前於 production 執行任何寫入**  
> **GitHub**：[#1096](https://github.com/jerry200176-png/AllTrue_System/issues/1096)（in-app #190）  
> **程式修復**：PR #1108 已 merge（2026-07-08）— 新續約不再產生 0 元  
> **本文件範圍**：**歷史資料**修復（修復前已建立的錯誤 `StudentClass` / `Invoice`）

---

## 1. 影響分析

### 1.1 根因（程式面，已修）

`buildSessionsFromWeeklySchedule` 用 Carbon `dayOfWeek`（0=日）比對 ISO weekday（7=日）。週一～六兩套慣例相同，**唯獨週日永不匹配** → `renew-monthly` 算出 `SessionCount=0`、`Charge=0` → NT$0 `Invoice`。

修復後（`isoWeekday()` + `dayOfWeekIso`）週日 slot 可正確計入堂數。

### 1.2 歷史資料特徵（偵測簽章）

| 欄位條件 | 說明 |
|----------|------|
| `ScheduleMode = 'date'` | 月結制 |
| `week IN (0,7)` 或 `week1..week6 IN (0,7)` | 排課含週日（DB 存 ISO 7） |
| `SessionCount = 0` **且** `Charge = 0` | renew 當下算不出堂數與金額 |
| `StartDate >= '2026-01-01'` | 本次 audit 窗口（可擴） |
| `ClassSession` 實際有列 | `ensureMonthlyFutureScheduledSessions` 等後續路徑仍可能物化堂次 |

### 1.3 Production audit 結果（2026-07-08，唯讀）

**簽章完全符合（6 筆）**

| SC ID | 學生 | 分校 | 期間 | week/time | SessionCount | Charge | Rate | 實際堂次 | Invoice |
|-------|------|------|------|-----------|--------------|--------|------|----------|---------|
| 1695 | 洪子勛 | 9 | 2026-05-24～05-31 | 7 / 13:00 | 0 | 0 | 1000 | 2 | — |
| 1696 | 洪子勛 | 9 | 2026-05-24～05-31 | 7 / 10:00 | 0 | 0 | 1000 | 2 | — |
| 2026 | 洪子勛 | 9 | 2026-06-01～07-05 | 7 / 13:00 | 0 | 0 | 1000 | 4 | **#690 NT$0** unpaid |
| 2027 | 洪子勛 | 9 | 2026-06-01～07-05 | 7 / 15:00 | 0 | 0 | 1000 | 4 | **#691 NT$0** unpaid |
| 1331 | 陳顥昀 | 9 | 2026-05-03～05-31 | 7 / 17:00 | 0 | 0 | 2200 | 2 | — |
| 1539 | 周允妍 | 22 | 2026-05-01～05-31 | 2+7 雙時段 | 0 | 0 | 450 | 6 | — |

**對照：洪子勛修復前正常續約（4 月）**

| SC ID | 期間 | SessionCount | Charge | 實際堂次 |
|-------|------|--------------|--------|----------|
| 197 | 2026-04-12～05-31 | 8 | 8000 | 8 |
| 199 | 2026-04-12～05-31 | 8 | 8000 | 8 |

→ 5 月 partial renew（1695/1696）與 6 月 full renew（2026/2027）在 bug 窗口內退化為 0。

### 1.4 相關但非本 bug 簽章（需分案）

| SC ID | 學生 | 現象 | 備註 |
|-------|------|------|------|
| 316, 318 | 翟君和 | Sunday、`SessionCount=0` 但 `Charge=6600` | Charge 非 0，可能手動補登或其他 renew 路徑 |
| 382 | 吳艾潼 | `SessionCount=0`、`Charge=13200`、9 堂 | 非 Sunday-only 0 元簽章 |
| 多筆 | 各生 | `SessionCount=0` 但 `consumed > 0` | 月結／堂數制混用或 #957 重複物化家族 — 見 `189-191` / Epic #957 |

**本次 #190 歷史修復建議範圍**：上表 **6 筆簽章完全符合** 者；其餘列入 #957 資料清查 backlog。

### 1.5 業務影響估計

| 項目 | 估計 |
|------|------|
| 受影響學生 | **3 人**（洪子勛、陳顥昀、周允妍） |
| 受影響合約 | **6 筆** StudentClass |
| 應收未入帳（粗估） | 依 `實際堂次 × Rate`：約 **NT$17,100**（見下表） |
| 已開 Invoice | **2 張**（#690、#691）均 NT$0、status=unpaid |
| 已收款風險 | 洪子勛 6 月帳務回報「2998 vs 3000」— **主任可能已手動調整帳外**；修復前須與帳務確認 |
| 營運影響 | 繳費提醒漏收、月結對帳不平、科目數／薪資口徑若依 Charge 會偏低 |

**粗估應收（修復目標 Charge）**

| SC | 堂次 | Rate | 建議 Charge |
|----|------|------|-------------|
| 1695 | 2 | 1000 | 2,000 |
| 1696 | 2 | 1000 | 2,000 |
| 2026 | 4 | 1000 | 4,000 |
| 2027 | 4 | 1000 | 4,000 |
| 1331 | 2 | 2200 | 4,400 |
| 1539 | 6 | 450 | 2,700 |
| **合計** | | | **~19,100** |

> 周允妍 SC1539 為週六+週日雙時段，修復時應以修復後 `buildSessionsFromWeeklySchedule` 重算，勿僅用 `COUNT(*)`。

### 1.6 嚴重度

**P1（帳務）** — 程式已防新案；歷史 6 筆合約仍帶錯誤 Charge/Invoice，影響對帳與家長通知可信度。

---

## 2. 偵測查詢（唯讀）

### 2.1 主查詢 — #190 簽章合約

```sql
SELECT sc.ID, sc.StudentID, st.name AS student_name, st.CampusID,
       sc.ScheduleMode, sc.SessionCount, sc.Charge, sc.Rate,
       sc.StartDate, sc.EndDate, sc.week, sc.week1, sc.time, sc.time1,
       sc.settlement_day, sc.Stop, sc.MDate,
       (SELECT COUNT(*) FROM ClassSession cs
        WHERE cs.StudentClassID = sc.ID
          AND cs.Status NOT IN ('cancelled')) AS actual_sessions
FROM StudentClass sc
JOIN Student st ON st.id = sc.StudentID
WHERE sc.ScheduleMode = 'date'
  AND (sc.week IN (0, 7)
    OR sc.week1 IN (0, 7) OR sc.week2 IN (0, 7) OR sc.week3 IN (0, 7)
    OR sc.week4 IN (0, 7) OR sc.week5 IN (0, 7) OR sc.week6 IN (0, 7))
  AND sc.SessionCount = 0
  AND sc.Charge = 0
  AND sc.StartDate >= '2026-01-01'
ORDER BY sc.StartDate;
```

### 2.2 SessionCount / 實際堂次不一致（擴展清查）

```sql
SELECT sc.ID, st.name, sc.StartDate, sc.EndDate,
       sc.SessionCount, sc.Charge, sc.Rate, sc.week, sc.time,
       COUNT(cs.id) AS actual_sessions
FROM StudentClass sc
JOIN Student st ON st.id = sc.StudentID
LEFT JOIN ClassSession cs ON cs.StudentClassID = sc.ID
  AND cs.Status NOT IN ('cancelled')
WHERE sc.ScheduleMode = 'date'
  AND (sc.week IN (0,7) OR sc.week1 IN (0,7) OR sc.week2 IN (0,7)
    OR sc.week3 IN (0,7) OR sc.week4 IN (0,7) OR sc.week5 IN (0,7) OR sc.week6 IN (0,7))
  AND sc.StartDate >= '2026-01-01'
GROUP BY sc.ID, st.name, sc.StartDate, sc.EndDate,
         sc.SessionCount, sc.Charge, sc.Rate, sc.week, sc.time
HAVING (sc.SessionCount = 0 AND actual_sessions > 0)
    OR (sc.Charge = 0 AND sc.Rate > 0 AND actual_sessions > 0);
```

### 2.3 關聯 Invoice

```sql
SELECT i.id, i.StudentClassID, st.name, i.billing_period,
       i.TotalAmount, i.PaidAmount, i.Status, i.reconciled_at
FROM Invoice i
JOIN StudentClass sc ON sc.ID = i.StudentClassID
JOIN Student st ON st.id = sc.StudentID
WHERE i.StudentClassID IN (
  /* 代入 2.1 結果的 SC ID 清單 */
  1695, 1696, 2026, 2027, 1331, 1539
);
```

### 2.4 Pi 唯讀執行方式

```bash
# ⛔ 禁止在 Pi 跑 php artisan test
ssh admin@pi.lifenet.com.tw 'cd /home/admin/backend && php artisan tinker --execute="..."'
```

---

## 3. 修復策略比較

| 策略 | 說明 | 優點 | 風險 |
|------|------|------|------|
| **A. 重算 SessionCount + Charge** | 用**已修** `buildSessionsFromWeeklySchedule` 對每筆 SC 重算期內應有堂次，寫回 `SessionCount`、`Charge`、`RemainingSessions` | 與現行程式一致；可審計 | 需確認 Rate 與月結規則（partial month） |
| **B. 僅修正 Invoice** | 不動 SC，只改 Invoice `TotalAmount` | 改動小 | SC 與帳單仍不一致；評量/堂數口徑仍錯 |
| **C. 作廢 SC + 手動開新約** | 業務重開課程 | 最乾淨 | 工作量大；歷史斷層 |
| **D. 僅帳務備註** | Note 標記，不改數字 | 零風險 | 不解決系統口徑 |

**推薦**：策略 **A** + 選擇性 **B**（Invoice 金額對齊重算後 Charge）。

### 3.1 洪子勛（優先）

1. 對 SC 1695/1696/2026/2027 以修復後演算法重算 `SessionCount`、`Charge`
2. 更新 Invoice #690/#691 `TotalAmount`（若主任確認尚未線下收款）
3. 與主任確認 6 月是否已手動收款或調整為 2998/3000 — **避免雙重收費**
4. `reconciled_at` 已標記的 Invoice 需帳務確認是否重開

### 3.2 陳顥昀、周允妍

- 無 NT$0 Invoice 紀錄；修復 SC 欄位 + 必要時補開 Invoice（**人工帳務流程，腳本不自動開帳**）

---

## 4. Migration / 修復腳本（草稿）

> 建議路徑：`backend/app/Console/Commands/RepairSundayMonthlyBilling.php`  
> **尚未實作** — 僅規格。

### 4.1 介面

```bash
# 唯讀預覽（必須先跑）
php artisan repair:sunday-monthly-billing --dry-run
php artisan repair:sunday-monthly-billing --dry-run --student-class=2026

# 執行（需 --force + 環境確認）
php artisan repair:sunday-monthly-billing --force --student-class=2026,2027
```

### 4.2 Dry-run 預期輸出（範例）

```
CASE sc_id=2026 student=洪子勛 period=2026-06
  BEFORE: SessionCount=0 Charge=0 actual_sessions=4
  AFTER:  SessionCount=4 Charge=4000 (rate=1000)
  INVOICE id=690: TotalAmount 0 → 4000 [REVIEW]
  ACTION: UPDATE StudentClass; UPDATE Invoice (if --apply-invoices)
```

### 4.3 安全要求

| 要求 | 說明 |
|------|------|
| **備份** | `mysqldump` 全庫（`DANGEROUS_OPERATIONS.md`） |
| **Transaction** | 每學生/每期一包 `DB::transaction()` |
| **Dry run** | 預設 `--dry-run` |
| **快照** | `storage/app/repair-snapshots/190-{timestamp}.json` |
| **環境鎖** | production 需 `ALLOW_PROD_REPAIR=1` |
| **帳務閘** | `--apply-invoices` 為獨立 flag，預設不動 Invoice |

### 4.4 回滾

1. 從備份 SQL 還原 `StudentClass` + `Invoice` 受影響列
2. `php artisan repair:sunday-monthly-billing --rollback --snapshot=<file>`

---

## 5. 核准清單（CEO / 主任）

- [ ] 已閱讀 §1–§3
- [ ] Dry run 輸出已人工核對
- [ ] 已做 DB 備份（路徑：________）
- [ ] 洪子勛 6 月帳務已與主任確認（避免雙收）
- [ ] 同意重算 6 筆 SC 的 SessionCount/Charge
- [ ] 同意是否修正 Invoice #690/#691

---

## 6. 預防

- 程式：PR #1108 + `WeeklyScheduleSundayBuilderTest` / `MonthlyRenewTest`（已上線）
- 文件：`AI_REGRESSION_LESSONS.md` §R64
- 監控（建議）：夜間唯讀查詢 §2.1，結果 > 0 則 alert（#1081 reproduction gate 家族）

---

## 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-08 | 初版：production 唯讀 audit（6 筆簽章合約、2 張 NT$0 Invoice） |
