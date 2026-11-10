# Bug Fix Plan — Parent Portal Monthly Billing Status Clarity

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2 |
| 根因類型 | 顯示邏輯錯誤 / 帳期欄位缺失 |
| 根因摘要 | `ParentPortalController::dashboard()` 用 `Carbon::now()` 作為所有月結課程的月份標籤與統計月份，`ParentPortal.vue` 又以 `is_stopped` 優先於 `paid` 顯示狀態；同時家長端 `payment_alerts` 把堂數制「剩餘 <= 2 堂」當成家長待處理通知，造成已繳舊約剩 1 堂時仍被家長理解成待繳費。 |
| 錯誤行為 | 月結課程若是未來月份課程，家長端仍顯示目前月份；已繳但 `Stop=1` 的課程顯示「已停課」而非付款已完成或結案語意；已繳堂數制舊約剩 1 堂時仍出現在家長繳費通知區。 |
| 預期行為 | 月結課程顯示對應服務月份 / 帳期月份；付款狀態與課程生命週期分層顯示，不讓「已停課」覆蓋「已繳費」；家長端付款通知只顯示需要家長付款或確認的項目，不把內部續課提醒誤包裝成待繳費。 |
| 影響範圍 | 家長入口 `ParentPortal.vue`、`GET /api/v1/parent/dashboard`、月結課程/Invoice 顯示。 |
| B1 偵查來源 | 截圖 `S__57647109.jpg` + 程式碼檢查：`ParentPortalController::dashboard()`、`ParentPortal.vue`。 |

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 家長端月結課程帳期與付款狀態顯示修復 |
| 日期 | 2026-04-29 |
| 狀態 | Draft — 等使用者批准進 DEV |
| 目標角色 | 家長、主任、老師 |
| 關聯 Bug | GitHub issue 待建立/更新 |

## 2. 業務背景與影響

家長端目前把「今天所在月份」當成月結課程的顯示月份，導致 5 月課程在 4 月底被顯示為「4月已上」。若同時有已繳費但被後台暫停/結案的課程，家長看到「已停課」會誤以為課程或付款異常。

第三個回饋是舊堂數制課程轉月結後，舊約仍有 `RemainingSessions=1`；主任端低堂數提醒是合理的內部續課訊號，但家長端「繳費通知」不應把已繳費舊約剩餘堂數解讀成待繳費。

修復後預期行為：
- 家長看到的是「5月已上 / 5月預定」而非今天月份。
- 付款已完成時，主狀態優先表達「已繳費」或「已繳費・已結案」，而不是單獨顯示「已停課」。
- 家長端繳費通知只顯示「未繳費 / 部分繳費 / 需要家長處理」項目；已繳費的低堂數舊約不在待繳費區造成誤解。
- 後台仍可保留 `Stop=1` 的結案/停課效果，不因此改變繳費提醒規則。

## 3. 範圍

In Scope:
- `GET /api/v1/parent/dashboard` 增加月結課程級別的顯示月份/帳期欄位。
- 家長端「進行中的課程」卡片使用課程級別月份文案。
- 家長端狀態 badge 分層：付款狀態優先，生命週期狀態輔助。
- 家長端 `payment_alerts` 與主任端 `alerts/tuition` 分離：家長端不得把已繳費低堂數提醒顯示成待繳費。
- 新增後端 regression tests 與前端顯示 helper 測試/可驗證邏輯。
- 更新 `CHANGELOG.md` 與防再犯記錄。

Out of Scope:
- 不修改主任儀表板 `AlertController::tuition` 列入條件。
- 不取消主任端堂數制「已繳但剩 <= 2 堂」的續課提醒。
- 不修改課程續約、核帳、Invoice payment sync 的核心扣款/付款資料。
- 不新增 migration，除非 DEV 偵查證明現有 `Invoice.billing_period` / `StartDate` 不足。
- 不自動改 production 資料，例如批次恢復停課或改 Paid。

## 4. RACI

| 任務 | R | A | C | I |
|---|---|---|---|---|
| 規則確認 | AI Agent | AI Agent | 使用者 | 老師/主任 |
| 後端修復 | AI Agent | AI Agent | 使用者 | - |
| 前端修復 | AI Agent | AI Agent | 使用者 | - |
| 測試與 Review | AI Agent | AI Agent | 使用者 | - |
| 部署監控 | AI Agent | AI Agent | 使用者 | - |

## 4b. Dependencies

- 目前 PR #206 已 merge 但 production deploy 仍受 GitHub billing/spending limit 阻塞；本 bug 的實作 PR 可以先開，但上線仍需等 #194 解決。
- 需確認月結課程是否已可靠建立 `Invoice.billing_period`；若沒有，fallback 使用 `StudentClass.StartDate` 的月份。

## 5. Acceptance Criteria

### AC-001：未來月份月結課程顯示服務月份
- AC-001-a：今天為 4 月、月結課程 `StartDate` 或未結清 Invoice `billing_period` 為 2026-05 時，家長端課程卡顯示 `5月已上`。
- AC-001-b：該課程 5 月尚未上課時，`attended_this_month` 顯示 0，且不誤顯示 4 月統計。

### AC-002：已繳費且停止/結案課程不造成家長誤解
- AC-002-a：月結課程 `Paid=1` 且 `Stop=1` 時，家長端主付款狀態顯示已繳費語意。
- AC-002-b：若仍需呈現生命週期，使用輔助文案如 `已結案` / `課程已結束`，不以紅色「已停課」作為唯一狀態。

### AC-003：未繳費停止課程仍保留風險提示
- AC-003-a：`Paid=0` 且 `Stop=1` 的課程不可被誤顯示為已繳費。
- AC-003-b：若顯示，應明確表示需聯絡補習班確認，而不是隱藏付款風險。

### AC-004：主任提醒規則不被改壞
- AC-004-a：`AlertController::tuition` 既有月結/堂數提醒測試仍通過。
- AC-004-b：家長端顯示修復不改變主任端繳費提醒列入條件。

### AC-005：已繳舊堂數制課程不顯示成家長待繳費
- AC-005-a：堂數制舊約 `Paid=1` 且 `remaining_sessions=1` 時，家長端 `payment_alerts` 不顯示為待繳費。
- AC-005-b：堂數制舊約 `Paid=0` 且 `remaining_sessions=1` 時，家長端仍顯示待繳費或需處理提醒。
- AC-005-c：主任端低堂數續課提醒仍保留，不受家長端通知過濾影響。

## 6. 功能需求 FR

- FR-001：後端應為每個月結課程回傳 `display_month_label` 或等價欄位，來源優先序為未作廢 Invoice `billing_period` → `StartDate` → 今天月份。
- FR-002：後端應依課程顯示月份計算 `attended_this_month`，而非全域今天月份。
- FR-003：前端月結卡片應使用課程級別月份文案，不再只讀 `dashboard.current_month_label`。
- FR-004：前端狀態 badge 應將付款狀態與課程生命週期拆開；`paid=true` 不應被 `is_stopped=true` 蓋成單一「已停課」。
- FR-005：後端 response 應保持向後相容，保留既有欄位，新增欄位供新版前端使用。
- FR-006：家長端 `payment_alerts` 應只包含需要家長付款/確認的項目；已繳費低堂數提醒若要展示，必須另用「續課提醒」語意，不可放在待繳費區。

## 7. 非功能需求 NFR

不適用效能型 NFR。此修復為單一學生 dashboard 查詢與顯示邏輯；新增 Invoice 查詢需避免 N+1，使用 batch query / map。

## 8. 技術方向

- `ParentPortalController::dashboard()`：新增月結課程 billing period resolution；以 batch `Invoice::notVoided()` 查詢 class IDs 的最新/最相關帳期。
- `ParentPortalController::dashboard()`：將 `attended_this_month` 改為依每個月結課程的 display period 計算。
- `ParentPortalController::dashboard()`：調整家長端 `payment_alerts`，不要沿用主任端低堂數提醒語意；已繳費且只是低堂數的舊約不列入待繳費。
- `ParentPortal.vue`：新增顯示 helper，付款 badge 與 lifecycle badge 分離。
- Tests：新增 `ParentPortalDashboardTest` 或擴充既有 parent portal feature tests，覆蓋未來月份月結、stopped+paid 狀態、已繳舊約剩 1 堂不列待繳費。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-04-29 | 付款狀態優先於停課顯示，但不刪除 lifecycle 資訊 | 單純把 `Stop=1` 隱藏 | 業界帳務 UI 通常將 invoice/payment status 與 subscription/course lifecycle 分層，避免家長誤解付款狀態。 |
| 2026-04-29 | 帳期月份優先用 Invoice `billing_period`，fallback `StartDate` | 永遠用今天月份 | 月結是 service period/billing period 語意，不能由瀏覽當天決定。 |
| 2026-04-29 | 家長端付款通知不顯示已繳費低堂數舊約 | 沿用主任端低堂數續課提醒 | 主任端需要營運提醒；家長端看到「繳費通知」會理解成欠費，兩者語意不同。 |

## 9. 資安與存取控制

涉及家長入口與學生資料可見性，但不改 auth/token 流程。必須確認：
- 仍只透過 parent token 查該 session 的學生。
- 不新增跨學生/跨分校資料查詢。
- Invoice 查詢限定在該學生的 class IDs 內。

## 10. QA 驗收

Happy Path:
- 4 月查看 5 月月結課程，顯示 `5月已上 0` / `預定 5 堂/月`。
- 已繳費月結課程顯示已繳費語意。

Edge:
- 沒有 Invoice 的 legacy 月結課程 fallback `StartDate`。
- `Stop=1, Paid=1` 與 `Stop=1, Paid=0` 分別顯示不同語意。
- 同一學生多門月結課程有不同月份時，各自顯示自己的月份。
- 堂數制舊約 `Paid=1, remaining=1` 不出現在家長待繳費；`Paid=0, remaining=1` 仍出現。

Revert-proof 驗證:
- 新增測試後，revert 後端 period resolution 時至少 AC-001 測試失敗。
- revert 前端 badge helper 時至少 AC-002 顯示測試/快照或 helper test 失敗。

## 11. 上線與維運

- Migration：預期不需要。
- 部署：PR → CI green → merge → `deploy.yml` 自動部署；目前需等 #194 GitHub billing/spending limit 解決。
- Observability：部署後檢查 `/api/v1/health`，並用家長測試帳號/API 驗證目標學生 dashboard response。
- 回滾：若顯示異常，用 PR revert 回滾前後端顯示邏輯；不需 DB rollback。

## 12. 優先級

P2：家長誤解繳費狀態，影響信任與客服成本；目前可人工官@通知 workaround，但應排入下一個 billing/parent portal fix PR。

執行 Agents:
- `[DEV]` 後端 + 前端
- `[TEST]` regression + revert-proof
- `[REVIEW]` 權限/PII + billing rule review
- `[DOCS]` CHANGELOG + AI_REGRESSION_LESSONS
- `[OPS]` deploy + smoke test

## 13. 風險 / 假設 / 開放問題

業界參照：SaaS billing UI 通常將 invoice status（paid/open/past due）與 subscription lifecycle（active/paused/canceled）分層；客戶端顯示應以 invoice/payment 作為帳務真相，service period/billing period 作為月份來源。

風險:
- 若把 `Stop=1, Paid=1` 重新顯示在「進行中的課程」，可能讓家長以為仍有未來課。需改文案為 `已繳費・已結案` 或在進行中區塊中淡化/移至歷史區塊。
- 若只改前端 badge，不改月份統計，仍會保留 `4月已上` 誤導。
- 若直接修改 `RemainingSessions` 資料作為唯一修復，未來同類轉換仍會復發；系統應區分主任端續課提醒與家長端待繳費通知。

開放問題（需使用者確認）:
1. `Stop=1, Paid=1` 的月結課程要留在「進行中的課程」區塊，還是移到「已結束/歷史課程」區塊？
2. 面向家長的文案偏好：`已繳費・課程已結束`、`已繳費・已結案`、或只顯示 `已繳費`？
3. `Stop=1, Paid=0` 是否要顯示 `請洽櫃台確認`，避免家長自行判讀？
4. 已繳費但剩 1～2 堂的舊約是否完全不顯示給家長，或改放「課程即將結束，櫃台會協助安排」這種非繳費提醒？

## 14. Definition of Done

- [ ] FR-001/FR-002：驗證方式：GitHub Actions PHPUnit parent portal tests 通過，response 含 5 月月結課程 `display_month_label=5月` 且 `attended_this_month=0`。
- [ ] FR-003/FR-004：驗證方式：Vite build 通過，前端 helper 對 `paid=true,is_stopped=true` 回傳已繳費主狀態。
- [ ] FR-005/FR-006：驗證方式：舊欄位 `current_month_label`、`attended_this_month` 仍存在；`Paid=1, remaining=1` 不出現在家長待繳費測試，`Paid=0, remaining=1` 仍出現。
- [ ] Revert-proof：驗證方式：revert period resolution 或 badge helper 後，新增 regression tests 至少 1 case failure。
- [ ] CHANGELOG：驗證方式：`git diff docs/CHANGELOG.md` 含本修復條目。
- [ ] Health check：驗證方式：deploy 後 `curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `status=ok`。
