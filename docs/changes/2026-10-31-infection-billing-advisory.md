## 2026-10-31 — ci(billing): nightly advisory Infection mutation run on the billing money code
<!-- release-notes: silent_ship=silent-2026-10-31-infection-billing-advisory -->
<!-- silent-reason: Dev-only dependency and an advisory nightly workflow; no production code or runtime behaviour changes. -->
- Adds infection/infection (BSD-3) as a backend dev dependency and `.github/workflows/infection-billing.yml` (nightly + manual). It mutates only `ContractMoneyVerdict`, `ContractMoneyState` and `BillingPayableResolver` against the billing tests, never fails, and writes the MSI to the job summary (#4659 step 4).
