# ADR: ClassSession carries a frozen original slot

Status: accepted (P1 of ARCH-OCCURRENCE-IDENTITY-20261011; answers RFC_SCHEDULE_OCCURRENCE_IDENTITY §9 "does ClassSession store the original start?").

Decision: `ClassSession.OriginalSessionDate` (DATE NULL) + `OriginalStartTimeHM` (CHAR(5) NULL) are stamped by `ClassSessionMaterializationService::upsertSlot` on create for series rows and are write-once: `ClassSessionObserver::updating` reverts any change to a set value and logs a warning. NULL -> value is allowed (P3 backfill). Extras (`manual_occurrence`) stay NULL; their identity is `extra:<id>`.

Why hard to reverse: later phases (P3 backfill, P4/P5 readers, P6 unique index) key occurrence identity on these columns, so changing the freeze rule afterwards re-keys every consumer.
Why surprising without context: moves, substitutes, restores and contract transfers all keep the columns while `SessionDate/StartTime` change.
Trade-off: a guard in one observer instead of ~30 writers, at the cost of silently ignoring (not throwing on) mutation attempts. Direct `DB::table()` updates bypass it.

P1 has no readers and no index; rollback = revert PR (columns unused; `down()` drops them).
