# ADR: Parent–Student Binding Redesign

| Field | Value |
|-------|-------|
| Status | **Proposed**（待 Founder 決策；DESIGN REVIEW ONLY） |
| Date | 2026-07-26 |
| Deciders | Founder（approve）；Principal Product Architect（recommend） |
| Related | Benchmark · Target Architecture · Threat Model · UX Spec · Rollout |

---

## Context

AllTrue 家長綁定現行以 LINE OA「綁定 姓名 手機」與 Parent Portal「姓名／學號＋手機」為主路徑。權威聯絡手機為 `StudentContactPhone`（`parent_phone` → legacy `Phone`）。

已知產品／安全／營運缺口（code-backed，見 Target Architecture §Current State）：

1. 失敗文案把多種根因壓成「找不到姓名與此手機」。  
2. Portal 對空手機給 401 補登提示，LINE 沒有——行為不一致且部分路徑可列舉。  
3. LINE 同分校同名同條件 **first-match**；Portal 多筆則 409。  
4. Parent name login **跨校區**；LINE bind campus-scoped。  
5. 主任無缺資料／失敗案件工作流；Action Inbox 不含 binding。  
6. 刪學生留下 orphan `student_line_bindings`；撤銷 binding 不失效 ParentSession。  
7. 家長在 LINE 對話傳送完整手機（對話留存風險）。

核心問題不是文案，而是：**如何安全、可理解、可營運地驗證家長身分並建立監護關係，同時讓分校補齊資料。**

外部研究（[`PARENT_BINDING_BENCHMARK.md`](../research/PARENT_BINDING_BENCHMARK.md)）顯示成熟教育產品普遍採用 **school-issued pairing credential**，並以 **staff approval** 為 fallback；OTP-first 並非主流家庭入口模式。

---

## Decision drivers（加權）

| Criterion | Weight |
|-----------|--------|
| Security / privacy | 30% |
| 補習班營運可執行性 | 25% |
| 家長 UX | 20% |
| 向下相容與 migration | 15% |
| 成本與維運 | 10% |

分數 1–5；加權總分 = Σ(score × weight)。

---

## Options

### Option A — 現行姓名＋手機強化版

保留流程；安全文案；structured reason；rate limit；masking；主任補資料；ambiguous fail-closed。

| Criterion | Score | Evidence / reason |
|-----------|------:|-------------------|
| Security / privacy | 2 | 仍依賴可猜測／可列舉的姓名+手機；完整手機進 LINE 對話（Threat T2/T10） |
| Ops executability | 4 | 主任已熟悉；補資料 UI 可立刻降摩擦 |
| Parent UX | 3 | 習慣不變，但缺手機時仍卡住且文案難解釋 |
| Compat / migration | 5 | 幾乎無 schema 大改 |
| Cost / ops | 5 | 無 SMS |
| **Weighted** | **3.45** | 0.6+1.0+0.6+0.75+0.5 |

### Option B — 分校產生一次性 Parent Pairing Code

主任從學生頁產生高熵碼；server-side hash；expiry；single-use 或 max_uses；campus-scoped；revoke／regenerate；QR／deep link／手動輸入；audit。

| Criterion | Score | Evidence / reason |
|-----------|------:|-------------------|
| Security / privacy | 5 | 對齊 Canvas/ClassDojo/PowerSchool/Schoology；不靠手機猜測（Benchmark §2） |
| Ops executability | 4 | 學生頁一鍵產生；現場可口頭／紙本／LINE 傳碼；無需 SMS 供應商 |
| Parent UX | 4 | 家長先有 LINE identity，再輸入碼——與 ClassDojo「先帳號再 code」同構 |
| Compat / migration | 4 | 可與 legacy 並行；需新表 |
| Cost / ops | 5 | 無 per-SMS 成本 |
| **Weighted** | **4.40** | 1.5+1.0+0.8+0.6+0.5 |

### Option C — 家長申請 → 主任審核

Pending BindingRequest；不立即揭露學生；approve/reject/expire；Action Inbox；dedupe。

| Criterion | Score | Evidence / reason |
|-----------|------:|-------------------|
| Security / privacy | 5 | 人審；對齊 ClassDojo request / Google invite 精神 |
| Ops executability | 2 | 每件綁定都進人工；四校區尖峰不可擴 |
| Parent UX | 2 | 等待；補習班家長期望即時 |
| Compat / migration | 4 | 可並行 |
| Cost / ops | 4 | 無 SMS，但人力成本高 |
| **Weighted** | **3.40** | 1.5+0.5+0.4+0.6+0.4 |

### Option D — 手機 OTP

對系統已存手機發 OTP；expiry／attempt limit。

| Criterion | Score | Evidence / reason |
|-----------|------:|-------------------|
| Security / privacy | 4 | 證明持碼者持有該號；但缺號／錯號時失效；enumeration 若回報「已發送」需小心 |
| Ops executability | 2 | 核心痛點正是缺／錯 parent_phone；OTP 無法自舉 |
| Parent UX | 4 | 有正確手機時順；否則需 fallback |
| Compat / migration | 3 | 依賴資料品質；要 SMS provider |
| Cost / ops | 2 | 台灣簡訊成本＋deliverability＋客服 |
| **Weighted** | **3.15** | 1.2+0.5+0.8+0.45+0.2 |

### Option H — Hybrid（推薦）

**Primary B** + **Fallback C** + **Legacy A 降級並設 sunset**。

| Criterion | Score | Evidence / reason |
|-----------|------:|-------------------|
| Security / privacy | 5 | Credential + approval + legacy 降權 |
| Ops executability | 4 | 多數走碼；少數進 Inbox；Phase 0–1 先補資料可觀測 |
| Parent UX | 4 | 有碼即綁；無碼可申請或臨櫃拿碼 |
| Compat / migration | 5 | 分階段；不一次砍成功路徑 |
| Cost / ops | 4 | 無強制 SMS；Inbox 僅高信號 |
| **Weighted** | **4.45** | 1.5+1.0+0.8+0.75+0.4 |

---

## Decision（推薦，待批准）

**採用 Option H（Hybrid）**：

1. **Primary**：Campus-scoped Parent Pairing Credential（短碼 + 安全連結／QR）。  
2. **Fallback**：BindingRequest → Director approve（Action Inbox）。  
3. **Legacy**：姓名＋手機保留為 rate-limited fallback；統一安全文案；ambiguous fail-closed；缺資料建內部任務；**有明確 sunset**。  
4. **資料模型**：漸進引入 `ParentIdentity` / `GuardianStudentRelationship` / `PairingCredential` / `BindingRequest` / `BindingAttempt`；`StudentLineBinding` 先作 LINE channel projection，不一次大爆炸重寫。  
5. **不採用 OTP-first** 作為主路徑（可列為 Phase 3+ optional step-up，非本期）。

### 為何 Hybrid 勝過純 B

純 B 在「家長當下拿不到碼」時會死路；ClassDojo 證明需要 request fallback。Hybrid 用 C 補洞，但預設不讓 C 成為主流量（靠 SLA／Inbox 信號控制）。

### 為何勝過 OTP-first

Benchmark 顯示教育家庭入口主流是 school-issued credential。AllTrue 缺手機資料比例未知但營運上已是痛點；OTP 無法在 `CONTACT_PHONE_MISSING` 時運作，反而強化對錯誤資料的依賴。

---

## Rejected alternatives

| Alternative | Why rejected |
|-------------|--------------|
| Docs-only safe copy（只改文案） | 不合格條件；不解決錯綁／營運／orphan |
| Replace ParentSession with Keycloak/authentik | 雙 IdP；過重；LINE 已是家長主身份通道 |
| OpenFGA as runtime authZ | 概念可借；本期用應用層關係表即可 |
| Immediate kill of name+phone | 破壞現有成功路徑；無 migration 證據 |
| Second identity truth beside LINE/ParentSession without mapping | 製造雙真相（違反品質門檻） |

---

## Consequences

### Positive

- 對齊教育業界 credential 模式；降低 LINE 對話手機暴露。  
- 主任獲得可執行工作流（發碼、補資料、審核）。  
- 安全與 UX 文案分離（reason code）。  
- 可分階段 rollout、可回滾。

### Negative / costs

- 需新表與 API；主任需學習「發碼」。  
- 家長多一步「向分校拿碼」（可用現場慣例消化）。  
- Legacy 並行期間複雜度上升（用 feature flag 管理）。

### Neutral

- `StudentLineBinding` 短期保留為 LINE 推播投影。  
- OTP 可於未來作為 optional step-up，不阻塞本期。

---

## Migration strategy（摘要）

詳見 [`PARENT_BINDING_ROLLOUT.md`](../operations/PARENT_BINDING_ROLLOUT.md)。

| Phase | Intent |
|-------|--------|
| 0 | Observability / 資料品質（不改成功路徑） |
| 1 | Safe UX + Director completeness + Inbox high-signal |
| 2 | Pairing + Manual approval + relationship model |
| 3 | Legacy sunset + binding migration + revoke/recovery 完備 |

---

## Founder decisions required

1. Pairing code **max_uses** 預設（建議 2；上限 4，對齊 ClassDojo 精神但不硬抄）。  
2. Default **TTL**（建議 72h；可配 24h–7d）。  
3. Legacy name+phone **sunset 日期**或成功綁定占比門檻（建議：pairing 佔比 >70% 且連續 30 天後進入 Phase 3）。  
4. 學生 `graduated`/`inactive` 後家長存取政策（deny vs read-only）。  
5. 是否允許同一 LINE 跨校區多孩子（現行允許；建議維持但需明示 campus）。  
6. BindingRequest 是否對家長開放自助，或僅臨櫃／電話由主任代建。  
7. 是否投資 SMS OTP 作為未來 step-up（建議：**本期不做**）。

---

## Validation

- [ ] Founder 批准本 ADR Status → Accepted  
- [ ] Implementation issues 依 Phase 開立（見 `docs/product/parent-binding-implementation-issues/`）  
- [ ] 非本輪：不寫 production code / migration / deploy  
