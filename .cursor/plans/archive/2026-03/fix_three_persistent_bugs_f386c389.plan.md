---
name: Fix three persistent bugs
overview: "Fix three interrelated bugs: (1) SmartCalendar showing courses beyond last session date, (2) deductRemainingSessions increasing instead of decreasing remaining sessions, (3) pending evaluations not appearing in the overview dashboard."
todos:
  - id: fix-calendar
    content: "SmartCalendar getCoursesAt: exclude session-count courses when lastDate is null (purchased > 0 but no computable end date)"
    status: completed
  - id: fix-deduct
    content: "deductRemainingSessions: treat null/empty currentRemaining as 0 for safeguard, merge duplicate branches"
    status: completed
  - id: fix-overview
    content: "Dashboard pending evaluations: backend support per_page param, frontend request per_page=200"
    status: completed
  - id: cleanup-deploy
    content: Remove debug instrumentation from all files and redeploy
    status: completed
isProject: false
---

# Fix Three Persistent Bugs

## Bug 1: SmartCalendar shows courses beyond last session date

**Root cause:** In `getCoursesAt` ([SmartCalendar.vue](frontend/src/pages/SmartCalendar.vue) ~line 1130), when `lastDate` is `null` (cannot be computed), the course is **not excluded** — it passes through and appears on every matching slot. This happens when:

- The API (`sessionDatesByCourseId`) has no data for that course
- `courseLastSessionDate` returns `null` (e.g. missing `days_of_week`, `first_class_date`, or not recognized as session-mode)
- `getSessionDateSetForCourse` returns `null`

Additionally, `filteredCourses` (week merge loop) has a similar gap: when `sessionSet` is null and `purchased > 0`, it does `continue` (skips), but when `sessionSet` is null and `purchased <= 0`, it falls through to the legacy isRecurringDay path which may not correctly enforce end dates.

**Fix strategy:**

- In `getCoursesAt`: when `lastDate == null` AND the course has `purchased > 0` (session-count), **exclude it** rather than showing it everywhere. A session-count course without a computable end date should not appear.
- In `filteredCourses` merge loop: same logic — if `sessionSet` is null and `purchased > 0`, already handled by `continue`. Verify this is correct.
- In `dayFilteredCourses`: same check — already has `if (purchased > 0) return;` when sessionSet is null. This is correct.

Key change in `getCoursesAt` (~line 1140):

```javascript
if (lastDate != null && targetYmd > lastDate) return false;
// ADD: session-count course with unknown lastDate should not appear
const purchased = Math.max(0, parseInt(c.sessions_purchased ?? c.SessionCount ?? 0, 10) || 0);
if (lastDate == null && purchased > 0) return false;
```

---

## Bug 2: deductRemainingSessions increases remaining sessions

**Root cause:** In `deductRemainingSessions` ([LearningRecordController.php](backend/app/Http/Controllers/LearningRecordController.php) ~line 444), the safeguard that prevents `newRemaining > currentRemaining` only runs when `currentRemaining !== null && currentRemaining !== ''`. When `RemainingSessions` is `null` or `''` in the database (common for older records), the safeguard is bypassed and `newRemaining = purchased - approvedCount` can be a large number (e.g. 17), appearing as an "increase" from the displayed 0.

**Fix strategy:** Treat `null`/empty `currentRemaining` as `0` for the safeguard check:

```php
$effectiveCurrent = ($currentRemaining !== null && $currentRemaining !== '')
    ? (int) $currentRemaining
    : 0;
$newRemaining = max(0, $purchased - $approvedCount);
if ($newRemaining > $effectiveCurrent) {
    $newRemaining = max(0, $effectiveCurrent - 1);
}
```

This ensures remaining sessions can **never increase** after approving/backdoor-approving, regardless of the initial DB value.

Also: the `elseif ($purchased > 0)` branch duplicates the same logic as the `$scheduleMode === 'count'` branch. Simplify into one condition: `if ($scheduleMode === 'count' || $purchased > 0)`.

---

## Bug 3: Pending evaluations not appearing in overview dashboard

**Root cause:** The backend paginates results with `paginate(20)` (line 66 of LearningRecordController), returning only the first 20 records sorted by `id desc`. The dashboard never requests subsequent pages. If there are more than 20 records matching `status=pending,changes_requested`, older ones are hidden.

More critically: the dashboard only fetches page 1 of 20. For a branch with many records, pending evaluations may not appear at all if they have lower IDs.

**Fix strategy:**

- For the dashboard overview call, increase the per_page or use a dedicated count + recent records approach.
- Simplest fix: the frontend should pass `per_page=100` (or a reasonable limit) when fetching pending evaluations for the dashboard, since this is a summary view that needs all pending items.
- Backend already respects `per_page` parameter? Check... No, it hardcodes `paginate(20)`. Fix: use `$request->input('per_page', 20)` with a reasonable max.

Changes:

- Backend `LearningRecordController::index`: replace `paginate(20)` with `paginate(min((int) $request->input('per_page', 20), 200))`.
- Frontend `DirectorDashboard.vue`: add `per_page=200` to the `lrParams` for the pending evaluations API call.

---

## Files to modify

- `frontend/src/pages/SmartCalendar.vue` — `getCoursesAt` function
- `backend/app/Http/Controllers/LearningRecordController.php` — `deductRemainingSessions` method and `index` method pagination
- `frontend/src/pages/DirectorDashboard.vue` — add `per_page` param to pending evaluations fetch

## Post-fix

- Remove accumulated debug instrumentation (fetch calls to debug-log endpoint and PHP file_put_contents to debug log) after verification
- Redeploy frontend with `npm run deploy`

