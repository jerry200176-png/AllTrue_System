## Summary
<!-- 一句話說明這個 PR 做了什麼，以及為什麼 -->

## Risk-Class (required — see `docs/governance/RISK_BASED_MERGE_POLICY.md`)
<!-- Pick the HIGHEST class that applies. When unsure, choose higher. -->
- [ ] **R0** — docs / generated evidence / no production behavior
- [ ] **R1** — low-risk reversible code (isolated fix, display); tests + rollback
- [ ] **R2** — domain/billing/auth/cron/deploy/migration/cross-campus — independent review + rollback
- [ ] **R3** — data repair / destructive / privilege / financial / security boundary — protected Founder gate

**Risk-Class:** R?  
**Autonomy-Tier:** T? <!-- R0/T0, R1/T1, R2/T2, R3/T3 -->
**Review / protected-boundary evidence：** <!-- T2: independent review context; T3: Founder decision packet and exact boundary; otherwise N/A -->
**Rollback:** <!-- revert SHA / prior deploy / repair --rollback / n/a -->

> **Review evidence（global approvals remain 0）**：T2/R2 必須有 current-head independent review context。可接受 distinct authorized GitHub identity 的 `APPROVED` review，或現有 Cursor Bugbot GitHub App 對 exact HEAD 的 completed `success` check（slug `cursor`、App ID `1210556`）；缺少、過期、`neutral`、失敗或未驗證的 verifier evidence 一律 fail closed。一般自動 checks、高風險測試與 self-review checklist 只能補強，不能冒充 independent review，也不要求 Founder 建立第二個 GitHub identity。
> **T0/T1**：required checks 與本 checklist 據實填寫後，Agent 可依風險政策 autonomous merge。
> **T2/R2**：除 required CI、rollback / production-verification evidence 外，必須有上述任一 independent review evidence；無 protected Founder decision 時不需 Founder rubber stamp。
> **T3/R3**：可準備實作與 evidence package；若 merge 仍會隱含 production activation，先由 `merged-awaiting-activation` separation 解耦；Founder GO 仍只在 protected action 前需要，不得以 review 取代該 gate。

## 關聯 Issue（Refs / Closes 規則）
<!-- 多階段、Epic、仍有一截沒做完 → 只填 Refs，不要寫 Closes，避免 GitHub 整張 issue 被關掉 -->
- **Refs**：`Refs #123`（本 PR 只完成其中一部分、後續還有 Phase 2 / follow-up）
- **Closes**：`Closes #123`（本 PR 完成該 issue 全部驗收範圍時才可填；**一整張 issue 含多 Phase 時，請等最後一個 PR 再寫 Closes**）

> 不確定就一律 **Refs**，merge 後在 issue 手動勾進度。

## Type
- [ ] feat — 新功能
- [ ] fix — Bug 修復
- [ ] chore — 文件 / 設定 / 維護
- [ ] td — 技術債清償

## Test Plan
<!-- 列出驗收步驟；PHPUnit / 前端測試由 CI 跑，有手動場景再寫 -->
- [ ] 

> **Golden**：無需人工勾選。Presubmit **CHECK 6** 與 CI job **Golden scenarios report** 會依 diff 對應 §0–§4；見 [`docs/QA_GOLDEN_SCENARIOS.md`](../docs/QA_GOLDEN_SCENARIOS.md)。

## Migration Compatibility（有 DB migration 時必填）
<!-- 見 docs/RULE_MIGRATION_COMPAT.md -->
- **Phase**: Expand / Backfill / Contract / Simple Add / N/A
- **Reversibility**: `down()` 可逆 ✅ / 不可逆（原因：___）
- **Big table risk**: 表名 + 預估 row 數（> 10K 需評估）
- **Rollback impact**: code rollback 後舊 schema 是否仍可運行？


## Checklist
- [ ] 已 push feature branch；**merge 前** CI / Presubmit / Security 需全綠（由負責人跟到 completed）
- [ ] Risk-Class 已宣告且與實際 diff 一致
- [ ] 有改 `backend/app/`、`backend/routes/`、`frontend/src/` → 已更新 `docs/CHANGELOG.md`（docs-only / 純 workflow 可略，見團隊慣例）
- [ ] 有 DB migration → 併 PR 說明上線後由 `deploy.yml` migrate；不在 production 手動試跑 full test
- [ ] 有前端 deployable diff → merge 後確認 `deploy.yml` 成功，必要時驗 `version.json` / health
- [ ] 未擅自改 `AlertController::tuition` / `SessionDeductionService` 等高風險邏輯（若有改必須有測試 + 審核）
- [ ] **多校區隔離**：新 query / endpoint 都帶 `CampusID` / `branch_id`；跨校 join 經 `require_campus` 或 `resolveCampusIds`（Execution #482）
- [ ] 未 `git push --force`、未直推 `main`

## Threat Note（T2/T3 必填；T0/T1 可略）
<!-- 對齊 .cursor/rules/module-security.mdc / Execution #480。若改 auth / PII / RFID / LINE webhook / 跨校資料邊界、新增公開端點，務必填寫。 -->
- **資產 (Asset)**：<!-- e.g., StudentClass 堂數、家長手機、LINE userId -->
- **威脅 (Threat / STRIDE)**：<!-- S/T/R/I/D/E 哪一面，1–2 句 -->
- **緩解 (Mitigation)**：<!-- middleware / rate limit / authz check / audit log / 多校區 filter -->
- **殘餘風險 (Residual)**：<!-- 可接受或要追新 issue -->

> 若本 PR 只是 docs / CSS / 純 refactor 無安全邊界變更，請在此節寫 `N/A — 無 auth/PII/邊界變更`。

## Screenshots（前端有 UI 改動時填）
<!-- 貼 before / after 截圖 -->

## Design System（前端有 UI 改動時填，#737 制度化）
<!-- 參考 docs/RULE_DESIGN_SYSTEM.md（唯一真相）；不確定就對照禁止清單 §7 -->
<!-- CI 自動偵測新增 raw hex：一旦超過 baseline 即 FAIL（不再 advisory） -->
- [ ] **無新增 raw `#hex`**（CI blocking — 違反即 PR 無法 merge；需清理或更新 baseline）
- [ ] 每個區塊 Primary CTA ≤ 1 顆（`docs/RULE_DESIGN_SYSTEM.md §6`）
- [ ] 金額 / 堂數 / 日期已套 `tabular-nums`（`docs/RULE_DESIGN_SYSTEM.md §4 / §6`）
- [ ] 空狀態含圖示 + 一句說明 + 下一步行動（`docs/GUIDE_UI_COPY.md §2`）
- [ ] Loading 狀態：骨架屏或 spinner，非白屏（`RULE_DESIGN_SYSTEM §4`）
- [ ] 未改業務邏輯 / 繳費規則（只動樣式 / 文案 / 空狀態）
