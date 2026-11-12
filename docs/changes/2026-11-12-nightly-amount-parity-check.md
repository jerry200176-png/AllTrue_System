## 2026-11-12 — feat(ops): nightly read-only amount parity condition (reported, not enforced)
<!-- release-notes: silent_ship=silent-2026-11-12-nightly-amount-parity-check -->
<!-- silent-reason: Internal read-only nightly condition; no UI, amount, status, alert threshold or data change. Reported only, never fails the job. -->
- The nightly bug-verification run also counts live bills whose header, line items and on-screen amount disagree, per campus, with named exclusions. It only reads; it repairs nothing and cannot fail the run.
