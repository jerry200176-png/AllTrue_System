## 2026-10-19 — docs(billing): plan for confusing amount display semantics
<!-- release-notes: silent_ship=silent-2026-10-19-billing-amount-display-plan -->
<!-- silent-reason: planning doc only; no staff-visible product change -->

- Added `docs/plans/2026-10-19-billing-amount-display-semantics.md`: current SoT for contract Charge vs invoice receivable/applied/outstanding, same-contract/period scope, label assessment, edge cases, OSS citations, phased display-only work, rollback.
- Implementation deferred; money-path ownership remains with billing closeout. No production or billing code in this change.
