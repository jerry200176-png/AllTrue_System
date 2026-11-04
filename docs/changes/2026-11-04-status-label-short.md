## 2026-11-04 — fix(ui): payment-page status labels shortened to 6 characters or fewer, explanation behind a tooltip (#4659)
<!-- release-notes: staff_update=staff-2026-11-04-status-label-short -->
- `courseMoneyState.js`: legacy-flag row label is now 查無收款 (full sentence kept as `LEGACY_FLAG_UNVERIFIED_HELP`, used for titles and the contract card); new `REVIEW_REQUIRED_SHORT_LABEL`. Backend values unchanged.
- `StudentBillingList.vue`, `TuitionCollectionPage.vue`, `AccountingLedgerModal.vue`: short label shown, full text in `title`.
- `scripts/design/status-label-length.mjs` + baseline: advisory step in the existing design-advisory job (never fails).
