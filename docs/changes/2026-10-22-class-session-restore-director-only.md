## 2026-10-22 — Restoring a cancelled lesson is director-only on the server too
<!-- release-notes: silent_ship=silent-2026-10-22-class-session-restore-director-only -->
<!-- silent-reason: authorization hardening; the calendar already offers 復原 only to directors -->

The calendar offers 「復原」 for a cancelled lesson only to directors (`CalendarSessionEditModal`), but `POST /class-sessions/{id}/restore` accepted any teacher of the campus, and a restore re-applies the lesson's deduction. The route now carries `role:director,super_admin`, like the neighbouring reassign-contract routes. The read-only `GET .../recovery` check the calendar loads for every viewer is unchanged. Regression: `ClassSessionUpdateTest::test_a_teacher_cannot_restore_a_cancelled_session`. Rollback is a normal revert.
