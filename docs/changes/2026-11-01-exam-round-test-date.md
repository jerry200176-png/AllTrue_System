## 2026-11-01 — test(exam): exam-round API test no longer expires on a fixed date
<!-- release-notes: silent_ship=silent-2026-11-01-exam-round-test-date -->
<!-- silent-reason: test-only fix; no product change -->
- `ExamRoundApiTest` set its entry deadline to `2026-10-31`. From 2026-11-01 the round no longer counted as pending, so the test failed on main and blocked every PR in the land queue. The deadline is now one week from the day the test runs.
