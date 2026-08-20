# RFC: Learning Assessment MVP

**Status:** Proposed for Slice 1 implementation
**Date:** 2026-08-20
**Risk class:** R2
**Related:** `docs/research/2026-08-learning-assessment-mvp.md`

## Decision

Add an additive `Assessment` bounded context beside `LearningRecord`.
Assessment results are diagnostic/teaching data and are never an implicit
attendance, LearningRecord approval, or billing event.

The first production slice supports staff-created assessment definitions and
manual student result entry. It intentionally postpones question-level online
attempts until the result workflow has been used with one real subject.

## Context and boundary

Existing truth remains:

```text
ClassSession → StudentSignIn / attendance → LearningRecord approval → billing sync
```

New truth is:

```text
Assessment → AssessmentResult attempt(s) → audit → future remediation / report
```

`Assessment` may optionally reference a `StudentClass`, but it does not require
a `ClassSession`. A diagnostic test can happen before enrollment, on paper, or
after a class. If a class context is supplied, it is for filtering and teacher
workflow only; it does not create a session or alter session status.

## Roles and permissions

| Action | Director / admin | Teacher | Parent |
|---|---:|---:|---:|
| List assessments in authorized campuses | yes | assigned/authorized scope | no in Slice 1 |
| Create/update assessment definition | yes | yes for own/assigned scope | no |
| Enter or update own student result | yes | yes, only students in authorized teaching scope | no |
| Review/close/void result | yes | no in Slice 1 | no |
| View result summary | yes | own/authorized students | no in Slice 1 |

Authorization must be enforced in the controller/service using
`auth_role`, `auth_user`, and `auth_campus_ids`. Client-provided `campus_id`,
`student_id`, or `student_class_id` must be resolved and checked against the
server-side student/course relationships.

## Lifecycle

Assessment definition:

```text
draft → published → closed → archived
```

Result:

```text
draft → submitted → reviewed
                 ↘ voided
```

Only a director can move a result to `reviewed` or `voided` in Slice 1. A
reviewed result is not deleted; a correction creates an audit record and keeps
the attempt identity.

## Data contract

### `assessments`

- `id`
- `campus_id`
- optional `subject_id`, `student_class_id`
- `title`, `description`
- `assessment_type` (`baseline`, `checkpoint`, `remediation`, `other`)
- `status`
- `scheduled_for`
- `max_score`, optional `passing_score`
- `created_by_user_id`, `published_at`, `closed_at`, timestamps

### `assessment_results`

- `id`, `assessment_id`, `student_id`, optional `student_class_id`
- `attempt_no` (unique per assessment/student)
- `score`, `max_score_snapshot`, `percent`
- `status`, `notes`
- `recorded_by_user_id`, optional `reviewed_by_user_id`
- `recorded_at`, `reviewed_at`, timestamps

### `assessment_audit_logs`

- `id`, `assessment_id`, optional `assessment_result_id`
- `campus_id`, optional `actor_user_id`, `action`, `reason`
- JSON `before`, JSON `after`, timestamps

The result stores `max_score_snapshot` because changing an assessment's future
maximum must not rewrite historical percentages. Percent is calculated by the
server with decimal arithmetic and bounded to 0–100.

## API shape

All endpoints are under `/api/v1`, authenticated, and campus-scoped.

- `GET /assessments`
- `POST /assessments`
- `GET /assessments/{assessment}`
- `PATCH /assessments/{assessment}`
- `POST /assessments/{assessment}/publish`
- `POST /assessments/{assessment}/close`
- `GET /assessments/{assessment}/results`
- `POST /assessments/{assessment}/results`
- `PATCH /assessment-results/{result}`
- `POST /assessment-results/{result}/review`
- `POST /assessment-results/{result}/void`
- `GET /assessment-reports/summary`

Payloads use lower-snake-case API names, while existing legacy models retain
their historical column names. Error responses use the existing JSON `message`
style and HTTP 403/404/409/422 semantics.

## Failure and recovery

- Duplicate result attempt: return 409 with the existing result identity; do
  not silently overwrite.
- Invalid score or max score: return 422; do not persist partial data.
- Unauthorized campus/student/class relationship: return 403 without revealing
  whether the foreign record exists.
- Repeated publish/close/review/void requests are idempotent when already in
  the requested terminal state, otherwise return 409.
- Result mutations happen in one transaction with the audit row. If audit
  creation fails, the result mutation rolls back.

## Migration and rollback

All Slice 1 tables are additive, indexed, and independent of legacy tables by
foreign-key-like IDs without changing legacy schema. No backfill is required.
The safe rollback is: disable the navigation/API exposure, revert the code, and
only then use the standard migration rollback if no retained assessment data is
needed. Production execution must remain through `deploy.yml`.

## Verification

- Feature tests for every lifecycle transition and authorization branch.
- SQLite-compatible migration/test path where possible; MySQL-specific index
  behavior checked by CI's existing database path.
- Frontend unit/build tests for the staff queue and entry form.
- Existing LearningRecord, attendance, billing, and parent tests remain green.
- Post-deploy read-only check: `/health`, `/version.json`, authenticated
  assessment list for a test role, and existing learning-record smoke path.
