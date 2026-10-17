# Local agreed-fee correction preparation

`silent_ship`: disabled, unmerged preparation; no staff-visible activation.

Adds a separate fee-only preview/apply workflow from student accounting, including positive billed trials and an explicitly separate never-billed trial/course path. Preserves Rate, count, usage, dates, attendance and deduction history. Billed corrections retain the original bill and replace it with an unpaid bill; never-billed corrections issue no bill. Neither path creates a payment, payment report, receipt, refund or notification.

The literal backend flag remains false. Shared schema adoption depends on coordination with pending #4132 and alters invoice/amount nullability for never-billed history. Production migration, merge, enablement, and actual-record correction are not authorized by this local preparation. See [implementation boundary](../plans/2026-10-17-agreed-fee-correction-local.md).
