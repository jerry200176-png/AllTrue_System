## 2026-10-19 — ci(ops): serialize Xinzhuang fee dump dispatches
<!-- release-notes: silent_ship=silent-2026-10-19-xinzhuang-fee-dump-serialize -->
<!-- silent-reason: CI dump dispatcher fix; no staff-facing product change -->

After #4293, back-to-back `production-case-dump` dispatches under `production-ssh`
cancelled the middle course (3753). Dispatcher now waits for each dump run to finish
before starting the next, and bumps the request file to recover the missing dump.
