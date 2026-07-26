# Parent Binding Benchmark — Evidence-Based Research

| Field | Value |
|-------|-------|
| Status | Design review only — **no production code** |
| Research date | 2026-07-26 |
| Repo SHA base | `origin/main` at research start |
| Audience | Founder decision + implementation planning |
| Related | [`PARENT_IDENTITY_TARGET_ARCHITECTURE.md`](../architecture/PARENT_IDENTITY_TARGET_ARCHITECTURE.md), [`ADR-PARENT-STUDENT-BINDING.md`](../adr/ADR-PARENT-STUDENT-BINDING.md) |

> **方法論**：每個來源必須是官方 Help Center、官方 API / docs、公開 source、tests、security advisory 或 release notes。不採行銷首頁、SEO 部落格、二手摘要、AI 生成文、或「因為 stars 高」作為架構證據。stars / license / activity 僅作**活躍度與供應商風險**指標，不作為「教育現場最佳 UX」證據。

---

## 1. Executive synthesis（先結論）

大型教育產品對「家長—學生關係」幾乎一致採：

1. **學校／教師為授權權威**（發出 Access ID、Parent Code、Activation Key、或 email invite）。
2. **家長身份（account）與監護關係（relationship）分離**。
3. **有期限、可重發／可重設的配對憑證**，或 **主任／教師審核的 pending request**。
4. 失敗時把家長導向「聯繫學校」，而不是系統猜測「學生是否存在」。

高成熟度身份系統（Keycloak / authentik / Ory Kratos）則一致要求：**token hash、expiry、single-use、revoke、audit、anti-enumeration**。它們是 credential lifecycle 的借鏡，**不是**補習班家長 UX 的模板。

**對 AllTrue 的含義**：現行「LINE 對話輸入姓名＋完整手機」同時承擔了 authentication、relationship creation、campus scoping 與 support triage，與成熟產品的邊界切割不符。最佳借鏡是 **Canvas / ClassDojo / PowerSchool / Schoology 的 school-issued pairing credential**，加上 **ClassDojo / Google Classroom 的 staff-approval fallback**，並以 Kratos / authentik / Keycloak 的 token lifecycle 補強安全。

---

## 2. Evidence table — 商業／大型教育產品

### 2.1 ClassDojo — Parent Code + Request to Connect

| 欄位 | 內容 |
|------|------|
| 系統 | ClassDojo |
| 官方來源 | Help Center |
| 查閱日期 | 2026-07-26 |
| Stars | N/A（closed product） |
| 活躍度 | Help Center 持續維護（官方文章可存取） |
| License | Proprietary |
| 研究功能 | Parent account creation；Parent Code（per-child）；multi-parent；request-to-connect；troubleshooting |
| 證據連結 | [Create Parent Account](https://help.classdojo.com/hc/en-us/articles/205417305-Create-a-Parent-Account)；[Parent Code](https://help.classdojo.com/hc/en-us/articles/202047699-Connecting-to-Your-Child-s-Class-via-a-Parent-Code)；[Code troubleshooting](https://help.classdojo.com/hc/en-us/articles/202816855-Troubleshooting-Parent-Code-Does-Not-Work)；[Request to Connect](https://help.classdojo.com/hc/en-us/articles/360050943651-Request-to-Connect-to-Your-Child-s-Teacher) |

**已驗證事實（來自官方 Help，非推測）：**

- Parent Code **對每個孩子唯一**；可由 **最多 4 位**家長／監護人使用。
- Code **建立後 30 天過期**；達上限或過期 → 聯絡教師拿新碼。
- Code 格式：以 `P` 開頭、7–9 位數（官方 troubleshooting）。
- 無 code 時可 **搜尋學校／教師 → Request to connect → pending until teacher approves**。
- 家長先建立自己的 Parent Account，再連結孩子（identity ≠ relationship）。

**可借鑑：** school/teacher-issued code；expiry；multi-guardian max uses；approval fallback；失敗導向教師而非系統列舉。

**不適合 AllTrue 直接照抄：** ClassDojo 以「班級／教師」為中心；AllTrue 以「分校 Campus＋學生」為中心；沒有 LINE OA 綁定通道；4-parent 上限需產品決策而非硬抄。

---

### 2.2 Canvas LMS — Observer / Observee + Pairing Code

| 欄位 | 內容 |
|------|------|
| 系統 | Canvas LMS（Instructure） |
| 官方來源 | Canvas REST API docs + 公開 source |
| 查閱日期 | 2026-07-26 |
| Stars | 6,748（`instructure/canvas-lms`，2026-07-26 via GitHub API） |
| 活躍度 | `pushed_at` 2026-04-30；持續維護；AGPL-3.0 |
| License | AGPL-3.0 |
| 研究功能 | Observer↔Observee；`ObserverPairingCode`；generate permission；expiry；consume |
| 證據連結 | [User Observees API](https://canvas.instructure.com/doc/api/user_observees.html)；source [`observer_pairing_code.rb`](https://github.com/instructure/canvas-lms/blob/master/app/models/observer_pairing_code.rb)；[`User#generate_observer_pairing_code`](https://github.com/instructure/canvas-lms/blob/master/app/models/user.rb)（L3986–3993）；[`observer_pairing_codes_api_controller.rb`](https://github.com/instructure/canvas-lms/blob/master/app/controllers/observer_pairing_codes_api_controller.rb)；spec [`observer_pairing_code_spec.rb`](https://github.com/instructure/canvas-lms/blob/master/spec/models/observer_pairing_code_spec.rb) |

**已驗證事實：**

- PairingCode object：`user_id`, `code`, `expires_at`, `workflow_state`（API model）。
- `generate_observer_pairing_code`：`SecureRandom.base64` 截短為 6 字元、迴圈避開 active collision、**`expires_at: 7.days.from_now`**（source）。
- `ObserverPairingCode.active` scope：`workflow_state <> 'deleted' AND expires_at > now`；`destroy` 為 soft-delete（`workflow_state='deleted'`）。
- API `POST /api/v1/users/:user_id/observer_pairing_codes` 需 `:generate_observer_pairing_code` 權限；學生須有 enrollment 且 account 允許 self-registration。
- 可產生多個 code（spec：`can generate more than one code`）。
- NSW DoE 公開 PDF 說明：**codes expire after seven days or first successful use**（機構操作說明，與 source expiry 對齊）。

**可借鑑：** 明確 Observer/Observee（關係模型）；permission-gated issuance；expiry + soft revoke；consume 後失效；institution/account scope。

**不適合照抄：** Canvas 是 LMS 帳號體系（login/password）；AllTrue 家長主通道是 LINE；6-char base64 entropy 對公開猜測面需加 rate limit（Canvas 有帳號邊界保護）。AllTrue 應採 **更高 entropy + hash-at-rest**（見 Kratos/authentik），不要明文存 code。

---

### 2.3 PowerSchool — Access ID / Access Password

| 欄位 | 內容 |
|------|------|
| 系統 | PowerSchool SIS Student and Parent portal |
| 官方來源 | PowerSchool Docs |
| 查閱日期 | 2026-07-26 |
| Stars | N/A |
| 活躍度 | Official product docs（`ps.powerschool-docs.com`） |
| License | Proprietary |
| 研究功能 | Parent vs Student account 分離；Access ID/Password；multi-student；email verification；admin-issued credentials |
| 證據連結 | [Create Parent Account](https://ps.powerschool-docs.com/pssis-student-parent/latest/create-parent-account)；[Accounts](https://ps.powerschool-docs.com/pssis-student-parent/latest/accounts)；[Parent & Student Resource Center](https://www.powerschool.com/community-support/parent-student-resource-center/) |

**已驗證事實：**

- Parent 建立 **自己的** username/password 帳號；用學校提供的 **Access ID + Access Password** 連結每位學生。
- **多位家長**可各自建立帳號、共用同一組 student access credentials。
- 註冊後 **email verification，24 小時內**點連結。
- 「若沒有 Access ID，聯絡學校」——學校是 credential 權威。
- PowerSchool 官方明確：登入資訊由 school/district 核發，公司本身不公開各校 URL 清單。

**可借鑑：** identity/account ≠ student link；admin/school-issued credentials；multi-child；email verify；recovery 與學校支援分工。

**不適合照抄：** 雙憑證（ID+Password）對 LINE 場景過重；台灣補習班主任現場多為口頭／紙本／LINE 傳遞短碼，Canvas/ClassDojo 式單碼較貼近。

---

### 2.4 Clever — School-managed identity & family privacy

| 欄位 | 內容 |
|------|------|
| 系統 | Clever |
| 官方來源 | Trust / Privacy / Support |
| 查閱日期 | 2026-07-26 |
| Stars | N/A |
| 活躍度 | Active commercial identity platform for education |
| License | Proprietary |
| 研究功能 | School/district as data & auth authority；family security messaging；FERPA school-official framing |
| 證據連結 | [Privacy Policy](https://www.clever.com/trust/privacy/policy)；[Privacy overview](https://www.clever.com/trust/privacy)；[For Families: security](https://support.clever.com/hc/s/articles/360020791011)；[Schools Terms](https://www.clever.com/trust/terms/schools) |

**已驗證事實：**

- Schools/districts **控制**何資料進入 Clever、與哪些 app 分享；Clever 以 school direction 處理 Student Data。
- Family-facing 訊息強調：控制權在 school organization；家長疑問導回學校。
- Clever 定位是 **rostering / identity platform**，不是家長自助用姓名+手機猜綁。

**可借鑑：** campus/org 為授權與資料權威；家長問題導向學校；最小化資料分享。

**不適合照抄：** Clever 假設 district SIS sync；AllTrue 四校區補習班沒有同等 roster pipeline；不可導入整套 IdP。

---

### 2.5 Schoology — Parent Access Code（XXXX-XXXX-XXXX）

| 欄位 | 內容 |
|------|------|
| 系統 | Schoology（PowerSchool Learning） |
| 官方來源 | PowerSchool Learning docs + Customer Central |
| 查閱日期 | 2026-07-26 |
| Stars | N/A |
| 活躍度 | Official docs maintained |
| License | Proprietary |
| 研究功能 | Parent Access Code；multi-guardian same code；Add Child；admin reset codes；SIS sync alternative |
| 證據連結 | [Creating Parent Accounts](https://uc.powerschool-docs.com/en/schoology/latest/creating-parent-accounts-understanding-your-options)；[Invalid access code troubleshooting](https://customercentral.powerschool.com/s/article/troubleshoot-access-code-not-working-parent-269796?language=en_US) |

**已驗證事實：**

- 每位學生有 **Parent Access Code**，格式 `XXXX-XXXX-XXXX`（12 字元）。
- **多位家長／監護人可用同一 code** 各自建立帳號。
- 多子女：先用一碼註冊，再 **Add Child** 用另一碼。
- Admin 可 download codes、**reset** 錯誤發放的 codes。
- 亦支援 SIS sync 或 admin 手動建立 parent–child association（學校權威路徑）。

**可借鑑：** 可讀短碼；multi-use guardian policy 明確；regenerate；staff distribution workflow（CSV download）。

**不適合照抄：** 長期不變的 access code（若未 rotate）風險高於 Canvas 7-day code；AllTrue 應預設 **短壽命 + hash**，並用 `max_uses` 表達 multi-guardian。

---

### 2.6 Infinite Campus — Activation Key（district-issued）

| 欄位 | 內容 |
|------|------|
| 系統 | Infinite Campus (Campus Parent) |
| 官方來源 | Infinite Campus Support + district operational pages |
| 查閱日期 | 2026-07-26 |
| Stars | N/A |
| License | Proprietary |
| 研究功能 | New User + Activation Key；one-time claim；relationship portal flag managed by school |
| 證據連結 | [Parents & students support](https://www.infinitecampus.com/support/parents-and-students)（「Infinite Campus does not have this information」— key 由 district 發）；district examples e.g. [Rockwood](https://www.rsdmo.org/departments/technology/infinite-campus/establishing-an-infinite-campus-account) |

**已驗證事實：**

- 新用戶需 **district-issued Activation Key**；官方明確公司不持有各戶 key。
- 關係必須在 Campus 設為含 Portal access，否則看不到孩子——**學校管理關係旗標**。
- 多個學區文件描述 Activation Key **使用後不可再用於建第二帳**（one-time claim；學區實作文件，需注意非單一全球規格）。

**可借鑑：** school-issued activation；company/platform 不越權發碼；relationship flag 與 account 分離。

**不適合照抄：** 依賴 SSN / student number lookup 的學區流程（台灣個資與補習班場景不合）。

---

### 2.7 Google Classroom — Guardian invitation

| 欄位 | 內容 |
|------|------|
| 系統 | Google Classroom |
| 官方來源 | Google Help |
| 查閱日期 | 2026-07-26 |
| Stars | N/A |
| License | Proprietary |
| 研究功能 | Teacher/admin email invite；accept window；guardian self-remove；no class-code for guardians |
| 證據連結 | [Get email summaries (guardians)](https://support.google.com/edu/classroom/answer/6388136)；[Guardian FAQ](https://support.google.com/edu/classroom/answer/7126518) |

**已驗證事實：**

- Guardian **不能**用 class code 加入；必須由 teacher/admin **寄送 email invitation**。
- 接受期限 **120 days**（官方 guardian 文）。
- Guardian 可自行 remove；可「I'm Not The Guardian」。
- Admin 可限制誰能 invite/remove guardians。

**可借鑑：** staff-initiated invite；明確拒絕路徑；長期限適合 email，但 AllTrue LINE 場景宜更短；權限分級。

**不適合照抄：** Classroom guardian 主要是 email summary，不是完整家長入口／繳費／評量；AllTrue 關係一旦 active 權限更大，expiry 應更短、審核更嚴。

---

## 3. Evidence table — 開源身份與教育系統

### 3.1 Keycloak

| 欄位 | 內容 |
|------|------|
| Repository | `keycloak/keycloak` |
| Stars | 35,840（2026-07-26） |
| License | Apache-2.0 |
| 最近 release | 26.7.0（2026-07-09） |
| `pushed_at` | 2026-07-26 |
| 研究功能 | Action tokens；organization invitations；single-use；expiry；admin delete/resend |
| 證據 | [DefaultActionToken javadoc](https://www.keycloak.org/docs-api/26.6.1/javadocs/org/keycloak/authentication/actiontoken/DefaultActionToken.html)（single-use nonce + absolute expiration）；[InvitationManager](https://www.keycloak.org/docs-api/latest/javadocs/org/keycloak/organization/InvitationManager.html)；[OrganizationInvitationsResource](https://www.keycloak.org/docs-api/26.5.7/javadocs/org/keycloak/admin/client/resource/OrganizationInvitationsResource.html)（delete permanent；resend = new token + fresh expiry） |

**可借鑑：** invite lifecycle（create / expire / resend / delete）；action token single-use；org-scoped invite。

**不適合：** 不應把 AllTrue 家長入口整包換成 Keycloak；過重、與 LINE identity 重疊。

---

### 3.2 authentik

| 欄位 | 內容 |
|------|------|
| Repository | `goauthentik/authentik` |
| Stars | 22,493（2026-07-26） |
| License | Mixed（core GPL-ish / enterprise separate — GitHub `NOASSERTION`；LICENSE 標示多段） |
| 最近 release | version/2026.5.6（2026-07-22） |
| 研究功能 | Invitations：expiry（default 48h）、single-use、flow-bound token、admin share link |
| 證據 | [Invitations docs](https://docs.goauthentik.io/users-sources/user/invitations/)；[Invitation stage](https://docs.goauthentik.io/add-secure-apps/flows-stages/stages/invitation/)；[CVE-2025-64708](https://docs.goauthentik.io/security/cves/CVE-2025-64708/)（expiry 必須在 validate 時檢查，不可只靠 background cleanup） |

**可借鑑：** default short expiry；single-use vs multi-use 明確產品開關；**validate-on-use 必須檢查 is_expired**（authentik CVE 教訓直接適用 AllTrue）。

**不適合：** 整包 IdP；AllTrue 已有 Bearer ParentSession。

---

### 3.3 Ory Kratos

| 欄位 | 內容 |
|------|------|
| Repository | `ory/kratos` |
| Stars | 13,780（2026-07-26） |
| License | Apache-2.0 |
| 最近 release | v26.2.0（2026-03-20）；`pushed_at` 2026-07-25 |
| 研究功能 | Recovery code/link；invite via recovery；lifespan；`notify_unknown_recipients: false`；never log plaintext |
| 證據 | [Account recovery flow](https://www.ory.com/docs/kratos/self-service/flows/account-recovery-password-reset)；[Invite users](https://www.ory.com/docs/kratos/manage-identities/invite-users)；[Admin recovery](https://www.ory.com/docs/kratos/manage-identities/account-recovery) |

**可借鑑（對 AllTrue pairing credential 極關鍵）：**

- Prefer one-time **code** over long-lived magic link when channel is SMS/chat。
- **`notify_unknown_recipients: false`** — 反 enumeration 的官方預設哲學。
- Admin API 產生 code；**明文只在回應瞬間給呼叫端，不落 DB plaintext**（文件安全考量）。
- Invite = create identity + time-bounded recovery credential。
- Code/link lifespan 可配（例 15m / 12h）。

**不適合：** 替換整個 ParentSession；Kratos 是通用 IdP，不懂 guardian–student 教育關係。

---

### 3.4 OpenFGA — Relationship-based authorization

| 欄位 | 內容 |
|------|------|
| Repository | `openfga/openfga` |
| Stars | 5,499（2026-07-26） |
| License | Apache-2.0 |
| 最近 release | v1.18.1（2026-06-29） |
| 研究功能 | Authorization model；relationship tuples；Check / ListObjects；conditional tuples |
| 證據 | [Concepts](https://openfga.dev/docs/concepts)；[Modeling](https://openfga.dev/docs/modeling/getting-started) |

**可借鑑：** 把 `parent#guardian_of→student` 當一等公民關係；revoke = delete/conditional tuple；campus 可建模為 `organization`。

**不適合本期導入：** AllTrue 規模與運維不需獨立 FGA 服務；應用層表 `GuardianStudentRelationship` 即可表達同等語義，文件中保留 Zanzibar 風格作為概念模型。

---

### 3.5 Moodle — Parent / Mentee role

| 欄位 | 內容 |
|------|------|
| Repository | `moodle/moodle` |
| Stars | 7,285（2026-07-26） |
| License | GPL-3.0 |
| `pushed_at` | 2026-07-22 |
| 研究功能 | Custom Parent role；assign parent→student at user context；Mentees block |
| 證據 | [Parent role](https://docs.moodle.org/500/en/Parent_role)；[Mentees block](https://docs.moodle.org/500/en/Mentees_block) |

**可借鑑：** 關係在 **user context** 指派（非全域「只要同手機」）；權限最小化；多 mentee。

**不適合：** Moodle 假設 admin 手動指派，缺少家長自助 pairing UX；AllTrue 需要自助 + staff ops。

---

### 3.6 Gibbon — Family-centric school MIS

| 欄位 | 內容 |
|------|------|
| Repository | `GibbonEdu/core` |
| Stars | 621（2026-07-26） |
| License | GPL-3.0 |
| `pushed_at` | 2026-07-24 |
| 研究功能 | Parent dashboard；family links on admission accept；Data Updater（家長提交、學校審核） |
| 證據 | [Parents tutorial](https://docs.gibbonedu.org/tutorials/using-gibbon/parents)；[Features](https://gibbonedu.org/features)；[Data Updater](https://docs.gibbonedu.org/guides/modules/data-updater/data-updater) |

**可借鑑：** 入學接受時自動建立 family links（學校權威）；家長改資料走 **request → staff verify**（與 binding request 同構）。

**不適合：** 整包 MIS；Gibbon 家長帳號多由學校建立，非 LINE 自助。

---

### 3.7 Frappe Education

| 欄位 | 內容 |
|------|------|
| Repository | `frappe/education` |
| Stars | 583（2026-07-26） |
| License | GNU GPL V3（repo `license.txt`） |
| `pushed_at` | 2026-07-24 |
| 研究功能 | Education ERP modules（student / guardian patterns in ERPNext lineage） |
| 證據 | GitHub repo metadata + license file |

**可借鑑：** ERP 中 Guardian 常為獨立 party 連到 Student（關係表思維）。

**不適合：** 與 AllTrue Laravel/Vue 堆疊不同；不直接移植 DocType。

---

## 4. Cross-product pattern matrix

| Pattern | ClassDojo | Canvas | PowerSchool | Schoology | Infinite Campus | Google Classroom | Moodle | Gibbon |
|---------|-----------|--------|-------------|-----------|-----------------|------------------|--------|--------|
| School-issued credential | Parent Code | Pairing code | Access ID/PW | Access Code | Activation Key | Email invite | Admin assign | School-created accounts |
| Parent account separate | Yes | Yes (Observer user) | Yes | Yes | Yes | Google Account | Yes | Yes |
| Expiry | 30d | 7d / first use | Admin reset | Resetable | One-time claim* | 120d invite | N/A | N/A |
| Multi-guardian | ≤4 / code | Multiple codes | Shared access creds | Same code OK | Per household/key* | Multiple invites | Multiple role assigns | Family links |
| Approval fallback | Request to connect | N/A (code/password) | School creates account | Admin associate | School relationship flag | Staff invite only | Admin only | Admission accept |
| Anti-enumeration UX | Contact teacher | Auth wall | Contact school | Invalid code | Contact district | No invite = no access | N/A | N/A |

\*Infinite Campus one-time claim 來自學區操作文件，非單一全球 API 規格；標為「常見實作」。

---

## 5. What NOT to copy（明確拒絕）

| 誘惑 | 為何拒絕 |
|------|----------|
| 「大公司都用 OTP」 | PowerSchool/ClassDojo/Canvas/Schoology 主流是 **school-issued credential**，不是對已存手機發 SMS OTP。OTP 適合「證明持有已驗證手機」，但 AllTrue 核心痛點是 **手機資料常缺失／錯誤**。 |
| 因 Canvas 6.7k stars 就抄 6-char plaintext code | Entropy／hash／rate-limit 不足；應學 lifecycle 而非字串長度。 |
| 導入 Keycloak/authentik 當家長 IdP | 與既有 LINE + ParentSession 雙真相；運維過重。 |
| Clever 式 district sync | 無 SIS；四校區補習班。 |
| 只改錯誤文案 | 不解決 enumeration、歧義綁定、主任無工作流、orphan binding。 |

---

## 6. Implications for AllTrue hybrid

| 外部證據 | AllTrue 設計含義 |
|----------|------------------|
| ClassDojo Parent Code + request | Primary = pairing code；Fallback = BindingRequest |
| Canvas 7d / consume / soft revoke | PairingCredential state machine |
| PowerSchool Access ID school-issued | Director 從學生頁發碼；非家長自助列舉學生 |
| Schoology multi-guardian same code | `max_uses` 可配置（建議預設 2–4） |
| Kratos notify_unknown_recipients=false | 匿名失敗統一文案；內部 reason code |
| authentik CVE expiry check | consume 路徑原子檢查 expires_at／revoked_at |
| OpenFGA tuples | DB 上明確 GuardianStudentRelationship，而非塞回 StudentLineBinding |
| Gibbon Data Updater | 缺 parent_phone → staff completeness workflow |

---

## 7. Unresolved / cannot confirm

| 項目 | 狀態 |
|------|------|
| ClassDojo code 是否 server-side hash | 無法從 Help Center 確認實作細節 |
| PowerSchool Access Password entropy／儲存 | 官方 docs 未公開演算法 |
| Infinite Campus Activation Key 全域是否一律 single-use | 僅見學區文件；不以全球規格宣稱 |
| Canvas API 頁面 2026-07-26 部分 CDN 維護頁 | 以 GitHub source + 既有 API mirror／搜尋快取為準；source `expires_at: 7.days` 已直接驗證 |
| 台灣補習班家長對「紙本／LINE 傳配對碼」接受度 | 需 Founder／現場驗證（產品假設，非外部證據） |

---

## 8. Source checklist（查閱日期一律 2026-07-26）

1. ClassDojo Help — Parent Code / troubleshooting / request to connect  
2. Canvas LMS source — `ObserverPairingCode`, `User#generate_observer_pairing_code`  
3. Canvas API — User Observees / PairingCode model  
4. PowerSchool Docs — Create Parent Account / Accounts  
5. Clever Trust — Privacy / Schools terms / Family security article  
6. Schoology (PowerSchool) — Creating Parent Accounts  
7. Infinite Campus — Parents & students support  
8. Google Classroom Help — Guardian summaries accept (120 days)  
9. Keycloak docs-api — ActionToken / Organization invitations  
10. authentik docs — Invitations + CVE-2025-64708  
11. Ory Kratos docs — recovery / invite / notify_unknown_recipients  
12. OpenFGA docs — Concepts  
13. MoodleDocs — Parent role / Mentees  
14. Gibbon Docs — Parents / Data Updater  
15. GitHub API — stars / license / releases for listed OSS repos  
