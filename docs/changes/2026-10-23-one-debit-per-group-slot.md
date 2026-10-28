## 2026-10-23 — fix(packages): a group slot debits a shared package once on every path
<!-- release-notes: staff_update=staff-2026-10-23-one-debit-per-group-slot -->

Group packages (one_on_two, one_on_three, one_on_many, tutoring) debit the shared pool once per (date, start time) slot (FR-003), but four paths could leave one slot debited twice (#4455 class E):

- **Stale reads (#4454).** The slot and lesson net checks read the ledger with plain reads, which under REPEATABLE READ keep the snapshot the caller's transaction took before the package lock. Two members attending at once could both debit the slot. The package ledger reads after the lock are now locking (shared) reads on the package's own rows, and the package counters are recomputed from current rows. Lesson rows are still read without locks.
- **Package creation.** Past dates entered when creating a package were debited per subject with no slot check; they now go through the same slot rule as attendance (`deductUnderSlotRule`).
- **Seconds in the start time.** Swipe and self-study lessons store seconds, so `16:00:37` and `16:00` were two slots. The slot is now keyed on `StartTimeHM` (HH:MM).
- **Retiming an attended lesson.** Changing the start time of a debited lesson carried its debit into the new slot even when that slot was already debited, and left the old slot with no debit even when other members were still taught there. After a start-time change the slot rule is applied to both slots. The old slot keeps its one debit on a member lesson still attended there. A carried debit that lands in a debited slot is credited back (reason `retime`). A taught lesson moving into a slot with no debit debits it, so moving a lesson back restores its slot.

Not changed: the course ledger's own net check (concurrent marks of the same lesson can still deduct a course twice), moving a slot debit to another still-charged member when the carrier is *reverted* (a retime does move it; #4454), the conversion seed, the ledger rebuild tool's slot key, and existing ledger rows (repair is F2). No migration. Tests: `PackageGroupSlotOneDebitTest`, `PackageSlotCurrentReadTest`. Rollback is a normal revert.
