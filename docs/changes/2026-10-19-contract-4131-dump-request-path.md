## 2026-10-19 — ci(ops): push request-file path for contract_4131 dump
<!-- release-notes: silent_ship=silent-2026-10-19-contract-4131-dump-request-path -->
<!-- silent-reason: CI dump trigger for Track B path acceptance; no staff-facing product change -->

Adds `contract-4131-dump-request.yml` so merging a change to
`operations/closeout/contract-4131-stale-teacher.request.md` dispatches a single
read-only `production-case-dump` run for `contract_4131_stale_teacher` (course 4131).
Child workflow still fail-closes on `DUMP_ARTIFACT_KEY` and `production-ssh`.
Does not authorize Cursor to redispath or claim path PASS without dump evidence.
