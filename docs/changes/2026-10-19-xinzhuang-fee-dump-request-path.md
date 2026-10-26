## 2026-10-19 — ci(ops): push request-file path for Xinzhuang fee dumps
<!-- release-notes: silent_ship=silent-2026-10-19-xinzhuang-fee-dump-request-path -->
<!-- silent-reason: CI dump trigger for cloud agents; no staff-facing product change -->

Adds `xinzhuang-fee-dump-request.yml` so merging a change to
`operations/closeout/xinzhuang-fee-dump.request.md` dispatches allowlisted
`production-case-dump` runs (4064/3753/3693 only). Child workflow still fail-closes
on `DUMP_ARTIFACT_KEY` and `production-ssh`.
