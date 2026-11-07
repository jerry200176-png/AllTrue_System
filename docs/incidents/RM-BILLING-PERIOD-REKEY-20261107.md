# Repair Manifest — re-key monthly bills to their first-lesson month (in-app #514, 2026-11-07)

Status: POP operation `billing-period-rekey-20261107`; nothing runs until the Founder approves a specific dry-run digest.
Risk: R3/T3. Pairs with the forward fix (`Billing\BillingPeriod::forCycle`, new bills only).

## Why

A renewal bill was labelled by the month its cycle STARTED (`MonthlyRenewalPeriodService::inspect`). A cycle 04-29 ~ 05-27 with May lessons read
2026-04. New bills now use the first lesson's month (Stripe/Chargebee label by service period). Founder 2026-11-07: old bills move too, every campus.

## Scope (computed at dry-run, ids only)

Parameters: `campus_ids` (`["all"]` or a list), `decision_reference = repair-billing-period-rekey-20261107`, `expected_digest` (empty on dry-run).
Candidates: live (non-void) Invoice on a `ScheduleMode=date` class with exactly one item whose period equals the class StartDate..EndDate
(a whole-cycle renewal bill). Catch-up, split and multi-item bills are never touched: their calendar month is the point.
Target = month of the cycle's first non-cancelled lesson, else the cycle start month. A row exists only when stored != target.
- `ready`: will be re-keyed.
- `skipped` + `reason`: `period_taken` (a live bill of the same student+subject already holds the target period, stored or planned in this run:
  never forced, never two bills in one period), `note_full` (the 255-char Note cannot hold the number tag).

Dry-run output: digest, counts per campus, per stored month and per new month in the log; the ids-only manifest only in the encrypted artifact.

## What is written (only)

Per `ready` bill, one transaction under row locks (digest recomputed; drift aborts): `Invoice.billing_period`, that invoice's
`payment_reports.billing_period` (rows still on the old period), and a `[invno_period:<old YYYY-MM>]` Note tag. TotalAmount, PaidAmount, Status,
items, payments, sessions are untouched. Strict `pop.billing_period_rekey` audit row per invoice (invoice id, from, to, operation id).

## Invoice numbers

`INV-YYYYMM-<id>` is computed, staff-only (accounting ledger, bill list, correction notes), and not in the parent portal or receipts
(`RCPT-` uses the payment date). `Billing\InvoiceNumber::for()` reads the Note tag, so an issued number never changes. No migration.

## Evidence and restore

Workflow `pop-billing-period-rekey.yml` writes before (dry-run manifest) and after (execute snapshot, no Note text) as JSON lines encrypted with
`DUMP_ARTIFACT_KEY` (openssl aes-256-cbc, same as `production-case-dump.yml`), artifact retention 7 days. Restore by request id: a new
Founder-approved POP rollback of that request (the strategy is in the atomic-financial recovery allowlist) puts back period and report periods and removes only this run's Note tag, for bills still exactly as this run left them (a voided bill is left alone; later Note text stays).

## Order of landing

Lands after the forward-fix PR (#5043): the month report (`FinanceController`) must count a bill in its label month first, else re-keyed bills would show 待確認 in the report. Moving a cycle's label also moves it between report months (April to May for the 04-29 cycles): intended, Founder 2026-11-07.
