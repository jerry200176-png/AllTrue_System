---
name: Fix Scheduling System
overview: Fix and enhance the tutoring center's scheduling system by unifying the broken evaluation flow, adding missing features (new course from CourseManagement, director edit permissions, conflict detection), and correcting billing logic for both per-session and monthly billing modes.
todos:
  - id: unify-teacher-eval
    content: Rewrite TeacherEvaluation.vue to use /api/v1/learning-records instead of Supabase evaluations table
    status: completed
  - id: unify-director-dashboard
    content: Rewrite DirectorDashboard.vue evaluation section to use /api/v1/learning-records (fetch pending, approve)
    status: completed
  - id: director-edit-perms
    content: Update LearningRecordController.php update() to allow directors to edit evaluations regardless of status
    status: completed
  - id: add-new-course-btn
    content: Add '+ 新增課程' button and modal to CourseManagement.vue with same fields as SmartCalendar quick add
    status: completed
  - id: conflict-detection-cm
    content: Add conflict detection logic to CourseManagement.vue (port from SmartCalendar)
    status: completed
  - id: extra-billing-distinction
    content: Update extra lesson modals in SmartCalendar and CourseManagement to distinguish billing type hints
    status: completed
  - id: monthly-display-fix
    content: Fix CourseManagement to show '已上堂數' for monthly billing instead of '剩餘堂數'
    status: completed
isProject: false
---

# Fix Tutoring Center Scheduling System

## Problem Analysis

After reviewing the codebase, the following issues were identified:

### Critical Bug: Dual Evaluation System (404 Errors)

There are **two separate evaluation flows** that are disconnected:

- **Flow A (Broken)**: `TeacherEvaluation.vue` writes to Supabase `evaluations` table, and `DirectorDashboard.vue` reads from it. But there is **no `/api/v1/evaluations` route** in the backend, so the Supabase proxy returns 404. Teachers cannot submit evaluations and directors cannot see pending evaluations.
- **Flow B (Working)**: `LearningRecordsPage.vue` uses `/api/v1/learning-records` (Laravel `LearningRecordController`), which works correctly including approve/reject/deduct sessions.

### Feature Gaps

1. **CourseManagement has no "Add New Course" button** -- only "Backfill" for pre-system data. Users can only add courses from SmartCalendar.
2. **Director cannot edit evaluations freely** -- backend `update()` in [LearningRecordController.php](backend/app/Http/Controllers/LearningRecordController.php) restricts editing to `rejected`/`changes_requested` status only. Director should be able to edit any evaluation.
3. **No conflict detection in CourseManagement** -- only SmartCalendar checks for teacher schedule conflicts.
4. **Extra lesson billing distinction missing** -- the UI doesn't differentiate between per-session (uses up sessions faster) and monthly (requires extra payment) when adding lessons.

### Display Issues

1. **Monthly billing shows "remaining sessions"** incorrectly -- monthly courses should show "sessions completed" (已上堂數) instead.
2. **Subject count weights are correct** (verified: 1v1=3, 1v2=1.5, 1v3=1, tutoring=1 per 2h class, /8 = subject count).

---

## Implementation Plan

### 1. Unify Evaluation Flow (Fix 404)

Rewrite `[TeacherEvaluation.vue](frontend/src/pages/TeacherEvaluation.vue)` to submit evaluations via the Laravel `learning-records` API instead of the non-existent Supabase `evaluations` table.

- Change `supabase.from('evaluations').insert(...)` to `fetch('/api/v1/learning-records', { method: 'POST', body: ... })`
- The teacher needs to submit: `StudentID`, `TeacherID`, `Subject`, `SessionDate`, `StartTime`, `EndTime`, `Content` (Progress), `Comment`, etc.
- Status will be set to `pending` automatically by the backend.

Rewrite the evaluation section in `[DirectorDashboard.vue](frontend/src/pages/DirectorDashboard.vue)` to:

- Fetch pending evaluations from `GET /api/v1/learning-records?status=pending` instead of `supabase.from('evaluations')`
- Approve evaluations via `POST /api/v1/learning-records/{id}/approve` instead of `supabase.from('evaluations').update()`
- The backend `approve()` already handles session deduction (堂數制) and UsedSessions tracking (月結制).

### 2. Director Evaluation Edit Permissions

In `[LearningRecordController.php](backend/app/Http/Controllers/LearningRecordController.php)`, modify the `update()` method:

```php
// Current (line 209): Only allows editing rejected/changes_requested
if (!in_array($learningRecord->Status, ['rejected', 'changes_requested'], true)) {
    return response()->json(['message' => 'Record is not editable'], 409);
}

// New: Directors can edit any non-approved record; teachers still restricted
if ($role === 'teacher' && !in_array($learningRecord->Status, ['rejected', 'changes_requested'], true)) {
    return response()->json(['message' => 'Record is not editable'], 409);
}
```

Also allow directors to edit `approved` records by resetting status if needed, and ensure the "backdoor approve" (補登空白評量) feature in LearningRecordsPage works correctly.

### 3. Add "New Course" to CourseManagement

In `[CourseManagement.vue](frontend/src/pages/CourseManagement.vue)`, add a "+ 新增課程" button next to the existing "補登舊資料" button. The new course modal should have the **same fields** as SmartCalendar's quick add:

- Student, Teacher, Subject, Class type (1v1/1v2/1v3/tutoring)
- First class date, Start time, Duration (default 2h), Days of week (multi-select)
- Payment type (session/monthly), Sessions purchased (for session type)
- Rate per 2h (cost proportional to time)

The form should insert into `student-classes` via the Supabase proxy, identical to SmartCalendar's `submitModal()`.

### 4. Add Conflict Detection to CourseManagement

Port the conflict check logic from SmartCalendar into CourseManagement:

- When adding or editing a course, check if the selected teacher already has overlapping courses at the same day/time
- Apply same rules: 1v1 max 1 student, 1v2 max 2, 1v3 max 3, tutoring unlimited
- Show conflict warning and disable save button when conflict detected
- Load all existing courses for the branch to check against

### 5. Fix Extra Lesson Billing Distinction

In both `[SmartCalendar.vue](frontend/src/pages/SmartCalendar.vue)` and `[CourseManagement.vue](frontend/src/pages/CourseManagement.vue)`, update the extra lesson modal:

- Current hint: "加課會消耗堂數，老師需上傳評量表"
- New logic: Check the parent course's `payment_type`
  - If `session` (堂數制): "加課會提早用完堂數，不額外收費"
  - If `monthly` (月結制): "加課需額外繳費，老師需上傳評量表"

### 6. Fix Monthly Billing Display

In `[CourseManagement.vue](frontend/src/pages/CourseManagement.vue)`, the course table shows "剩餘堂數" for all courses. For monthly billing:

- Change column to show "已上堂數" (sessions completed) instead of "剩餘堂數" for `payment_type === 'monthly'`
- The `UsedSessions` field in the backend already tracks this
- In the edit modal, hide "購買堂數" and "剩餘堂數" for monthly courses, show "已上堂數" instead

### 7. Monthly Billing: Add Session on Approval

The backend `[deductRemainingSessions()](backend/app/Http/Controllers/LearningRecordController.php)` already increments `UsedSessions` for all billing modes and only decrements `RemainingSessions` for count-based. For monthly billing, the user says sessions should be "added" after approval. The current behavior of incrementing `UsedSessions` achieves this -- we just need to ensure the frontend displays `UsedSessions` correctly for monthly courses.

---

## Files to Modify

**Frontend:**

- `frontend/src/pages/TeacherEvaluation.vue` -- rewrite to use learning-records API
- `frontend/src/pages/DirectorDashboard.vue` -- rewrite evaluation section to use learning-records API
- `frontend/src/pages/CourseManagement.vue` -- add new course button, conflict detection, billing display fixes
- `frontend/src/pages/SmartCalendar.vue` -- extra lesson billing distinction

**Backend:**

- `backend/app/Http/Controllers/LearningRecordController.php` -- director edit permissions

