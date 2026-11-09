# Repair Manifest — 舊沖銷紀錄對帳 2026-11-07

Status: POP operation `legacy-void-link-20261107`; dry-run only until Founder approves the printed digest.
Risk: R3/T3. Source in-app #521 (PR #5106 made `PaymentReportController::void()` refuse bills with a legacy unlinked void row); evidence: read-only case dump `inapp_521_swallowed_receipts`, run 42385247945.

Legacy unlinked void row = Payment with Amount < 0 or Method `void`, `payment_report_id` NULL, Method not `transfer_out`/`refund`, on a live bill. The dump found 3 rows on 3 bills, each with exactly one candidate receipt (same amount, its payment written earlier).

Closed allowlist by void Payment id (code: `LegacyVoidLinkManifest`; IDs only):

| void payment | bill | receipt | receipt payment | amount |
|---|---|---|---|---|
| 773 | 704 | 670 | 673 | -8800 |
| 1491 | 1485 | 1413 | 1490 | -2500 |

Not auto-linked (listed for a human decision): void payment 940 on bill 960. Its candidate receipt 928 already has another linked reversal (payment 1942), so linking 940 would make the double reversal explicit instead of fixing it; bill 960 also holds receipts 929 and 2435 and needs a reviewed correction.

Only `Payment.payment_report_id` of the two void rows is written (NULL to the receipt). Amounts, methods, invoices, statuses, receipts and net paid are never written and are re-checked. Guards (plan and again under row locks in apply): void row, bill and receipt exist and match the manifest; amount equals minus the receipt's reported amount; the receipt's payment is the manifest one; the bill is not void; the receipt has no other linked reversal. Live NULL is `before`, the receipt id is `after` (no-op); anything else fails naming the void row.
Execution channel: use the existing authenticated POP control plane and Pi-local `pop:execute-approved` scheduler. The proposed SSH adapter was removed: no token is harvested from a human session, no runner-to-SSH repair path or HTTP execute route is added. The current cloud triage key cannot submit or approve this operation.

Preview binding: create a read-only draft with both `decision_reference=link-legacy-void-20261107` and `expected_digest=""`, and retain its complete preview. After the exact target, effect, downside, inverse and latest source evidence are reviewed, create a distinct draft/idempotency key with `expected_digest` equal to that preview's full SHA256; run its dry-run again. The genuine authenticated Founder approval is bound to those parameters, deployed code SHA and expiry. Approval rejects an empty/stale digest. Execution repeats the digest comparison under invoice-before-payment locks and includes current invoice/payment/receipt fingerprints; an otherwise valid new payment invalidates the reviewed preview. This code PR grants no financial execution approval.

Timestamp/inverse: only `payment_report_id` is written; the original `updated_at` is deliberately preserved. Snapshot stores the original link/time fields and a post-execution digest. Verify checks that post-state. Rollback refuses later state drift, then restores only this run's exact guarded links without touching timestamps, cash or receipt state. A successful dry-run is not evidence that historical accounting is repaired.

Activation packet still needs a fresh complete production preview, its full bound digest, exact deployed strategy SHA, independently reviewed effect/inverse and the authenticated Founder decision. Historical dump run42385247945 identifies candidates, not today's execution state.
