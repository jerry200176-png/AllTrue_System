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
Dry-run: `pop-legacy-void-link.yml` `mode=dry-run`, `confirm=DRY_RUN_LEGACY_VOID_LINK_20261107`, deployed backend SHA. Approve is outside this PR.
Inverse: the stored snapshot sets the link back to NULL, only for rows still linked to the manifest receipt.
