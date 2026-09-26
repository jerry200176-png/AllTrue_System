# In-App348 — existing settled-label explanation

SourceRef `alltrue:bug_report:348`; canonical GitHub #3213.
Fresh queue36217213127 and detail36217215204: new, no comments/logs.
Attachment295 viewed privately via the existing storage URL, not committed.
It shows the settled summary labels without a reason or next step.

## Existing authority and bounded change

AccountingController::settledCourses sets legacy_paid_without_invoice only
when there are no non-voided invoices and the course is already marked Paid.
has_exception is the existing per-invoice overpaid aggregate >0 (net receipts
minus invoice total, bounded at zero). This change explains those definitions
using static text and points to the existing same-row ledger for checking
existing records with the accounting operator. No classification, source,
amount, calculation, handler, payment advice/action, schema, identity,
permission or production data changes. All thirteen existing product-loop
small-fix conditions hold; R1/T1 presentation, not a blanket billing exemption
or reuse of the one-time #362 adjudication.

## Verification and review

Actual browser RED: existing page has no labelled explanatory note.
GREEN required: visible/readable at390/1280px; exact existing meanings;
unchanged4,200/5,600 fixture values and both existing ledger buttons;
no non-GET/HEAD requests from viewing the explanation. Full existing suite,
build, machine declaration, required exact-head CI and review remain gates.
Entire script and style suffix must match baseline345b0f4cb byte-for-byte.
Existing typography/spacing/note style; no new design system or raw colors.
Anti-slop review:1/10; product distinctiveness8/10.

## Release and claims

Normal PR -> exact-head gates -> canonical deploy.yml -> containing runtime
SHA plus health -> existing lawful scoped Phase-C/public retest request.
Local synthetic rendering is not production user-path observation; reporter
acceptance remains PENDING. No existing billing record has been validated or
repaired. Revert this PR and normally deploy the prior code345b0f4cb if needed;
no migration/data rollback. A proposed code recovery is not executed rollback.
