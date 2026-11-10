# Bug Fix Plan — Steer billing unit course lookup

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1：課程查找與應收總額同時錯誤，可能影響未收款帳務判斷 |
| 根因類型 | 欄位缺失 + 前後端計價條件錯誤 |
| 根因摘要 | `CourseManagement.vue::editCourse` 沒有把 API 的 `rate_unit` 放入編輯表單，`submitEdit` 也沒送回；後端只在 Rate／堂數變動時重算 `Charge`，前端 `getCourseTotalFee` 對堂數制直接按堂計算，造成 billing unit 在 DB、API、UI 與總額計算間漂移。 |
| 錯誤行為 | 將課程由按堂改為按時計費後，仍送出／保留 `session`，列表固定顯示「每堂」，且總額以單價乘堂數。 |
| 預期行為 | `rate_unit` 由編輯表單、PUT、StudentClass、index response 到課程查找費用函式全程一致；`hour` 使用每小時費率乘實際總課程時數，`session` 使用每堂費率乘購買堂數。 |
| 影響範圍 | StudentClass update/index API、CourseEditForm、CourseManagement 課程卡／詳情／歷史卡與總費用計算；不改已入帳付款資料或其他薪資計費。 |
| **歷史比對** | 命中 GitHub #509／in-app #129（`rate_unit=hour` 與 UI/Charge 語意不一致）、#798（錯誤 Charge 被當成合法 delta）、§R76（F7：前後端 session/hour 規則必須一致）；本案是同一計價契約家族的再次出現。 |
| **根因層級** | 架構設計缺口：5 Whys 收斂為 billing unit 沒有成為跨層必需欄位與單一計價 read model，新增欄位後仍有明確 mapper、條件分支與 hardcode 文案未同步。 |
| **大廠參考** | Stripe Price 將 `unit_amount` 與 `quantity` 的 per-unit 語意明確分開，並以用量作為計價數量；見 [Stripe Products and prices](https://docs.stripe.com/invoicing/products-prices?dashboard-or-api) 與 [Create a price API](https://docs.stripe.com/api/prices/create)。開源 [billing_platform](https://github.com/sohan-shingade/billing_platform) 也將每筆 invoice 的 quantity、unit price、total 分開保存。AllTrue 因此採「保存 unit + 明確 quantity basis + 同一 read model」避免只換顯示文字。 |
| B1 偵查來源 | 直接讀取 `StudentClassController::index/update/mapFrontendPayload/calculateCourseChargeFromRate`、`CourseManagement.vue`、`coursePricing.js`、既有 billing tests、PRICING_CONTRACT、§R76；並完成 closed issue、MemPalace 與業界／開源查詢。 |

## 1. 文件資訊

- 功能名稱：課程查找 billing unit 一致性
- 版本：2026-09-02
- 狀態：Implementation
- 目標角色：主任／可編輯課程的教職員
- 關聯 Bug：Steer；歷史 #509、#798、#934、§R76

## 2. 業務背景與影響

按時計費課程的 `$750` 是每小時價格，不是每堂價格。若 8 堂、每堂 2 小時，總費用應為 `$750 × 16 = $12,000`；按堂課程仍應為「每堂 `$750` × 8」。修復後課程查找、API 與帳務快照都使用同一 billing unit。

## 3. 範圍

### In Scope

- 編輯表單載入與送出 `rate_unit`。
- StudentClass update 在 billing unit 變更時依最新 unit 重算未收款課程 Charge。
- index API 回傳明確且最新的 billing unit、單價與有效總額。
- 課程查找所有價格／總額入口依 unit 顯示與計算。
- 後端、前端 regression tests、部署與 production read-only 驗證。

### Out of Scope

- 不針對黃品皓或任何單一學生 hardcode。
- 不批次改寫既有 production 課程或已入帳 Invoice／Payment。
- 不改月結帳期、方案池、扣堂分鐘制、老師薪資或收據歷史快照規則。
- 不新增 DB schema；沿用現有 `StudentClass.rate_unit` 與 `TotalHours`。

## 4. RACI

| 角色 | 負責 |
|---|---|
| R | AI Agent |
| A | AI Agent |
| I | 使用者／Founder（部署與 production evidence） |

## 4b. Dependencies

- 無 migration 依賴；`rate_unit` 欄位與現有 API 已存在。
- 必須通過 required CI、治理 preflight 與 deploy workflow。
- Production 驗證只能使用既有 read-only diagnose workflow。

## 5. Acceptance Criteria

### AC-001：billing unit 編輯鏈路

- AC-001-a：編輯課程切換 `session → hour` 並儲存後，DB row、PUT response、重新 GET/index response 均為 `rate_unit=hour`。
- AC-001-b：按堂課程儲存後仍為 `rate_unit=session`，Charge 仍為 Rate × SessionCount。

### AC-002：課程查找顯示與總額

- AC-002-a：`rate_unit=hour`、每小時 750、8 堂、每堂 2 小時，課程查找顯示「每小時 $750」，總費用為 `$12,000`。
- AC-002-b：`rate_unit=session`、每堂 750、8 堂，課程查找顯示「每堂 $750」，總費用為 `$6,000`。

### AC-003：production 案例

- AC-003-a：production read-only evidence 找到大安黃品皓課程後，回傳 billing unit 為 hour、rate 為 750，UI/計算結果符合實際總課程時數。
- AC-003-b：同一 evidence 中抽查按堂計費課程，顯示與總額均維持按堂口徑。

## 6. 功能需求

- FR-001：編輯表單必須保留並送出目前 billing unit。
- FR-002：後端更新只要 Rate、堂數或 billing unit 任一變更，就必須以最新 unit 重算未收款課程 Charge。
- FR-003：API 必須回傳 canonical `rate_unit`，不得由前端猜測或用顯示文字反推。
- FR-004：課程查找價格 label 與總額 calculator 必須共用 `rate_unit`；hour 使用實際總課程時數，session 使用購買堂數。
- FR-005：session 分支與既有 package／monthly 行為不得 regression。

## 7. 非功能需求

不適用效能型需求；本次為計價語意與資料契約一致性修復。保留既有 API scope、角色權限與付款鎖定。

## 8. 技術方向

- `frontend/src/pages/CourseManagement.vue`：編輯 form hydration、PUT payload、課程卡／詳情／歷史卡的 billing unit label。
- `frontend/src/lib/coursePricing.js`：收斂 session/hour 的 per-unit 與 total fee calculator，讓 payment type 不覆蓋 rate unit 語意。
- `backend/app/Http/Controllers/StudentClassController.php`：更新欄位驗證、Charge recompute trigger 與 index response contract。
- `backend/tests/Feature/StudentClass...`、`RateUnitChargeCalculationTest`：鎖定 update/index/full-chain 行為。
- `frontend/src/lib/coursePricing.test.js` 與 CourseManagement contract test：鎖定兩種 unit 的顯示與金額。
- 取捨：本次沿用既有欄位與計價 helper，不引入新 billing domain 或資料遷移；以 canonical response + shared calculator 降低修改面。

## 8b. Decision Log

| 日期 | 替代方案 | 選擇與理由 |
|---|---|---|
| 2026-09-02 | 只改「每堂／每小時」文字 | 不採用；會留下 DB、Charge 與總額錯誤。 |
| 2026-09-02 | 只修前端總額 | 不採用；下一次儲存或帳務 API 仍會使用舊 Charge。 |
| 2026-09-02 | 修正完整 payload、backend recompute、shared calculator | 採用；涵蓋 DB → API → UI → total fee 全鏈路。 |

## 9. 資安與存取控制

課程查找含學生 PII 且 API 受既有 campus／teacher scope 保護。本次不新增讀取權限、不放寬 update authorization；production 驗證只使用最小範圍 read-only workflow，避免在 log 暴露不必要個資。

## 10. QA 驗收

- Happy path：hour 750 × 16 小時；session 750 × 8 堂。
- Edge：90 分鐘課程、zero/legacy Charge fallback、package/monthly 不被 session/hour 修復誤改。
- Error：非法 billing unit 被拒絕；無權限課程仍 403；已收款鎖定規則不變。

### Revert-proof 驗證

- [ ] 暫存還原實作變更後重跑每個新增 regression case，至少一個 hour case 與一個 session case 必須 failure，確認測試不是誤綠。

## 11. 上線與維運

- 無 migration。
- PR required checks 通過後 squash merge；由 `.github/workflows/deploy.yml` 部署 merge SHA。
- 部署後執行 health check 與既有 production read-only diagnose workflow，保存 API／DB／計算 evidence。
- 回滾：deploy workflow 以既有 prior SHA 回滾 code；本次不改 production data，無資料 rollback。

## 12. 優先級

- P1；執行 Agent：AI Agent。

## 13. 風險／假設／開放問題

- 假設既有 production `TotalHours` 已代表該課程的總計費時數；若 evidence 顯示 legacy row 不一致，只提供 read-only discrepancy，不在本次修復中改資料。
- 風險：已建立但未收款的課程 Charge 會因 unit 切換重算；這符合計價契約，但已收款資料仍受既有付款鎖定保護。
- 開放問題：黃品皓 production row 的實際堂數／總時數需由 read-only workflow 取證後填入驗證紀錄，不以學生姓名推導計價。

## 14. Definition of Done

- [ ] Full-chain tests：`cd backend && vendor/bin/phpunit --filter='StudentClassRateUnitUpdateTest|RateUnitChargeCalculationTest'` 回傳 0。
- [ ] Frontend tests：`cd frontend && npm test -- --run coursePricing.test.js` 回傳 0。
- [ ] Revert-proof：暫存實作變更後新增 hour/session case 各至少 1 failure。
- [ ] Static checks：`make agent-preflight` 與 CI required checks 通過。
- [ ] Deploy：`gh run` 顯示 `.github/workflows/deploy.yml` merge SHA 成功。
- [ ] Health：production health endpoint HTTP 200 且 status ok。
- [ ] Production evidence：read-only workflow artifact 同時含黃品皓的 unit、rate、total hours、expected total 與按堂對照案例。
- [ ] Documentation：`docs/CHANGELOG.md` 與 `docs/AI_REGRESSION_LESSONS.md` 含本次防再犯規則與 PR／deploy 證據。
