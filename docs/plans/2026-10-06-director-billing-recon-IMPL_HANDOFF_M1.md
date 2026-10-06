# Implementation handoff — 主任學生帳務對帳 IA · **M1 only**

**Status:** Ready for Claude Code / Codex / Cursor worker.  
**Planner:** Cursor cloud agent (docs contract only).  
**Do not** start M2–M4 in the same change set.

---

## Handoff block

```
## Handoff
- Date/UTC: 2026-10-06
- Repo: AllTrue
- Branch (contract): cursor/director-billing-recon-ia-1662 @ 7875ab7e
- Contract PR: https://github.com/jerry200176-png/AllTrue_System/pull/3641
- Related: TD-081；不綁特定 in-app bug（產品 IA）
- Done: M0 PRD + INDEX + CHANGELOG + TD-081 連結
- Not done: M1 程式（錢↔課完整上課日）；M2 入口收斂；M3 模式條；M4 文案
- Next: 本檔 Authorized work → RED tests → GREEN → PR（新 branch）
- Campaign check: 非排課主線；billing IA 並行允許（NORTH_STAR）
- Risks / do-not-touch: 見 Forbidden
- Capability: 禁 Pi SSH／禁 artisan test on prod／禁改催繳列入條件
```

---

## Bindings（單一真相）

| Item | Value |
|------|--------|
| Product contract | [`docs/plans/2026-10-06-director-student-billing-reconciliation-ia.md`](2026-10-06-director-student-billing-reconciliation-ia.md) |
| This handoff | 本檔（M1 唯一授權範圍） |
| Money state machine | [`docs/architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md`](../architecture/RFC_REPORTED_PAID_ACCOUNTING_SPLIT.md) — **勿改** |
| Alert inclusion | [`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`](../DIRECTOR_PAYMENT_ALERT_RULES.md) — **勿改** |
| 「對帳」正名 | [`docs/GUIDE_NIGHTLY_SESSION_RECONCILE.md`](../GUIDE_NIGHTLY_SESSION_RECONCILE.md) · TD-081 |
| UI copy | [`docs/GUIDE_UI_COPY.md`](../GUIDE_UI_COPY.md) |
| Entry / surfaces today | `TuitionCollectionPage.vue` · `AccountingLedgerModal.vue` · `AccountingController::ledger` · slip/receipt session lists |

---

## Skills to load（開工前）

依 [`docs/GUIDE_AGENT_SKILLS.md`](../GUIDE_AGENT_SKILLS.md)：只挑、不整包。

| 順序 | Skill / rule | 用途 |
|------|----------------|------|
| 1 | `AGENTS.md` + `docs/INDEX.md`（本計畫列） | First-read |
| 2 | `.cursor/skills/alltrue-testing/SKILL.md` | RED→GREEN；禁 Pi 測 |
| 3 | `.cursor/skills/alltrue-code-review/SKILL.md` | merge 前對 FR |
| 4 | `.cursor/rules/module-test.mdc` | Factory／NOT NULL |
| 5 | （原則）addyosmani `test-driven-development` + `incremental-implementation` | 垂直切片；**不要** `npx skills add` 整包 |

可選：`.cursor/skills/alltrue-security/SKILL.md`（若動 API 回傳欄位／校區隔離）。

---

## Authorized work（M1）

**Outcome：** 主任在**學生帳務檔**（現有 `AccountingLedgerModal`／其演進）展開某張帳單或某筆收款時，看到**完整涵蓋上課日**，不是只有第一堂。

1. **讀型契約**：帳單／收款帶「涵蓋上課日」清單（或同 response 可組出）。  
   - **月結**：該帳單服務期間內有效堂次（與繳費單 `slipSessionDetailsForPeriod` 語意對齊）。  
   - **堂數制**：本合約購買範圍內已上＋預計（與現行繳費單／收據涵蓋語意對齊）。  
2. **UI**：學生帳務檔帳單／收款列可展開日期 chips＋「共 N 堂」；截斷時「尚有 N 堂」。  
3. **列表短標**：佇列／收據列仍可用第一堂／期間當副標（FR-009）；不得取代展開完整日。  
4. **測試**：月結＋堂數制各至少一則；校區隔離；涵蓋日失敗時金額仍可見（NFR-005）。

Risk：T2。可 Agent 完成 CI＋review 後依 `RISK_BASED_MERGE_POLICY` merge；**不**改 billing 寫入語意 → 非 T3。

---

## Forbidden in this unit

- M2 入口收斂／刪大量 CTA（下一張 PR）
- M3 模式說明條大改版
- M4 全站「對帳」字樣清扫
- 改 `DIRECTOR_PAYMENT_ALERT_RULES` 列入條件
- 改 reported≠booked 狀態機／`Paid=1` 寫入時機
- 新建第二個「對帳中心」或第三套日期算法（必須複用 slip／receipt 規則）
- migration／schema cutover、production data repair
- Pi SSH、`php artisan test` on `/home/admin`
- 整包安裝上游 agent-skills

---

## Suggested first commands（worker）

```bash
agent-start alltrue director-billing-m1
# 或雲端：從 origin/main 開 cursor/director-billing-recon-m1-<id>

# 必讀（短）
# 1) 本 handoff
# 2) PRD §5 US-2、§6 FR-003/009、§8 技術方向
# 3) AccountingController::ledger + AccountingLedgerModal.vue
# 4) MonthlyBillingService slip sessions + PaymentReport receipt session_dates

# 然後：先寫失敗測試（涵蓋日缺失）→ 再補 API／UI
```

---

## Paste-ready kickoff（丟給 Claude Code / Codex）

```text
你是 AllTrue worker。只做 M1：學生帳務檔「錢↔完整上課日」。

必讀：
- docs/plans/2026-10-06-director-billing-recon-IMPL_HANDOFF_M1.md
- docs/plans/2026-10-06-director-student-billing-reconciliation-ia.md（US-2、FR-003/009）
- .cursor/skills/alltrue-testing/SKILL.md

規則：
- RED→GREEN；禁 Pi 測；禁改催繳列入條件與收款狀態機
- 涵蓋上課日語意必須與繳費單／收據同源，禁止第三套算法
- 本 PR 不做 M2–M4

完成定義：
- 月結＋堂數制展開可見完整日期；有自動化測試；CI 綠；更新 CHANGELOG 一行
- PR 描述引用本 handoff 與 contract PR #3641
```

---

## Rollback

Revert 實作 PR。無 migration 則無 DB 回滾。

## Worker note

Cursor／Claude Code／Codex 可替換。綁定本 handoff 範圍與 contract 指紋；GitHub 討論不是第二份規格。規格衝突時以 PRD＋本檔 **Forbidden** 為準。
