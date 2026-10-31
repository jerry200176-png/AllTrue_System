## 2026-10-31 — fix(learning-records): an outstanding refill follows every later contract transfer (#4663)
<!-- release-notes: silent_ship=silent-2026-10-31-lr-refill-follows-transfers -->
<!-- silent-reason: ownership edge case on repeated transfers; no screen change -->
- `reassign-contract` treated a refill as outstanding only when a snapshot named the record's current teacher. With A→B→C→D and no refill in between, the B→C move left no snapshot naming C, so the C→D move kept the record on C and teacher D got 403. A refill now counts as outstanding whenever the record was snapshotted, its body is still empty and it is not approved. The record follows the new contract teacher on every transfer, and A's body is still captured once.
