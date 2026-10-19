# Plan: confusing billing amount displays (semantics + IA)

| Field | Value |
|---|---|
| Status | **Plan only** — no production / billing code / deploy / activation |
| Date | 2026-10-19 |
| Risk | T0 docs now; future UI copy work = T1–T2 (display-only). Money math / invoice rewrite stays T3 / billing-owner |
| Owner (plan) | This session (non-billing retained) |
| Owner (implementation) | **Billing closeout session** for money paths; this session may land **display-only** copy after plan GO — **no duplicate money logic** |
| Coordination | Handoff to billing closeout `bc-01a1549a-4b74-7985-95f8-2ac405420cfb`; do not compete with Xinzhuang fee/schema work |
| Holds | Live pin `ad9e3690`; train `39333337571` DO NOT APPROVE; Local Cursor sole case writer; #394 HOLD |
| Related | in-app #414 / GH #4155 (contract vs bill); F7 / G-009; `DIRECTOR_PAYMENT_ALERT_RULES.md`; recon IA `docs/plans/2026-10-06-director-student-billing-reconciliation-ia.md` §0.2–0.5 |

---

## 1. Problem

Directors see several money numbers that look interchangeable but are not:

- Course management 「應繳」next to a **contract Charge estimate**
- Contract card 「合約金額」vs 「本合約帳單 應收／已收／未收」
- Ledger columns 「應收／已記入／未結清」
- Tuition queue 「應繳／已繳／未結清」and payable_* fields
- Proposed shorthand **課程總額／已繳／尚欠** + invoice **本期應繳** — *not* safe as universal labels

Jerry request: explain current semantics + sources; keep **same-contract / same-period** scope explicit; assess proposed labels; cover edge cases; cite mature OSS; deliver before/after (synthetic), SoT rules, tests, phases, rollback. **Do not hide mismatches or silently recompute historical invoices.**

---

## 2. Current semantics (authoritative code)

### 2.1 Four amounts (never collapse without a scope label)

| Concept | Source of truth | Code | Scope |
|---|---|---|---|
| **Contract agreed total** | `StudentClass.Charge` (current agreed course fee) | Course list / `current_contract_charge` / ContractCard 「合約金額」(#4155) | **One StudentClass** |
| **Invoice receivable (應收)** | Prefer projected `total_amount` from `InvoiceAmountReconciliationService::resolve` | Ledger / ContractCard bill aggregate / CourseManagement billing 「應繳」when invoice exists | **One Invoice**, or sum of **non-void invoices on same StudentClass** |
| **Applied / paid (已記入／已收)** | Net `Payment` on that invoice: positives − void/reversal abs | `net_applied` / `calculated_applied_amount` / `applied_amount` | **Same invoice** (or same-contract sum of non-void) |
| **Outstanding (未結清／未收)** | `max(0, total_amount − net_applied)`; excess → `overpaid_amount` | Same resolver; course verdict via `BillingPayableResolver` | Must state: **this invoice** vs **this contract** vs **student due-today** (recon D8) |

Persisted audit value on the invoice row remains `Invoice.TotalAmount` (`stored_total_amount`). Projection may **display** a different `total_amount` only when `repriceable` (unpaid monthly, nothing applied, billable-sessions policy) — see §2.3. **Paid / partial invoices keep stored totals** until an approved accounting repair.

### 2.2 Same-contract vs same-period

| Scope | Meaning | Where used today |
|---|---|---|
| **Same contract** | All non-void `Invoice.StudentClassID = course.ID` | ContractCard `amounts` (應收／已收／未收); ledger filter for the focused course |
| **Same period** | One `billing_period` (YYYY-MM) or item `PeriodStart`–`PeriodEnd` on **one** invoice | ContractCard monthly line 「應繳 …　已收 …」; ledger period subtitle; monthly drafts |
| **Student / campus** | Sum across contracts (due-today vs later per recon D8) | TuitionCollection summary; StudentBillingList |

**Rule:** never present a single 「尚欠」without saying which of the three scopes it is.

### 2.3 Projection vs historical (do not hide)

`InvoiceAmountReconciliationService` (header comment + `resolve()`):

- Unpaid + zero applied + monthly `date` + billable_sessions → may set display `total_amount = computed` and `amount_source = billable_sessions`.
- If `amount_discrepancy` and **not** repriceable (already paid/partial, cross-month cycle, etc.) → keep **stored** amount; UI must still surface 「開單時金額／目前課程金額」via `issuedBillRateNote` / ContractCard `#4115` note — **neutral**, no auto-void, no silent rewrite (#4155 / Founder D4).

Anomalies already named in ledger: `paid_amount_mismatch`, `paid_status_with_balance`, `open_status_without_balance`.

### 2.4 Surface map (today)

| Surface | Labels today | Binds to |
|---|---|---|
| CourseManagement billing column | 「應繳 $invoice.total」or 「應繳：待開單」+ optional 「估算 $Charge」 | Current invoice **or** estimate; **not** full contract settlement |
| ContractCard | 「合約金額」+ 「本合約帳單 應收／已收／未收」+ optional rate note | Charge + same-contract invoice aggregate (#4155) |
| AccountingLedgerModal | 「應收／已記入／未結清」per invoice | Resolver per invoice |
| TuitionCollection | 「應繳／已繳／未結清」; payable_* for queue action | Payable invoice for **action**; CSV mixes concepts — high confusion risk |
| PaymentEntryModal | 「帳單應繳」vs 「應繳」 | `payable_amount` when invoiced |
| StudentBillingList | 「未繳／已繳／金額待確認」per contract | Alert-derived owed/paid |

### 2.5 Assessment of proposed labels

| Proposed | Fit? | Verdict |
|---|---|---|
| **課程總額** | Maps to `Charge`, not invoice sum | OK **only** with qualifier 「目前合約約定」; **wrong** if read as 「帳單合計」or installments total |
| **已繳** | Ambiguous: flag `Paid`, report confirmed, or net Payment | Prefer 「已收／已記入」tied to **receipts**; never invent from legacy flag alone (#4114) |
| **尚欠** | Ambiguous scope | Prefer 「未收（本合約帳單）」or 「到今天未繳（學生）」per recon D8 — never one bare 「尚欠」 |
| **本期應繳** | Only monthly / period invoice | OK on **period lines**; **wrong** as the only number on count-mode single invoice or multi-contract student header |

**Recommendation:** do **not** replace #4155 wording with bare 課程總額／已繳／尚欠. Prefer scoped Chinese already shipping on ContractCard + ledger; fix **CourseManagement** and **TuitionCollection** where 「應繳」still collapses estimate vs bill.

---

## 3. Before / after (synthetic only — no private financial payloads)

IDs and amounts are **lab fixtures** (same shape as `CourseAmountBasis.test.js`).

### Scenario A — single count invoice, partial pay

| | Before (confused read) | After (scoped) |
|---|---|---|
| Contract | One number 「應繳 8800」next to paid-looking status | 合約金額 8,800　本合約帳單 應收 8,800・已收 8,000・未收 800 |
| Invoice detail | 「本期應繳」on a non-monthly bill | Row: 應收 8,800｜已記入 8,000｜未結清 800（無「本期」） |

### Scenario B — installments (2 active invoices + 1 void)

| | Before | After |
|---|---|---|
| Contract | Sum looks like 17,700 if void included | 應收 8,800・已收 8,000・未收 800（void 9,900 excluded; void row grey 「已作廢」） |

### Scenario C — historical issued ≠ current Charge

| | Before | After |
|---|---|---|
| Course list | 「應繳 8000」+ mental overlap with Charge 8800 | 合約金額 8,800；帳單應收 8,000；中性 note「開單時／目前」— **no rewrite** |

### Scenario D — waived / free / tutoring

| | Before | After |
|---|---|---|
| Status | Green 「已繳」 | 「確認不收」／免費標／「輔導課不用繳費」; outstanding not in campus 未收 |

### Scenario E — multi-contract student

| | Before | After |
|---|---|---|
| Header | One 「尚欠」 | 到今天未繳 $X；之後還會到期 $Y（recon D8）; per-card same-contract lines |

### Scenario F — credit / refund / overpay

| | Before | After |
|---|---|---|
| Outstanding 0 with extra cash | Looks settled | 「未結清 0」+ 「多收 $Z，請找老闆處理」(D19); reversals reduce applied, never rewrite TotalAmount |

---

## 4. Source-of-truth rules (implementation must obey)

1. **Receipts beat flags.** Legacy `Paid=1` without net Payment → 「需核對」, never 「已收」(#4114).
2. **Invoice stored total is audit.** Display projection only when `repriceable`; never silently persist a recomputed historical amount in a UI-only PR.
3. **Void invoices out of aggregates.** Status `void` excluded from 應收／已收／未收 sums (existing ContractCard tests).
4. **Pending parent report ≠ paid.** Show chip 「待核對入帳」; amounts stay on invoice until confirm.
5. **Mismatch is visible.** If Charge ≠ invoice total, or status vs outstanding disagree, show anomaly / rate note — do not average or hide.
6. **Campus scope** on every read (existing middleware); no cross-campus totals on director UI.
7. **Same algorithm everywhere.** Course settlement for new code → `BillingPayableResolver`; per-invoice math → `InvoiceAmountReconciliationService`. UI must not invent a third formula.

---

## 5. Edge-case matrix

| Case | Display rule |
|---|---|
| Single invoice | Contract aggregate = that invoice |
| Installments / multi invoice | Sum non-void; list each period row |
| Historical issued price | Keep bill; show issued vs current; no auto-reprice |
| Waived / free gift | Explicit waived/free labels; not 「已繳清」via Charge 0 alone |
| Voided receipt / Method=void | Net applied ↓; bill TotalAmount unchanged |
| Multiple contracts | Never sum into one unlabeled 尚欠 |
| Credits / refunds / overpay | overpaid_amount + ops path; not negative 「未收」 |
| Inconsistent status vs math | Surface anomaly codes already in ledger |
| Shared package follower | Point to lead card; don't show actionable 未繳 that can't be paid here |
| Unbilled | 「待開單」+ optional 估算 Charge — label as estimate |

---

## 6. Open-source references (deep enough to cite)

| Project | What we take | Source / tests | License | Limitations vs tutoring |
|---|---|---|---|---|
| **Kill Bill + Kaui** | Explicit split: charged amount vs invoice **balance** = charged (+ credit adj) − (paid + refunded); VOID/DRAFT balance 0 | Docs: [invoice_examples](https://docs.killbill.io/latest/invoice_examples), [invoice API](https://apidocs.killbill.io/invoice); Kaui admin UI | **Apache-2.0** | Subscription/catalog oriented; our 「合約金額」is closer to catalog price, not Kill Bill charged amount |
| **Odoo Accounting (OCA payment widgets)** | Outstanding ≠ amount already reconciled onto *this* invoice; residual / partial application UI; tests around widgets | OCA `account_payment_widget_amount` / residual modules; core discussion in odoo#37620 | **AGPL-3** (OCA) / Odoo EE mixed — **ideas only, no code copy** | Full double-entry; heavier than AllTrue invoice+payment tables |
| **Invoice Ninja v5 schema** | `amount` / `balance` / `paid_to_date` triad on one invoice document | OpenAPI `InvoiceSchema` on `v5-stable` | **Elastic-2.0** (source-available, **not** OSI open source) — cite semantics only | Client-balance model; weaker tutoring multi-contract / period lesson pricing |

**Fit for AllTrue:** keep Kill Bill–style **charged vs balance** language at **invoice** scope, and AllTrue-specific **合約金額** at **contract** scope (#4155 already aligned). Do not import AGPL/Elastic code.

Prior industry UX already in recon IA §0.4 (Jackrabbit / Pike13 / Teachworks) — reuse; no re-research.

---

## 7. Regression / acceptance tests (when implementing)

Reuse / extend (do not replace money services in a copy-only phase):

| Layer | Existing | Add |
|---|---|---|
| Frontend unit | `CourseAmountBasis.test.js`, `ContractCard.test.js` (#4155), `StudentBillingList.test.js` | CourseManagement billing cell: invoiced vs estimate labels; bare 「本期應繳」absent on count-mode |
| Frontend unit | Payment badge / free status tests | Waived/free never labeled 已繳 via Charge alone |
| Backend | `BillingPayableResolver` / invoice reconciliation PHPUnit | Unchanged math; optional assertion that API still returns `stored_total_amount` + `amount_discrepancy` for UI |
| Acceptance | calendar-course-acceptance payments slice (read-only) | Only after display merge: screenshot/copy checks with **synthetic** campus — no private amounts in public logs |

---

## 8. Smallest implementation phases

| Phase | What | Who | Out of scope |
|---|---|---|---|
| **P0** | This plan + billing-owner ack | This session | Code |
| **P1** | Copy-only: CourseManagement + TuitionCollection align to ContractCard/ledger glossary (應收／已收／未收 + 合約金額 + 估算) | Display owner after GO | No resolver changes |
| **P2** | Student header due-today vs later (recon D8) if not already complete | Billing UI owner | No invoice rewrite |
| **P3** | Anomaly surfacing consistency (ledger codes → course list tooltips) | Same | No silent repair |
| **P4** | Optional glossary help drawer (one paragraph) | Docs/UI | — |

Money bugs (wrong TotalAmount, wrong applied) stay on **billing closeout / POP repair** tracks — not this display plan.

---

## 9. Rollback

- P1–P3 are UI strings / layout: `git revert` the display PR; no migration; no invoice rewrite.
- Feature flag only if P2 needs API shape change; default off.
- Never roll forward by recomputing `Invoice.TotalAmount` from Charge.

---

## 10. Coordination / non-goals

- **Billing closeout** owns fee schema, activation, POP apply, case money repair, release readiness.
- **This session** owns plan + optional later **display-only** PRs after explicit ack; will not open competing Charge/Invoice math PRs.
- Local case correction @ pin continues uninterrupted.
- No public issue/PR bodies with private student financial payloads — synthetic only (as above).

---

## 11. Evidence index (repo)

- `backend/app/Services/InvoiceAmountReconciliationService.php`
- `backend/app/Services/BillingPayableResolver.php`
- `frontend/src/components/tuition/ContractCard.vue` (+ #4155 changes fragment)
- `frontend/src/components/AccountingLedgerModal.vue`
- `frontend/src/pages/CourseManagement.vue` (billing 「應繳」/估算)
- `frontend/src/pages/TuitionCollectionPage.vue`
- `frontend/src/components/__tests__/CourseAmountBasis.test.js`
- `docs/plans/2026-10-06-director-student-billing-reconciliation-ia.md` §0
- `docs/AI_REGRESSION_LESSONS.md` F7 / R94–R95 / G-009
