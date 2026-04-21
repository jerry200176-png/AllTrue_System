---
name: multi-slot_course_display_alignment
overview: Align course-management UI and learning-record flows so same-day multi-slot sessions are counted, displayed, and edited per actual class session while preserving compatibility for legacy date-only data.
todos:
  - id: frontend-session-semantics
    content: Refactor course-management composables/pages to count and render per session row with grouped-date UI
    status: completed
  - id: frontend-learning-record-mapping
    content: Update LearningRecordsPage mappings and keys to ClassSessionID-first with legacy fallback
    status: completed
  - id: backend-fallback-hardening
    content: Adjust StudentClassController and LearningRecordController date-based helpers for multi-slot-safe behavior
    status: completed
  - id: deduction-legacy-compat
    content: Improve SessionDeductionService legacy fallback counting for date-only orphan records
    status: completed
  - id: regression-tests
    content: Add feature tests for multi-slot display, reschedule targeting, due-filter timing, and deduction accuracy
    status: completed
  - id: verify-and-deploy
    content: Run backend tests, frontend checks, then deploy frontend build
    status: completed
isProject: false
---

# Multi-Slot Session Consistency Plan

## Goal
Ensure all downstream flows match the new scheduling rule: same date with multiple time slots must behave as multiple sessions across course display, leave/reschedule actions, learning records, dashboard pending-review, and fallback APIs.

## Scope Confirmed
- Full chain update (not only scheduler).
- Keep backward compatibility for legacy date-only data.

## Impacted Areas
- Frontend course/session display and edit flows:
  - [frontend/src/composables/course-management/useCourseSessionsDisplay.js](frontend/src/composables/course-management/useCourseSessionsDisplay.js)
  - [frontend/src/pages/CourseManagement.vue](frontend/src/pages/CourseManagement.vue)
  - [frontend/src/composables/course-management/useRescheduleAndMakeup.js](frontend/src/composables/course-management/useRescheduleAndMakeup.js)
  - [frontend/src/composables/course-management/useSessionEditFlow.js](frontend/src/composables/course-management/useSessionEditFlow.js)
- Frontend learning record page mapping:
  - [frontend/src/pages/LearningRecordsPage.vue](frontend/src/pages/LearningRecordsPage.vue)
- Backend fallback/session-date and LR linkage behavior:
  - [backend/app/Http/Controllers/StudentClassController.php](backend/app/Http/Controllers/StudentClassController.php)
  - [backend/app/Http/Controllers/LearningRecordController.php](backend/app/Http/Controllers/LearningRecordController.php)
  - [backend/app/Services/SessionDeductionService.php](backend/app/Services/SessionDeductionService.php)
- Regression tests:
  - [backend/tests/Feature/ClassSessionBatchApiTest.php](backend/tests/Feature/ClassSessionBatchApiTest.php)
  - [backend/tests/Feature/LearningRecordApprovalDeductionTest.php](backend/tests/Feature/LearningRecordApprovalDeductionTest.php)
  - Add/extend feature tests for reschedule and due-filter behavior.

## Implementation Plan
1. Normalize frontend to session-row semantics (not unique-date semantics).
   - Replace date `Set`/`seenDates` counting with per-session-row counting keyed by `class_session_id` (or `date+start_time` fallback).
   - Keep grouped date display for readability, but include multiple chips/rows when same date has multiple times.
   - Update counters (`used`, `upcoming`, `remaining display`) to count rows, excluding leave/cancelled according to existing rule.

2. Fix leave/reschedule option identity.
   - Replace date-only option identity in course management flows with session identity.
   - Option label includes date + time range to disambiguate same-day slots.
   - Keep fallback for legacy rows without `id` by using stable synthetic key (`date|start|end|index`).

3. Fix learning-record page session matching.
   - Replace `classId|date` lookup and event key strategy with `ClassSessionID`-first mapping.
   - Date-only fallback remains for old records with missing/invalid `ClassSessionID`.
   - Ensure teacher form default selection and row status map choose exact session when same date has multiple slots.

4. Tighten backend fallback endpoints and LR helpers.
   - In `StudentClassController::sessionDates`, avoid collapsing to one entry per date for new flows; return backward-compatible shape plus per-date counts and/or per-session hints.
   - In `LearningRecordController` date-based helper methods (`findEffectiveClassSessionForDate`, reschedule by old/new date), add deterministic disambiguation and class-session-id-first paths where possible, while preserving old request compatibility.
   - In `SessionDeductionService`, keep `ClassSessionID` counting as source of truth; improve legacy fallback path so date-only orphan records don’t undercount multi-slot days.

5. Regression tests and verification.
   - Add feature tests for: same-day two sessions show as two actionable units; only_due filter by end-time per session; reschedule targets exact session; deduction count on two approved same-day sessions.
   - Validate UI flows manually in CourseManagement/LearningRecords pages with one student, one date, two timeslots.

## Compatibility Rules
- Prefer exact keys in this order:
  1) `ClassSessionID`
  2) `class_session_id`
  3) `session_date + start_time (+ end_time)`
  4) date-only fallback (legacy only)
- Legacy date-only data remains visible and editable, but exactness warnings/logging should be added when ambiguity exists.

## Validation Checklist
- Course list displays same-day two sessions as two units.
- Leave/reschedule UI can target both sessions independently.
- LearningRecords page does not overwrite one slot’s status with another on same day.
- Dashboard pending-review count remains based on LR rows and is unchanged/regression-safe.
- Remaining/used session numbers match actual ClassSession row count.