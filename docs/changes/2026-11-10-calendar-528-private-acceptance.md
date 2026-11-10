## 2026-11-10 — test: observe calendar cache replacement through the existing private App harness
<!-- release-notes: silent_ship=silent-2026-11-10-calendar-528-private-acceptance -->
<!-- silent-reason: acceptance coverage only; no product UI, business API or production data change -->
- Reuse the working director login/navigation harness for one synthetic calendar move, with exact calendar reads and the reschedule response fulfilled locally before navigation. Keep the global write/materializer guard and existing private capture settings.
- Record public frontend identity before/after separately from the test-only harness SHA, plus hashes of the actual loaded root/calendar modules. Local fixture identity is synthetic; a named runtime PASS must be checked against the approved deployed revision.
- This verifies bundled frontend cache behavior with mocked data. Actual transaction #528, database correctness, native read redirect effects and reporter acceptance remain unverified.
- Replace the stale Phase C capability status with a link to the canonical automation table; code/catalog registration does not establish credentials, flag readiness or execution authorization.
