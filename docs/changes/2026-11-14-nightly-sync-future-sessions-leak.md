## 2026-11-14 — fix(tests): SyncFutureSessionTimesTwoPhaseTest deletes the rows it commits (#5154)
<!-- release-notes: silent_ship=silent-2026-11-14-nightly-sync-future-sessions-leak -->
<!-- silent-reason: test-only cleanup; no screen, API or data change -->
- The nightly random-order PHPUnit run failed `PrepaidHorizonPhase0ReportTest` and `OutboundRemindersResolverTest` because `SyncFutureSessionTimesTwoPhaseTest` runs without a wrapping transaction and never deleted its Student / StudentClass / ClassSession / LearningRecord rows. Its tearDown now removes exactly the rows it inserted.
