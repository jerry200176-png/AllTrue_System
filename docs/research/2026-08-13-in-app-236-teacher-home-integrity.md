# In-app #236 teacher-home integrity evidence (2026-08-13)

## Decision

Make the teacher-home projection resilient to two independently observed failure
modes: (1) the same `ClassSession` projection can arrive in the normalized
`SessionViewModel` contract but be passed to an old snake_case-only de-duplicator;
and (2) concurrent reactive loads can blank a previously valid week and permit an
older response to replace the newer projection. This change is deliberately a
read-model repair. It does not mutate `ClassSession`, `LearningRecord`, attendance,
or billing data.

## Evidence layers

- **Locally verified:** `fetchClassSessions()` normalizes API data to camelCase
  `SessionViewModel` fields. `TeacherHomePage.vue` then calls
  `dedupeSessionsByStudentSlot()`, which previously read only `student_id`,
  `session_date`, `start_time`, and `learning_record_status`. Consequently, every
  normalized row was unkeyable and passed through. The new regression assertion
  supplies two same-student/same-slot camelCase models and proves that one is kept.
  The local `LearningRecord` schema has a unique `ClassSessionID` constraint, so
  one physical ClassSession cannot legitimately have two active LearningRecords.
- **Locally verified:** the week loader was triggered by mount, week offset and
  teacher-branch reactivity; it cleared `weekSessions` before its asynchronous
  request completed and did not consume its `AbortController`. The code now keeps
  the last good projection and uses a monotonically increasing request sequence.
  Its contract test asserts both properties.
- **Observed:** an authorized production administrator view showed #236 as a
  teacher-home report containing the three linked symptoms (flicker, duplicate
  evaluation, missing noon Da'an lesson) and one attachment. Attachment content
  and personal data were not copied into this document.
- **Documented:** Vue documents watcher cleanup as the way to prevent stale async
  side effects from applying after a watched value changes. The compatible local
  implementation uses a sequence guard because the existing API wrapper does not
  accept an `AbortSignal`. [Vue watcher cleanup](https://vuejs.org/guide/essentials/watchers.html)
  also explains why stale requests must be cancelled or ignored.
- **Documented:** RFC 5545 identifies an individual recurrence by a stable
  recurrence identity rather than its current displayed time, and says duplicate
  generated instances are ignored. This supports treating the UI de-duplication
  key as a defensive read projection, not authority to merge records.
  [RFC 5545 section 3.8.4.4](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.4.4)
- **Source-code verified:** [Frappe Education](https://github.com/frappe/education)
  commit `71aada478bf682f6d034fd4caa6f2f5438b5ace9`, GPL-3.0, active develop branch
  (updated 2026-08-13). `education/education/doctype/course_schedule/course_schedule.py`
  validates student-group, instructor and room overlap at write time;
  `test_course_schedule.py` tests each conflict and a non-conflict. It supports
  prevention at the scheduling boundary, but no GPL code is copied.
- **Source-code verified:** [Moodle](https://github.com/moodle/moodle) commit
  `3deaeb9e4d026b75ba8f2f4a9108f6340a85df7a`, GPL-3.0, active main branch
  (updated 2026-08-13). `public/mod/assign/db/install.xml` enforces a unique
  attempt identity; `public/mod/assign/locallib.php::get_user_submission()` loads
  that identity and creates within a transaction. It supports enforcing logical
  identity in persistence and resolving the current record from it. No Moodle code
  is copied.

## Local adaptation and limits

**Inferred then bounded locally:** a correct UI must show one teacher-facing card
for a confirmed same-student, date and start-time collision, preferring an
attended/approved materialized session; it must never delete or mutate the other
record. The server-side `ClassSession` unique slot constraint prevents duplicates
inside one contract, while renewal/cross-contract collisions remain a distinct
domain condition. They require the existing controlled duplicate-review/repair
workflow and cannot be silently resolved by the browser.

The production diagnostic run `31688819608` was read-only but its legacy workflow
contains a hard-coded historical probe and is therefore **not** evidence about
#236's reporter. It found no orphan sessions only; no data repair is authorized
from that result. A narrowly scoped, PII-minimized diagnostic must be used before
any future production data repair.
