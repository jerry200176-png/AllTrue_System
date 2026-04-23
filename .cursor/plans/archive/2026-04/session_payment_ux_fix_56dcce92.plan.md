---
name: Session payment UX fix
overview: 修正「帳單已收款但課程仍顯示未繳」、釐清取消堂次與購買堂數的產品語意與 UI 計數，並收斂剩餘堂數與堂次列表的計算口徑，必要時補資料修復腳本。
todos:
  - id: invoice-sync-paid
    content: BillingController::recordPayment（與對稱沖帳若有）在 transaction 內回寫關聯 StudentClass.Paid/PayDate；StudentClassController::index 併入防呆；補 Feature test；評估 AlertController::tuition 與 DIRECTOR_PAYMENT_ALERT_RULES 一致性
    status: completed
  - id: backfill-cmd
    content: 新增一次性 artisan/SQL：已有 Payment 但 Paid=0 的 StudentClass backfill；用於大直個案
    status: completed
  - id: course-ui-copy
    content: CourseManagement + useCourseSessionsDisplay：分離「購買堂數」與列表筆數；取消列可見；必要時 StudentsList 對齊
    status: completed
  - id: drift-warning
    content: SessionCount 與非取消 ClassSession 列數不一致時顯示主任向警告（先不擅自改 SessionDeduction 規則）
    status: completed
  - id: case-dazhi
    content: 針對陳昶勳課程跑 DB 診斷：Payment、SessionCount、各 ClassSession.Status、Charge；執行修復並截圖驗收
    status: completed
  - id: payment-rules-matrix
    content: 產出「發票／付款 → StudentClass.Paid／PayDate」決策矩陣（全額付清 vs 部分付款 vs 沖帳／刪除付款 vs 主任手動標未繳）並與 docs/DIRECTOR_PAYMENT_ALERT_RULES.md 對照；若有衝突列為產品簽核項
    status: completed
  - id: backfill-safety
    content: Backfill artisan：--dry-run、受影響 ID 清單輸出、可選 --since、執行寫入前後 CSV／JSON 稽核；僅 super_admin 或 APP_ENV+token 護欄（擇一寫入計畫實作）
    status: completed
  - id: sync-observability
    content: recordPayment 與 backfill 寫結構化 log（invoice_id、student_class_ids、舊 Paid/PayDate→新值）；必要時 metrics 計數便於事後稽核
    status: completed
  - id: conflict-director-unpaid
    content: 定義與實作「主任曾明確標未繳」與「帳單已收款」衝突時的優先序（例：保留 payment_status 手動覆寫欄位、或僅在未手動鎖定時同步）；並補測試
    status: completed
  - id: release-qa-docs
    content: 更新 docs/CHANGELOG.md 行為說明；主任手動測清單（入帳→課程列表→儀表板催繳／續課提醒→課程管理取消列）；若 API 新增欄位註記契約
    status: completed
  - id: qa-test-matrix
    content: 建立 QA／自動化對照表：入帳（0→1 筆、部分、全額）、沖帳／刪付款（若有 API）、無發票僅編輯 paid_at、主任切換已繳／未繳、多 Item 同一發票多門課；每列對應預期 DB（Paid/PayDate）與 GET student-classes JSON
    status: completed
  - id: qa-regression-alerts
    content: 驗收 GET alerts/tuition 與 TuitionCollectionPage／DirectorDashboard 卡片：同步後「不應再誤列入已全額繳清之課程」或應列入時須符合 DIRECTOR_PAYMENT_ALERT_RULES（逐條勾選）
    status: completed
  - id: qa-regression-slips
    content: 驗收催繳／繳費單相關：已繳不產圖、tuition-slip／PaymentSlipModal；參考 docs/AI_REGRESSION_LESSONS.md 催繳名單章節
    status: completed
  - id: qa-cross-role-branch
    content: 驗收分校隔離：主任僅見本校 student-classes；老師在 CourseManagement／StudentsList（若有）僅見授權範圍；切分校後列表與入帳同步後資料不跨校
    status: completed
  - id: qa-session-ui-e2e
    content: 手動 E2E：購買 12 堂→課程管理取消 1 堂 scheduled→購買數不變、取消列可見、總費用不應因取消單堂而少一堂（除非編輯課程改堂數）；剩餘堂數與「已上／未上」合計可解釋
    status: completed
isProject: false
---

# 堂數／繳費狀態與列表一致化計畫

## 背景與根因（已從程式碼驗證）

### 1. 有繳費日仍顯示「未繳費」

- 列表 API 將 **`payment_status` 完全綁在 `StudentClass.Paid`**（0 → `unpaid`），與畫面上可能出現的日期無直接關聯。

```358:361:backend/app/Http/Controllers/StudentClassController.php
            $class->payment_status = empty($class->Paid) ? 'unpaid' : 'paid';
            $directPaidAt = $class->PayDate ? substr($class->PayDate, 0, 10) : null;
            $class->paid_at = $directPaidAt;
            $class->last_paid_at = $directPaidAt ?? ($paidAtMap[(int) $class->ID] ?? null);
```

- `last_paid_at` 可來自 **`Invoice`→`Payment` 的 MAX(PaidAt)`**（[`AlertController::lastPaidAtByStudentClassIds`](backend/app/Http/Controllers/AlertController.php)），但 **[`BillingController::recordPayment`](backend/app/Http/Controllers/BillingController.php) 入帳時並未回寫 `StudentClass.Paid` / `PayDate`**。因此常見情境是：**帳單已收款、畫面小字有日期，徽章仍是未繳**。

- 你已選擇產品方向：**有該課程相關帳單收款紀錄時，課程應視為已繳（並同步 DB 狀態）**。

### 2. 「取消一堂」後「共 11 筆」、總費用變 11 堂

- 課程管理下方「上課日期（實際 … 堂，共 … 筆）」的 **「共 N 筆」來自 [`sessionUnits`](frontend/src/composables/course-management/useCourseSessionsDisplay.js)**：會 **直接排除 `status === 'cancelled'` 的 `ClassSession` 列**，因此取消後 **N 會少 1**，使用者直覺會以為「購買堂數被吃掉」。
- 若同時 **`StudentClass.SessionCount`（`sessions_purchased`）被某次儲存改成 11**，總費用（`Charge` 與堂數連動時）會變成 **11 × 單價**，與「我買 12 堂」的契約認知衝突。需在實作時盤點：**僅編輯課程表單變更 `sessions_purchased` 時才可改 `SessionCount`**；單堂狀態機（[`ClassSessionController::update`](backend/app/Http/Controllers/ClassSessionController.php)）不應默默縮減購買堂數（目前以程式閱讀結果：**取消只改列狀態，不會自動減 `SessionCount`**；若現場資料已變 11，較可能是 **某次 PUT `student-classes` 帶入較小 `sessions_purchased`、或手動/匯入**，需用 DB 查證）。

### 3. 剩餘堂數與下方日期「對不起來」

- 列表上的剩餘來自後端 **`SessionCount` − `SessionDeductionService::batchObservedUsedSessions` 的 observed used**（與「已上」徽章用的狀態集合 **不完全相同**；例如 composable 裡 `SESSION_DISPLAY_CONSUMED` 含 `absent`，而 observed used 對 `ClassSession` 的計數是 **`completed`/`attended`/`late`** 等，再與刷卡扣點、`LearningRecord` 取 max）。

```36:64:backend/app/Services/SessionDeductionService.php
        $completedSessions = ClassSession::query()
            ->whereIn('StudentClassID', $ids)
            ->whereIn('Status', ['completed', 'attended', 'late'])
            ->groupBy('StudentClassID')
            ->selectRaw('StudentClassID, COUNT(*) as c')
            ->pluck('c', 'StudentClassID');
        // ...
            $out[$id] = max($a, $b, $d);
```

- 另：**非取消列數 > `SessionCount`**（例如順延補堂、`IsContractException`、歷史重排）時，畫面上 **12 格** 但 **購買/計價 11** 就會出現你截圖二那種 **「剩餘 1」但視覺上像還有 2 堂未上」** 的矛盾。這屬 **資料或 UI 語意不同步**，需一併處理。

---

## 目標（Acceptance criteria）

1. **繳費狀態**：凡與該 `StudentClass` 綁定的 **`Invoice` 已有 `Payment` 紀錄**（依你選擇：建議 **發票狀態 `paid` 或 `PaidAmount > 0`** 即視為已繳，細節在實作前用一張矩陣表定稿），**`GET student-classes` 回傳的 `payment_status` 為 `paid`，且 `Paid`/`PayDate` 與之一致**（避免儀表板／列表各說各話）。
2. **取消堂次**：**取消 ≠ 減少購買堂數**；購買堂數 **僅能透過課程編輯**（`sessions_purchased` / `SessionCount`）變更。取消的堂次在 UI 上仍應可辨識（**保留列或獨立「已取消」計數**），**主標題的「共 N 筆」不得再讓使用者誤解為「購買 N 堂」**。
3. **剩餘堂數**：課程管理頁上 **「剩餘」數字與列表狀態**在相同定義下可自洽；若資料不一致（非取消列數與 `SessionCount` 不符），**顯示警告並給出修復入口**（或僅主任可見的提示）。

---

## 實作階段

### Phase A — 帳單收款與課程 `Paid` 同步（你選的方向）

- **寫入路徑**：在 [`BillingController::recordPayment`](backend/app/Http/Controllers/BillingController.php)（及若有「刪除付款／沖帳」API 一併對稱處理）於 transaction 內：
  - 解析該發票關聯的 `StudentClassID`（**發票本體 + `InvoiceItem`**，邏輯可對齊 [`slipData`](backend/app/Http/Controllers/BillingController.php) 已寫過的收集方式）。
  - 當符合「已視為已繳」規則時：`StudentClass.Paid = 1`；若 `PayDate` 為空則設為 **`MAX(Payment.PaidAt)` 日期**（與 `lastPaidAtByStudentClassIds` 一致）。
- **讀取路徑（防呆）**：[`StudentClassController::index`](backend/app/Http/Controllers/StudentClassController.php) 組 `payment_status` 時，可 **併入「該課程已有有效付款」** 的判斷，避免舊資料在 backfill 前仍顯示錯誤。
- **回歸**：補 [`StudentClassPaidStatusTest`](backend/tests/Feature/StudentClassPaidStatusTest.php) 類案例：**「僅入帳、未手動切換 `payment_status`」** 課程列表應為 `paid`；並快速確認 [`AlertController::tuition`](backend/app/Http/Controllers/AlertController.php) 與 [`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`](docs/DIRECTOR_PAYMENT_ALERT_RULES.md) 是否仍依預期（**若條件變動須先取得產品簽核**）。
- **一次性修復**：提供 `artisan` 指令或安全 SQL（僅主任／維運執行）：對 **已有 Payment 但 `Paid=0`** 的 `StudentClass` 做 backfill（含 **大直 / 陳昶勳** 個案）。

### Phase B — 「購買堂數」與「列表筆數」語意分離（前端為主，必要時後端加欄位提示）

- 調整 [`CourseManagement.vue`](frontend/src/pages/CourseManagement.vue) + [`useCourseSessionsDisplay.js`](frontend/src/composables/course-management/useCourseSessionsDisplay.js)：
  - 標題改為 **「購買 {{ sessions_purchased }} 堂｜排程列 {{ … }}（含取消 {{ … }}）」** 或同等清楚文案；**「共 N 筆」改為「非取消列數」或「全部列數」並附註**，避免與購買數混淆。
  - **取消列**：改為仍出現在網格中但樣式為「已取消」（或第二列統計），**不再從列表憑空消失**。
- 若 [`StudentsList.vue`](frontend/src/pages/StudentsList.vue) 也有類似展開 UI，一併對齊文案（避免兩頁認知不同）。

### Phase C — 剩餘堂數與堂次列一致性

- **短期**：在課程管理列上，若偵測 **`count(non-cancelled ClassSession)` ≠ `sessions_purchased`**（或與後端 `session_sync` 資訊矛盾），顯示 **黃色警告條**（文案：「購買堂數與實際排程列數不一致，請聯絡管理員或從編輯課程校正」）。
- **中期（可選）**：統一「已用堂數」定義（是否含 `absent`、是否以 ledger 為準）— 需與 **核准評量扣堂** 政策一致，**變更前須依 [`docs/AI_REGRESSION_LESSONS.md`](docs/AI_REGRESSION_LESSONS.md) 與使用者確認**；本次建議 **先做警告 + 資料修復**，避免未授權改商業規則。

### Phase D — 個案驗證（大直／陳昶勳）

- 以 **Student + StudentClass ID + Campus** 查：`SessionCount`、`RemainingSessions`、`Charge`、`Paid`、`PayDate`、非取消 `ClassSession` 筆數、各列 `Status`、`Payment` 紀錄。
- 依查詢結果：**補 `Paid`/`PayDate`、或還原誤改的 `SessionCount`、或標記/合併重複堂次**（每一步留下操作紀錄）。

---

## 風險與依賴

- **繳費提醒規則**：動到「何謂已繳」會影響 [`AlertController::tuition`](backend/app/Http/Controllers/AlertController.php) 與 [`TuitionCollectionPage.vue`](frontend/src/pages/TuitionCollectionPage.vue) 的篩選；實作時須 **對照 [`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`](docs/DIRECTOR_PAYMENT_ALERT_RULES.md)**，若與文件不一致須產品簽字。
- **堂數扣除**：避免在 Phase C 順便改 `SessionDeductionService` / 核准扣堂鏈條（高風險區，見 [`AGENTS.md`](AGENTS.md)）。

---

## CTO 加強（已併入 todos）

- **邊界條件一次說清**：部分入帳、沖帳或刪除付款、一張發票綁多門課、課程無發票僅現金備註等，必須先有決策矩陣再寫程式，避免上線後「以為已繳／仍以為欠費」的法律與營運糾紛。
- **營運安全**：歷史 backfill 屬大量寫入，需 **dry-run、可審計輸出、權限護欄**；若無法還原，至少在 log 留存變更前後。
- **與人為操作衝突**：若主任刻意標「未繳」但會計已入帳，系統需定義 **誰覆寫誰**（或引入「手動鎖定」），否則會被當 bug 來回報。
- **可觀測性**：入帳觸發同步時打 **結構化 log**（含 invoice、class、舊新值），利於大直類個案事後追查，不必開 tinker。
- **發佈與回歸**：行為變更屬使用者可見契約，**CHANGELOG + 手動 QA 清單**（課程列表、催繳／續課提醒、課程管理 UI）應與程式同 PR，降低跨模組回歸。

---

## 建議時程（粗估）

| 階段 | 內容 | 粗估 |
|------|------|------|
| A | 入帳同步 + API 防呆 + 測試 + 一次性 backfill | 1–2 天 |
| B | 課程管理文案／取消列顯示 | 0.5–1 天 |
| C | 不一致警告（前端 + 可選後端 flag） | 0.5 天 |
| D | 個案 DB 查證與修復 | 0.5 天 |

---

## QA 驗收加強（必勾）

### 契約與 API

- **GET `/api/v1/student-classes`**：`payment_status`、`paid_at`、`last_paid_at` 與 DB `Paid`／`PayDate`／帳單付款在決策矩陣下**三者不自相矛盾**；Network 複製 JSON 作 release 附件。
- **PUT `/api/v1/student-classes/{id}`**：僅改堂數／不改入帳時，`payment_status` 不因無關欄位被誤改（迴歸現有 StudentClassPaidStatusTest 行為）。

### 帳務與提醒

- **`recordPayment` 後**：關聯課程於列表為已繳；**重新整理**與**他台裝置登入**結果一致（排除僅前端快取）。
- **`GET /api/v1/alerts/tuition`**：依 [`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`](docs/DIRECTOR_PAYMENT_ALERT_RULES.md) 勾選每一類提醒是否仍合理；若有「應出現／不應出現」爭議，**阻塞 release** 直至產品簽字。
- **催繳／繳費單**：已繳課程**不得**產出催繳圖或誤導文案（對照 [`docs/AI_REGRESSION_LESSONS.md`](docs/AI_REGRESSION_LESSONS.md) 催繳相關節）。

### 課程 UI 與堂數

- **取消一堂（scheduled→cancelled）**：`SessionCount`（購買）不變；畫面可見「已取消」或同等語意；**「購買 N 堂」與「列表筆數」文案**不可再讓使用者理解為 N=購買數。
- **Drift 警告**：人為造出「非取消 ClassSession 列數 ≠ `sessions_purchased`」測試資料時，警告出現且**不**自動改扣堂邏輯。
- **剩餘堂數**：任選一堂標 `absent`（若有點名／扣堂流程）與「僅已上」兩情境，剩餘與列表狀態可依產品說明解釋（若無說明文件則阻塞為產品待補）。

### 權限與資料

- **分校**：同一學生若誤跨校資料不應因本功能可見；入帳同步僅影響**該發票所屬學生校區**之課程。
- **Backfill**：`--dry-run` 筆數與實跑 `--execute`（或同等）**變更筆數一致**；實跑後抽樣 3 筆 DB 核對。

### 放行門檻

- 上述區塊**全數勾選** + 既有 Pest／新增案例綠燈 + `release-qa-docs` 所列手動清單完成後，始可合併部署。
