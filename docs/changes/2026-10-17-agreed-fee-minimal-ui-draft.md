## 2026-10-17 — feat(billing): agreed-fee minimal UI Draft (default-off)
<!-- release-notes: silent_ship=silent-2026-10-17-agreed-fee-minimal-ui-draft -->
<!-- silent-reason: Default-off Draft UI; agreed-fee entry stays hidden until a server capability is granted and the Founder enable gate. -->

- Reuse handoff `AgreedFeeCorrectionModal` (preview → apply, token/idempotency, no amount guess).
- Gate entry via `ContractAdjustmentChoiceModal` `agreedFeeAvailable` (default false); CourseManagement keeps the gate off.
- Component/API-contract tests: off no entry, preview-only, controlled token/amount/reason, refusal paths never report success, no payment/receipt calls.
- Does not enable `agreed_fee_correction_enabled`, alter payment/receipt flows, or claim production activation.
