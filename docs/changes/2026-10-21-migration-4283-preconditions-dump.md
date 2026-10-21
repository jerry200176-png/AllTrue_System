## 2026-10-21 — chore(ops): read-only #4283 migration precondition case for the 新店 release gate
<!-- release-notes: silent_ship=silent-2026-10-21-migration-4283-preconditions-dump -->
<!-- silent-reason: internal read-only ops probe; no product behavior change -->

`production-case-dump.yml` gains `migration_4283_preconditions`, which takes no
target. It reads the `invoice_corrections` schema, the row and duplicate-key
counts, the course-key unique index, which of the four recent migrations are
still pending, and the session isolation level. The job log publishes only fixed
tokens and counts, never row values, so the go/no-go for the next release
(which runs `2026_10_19_090000`) does not wait on decrypting the artifact.
