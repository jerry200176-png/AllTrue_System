# Parent Binding Threat Model

| Field | Value |
|-------|-------|
| Status | **ADR Accepted**（Founder 2026-07-26）— threat model only; **no production code** |
| Date | 2026-07-26 |
| Scope | LINE OA binding, Parent Portal login, guardian relationship lifecycle, staff pairing ops |
| Related | [`PARENT_BINDING_BENCHMARK.md`](../research/PARENT_BINDING_BENCHMARK.md), [`ADR-PARENT-STUDENT-BINDING.md`](../adr/ADR-PARENT-STUDENT-BINDING.md) |

> **原則**：文案模糊化是 **必要但不充分** 的控制。完整方案必須含 credential lifecycle、rate limit、audit、campus isolation、撤銷、與營運可觀測性。

---

## 1. Assets

| Asset | Sensitivity | Notes |
|-------|-------------|-------|
| Student PII（姓名、手機、分校、課程） | High | 家長入口可見評量／繳費摘要 |
| ParentIdentity（LINE user id、verified phone） | High | 跨學生關聯鍵 |
| GuardianStudentRelationship | High | 授權真相 |
| PairingCredential raw token | Critical | 僅發行瞬間可見；DB 只存 hash |
| ParentSession token | High | 現行 sha256 存 hash（可保留） |
| BindingAttempt / audit | Medium | 不可含完整手機明文 |
| Director inbox tasks | Medium | 高信號、可執行；無完整手機 |

---

## 2. Actors

| Actor | Intent |
|-------|--------|
| Legitimate parent/guardian | Bind to own children |
| Curious parent / social engineer | Enumerate students / guess phones |
| Malicious outsider | Brute force codes / IDOR |
| Compromised LINE account | Abuse existing relationship |
| Insider staff (director) | Legitimate ops；also over-privilege risk |
| Automated webhook replay | Accidental duplicate side effects |

---

## 3. Current-state attack surface（code-backed）

| Surface | Path | Notes |
|---------|------|-------|
| LINE webhook bind | `LineWebhookController::handleBindingByName/ById` | Campus-scoped；失敗文案含姓名＋分校；**無 bind audit**；**無 throttle** |
| Parent login | `POST /api/v1/parent/login` | `throttle:5,10`；name lookup **跨校區**；empty-phone → 401 明示補登 |
| LIFF login-line | `POST /api/v1/parent/login-line` | Relies on verified bindings |
| Staff unbind | `DELETE` line-bindings | Does not expire ParentSessions |
| Student delete | `purgeStudentRecords` | Purges ParentSession；**不刪** `student_line_bindings` |

Contact phone SSOT：`StudentContactPhone`（`parent_phone` → legacy `Phone`）。

---

## 4. Threat catalog

評分尺度（可驗證）：

- **Likelihood**：1 Rare → 5 Frequent/easy  
- **Impact**：1 Negligible → 5 Severe (PII / wrong guardian access)  
- **Detectability**：1 Easy to detect → 5 Blind（分數越高越難發現）  
- **Risk score** = Likelihood × Impact（Detectability 用於 residual / monitoring 優先級）

### T1 — Student existence enumeration

| Field | Value |
|-------|-------|
| Description | 攻擊者用不同姓名探測「是否存在」；或利用 empty-phone 401 確認姓名存在 |
| Likelihood | 4 |
| Impact | 3 |
| Detectability | 4（現行幾乎無 structured fail log） |
| Existing controls | LINE campus scope；parent login throttle；部分 404 模糊文案 |
| Gaps | LINE 失敗回覆含姓名；Portal empty-phone 401 明示；webhook 無 throttle |
| Proposed | 統一安全失敗文案；內部 reason code；rate limit webhook bind；never confirm existence to anonymous |
| Residual | Low–Med（社工仍可打電話問櫃檯——可接受，屬營運通道） |

### T2 — Phone number guessing

| Field | Value |
|-------|-------|
| Likelihood | 3（已知姓名時台灣手機空間仍大，但可針對特定家庭） |
| Impact | 5（成功即取得家長入口） |
| Detectability | 4 |
| Existing | Digit normalize match；login throttle 5/10min |
| Gaps | Webhook 無 throttle；成功路徑無 step-up |
| Proposed | Legacy name+phone 降級＋嚴格 rate limit；primary 改 pairing code；attempt counter + lockout |
| Residual | Med for legacy until sunset |

### T3 — Same-name wrong bind

| Field | Value |
|-------|-------|
| Likelihood | 3（同分校同名） |
| Impact | 5 |
| Detectability | 3 |
| Existing | Portal 多筆 → 409；**LINE first-match wins** |
| Proposed | Ambiguous → fail closed（`AMBIGUOUS_MATCH`）；要求 pairing code / StudentID / staff approval |
| Residual | Low if fail-closed |

### T4 — Cross-campus data leak

| Field | Value |
|-------|-------|
| Likelihood | 3 |
| Impact | 4 |
| Detectability | 3 |
| Existing | LINE campus filter；historical cleanup migration for cross-campus LINE ids |
| Gaps | Parent name login **global** `TRIM(name)`；login-line returns all campuses' children |
| Proposed | Name login 必須 campus-scoped 或廢止；pairing credential 綁 `campus_id`；relationship 帶 scope |
| Residual | Low |

### T5 — Unauthorized guardian access

| Field | Value |
|-------|-------|
| Likelihood | 3 |
| Impact | 5 |
| Existing | Contact phone gate；verified_at required（2026-07-24 migration） |
| Gaps | Phone change 不撤銷舊 binding；status/graduate 不影響存取 |
| Proposed | Relationship lifecycle；revoke on transfer/archive；re-verify policy on phone change |
| Residual | Med without ops discipline |

### T6 — Pairing code screenshot / forward

| Field | Value |
|-------|-------|
| Likelihood | 4（紙本／LINE 轉傳常見） |
| Impact | 4 |
| Detectability | 4 |
| Existing | N/A（尚未實作） |
| Proposed | **Rejected default：shared multi-use code**（Founder：`max_uses=1`，每監護人獨立碼）。TTL 預設 **7d**（staff 可選 24h/72h/7d）；consume audit；staff revoke/regenerate；active unused cap **4**/student+campus；顯示「勿轉傳」 |
| Residual | Med（實體轉傳無法消滅；靠 TTL+single-use+revoke+cap） |

### T6b — Active credential cap exhaustion / staff spam issue

| Field | Value |
|-------|-------|
| Likelihood | 2 |
| Impact | 2 |
| Proposed | Enforce max 4 active unused credentials per student+campus；issue fails closed with `ACTIVE_CREDENTIAL_CAP` |
| Residual | Low |

### T7 — Token replay

| Field | Value |
|-------|-------|
| Likelihood | 3 |
| Impact | 4 |
| Proposed | Single-use 或 max_uses 原子 consume（`UPDATE … WHERE used_count < max AND revoked_at IS NULL AND expires_at > now`）；DB unique；idempotency key |
| Residual | Low |

### T8 — Brute-force codes

| Field | Value |
|-------|-------|
| Likelihood | 3 if short codes |
| Impact | 5 |
| Proposed | ≥128-bit secret in link；short human code ≥ 10^10 空間或加 campus salt；per-IP / per-LINE rate limit；exponential backoff；lock credential after N attempts |
| Residual | Low–Med |

### T9 — Full phone in logs

| Field | Value |
|-------|-------|
| Likelihood | 4（若未來加 log 易犯） |
| Impact | 4 |
| Existing | Parent login logs count/student_id；webhook bind **目前幾乎不記** |
| Proposed | Masked phone（`09****5678`）；禁止 raw phone in Notification / Inbox；CI log PII scan |
| Residual | Low with policy |

### T10 — LINE chat retention of full phone

| Field | Value |
|-------|-------|
| Likelihood | 5（現行流程要求家長輸入完整手機） |
| Impact | 3–4（對話歷史長期可見） |
| Proposed | Primary 改 pairing code／deep link；legacy sunset；教育家長勿在公開群貼碼 |
| Residual | Med until sunset |

### T11 — Over-privileged director

| Field | Value |
|-------|-------|
| Likelihood | 2 |
| Impact | 4 |
| Existing | `role:director` + `require_campus` |
| Proposed | Issue/revoke pairing 僅 campus scope；audit `created_by`；敏感操作二次確認；super_admin 跨校另權 |
| Residual | Low–Med |

### T12 — Multi-guardian revoke gaps / session revocation

| Field | Value |
|-------|-------|
| Likelihood | 3 |
| Impact | 4 |
| Existing | Staff can delete one `StudentLineBinding` |
| Gaps | 不失效 ParentSession；無「撤銷所有監護人」批次；無通知其他監護人 |
| Proposed | **Founder 強制**：Revoke relationship → **立即失效**該 parent+student 的 ParentSession；audit；optional notify |
| Residual | Low with enforced invalidation tests |

### T12b — Self-serve BindingRequest enumeration / spam

| Field | Value |
|-------|-------|
| Likelihood | 3 |
| Impact | 3 |
| Proposed | Require authenticated LINE identity；safe generic response（永不確認學生存在）；rate limit；dedupe；staff masked evidence only；Inbox cooldown |
| Residual | Low–Med |

### T13 — Alumni / transferred student still accessible

| Field | Value |
|-------|-------|
| Likelihood | 3 |
| Impact | 3 |
| Existing | `status` ignored by login/bind |
| Proposed | Policy：`inactive`/`graduated` → relationship `suspended`；portal read-only or deny（Founder 決策） |
| Residual | Depends on product policy |

### T14 — Orphan bindings after delete/merge

| Field | Value |
|-------|-------|
| Likelihood | 3 |
| Impact | 3 |
| Existing | Delete student 不清 `student_line_bindings` |
| Proposed | FK ON DELETE CASCADE 或 purge hook；merge playbook；orphan job |
| Residual | Low |

### T15 — Race duplicate relationship

| Field | Value |
|-------|-------|
| Likelihood | 2 |
| Impact | 2 |
| Existing | UNIQUE `(student_id, line_user_id)` |
| Proposed | UNIQUE active relationship `(parent_identity_id, student_id)` WHERE status=active；transactional consume |
| Residual | Low |

### T16 — Webhook retry duplicate side effects

| Field | Value |
|-------|-------|
| Likelihood | 3 |
| Impact | 2 |
| Existing | Re-bind → already-bound message；HTTP 200 |
| Proposed | LINE event delivery dedupe key；idempotent bind；structured audit once |
| Residual | Low |

### T17 — IDOR on staff/parent APIs

| Field | Value |
|-------|-------|
| Likelihood | 2 |
| Impact | 5 |
| Existing | Campus checks on staff binding delete；parent session self-scope |
| Proposed | Contract tests：caller 不可傳任意 `campus_id`／`student_id` 越權；pairing inspect 不洩漏 student PII |
| Residual | Low |

### T18 — Tenant / campus boundary bypass

| Field | Value |
|-------|-------|
| Likelihood | 3 |
| Impact | 5 |
| Existing | Middleware `require_campus`；webhook campus resolution |
| Gaps | Global name login；multi-campus LINE user portal |
| Proposed | Credential + relationship always carry `campus_id`；cross-campus switch explicit & audited |
| Residual | Low |

---

## 5. Abuse-case → control mapping（摘要）

```
Anonymous probe name/phone
  → uniform safe copy + RATE_LIMITED + no inbox spam

Ambiguous same-campus names
  → AMBIGUOUS_MATCH fail-closed + staff pairing code

Stolen/forwarded pairing code
  → short TTL + max_uses + revoke + audit who consumed

Staff mistakes phone
  → completeness UI + pairing primary so phone not sole authz

Student leaves campus
  → suspend/revoke relationships + session expiry job
```

---

## 6. What “safe copy” is NOT

| Myth | Reality |
|------|---------|
| 統一錯誤文案 = 安全完成 | 只降低 enumeration；不阻止猜中／錯綁／orphan |
| 隱藏「學生不存在」即可 | 仍需 rate limit、歧義 fail-closed、credential 模型 |
| OTP 自動更安全 | 缺手機／錯手機時無法送達，且 SMS 成本與洩漏面 |

---

## 7. Security acceptance gates（給實作階段）

1. Anonymous failure responses 不可區分 `STUDENT_NOT_FOUND` / `PHONE_MISMATCH` / `CONTACT_PHONE_MISSING`。  
2. Raw pairing token 永不入 DB／普通 log／Inbox。  
3. Consume 必須單語句原子或 transaction + row lock。  
4. 所有 staff mutating endpoints campus-scoped + audit。  
5. Feature tests：enumeration、IDOR、cross-campus、concurrent consume、replay。  
6. Log PII scan in CI for parent-binding paths.  
