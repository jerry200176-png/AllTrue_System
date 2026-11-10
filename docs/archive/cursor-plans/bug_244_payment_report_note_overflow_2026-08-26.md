# Bug Fix Plan：確認入帳遇長備註失敗（in-app #244）

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1（帳務核銷失敗，但交易具備 atomic rollback，未形成錯帳） |
| 根因類型 | 欄位長度契約不一致＋交易競態邊界缺口 |
| 根因摘要 | `PaymentReportController::confirm` 把可達 500 字的 `payment_reports.note` 寫入舊 `Payment.Note` 255 字欄位；MySQL 回 1406，確認交易因此回滾。確認流程另未在交易內鎖定 PaymentReport，併發重試有重複入帳風險。 |
| 錯誤行為 | 主任按「確認入帳」後回傳伺服器錯誤，Payment、Invoice、PaymentReport 狀態都未完成。 |
| 預期行為 | 合法長度的備註可完整保留；確認時 Payment、Invoice、課程付款狀態與 PaymentReport 在同一交易內成功，重複請求不會重複建立收款。 |
| 影響範圍 | 主任／超級管理員的 `PUT /api/v1/payment-reports/{id}/confirm` 與批次確認；既有帳務資料不刪除、不截斷。 |
| **歷史比對** | F6（輸入邊界／長度）：#1732、#2047、§F6／§R111；F7／R30：帳務狀態仍須維持 PaymentReport、Payment、Invoice、Paid 的單一交易一致性。Sentry #2064 是本案當次 crash；舊 #1911／in-app #240 是不同的已繳列表篩選問題。 |
| **根因層級** | 架構設計缺口：付款回報新增可較長備註後，沒有同步更新收款帳本欄位契約；5 Whys 結論是「前端可輸入」與「下游帳本可承載」沒有單一邊界，且核銷鎖定點不在狀態機交易內。 |
| **大廠參考** | Stripe 將付款狀態視為明確生命週期，並以可安全重試的 idempotency 避免重複請款；Laravel Cashier Stripe（MIT，16.x，commit `2070664b51922592202c48c164d0c4d37f0b17cb`）以受控付款動作與例外流程封裝狀態轉換；Saleor（BSD-3-Clause，main，commit `ac7f0a133287b1e46512bf50d39023e251e5899d`）將付款交易狀態與事件／資料模型分開並以測試守住轉換。採用本專案相同精神：欄位契約對齊、完整保留來源備註、交易內鎖定狀態；不複製其程式碼。 |
| B1 偵查來源 | Production Case Dump #32955929844、Bug Detail Dump #32955318599、Sentry GitHub mirror #2064、程式碼 `PaymentReportController::confirm`、資料庫 migration 與回歸家族紀錄。 |

## 1. 文件資訊

- 功能：主任付款回報確認入帳
- 版本：2026-08-26
- 狀態：修復中
- 目標角色：director、super_admin
- 關聯：in-app #244、GitHub #2065、Sentry #2064

## 2. 業務背景與影響

洪家溱的付款回報包含跨課程查核說明，按確認入帳時因備註欄位不一致而無法核銷。修復後，完整說明會留在付款紀錄；失敗重試不會重複建立 Payment，錯誤也不會留下半套帳務資料。

## 3. 範圍

### In Scope

- 將 `Payment.Note` 的承載能力與既有 PaymentReport 備註契約對齊。
- 在確認交易內重新讀取並鎖定 PaymentReport，重新檢查 pending 狀態。
- 補長備註、重複確認與 rollback 不留半套資料的 regression tests。
- 更新 CHANGELOG、F6／帳務防復發紀錄與部署驗收證據。

### Out of Scope

- 不改付款金額、Invoice 金額計算、Paid 語意或催繳列入條件。
- 不改 `directorRecord`、收據格式、前端頁面、權限角色與既有資料修復。
- 不直接修改 production 的洪家溱資料；上線後由既有核銷入口重新操作，資料寫入仍須通過同一交易。

## 4. RACI

| 角色 | 責任 |
|---|---|
| R | AI Agent：程式、測試、文件、CI、部署與驗證 |
| A | AI Agent：依 production control contract 執行 |
| C | 無；若發生資料修復需求，另依帳務 T3 邊界申請 |
| I | CEO／回報主任：收到上線與驗收通知 |

## 4b. Dependencies

- 依賴既有 `payment_reports.note` migration（已在 main）。
- 需要 production deploy workflow 執行 schema migration；沒有前置 PR。
- 不新增外部服務或資料表索引。

## 5. Acceptance Criteria

### AC-001：合法長備註可確認入帳

- AC-001-a：pending PaymentReport 的 500 字備註確認成功，回 200，Payment.Note 與原備註完全相同。
- AC-001-b：確認完成後 PaymentReport 為 confirmed、Payment 存在、Invoice／StudentClass 的付款狀態正確。

### AC-002：確認重試與併發安全

- AC-002-a：同一 PaymentReport 在交易內被鎖定後，只有 pending 請求可建立 Payment。
- AC-002-b：已確認的重試回 422，Payment 數量不增加，不能重複入帳。

### AC-003：失敗原子性

- AC-003-a：任何確認交易例外後，PaymentReport 仍為 pending，Payment／Invoice／StudentClass 不留下部分更新。

## 6. 功能需求

- FR-001：Payment.Note 必須承載既有 PaymentReport.note 的最大合法長度，不可靜默截斷。
- FR-002：confirm 必須在 DB transaction 內鎖定 PaymentReport 並重新檢查 pending 狀態。
- FR-003：既有付款重複防護與帳務狀態更新順序必須維持。

## 7. 非功能需求

不適用：本案不是效能問題；但 migration 必須可回滾，且 rollback 遇到超過 255 字資料時要 fail closed，不得截斷帳務備註。

## 8. 技術方向

- `backend/database/migrations/`：新增 Payment.Note 型別對齊 migration，up 擴充為可承載長文字，down 先檢查資料長度再決定是否可安全回復。
- `backend/app/Http/Controllers/PaymentReportController.php::confirm`：把 PaymentReport 的鎖定與狀態檢查放入 transaction，保留既有帳務鎖與原子更新。
- `backend/tests/Feature/PaymentReportApiTest.php`：以 500 字備註、已確認重試與狀態完整性測試鎖定契約。

## 8b. Decision Log

| 日期 | 替代方案 | 決定與理由 |
|---|---|---|
| 2026-08-26 | 在寫入 Payment 前截斷備註 | 不採用；會遺失主任提供的核帳脈絡，且把合法輸入變成不可追溯資料。 |
| 2026-08-26 | 只把 Payment.Note 改成 255 字以上字串 | 不採用；無法保證未來備註長度，也不符合長文字欄位語意。 |
| 2026-08-26 | Payment.Note 改為 text，並在 confirm 交易內鎖定報表 | 採用；對齊現有 500 字輸入契約、保留完整備註，並讓重試在狀態邊界安全停止。 |

## 9. 資安與存取控制

本案不新增權限。既有 RequireRole／RequireCampus 邊界維持；鎖定的 PaymentReport 仍只能由原本可確認該分校資料的角色處理。測試維持跨分校拒絕案例。

## 10. QA 驗收

- Happy Path：短備註與 500 字中文備註均可確認，Payment／Invoice／Paid／report 狀態一致。
- Edge：空備註使用既有 fallback；已確認報表重試不增 Payment；批次確認仍走同一 confirm。
- Error：交易例外 rollback 後不留 Payment、Invoice 金額或課程付款狀態半套。

### Revert-proof 驗證

- [ ] 先暫存修復，再移除新增長備註測試所依賴的防護，測試至少 1 case failure；恢復工作樹後重跑全綠。

## 11. 上線與維運

- 有 migration：先執行 deploy workflow 的備份／migration／health gate。
- 部署後確認 production SHA、`GET /api/v1/health` 與 DB-dependent API。
- 以 read-only probe 確認 #244 的 report 仍 pending 且沒有半套 Payment／Invoice，再由主任從介面重新確認入帳。
- 回滾：若 migration／CI／smoke 失敗，revert merge SHA；schema down 只有在沒有超長資料時才允許，否則保留 text 並修復程式，不截斷資料。

## 12. 優先級

P1；執行 Agent：AI Agent。

## 13. 風險／假設／開放問題

- 假設既有 MySQL 與測試資料庫支援將 Payment.Note 改為 TEXT；migration 會在 CI 與 production deploy 驗證。
- 風險是舊帳務工具依賴 Note 非 NULL；程式保留空字串 fallback，migration 只擴充承載能力，不改既有值語意。
- Stripe 的 idempotency 與狀態生命週期原則見官方文件：[Idempotent requests](https://docs.stripe.com/api/idempotent_requests?lang=curl)、[PaymentIntent lifecycle](https://docs.stripe.com/payments/paymentintents/lifecycle)。
- 開源參考固定版本：[Laravel Cashier Stripe commit 2070664](https://github.com/laravel/cashier-stripe/tree/2070664b51922592202c48c164d0c4d37f0b17cb)（MIT）、[Saleor commit ac7f0a1](https://github.com/saleor/saleor/tree/ac7f0a133287b1e46512bf50d39023e251e5899d)（BSD-3-Clause）；只採納狀態／交易設計原則，不複製程式碼。

## 14. Definition of Done

- [ ] FR-001：`vendor/bin/phpunit --filter PaymentReportApiTest` 全綠，長備註測試確認完整保存。
- [ ] FR-002：同一測試檔的已確認重試案例回 422 且 Payment 數量不變。
- [ ] FR-003：`vendor/bin/phpunit --filter PaymentReportApiTest` 的既有帳務案例全綠。
- [ ] Revert-proof：新增測試在移除修復後至少 1 case failure，恢復後全綠。
- [ ] CHANGELOG：`git diff docs/CHANGELOG.md` 有 2026-08-26 修復條目。
- [ ] CI：GitHub required checks 全綠，PR 已 merge。
- [ ] Health：production `GET /api/v1/health` HTTP 200，且 production SHA 等於 merge SHA。
- [ ] In-app #244：production bug status 為 resolved 且有公開留言；等待回報者驗收後才 closed。
