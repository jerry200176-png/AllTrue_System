# PRD：單科堂數制轉多科共用方案安全流程

> **版本**：v1 Draft（2026-08-27）  
> **狀態**：設計完成；唯讀預檢已實作於功能分支，尚未改動 production 資料
> **風險**：T3（堂數、付款、發票、出席歸戶）  
> **目標角色**：主任、admin、super_admin；家長只讀  
> **關聯**：`CoursePackageController`、`CourseContinuity` #1382、`DIRECTOR_PAYMENT_ALERT_RULES.md`

## 1. 決策與範圍

主任目前遇到的問題不是「找不到新增按鈕」，而是既有單科合約已可能包含上課、點名、扣堂、付款或發票歷史。若把 `StudentClass` 直接改成方案成員，系統可能只改關聯欄位，卻沒有把歷史扣堂與帳務證據納入方案帳本。

本 PRD 的決策是：

1. 主任預設使用「建立新的多科共用方案，保留原合約歷史」流程。
2. 只有完全沒有使用、出席、學習紀錄、發票、付款回報或方案帳本的單科合約，才可進入受控轉換。
3. 不把付款、發票、收據、學習紀錄或 `ClassSession` 搬到另一個合約。
4. 任何不符合條件的案件，介面必須指出阻擋原因與下一步，不讓主任只能看到模糊的 422。

## 2. 業務背景與成功指標

### 痛點

- 主任不知道「可以改」與「會破壞歷史」的界線。
- 現有多科方案建立頁可以建立新方案，但既有單科合約沒有清楚的轉換預檢。
- 現有 `bind-courses` 是遷移用 API；它目前檢查學生、校區、堂數制與啟用狀態，沒有完整檢查付款、發票、出席與扣堂帳本。

### 成功指標

- 100% 的轉換嘗試先取得預檢結果；未通過時不得寫入任何合約、方案或帳本資料。
- 每個阻擋結果至少包含一個可理解的原因與一個可執行的下一步。
- 安全轉換成功後，原有歷史筆數不變，方案餘額與新成員資料可由 API 重算一致。
- 轉換、拒絕、取消與重試皆留下最小必要的操作者、時間、來源合約與決策原因。

## 3. 範圍

### In scope

- 單科堂數制合約的唯讀轉換預檢。
- 預檢結果的主任白話文案、原因分類、下一步與響應式介面。
- 零使用、零收款、零歷史帳務的安全轉換路徑。
- 既有遷移 API 的相同條件後端守門與冪等性。
- 保留舊合約的「建立新多科方案」引導流程。
- API、前端、權限、審計、回滾與回歸測試。

### Out of scope

- 搬移或重寫 `Payment`、`Invoice`、`Receipt`、`payment_reports`、`LearningRecord`、`StudentSignIn` 或 `ClassSession`。
- 自動把已使用的剩餘堂數跨合約合併。
- 變更繳費提醒列入規則、收費金額規則或扣堂演算法。
- 讓月結制、`actual_duration`、跨學生、跨校區或已停用合約進入此流程。
- 直接修正 production 個案資料。

## 4. RACI 與依賴

| 工作 | R | A | C | I |
|---|---|---|---|---|
| 預檢與 API 契約 | AI Agent | AI Agent | QA Agent | CEO／主任 |
| 前端轉換引導 | AI Agent | AI Agent | UX／QA Agent | 主任 |
| 帳務與出席不變式測試 | AI Agent | AI Agent | Security／QA Agent | CEO |
| 發布、health、回滾證據 | AI Agent | AI Agent | Ops Agent | CEO／主任 |

### Dependencies

- 既有多科方案建立入口：`UniversalClassScheduler.vue` 與 `POST /api/v1/course-packages/create-multi-subject`。
- 既有方案綁定入口：`POST /api/v1/course-packages/{id}/bind-courses`；目前僅視為遷移工具，不可直接當主任 UI。
- 堂數、付款與方案提醒規則：`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`。
- 合約關聯原則：`docs/architecture/RFC_COURSE_CONTINUITY.md`；不做實體合併。
- 上線依賴：隔離 worktree、完整 CI、獨立 review、單一 AllTrue production release、部署後版本與唯讀 smoke。
- 若未來要把已使用餘額轉入方案，需另立帳本遷移決策與 Repair Manifest；不屬於本 PRD。

## 5. User stories 與 Acceptance Criteria

### US-001：主任知道為何不能直接轉換

身為主任，我希望選取既有單科課程後看到清楚的預檢結果，以便不必反覆嘗試或請工程師手動修資料。

### US-002：主任能安全處理新需求

身為主任，我希望在既有合約已有歷史時，直接得到「建立新多科方案、保留舊合約」的入口，以便不破壞家長的既有對帳。

### AC-001：預檢為唯讀

- `GET /api/v1/student-classes/{studentClass}/package-conversion-preview` 只讀取合約、出席、學習紀錄、發票、付款回報、帳本與方案關聯。
- 預檢失敗或權限不足時，資料庫中的所有來源資料均不變。

目前功能分支已提供上述唯讀端點與「更多 → 轉多科方案預檢」入口；它只回傳
`read_only`、`can_convert`、穩定原因碼與下一步，不執行綁定或建立方案。

### AC-002：安全可轉換條件

- 只有堂數制、啟用中、同一學生／同一校區、未屬於其他方案，且使用量與所有歷史帳務證據均為零的合約，回傳 `eligible=true`。
- 零使用條件至少涵蓋：沒有非取消 `ClassSession`、沒有已出席／已扣堂 ledger、沒有學習紀錄、沒有發票、沒有付款回報、沒有有效付款。

### AC-003：阻擋原因

- 每個不合格條件回傳穩定的原因碼、白話訊息與建議動作。
- 已使用或已收款的單科課程不得被 API 綁入新方案；建議動作是「建立新多科方案並保留原合約」。

### AC-004：成功轉換的資料不變

- 成功轉換只建立／更新方案關聯，不刪除原 `StudentClass`。
- 原合約 ID、歷史堂次、付款／發票／收據與學習紀錄筆數不變。
- 方案總堂數、剩餘堂數與成員摘要可由方案帳本重算一致。

### AC-005：重試與取消

- 同一轉換請求重送不會建立第二個方案或第二份成員關聯。
- 主任取消預檢或關閉視窗不會留下寫入中的請求或錯誤狀態。

## 6. UI／UX 規格

- 在單科課程的「更多／合約」操作下提供「檢查是否可轉多科方案」，不在一般編輯欄位中混入危險的直接改寫。
- 預檢視窗先顯示學生、分校、科目、目前剩餘堂數與「不會搬移歷史帳務」提示，再顯示結果。
- 結果分成「可安全轉換」與「需建立新方案」兩種明確狀態；阻擋狀態顯示原因卡片與單一主要 CTA。
- 主要 CTA：`建立新的多科方案`；次要 CTA：`查看原合約`、`取消`。不得讓「直接綁定」成為預設主要動作。
- Loading 顯示「正在檢查課程歷史與帳務」，錯誤狀態提供「重新檢查」，不以空白畫面或無意義的 HTTP 422 取代說明。
- 390px 寬度下原因卡片、CTA 與長學生姓名必須折行；按鈕最小觸控區 44px。
- `role=dialog`、焦點回復、鍵盤 Escape、`aria-live` 結果公告與表單錯誤關聯必須通過 UI smoke。

## 7. 功能需求（FR）

- **FR-001**：後端提供單一唯讀預檢契約，集中判斷所有可轉換條件。
- **FR-002**：後端寫入端點必須重跑預檢，不得信任前端曾經取得的 `eligible=true`。
- **FR-003**：預檢必須區分「無歷史可轉換」、「已有歷史不可轉換」、「跨學生／校區」、「非堂數制」、「已屬方案」等原因。
- **FR-004**：安全轉換不改寫歷史財務、出席、評量與帳本資料。
- **FR-005**：已有歷史的課程在前端直接導向新多科方案建立流程，並將原課程視為保留的歷史合約。
- **FR-006**：所有寫入操作必須有角色與校區隔離，並留下操作者與決策原因。
- **FR-007**：方案與成員的餘額顯示只能使用方案層權威欄位，不得從各科目剩餘堂數自行相加。
- **FR-008**：API 使用穩定錯誤碼與可供前端顯示的下一步，不讓主任自行猜測如何修復。

## 8. 非功能需求（NFR）

- **NFR-001 效能**：單一合約預檢在測試資料 10 萬筆級別下，P95 小於 500ms；查詢必須限制在單一合約與其關聯資料。
- **NFR-002 降級**：任一歷史資料查詢失敗時 fail closed，回傳「無法完成預檢，請重新檢查」，不可誤回 `eligible=true`。
- **NFR-003 可觀測性**：只記錄合約 ID、方案 ID、原因碼、結果、操作者與 correlation ID；不得記錄學生姓名、電話或付款明細。
- **NFR-004 冪等性**：重試不產生重複方案、成員或審計事件。

## 9. 技術方向與取捨

| 邊界 | 採用 | 取捨理由 |
|---|---|---|
| 預檢 | `StudentClassController`／`CoursePackageController` 的共用 domain service 與唯讀 API | 讓 UI 與寫入端點使用同一套判斷，避免前端與後端分叉 |
| 方案建立 | `CoursePackageController::createMultiSubject` | 沿用現有多科建立與排課契約，不另做第二套方案模型 |
| 既有合約 | `StudentClass`、`ClassSession`、`Invoice`、`PaymentReport`、`LearningRecord` 保持原歸戶 | 保留對帳與出席歷史，回滾可逆 |
| 方案餘額 | `CoursePackage` 與 `PackageSessionLedger` | 方案層是共用池的唯一真相，不從科目列加總 |
| 轉換 UI | `CourseManagement.vue` 與 `UniversalClassScheduler.vue` 的情境式入口 | 主任先看到原因與下一步，再進入新方案流程 |
| 審計 | 現有 `ScheduleAuditLog`／既有敏感操作稽核邊界 | 避免另造無法查詢的平行稽核表 |

不採用「實體合併兩筆 `StudentClass`」：它會改寫付款、出席與評量歸戶，且回滾成本高；這與 `RFC_COURSE_CONTINUITY.md` 的既有決策衝突。

## 10. Decision log

| 日期 | 選項 | 決策與理由 |
|---|---|---|
| 2026-08-27 | 直接讓主任使用 `bind-courses` | 拒絕；現行守門未涵蓋歷史付款、發票與帳本 |
| 2026-08-27 | 所有案例直接搬剩餘堂數 | 拒絕；已使用餘額的跨合約轉移是帳本遷移，不可由一般 UI 猜測 |
| 2026-08-27 | 既有歷史一律重建且刪除舊合約 | 拒絕；會破壞家長對帳、點名與評量追溯 |
| 2026-08-27 | 預檢 + 零歷史安全轉換 + 其他案例建立新方案 | 採用；可解決主任操作困惑，同時維持資料可逆與可稽核 |

## 11. 資安與存取控制

- `director`、`admin`、`super_admin` 僅能讀取與操作其授權校區；跨校區與跨學生一律 fail closed。
- 方案預檢回應不得把付款金額、家長聯絡方式或內部資料庫 ID 當成主任決策必要資訊；技術 ID 僅供稽核與 drill-down。
- STRIDE 快評：Spoofing 依現有 Bearer auth；Tampering 由寫入端點重跑預檢與 transaction 防護；Repudiation 記錄操作者／原因；Information disclosure 採最小欄位；Denial of service 限制單一合約範圍與請求頻率；Elevation of privilege 由角色與校區檢查共同防護。
- 任何「搬移付款／發票／扣堂」的未來擴充必須升級為獨立 Repair Manifest 與安全審查，不可藏在本流程。

## 12. QA 驗收

### Happy path

- 無任何歷史的單科堂數制課程可通過預檢並完成一次安全轉換。
- 轉換後加入第二科，兩科顯示同一方案池；原合約與歷史資料仍存在。

### Edge cases

- 只有取消／作廢的 session、invoice 或 payment report：依權威狀態分類，不可只用資料列存在與否判斷。
- 有未來排課但尚未出席：必須顯示「已有未來承諾，需先決定保留或重排」，不得靜默搬移。
- 有部分使用、付款、發票、學習紀錄、跨校區或已屬其他方案：全部阻擋並提供建立新方案 CTA。
- 重複點擊、並行請求、重新整理、返回上一頁與 390px 視窗均不得產生重複資料或失去原因訊息。

### Error cases

- 未登入、角色不足、校區不符、合約不存在、資料查詢 timeout：回傳穩定錯誤碼與白話 recovery，且資料不變。
- 寫入前資料被其他操作者改變：重新預檢並拒絕 stale decision，不接受舊的 `eligible` 結果。

### Revert-proof

- 新增的每個 regression test 在移除對應守門或預檢 wiring 後至少失敗一個 case。
- 若未來需要回滾，只回退程式與 schema 變更；本 PRD 的安全轉換不得要求搬回歷史帳務。

## 13. 上線與維運

### Rollout

1. 先合併唯讀預檢與測試，不開啟任何 production 資料寫入。
2. 以 feature flag 隱藏寫入 CTA，先觀察預檢原因分布與誤擋率。
3. 通過完整 CI、獨立 review、UI smoke 與安全審查後，才在單一 release 開啟安全轉換。
4. 部署後驗證 health、version identity、權限隔離與一個 synthetic zero-history fixture；不對真實學生執行轉換作為 smoke。

### Observability

| 訊號 | 內容 | 告警／處置 |
|---|---|---|
| `package_conversion.preview` | 結果、原因碼、延遲、校區 scope | 422 比例突升時檢查資料契約，不直接放寬守門 |
| `package_conversion.execute` | 成功／拒絕、來源合約、方案、correlation ID | 任何歷史資料變更異常立即停止 CTA |
| release evidence | serving SHA、health、預檢 read-only 結果 | SHA 不符即視為未上線 |

### 回滾

- 程式回滾走正常 deploy workflow；無 migration 的預檢版本可直接回退。
- 若未來包含 schema，先保留舊欄位與舊 endpoint，使用向後相容的 rollback PR；不得直接刪除歷史欄位。
- 已成功的安全轉換只會影響零歷史資料，回滾時解除關聯即可；不得刪除來源合約。

## 14. 里程碑與 Definition of Done

### 優先級

- **P1 `[ARCH]`**：凍結預檢條件、原因碼、資料不變式與 API 契約。
- **P1 `[DEV]`**：實作唯讀預檢、寫入重檢、權限／校區守門與冪等。
- **P1 `[DEV]`**：完成主任視角的結果卡、下一步 CTA、loading／error／responsive／accessibility。
- **P1 `[TEST]`**：後端帳務／出席／ledger 不變式、並行與回歸測試；前端單元與 Playwright。
- **P1 `[REVIEW]`**：資安、資料完整性與 API 契約 review。
- **P1 `[DOCS]`**：更新 `CHANGELOG`、`STAFF_UPDATES`、`AI_REGRESSION_LESSONS` 與 Portfolio queue。
- **P1 `[OPS]`**：CI、release evidence、health、版本比對與 rollback readiness。

### Definition of Done

- [ ] 預檢契約：`vendor/bin/phpunit --filter=PackageConversionPreview` 回傳全部通過，且不合格案例均為 `eligible=false`。
- [ ] 後端完整回歸：`vendor/bin/phpunit` 回傳成功，方案、帳務、點名與權限測試全部通過。
- [ ] 前端：`npm run lint:no-undef`、`npm run test:unit`、`npm run build` 均回傳成功。
- [ ] UI：`npm run test:e2e:ui-foundation -- package-conversion` 通過 390px、鍵盤、loading、error 與成功流程。
- [ ] 安全：跨校區／跨學生測試回傳 403 或穩定阻擋結果，未產生任何資料寫入。
- [ ] 文件：`git diff --check` 成功，且 `docs/INDEX.md` 可導向本文件。
- [ ] 發布：`version.json`／deployment identity 與 reviewed SHA 相同，health HTTP 200 且狀態為 ok。

## 15. 業界與開源證據

- Stripe 的 [Subscription Schedules](https://docs.stripe.com/billing/subscriptions/subscription-schedules) 將變更拆成有明確生效日的 phases，並把 proration 行為明確化；這支持 AllTrue 先決定「何時生效」再決定是否調整帳務。
- Stripe 的 [Change the price of existing subscriptions](https://docs.stripe.com/billing/subscriptions/change-price) 提供變更前的 proration preview，並區分立即開票與不產生 proration；這支持 AllTrue 在寫入前先做唯讀預檢。
- Stripe 的 [usage-based billing lifecycle](https://docs.stripe.com/billing/subscriptions/usage-based/how-it-works) 將 usage ingestion、billing、monitoring 分開；這支持 AllTrue 不把課程關聯變更與歷史扣堂帳本混成一次欄位更新。
- [Kill Bill `cb60779c`](https://github.com/killbill/killbill/tree/cb60779c171391be558cd7aebb1eafea60ad2b82) 採訂閱事件、週期與發票分層；其 `TestCatalogSameDayEffectiveDateForExistingSubscriptions.java` 以生效日與下一個 billing date 驗證變更不覆寫進行中週期。
- [ERPNext `d6956790`](https://github.com/frappe/erpnext/tree/d6956790d8f8940696783bc7ca85438ecd7d4b6e) 的 subscription model 與 test suite 將 billing period、invoice 與 status 分開驗證；AllTrue 僅借鑑其邊界測試方式，不引入 GPL 程式碼。

## 16. 未決事項

- `[BLOCKED: 業務決策]` 若既有合約已使用 1 堂但尚有剩餘堂數，未來是否允許把「未使用餘額」轉入多科池？本 PRD 預設不允許自動轉移，需另案帳本遷移規格。
- `[AI-RESOLVABLE]` 現有 `bind-courses` 的歷史資料測試需補上付款／發票／ledger 情境，避免遷移工具與主任 UI 使用不同安全標準。
- `[AI-RESOLVABLE]` 預檢原因碼、錯誤訊息與現有 API error contract 應在實作 PR 內集中定義，禁止由各頁面自行翻譯。
