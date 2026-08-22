# Bug Fix Plan：未收款課程堂數／費用更正（#1901）

## 0. 根因確認（Root Cause）
| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 流程／資料模型缺口 |
| 根因摘要 | `BillingContractLockGuard` 在第一筆扣堂後鎖定 `SessionCount`；系統只有一般編輯與結案重開兩條路，沒有針對尚未收款、但購買堂數輸入錯誤的具名更正流程。 |
| 錯誤行為 | 洪睿淵的課程維持 8 堂與 8 堂金額，主任無法改成實際應收 7 堂／7,700 元，因而無法產生正確收據。 |
| 預期行為 | 主任可在未收款且無待處理對帳時，將堂數與費用安全下修；已發生的扣堂／上課紀錄不變，多出的未上課排程取消，餘額重算。 |
| 影響範圍 | 堂數制、非共用方案、未收款課程；主任與超級管理員的更正 API／課程管理操作。 |
| **歷史比對** | GitHub #1901；復發家族 F1（狀態／帳務收尾與額度一致性），相關 R59、R110–R112；既有 PR #1907、#1912、#1915 提供轉移／重指派工具但未處理未收款合約下修。 |
| **根因層級** | 架構設計缺口；5 Whys 結論：鎖定是為了避免追溯改寫扣堂歷史，但缺少「未收款帳務更正」這個與一般編輯分離的交易邊界。 |
| **大廠參考** | Stripe 將已建立帳務的修正拆成 credit note／調整交易，而不是直接覆寫原付款紀錄；ERPNext 對已提交單據採 amend／新版本方式保留歷史。本次採相同邊界精神，以具名、權限受限、稽核的更正 command 處理未收款例外，而不放寬一般 PUT。 |
| B1 偵查來源 | `docs/AI_REGRESSION_LESSONS.md`、G-011、`BillingContractLockGuard`、`SessionDeductionService::recomputeCounters()`、PaymentReport／Invoice 流程與 #1901 工單留言。 |

## 1. 文件資訊
- 功能：未收款堂數／費用更正
- 版本：2026-08-22
- 狀態：DEV
- 目標角色：director、super_admin
- 關聯 Bug：GitHub #1901

## 2. 業務背景與影響
主任需要在收款前修正實際應收堂數。若只能刪除或改寫已上課資料，會破壞扣堂與收據一致性；若只能結案重開，則主任無法直接完成當期正確收款。

修復後，洪睿淵的理化課可由 8 堂／8,800 元更正為 7 堂／7,700 元，保持未繳費狀態，並讓後續收據讀到 7 堂／7,700 元。

## 3. 範圍
### In Scope
- 新增主任／超級管理員專用的未收款堂數更正 API。
- 新增課程管理的操作入口與確認視窗。
- 驗證堂數制、非共用方案、未收款、無有效付款／待對帳回報、不得低於已使用堂數。
- 更正後取消超出堂數的 scheduled 排程、重算餘額、寫入稽核紀錄。
- 回歸測試、CHANGELOG、AI regression lesson 與 API 文件。

### Out of Scope
- 不放寬一般 `PUT /student-classes/{id}` 的計費契約鎖定。
- 不修改已收款、已確認／待處理繳費回報或共用課程包。
- 不改寫已上課、出缺勤、LearningRecord、扣堂 ledger。
- 不實作付款後 credit note／發票作廢重開流程。
- 不直接修改 production 資料庫；正式資料更正須透過部署後的受控 API 執行。

## 4. RACI
- R：AI Agent（後端、前端、測試與文件）
- A：AI Agent（依既有治理流程提交 branch／PR）
- C：既有帳務與課務規則文件
- I：使用本功能的主任與維運人員

## 4b. Dependencies
- 無 migration 依賴；沿用現有 `StudentClass`、`ClassSession`、`PaymentReport`、`security_audit_events`。
- 依賴既有 `SessionDeductionService::recomputeCounters()` 與 `BillingContractLockGuard`。
- 正式使用前依既有 CI／PR 流程部署前端與後端。

## 5. Acceptance Criteria
### AC-001：未收款下修
- AC-001-a：主任將 8 堂／8,800 元更正為 7 堂／7,700 元，API 回 200，課程為 7 堂／7,700 元且仍為未繳費。
- AC-001-b：已上課紀錄與扣堂 ledger 數量不變，超出新堂數的未上課 scheduled 堂次變為 cancelled。
- AC-001-c：`RemainingSessions` 依 canonical deduction counter 重算。

### AC-002：付款安全閘門
- AC-002-a：已收款、有效付款、待處理或已確認回報時，API 回 409 且資料不變。
- AC-002-b：已收款課程不會透過此 API 變回未繳費。

### AC-003：契約邊界
- AC-003-a：月結、共用方案、按時計費、低於已使用堂數時，API 回 422 且資料不變。
- AC-003-b：一般課程 PUT 在已有扣堂紀錄時仍回 `billing_contract_locked`。

### AC-004：權限與稽核
- AC-004-a：跨分校主任、老師與未授權角色不能執行更正。
- AC-004-b：成功更正寫入不含 PII 的舊／新堂數、舊／新費用與固定 reason code 稽核事件。

## 6. 功能需求 FR
- FR-001：系統應提供受角色與分校範圍保護的具名更正 command。
- FR-002：系統應只允許未收款、無有效收款與無待處理回報的課程。
- FR-003：系統應確保更正後堂數不低於 observed used sessions，且按堂費用符合單堂費率公式。
- FR-004：系統應保留歷史堂次與 ledger，僅取消超額 scheduled 堂次並重算衍生餘額。
- FR-005：系統應在成功更正後讓既有收款／收據讀取新的 SessionCount 與 Charge。

## 7. 非功能需求 NFR
不適用效能型 NFR；本修復是單一課程的交易一致性與權限邊界修復。交易需使用 row lock，避免收款與更正同時寫入造成競態。

## 8. 技術方向
- `StudentClassController::billingCorrection`：新增具名 endpoint，執行授權、付款閘門、契約驗證、排程取消、counter 重算與 audit。
- `BillingContractLockGuard`：維持不變，避免普通編輯繞過歷史鎖定。
- `SecurityAuditEvent`：補充費用舊／新值的允許 metadata key。
- `CourseManagement.vue`：新增主任操作入口、確認視窗與錯誤提示。
- `StudentClassBillingCorrectionTest`：覆蓋 happy path、付款閘門、使用量、權限與 audit。

取捨：這是治標的安全操作缺口修復；更完整的付款後 credit note／不可變帳務版本模型另列為後續架構工作，不在本次放寬契約鎖定。

## 8b. Decision Log
- 2026-08-22：不移除 `BillingContractLockGuard`；選擇具名 command，因為普通 PUT 無法區分誤輸入與追溯改寫。
- 2026-08-22：只允許下修且不得低於已使用堂數；增加堂數沿用加購／續報，避免更正 API 變成一般加購入口。
- 2026-08-22：不接受任意費用；按堂課程必須符合單堂費率 × 堂數，避免堂數與收據金額再次分離。

## 9. 資安與存取控制
- Route 僅開放 director／super_admin，並套用 `require_campus` 與既有 password-change gate。
- Controller 再做 StudentClass 分校授權，避免只依 route middleware。
- 不接受 Paid=1、有效付款、pending／confirmed report。
- 稽核 metadata 不記學生姓名、電話、LINE 或輸入內容，只記 hash reference 與帳務變更數值。

## 10. QA 驗收
- Happy Path：8→7、8,800→7,700；已上 7 堂保留，尾端 scheduled 取消，餘額為 0。
- Edge：new count 等於已使用、零／負值、費用不符、無扣堂歷史、已有 unpaid invoice。
- Error：已付款、pending report、共用方案、月結、按時、跨校、teacher。
- API、PHP syntax、前端 build 與既有 billing regression tests 必須通過。

### Revert-proof 驗證
- [ ] 在移除本次新增 endpoint／guard 測試覆蓋的修復後，新增 happy-path 測試至少 1 case failure，確認測試不是誤綠。

## 11. 上線與維運
- 無 migration。
- 透過 branch／PR／既有 CI 部署後端與前端，清 Laravel route／config cache。
- 觀測 `student_class.billing_contract_correction` audit event 與 4xx code 分布。
- 回滾：回滾應用程式版本即可；已完成的更正資料需依稽核事件走人工受控資料更正，不直接反向覆寫 production。

## 12. 優先級
- P1；執行 Agent：AI Agent。

## 13. 風險／假設／開放問題
- 假設主任反映的課程是按堂、非共用方案，且尚未有付款回報；若存在付款資料，應走付款作廢／更正流程。
- 風險：既有未付款發票若已建立，需確認其狀態與費用是否要另走作廢重開；本 command 不會覆寫已發出的帳務紀錄。
- 風險：排程尾端可能因請假／調課有例外，取消邏輯沿用既有 `cancelExcessScheduledSessions`，不碰 locked attendance。
- 業界參考：Stripe credit note／API 與 ERPNext amend／immutable ledger 均將帳務修正與原始紀錄分離；本次以相同原則限制更正邊界。

## 14. Definition of Done
- [ ] FR-001～FR-004：`php artisan test --filter=StudentClassBillingCorrectionTest` 回傳全數通過。
- [ ] FR-005：測試確認更正後 `SessionCount`／`Charge` 被收據／付款流程讀取。
- [ ] PHP syntax：`php -l` 對修改的 PHP 檔案回傳 `No syntax errors detected`。
- [ ] Frontend：`npm run build` 回傳成功。
- [ ] Docs：`git diff --check` 回傳無輸出，且 CHANGELOG／regression lesson 有本修復條目。
- [ ] Revert-proof：依 §10 命令執行，新增測試在移除修復時至少一案失敗。
