# PRD — Invoice 作廢入口 + 總覽代課動態排版字型修正

## 0. 根因（BugFix 專屬）

- 帳務：系統已支援 `Invoice.Status='void'` 排除應收，但沒有主任可操作的 Invoice 作廢入口，導致 `INV-202605-000357`、`INV-202604-000199` 這類錯帳只能靠資料修復草案，無法在系統內安全處理。
- UI：`RecentSubstitutesCard` 的 header 使用左 icon + title + badge 同列，右側空間不足時「代課動態」會被壓縮或切到卡片邊界；目前字型只在局部設定，缺少穩定的 CJK/數字字體 token。

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | Invoice 作廢入口 + 代課動態視覺修正 |
| 日期 | 2026-04-28 |
| 狀態 | Approved for DEV |
| Risk Tier | T3（帳務/稽核）+ T1（前端視覺） |
| 目標角色 | 主任 / super_admin |

## 2. 目標與業務背景

- 主任可在課程管理的月結帳單 Modal 直接作廢「不該存在且未收款」的 Invoice。
- 作廢保留稽核軌跡，不刪除資料，不修改歷史付款金額。
- 總覽儀表板代課動態卡片不切字、不擠壓，字型達到大公司 SaaS dashboard 的乾淨、可讀、穩定風格。

KPI：
- 錯帳 Invoice 作廢後 0 筆出現在家長應收/課程帳單/主任催繳加總。
- 作廢操作 100% 要求原因並記錄操作者。
- 代課動態 header 在 320px 寬度仍不切字。

## 3. 範圍

In Scope：
- 新增受權限保護的 Invoice 作廢 API。
- 課程管理月結帳單 Modal 新增「作廢」操作與二次確認/原因輸入。
- 作廢後刷新帳單列表與課程資料。
- 補 PHPUnit regression：權限、跨分校、paid invoice 不可作廢、void 後排除。
- 修正代課動態卡片 header layout、badge 位置、CJK/數字字型 token。

Out of Scope：
- 不新增批次作廢。
- 不作廢 PaymentReport 或 Payment。
- 不自動判斷哪些歷史帳單應作廢。
- 不引入付費字型或新的外部 font CDN。
- 不直接在 production DB 執行手工 SQL。

## 4. RACI

| 工作 | R | A | C | I |
|---|---|---|---|---|
| PRD/ARCH | AI Agent | AI Agent | 使用者 | 使用者 |
| 後端 API | AI Agent | AI Agent | - | 使用者 |
| 前端 UI | AI Agent | AI Agent | - | 使用者 |
| TEST/SEC/REVIEW/OPS | AI Agent | AI Agent | - | 使用者 |

## 4b. Dependencies

- 已上線：`Invoice.Status='void'` 排除家長應收/課程帳單/主任催繳統計。
- 現有入口：`CourseManagement.vue` 月結帳單 Modal。
- 無 DB migration 依賴，先使用現有 `Status` 與 `Note` 欄位記錄作廢資訊。

## 5. User Stories + AC

- US-001：作為主任，我要在月結帳單列表看到錯帳 Invoice 的「作廢」按鈕，讓我不用請工程師手動改資料。
  - AC：只有 `unpaid` 且 `PaidAmount=0` 的 Invoice 顯示作廢。
  - AC：按下後必須輸入原因，空白不可送出。
- US-002：作為主任，我作廢後要立即看不到該帳單，讓應收畫面回到正確口徑。
  - AC：作廢成功後 Modal refresh，該 Invoice 不再列出。
- US-003：作為管理者，我要知道誰在何時作廢了哪張帳，讓帳務可稽核。
  - AC：`Note` 追加 `[void: reason; user_id=...; at=...]`。
- US-004：作為主任，我要總覽代課動態卡片清楚好看，不切字。
  - AC：320px、桌面右欄寬度、資料筆數 badge 存在時都不切到邊界。

## 5b. UI/UX 精緻化

Invoice 作廢：
- 月結帳單 Modal 操作欄：`核帳` 與 `作廢` 分開，作廢使用 outline danger style。
- 二次確認 Dialog：標題「作廢帳單」、顯示月份/金額/Invoice ID/課程 ID，輸入原因 textarea。
- Loading：送出時按鈕 disabled，文字「作廢中...」。
- Success toast：`已作廢 2026年5月帳單`。
- Error toast：跨分校/已繳/部分繳/已作廢要顯示後端 message。
- 響應式：手機寬度操作按鈕換行，不擠壓金額欄。

代課動態/字型：
- Header 改為 CSS grid：icon 固定、title 區 `min-width:0`、badge 靠右，不讓 title 被 badge 擠出卡片。
- Title 使用 `clamp(14px, 1.1vw, 16px)`、line-height 1.25；meta 降階為 caption。
- 全域 font token 建議：`Inter`, `Noto Sans TC`, `PingFang TC`, `Microsoft JhengHei`, system sans。
- 數字/時間使用 `font-variant-numeric: tabular-nums`。

## 6. 功能需求 FR

- FR-001：新增 Invoice 作廢 API，僅 director/admin/super_admin 可用，且套用 campus isolation。
- FR-002：只允許作廢 `Status` 非 paid/partial 且 `PaidAmount=0` 的 Invoice。
- FR-003：作廢必填 reason，長度 3-255。
- FR-004：作廢寫入 `Status='void'`，並在 `Note` 追加稽核資訊。
- FR-005：作廢後不可進入 `notVoided()` 預設列表。
- FR-006：CourseManagement 月結帳單 Modal 顯示作廢入口。
- FR-007：RecentSubstitutesCard header 不切字、不溢出。
- FR-008：前端字型統一走全域 token，不在單一元件硬湊多套 stack。

## 7. NFR

- API p95 < 500ms。
- 作廢操作必須 transaction。
- UI 操作錯誤不可造成帳單列表空白。
- 不新增外部字型 CDN，避免 CSP/離線/效能風險。

## 8. 技術方向

- 後端：在既有 BillingController/Billing route 增加單筆 Invoice 作廢能力。
- 前端：CourseManagement 月結帳單 Modal 加作廢流程；RecentSubstitutesCard 調整 header layout；styles.css 增加/整理字型 token。
- 權限：沿用現有 `auth:sanctum`、`role:director,admin,super_admin`、`require_campus` 分校限制。
- 資料：不改 schema，先以 `Status` + `Note` 保留 audit；未來若作廢量增多再評估 `VoidedAt/VoidedByUserID/VoidReason` migration。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-04-28 | 用系統入口作廢 Invoice | 手工 SQL | 讓主任可自助處理，且有權限/驗證/測試 |
| 2026-04-28 | 不刪 Invoice | DELETE | 保留 audit trail |
| 2026-04-28 | 先不作廢 paid/partial | 允許全部作廢 | paid/partial 涉 Payment/Receipt 沖銷，需另一套收款撤銷流程 |
| 2026-04-28 | Inter + Noto Sans TC fallback | 付費字型/CDN | 業界 SaaS 常用、低風險、CJK 穩定 |

## 9. 資安與存取控制

STRIDE：
- Spoofing：使用現有 Bearer token，不新增公開端點。
- Tampering：只能作廢同分校 Invoice；跨分校 403。
- Repudiation：Note 追加 reason/user/time，Log 記錄 invoice_id/student_id/campus_id。
- Information Disclosure：錯誤訊息不洩漏跨分校資料。
- DoS：單筆操作，無批次。
- Elevation：teacher/parent 不可呼叫。

## 10. QA 驗收

Happy Path：
- unpaid + PaidAmount=0 invoice 作廢成功。
- 作廢後 CourseManagement Modal 不再顯示，alerts/tuition 不加總。

Edge/Error：
- paid invoice 回 422。
- partial invoice 回 422。
- reason 空白回 422。
- 跨分校 director 回 403。
- void invoice 再作廢回 422。

UI：
- 320px 寬度 header 不切「代課動態」。
- badge 有/無資料皆正常。
- 深色模式不破版。

## 11. 上線與維運

- Feature branch → PR → CI/PHPStan/Vite 全綠 → merge → deploy.yml 自動部署。
- 無 migration。
- 部署後驗證 `/api/v1/health`、`/version.json`。
- Rollback：PR revert；已被作廢的資料不自動反作廢，需另走資料修復批准。

## 12. 里程碑與優先級

- P0 `[FEATURE]`：Invoice 作廢 API + tests。
- P0 `[FEATURE]`：CourseManagement 作廢 UI。
- P1 `[FEATURE]`：RecentSubstitutesCard 排版與全域字型 token。
- P1 `[TEST]`：後端 feature tests + 前端 build。
- P1 `[REVIEW]`：STRIDE + code review。
- P1 `[DOCS]`：CHANGELOG + AI_REGRESSION（若新增防再犯）。
- P1 `[OPS]`：PR merge 後 deploy + health/version。

## 13. 風險 / 假設 / 開放問題

WebSearch 摘要：
- SaaS dashboard 常見做法是 Inter 作為 UI font，搭配明確 CJK fallback；Inter 在 11-16px UI 場景、數字表格、dashboard 可讀性上是主流。
- CJK UI 需要顯式 fallback，例如 Noto Sans TC / PingFang / Microsoft JhengHei，避免標點與中文字 fallback 不穩。

風險：
- 若未來要作廢 paid/partial invoice，必須整合 Payment/PaymentReport 沖銷，不可沿用本次入口。
- 只用 Note 保存 audit 可用但不夠結構化；若作廢功能常態化，後續應升級 schema。

## 14. Definition of Done

- [ ] Invoice 作廢 API 權限正確：驗證方式 `PHPUnit` 覆蓋 director/super_admin/teacher/cross-campus。
- [ ] paid/partial 不可作廢：驗證方式 `PHPUnit` 回 422。
- [ ] void 後從應收口徑排除：驗證方式 `PHPUnit` 檢查 student-class invoices。
- [ ] CourseManagement 可完成作廢流程：驗證方式 `npm run build` 成功且 UI 狀態完整。
- [ ] 代課動態 header 不切字：驗證方式 CSS layout 在 narrow width 不 overflow。
- [ ] CI 全綠：驗證方式 `gh pr checks` 全 success。
- [ ] Production health OK：驗證方式 `curl -sk https://daan.lifenet.com.tw/api/v1/health` 回 `status=ok`。
