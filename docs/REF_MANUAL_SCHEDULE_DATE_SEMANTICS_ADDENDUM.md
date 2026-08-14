# Manual occurrence date semantics (#211)

- Store `scheduling_policy=manual_occurrence`; never add a new `ScheduleMode` value.
- A course may keep its historical and future materialized sessions when converted.
- The director/admin UI books one future date and start time at a time. Duration comes from the course.
- The API checks independent-course eligibility, branch isolation, student/teacher/room conflicts,
  active future reservations, and the `(StudentClassID, SessionDate, StartTime)` idempotency key.
- Cancellation, leave, reschedule, and pause do not silently create a replacement; the UI says to
  manually arrange the next lesson when needed.
- Shared package courses are rejected until package-pool reservation/coverage is implemented.
