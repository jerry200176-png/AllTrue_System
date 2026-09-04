# RFC：AllTrue 新生問班 → 試聽 → 報名招生閉環 V1

## 1. 文件資訊

- 功能：招生詢問（Admission Inquiry）與 guided interview
- 版本：V1
- 狀態：Dark-launch complete / **BLOCKED: Founder activation GO**（flag 預設 off；production migrate／flag on 需 GO）
- 目標角色：公開家長、主任、super admin
- Risk tier：T3（新 PII、角色權限、schema migration）
- 依賴 branch：本 branch 與 onboarding worktree 分離；只接受最小 additive architecture
- Activation artifacts：[`docs/runbooks/admissions-funnel-v1-activation-execution-package.md`](../runbooks/admissions-funnel-v1-activation-execution-package.md) · [`docs/runbooks/admissions-funnel-v1-founder-activation-brief.md`](../runbooks/admissions-funnel-v1-founder-activation-brief.md)

## 2. 目標與業務背景

家長目前缺少一個手機友善、逐步引導的問班入口；主任也缺少「新詢問 → 聯絡 → 安排試聽 → 記錄結果 → 報名」的單一工作面。若問班直接建立 Student，未完成接觸的家庭會污染正式學生名冊，亦容易在報名時重複建檔。

V1 的業務結果是：

1. 公開表單完成率與送出後可追蹤性可由 API／UI smoke 驗證。
2. 每一筆有效詢問都有明確 lifecycle、campus owner、最後動作與下一步。
3. 試聽前不建立正式 Student；建立試聽時只建立一次 Student，後續報名沿用該 Student。
4. 既有排課、老師、合約／繳費及 trial conversion authoritative flow 的既有測試維持通過。

## 3. 範圍

### In scope

- 公開 `/admissions` guided interview：校區、家長聯絡方式、學生基本資料、年級、科目、偏好時段、同意送出。
- `admission_inquiries` bounded context：狀態、去重索引、聯絡紀錄摘要、試聽與 Student 關聯。
- 主任工作面：依校區檢視、篩選、聯絡、安排一次或既有 trial workflow、記錄試聽結果、開啟既有報名／trial conversion。
- 伺服器端狀態轉移、campus authorization、PII encryption、dedupe hash、append-only security audit。
- mobile-first、鍵盤操作、44px 觸控目標、loading／empty／error／success feedback。
- feature flag、CI tests、release execution package、CHANGELOG、production read-only verification。

### Out of scope

- CRM、lead scoring、marketing automation、email/SMS/LINE campaign、獨立 analytics framework。
- 第二套排課、老師搜尋、合約、收款或試聽轉正邏輯。
- 公開家長帳號、登入、付款、檔案上傳或自由文字的敏感個資收集。
- legacy `Student.parent_phone` cutover、既有 guardians backfill、刪除或重寫 onboarding。

## 4. RACI

| 角色 | RACI | 責任 |
|---|---|---|
| `[FEATURE]` Agent | R | API、資料、公開與主任 UI |
| `[TEST]` Agent | R | feature、security、UI smoke 與 regression tests |
| `[REVIEW]` Agent | R | FR／NFR、STRIDE、campus isolation、PII review |
| `[DOCS]` Agent | R | RFC、CHANGELOG、release evidence |
| `[OPS]` Agent | R | CI、deploy workflow、health／bundle／API verification |
| Founder | I / gate | 僅 privacy／permission／production data／不可逆 migration 的 activation GO |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 既有 Student flow | `StudentController` 與 `GuardianSyncService`；建立 Student 後沿用既有 parent binding dual-write | 已存在 |
| 既有 enrollment flow | `EnrollmentService`；試聽建立必須使用現有 validation、schedule guard、teacher/campus checks | 已存在 |
| 既有 trial conversion | `StudentClassController@convertTrial`；報名不可重造 Student 或 conversion engine | 已存在 |
| 既有 auth | `AttachAuthUser`、`role:director,super_admin`、`require_campus` | 已存在 |
| 既有稽核 | `SecurityAuditEvent`；metadata 不放姓名、電話、訊息內容 | 已存在 |
| DB | additive `admission_inquiries` migration；merge 不等於 production migrate | **dark-launch code ready**；production migrate 需 REP + Founder activation GO |
| Feature flag | `admissions_funnel_v1` 預設關閉；公開入口與主任 nav 同步受控 | **已落地**（預設 off；deploy input `unchanged`/`on`/`off`） |

## 5. User Stories 與 Acceptance Criteria

### US-001 家長送出問班

As a 家長, I want to 用手機逐步回答問班問題, so that 我不用知道 AllTrue 內部課程資料結構也能留下可聯絡詢問。

- AC-001：未填必填欄位不能送出，欄位旁有 inline error；送出成功顯示不含 inquiry id／PII 的確認訊息。
- AC-002：重複送出同校區、同家長電話、同學生姓名的有效詢問不產生第二筆 active inquiry。
- AC-003：公開 response 與錯誤不得回傳 Student、guardian、電話明文或內部 ID。

### US-002 主任處理新詢問

As a 主任, I want to 看到我負責校區的詢問並記錄聯絡結果, so that 每個家庭都有下一步且不會越權看其他校區。

- AC-004：director 只能讀寫 assigned campus；super admin 可按校區篩選。
- AC-005：狀態只能依 server state machine 前進或進入明確 terminal status；任意跳狀態回傳 422。
- AC-006：list 只顯示遮罩聯絡資訊；detail 的明文電話只在授權主任操作所需範圍回傳。

### US-003 安排與完成試聽

As a 主任, I want to 從 inquiry 直接帶入試聽建立流程, so that 不需重新輸入家長與學生身份資料。

- AC-007：trial scheduling transaction 只在第一次成功建立一個 Student 與一個 trial course；重試為 idempotent。
- AC-008：試聽建立使用 `EnrollmentService` 的既有教師、校區、衝堂、日期及課程合約檢查。
- AC-009：主任可記錄 attended／no-show／cancelled／not_suitable，並保留可追蹤的下一步。

### US-004 試聽後報名

As a 主任, I want to 開啟既有 trial conversion／enrollment workflow, so that 報名後只補課程商業資料，不重建 Student。

- AC-010：正式報名沿用既有 trial conversion；source trial history 與 future session preservation 不變。
- AC-011：conversion 後 inquiry 連到同一 `student_id`，Student count 不增加，重試不重複報名。
- AC-012：既有 TrialConversion、enrollment、student/guardian regression tests 全部通過。

## 5b. UI/UX 精緻化

| 頁面 | 規格 |
|---|---|
| 公開 guided interview | 單欄卡片、每步一個主問題、進度提示、固定底部主 CTA；沿用既有 tokens；步驟切換保留已填值；錯誤 inline；完成頁不暴露識別碼。 |
| 主任 inquiry queue | 手機先顯示狀態、學生遮罩名、校區、最後動作與下一步 CTA；桌面提供 filter；空狀態含說明與「重新整理」CTA；載入使用 skeleton。 |
| inquiry detail／trial handoff | PII 以 label/value 分組，電話操作提供明確 click-to-call；安排與狀態 mutation 顯示 spinner、成功 toast、422 inline；不可逆 conversion 沿用既有 confirmation。 |
| Responsive / a11y | 無水平 overflow；窄螢幕單欄；互動目標至少 44px；可全鍵盤操作；focus visible；非色彩單獨傳達狀態；文字對比至少 4.5:1；錯誤以 `aria-live` 宣告。 |

## 6. Functional Requirements

- FR-001：系統以 active flag 控制公開入口與主任功能，flag off 時既有 login、Student、排課頁完全不變。
- FR-002：公開 endpoint 以 bounded rate limit 接收最小必要資料，成功與 duplicate 都使用 generic acknowledgement。
- FR-003：資料層保存 inquiry status、campus、encrypted PII、dedupe hashes、consent timestamp、actor／assignment、trial／student／conversion references。
- FR-004：所有主任 query 先套用 campus scope；request 中的 campus、teacher、trial class 必須重新以 server authoritative data 驗證。
- FR-005：狀態 mutation 產生 PII-minimized `SecurityAuditEvent`，不把 raw request body 寫入 log 或 audit。
- FR-006：試聽 handoff 將 inquiry 的學生／家長資料映射到既有 enrollment input；不可建立第二套 Student identity。
- FR-007：報名成功後只更新 inquiry lifecycle／reference，課程、session、付款與 trial conversion 規則仍由既有模組負責。
- FR-008：失敗可安全重試；半完成流程顯示可恢復 action，不自動猜測或覆寫既有 Student。

## 7. Non-functional Requirements

- NFR-001：公開 submit p95 < 500ms（不含外部網路）；超時不重試建立，client 顯示可安全重送。
- NFR-002：queue list p95 < 800ms，固定 page size 50；不得一次回傳全部 inquiry 或全部 PII。
- NFR-003：rate limit 至少以 IP + dedupe hash 控制；超限為 429，回應不洩漏是否已有資料。
- NFR-004：PII 使用 Laravel application encryption；dedupe 僅使用 keyed hash；production logs、audit、frontend bundle 不含 raw PII。
- NFR-005：migration 僅 additive、有 has-table guard、可 rollback；不在 branch、CI 或 production 直接手動 migrate。

## 8. 技術方向

| 邊界 | 設計 |
|---|---|
| Data | 單一 `admission_inquiries` table；inquiry 是 prospect context，不是 Student／CRM entity。 |
| Public API | `/api/v1/admission-inquiries` submit 與公開 branch read；generic response、rate limit、flag gate。 |
| Staff API | `/api/v1/admission-inquiries` list/detail 與明確 action endpoints；director/super_admin middleware + campus policy。 |
| Staff UI | `AdmissionInquiriesPage` 掛入 navigation registry；不改既有 Student page contract。 |
| Public UI | `#/admissions` standalone shell；不要求登入，不共用 Parent Portal identity。 |
| Trial handoff | 既有 `EnrollmentService`；不新增 scheduler。 |
| Enrollment handoff | 既有 `convertTrial`／enrollment contract；inquiry 只保存 reference 與 lifecycle。 |
| Audit | 既有 `SecurityAuditEvent`，以 inquiry hash reference 作 subject。 |

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-09-04 | 將 inquiry 與 Student 分離，試聽建立時才建立 Student | 問班即寫 Student；另建 CRM | 避免正式名冊污染，且符合使用者要求的單一 Student authoritative flow。 |
| 2026-09-04 | 以 campus + keyed phone/student hash 去重 | 只靠姓名；公開端查 Student | 姓名不足以識別，公開端不應暴露正式身份；同電話不同學生仍可存在。 |
| 2026-09-04 | 以明確 action/state machine 取代任意 PATCH | 自由編輯 status；全自動 pipeline | 可審計、可復原、避免 staff 誤跳過試聽或越權完成報名。 |
| 2026-09-04 | trial 與 conversion 委派現有服務 | 新造 admissions scheduler／conversion service | 保持排課、老師、合約、繳費單一真相，降低回歸面。 |
| 2026-09-04 | feature flag 預設 off，migration 與 activation 分離 | merge 後立即公開 | PII／permission／不可逆資料風險需獨立 gate；符合 AllTrue REP。 |

## 9. 資安與存取控制

觸發原因：PII、公開 endpoint、角色權限、Student identity handoff。

| STRIDE | V1 控制 |
|---|---|
| Spoofing | Public 僅可 submit；staff action 必須通過既有 bearer identity、role、campus middleware；不信任 client campus。 |
| Tampering | action-specific validation、狀態轉移 guard、row lock/idempotency key、既有 enrollment／conversion server checks。 |
| Repudiation | 所有 assign/contact/trial/result/enroll action 寫既有 append-only audit；subject 用 HMAC ref。 |
| Information disclosure | PII encrypted at rest；list masked；public generic response；錯誤與 logs 不含 raw PII；detail 受 role/campus。 |
| Denial of service | public route throttle、payload 長度上限、page size 上限、固定選項／時段數量上限。 |
| Elevation of privilege | director/super_admin only；teacher 無 inquiry management；每次讀取與 mutation 都做 campus／related StudentClass scope。 |

## 10. QA 驗收

### Happy path

公開送出 → 主任看到 new → contact → 選既有 trial config → 建立一次 Student/trial → 記錄 attended → 開啟既有 conversion → inquiry enrolled 且 Student ID 相同。

### Edge / error

- 同一家庭重複 submit、同電話不同學生、空白／格式錯誤電話、重複 action、過期 inquiry。
- director 跨校區 list/detail/action、teacher access、super admin 多校區。
- schedule conflict、teacher 不屬校區、trial conversion 失敗、網路重試、部分成功 recovery。
- 小螢幕、鍵盤、screen-reader error announcement、loading／empty／429／500。

### Required automated checks

- PHPUnit：public validation/generic response/dedupe、state machine、PII redaction、campus auth、trial handoff idempotency、same Student conversion。
- Frontend：navigation registry、public step flow、staff action state、mobile overflow／accessible labels。
- Regression：既有 `TrialConversionTest`、Student guardian／enrollment／schedule tests。
- Static review：`git diff --check`、lint、no raw PII in logs/audit、route middleware audit。

## 11. 上線與維運

- 部署由 `.github/workflows/deploy.yml` 觸發；merge 不代表 production migration 已執行。
- `admissions_funnel_v1` 預設 off；activation sequence 為 CI → deploy → migration REP/Founder GO → staff-only smoke → bounded public rollout → production verification。
- Observability：`admission_inquiry.submit`、`state_transition`、`trial_handoff`、`enrollment_link` 只記 outcome/reason code；429、422、500 與 orphan reference 各自可查。
- 回滾：先關閉前端 `VITE_ADMISSIONS_FUNNEL_V1` 與後端 `ADMISSIONS_FUNNEL_V1`；程式以 revert 回退；若 migration 已啟用，依 REP 的 snapshot／migration rollback procedure，禁止刪除或猜測修復 production data。
- Production activation、PII policy、permission widening、migration execution 均是本 RFC 外的 Founder gate；本 branch 只準備可審查 artifact。

## 12. 里程碑與優先級

- P0 `[FEATURE]`：schema、public submit、staff list/detail、state guard、trial handoff、既有 conversion link。
- P0 `[TEST]`：API security/idempotency/regression 與 frontend interaction tests。
- P0 `[REVIEW]`：STRIDE、campus isolation、PII/log scan、independent review。
- P1 `[FEATURE]`：mobile visual polish、filters、contact/result affordances、empty/error states。
- P1 `[DOCS]`：CHANGELOG、release execution package、runbook、operator smoke script。
- P1 `[OPS]`：CI、deploy evidence、health、bundle marker、feature flag verification。
- P2：nurture automation、multi-channel messaging、funnel analytics；明確不納入 V1。

## 13. 風險、假設、開放問題

| 風險 | 等級 | 業界／開源參考 | 本專案採行方式 |
|---|---|---|---|
| prospect 與正式 customer identity 混在一起 | 高 | Salesforce 將 lead capture/qualification/conversion 分階段；EspoCRM Lead 有獨立 status 與 ConvertService | inquiry 獨立 table；只在 trial handoff 建 Student，conversion 委派既有 flow。 |
| 重複建立與不可逆轉換 | 高 | Salesforce conversion 支援既有 account/contact、duplicate warning 且 conversion 不可逆；EspoCRM conversion 先做 duplicate check | keyed dedupe、row lock、idempotent handoff；正式 enrollment 保留既有 confirmation 與 conversion history。 |
| PII 被公開 endpoint 或 audit 洩漏 | 高 | EspoCRM 公開說明 role/field/entity security；AllTrue `SecurityAuditEvent` 已採 PII-minimized contract | encrypted casts、hash only dedupe、masked list、generic public response、audit allowlist。 |
| 流程過度變成 CRM／自建 scheduler | 中 | Salesforce 公開 lead stages 可作流程參考，但其 CRM/analytics scope 不適合直接搬入 | V1 僅 bounded inquiry lifecycle，重用 AllTrue enrollment/scheduling/conversion。 |

研究證據：AllTrue repository code／docs、[Salesforce lead management stages](https://www.salesforce.com/sales/what-is-lead-management/)、[Salesforce lead conversion](https://help.salesforce.com/s/articleView?id=sf.faq_leads_what_happens_when.htm&language=en_US&type=5)、[EspoCRM public demo landing page](https://www.espocrm.com/demo/)、以及 pinned maintained sources [EspoCRM](https://github.com/espocrm/espocrm/tree/c3770a47d46dfd6a8bbfc7b5bf276c8d0b4a059b)／[SuiteCRM-Core](https://github.com/SuiteCRM/SuiteCRM/tree/2cd77380bc838b8bd6c80f9fbe25855d73ef860c)。EspoCRM live demo host 在本次 bounded probe 重新導向至不同安全 host，未執行登入或提交資料。

### 假設與自動回退

- A-001：每筆公開 inquiry 有家長電話；若 validation 不成立，拒絕建立而不以姓名猜測 dedupe。
- A-002：trial scheduling 所需 teacher／subject／時段可由既有主任 workflow 提供；若資料不完整，停在 contacted，不建立 Student。
- A-003：既有 trial conversion response 可保存新 course reference；若契約改變，測試先 fail，inquiry 保持可重試且不改 Student。
- A-004：feature flag 可安全關閉；若 production smoke 發現異常，先 off flag，不直接刪除 inquiries／Students。

### Open questions

- `[AI-RESOLVABLE]`：確認現有 `EnrollmentService` 回應中的 trial class reference 與既有 conversion endpoint 的最小 linkage 欄位。
- `[AI-RESOLVABLE]`：確認 frontend 現有 tokens、navigation test harness 與 public shell 的最小 additive 接法。
- `[BLOCKED: Founder activation GO]`：production migration、公開入口 activation、PII retention period 或 permission widening 的最終批准；未取得前不執行 production write。

## 14. Definition of Done

- [x] 家長 guided interview 可送出且 duplicate 不增長 active inquiry：驗證方式：PHPUnit API tests + frontend interaction test，0 failures。
- [x] 主任只能管理所屬校區並可完成 contact／trial／result lifecycle：驗證方式：PHPUnit authorization/state tests，cross-campus assertions 全 PASS。
- [x] trial handoff 只建立一個 Student 並可安全重試：驗證方式：idempotency feature test，`Student` 與 trial course count 符合預期。
- [x] 報名沿用既有 conversion 且無重複 Student：驗證方式：TrialConversion regression + inquiry linkage test，source history preserved。
- [x] PII protection 與 STRIDE 無 HIGH：驗證方式：`[REVIEW]` static scan、log/audit assertion、route middleware audit。
- [x] mobile／a11y 規格通過：驗證方式：frontend test／bounded browser smoke，無水平 overflow、44px targets、keyboard labels present。
- [x] CI 與 build 通過：驗證方式：repository allowlisted test、lint、build commands exit 0。
- [x] 文件與 release evidence 齊全：驗證方式：diff 含 CHANGELOG、RFC、REP/runbook；production claims 具 workflow SHA、health 與 targeted API/UI evidence。
- [ ] production verified：驗證方式：只有在 Founder gate 後執行 deploy/migration/flag rollout，`make production-identity` 與 feature smoke 均 PASS；未授權前標記 **BLOCKED: Founder activation GO**，不宣稱完成。

**Dark-launch note（2026-09-04）**：程式與自動化驗收在 `ADMISSIONS_FUNNEL_V1=false` 下完成；production 啟用見 [`docs/runbooks/admissions-funnel-v1-founder-activation-brief.md`](../runbooks/admissions-funnel-v1-founder-activation-brief.md)。
