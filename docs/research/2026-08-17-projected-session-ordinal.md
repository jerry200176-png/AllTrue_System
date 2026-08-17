# Projected chips must not consume 第N堂 (2026-08-17)

## Decision

Course-management chip ordinals (`第N堂`) number only **materialized**, quota-occupying ClassSession rows. `isProjected` 預排 chips stay visible with the 預排 label but return no ordinal, so they cannot sit as “第3堂” between attended lessons.

## Current local behavior (locally verified)

`getSessionNumber` walked `sessionUnits()` and skipped leave / over-quota only. Projected slots are merged into that list (`classSessionsApi` kind=`projected`) and were counted. Header copy already said「本科已上 5 堂」via attended chips (`#1834` / R110), so the ordinal contradicted the count. Backend `LearningRecordController::batchSessionNumbers` already numbers only real ClassSession rows and skips cancelled/leave.

## Comparable products

| Source | Why relevant | Evidence |
|---|---|---|
| RFC 5545 STATUS / recurrence expansion | Virtual instances exist before they are overridden/confirmed; SEQUENCE is a **revision**, not a lesson index | Documented: [RFC 5545 §3.8.1.11](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.1.11) `TENTATIVE` vs `CONFIRMED` |
| Google Calendar Events API | `status=tentative` is not a confirmed event; cancelled exceptions must not be presented as live instances | Documented 2026-08-17: [Events resource `status`](https://developers.google.com/calendar/api/v3/reference/events) |
| Open edX unit workflow | Learners never see **Draft (Never Published)**; drafts are not in the LMS sequence | Documented 2026-08-17: [Unit publishing status](https://docs.openedx.org/en/latest/educators/references/course_development/unit_workflow_and_status.html) (docs.openedx.org `80d50bc1` 2026-08-14) |
| Frappe Education `CourseSchedule` | Calendar items are persisted documents; overlap validation runs on saved rows, not on uncommitted expansions | Source-code verified: [course_schedule.py](https://github.com/frappe/education/blob/71aada478bf682f6d034fd4caa6f2f5438b5ace9/education/education/doctype/course_schedule/course_schedule.py) commit `71aada478bf682f6d034fd4caa6f2f5438b5ace9` (GPL-style / license.txt; no code copied) |

## Adaptation

Keep showing 預排 dates (directors still need the hole). Do not give them a consumption ordinal. Materialized `scheduled` rows (already booked) keep numbering after attended. Matches evaluation `session_number` and `rowOccupiesPurchasedQuota` (projected already `false`).

## Rejected

Renumbering only attended and hiding future scheduled ordinals — those rows are committed bookings. Silently deleting the 08/04 projection — data repair, out of scope.

## Tests

`useCourseSessionsDisplay.test.js`: attended + 預排 + later attended; 預排 → `null`; following attended continues 3, 4, 5.
