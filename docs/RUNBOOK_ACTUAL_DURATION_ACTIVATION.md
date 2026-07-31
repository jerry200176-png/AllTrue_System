---
owner: platform
review_cycle: quarterly
last_reviewed: 2026-07-31
---

# RUNBOOK — 依實際時長扣堂：上線啟用與回滾

> **REFERENCE ONLY — NO DECISION OR EXECUTION AUTHORITY.**
> 產品契約：[`docs/architecture/RFC_NONSTANDARD_SESSION_DURATION_BILLING.md`](architecture/RFC_NONSTANDARD_SESSION_DURATION_BILLING.md)。
> 決策：Founder。執行：`deploy.yml`。回滾通則：[`RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md)。
>
> **本文件撰寫時，功能已合併進 main，但尚未在正式環境啟用。**
> 沒有執行過任何正式環境部署、migration、旗標開啟、測試課程建立或資料修復。

---

## 0. 這個功能到底改了什麼（一段話）

每一門課可以自己定義「標準一堂 = 幾分鐘」，點名時依**實際上課時長**按比例扣除額度。
「一堂」是**該課程自己的**定義，不是全公司的規定：同樣一次 180 分鐘的課，在標準 90 / 120 / 180 分鐘的課程裡分別扣 **2.00 / 1.50 / 1.00** 堂。
沒有選擇這個模式的課程，行為完全沒有改變。

---

## 1. 兩個開關，缺一不可

| 開關 | 位置 | 意義 | 預設 |
|---|---|---|---|
| `PERF_ACTUAL_DURATION_DEDUCTION` | Pi `/home/admin/.env` → `config/perfflags.php` | 這個環境有沒有這個功能 | **false** |
| `StudentClass.deduction_basis` | 每門課一列 | 這門課要不要用 | `fixed_session` |
| `ACTUAL_DURATION_DEDUCTION_ENABLED` | `frontend/src/lib/perfFlags.js`（編譯期） | 建課表單要不要顯示這個選項 | **false** |

**Fail-safe，不是 fail-open。** 環境旗標為 false 時，就算某門課已經被標記成 `actual_duration`，它的扣堂行為仍然**完全等同** `fixed_session`。因此關掉旗標就是完整回滾，不需要任何資料遷移，也沒有半套狀態要清。

建課 API 也受環境旗標管制：旗標關閉時，API 會直接 422 拒絕建立 `actual_duration` 課程，而不是建立一門「契約寫著按時長計費、實際卻整堂扣」的課。

---

## 2. 啟用前必須先看的東西（Phase 0A 盤點）

**先跑唯讀盤點，再決定要不要開。** 這個指令不寫任何資料：

```bash
# 在 Pi 上唯讀執行（⛔ 不要加任何 --fix 之類的旗標，它也沒有）
php artisan scheduling:nonstandard-duration-inventory
```

輸出會列出目前排課時長與課程計價單位不一致的課程數量、分校分布，以及已發生（happened）與尚未發生（planned）的分別。

判讀：
- 數量為 0 → 沒有現存受影響課程，啟用風險最低（只影響之後新建的課）。
- 數量不為 0 → 這些是**現存的**不一致，**不會**被這次啟用自動改變（既有課程一律維持 `fixed_session`）。它們是另一個題目，不是這次啟用的前置條件，但 Founder 應該知道規模。

---

## 3. 啟用步驟（尚未執行）

> ⛔ 以下每一步都需要 Founder 明確授權。R2/R5/R6 紅線仍然適用：不在 Pi 跑測試、不直接 SSH 改程式碼、migration 只在 PR merge 後執行。

1. **確認 migration 已套用。**
   `standard_lesson_minutes`（nullable int）與 `deduction_basis`（varchar(32)，預設 `fixed_session`）。
   兩者皆為 additive、nullable/有預設，套用時不重寫既有列的語意。
   ```bash
   php artisan migrate --force        # PR merge 後才可執行（R5）
   ```

2. **先只開後端旗標，前端仍關。**
   在 `/home/admin/.env` 加入：
   ```
   PERF_ACTUAL_DURATION_DEDUCTION=true
   ```
   ⛔ **不要**在 Pi 執行 `php artisan config:clear`（事故 B）。走 `deploy.yml` 的正常流程讓設定生效。

   此時：後端引擎已就緒，但沒有任何課程是 `actual_duration`，所以**行為上什麼都不會變**。這一步是為了讓引擎先在正式環境待命，而不是引擎與課程同時出現。

3. **驗證什麼都沒變。**
   - `GET /api/v1/health` 為 `ok`
   - 任選一門既有課程點名一次，確認扣堂數字與啟用前一致
   - `session_deduction_ledger` 沒有出現非預期的 `minutes` 值

4. **開前端旗標。**
   `frontend/src/lib/perfFlags.js` 的 `ACTUAL_DURATION_DEDUCTION_ENABLED` 改為 `true` → PR → CI 全綠 → merge → 等 `deploy.yml` → 驗 `version.json`（Y3）。
   此時建課表單才會出現「扣堂方式」選項。

5. **第一門課由 Founder 指定，並且全程有人看著。**
   建課後立即比對：
   - 課程列表顯示的 `remaining_lesson_equivalent` 與 `remaining_hours`
   - 第一次點名後 `RemainingMinutes` 的變化量 = 該次實際分鐘數
   - `session_deduction_ledger.minutes` 記到的是分鐘，不是堂數

---

## 4. 回滾

**回滾 = 把環境旗標關掉。** 沒有資料要還原。

```
PERF_ACTUAL_DURATION_DEDUCTION=false
```

關掉之後：
- 已經標記為 `actual_duration` 的課程，扣堂行為立刻回到 `fixed_session`
- 已經寫入 ledger 的分鐘數**不會**被改寫（那是歷史事實，不是要清理的髒資料）
- 建課 API 會開始拒絕新的 `actual_duration` 課程

如果需要連程式碼一起退掉，走 [`RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md) §3a 的 revert PR 路徑。migration 本身是 additive 的，留著不會影響任何既有行為；**不建議**為了回滾而 drop 欄位（會讓已建立的課程失去契約紀錄）。

---

## 5. 已知限制（v1 刻意不做）

| 項目 | 狀態 | 原因 |
|---|---|---|
| 跨期借用／自動拆堂 | 不做 | D3。額度用完就是用完，不會回頭從別的期間補 |
| 共用課程包（CoursePackage） | 雙向排除，422 明確拒絕 | D4。共用池 ledger 只能表示整堂（TD-059） |
| 月結制課程 | 尚不支援，422 拒絕 | 月結的額度語意還沒定義 |
| 扣堂後修改契約 | **不提供任何修正管道** | 額度仍由 `SessionCount × standard_lesson_minutes` 推導，事後改標準堂長會**重新解釋歷史**。宣稱「只影響未來」會是假保證。要改就結掉重開 |
| 額度授予 ledger | 延後 | D7 |

第一筆扣堂 ledger 寫入後，`standard_lesson_minutes`、`deduction_basis`、`SessionCount` 由 `BillingContractLockGuard` 在**後端**鎖定。前端把欄位變灰只是 UX，後端才是權威。

---

## 6. 監看什麼

| 訊號 | 正常 | 不正常時怎麼辦 |
|---|---|---|
| `RemainingMinutes` 為負 | 不應發生（floor 在 0） | 立刻關旗標，保留現場，開 issue |
| 點名被擋 | **不應發生** | D5 明訂超額不擋點名。若被擋 = bug，關旗標 |
| `remaining_lesson_equivalent` 與 `RemainingMinutes` 不一致 | 不應發生 | 兩者同源，不一致代表換算有問題 |
| 建課 422 `overage_confirmation_required` | 正常，這是設計 | 老師勾確認即可繼續 |

---

## 7. 相關文件

- 產品契約與決策 D1–D7：[`architecture/RFC_NONSTANDARD_SESSION_DURATION_BILLING.md`](architecture/RFC_NONSTANDARD_SESSION_DURATION_BILLING.md)
- 回滾通則：[`RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md)
- 危險操作紅線：[`DANGEROUS_OPERATIONS.md`](DANGEROUS_OPERATIONS.md)
- 部署流程：[`DEPLOYMENT.md`](DEPLOYMENT.md)
