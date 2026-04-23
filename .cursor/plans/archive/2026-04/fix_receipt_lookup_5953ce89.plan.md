---
name: 催繳名單升級 PRD
overview: 修正收據查詢 pagination bug，並將催繳名單升級至業界教務管理系統規格，新增狀態篩選 tabs、欄位排序、CSV 匯出、收款率 summary。所有任務由 AI Agent 自主完成，無需人工審核介入。
todos:
  - id: be-1
    content: "[FEATURE] 後端：PaymentReportController@index 加 student_class_id 可選篩選參數，並驗證該課程的學生 CampusID 在 caller 的 auth_campus_ids 內（或 super_admin）"
    status: completed
  - id: fe-1
    content: "[FEATURE] 前端：修正 viewReceiptForClass，帶 student_class_id=<id>&status=confirmed 精準查詢，取第一筆，不依賴分頁遍歷"
    status: completed
  - id: fe-2
    content: "[FEATURE] 前端：Summary cards 升級——新增逾期卡（筆數+金額，--danger 色），未結清卡加顯示收款率（已繳÷應繳，0%紅/中黃/≥80%綠，分母0時顯示—）"
    status: completed
  - id: fe-3
    content: "[FEATURE] 前端：狀態篩選 Tabs（全部/未繳/逾期/待核帳/已繳），逾期定義為 days_until_settlement < 0 且 payment_status 為 unpaid/partial/pending_report，各 tab 顯示筆數 badge"
    status: completed
  - id: fe-4
    content: "[FEATURE] 前端：表頭欄位排序（學生姓名/科目/應繳/未結清/到期日）——點一次 asc、再點 desc、第三次清除，標頭顯示 ▲/▼ 箭頭，hover 淡色背景"
    status: completed
  - id: fe-5
    content: "[FEATURE] 前端：匯出 CSV 按鈕——匯出當前篩選（tab+搜尋）結果，UTF-8 BOM，欄位：學生/科目/模式/狀態/應繳/已繳/未結清/最近付款日/到期日/逾期天數，檔名 催繳名單_YYYYMMDD.csv，0筆時 disabled + tooltip"
    status: completed
  - id: ux-1
    content: "[FEATURE] UI/UX 精緻化：Tab active=--primary underline + 背景；tab 切換無 spinner；空狀態用 inbox icon(48px,--text-light)+說明+「查看全部」CTA；排序箭頭 transition；CSV 按鈕點擊後 icon 旋轉<500ms；所有觸控目標≥44px；色彩沿用既有 token 不新增"
    status: completed
  - id: test-1
    content: "[TEST] 撰寫 Pest Feature Test：FR-001（35筆報告第31筆為目標，精準查詢應命中）、FR-002（跨校 student_class_id 應返回 403）、FR-003（逾期 tab 篩選邏輯）"
    status: completed
  - id: review-1
    content: "[REVIEW] Code Review + 資安驗證：確認 student_class_id filter 有 campus 歸屬驗證；確認 CSV 純前端不洩漏未授權資料；確認 tab/排序邏輯無 XSS 風險"
    status: completed
  - id: docs-1
    content: "[DOCS] 更新 CHANGELOG.md：記錄收據 bug 修正、新增 tabs/排序/CSV/summary 升級"
    status: completed
  - id: deploy-1
    content: 部署：npm run deploy，smoke test——點收據應成功、逾期 tab 篩選正確、CSV 下載無亂碼
    status: completed
isProject: false
---

# PRD：催繳名單升級 — 收據修正 + 業界規格強化

> **執行模式**：本 PRD 設計為 AI Agent 全自主執行。所有「人工審核」節點已改為 Agent 自我驗證步驟（`[REVIEW]` / `[TEST]`）。DoD 以自動化測試通過 + smoke test 確認為準，無需人工 sign-off。

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 催繳名單升級（收據查詢修正 + 篩選/排序/匯出） |
| 版本 / 日期 | v1.1 / 2026-04-19 |
| 狀態 | Approved — Agent 可直接執行 |
| 目標角色 | 補習班主任（催繳管理、核帳操作）、會計（匯出帳務）|

---

## 2. 目標與業務背景

**現有痛點：**
- 點「收據」按鈕顯示「找不到此課程的核帳收據」，即使款項已確認入帳。根因：前端僅查詢第一頁（30 筆，按 pending 優先排序），已確認收據排到後頁無法找到。
- 催繳名單缺乏快速篩選，主任需逐行掃描才能識別逾期帳款。
- 無法匯出名單，會計需手動抄錄。
- 未顯示整體收款率，主任無法一眼掌握應收狀況。

**業務價值：**
- 收據 bug 修正直接消除主任對系統可信度的疑慮。
- 狀態篩選 tabs + 排序將催繳掃描作業從數分鐘降至秒級（對標 TUIO、Classter 等教務 SaaS 的標準操作流程）。
- CSV 匯出讓會計對帳不再依賴手動抄錄。
- 收款率 KPI card 讓主任可在晨會直接彙報。

**成功指標（KPI）：**
- 收據點開成功率：從 < 50% 提升至 ≥ 99%
- 催繳頁逾期帳款識別時間：< 5 秒（目前需人工掃描）
- 會計每週手動對帳次數：目標歸零

---

## 3. 範圍

**In Scope：**
- 修正收據查詢 pagination bug（後端加 filter + 前端改精準查詢）
- 狀態篩選 tabs（全部/未繳/逾期/待核帳/已繳）
- 表頭欄位排序（5 個欄位）
- CSV 匯出（支援 Excel 中文）
- Summary cards 升級（逾期獨立卡 + 收款率）

**Out of Scope：**
- 催繳 LINE 推播批次發送（後續 P2）
- 催繳紀錄追蹤（記錄最後催繳日期）（後續 P2）
- 行動裝置 card layout 重構（催繳名單主要桌機操作）
- 任何付款業務邏輯變動

---

## 4. RACI（Agent 自主執行版）

| 角色 | 執行者 | R/A/C/I |
|---|---|---|
| 功能實作 | `[FEATURE]` Agent | R |
| 測試設計與執行 | `[TEST]` Agent | R |
| Code Review + 資安驗證 | `[REVIEW]` Agent | R |
| 文件更新 | `[DOCS]` Agent | R |
| 部署與 smoke test | 部署 Agent / `deploy.sh` | R |
| PRD 總責 | `[FEATURE]` Agent（依本文件自主執行）| A |

> 無需人工 UI/UX sign-off 或 PM sign-off。DoD 以第 10 節驗收條件全部通過為準。

---

## 5. User Stories

**US-1（收據 Bug 修正）**
> As a 主任, I want 點收據按鈕能正確開啟收據, so that 我不需要懷疑款項是否真的已入帳。
>
> Acceptance Criteria：
> - [ ] 分校有任意數量待審繳費回報時，已確認課程點「收據」均能正確開啟 Modal
> - [ ] 若該課程無已確認收據，顯示 toast「找不到此課程的核帳收據」
> - [ ] 收據查詢 API 回應 P95 < 800ms

**US-2（狀態篩選）**
> As a 主任, I want 用 tab 快速切換「未繳 / 逾期 / 待核帳 / 已繳」, so that 不需要逐行掃描整張表。
>
> Acceptance Criteria：
> - [ ] 共 5 個 tabs（全部/未繳/逾期/待核帳/已繳），各 tab 顯示筆數 badge
> - [ ] 「逾期」= days_until_settlement < 0 且 payment_status ∈ {unpaid, partial, pending_report}（已付款的過期月結課程不列入，因不需催繳）
> - [ ] tab 篩選與姓名搜尋同時作用（AND 邏輯），tab 切換無 API 重打
> - [ ] 篩選後 0 筆時顯示空狀態設計（非空白）

**US-3（欄位排序）**
> As a 主任, I want 點欄位標頭進行排序, so that 快速找到未結清金額最高或逾期最久的學生。
>
> Acceptance Criteria：
> - [ ] 學生姓名、科目、應繳、未結清、到期日可排序
> - [ ] 點一次升冪 → 再點降冪 → 第三次清除排序
> - [ ] 目前排序狀態以 ▲/▼ 指示，清除時箭頭消失

**US-4（CSV 匯出）**
> As a 會計/主任, I want 匯出當前篩選結果為 CSV, so that 可用 Excel 對帳不需手動抄錄。
>
> Acceptance Criteria：
> - [ ] 匯出當前 tab + 搜尋篩選後的全部結果（非分頁）
> - [ ] 欄位：學生姓名、科目、模式、狀態、應繳、已繳、未結清、最近付款日、到期日、逾期天數
> - [ ] 檔名：`催繳名單_YYYYMMDD.csv`，含 UTF-8 BOM，Excel 開啟不亂碼
> - [ ] 0 筆時按鈕 disabled + tooltip「目前無資料可匯出」

**US-5（Summary 升級）**
> As a 主任, I want 頁面頂端一眼看到逾期金額與收款率, so that 快速向校方彙報當月收款狀況。
>
> Acceptance Criteria：
> - [ ] Summary 5 張卡：總筆數、未繳（筆+金額）、逾期（筆+金額）、待核帳（筆）、未結清總額+收款率
> - [ ] 收款率 = 已繳總額 ÷ 應繳總額；分母 = 0 時顯示「—」；0% 紅色、< 80% 黃色、≥ 80% 綠色

---

## 5b. UI/UX 精緻化需求

**頁面：TuitionCollectionPage（催繳名單）**

| 面向 | 規格（可直接實作） |
|---|---|
| **版面層次** | header → summary cards（5 張，flex-wrap，gap 10px）→ filter tabs（margin-top 16px）→ toolbar（搜尋左、匯出右、margin-top 12px）→ table。tabs 字體 14px font-weight 600 |
| **色彩 token** | tabs active：border-bottom 2px `--primary` + background `--primary-light`（已有 token）。逾期卡：`#FFF5F5` / `#FECACA` / `var(--danger)` — 與現有 `tc-card--danger` 一致。收款率色：0% → `var(--danger)`；1–79% → `#D97706`；≥80% → `var(--success)` |
| **互動回饋** | tab 切換：即時（computed），無 spinner，無 layout shift。排序標頭 hover：`background: var(--bg)`、`cursor: pointer`、transition 150ms。CSV 按鈕點擊後 icon class `spin` 加 500ms timeout 後移除，disabled 期間 opacity 0.7。Toast 沿用現有 `showToast()` |
| **空狀態** | 當前 tab 篩選後 0 筆：`<span class="material-symbols-outlined" style="font-size:48px;color:var(--text-light)">inbox</span>` + `<p>此分類目前無資料</p>` + ghost button「查看全部」（`activeTab.value = 'all'`）。禁止空白區域 |
| **載入狀態** | 初始載入：現有 skeleton loader 不變。tab/排序/搜尋：純前端 computed，不需額外 loading |
| **防呆** | CSV 0 筆時：按鈕 `disabled` 加 `title="目前無資料可匯出"`。排序第三次點擊回預設：箭頭以 `v-if="sortKey === col.key"` 控制顯示，清除後自動消失 |
| **響應式** | 催繳名單為桌機主要頁面（≥1024px）。Summary cards < 768px 允許換行。Tabs < 640px 加 `overflow-x: auto; white-space: nowrap`。Table 維持水平滾動，不改 card layout |

---

## 6. 功能需求（FR）

**FR-001**：`GET /api/v1/payment-reports` 支援 `student_class_id` 可選參數，傳入時僅回傳該課程的報告；後端須驗證該 `StudentClassID` 的學生 `CampusID` 屬於 caller 的 `auth_campus_ids`（或 `super_admin`），不符則回傳 403。

**FR-002**：前端收據查詢帶 `student_class_id=<id>&status=confirmed` 精準打 API，取第一筆作為收據來源，不依賴分頁遍歷。

**FR-003**：催繳名單提供 5 個狀態 tabs：全部 / 未繳 / 逾期 / 待核帳 / 已繳，各 tab 顯示對應筆數 badge，tab 切換為前端 computed，不重打 API。

**FR-004**：「逾期」tab 定義：`days_until_settlement < 0` 且 `payment_status ∈ {unpaid, partial, pending_report}`。已付款但過期的月結課程不列入（不需催繳）。

**FR-005**：學生姓名、科目、應繳金額、未結清金額、到期日欄位標頭可點擊排序（asc → desc → 清除），顯示 ▲/▼ 指示。

**FR-006**：「匯出 CSV」按鈕匯出當前篩選（tab + 姓名搜尋）的全部結果，含 UTF-8 BOM，10 欄，檔名 `催繳名單_YYYYMMDD.csv`。篩選後 0 筆時按鈕 disabled。

**FR-007**：Summary cards 共 5 張：總筆數、未繳（筆+金額）、逾期（筆+金額）、待核帳（筆）、未結清總額+收款率百分比。

---

## 7. 非功能需求（NFR）

- **NFR-01**：收據精準查詢 API P95 < 500ms（`StudentClassID` 若無 index 需評估加入）
- **NFR-02**：tab 切換 / 排序畫面更新 < 50ms（純前端 computed，無網路請求）
- **NFR-03**：CSV 產生（1,000 筆以內）< 1 秒（前端 Blob）
- **NFR-04**：所有 API 失敗場景均 toast 提示，不 crash 頁面；CSV 0 筆時 UI 防呆

---

## 8. 技術方向（給 `[FEATURE]` Agent）

**受影響檔案：**
- `backend/app/Http/Controllers/PaymentReportController.php`（`index()` 方法）
- `frontend/src/pages/TuitionCollectionPage.vue`

**API 變動：**
- `GET /api/v1/payment-reports`：新增 optional `student_class_id` query param，向後完全相容（既有呼叫不傳此參數，行為不變）

**前端架構選擇：**
- 篩選 / 排序 / CSV 全部為前端 computed，不引入新 API，零網路延遲
- 現有 `filteredRows` computed 改為三層串聯：tab 篩選 → 姓名搜尋 → 排序
- CSV 用 `Blob` + `URL.createObjectURL` 下載，無需後端 endpoint

**是否需要 DB migration：** 否（`StudentClassID` 欄位已存在）

**是否需要新 index：** 若 `payment_reports.StudentClassID` 無 index，`[FEATURE]` Agent 應在 Controller 加 comment 提醒；若資料量 < 10k，不需立即加 index

**Agent 分工：**
- `[FEATURE]` → 後端 filter + 前端全部功能 + UI/UX 精緻化（依 5b 節自我驗證）
- `[TEST]` → Pest Feature Test（FR-001 跨頁查詢、FR-001 跨校 403）
- `[REVIEW]` → 資安驗證（campus 歸屬邏輯）+ code review
- `[DOCS]` → CHANGELOG.md

---

## 9. 資安與存取控制

**Role 存取：**
- `GET /api/v1/payment-reports` 已有 `auth_campus_ids` middleware 保護
- 新增 `student_class_id` filter 後，後端須額外驗證：查詢該 StudentClassID 所屬學生的 CampusID 是否在 caller 的 `auth_campus_ids` 中（或 role = `super_admin`），否則回 403
- `[REVIEW]` Agent 必須確認此邏輯存在，視為 P0 安全項目

**PII 處理：**
- API 回傳含學生姓名、繳費金額——既有 Bearer token 保護，本次不引入新暴露面
- CSV 在瀏覽器端生成，資料來自已授權 API 回應，不經後端，無額外傳輸風險

**CSV 匯出稽核 log：**
- 業界小型 SaaS（Classter、Brightwheel、TUIO）標準：單一補習班規模不需匯出稽核 log，除非面臨 SOC2 / PDPA 合規要求。本系統目前無此需求，**本次不實作稽核 log**；若日後面臨合規要求，以後端 streaming endpoint 取代前端 Blob 並加 log。

**STRIDE 快評：**
- **Tampering**：`student_class_id` 可被偽造傳入他校 ID → 後端 campus 歸屬驗證（FR-001）緩解，`[REVIEW]` 必查
- **Info Disclosure**：CSV 純前端，無新暴露面
- **其他 S/R/E/D**：無新增風險

---

## 10. QA 驗收標準（Agent 自主執行）

### FR-001 / FR-002：收據精準查詢

| 類型 | 測試案例 | 預期結果 |
|---|---|---|
| Happy Path | 分校有 35 筆待審，第 31 筆為 A 課程已確認收據 | 點「收據」成功開啟 Modal |
| Happy Path | 同一課程有 2 筆已確認（補繳）| 取 created_at desc 第一筆 |
| Edge Case | 該課程無任何已確認報告 | Toast「找不到此課程的核帳收據」|
| Error Case | API 500 | Toast「查詢收據失敗」，Modal 不開啟 |
| Security | 傳入他校 student_class_id | API 回傳 403 |

### FR-003 / FR-004：狀態篩選 Tabs

| 類型 | 測試案例 | 預期結果 |
|---|---|---|
| Happy Path | 點「逾期」tab | 僅顯示 days < 0 且 unpaid/partial/pending_report，badge 數字正確 |
| Happy Path | 逾期 tab + 姓名搜尋「王」| AND 邏輯，只顯示姓名含「王」且逾期的列 |
| Edge Case | tab 篩選後 0 筆 | 顯示 inbox icon + 說明 + 「查看全部」CTA |
| Edge Case | 已付款但 days < 0（月結課程）| 不出現在「逾期」tab |

### FR-005：欄位排序

| 類型 | 測試案例 | 預期結果 |
|---|---|---|
| Happy Path | 點「未結清」→ asc → desc → 清除 | 每步排序正確，箭頭正確顯示/消失 |
| Edge Case | 排序後切換 tab | 排序狀態保留，對新 tab 資料繼續有效 |

### FR-006：CSV 匯出

| 類型 | 測試案例 | 預期結果 |
|---|---|---|
| Happy Path | 篩選後點匯出 | CSV 下載，Excel 開啟無亂碼，筆數與畫面一致 |
| Edge Case | 0 筆時 | 按鈕 disabled，hover 顯示 tooltip |
| Happy Path | 檔名格式 | `催繳名單_20260419.csv` |

### FR-007：Summary Cards

| 類型 | 測試案例 | 預期結果 |
|---|---|---|
| Happy Path | 有 3 筆逾期共 NT$9,000 | 逾期卡顯示「3 / NT$9,000」|
| Edge Case | 應繳總額 = 0 | 收款率顯示「—」|
| Edge Case | 收款率 = 0% | 紅色；= 85% → 綠色；= 60% → 黃色 |

**UI/UX 自動驗收清單（`[FEATURE]` Agent 實作後自我對照）：**
- [ ] 空狀態有 `inbox` icon + 說明文字 + 「查看全部」CTA，無空白區域
- [ ] tab 切換 < 50ms（純 computed），無 layout shift
- [ ] CSV 按鈕點擊後 icon 旋轉 < 500ms 後恢復
- [ ] 排序箭頭 ▲/▼ 正確顯示，清除後消失
- [ ] 逾期卡使用 `var(--danger)`，收款率 0% 紅 / 中黃 / ≥80% 綠
- [ ] 所有按鈕/tab 觸控目標高度 ≥ 44px
- [ ] tab 橫向 overflow-x: auto（< 640px），不換行

---

## 11. 上線與維運

**Agent 部署步驟（依序執行）：**
1. 後端無 migration，直接重啟 PHP-FPM（`docker compose restart backend` 或對應指令）
2. `npm run deploy`（或 `./deploy.sh`）產出前端 build + assets sync
3. Smoke test（Agent 自行確認）：
   - `curl -H "Authorization: Bearer <token>" "/api/v1/payment-reports?student_class_id=1&status=confirmed"` → 應回傳 200 + data array
   - 前端催繳名單頁：tab 切換正常、CSV 可下載、收據可開啟

**回滾方案：**
- 後端：移除 `student_class_id` filter 那一個 `if` 區塊（無 migration，零資料風險）
- 前端：重新部署上一版 build artifacts（`git revert` + redeploy）

**監控：**
- 現有 API response time 監控即可涵蓋，不需新增

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 執行 Agent |
|---|---|---|
| P0 | 收據精準查詢 Bug 修正（FR-001、FR-002）| `[FEATURE]` |
| P1 | 狀態篩選 Tabs（FR-003、FR-004）| `[FEATURE]` |
| P1 | Summary Cards 升級（FR-007）| `[FEATURE]` |
| P1 | CSV 匯出（FR-006）| `[FEATURE]` |
| P1 | 欄位排序（FR-005）| `[FEATURE]` |
| P1 | UI/UX 精緻化（第 5b 節全部項目）| `[FEATURE]` |
| P1 | Pest 測試（FR-001 跨頁、FR-001 跨校 403）| `[TEST]` |
| P1 | Code Review + 資安驗證 | `[REVIEW]` |
| P1 | CHANGELOG.md 更新 | `[DOCS]` |
| P1 | 部署 + Smoke test | 部署 Agent |

> 所有 P0/P1 項目由 Agent 在同一次執行週期完成，無阻塞依賴。

---

## 13. 風險、假設、開放問題

**風險（已評估並決策）：**

| 風險 | 等級 | 業界決策 / 緩解方案 |
|---|---|---|
| `student_class_id` 可偽造查詢他校資料 | 高 | 後端加 campus 歸屬驗證（FR-001），`[REVIEW]` Agent P0 必查；業界標準（TUIO、Classter）均在 API layer 做 tenant isolation |
| CSV 前端 Blob 在 > 10,000 筆時瀏覽器可能短暫凍結 | 低 | 單一補習班催繳名單通常 < 500 筆，低風險；若未來有需求改後端 streaming（標注後續 P2）|
| `days_until_settlement` 欄位在部分課程為 null | 低 | 業界作法：null 視為「無到期日」，不計入逾期計算，排序時排至最後。前端加 null-safe guard（`?? 999`，現有程式碼已有此模式）|

**假設（業界對標後確認，無需人工確認）：**

| 假設 | 依據 |
|---|---|
| 「逾期」tab 不包含已付款但過期的月結課程 | 業界催繳 SaaS（TUIO、iSMS）定義「逾期催繳」為「有欠款且超過結帳日」；已付款課程不需催繳動作，列入會造成主任誤操作 |
| CSV 匯出不需後端稽核 log | 補習班規模無 SOC2/PDPA 合規要求；同規模業界（Brightwheel、MyKidsTime）均為前端 Blob 無 log；若日後合規需求出現，另立 ticket |
| 收款率計算分母為所有課程應繳金額總和（charge 欄位）| 業界 AR dashboard 標準：AR Collection Rate = Total Collected ÷ Total Billed；charge = null 的課程排除在外（視為免費課程）|
| `student_class_id` 欄位不需新 index（短期）| 催繳名單最多數百筆，單次精準查詢一個 StudentClassID，全表掃描 < 10ms；業界建議 table size > 50k rows 再加 index |

**開放問題：** 無（原有待確認項目均已依業界標準決策）

---

## 14. Definition of Done（Agent 自主驗證）

- [ ] FR-001 ~ FR-007 全部通過第 10 節測試案例（`[TEST]` + `[REVIEW]` Agent 確認）
- [ ] UI/UX 自動驗收清單（第 10 節末）全部打勾（`[FEATURE]` Agent 自我對照）
- [ ] `[REVIEW]` Agent 確認：`student_class_id` filter 有 campus 歸屬驗證，回傳 403 測試通過
- [ ] `npm run deploy` 完成，smoke test 三項全通過
- [ ] `CHANGELOG.md` 已更新
