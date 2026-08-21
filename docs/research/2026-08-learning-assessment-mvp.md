# Learning Assessment MVP research record

**Date:** 2026-08-21 (Asia/Taipei; Slice 3 update)
**Decision:** how to add structured learning checks to AllTrue without changing
the existing attendance, LearningRecord approval, or billing contracts.
**Target:** AllTrue System (`jerry200176-png/AllTrue_System`)
**Risk:** R2 — additive schema, role-scoped student data, and a new product
workflow; no production data repair and no change to session deduction.

## Scope and success criteria

The first release is a staff-facing assessment register and result ledger. It
supports a teacher or director recording a named check, entering a student's
score, and viewing progress by assessment and student. It does not yet
replace paper exams, add a student quiz runner, or send results to parents.

Success means:

- a result can be created without creating or changing `ClassSession`,
  `StudentSingIn`, or `LearningRecord` rows;
- every read and write is restricted by the existing authenticated campus
  scope and role rules;
- score history is append-audited before result changes;
- the same student can have multiple attempts without overwriting a previous
  attempt;
- a director can see assessment-level completion and score summaries;
- the feature can be disabled or reverted without changing existing records.

## Local system evidence

The current AllTrue workflow is already a strong operational spine:

```text
ClassSession → attendance → LearningRecord → director approval → parent feedback
```

`LearningRecord` is a post-lesson report tied to a `StudentClassID` and
`ClassSessionID`. Its `QuizScore` is a text field alongside `Progress`,
`HomeworkStatus`, and narrative comments; it is not a normalized attempt or
question-result model. Approval also synchronizes attendance and session
deduction through the existing approval pipeline. Therefore an assessment
result must be a separate bounded context and must not be encoded as another
LearningRecord or as a special attendance state.

Relevant local sources:

- [`README.md`](../../README.md): Vue 3 + Laravel 8 + MySQL, four campuses,
  existing parent/teacher/director workflows.
- [`backend/app/Models/LearningRecord.php`](../../backend/app/Models/LearningRecord.php):
  current post-lesson record and status semantics.
- [`backend/routes/api.php`](../../backend/routes/api.php): existing role and
  `require_campus` route boundaries.
- [`docs/MODULE_LEARNING_RECORD_CROSS_ROLE_UX.md`](../MODULE_LEARNING_RECORD_CROSS_ROLE_UX.md):
  current role separation problems and the rule that approved LearningRecord
  remains the parent-facing learning update.
- [`docs/TECH_DEBT.md`](../TECH_DEBT.md): TD-074 records that LearningRecord
  content has no complete version history; the new ledger therefore starts
  with an explicit audit table instead of extending that debt.

## Official and live-product evidence

No private or authenticated product behavior was used. Public documentation was
reviewed on 2026-08-20:

- Moodle's official documentation describes a reusable question bank, multiple
  attempts, automatic/manual grading, and review of individual responses:
  [Quiz activity](https://docs.moodle.org/402/en/Quiz),
  [Question bank](https://docs.moodle.org/405/en/mod/quiz/question), and
  [Quiz reports](https://docs.moodle.org/502/en/mod/quiz/grading).
- Gibbon's documentation describes rubric-based feedback reusable from
  Markbook/Formal Assessment:
  [Creating Rubrics](https://docs.gibbonedu.org/guides/modules/rubrics/rubrics).

These are documented product capabilities, not claims about AllTrue's current
runtime.

## Maintained open-source evidence

The following repositories were checked through GitHub API on 2026-08-20. The
SHAs pin the inspected source state; no code was copied.

| Project | Version / commit | License | Evidence inspected | Fit and limit |
|---|---|---|---|---|
| [Moodle](https://github.com/moodle/moodle) | `6216fe4ed19a5a3c88c0951d1647e9f2d626bcbb` (`main`) | GPL-3.0 | `mod/quiz/locallib.php`, `mod/quiz/tests/attempt_test.php`, quiz reports and question-bank docs | Best reference for immutable question usage, attempt state, regrade, and per-question analysis. Too large and too LMS-shaped to embed in AllTrue. |
| [Gibbon](https://github.com/GibbonEdu/core) | `80f2d3c52e40b02ca6eedca3dea7a6083a98eca8` (`v31.0.00`) | GPL-3.0 | `modules/Markbook/markbook_edit.php`, `markbook_view.php`, `modules/Formal Assessment/internalAssessment_write_dataProcess.php` | Best reference for class-scoped columns, teacher edit authorization, rubric/descriptor results, and bulk entry. Its PHP page architecture is not compatible with AllTrue's API/Vue boundary. |
| [Open edX](https://github.com/openedx/edx-platform) | `91172547cac10c4eb95c5a3e1d3e5d5cbf063f52` (`master`) | AGPL-3.0 | `lms/djangoapps/grades/models.py`, `grades/services.py`, `grades/tests/` | Useful reference for derived grade aggregation and service boundaries. Its distributed courseware and grading model are out of scope for the first slice. |

The repository metadata showed Moodle, Gibbon, and Open edX actively receiving
changes at the time of review. License review is a reason to adapt principles,
not copy source or add a dependency.

## Patterns adopted

1. **Stable assessment definition plus separate attempt/result rows.** This
   permits repeated checks and preserves history, following Moodle's attempt
   model rather than overwriting a single grade.
2. **Result snapshots.** Store the score and maximum score used at the time of
   entry. A later assessment edit must not silently reinterpret an old result.
3. **Structured score now, question bank later.** The first slice handles the
   real paper-test workflow with manual score entry. Slice 3 now adds a narrow
   staff-mediated runner without changing the paper-result path.
4. **Class/campus authorization before UI filtering.** Gibbon's edit boundary
   is useful, but AllTrue must enforce it in Laravel using the existing
   `auth_campus_ids` attributes; Vue filters are not security controls.
5. **Audit before mutation.** Assessment result changes are append-audited with
   actor, action, before, after, and reason. This directly addresses the local
   LearningRecord history gap without pretending to repair TD-074.

## Patterns rejected

- Importing Moodle or Gibbon as a subsystem: license, deployment, data-model,
  and operational blast radius are unjustified for this increment.
- Treating `QuizScore` as the new source of truth: it is untyped text and is
  coupled to the post-lesson report.
- Making a test result approve attendance or deduct sessions: an assessment
  may be diagnostic, take place outside a billed lesson, or be entered later.
- Building adaptive AI recommendations before the score and remediation data
  are trustworthy.

## Adaptation and next slices

### Slice 1 — assessment/result ledger (this task)

- `assessments`: campus-scoped definition and lifecycle.
- `assessment_results`: student attempts with normalized score fields.
- `assessment_audit_logs`: append-only before/after evidence for definitions and
  results.
- Staff API and minimal director/teacher workflow.

### Slice 2 — knowledge tags and remediation

- Reusable chapter/skill tags attached to an assessment/result.
- Remediation task with owner, due date, completion, and linked follow-up
  assessment.

### Slice 3 — digital question bank and student attempts

- Versioned questions, assessment question snapshots, answer rows, automatic
  marking for deterministic types, and manual review for free text.

Slice 3 adaptation decision (2026-08-21): the current AllTrue API has
teacher/director authentication and campus/class ownership, but no established
student login contract for this workflow. The first online runner is therefore
staff-mediated: an authorized teacher or director opens an attempt for a
student, records answers, and submits it. A later student-facing runner must
reuse the same attempt tables after product ownership, session expiry, and
parent visibility are explicitly specified.

The scoring boundary follows Moodle's documented separation between reusable
question-bank content, deferred submission, automatic grading, and a manual
grading queue for essay/free-text responses ([essay question type](https://docs.moodle.org/en/Essay_question_type),
[manual grading report](https://docs.moodle.org/501/en/Quiz_manual_grading_report)).
Assessment snapshots retain the approved question version and correct answer
server-side; normal payloads redact the correct answer, and any attempt makes
the assessment's question set immutable. This is an adaptation of the
workflow pattern, not a dependency or source-code copy.

### Slice 4 — parent-facing progress

- Only reviewed/published results appear in the parent portal, with plain
  language and explicit status; internal audit, drafts, and teacher notes stay
  private.

## Security, privacy, and operations

- All rows are campus-scoped; server-side authorization is mandatory on every
  endpoint.
- Student results are PII-linked educational records. Avoid names or scores in
  logs, exports, test fixtures, and public issue bodies.
- No new external service or dependency is required for Slice 1.
- Migration is additive and reversible before real production data exists;
  rollback is a code revert plus migration rollback according to the existing
  deploy contract, never a manual production migration.
- The feature does not alter attendance, LearningRecord approval, billing, or
  parent output in Slice 1.

## Acceptance and telemetry

- Feature tests cover campus isolation, teacher/director permissions, repeated
  attempts, score normalization, invalid transitions, and audit creation.
- Frontend tests cover empty/loading/error states and mobile-safe entry.
- Product follow-up metrics: assessments created per campus, result completion
  rate, median time from assessment creation to first result, and percentage of
  results with a follow-up remediation task once Slice 2 exists.

## Unknowns intentionally deferred

- The first subject and exact paper-test import format.
- Whether parents should see raw scores or only level/feedback summaries.
- Which knowledge-tag taxonomy the teaching team will maintain.
- Whether online student attempts are needed after the manual-result slice.
