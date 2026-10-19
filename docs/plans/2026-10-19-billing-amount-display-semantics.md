# Plan: confusing billing amount displays (semantics + IA)

| Field | Value |
|---|---|
| Status | **Review draft (plan only)** — Dot: retain plan + synthetics; OSS + fixture table strengthened 2026-10-19. No implement / merge / deploy |
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

## 6. Open-source evidence (two implementations only — research complete for this plan)

Dropped superficial third cites (Invoice Ninja Elastic-2.0; OCA widget modules). Borrow **semantics / UI vocabulary only** — no code import.

### 6.1 Kill Bill — invoice charged vs balance vs paid/refund (primary)

| | |
|---|---|
| **Why relevant** | Same triad AllTrue needs at **invoice** scope: charged (应收) ≠ balance/outstanding (未结清) ≠ paid/refund net (已記入). VOID/DRAFT/written-off force balance 0 without rewriting charged history. |
| **Version pinned** | Latest release tag **`killbill-0.24.22`** (published 2026-09-29). Logic cited from `master` paths that match this line of code (Apache headers on the files below). |
| **License** | **Apache-2.0** — permissive; still **do not vendor** Kill Bill into AllTrue; copy formulas into docs/tests as plain assertions only. |
| **Official docs** | [Invoice Examples — charged amount & invoice balance](https://docs.killbill.io/latest/invoice_examples) (`INVOICE BALANCE = CHARGED + CBA_ADJ − (PAID + REFUNDED)`; DRAFT/VOID/WRITTEN_OFF → balance 0). [Invoice API resource](https://apidocs.killbill.io/invoice) (`amount`, `balance`, `creditAdj`, `refundAdj`). |

**Exact source (behavior borrowed):**

1. **Raw balance formula** — `InvoiceCalculatorUtils.computeRawInvoiceBalance`:  
   `chargedAmount = amountCharged + amountCredited + creditAdjSnowflake`  
   `amountPaidNet = amountPaid(ATTEMPT) + amountRefunded(REFUND|CHARGED_BACK)`  
   `invoiceBalance = chargedAmount − amountPaidNet` then `KillBillMoney.of` (currency rounding).  
   Source: [`invoice/.../InvoiceCalculatorUtils.java` L~108–125](https://github.com/killbill/killbill/blob/master/invoice/src/main/java/org/killbill/billing/invoice/calculator/InvoiceCalculatorUtils.java) (`computeRawInvoiceBalance`, `computeInvoiceAmountPaid`, `computeInvoiceAmountRefunded`).
2. **DAO entry** — migrated invoices return 0; else delegate to calculator:  
   [`InvoiceModelDaoHelper.getRawBalanceForRegularInvoice`](https://github.com/killbill/killbill/blob/master/invoice/src/main/java/org/killbill/billing/invoice/dao/InvoiceModelDaoHelper.java).
3. **Account rollup skips DRAFT/VOID**; written-off → 0 balance while CBA still summed:  
   [`DefaultInvoiceDao.getAccountBalance`](https://github.com/killbill/killbill/blob/master/invoice/src/main/java/org/killbill/billing/invoice/dao/DefaultInvoiceDao.java) (loop: skip DRAFT/VOID; `isWrittenOff ? ZERO : getRawBalance…`).
4. **Payment capped at balance** (no silent over-apply):  
   [`InvoicePaymentControlPluginApi.validateAndComputePaymentAmount`](https://github.com/killbill/killbill/blob/killbill-0.18.1/payment/src/main/java/org/killbill/billing/payment/invoice/InvoicePaymentControlPluginApi.java) (balance ≤ 0 → 0; input > balance → abort/cap). *Cited at 0.18.1 blob for stable line anchor; same guard pattern remains in current payment control.*

**Tests (official):**

- Partial item adjustment updates balance with **`BigDecimal.ROUND_HALF_UP` scale 2**:  
  [`TestInvoice.testPartialInvoiceItemAdjustment`](https://github.com/killbill/killbill/blob/master/profiles/killbill/src/test/java/org/killbill/billing/jaxrs/TestInvoice.java) — asserts `adjustedInvoiceBalance = invoice.getBalance().add(adjustedAmount.negate()).setScale(2, ROUND_HALF_UP)`.
- Docs narrative for recurring pay-to-zero balance: [invoice_examples](https://docs.killbill.io/latest/invoice_examples) (charged 24.95, paid 24.95 → balance 0).

**What we borrow for AllTrue UI**

- Keep **应收 (charged/total)** distinct from **未结清 (balance)** and **已記入 (successful pays − refunds)**.
- Void / non-active invoices **out of outstanding aggregates** (like DRAFT/VOID skip).
- Refunds **increase** outstanding again via net (AllTrue: Method=void / negative Payment → ↑ `outstanding_amount`), without rewriting `Invoice.TotalAmount`.

**NOT transferable**

- Kill Bill **subscription catalog / RECURRING / USAGE / TAX item taxonomy** — AllTrue uses tutoring `StudentClass.Charge` + period lesson projection, not catalog phases.
- **Account-level CBA wallet** (`CBA_ADJ` across invoices) — AllTrue has per-invoice overpay hint + ops path, not a transferable account credit pool UI.
- **`KillBillMoney` / multi-currency rounding** — AllTrue is NTD integer cents in resolvers; do not import BigDecimal money utils.
- **Parent/child hierarchical invoices** — no AllTrue equivalent; ignore.
- **Auto-payment plugins** — out of scope for display plan.

### 6.2 Odoo 18 Accounting — `amount_total` vs `amount_residual` vs `payment_state` (secondary)

| | |
|---|---|
| **Why relevant** | Clear **document total** vs **amount due** vs **payment status** labels on one invoice — matches our ledger columns 应收／未结清／状态 without collapsing into 「已繳」. |
| **Version pinned** | Branch **`18.0`** (LGPL-V3 tree). |
| **License** | **GNU LGPLv3** ([`LICENSE` on 18.0](https://github.com/odoo/odoo/blob/18.0/LICENSE)) — **ideas / field semantics only; no code copy** into AllTrue (LGPL copyleft on linked modules). |

**Exact source (behavior borrowed):**

1. Fields on `account.move`: `amount_total` (Total), `amount_residual` (**Amount Due**), `payment_state` ∈ `{not_paid, in_payment, paid, partial, reversed, …}` —  
   [`addons/account/models/account_move.py` (18.0)](https://github.com/odoo/odoo/blob/18.0/addons/account/models/account_move.py) (`PAYMENT_STATE_SELECTION`, `amount_residual`, `payment_state`, `_compute_amount`).
2. Residual comes from reconciled move lines (`line_ids.amount_residual`); posting + reconciliation drive status — not a single boolean “paid flag”.

**Tests (official):**

- Full reconcile ⇒ residual **0.0** on lines:  
  [`test_account_move_reconcile.py` `assertFullReconcile`](https://github.com/odoo/odoo/blob/18.0/addons/account/tests/test_account_move_reconcile.py) (`amount_residual` / `amount_residual_currency` == 0).
- Zero-amount invoice posts as **`payment_state: paid`** without inventing cash: covered in reconcile test evolution ([commit `33e76acab`](https://github.com/odoo/odoo/commit/33e76acab662cf35bfd512b2bd4c5531f5f07238) `test_invoice_draft_fully_paid_if_zero` — draft → post, `amount_total: 0.0`, `payment_state: paid`).
- `status_in_payment` / draft vs posted matrix:  
  [`test_account_payment.py` `test_payment_state_computation`](https://github.com/odoo/odoo/blob/18.0/addons/account/tests/test_account_payment.py).

**What we borrow**

- UI glossary: **Total ≠ Amount Due ≠ Payment status**.
- Zero obligation can show settled/paid **without** fabricating a receipt (aligns with AllTrue free/waived labels — still prefer explicit 「確認不收／免費」, not green 「已收」from legacy flag).

**NOT transferable**

- Full **double-entry** `account.move.line` reconciliation, taxes, multi-currency residuals.
- States `in_payment` / bank matching — AllTrue uses parent **report → confirm** (#1827), not bank feeds.
- Odoo **rounding tax widgets** — AllTrue integer NTD; no tax_totals editor.
- Any LGPL/AGPL module code (including OCA payment popovers) — **out**.

### 6.3 Mapping to AllTrue (no silent recompute)

| Kill Bill / Odoo idea | AllTrue SoT | Display |
|---|---|---|
| charged / `amount_total` | Invoice projection `total_amount` (+ `stored_total_amount` audit) | 应收 |
| paid − refund | `net_applied` / `calculated_applied_amount` | 已記入／已收 |
| balance / `amount_residual` | `outstanding_amount` | 未结清／未收 |
| catalog / product price | `StudentClass.Charge` | **合約金額** (separate — #4155) |
| payment_state | resolver `status` + badges | never invent from `Paid=1` alone (#4114) |

Prior industry UX (Jackrabbit / Pike13 / Teachworks) remains in recon IA §0.4 — not re-opened here.

---

## 6b. Acceptance-fixture table (synthetic — display expectations)

Inputs are **lab amounts**; scopes must match §2.2. Expected strings follow ContractCard / ledger glossary (not bare 課程總額／尚欠).

| ID | Scope | Input (synthetic) | Expected labels / values |
|---|---|---|---|
| F1 mismatch | same contract | Charge=8800; Invoice stored=8000 unpaid, applied=0; `amount_discrepancy` | 合約金額 **8,800**；本合約帳單 应收 **8,000**・已收 **0**・未收 **8,000**；rate note「開單時 8,000／目前 8,800」；**no** rewrite of stored total |
| F2 partial | same invoice | total=8800; applied=8000; outstanding=800; status=partial | 应收 **8,800**・已記入 **8,000**・未结清 **800**；badge **繳了一部分**；not 「已繳清」 |
| F3 installments | same contract | Inv A paid 8000/8000; Inv B unpaid 800/0; Inv C void 9900 | Aggregate 应收 **8,800**・已收 **8,000**・未收 **800**；void row grey **已作廢**, excluded from sums |
| F4 period | same period (monthly) | billing_period=2026-01; period due=4800; paid_at=2026-09-05 amt=4800 | Month line「应繳 **4,800**　已收 9/5 **4,800**」；**not** titled 本期应繳 on count-mode cards |
| F5 waived | same contract | waived / zero_amount_reason=free_gift; Charge may be 0 | Label **確認不收** or free-gift chip；**not** 「已收」via Charge 0；exclude from campus 未收 |
| F6 void pay | same invoice | total=8800; Payment +8800 then Method=void 8800 | 已記入 **0**；未结清 **8,800**；TotalAmount still **8,800** (unchanged) |
| F7 multi-contract | student | Contract X owed 800; Contract Y owed 4800 future | Header: 到今天未繳 **800**；之後還會到期 **4,800** (D8)；**no** single unlabeled 尚欠 **5,600** |
| F8 refund/overpay | same invoice | total=8000; net_applied=8800 | 未结清 **0**；hint **多收 800**；ops — not negative 未收 |
| F9 legacy flag | same contract | Paid=1, no Payment evidence | 「需核對：舊資料標記已收…」；**no** invented 已收 amount / date (#4114) |
| F10 unbilled | same contract | no invoice; Charge=8800 | 「应繳：待開單」+「估算 **8,800**」；估算 ≠ 帳單应收 |

---

## 7. Regression / acceptance tests (when implementing)

Reuse / extend (do not replace money services in a copy-only phase):

| Layer | Existing | Add |
|---|---|---|
| Frontend unit | `CourseAmountBasis.test.js`, `ContractCard.test.js` (#4155), `StudentBillingList.test.js` | Encode §6b fixtures **F1–F10** as copy assertions; CourseManagement: invoiced vs estimate; bare 「本期應繳」absent on count-mode |
| Frontend unit | Payment badge / free status tests | **F5/F9**: waived/free/legacy flag never labeled 已收 via Charge/`Paid` alone |
| Backend | `BillingPayableResolver` / invoice reconciliation PHPUnit | Unchanged math; optional assertion API still returns `stored_total_amount` + `amount_discrepancy` for UI |
| Acceptance | calendar-course-acceptance payments slice (read-only) | After display merge only: synthetic campus checks for F1/F3/F7 — no private amounts in public logs |

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
