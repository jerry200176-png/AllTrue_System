## 2026-10-25 — Identify rejected diagnostic target guards
<!-- release-notes: silent_ship=silent-2026-10-25-target-binding-probe-reasons -->
<!-- silent-reason: read-only diagnostic metadata; no product behavior or data change -->

Refs #4496. The existing #433 probe records which target guard failed using a
fixed enum. The target, query scope, short-circuit order, readonly transaction,
rollback and failed decision-grade result remain unchanged. Rejection still
prevents leave preview and slot-guard execution. Raw exception text and business
records remain private; the public summary accepts only the fixed reason enum.
