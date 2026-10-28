## 2026-10-24 — feat(billing): bounded ordinary-close capture proof
<!-- release-notes: silent_ship=silent-2026-10-24-ordinary-close-capture -->
<!-- silent-reason: unwired internal capture helper and tests; ordinary-close behavior is unchanged -->

- Adds a transaction-bound raw-row census and strict write-set delta proof for the contract, sessions, and two entitlement-history tables.
- Sensitive source values remain inside keyed row digests; the returned evidence part is explicitly partial and cannot be appended as a complete close event.
