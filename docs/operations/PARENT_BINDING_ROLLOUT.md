# Parent Binding Rollout & Migration

| Field | Value |
|-------|-------|
| Status | **ADR Accepted**（Founder 2026-07-26）— rollout plan only; **no production code this round** |
| Date | 2026-07-26 |
| Related | ADR · Target Architecture · Threat Model |
| OTP | **Phase 0–2 不含 OTP**；僅未來選項 |

> 成功標準是 KPI（§5），不是「功能已上線」。

---

## 1. Principles

1. **Expand-contract**：先雙寫／觀測，再切讀路徑，最後 sunset legacy。  
2. 每階段 **feature flag** + **rollback** + **production verification**。  
3. 不在 Pi 上跑測試；CI → PR → merge → `deploy.yml`。  
4. Migration 僅在批准實作後於 feature PR 進行；本輪 docs-only。  
5. 不混入排課／扣堂／billing／leave。

---

## 2. Phase 0 — Observability & data quality

**Goal**：不改家長成功路徑；看清楚失敗原因與缺資料規模。

| Item | Spec |
|------|------|
| Feature flag | `parent_binding_observability=true` |
| DB | Optional `binding_attempts` 表（append-only）或先 structured log |
| Code changes（未來） | LINE/Portal failure 寫 `reason_code`；mask phone；**不改**成功 bind |
| Backfill | Report SQL：缺 `parent_phone` 且 `Phone` 空；verified binding 數 |
| Rollback | 關 flag；停寫 attempts |
| Prod verification | 抽樣 log 無完整手機；reason_code 有值 |
| Metrics | failure by reason；missing contact count；legacy success rate |
| Exit criteria | ≥7 天 baseline；Founder 看過缺資料占比 |
| Support playbook | 主任仍用舊方式協助；內部可查 reason |

**KPI baseline 必採：** 綁定成功率、`CONTACT_PHONE_MISSING` 占比、ambiguous 次數。

---

## 3. Phase 1 — Safe UX + Director completeness

**Goal**：降低誤導與 enumeration；給主任補資料工具；**不改**既有成功綁定路徑行為（成功仍姓名+手機）。

| Item | Spec |
|------|------|
| Feature flag | `parent_binding_safe_copy`、`parent_binding_completeness_ui`、`parent_binding_inbox_v1` |
| DB | 可無新表；或 Inbox case type 擴充 |
| Changes | 統一失敗文案；Portal 移除 empty-phone 存在性洩漏；StudentsList 篩選缺資料；Import/Wizard 寫入 `parent_phone`；高信號 Inbox |
| Backfill | 無 |
| Rollback | 關 flags；文案回舊（git revert） |
| Prod verification | 手動：錯綁指令 → 安全文案；主任篩選可見缺手機學生 |
| Metrics | support contact rate；inbox create/resolve；safe_copy exposure |
| Exit criteria | 無家長投訴「文案說沒此學生但其實缺手機」類工單連續 14 天下降；completeness UI 被使用 |
| Support playbook | 櫃檯：先查缺手機 → 補資料 → 請家長再綁；記錄不貼完整手機到群 |

**Non-goals：** 不上 pairing；不刪 legacy。

---

## 4. Phase 2 — Pairing Code + Manual Approval + Relationship model

**Goal**：Primary credential 流程上線；legacy 降為 fallback。

| Item | Spec |
|------|------|
| Feature flag | `parent_binding_pairing`、`parent_binding_requests`、`parent_binding_legacy_bind`（default on） |
| DB migrations | `parent_identities`；`guardian_student_relationships`；`pairing_credentials`；`binding_requests`；（`binding_attempts` if not in P0） |
| Backfill | Verified `student_line_bindings` → ParentIdentity + active Relationship；SLB 保留 |
| Parent flow | LINE/LIFF consume code；optional request |
| Staff flow | issue/revoke/QR；approve/reject |
| Rollback | 關 pairing/requests flags；legacy 仍可用；新表保留無傷害 |
| Prod verification | E2E：發碼→consume→portal→撤銷→不可見；跨校拒絕；concurrent consume test in CI |
| Metrics | pairing success rate；time-to-bind；request age；code expired unused；duplicate prevented |
| Exit criteria | pairing 佔新綁定 ≥50%（或 Founder 門檻）；無 P0 錯綁；revoke 使 session 失效 |
| Support playbook | 標準改為發碼；legacy 僅當家長無法使用碼時 |

---

## 5. Phase 3 — Legacy sunset（KPI gate；無自動硬日期）

**Goal**：姓名+手機不再是預設；關係與撤銷完備。

### Sunset gate（須**同時**符合才可向 Founder 提案）

1. pairing code + BindingRequest 占新綁定 **≥ 80%**  
2. 連續 **30 天**  
3. legacy 相關客服／人工補救率 **< 10%**  
4. 無未解決 **P0／P1** identity、PII、跨校區事件  
5. **Evidence**：revoke → ParentSession 立即失效；migration rollback 演練通過  
6. **Founder 再次明確批准**  
7. **不**預設自動生效日期  

| Item | Spec |
|------|------|
| Feature flag | `parent_binding_legacy_bind=false`（可 per-campus；僅 gate 通過後） |
| DB | 可加 check：legacy attempts only via staff override |
| Migration | 確認所有 active SLB 有 relationship；orphan cleanup job |
| Rollback | 重新開啟 legacy flag（緊急）；rollback runbook 須先於提案前驗證 |
| Prod verification | legacy 指令回「請使用綁定碼」；既有 relationship 不受影響；session invalidation 抽樣 |
| Metrics | pairing+request share；legacy support rate；unattributable failures |
| Exit criteria | KPI gate + Founder re-approval；文件與 LineIntegration UI 更新 |
| Support playbook | 僅人工臨櫃驗證後由主任代 approve request 或發碼 |

---

## 6. Feature flag matrix

| Flag | P0 | P1 | P2 | P3 |
|------|----|----|----|-----|
| observability | on | on | on | on |
| safe_copy | off | on | on | on |
| completeness_ui | off | on | on | on |
| inbox_v1 | off | on | on | on |
| pairing | off | off | on | on |
| requests | off | off | on | on |
| legacy_bind | on | on | on | **off** |

---

## 7. Production verification checklist（每階段）

```bash
# After deployable merge only
curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool
# Frontend changes: confirm version.json timestamp
# Smoke: director login → student page → (phase-appropriate) actions
# Smoke: parent LIFF / LINE bind path
```

**禁止**在未過 CI、未 merge 時宣告完成。

---

## 8. Support playbook（跨階段）

| 家長狀況 | 主任動作 |
|----------|----------|
| 綁定失敗 | 先看學生是否缺家長手機／是否有碼 |
| 沒有碼 | 學生頁產生碼，私訊家長 |
| 碼過期 | 重發 |
| 前配偶仍看得到 | 撤銷該 relationship；確認 session 失效 |
| 轉校 | 撤銷舊校 relationship；新校發碼 |
| 懷疑被猜綁 | 撤銷＋重發；提高 TTL 縮短；查 attempts |

---

## 9. KPIs（決策指標）

| KPI | Definition | Direction |
|-----|------------|-----------|
| Bind success rate | successes / attempts | ↑ |
| First-try success | success on first attempt | ↑ |
| Time-to-bind | identity ready → relationship active | ↓ |
| Missing contact phone students | active students w/ empty contact | ↓ |
| Pending request age (p50/p95) | hours | ↓ |
| Director manual handle volume | inbox resolves / week | 先↑後↓ |
| Wrong-bind / revoke count | revokes after mistaken activate | ↓ |
| Ambiguous match count | `AMBIGUOUS_MATCH` | ↓（fail closed） |
| Rate-limited attempts | count | 監控 |
| Code expired before use | expired unused / issued | ↓ |
| Support contact rate | tickets mentioning 綁定 | ↓ |
| Cross-campus denial | expected denials | 監控 |
| Duplicate relationship prevented | unique conflicts | 監控 |
| Unattributable failures | attempts w/o reason_code | ↓ → 0 |

**不是 KPI：** PR merged、deploy green alone、文件頁數。

---

## 10. Risk register（rollout）

| Risk | Mitigation |
|------|------------|
| 主任不發碼 | Phase 1 培訓 + 預設 TTL 簡單；臨櫃劇本 |
| Legacy 並行複雜 | Flags + 明確 exit |
| Migration 漏 backfill | CI 稽核 job：verified SLB without relationship |
| Inbox 噪音 | 嚴格 dedupe／cooldown |
| SMS 誘惑偏離 ADR | OTP 不進本期 scope |
