## 2026-10-22 — Monthly lesson reader keeps the root reached on the 40th reschedule hop
<!-- release-notes: silent_ship=silent-2026-10-22-monthly-reader-fortieth-hop -->
<!-- silent-reason: edge-case parity fix in the same-day monthly reader; no visible change for normal chains -->

`ClassSessionController::monthlyLessonRowsByClass()` walks `original_schedule_id` for at most `BackfillScheduleOccurrenceIdentityService::MAX_HOPS` (40) hops. A chain of exactly 40 links reached its root on the last hop and then fell through to "no identity", so the live lesson was placed by proximity and could hide a different contract slot. The row reached at the hop limit now gets the same terminal check as inside the loop (frozen identity, else the root's own slot), which matches the backfill resolver. Shorter chains behave as before. Regression: `MonthlyCalendarLeaveNoBackfillTest::test_a_reschedule_chain_of_exactly_forty_links_keeps_its_root_slot`. Rollback is a normal revert. Refs #3970.
