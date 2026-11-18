## 2026-11-18 — fix(in-app): bind native support comments to their transaction
<!-- release-notes: staff_update=staff-2026-11-18-inapp-native-origin -->

- Future native intake acknowledgements and timeout comments record an internal append-only authorship receipt in the same locked transaction. Receipt failure rolls back the comment/status/log; replay remains idempotent. Existing campus, role, question, freshness and closure windows remain unchanged.
- Readers bind the exact comment/body/author/campus/context and close log, reject missing, corrupt or ambiguous proof, preserve a human comment with identical text/time, and emit only a fixed diagnostic plus opaque ID/exception class on unexpected read failure. Stored log timestamps keep origin stable across display timezone changes.
- Native proof is separate from POP execution receipts and public resolution evidence; it grants no approval, shipment or reporter acceptance. No migration, new key, historical rewrite or replay. Reader fence5505 must be served and verified before this producer is published or activated; every producer rollback target retains that fence and all audit rows.
