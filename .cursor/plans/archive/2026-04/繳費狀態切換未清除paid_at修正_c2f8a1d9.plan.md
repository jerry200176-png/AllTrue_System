---
name: 繳費狀態切換未清除 paid_at 修正
overview: |
  修正 StudentsList.vue 的 togglePaymentStatus 在切換「未繳費」時未清除 paid_at，
  導致 DB 殘留 Paid=0 但 PayDate IS NOT NULL 的不一致記錄；
  並透過 Data Backfill 修正現有 27 筆歷史髒資料，
  同時補齊 class-sessions/batch + paid_at 的端到端測試。
todos:
  - id: frontend-fix
    content: "[DEV] StudentsList.vue：togglePaymentStatus 切換至 unpaid 時，payload 加入 paid_at: null"
    status: pending
  - id: data-backfill
    content: "[DEV] Migration Script：將 Paid=0 AND PayDate IS NOT NULL 的 27 筆記錄 Paid→1（主任確認：有 PayDate 代表已繳費，均為誤觸切回未繳費）"
    status: pending
  - id: test-e2e
    content: "[TEST] EnrollmentApiTest / StudentClassPaidStatusTest：新增 POST /api/v1/class-sessions/batch + paid_at 端到端測試（含有 / 無 paid_at 兩條路徑）"
    status: pending
  - id: test-toggle
    content: "[TEST] 新增 PUT /api/v1/student-classes/:id 切換至 unpaid 時，PayDate 同步清除為 NULL 的 API 測試"
    status: pending
  - id: revert-proof
    content: "[TEST] Revert-proof 驗證：git stash 後重跑新增測試，確認至少 1 case failure"
    status: pending
  - id: code-review
    content: "[REVIEW] Code Review：逐條對照 FR-001～FR-004，確認前端與後端修改正確"
    status: pending
  - id: docs-update
    content: "[DOCS] 更新 docs/CHANGELOG.md 與 docs/AI_REGRESSION_LESSONS.md（補入「前端切換繳費狀態時必須同步清除 paid_at」教訓）"
    status: pending
  - id: deploy
    content: "[OPS] 部署：./deploy.sh；smoke test GET /api/v1/student-classes 確認 payment_status 正確"
    status: pending
isProject: false
---

# Bug Fix Plan — 繳費狀態切換未清除 paid_at 修正

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2 |
| 根因類型 | 欄位缺失（前端 payload 未帶 paid_at: null）+ 歷史髒資料 |
| 根因摘要 | `StudentsList.vue` 的 `togglePaymentStatus` 切換至「未繳費」時，只送 `{ payment_status: 'unpaid' }`，缺少 `paid_at: null`，導致後端 `mapFrontendPayload` 雖正確將 `Paid→0`，卻未清除 `PayDate`，形成 `Paid=0 / PayDate IS NOT NULL` 的矛盾記錄 |
| 錯誤行為 | 課程被切換為「未繳費」後，`StudentClass.PayDate` 仍保留舊日期；再次建立同一課程或查閱歷史記錄時，`payment_status` 依賴 `Paid=0` 判定為 `unpaid`，但 `PayDate` 有值，造成主任「明明輸入繳費日期、卻顯示未繳費」的疑惑 |
| 預期行為 | 切換為「未繳費」時，`Paid=0` 且 `PayDate=NULL`；切換為「已繳費」時，`Paid=1` 且 `PayDate` 為傳入日期 |
| 影響範圍 | 主任在 `StudentsList.vue`（學生課程列表頁）操作繳費切換的所有課程；歷史上已有 **27 筆** `Paid=0 AND PayDate IS NOT NULL` 的髒資料（2026-04-21 確認） |
| B1 偵查來源 | 本計畫整合 B1 偵查報告（對話 [繳費日期 bug 偵查](1c52552b-effd-4b63-a5b9-6b41748bb25d) 結論） |

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 繳費狀態切換未清除 paid_at 修正 |
| 版本 / 日期 | v1.0 / 2026-04-21 |
| 狀態 | Draft |
| 嚴重度 | **P2**（資料不一致，財務顯示錯誤；非即時服務中斷） |
| 目標角色 | 主任（在 `StudentsList.vue` 操作繳費切換者） |
| 關聯計畫 | `建立課程繳費日期失效修正_930095c1`（已完成；修正的是建立路徑；本計畫修正切換路徑） |

---

## 2. 業務背景與影響

**現在的痛點**

主任在「課程管理」或「學生課程列表」頁面將某筆課程從「已繳費」切換回「未繳費」時，後端確實將 `Paid` 置 0，但 `PayDate` 仍保留原始日期。之後若主任重新輸入繳費日期，畫面已顯示正確；但在此之前，`GET /api/v1/student-classes` 會依據 `Paid=0` 判斷為 `unpaid`，讓主任誤以為課程「明明已填日期，卻還是未繳費」，引發重複操作與帳務疑慮。

此外，現有 27 筆歷史髒資料（`Paid=0 AND PayDate IS NOT NULL`）若不清理，即使前端修正後，主任仍可能在課程清單中看到這些異常記錄，持續收到誤報。

**修復後預期行為**

1. 在 `StudentsList.vue` 將課程切換為「未繳費」後，`Paid=0` 且 `PayDate=NULL`；課程清單立即顯示「未繳費」，且不再保留舊日期。
2. 現有 27 筆歷史髒資料的 `PayDate` 被清為 NULL，`payment_status` 與 `Paid` 欄位一致。
3. 任何後端 PUT 呼叫若以 `payment_status: 'unpaid'` 但未帶 `paid_at` 欄位，後端應將 `PayDate` 強制設為 NULL。

---

## 3. 範圍

**In Scope**
- `frontend/src/pages/StudentsList.vue`：`togglePaymentStatus` 函數，補送 `paid_at: null`
- `backend/app/Http/Controllers/StudentClassController.php`：`mapFrontendPayload` 方法，確認 `payment_status = unpaid` 時強制將 `PayDate` 設為 NULL（後端保底）
- 歷史髒資料 Backfill Script（27 筆 `Paid=0 AND PayDate IS NOT NULL`）
- 新增 `POST /api/v1/class-sessions/batch + paid_at` 端到端測試
- 新增 `PUT /api/v1/student-classes/:id` 切換至 unpaid 時 `PayDate=NULL` 的 API 測試

**Out of Scope**
- `CourseManagement.vue`（同類 toggle 已於 commit `de03daf` 修正，本次不再重複動它）
- `UniversalClassScheduler.vue`（建立路徑前端，無需修改）
- `EnrollmentService::store`（建立路徑後端，已正確處理 paid_at）
- `SessionDeductionService`（堂數重算邏輯，已於前次計畫修正）
- Invoice / 付款流程（屬獨立功能模組）
- 催繳名單 `alerts/tuition`（不依賴 `Paid` 欄位，不在範圍內）
- 前端繳費日期 UI 的視覺樣式調整

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| 執行工程 | AI Agent | **R / A** |
| 主任（資料確認） | 最終使用者 | **I**（Backfill 前需確認規模） |
| CTO / 工程 Lead | 工程師 | **I**（Code Review 觸發） |
| QA | AI Agent | **R** |
| IT / Ops | 工程師 | **I**（部署） |

---

## 4b. Dependencies

| # | 前置條件 | 狀態 |
|---|---|---|
| D-01 | `建立課程繳費日期失效修正_930095c1` 已部署上線（後端建立路徑正確） | ✅ 已完成 |
| D-02 | `CourseManagement.vue` 的 toggle 已修正（commit `de03daf`） | ✅ 已完成 |
| D-03 | 無其他 pending PR 修改 `StudentClassController::mapFrontendPayload` | 需確認（開始前 git pull） |

---

## 5. Acceptance Criteria

### AC-001：StudentsList.vue 切換至「未繳費」
- AC-001-a：主任點擊切換為未繳費，API `PUT /api/v1/student-classes/:id` 的 payload 包含 `paid_at: null`，後端回傳 HTTP 200，`payment_status = "unpaid"`
- AC-001-b：切換後 `GET /api/v1/student-classes` 回傳該課程 `PayDate = NULL` 且 `payment_status = "unpaid"`（反向驗證：確認 `PayDate` 確實清除）

### AC-002：StudentsList.vue 切換至「已繳費」（反向，確認正向不破壞）
- AC-002-a：主任點擊切換為已繳費，payload 包含 `paid_at`（日期字串），後端回傳 HTTP 200，`payment_status = "paid"`
- AC-002-b：`GET /api/v1/student-classes` 回傳該課程 `PayDate = <填入日期>` 且 `Paid = 1`

### AC-003：後端 mapFrontendPayload 保底
- AC-003-a：`PUT /api/v1/student-classes/:id` 帶 `{ payment_status: 'unpaid' }`（不帶 `paid_at`），後端將 `PayDate` 設為 NULL，`Paid = 0`
- AC-003-b：`PUT /api/v1/student-classes/:id` 帶 `{ payment_status: 'paid', paid_at: '2026-03-01' }`，後端將 `Paid = 1`、`PayDate = '2026-03-01'`

### AC-004：歷史髒資料 Backfill
- AC-004-a：Backfill 執行後，`SELECT COUNT(*) FROM StudentClass WHERE Paid=0 AND PayDate IS NOT NULL` 回傳 `0`
- AC-004-b：Backfill 執行後，原 27 筆記錄 `Paid=1`、`PayDate` 保持原值不變
- AC-004-c：Backfill Script 支援 dry-run（不寫入，只印受影響筆數）

### AC-005：測試覆蓋
- AC-005-a：`POST /api/v1/class-sessions/batch` 帶 `paid_at` 的端到端測試通過（`payment_status = "paid"`）
- AC-005-b：`POST /api/v1/class-sessions/batch` 不帶 `paid_at` 的測試通過（`payment_status = "unpaid"`）
- AC-005-c：`PUT /api/v1/student-classes/:id` 切換至 unpaid 時 `PayDate = NULL` 的測試通過

---

## 6. 功能需求（FR）

**FR-001**：`StudentsList.vue` 的 `togglePaymentStatus` 函數，切換至 `unpaid` 時，送出的 payload 必須包含 `paid_at: null`，與 `CourseManagement.vue` 現有邏輯一致。

**FR-002**：`StudentClassController::mapFrontendPayload` 應新增後端保底邏輯：當 `payment_status = 'unpaid'`（或等效條件），無論前端是否傳入 `paid_at`，均強制將 `PayDate` 設為 NULL、`Paid` 設為 0，確保 DB 欄位一致性。

**FR-003**：新增一次性 Backfill Script（或 Artisan Command），將 `Paid=0 AND PayDate IS NOT NULL` 的記錄之 `PayDate` 更新為 NULL；Script 需支援 dry-run 模式（不實際寫入，只印出受影響筆數），並須在 staging 驗證後才執行 production。

**FR-004**：新增以下測試方法：
1. `test_batch_create_with_paid_at_sets_payment_status_paid`
2. `test_batch_create_without_paid_at_sets_payment_status_unpaid`
3. `test_toggle_to_unpaid_clears_paydate`

---

## 7. 非功能需求（NFR）

不適用。理由：本次為純邏輯修正（前端補送欄位 + 後端保底判斷 + 資料清理），不涉及效能敏感路徑、不新增 DB 索引、不改變 API 回應結構，現有回應時間不受影響。`PUT /api/v1/student-classes/:id` 已在線上穩定運行，本次只修改 payload 欄位，無效能風險。

---

## 8. 技術方向

**受影響元件**

| 類型 | 名稱 | 說明 |
|---|---|---|
| 前端元件 | `frontend/src/pages/StudentsList.vue` | `togglePaymentStatus` 函數：補送 `paid_at: null` |
| 後端 Controller | `backend/app/Http/Controllers/StudentClassController.php` | `mapFrontendPayload` 方法：加入 unpaid 保底邏輯 |
| Backfill Script | `backend/database/migrations/[date]_backfill_paid_paydate_consistency.php` | 一次性資料修正 migration |
| 測試 | `backend/tests/Feature/StudentClassPaidStatusTest.php` | 新增 AC-005-c 測試 |
| 測試 | `backend/tests/Feature/EnrollmentApiTest.php` | 新增 AC-005-a / AC-005-b 測試 |
| 文件 | `docs/CHANGELOG.md`、`docs/AI_REGRESSION_LESSONS.md` | 記錄修正與教訓 |

**資料表**

- `StudentClass`（欄位：`Paid INT(11) NOT NULL DEFAULT 0`、`PayDate DATE NULL`）

**架構選擇說明**

1. **前端修正優先，後端保底為輔**：理想上前端負責送正確 payload，後端不應做 UI 邏輯補償。但因歷史上曾出現前端漏送造成髒資料的情形（Bug B），本次在後端 `mapFrontendPayload` 加入「payment_status=unpaid → PayDate=NULL」保底，符合 Defense-in-Depth 原則，未來前端即使再次漏送也不會造成資料不一致。

2. **Backfill 選用 Laravel Migration 而非 Artisan Command**：原因是 Migration 有版本控制、有 `up/down` 結構、可被 `php artisan migrate:status` 追蹤，業界標準做法（參考 Laravel Docs: Data Migrations）。`down()` 實作為 no-op（不回寫 PayDate）以避免回滾時誤復原髒資料。

3. **Backfill 方向確認為 Paid→1**：主任確認「有 PayDate 的記錄代表已繳費，均為誤觸切回未繳費」。因此 27 筆的正確修正方向是將 `Paid` 改回 1（保留 PayDate），而非清除 PayDate。

**子任務 Agent 派發**

- `[DEV]` → 前端 `StudentsList.vue` 修正 + 後端保底邏輯
- `[DEV]` → Backfill Migration Script
- `[TEST]` → 三條新測試方法
- `[DOCS]` → CHANGELOG + AI_REGRESSION_LESSONS

---

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-21 | 前端補 `paid_at: null` + 後端保底 | 僅修前端 | 防禦深度：歷史已有前端漏送先例，後端保底杜絕同類 bug 再現 |
| 2026-04-21 | Backfill 將 27 筆改為 Paid=1（保留 PayDate） | 清除 PayDate | 主任確認：有 PayDate 代表已繳費，均為誤觸切回未繳費；Paid 欄位應回復為 1 |
| 2026-04-21 | 用 Laravel Migration 執行 Backfill | Artisan Command / 手動 SQL | 版本控制追蹤、dry-run 模式、staging 先行驗證流程成熟 |
| 2026-04-21 | 後端保底放在 `mapFrontendPayload` | 放在 Model 的 `setAttribute` | `mapFrontendPayload` 是 payload 正規化的唯一入口，語意清晰；Model setter 會影響所有路徑，過度侵入 |

---

## 9. 資安與存取控制

- **存取角色**：`PUT /api/v1/student-classes/:id` 的 `payment_status` / `paid_at` 修改，受 `auth:api` middleware 保護，僅限登入的主任（`type=A`）或超管（`type=S`）；本次修改不新增或放寬 role 檢查。
- **Backfill Script**：僅由工程師在後端直接執行 `php artisan migrate`，不開放 API 呼叫路徑；dry-run 模式不寫入。
- **PII**：`PayDate` 為繳費日期，屬財務記錄（非個人識別資訊），受現有 campus-scoped 存取控制保護，本次修改不改變 scope 邊界。
- **STRIDE 快評**：純邏輯修正，不新增 API 端點、不新增欄位、不放寬 auth；Backfill 在後端直接執行，無 API 暴露面；整體 STRIDE 風險無新增。

---

## 10. QA 驗收

### Happy Path

| 場景 | 操作 | 期望結果 |
|---|---|---|
| 切換至未繳費 | 主任在 StudentsList.vue 點擊切換為未繳費 | API 200，`payment_status = "unpaid"`，`PayDate = NULL` |
| 切換至已繳費 | 主任在 StudentsList.vue 點擊切換為已繳費 | API 200，`payment_status = "paid"`，`PayDate = <今日>` |
| 新建課程含繳費日期 | UniversalClassScheduler 填入 paid_at，建立課程 | `GET /api/v1/student-classes` 回傳 `payment_status = "paid"` |

### Edge Cases

| 場景 | 操作 | 期望結果 |
|---|---|---|
| 後端保底：前端漏送 paid_at | PUT 僅帶 `{ payment_status: 'unpaid' }`，不帶 paid_at | 後端強制 `PayDate = NULL`，`Paid = 0` |
| Backfill dry-run | `php artisan migrate --pretend` | 印出 `UPDATE ... SET Paid=1 WHERE Paid=0 AND PayDate IS NOT NULL` SQL，不寫入 |
| Backfill 後查詢 | `SELECT COUNT(*) FROM StudentClass WHERE Paid=0 AND PayDate IS NOT NULL` | 回傳 0 |

### Error Cases

| 場景 | 操作 | 期望結果 |
|---|---|---|
| paid_at 格式錯誤 | PUT 帶 `paid_at: 'not-a-date'` | API 422 Validation Error |

### Revert-proof 驗證
- [ ] `git stash` 後重跑 `php artisan test tests/Feature/StudentClassPaidStatusTest.php` 及 `EnrollmentApiTest.php`，新增的 3 個 case 至少各 1 failure（確認測試真正覆蓋了 bug，而非誤綠）

---

## 11. 上線與維運

**部署步驟**
1. `git pull` 拉取最新 code
2. `php artisan migrate --pretend` 確認 Backfill Migration SQL 正確
3. 主任確認 dry-run 輸出的 27 筆記錄範圍合理（可選：提供 JSON 清單給主任審閱）
4. `cd /home/admin && ./deploy.sh`（含 php-fpm opcache 清除 + `php artisan migrate`）
5. Smoke Test：`GET /api/v1/student-classes`，確認無 `payment_status` 異常記錄
6. 驗證：`SELECT COUNT(*) FROM StudentClass WHERE Paid=0 AND PayDate IS NOT NULL` 回傳 0

**需要 DB Migration**（Backfill 清除 27 筆 PayDate）；無結構性 schema 變動。

**監控**
- 部署後觀察「主任回報繳費顯示異常」工單是否降為 0
- 可定期執行 `SELECT COUNT(*) FROM StudentClass WHERE Paid=0 AND PayDate IS NOT NULL` 作為巡檢指令；應恆為 0

**回滾方案**
- 前端：`git revert` 前端 commit，重新部署 frontend（`npm run build` + 靜態檔替換），影響範圍僅 StudentsList.vue 的 toggle 行為，約 10 分鐘可回滾
- 後端保底邏輯：`git revert` 後端 commit，重新部署，無資料損失，約 5 分鐘
- Backfill Migration：`down()` 為 no-op，`php artisan migrate:rollback` 不回寫 PayDate，需手動 SQL 還原（因此務必 staging 先行驗證後才執行 production）

---

## 12. 優先級

| 優先級 | 項目 | 執行 Agent |
|---|---|---|
| **P1（Should Have，盡快）** | StudentsList.vue 前端 toggle 修正 | `[DEV]` |
| **P1（Should Have，盡快）** | 後端 mapFrontendPayload 保底邏輯 | `[DEV]` |
| **P2（Should Have，本週）** | Backfill Script（清除 27 筆 PayDate） | `[DEV]` |
| **P2（Should Have，本週）** | 3 條新增測試方法 | `[TEST]` |
| **P3（Nice to Have）** | CHANGELOG + AI_REGRESSION_LESSONS | `[DOCS]` |

---

## 13. 風險 / 假設 / 開放問題

> ⚠️ 本節已先呼叫 `WebSearch` 查詢業界解法（Vue 3 payment status toggle field clearing；Laravel boolean flag data migration），結論整合如下。

### 風險

| # | 風險描述 | 等級 | 業界解法 / 緩解方案 |
|---|---|---|---|
| R-01 | Backfill 誤清了部分「Paid=0 但商業上應視為已繳費」的記錄 | 中 | 業界標準做法：**只讀審計優先（Read-Fix 策略）**。先以 dry-run 印出受影響的 27 筆（含 StudentID / 課程名 / PayDate），由主任確認後再執行；Script 納入版本控制，staging 先行驗證。參考：Laravel Migration 業界最佳實踐（多步驟：新增 temp column → 轉移 → 刪除舊欄位）。 |
| R-02 | 後端保底邏輯影響其他合法的 PUT 路徑（例如：只更新課程名稱的 PUT 也帶到 payment_status） | 低 | 保底邏輯的觸發條件限定為「payload 中明確帶有 `payment_status = 'unpaid'`」，不影響未帶此欄位的 PUT 請求；需測試覆蓋此邊界條件 |
| R-03 | `CourseManagement.vue` 的 toggle 修正（commit `de03daf`）與本次前端修正在同一元件附近，需確認不產生 merge conflict | 低 | 兩者修改不同元件（CourseManagement vs StudentsList），無衝突風險；部署前確認 `git status` 乾淨 |
| R-04 | 27 筆髒資料在 Backfill 執行期間（毫秒級）被同步讀取，出現短暫不一致 | 極低 | Backfill 為單一 UPDATE WHERE 指令，MySQL 行鎖保護，無需停機；業界標準做法：非停機 data fix 以小批次 UPDATE（LIMIT 100）執行，但 27 筆規模可單次完成 |

### 假設

| # | 假設內容 | 驗證方式 | 狀態 |
|---|---|---|---|
| A-01 | 27 筆 `Paid=0 AND PayDate IS NOT NULL` 的記錄，其財務語意是「確實已繳費但 Paid 被誤觸切為 0」，PayDate 為有效收款日期 | 主任已口頭確認：有 PayDate 的均為已繳費 | ✅ 已確認 |
| A-02 | `Paid` 欄位是 UI 顯示的快取標記，財務系統的真實收款記錄以 Invoice / Payment 表為準；因此清除 PayDate 不影響財務核帳 | 已由 `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 及 `建立課程繳費日期失效修正_930095c1` §9 確認 | ✅ 已確認 |
| A-03 | `StudentsList.vue` 的 `togglePaymentStatus` 是目前唯一仍有此 bug 的前端入口（`CourseManagement.vue` 已修正） | 已於 B1 偵查 grep 全 codebase 確認 | ✅ 已確認 |

### 開放問題

| # | 問題 | 業界處理方式 | Owner | 截止 |
|---|---|---|---|---|
| Q-01 | 27 筆髒資料中，是否有主任認為「應為已繳費」的記錄（即 A-01 假設不成立）？ | 提供 dry-run 輸出清單給主任確認；若有，需個別修正 Paid=1 而非清除 PayDate | 主任 | Backfill 執行前 |
| Q-02 | `CourseManagement.vue` 的 `togglePaymentStatus` 修正（commit `de03daf`）是否已驗收上線？若尚未，是否需一起驗收？ | 確認 git log，若已部署則標記為 D-02 完成；若未部署則納入本次部署 | CTO / 工程 | 開始實作前 |

---

## 14. Definition of Done

- [ ] **FR-001**（StudentsList.vue 前端 toggle 補送 paid_at: null）：驗證方式：`git diff frontend/src/pages/StudentsList.vue` 顯示 `paid_at: null` 出現在 unpaid payload 中；Chrome DevTools Network 確認 PUT request body 含 `"paid_at":null`
- [ ] **FR-002**（後端 mapFrontendPayload 保底）：驗證方式：`php artisan test tests/Feature/StudentClassPaidStatusTest.php` 全綠，且新增的 `test_toggle_to_unpaid_clears_paydate` 通過
- [ ] **FR-003**（Backfill Script dry-run 可執行）：驗證方式：`php artisan migrate --pretend` 印出含 `UPDATE StudentClass SET PayDate = NULL WHERE Paid = 0 AND PayDate IS NOT NULL` 的 SQL，無錯誤
- [ ] **FR-003**（Backfill 執行後 DB 乾淨）：驗證方式：`php artisan tinker --execute="use Illuminate\\Support\\Facades\\DB; echo DB::table('StudentClass')->whereNotNull('PayDate')->where('Paid',0)->count();"` 回傳 `0`（原 27 筆 Paid 已更新為 1）
- [ ] **FR-004**（端到端測試）：驗證方式：`php artisan test tests/Feature/EnrollmentApiTest.php` 全綠，含 `test_batch_create_with_paid_at_sets_payment_status_paid` 及 `test_batch_create_without_paid_at_sets_payment_status_unpaid`
- [ ] **Revert-proof**：驗證方式：`git stash && php artisan test tests/Feature/StudentClassPaidStatusTest.php tests/Feature/EnrollmentApiTest.php` 各新增 case 至少 1 failure；`git stash pop` 後全綠
- [ ] **CHANGELOG**：驗證方式：`git diff docs/CHANGELOG.md` 含 `2026-04-21` 新增條目
- [ ] **AI_REGRESSION_LESSONS**：驗證方式：`git diff docs/AI_REGRESSION_LESSONS.md` 含「前端切換繳費狀態時必須同步送出 paid_at: null」教訓條目
- [ ] **部署 Health Check**：驗證方式：`curl -sk https://localhost/api/v1/health` 回傳 HTTP 200（或等效 smoke test URL）
