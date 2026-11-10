---
name: Fix scheduling system bugs
overview: "Fix 5 critical bugs: multi-day courses only showing one day on calendar, learning records page 500 error, subject units page blank, fee label text mismatch, and fee input freezing on keystroke."
todos:
  - id: fix-multiday-backend
    content: Fix backend mapFrontendPayload to handle days_of_week array and class_type->by1 mapping
    status: completed
  - id: fix-multiday-frontend
    content: Fix SmartCalendar to send single record with days_of_week, fix onCourseClick day preservation
    status: completed
  - id: fix-learning-record-model
    content: Add studentClass() relationship to LearningRecord model and fix dropdown labels
    status: completed
  - id: fix-fee-label
    content: Change fee label from '兩小時多少錢' to '一堂課費用' in SmartCalendar
    status: completed
  - id: fix-fee-input-reactivity
    content: Replace computed get/set with ref for fee input in SmartCalendar and CourseManagement
    status: completed
  - id: deploy
    content: Build frontend and deploy to backend/public
    status: completed
isProject: false
---

# Fix Scheduling System Bugs

## Bug 1: Multi-day courses (e.g. Mon+Thu) only show on Monday

**Root Cause:** When SmartCalendar creates a new course, it sends an **array of records** (one per day) via `supabase.from('student-classes').insert(records)`. The Supabase proxy sends this as `POST /api/v1/student-classes` with a JSON array. The backend's `mapFrontendPayload()` extracts only `$input[0]` (the first element), so only the Monday record gets created. The Thursday record is silently dropped.

Additionally, `mapFrontendPayload()` always hardcodes `by1 = 1`, ignoring the `class_type` from the frontend. This breaks the subject-count weighting.

**Fix (2 parts):**

1. **Frontend** ([frontend/src/pages/SmartCalendar.vue](frontend/src/pages/SmartCalendar.vue) ~line 1099-1111): Instead of creating multiple records, send a **single** record with `days_of_week` array. The backend should map `days_of_week` to the `week1`..`week6` fields:

```javascript
// Instead of creating N records, create ONE with days_of_week
const days = (modalForm.value.days_of_week || []).length > 0 
  ? modalForm.value.days_of_week 
  : [modalForm.value.day_of_week || 1];
payload.day_of_week = days[0];
payload.days_of_week = days;
payload.sessions_purchased = modalForm.value.sessions_purchased || 8;
payload.remaining_sessions = payload.sessions_purchased;
await supabase.from('student-classes').insert(payload); // single object
```

2. **Backend** ([backend/app/Http/Controllers/StudentClassController.php](backend/app/Http/Controllers/StudentClassController.php) `mapFrontendPayload()` ~line 424-461): Handle `days_of_week` array by mapping each day to `week1`..`week6` fields, and map `class_type` to `by1`:

```php
// Map days_of_week to week1..week6
if (isset($input['days_of_week']) && is_array($input['days_of_week'])) {
    for ($i = 1; $i <= 6; $i++) $mappedData["week{$i}"] = null;
    foreach ($input['days_of_week'] as $idx => $dow) {
        $field = 'week' . ($idx + 1);
        if ($idx < 6) $mappedData[$field] = (int) $dow;
    }
    $mappedData['week'] = (int) $input['days_of_week'][0];
}

// Map class_type to by1 (instead of always 1)
$classType = $input['class_type'] ?? 'one_on_one';
$by1Map = ['one_on_one' => 1, 'one_on_two' => 2, 'one_on_three' => 3, 'tutoring' => 4];
$mappedData['by1'] = $by1Map[$classType] ?? 1;
```

3. **Fix `onCourseClick`** (SmartCalendar.vue ~line 1040): When editing, preserve the original multi-day info:

```javascript
days_of_week: (baseCourse.days_of_week && baseCourse.days_of_week.length) 
  ? [...baseCourse.days_of_week] 
  : (course.day_of_week != null ? [course.day_of_week] : [1]),
```

---

## Bug 2: Learning Records page fails (500 error / no evaluation forms)

**Root Cause:** `LearningRecordController` calls `$query->with('studentClass.student')`, but `LearningRecord` model does not define a `studentClass()` relationship. This causes a 500 error.

**Fix:**

- [backend/app/Models/LearningRecord.php](backend/app/Models/LearningRecord.php): Add the missing relationship:

```php
public function studentClass()
{
    return $this->belongsTo(\App\Models\StudentClass::class, 'StudentClassID', 'ID');
}
```

- [frontend/src/pages/LearningRecordsPage.vue](frontend/src/pages/LearningRecordsPage.vue) ~line 323-324: Fix the backfill dropdown label to use API field names:

```javascript
label: `${c.student_name ?? c.student?.name ?? '?'} - ${c.subject_name ?? c.Subject ?? ''} (${c.teacher_name || '未指派'})`
```

---

## Bug 3: Subject Units page is blank

**Root Cause:** The subject-units endpoint joins `LearningRecord -> ClassSession -> StudentClass -> Student`. If there are zero **approved** learning records for the selected month/campus, the result is an empty array. Since no evaluations have been approved (Bug 2 was preventing the whole flow), the page shows blank. Fixing Bug 2 unblocks the evaluation pipeline. However, the page should also show a meaningful "no data" message instead of being completely blank.

**Fix:** After fixing Bug 2, ensure the `SubjectUnitsPage.vue` shows a clear "no approved records" notice. Also verify the API endpoint returns valid JSON even when empty (it does -- `{ "teachers": [], "totals": {...} }`). No backend change needed.

---

## Bug 4: Fee label should say "一堂課費用"

**Root Cause:** SmartCalendar uses "兩小時多少錢 ($)" as the label. Since duration is configurable (not always 2 hours), the label should be "一堂課費用" to match CourseManagement's convention.

**Fix in** [frontend/src/pages/SmartCalendar.vue](frontend/src/pages/SmartCalendar.vue):
- Line 180: Change `何時／多久／兩小時多少錢` to `何時／多久／費用`
- Line 260: Change `兩小時多少錢 ($)` to `一堂課費用 ($)`

---

## Bug 5: Fee input freezes when typing "1500"

**Root Cause:** `ratePer2h` is a computed with get/set that round-trips through `rate_per_30min`. With `v-model.number`, every keystroke triggers: user types "1" -> setter(1) -> rate_per_30min=0 -> getter returns 0 -> input resets to 0. The value jumps unpredictably during typing.

**Fix:** Replace the computed getter/setter with a plain `ref` for the input. Only sync to `rate_per_30min` on blur or before submit.

In [frontend/src/pages/SmartCalendar.vue](frontend/src/pages/SmartCalendar.vue):

```javascript
const ratePer2hInput = ref(2000);

// On modal open, initialize: ratePer2hInput.value = (modalForm.value.rate_per_30min ?? 0) * 4;
// On blur or submit, sync back: modalForm.value.rate_per_30min = Math.round(ratePer2hInput.value / 4);
```

Same fix needed in [frontend/src/pages/CourseManagement.vue](frontend/src/pages/CourseManagement.vue) for `addCourseRatePer2h` and `ratePer2hEdit` computed properties.

---

## Summary of files to modify

- **[backend/app/Models/LearningRecord.php](backend/app/Models/LearningRecord.php)** -- add `studentClass()` relationship
- **[backend/app/Http/Controllers/StudentClassController.php](backend/app/Http/Controllers/StudentClassController.php)** -- handle `days_of_week` array and `class_type -> by1` mapping in `mapFrontendPayload()`
- **[frontend/src/pages/SmartCalendar.vue](frontend/src/pages/SmartCalendar.vue)** -- fix multi-day creation (single record), fix `onCourseClick` days preservation, fix fee label, fix fee input reactivity
- **[frontend/src/pages/CourseManagement.vue](frontend/src/pages/CourseManagement.vue)** -- fix fee input reactivity for add/edit modals
- **[frontend/src/pages/LearningRecordsPage.vue](frontend/src/pages/LearningRecordsPage.vue)** -- fix backfill dropdown label field names

After all edits: `npm run deploy` to build and copy assets.
