# ADR: Parent–Student Binding Redesign

| Field | Value |
|-------|-------|
| Status | **Accepted** |
| Date | 2026-07-26 |
| Founder approval date | 2026-07-26 |
| Deciders | Founder（approved）；Principal Product Architect（recommend） |
| Related | Benchmark · Target Architecture · Threat Model · UX Spec · Rollout · PR #1434 |
| Implementation | **Not started** — docs/governance only until Phase issues are scheduled |

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

### Research limitations（不得改寫為已證實）

- 無法確認 ClassDojo／PowerSchool 是否 server-side hash pairing／access credentials。  
- 無法確認 Infinite Campus Activation Key 是否全球一律 single-use。  
- 尚未驗證現場「臨櫃／LINE 發碼」接受度（產品假設，需實作後觀測）。

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

## Options（評分保留供審計）

| Option | Weighted | Notes |
|--------|----------|-------|
| **H Hybrid** | **4.45** | **Accepted** |
| B Pairing Code only | 4.40 | 缺 request fallback |
| A Enhanced name+phone | 3.45 | 安全不足 |
| C Manual approval only | 3.40 | 主任負載不可擴 |
| D SMS OTP-first | 3.15 | 本期不做 |

詳細評分表見本 ADR 初版 Proposed 內容；Accepted 後以 Founder 決策參數為準。

---

## Decision（Accepted — Founder 2026-07-26）

**採用 Option H（Hybrid）**：

1. **Primary**：Campus-scoped Parent Pairing Credential（短碼 + 安全連結／QR）。  
2. **Fallback**：BindingRequest → Director approve（Action Inbox）；**允許家長自助申請**（須已 authenticated LINE identity）。  
3. **Legacy**：姓名＋手機降級為受控 fallback；統一安全文案；ambiguous fail-closed；缺資料建內部任務；**KPI sunset gate**（無自動硬日期）。  
4. **資料模型**：漸進引入 `ParentIdentity` / `GuardianStudentRelationship` / `PairingCredential` / `BindingRequest` / `BindingAttempt`；`StudentLineBinding` 先作 LINE channel projection。  
5. **OTP**：本期不做；僅列為未來 sensitive action step-up／recovery option；**不得**列入 Phase 0–2 dependency。

### Founder-approved parameters

#### PairingCredential

| Parameter | Decision |
|-----------|----------|
| Default `max_uses` | **1**（每位監護人使用**獨立** credential） |
| Active unused cap | 同一 `student + campus` 最多 **4** 組 active、未使用 credentials |
| Staff controls | revoke／regenerate |
| Consume | **必須原子化** |
| Storage | raw token **不落 DB**、不進普通 log（僅 hash） |
| Default TTL | **7 days** |
| Staff-selectable TTL | **24h / 72h / 7d** only |
| Permanent codes | **禁止** |
| Expiry behaviour | 過期後**重新發碼**；不得延長舊 token |

#### Legacy sunset gate（Phase 3 提出條件；須**同時**符合）

1. pairing code + BindingRequest 占新綁定 **≥ 80%**  
2. 連續 **30 天**  
3. legacy 相關客服／人工補救率 **< 10%**  
4. 無未解決 **P0／P1** identity、PII、跨校區事件  
5. revoke、session invalidation、migration rollback **已驗證**  
6. **Founder 再次明確批准**  
7. **不**設定現在就自動生效的硬日期  

#### Student status → relationship policy

| Student status | Relationship / access |
|----------------|----------------------|
| `paused` | 維持正常 relationship |
| `graduated` / `inactive` | **read-only 365 days** |
| After 365 days | relationship → **`suspended`** |
| Staff | 可人工延長 read-only／解除 suspended（須 audit） |
| Audit / 法定帳務 | 紀錄保留 |
| Relationship **revoked** | 現有 **ParentSession 必須立即失效** |

#### Cross-campus & multi-child

- 同一 `ParentIdentity` **可**跨校區、多子女。  
- 每條 `GuardianStudentRelationship` **必須 campus-scoped**。  
- UI **明示 student + campus**。  
- **不得**因相同手機自動合併學生。  
- `campus_id`／URL **不得**突破 relationship authorization。  

#### BindingRequest

- 允許家長自助申請。  
- 必須先有 authenticated LINE identity。  
- Safe generic external response；不透露學生是否存在。  
- Rate limit + dedupe。  
- Staff 端僅 masked evidence。  
- Staff 也可代建。  

---

## Rejected alternatives

| Alternative | Why rejected |
|-------------|--------------|
| Docs-only safe copy（只改文案） | 不合格條件；不解決錯綁／營運／orphan |
| Replace ParentSession with Keycloak/authentik | 雙 IdP；過重；LINE 已是家長主身份通道 |
| OpenFGA as runtime authZ | 概念可借；本期用應用層關係表即可 |
| Immediate kill of name+phone | 破壞現有成功路徑；無 migration 證據 |
| Second identity truth beside LINE/ParentSession without mapping | 製造雙真相 |
| Shared multi-use pairing code as default | **Rejected** — Founder：default `max_uses=1`；多監護人各自發碼 |
| OTP-first / OTP in Phase 0–2 | Founder：本期不做 |

---

## Consequences

### Positive

- 對齊教育業界 credential 模式；降低 LINE 對話手機暴露。  
- 主任獲得可執行工作流（發碼、補資料、審核）。  
- 安全與 UX 文案分離（reason code）。  
- 可分階段 rollout、可回滾。

### Negative / costs

- 需新表與 API；主任需學習「發碼」（含 TTL 選項與每監護人一碼）。  
- 家長多一步「向分校拿碼」或自助申請。  
- Legacy 並行期間複雜度上升（用 feature flag 管理）。

### Neutral

- `StudentLineBinding` 短期保留為 LINE 推播投影。  
- OTP 僅未來選項，不阻塞 Phase 0–2。

---

## Migration strategy（摘要）

詳見 [`PARENT_BINDING_ROLLOUT.md`](../operations/PARENT_BINDING_ROLLOUT.md)。

| Phase | Intent |
|-------|--------|
| 0 | Observability / 資料品質（不改成功路徑） |
| 1 | Safe UX + Director completeness + Inbox high-signal |
| 2 | Pairing + Manual approval + relationship model |
| 3 | Legacy sunset（KPI gate + Founder re-approval；無硬日期） |

---

## Validation

- [x] Founder 批准本 ADR Status → **Accepted**（2026-07-26）  
- [x] Founder decisions 已寫入本 ADR 與姊妹文件  
- [ ] Implementation issues 依 Phase 開立為 GitHub Issues（closeout 任務執行）  
- [x] 本輪仍不寫 production code / migration / deploy；**implementation 尚未開始**  
