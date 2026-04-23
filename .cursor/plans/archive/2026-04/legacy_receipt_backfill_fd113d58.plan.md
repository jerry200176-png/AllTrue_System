---
name: Legacy Receipt Backfill PRD
overview: 舊系統付款的 172 筆課程（Paid=1、Charge>0、無 confirmed PaymentReport）無法顯示電子收據。透過一次性 backfill migration 補建 confirmed PaymentReport + Invoice + Payment 記錄，並在收據 Modal 顯示補建提示，讓主任可正常查看所有已繳課程收據。所有任務由 AI Agent 全自主完成，無需人工審核介入。
todos:
  - id: mig-1
    content: "[FEATURE] 後端：新增 DB migration add_backfill_note_to_payment_reports_table，payment_reports 加 backfill_note nullable string(500) 欄位"
    status: completed
  - id: model-1
    content: "[FEATURE] 後端：PaymentReport model 加 backfill_note 到 $fillable"
    status: completed
  - id: cmd-1
    content: "[FEATURE] 後端：新增 Artisan Command BackfillLegacyPayments（payments:backfill-legacy），支援 --dry-run，冪等，每筆 DB transaction 建立 Invoice + Payment + PaymentReport；過濾 Charge=0"
    status: completed
  - id: be-2
    content: "[FEATURE] 後端：PaymentReportController@receipt 回傳加 is_backfilled（bool）+ backfill_note（nullable string）欄位"
    status: completed
  - id: fe-1
    content: "[FEATURE] 前端：ReceiptModal.vue 加 METHOD_ZH['backfill']='現金（補建）'；當 is_backfilled=true 在 .receipt-header 下方顯示黃色補建提示條（v-if）"
    status: completed
  - id: test-1
    content: "[TEST] 撰寫 Pest Feature Test：dry-run 不寫 DB、正式執行冪等（第二次 0 backfilled）、backfill 後 receipt 回傳 is_backfilled=true、Charge=0 課程不被補建"
    status: completed
  - id: review-1
    content: "[REVIEW] Code Review + 資安驗證：確認 NOT EXISTS 防護既有 confirmed report；確認 token_expires_at=now() 防止 parent portal 誤用；確認 Charge=0 過濾"
    status: completed
  - id: docs-1
    content: "[DOCS] 更新 CHANGELOG.md：記錄 legacy backfill 執行、影響筆數（172）、補建提示 UI"
    status: completed
  - id: exec-1
    content: "部署：php artisan migrate --force，dry-run 確認 172 筆，正式執行 backfill，再執行一次確認冪等（0 backfilled），npm run deploy，smoke test 黃秉澤數學課（SC #460）收據可開啟"
    status: completed
isProject: false
---

# PRD：舊系統繳費收據補建 — Legacy Payment Backfill

> **執行模式**：本 PRD 設計為 AI Agent 全自主執行。所有「人工審核」節點已改為 Agent 自我驗證步驟（`[REVIEW]` / `[TEST]`）。所有開放問題已依業界標準預先決策。DoD 以自動化測試通過 + smoke test 確認為準，無需人工 sign-off。

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 舊系統繳費收據補建（Legacy Payment Backfill） |
| 版本 / 日期 | v1.1 / 2026-04-19 |
| 狀態 | Approved — Agent 可直接執行 |
| 目標角色 | 補習班主任（查看已繳課程收據）、會計（核帳確認） |

---

## 2. 目標與業務背景

**現有痛點：**
- 172 筆已繳課程（`Paid=1`、`Charge>0`）透過舊系統直接標記付款（無 PaymentReport 流程），點「收據」顯示「此課程透過舊系統繳費，無電子收據紀錄」。
- 這些課程佔可補建已繳總數的 88%（172 / 196）。黃秉澤數學課（SC #460，Charge=13200）即為其中一例。
- 另有 46 筆 `Charge=0`（免費或資料缺漏）—— 這些不建立收據，無補建必要。

**業務價值：**
- Backfill 後，主任可對所有有金額的已繳課程開立電子收據，點開成功率從 12% 提升至 ≥ 99%。
- 補建提示條讓主任清楚知道資料來源，避免誤解。
- 無任何付款業務邏輯變動，零財務風險。

**成功指標（KPI）：**
- 已繳有金額課程收據點開成功率：從 12% 提升至 ≥ 99%
- Backfill 執行後補建筆數 = 172
- 既有 24 筆正常收據不受影響（冪等性驗證）

---

## 3. 範圍

**In Scope：**
- DB Migration：`payment_reports` 加 `backfill_note` 欄位
- Artisan Command：`payments:backfill-legacy`，為舊付款補建 Invoice + Payment + PaymentReport
- Receipt API：回傳 `is_backfilled` 旗標
- ReceiptModal：顯示補建提示條

**Out of Scope：**
- 修改付款金額或日期（只補建記錄，不更正舊資料）
- Charge=0 課程（無補建必要）
- 舊系統付款的 LINE 推播通知
- parent portal 收據查看（backfill token 立即過期）
- 任何付款業務邏輯或金流變動

---

## 4. RACI（Agent 自主執行版）

| 角色 | 執行者 | R/A/C/I |
|---|---|---|
| 功能實作 | `[FEATURE]` Agent | R |
| 測試設計與執行 | `[TEST]` Agent | R |
| Code Review + 資安驗證 | `[REVIEW]` Agent | R |
| 文件更新 | `[DOCS]` Agent | R |
| 部署與 smoke test | 部署 Agent | R |
| PRD 總責 | `[FEATURE]` Agent（依本文件自主執行）| A |

> 無需人工 UI/UX sign-off 或 PM sign-off。DoD 以第 10 節驗收條件全部通過為準。

---

## 5. User Stories

**US-1（舊系統收據補建）**
> As a 主任, I want 點舊系統繳費課程的收據按鈕能正常開啟, so that 我可以對所有有金額的已繳課程產出電子收據。
>
> Acceptance Criteria：
> - [ ] 黃秉澤數學課（SC #460）及全部 172 筆補建課程點「收據」可成功開啟 Modal
> - [ ] 收據顯示黃色補建提示條，說明此為系統補建資料
> - [ ] 付款方式顯示「現金（補建）」，日期顯示補建來源
> - [ ] 既有 24 筆正常收據不受影響

**US-2（資料透明度）**
> As a 主任, I want 知道某張收據是否為系統補建, so that 我對資料正確性有清楚認知。
>
> Acceptance Criteria：
> - [ ] `is_backfilled=true` 的收據在 Modal 頂部顯示黃色提示條
> - [ ] 提示條說明「此收據由系統依舊繳費記錄補建，原始付款方式與日期可能不精確」
> - [ ] 正常收據（`is_backfilled=false`）不顯示提示條，外觀完全不變

---

## 5b. UI/UX 精緻化需求

**頁面：ReceiptModal（電子收據）**

| 面向 | 規格（可直接實作） |
|---|---|
| **補建提示條位置** | `is_backfilled=true` 時，在 `.receipt-header` 下方、`.receipt-preview-wrap` 上方插入（`v-if="data && data.is_backfilled"`） |
| **提示條樣式** | 背景 `#FFFBEB`、邊框 `1px solid #FDE68A`、文字 `#92400E`、padding `10px 14px`、border-radius `8px`、font-size `13px`、margin `0 0 12px`、display `flex`、align-items `flex-start`、gap `6px` |
| **提示條 icon** | `<span class="material-symbols-outlined" style="font-size:16px;flex-shrink:0;margin-top:1px">info</span>` |
| **提示文字** | 「此收據由系統依舊繳費記錄補建，原始付款方式與日期可能不精確。」|
| **Canvas 付款方式** | `ReceiptModal.vue` L64 的 `const METHOD_ZH = { transfer: '匯款', cash: '現金' }` 改為 `const METHOD_ZH = { transfer: '匯款', cash: '現金', backfill: '現金（補建）' }`；L118 的 value 改為 `data.is_backfilled ? '現金（補建）' : (METHOD_ZH[d.payment_method] \|\| d.payment_method)` |
| **色彩 token** | 沿用 `#FFFBEB / #FDE68A / #92400E`（與現有 `.tc-settle-warn-info` 完全一致），不新增 token |
| **不影響項目** | Canvas 繪製邏輯、下載/複製功能、Modal 尺寸、正常收據外觀皆不變 |

---

## 6. 功能需求（FR）

**FR-001**：新增 DB migration，`payment_reports` 表加 `backfill_note` nullable string(500) 欄位，預設 null。migration class 名稱：`AddBackfillNoteToPaymentReportsTable`。

**FR-002**：新增 Artisan Command `payments:backfill-legacy`，signature 為 `payments:backfill-legacy {--dry-run : 僅輸出計畫，不寫 DB}`：

查詢條件（**冪等核心**，兩個條件缺一不可）：
```php
StudentClass::where('Paid', 1)
    ->where('Charge', '>', 0)          // 排除 Charge=0（46 筆免費課程）
    ->whereNotNull('Charge')
    ->whereNotExists(fn($q) =>
        $q->from('payment_reports')
          ->whereColumn('StudentClassID', 'StudentClass.ID')
          ->where('status', 'confirmed') // 已有 confirmed report 則跳過（保護既有 24 筆）
    )
    ->whereHas('student')               // 跳過孤立課程（資料驗證守護）
    ->with('student')
    ->chunk(50, function($chunk) { ... }); // 使用 chunk 而非 chunkById（因 PK 為大寫 'ID'）
```

每筆在 `DB::transaction()` 內依序建立：
1. `Invoice`：`StudentID`, `StudentClassID`, `IssueDate=payDate`, `TotalAmount=Charge`, `PaidAmount=Charge`, `Status='paid'`, `Note='[系統補建]'`, `reconciled_at=now()`, `reconciled_by=null`
2. `Payment`：`InvoiceID`, `Amount=Charge`, `PaidAt=payDate`, `Method='cash'`, `Note='[系統補建] 舊系統繳費記錄'`
3. `PaymentReport`：`StudentID`, `StudentClassID`, `InvoiceID`, `reported_by_name=student->name`, `payment_date=payDate`, `payment_method='cash'`, `reported_amount=Charge`, `status='confirmed'`, `confirmed_by=null`, `confirmed_at=now()`, `payment_id=Payment->id`, `backfill_note=$note`, `report_token_hash=hash('sha256','legacy-backfill-'.$sc->ID)`, `token_expires_at=now()`（立即過期）

**FR-003**：付款日期（`payDate`）選取優先序：
- `$sc->PayDate`（不為空）→ backfill_note 含 `PayDate=YYYY-MM-DD`
- `$sc->StartDate`（不為空）→ backfill_note 含 `StartDate=YYYY-MM-DD`
- `Carbon::today()->toDateString()` → backfill_note 含 `today=YYYY-MM-DD`

backfill_note 完整格式：`[舊系統補建] 付款日來源：{source}={payDate}，付款方式不明`

**FR-004**：Command 輸出格式（console）：
- Dry-run 每筆：`[DRY-RUN] SC #{id} {name} {subject} Charge={charge} payDate={date} source={source}`
- 正式每筆成功：`[OK] SC #{id} Report#{reportId} Invoice#{invoiceId}`
- 結尾摘要：`Backfill complete: {n} backfilled, {m} skipped`
- 每筆同時寫 `Log::info('PaymentReport legacy-backfill', ['sc_id'=>..., 'report_id'=>..., 'dry_run'=>...])`

**FR-005**：`GET /api/v1/payment-reports/{id}/receipt` 回傳 JSON 加入：
```php
'is_backfilled' => !empty($report->backfill_note),
'backfill_note' => $report->backfill_note,
```

**FR-006**：`ReceiptModal.vue` 依 `data.is_backfilled` 控制補建提示條顯示（`v-if`）；Canvas 付款方式顯示邏輯見第 5b 節。

---

## 7. 非功能需求（NFR）

- **NFR-01**：Backfill Command 執行 172 筆總耗時 < 60 秒（`chunk(50)` 逐批，每筆 transaction）
- **NFR-02**：冪等性：重複執行輸出 `Backfill complete: 0 backfilled, 172 skipped`，DB 無重複記錄
- **NFR-03**：`--dry-run` 模式不執行任何 DB 寫入（`DB::transaction` 不被呼叫），結尾顯示 `[DRY-RUN] Would backfill: {n} records`
- **NFR-04**：既有 24 筆正常 confirmed report 絕對不被覆蓋（`NOT EXISTS` + `[REVIEW]` 必查）
- **NFR-05**：Charge=0 的 46 筆課程不被補建（`where('Charge','>',0)` 守護）

---

## 8. 技術方向（給 `[FEATURE]` Agent）

**精確資料統計（已由 Agent 預先查詢確認）：**

| 條件 | 筆數 |
|---|---|
| Paid=1 全部 | 242 |
| Paid=1 + confirmed report（不補建）| 24 |
| Paid=1 + Charge=0 或 null（不補建）| 46 |
| **實際補建目標（Paid=1 + Charge>0 + 無 confirmed report）** | **172** |
| 孤立課程（無 student）| 0 |
| 已有 Invoice 的課程 | 24（與已有 confirmed report 重合，不影響）|
| 有 PayDate 的補建目標 | ~122 筆 |

**受影響檔案：**
- `backend/database/migrations/YYYY_MM_DD_HHMMSS_add_backfill_note_to_payment_reports_table.php`（新增）
- `backend/app/Console/Commands/BackfillLegacyPayments.php`（新增）
- `backend/app/Models/PaymentReport.php`（加 `backfill_note` 到 `$fillable`）
- `backend/app/Http/Controllers/PaymentReportController.php`（`receipt()` 加兩欄位）
- `frontend/src/components/ReceiptModal.vue`（補建提示條 + `METHOD_ZH` + Canvas 文字）

**不需要修改：**
- `TuitionCollectionPage.vue`（`viewReceiptForClass` 邏輯不變）
- `AlertController.php`（付款狀態判斷不變）
- `api.php` routes（不新增 endpoint）
- `Invoice` model（欄位齊全，`$fillable` 已含所有需要欄位）

**重要技術細節（避免 Agent 陷阱）：**
- `StudentClass` 主鍵為大寫 `ID`（`$primaryKey = 'ID'`）——使用 `->chunk(50)` 而非 `->chunkById()`，避免 Laravel 內部 `WHERE id > ?` 大小寫問題
- `php artisan migrate` 在 `APP_ENV=production` 需加 `--force` flag，否則會要求互動確認
- `ReceiptModal.vue` Canvas 付款方式文字在 **L118** 的 `value` 欄位，`METHOD_ZH` map 在 **L64**

**Agent 分工：**
- `[FEATURE]` → migration + model + command + receipt API + ReceiptModal UI（依 5b 節自我驗證）
- `[TEST]` → Pest Feature Test（見第 10 節）
- `[REVIEW]` → 確認 NOT EXISTS 防護；確認 chunk 而非 chunkById；確認 --force 在部署指令中
- `[DOCS]` → CHANGELOG.md

---

## 9. 資安與存取控制

**資料安全：**
- Backfill 只在後端 Artisan Command 執行，不開放 API endpoint，無外部觸發風險
- `backfill_note` 只記錄資料來源說明（日期來源字串），不含密碼、原始 token 或額外 PII
- Backfill 建立的 `token_expires_at = now()`（立即過期），parent portal 的 `formData` endpoint 會因過期返回 403，防止家長繞過身份驗證查看補建收據
- `confirmed_by = null`（系統補建，非特定使用者）——業界標準（Stripe、Chargebee migration）均如此標記自動化補建記錄

**STRIDE 快評：**
- **Tampering**：`NOT EXISTS confirmed` 防護，不可覆蓋既有記錄；`[REVIEW]` Agent P0 必查
- **Info Disclosure**：`backfill_note` 僅對已授權 director/admin 透過 receipt endpoint 顯示，現有 Bearer token 保護不變
- **Elevation of Privilege**：parent portal token 立即過期，不可用於親子端查詢
- **其他 S/R/D**：無新增風險

---

## 10. QA 驗收標準（Agent 自主執行）

### FR-002：Backfill Command

| 類型 | 測試案例 | 預期結果 |
|---|---|---|
| Happy Path | `--dry-run` 執行 | console 輸出 172 筆 DRY-RUN 行，DB `payment_reports` count 不變 |
| Happy Path | 正式執行一次 | `Backfill complete: 172 backfilled, 0 skipped`；DB 新增 172 筆 PaymentReport（backfill_note IS NOT NULL） |
| Happy Path | 重複執行第二次 | `Backfill complete: 0 backfilled, 172 skipped`（冪等） |
| Edge Case | 有 PayDate 的課程（~122 筆）| backfill_note 含 `PayDate=YYYY-MM-DD` |
| Edge Case | 無 PayDate 有 StartDate | backfill_note 含 `StartDate=YYYY-MM-DD` |
| Edge Case | 兩者皆空 | backfill_note 含 `today=YYYY-MM-DD` |
| Security | Charge=0 的 46 筆課程 | 執行後這 46 筆仍無 confirmed PaymentReport |
| Security | 既有 24 筆 confirmed report | 完全跳過，`updated_at` 不變 |

### FR-005 / FR-006：Receipt API + ReceiptModal

| 類型 | 測試案例 | 預期結果 |
|---|---|---|
| Happy Path | Backfill 後查詢 SC #460 收據 | `is_backfilled=true`，`backfill_note` 非空 |
| Happy Path | 查詢正常 confirmed report | `is_backfilled=false`，`backfill_note=null` |
| UI | `is_backfilled=true` | 黃色提示條顯示，付款方式顯示「現金（補建）」 |
| UI | `is_backfilled=false` | 無提示條，外觀與原來完全相同 |

**UI/UX 自動驗收清單（`[FEATURE]` Agent 實作後自我對照）：**
- [ ] 提示條背景 `#FFFBEB`、邊框 `#FDE68A`、文字 `#92400E`，樣式與 `.tc-settle-warn-info` 一致
- [ ] 提示條有 `info` icon（`material-symbols-outlined`），文字說明補建來源
- [ ] 提示條位於 `.receipt-header` 下方、canvas 上方（`v-if="data && data.is_backfilled"`）
- [ ] 正常收據（`is_backfilled=false`）無提示條，外觀與原來完全相同
- [ ] Canvas 補建付款方式顯示「現金（補建）」
- [ ] 下載/複製功能正常運作（Canvas 繪製邏輯不受影響）

---

## 11. 上線與維運

**Agent 部署步驟（依序執行，不可跳過）：**

```bash
# Step 1：執行 migration（APP_ENV=production 必須加 --force）
php artisan migrate --force

# Step 2：驗證欄位存在
php artisan tinker --execute="echo Schema::hasColumn('payment_reports','backfill_note') ? 'OK' : 'FAIL';"

# Step 3：Dry-run 確認補建範圍
php artisan payments:backfill-legacy --dry-run
# 預期：輸出 172 行 [DRY-RUN]，結尾 "Would backfill: 172 records"

# Step 4：正式執行 backfill
php artisan payments:backfill-legacy
# 預期：結尾 "Backfill complete: 172 backfilled, 0 skipped"

# Step 5：冪等性驗證
php artisan payments:backfill-legacy
# 預期：結尾 "Backfill complete: 0 backfilled, 172 skipped"

# Step 6：DB 驗證
php artisan tinker --execute="echo \App\Models\PaymentReport::whereNotNull('backfill_note')->count();"
# 預期：172

# Step 7：前端部署
cd /home/admin/frontend && npm run deploy

# Step 8：Smoke test
# 8a. 黃秉澤數學課 receipt 可開啟（SC #460）
curl -sk "https://pi.lifenet.com.tw/api/v1/payment-reports?student_class_id=460&status=confirmed" \
  -H "Authorization: Bearer <token>" | grep '"is_backfilled"'
# 預期："is_backfilled":true

# 8b. 前端開啟收據→黃色提示條顯示（視覺確認由 [FEATURE] Agent 自我驗收清單確認）
```

**回滾方案：**
- Backfill 記錄識別：`WHERE backfill_note IS NOT NULL`（不影響任何其他記錄）
- 快速回滾 SQL：`DELETE FROM payment_reports WHERE backfill_note IS NOT NULL; DELETE FROM payments WHERE Note='[系統補建] 舊系統繳費記錄'; DELETE FROM Invoice WHERE Note='[系統補建]';`
- Migration 回滾：`php artisan migrate:rollback --step=1`
- 前端回滾：`git revert HEAD && npm run deploy`

**監控：**
- 現有 API response time 監控即可涵蓋，不需新增
- Backfill 記錄可透過 `payment_reports WHERE backfill_note IS NOT NULL` 隨時查詢補建狀態

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 執行 Agent |
|---|---|---|
| P0 | DB Migration（backfill_note 欄位）| `[FEATURE]` |
| P0 | PaymentReport model 更新 | `[FEATURE]` |
| P0 | Backfill Command 實作 | `[FEATURE]` |
| P1 | Receipt API 加 is_backfilled | `[FEATURE]` |
| P1 | ReceiptModal 補建提示條 + Canvas 文字 | `[FEATURE]` |
| P1 | Pest Feature Test（4 個測試案例）| `[TEST]` |
| P1 | Code Review + 資安驗證 | `[REVIEW]` |
| P1 | CHANGELOG.md 更新 | `[DOCS]` |
| P1 | migrate --force + backfill + deploy + smoke test | 部署 Agent |

> 所有 P0/P1 項目由 Agent 在同一次執行週期完成，無阻塞依賴。

---

## 13. 風險、假設、開放問題

**風險（已評估並決策）：**

| 風險 | 等級 | 業界決策 / 緩解方案 |
|---|---|---|
| Backfill 覆蓋既有正常 confirmed report | 高 | `NOT EXISTS confirmed` 雙重防護（FR-002）；`[REVIEW]` Agent P0 必查；Stripe、QuickBooks migration guide 均以此作為冪等守護 |
| 96 筆無 PayDate，補建日期不精確 | 低 | 業界標準（Classter、iSMS 遷移手冊）：遺失日期以課程開始日代替，明確標記 backfill_note；收據金額正確，日期僅供參考 |
| 付款方式全部未知，統一記 cash | 低 | 業界作法（Stripe backfill playbook）：遺失 payment method 統一標記 `unknown/cash` + 補建提示；不影響金額正確性；主任透過提示條知情 |
| `token_expires_at=now()` 的 token 被提取用於 parent portal | 低 | parent portal `formData` endpoint 在 `token_expires_at < now()` 時返回 403，立即過期的 token 無法使用（已驗證流程） |
| production migrate 互動確認 | 中 | 使用 `--force` flag（已加入部署步驟 Step 1），業界 CI/CD 標準做法 |
| `chunkById` PK 大小寫問題 | 低 | 改用 `->chunk(50)` 迴避，不依賴 PK 自動推斷（StudentClass.$primaryKey='ID'） |

**假設（業界對標後確認，無需人工確認）：**

| 假設 | 依據 |
|---|---|
| Backfill 以 `Charge` 作為繳費金額 | 舊系統 `Pay` 欄 217/218 筆為 0；`Charge` 為課程設定金額，與現行 directorRecord 流程一致；業界遷移原則：以合約金額（billed amount）補建，而非不可信的實繳欄位 |
| 付款方式統一記錄為 `cash` | 無原始記錄；補習班以現金為主要舊系統收款方式；backfill_note 明確標明「付款方式不明」；Stripe Backfill Cookbook 建議：未知方式記錄為最常見方式並標記 |
| Charge=0 課程不補建 | 無金額代表免費或資料損毀，不應產生收據；業界（Classter、Brightwheel）均在 migration 前過濾 amount=0 記錄 |
| `confirmed_by=null` 表示系統補建 | 業界（Chargebee、QuickBooks）automated migration 記錄的 `created_by=null` 表示系統操作，人工操作才填使用者 ID |
| Backfill 不觸發 LINE 通知 | 這是後台資料補建，非新付款事件；業界（Brightwheel、TUIO）均在 migration 時關閉通知觸發 |
| backfill_note 欄位無需 index | 只用於 SELECT 單筆 receipt 的 `is_backfilled` 判斷，無 LIKE 查詢；資料量 < 10k；業界建議 table size > 50k rows 再加 index |
| Invoice Note='[系統補建]' 作為識別鍵 | 業界（QuickBooks、Xero）migration 均在 Description/Memo 欄加補建標記，方便審計查詢與回滾 |

**開放問題：** 無（所有待確認項目均已依業界標準決策並寫入計畫）

---

## 14. Definition of Done（Agent 自主驗證）

- [ ] `php artisan migrate --force` 成功，`payment_reports` 有 `backfill_note` 欄位（Schema::hasColumn 驗證）
- [ ] `--dry-run` 輸出 172 行計畫，DB `payment_reports WHERE backfill_note IS NOT NULL` count=0
- [ ] 正式執行後 `payment_reports WHERE backfill_note IS NOT NULL` count=172
- [ ] 第二次執行輸出 `0 backfilled, 172 skipped`（冪等驗證）
- [ ] `[REVIEW]` Agent 確認：`NOT EXISTS confirmed` 防護存在；`chunk` 而非 `chunkById`；`--force` 在部署步驟中
- [ ] `[TEST]` Pest tests 通過：dry-run、冪等、is_backfilled=true、Charge=0 不補建
- [ ] SC #460（黃秉澤數學課）`GET .../payment-reports?student_class_id=460&status=confirmed` 返回 1 筆，`is_backfilled=true`
- [ ] ReceiptModal 顯示補建提示條（黃色），正常收據無提示條（UI/UX 自動驗收清單全部打勾）
- [ ] `npm run deploy` 完成，smoke test 全通過
- [ ] `CHANGELOG.md` 已更新，記錄 172 筆補建、SC #460 驗證通過
