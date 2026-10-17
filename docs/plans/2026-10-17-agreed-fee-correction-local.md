# Agreed-fee correction: local implementation boundary

Status: LOCAL, disabled and unmerged. `billing.agreed_fee_correction_enabled` is literal `false`, without an environment switch. No production query, migration, credential configuration, deployment, or real-record repair is part of this work.

The existing proposal and its separate Python behavioral model remain design evidence. The implementation is an independent bounded service, `Billing/AgreedFeeCorrection`, with preview/apply controller and a dedicated dialog reached from each course in AccountingLedgerModal. Role/campus middleware and both controller/service enforce the exact student's campus; empty director scope grants nothing, while the documented super-admin exception remains. The ledger supplies a server capability, hidden by default and unavailable if schema is absent.

## Two explicit scopes

- `billed`: one live unpaid invoice selected by ID, owned solely by this course/student. Positive current stored Charge is the agreement authority; selected Invoice.TotalAmount is the obligation being replaced, and can differ. Original amount, items and all payment/report history remain. Original status becomes void; a replacement reference is appended only if the full original note plus reference fits 255 characters. The issuer creates one new unpaid bill and item, PaidAmount zero. Only course Charge changes.
- `unbilled`: zero invoices of ANY status, zero course-linked InvoiceItems (including externally anchored items), zero PaymentReports of ANY status, and no legacy paid markers. Only Charge, one correction record and one audit event change; invoice IDs, original invoice total and replacement amount remain NULL. No invoice is fabricated. This extra proposed product rule needs explicit adoption before activation.

Both scopes allow positive count-mode trial lessons: EnrollmentService creates positive trial fees without automatically issuing an invoice, and treats tutoring separately. Both reject packages, monthly, tutoring, free/zero/unknown Charge, waived variants, active pricing amendments, legacy Paid/Pay/PayDate, ambiguous/shared invoices, pending/confirmed reports and unmatched or surviving payment/reversal evidence. A void-only invoice history is never treated as never-billed; the operator receives an actionable refusal for separate review. Actual case classification and invoice presence remain unverified.

## API contract and invariants

POST `/api/v1/student-classes/{id}/agreed-fee-correction/preview` and `/apply` accept scope, explicit original invoice ID for billed (NULL/absent for unbilled), positive whole-dollar new_charge, trimmed reason (3–80 characters), stable UUID idempotency_key. Apply also requires the returned preview_token. Preview separately labels old stored Charge, original invoice amount/outstanding, new_charge, charge_delta, obligation_delta and new_outstanding. Unbilled has NULL invoice amounts; zero cash/report/receipt/refund/notification effects and unchanged Rate/count/usage/dates are explicit.

The versioned application-key signature expires after ten minutes and binds normalized input, actor, campus, course fields, all invoices/items/payments/reports, pricing amendments, sessions, deductions and entitlement transfers. Missing signing configuration fails closed. No application key is inspected or configured outside synthetic fixtures. Preview signatures grant no permission. Apply acquires fresh locks before consistent reads and compares the complete state again.

Domain rows survive signing-key rotation and audit retention. Same course/key/payload/actor/kind returns the stored result after fresh authorization, without requiring today's token or eligibility. Changed payload or actor is 409. Unique original invoice guarantees one winner across distinct billed keys; course serialization and state-bound previews provide the same outcome for never-billed requests. The permanent course/key index is shared across correction kinds.

One transaction contains original void/new bill (billed only), Charge update, permanent domain row and REQUIRED SecurityAuditEvent append. A forced failure after invoice creation or audit insertion rolls back all of them. No count/date correction service, pricing amendment writer, payment/report/receipt writer or sender is called. The course's history guard retains correction rows even while the feature is disabled.

## Lock coordination and bounded reversal prerequisite

The fee operation locks student → course → invoices/items/payments in stable order. It reads reports while holding course but NEVER locks report rows, because confirmation and reversal lock report → course → invoice/payments. A pre-existing pending/confirmed report refuses correction; writer transactions through course prevent money from interleaving. Fresh consistent reads follow the course lock, including replay reads, to avoid stale InnoDB REPEATABLE READ snapshots.

The existing report-void path formerly reused a confirmed report fetched before its transaction. A delayed request could append a second reversal and reopen an invoice after a successful fee correction. A bounded prerequisite re-fetches/locks report, checks confirmed again, locks course/invoice/payments in confirmation order, and refuses a void/misowned invoice. It does not acquire a student lock after course/report. Ordinary/exception invoice void already lock/recheck their invoice; tests cover both orderings against correction.

## Schema and integration dependencies

The create-table migration from pending #4132 is a LOCAL prerequisite; its create shape is preserved. One explicit coordinated local deviation makes its down refuse any retained rows rather than drop permanent history. The owner branch is untouched. No receipt-producing controller/service from that branch is copied. A separate extension adds kind (legacy default receipt_reissue), campus, old/new Charge, original invoice total, request/state hashes and unique course/key. It also explicitly alters original/replacement IDs and amount to nullable for agreed_fee_unbilled; this is an ALTERATION, not merely additive DDL. Billed kind is agreed_fee. Existing amount continues to mean replacement invoice total; NULL means no replacement invoice exists, and new_charge holds the new unbilled agreement.

Preflight rejects a missing/incompatible prerequisite schema or duplicate legacy course/key. The extension uses one MySQL ALTER. Down refuses before DDL if any fee-only/null history exists. Safe code rollback keeps history/schema and leaves the feature disabled, never deletes history or invents invoice IDs. Pending #4132 consumers must discriminate kind before assuming invoice IDs/amount are non-NULL. Coordinate schema adoption with its owner; this local copy does not authorize migration or merge.

PR #4214 separately fixes other existing screens that recompute Rate × count instead of respecting stored positive Charge. This dialog uses server Charge; whole-product display consistency needs that integration. This local branch does not copy the other owner's UI patch or claim all real cases are fixed.

## Validation and release boundary

Synthetic MySQL tests use separate PHP processes/PDO connections and assert InnoDB REPEATABLE READ. Cases include same/different-key races in both scopes, direct receipts, report confirmation and creation, delayed duplicate reversal, invoice void in both orders, and ordinary invoice issuance against unbilled correction. Focused API tests cover trial 8000→500, stored Charge versus selected bill mismatch, unchanged session/deduction rows, legacy markers, fail-closed authorization/state/schema/signing, history preservation, no sender/queue effects and atomic rollback. UI tests cover server capability, student-only entry targeting and stable-key retries after a lost response.

This is preparation evidence only. No CI, protected merge, production activation or verified real-record success is claimed. Adoption requires the exact financial semantics (including never-billed), schema/nullability coordination, reversal prerequisite, display dependency, risk review and product authorization. Any actual correction additionally requires verified agreement facts, invoice/history shape and operator intent.
