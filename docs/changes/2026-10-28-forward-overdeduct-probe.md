## 2026-10-28 — chore(ops): forward over-deduct read-only probe
<!-- release-notes: silent_ship=forward-overdeduct-probe -->
<!-- silent-reason: Operator-only read-only diagnostic; no screen or API behavior change. -->
- #4554: list count courses the nightly forward generator over-filled (#4515) whose ledger holds more attendance deducts than bought, with renewal targets. READ ONLY session, rolled back, ids/counts only.
