# Parent Binding Benchmark

| Field | Value |
|-------|-------|
| Status | Design only — no production code · 2026-07-26 |
| Related | Architecture · ADR |

**Method:** official Help/API/source/tests/advisories only. ★/license = activity risk, not UX proof.  
**Synthesis:** School-issued credential + identity≠relationship + expiry/revoke or staff approval + “contact school”. IdPs = hash/single-use/expiry/anti-enum. AllTrue hybrid = pairing (Canvas/Dojo) + request fallback + IdP lifecycle.

## Commercial

| Product | Evidence (2026-07-26) | Verified · Borrow · Don’t |
|---------|----------------------|---------------------------|
| **ClassDojo** | [Code](https://help.classdojo.com/hc/en-us/articles/202047699) · [Request](https://help.classdojo.com/hc/en-us/articles/360050943651) · [Troubleshoot](https://help.classdojo.com/hc/en-us/articles/202816855) | Per-child; ≤4; 30d; `P`+7–9; request→approve; acct≠link · code+request · class-centric |
| **Canvas** | Observees API · [`observer_pairing_code.rb`](https://github.com/instructure/canvas-lms/blob/master/app/models/observer_pairing_code.rb) · ★6748 AGPL pushed 2026-04-30 | 6-char; **7d** expiry; soft-delete; multi-code; NSW first-use · Observer+consume · plaintext short |
| **PowerSchool** | [Create Parent](https://ps.powerschool-docs.com/pssis-student-parent/latest/create-parent-account) | Own acct+Access ID/PW; multi-parent; email 24h; school-issued · acct≠link · dual creds heavy for LINE |
| **Clever** | [Privacy](https://www.clever.com/trust/privacy/policy) · [Families](https://support.clever.com/hc/s/articles/360020791011) | School controls data · org authority · SIS sync |
| **Schoology** | [Parent accounts](https://uc.powerschool-docs.com/en/schoology/latest/creating-parent-accounts-understanding-your-options) | `XXXX-XXXX-XXXX`; multi same code; admin reset · regenerate · long-lived risk |
| **Infinite Campus** | [Support](https://www.infinitecampus.com/support/parents-and-students) · district ops | District Activation Key; portal flag; one-time *common in district docs* · school-issued · SSN lookup |
| **Classroom** | [Guardian FAQ](https://support.google.com/edu/classroom/answer/7126518) | Staff email invite; 120d; self-remove · refuse path · not full portal |

## OSS

| Repo | ★ / License / Activity | Borrow · Don’t |
|------|------------------------|----------------|
| `keycloak/keycloak` | 35840 · Apache-2.0 · 26.7.0 (2026-07-09) · pushed 2026-07-26 | Action token single-use+expiry · replace ParentSession |
| `goauthentik/authentik` | 22493 · mixed · 2026.5.6 (2026-07-22) | Short expiry; **CVE-2025-64708** validate expiry · full IdP |
| `ory/kratos` | 13780 · Apache-2.0 · v26.2.0 · pushed 2026-07-25 | One-time; `notify_unknown_recipients:false`; plaintext at issue only · guardian semantics |
| `openfga/openfga` | 5499 · Apache-2.0 · v1.18.1 | Tuple concept · runtime FGA |
| `moodle/moodle` | 7285 · GPL-3.0 · pushed 2026-07-22 | User-context parent · admin-only |
| `GibbonEdu/core` | 621 · GPL-3.0 · pushed 2026-07-24 | Family links; Data Updater · full MIS |
| `frappe/education` | 583 · GPL-3 | Guardian party · stack mismatch |

## Patterns / reject / AllTrue

| | Dojo | Canvas | PS | Schoology | IC | Classroom |
|-|------|--------|-----|-----------|-----|-----------|
| Credential | Parent Code | Pairing | Access ID/PW | Access Code | Activation Key | Email invite |
| Expiry / multi / fallback | 30d / ≤4 / Request | 7d·first / multi codes / — | reset / shared / school | reset / same code / admin | one-time* / key* / flag | 120d / multi / staff |

| Reject | Why | Evidence→AllTrue |
|--------|-----|------------------|
| OTP-first | Mainstream=school cred; phones missing/wrong | Dojo→pairing+BindingRequest |
| Canvas plaintext 6-char | Learn lifecycle; hash+entropy | Canvas→state machine |
| Keycloak/authentik IdP | LINE+ParentSession overlap | PS→director issues |
| Clever SIS / copy-only | No SIS; misses enum/ops/orphan | Schoology→cap4 max_uses=1; Kratos→safe copy; authentik CVE→atomic consume; OpenFGA→GSR table; Gibbon→Inbox |

## Unconfirmed（MUST stay UNCONFIRMED）

| Item | Status |
|------|--------|
| ClassDojo / PowerSchool server-side hash | **UNCONFIRMED** |
| Infinite Campus Activation Key globally single-use | **UNCONFIRMED**（district docs only） |
| On-site paper/LINE code acceptance | **UNCONFIRMED**（observe post Phase 2） |

Founder Accepted Hybrid (ADR) does **not** promote rows above to confirmed.

## 無法確認（不得改寫為已證實）

- ClassDojo／PowerSchool 是否 server-side hash：**無法確認**
- Infinite Campus 是否全球 single-use：**無法確認**
- 臨櫃／LINE 發碼接受度：**尚未驗證**
