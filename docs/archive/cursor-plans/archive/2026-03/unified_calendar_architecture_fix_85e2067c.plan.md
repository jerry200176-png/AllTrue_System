---
name: Unified Calendar Architecture Fix
overview: "Fix the core architectural problem: Supabase and MySQL have parallel, unsynchronized `schedules` tables, causing CourseManagement, DirectorDashboard, and the evaluation system to show stale/wrong data. Unify session date computation, fix today's schedule generation, make LearningRecord creation incremental, and sync remaining_sessions back to Supabase after approval."
todos:
  - id: shared-util
    content: Create frontend/src/lib/sessionDates.js with computeSessionDatesForCourse() extracted from SmartCalendar
    status: completed
  - id: fix-cm
    content: Rewrite CourseManagement.vue to use shared utility + Supabase exceptions instead of session-dates API
    status: completed
  - id: fix-dd
    content: Rewrite DirectorDashboard ensureSchedulesFromStudentClasses to use shared utility; remove unpaid courses section
    status: completed
  - id: fix-sync
    content: "Update sync endpoint: accept exceptions in payload, make LR creation incremental"
    status: completed
  - id: fix-sync-frontend
    content: Update SmartCalendar + DirectorDashboard sync calls to include exceptions in payload
    status: completed
  - id: fix-remaining
    content: After evaluation approval (LearningRecordsPage + DirectorDashboard), sync remaining_sessions back to Supabase
    status: completed
  - id: fix-smartcal
    content: "SmartCalendar: import shared utility, replace inline getSessionDateSetForCourse"
    status: completed
  - id: build-deploy
    content: Build frontend and deploy
    status: completed
isProject: false
---

# Unified Calendar Architecture Fix

## Root Cause: Two Unsynchronized Data Systems

```mermaid
flowchart LR
  subgraph frontend [Frontend writes to Supabase]
    SC["SmartCalendar"]
    SC -->|"insert leave/reschedule/extra"| SupaSched["Supabase schedules"]
    SC -->|"insert/update courses"| SupaCourses["Supabase student-classes"]
  end
  subgraph backend [Backend reads from MySQL]
    API["session-dates API"] -->|"reads"| MySQLSched["MySQL schedules (EMPTY)"]
    Sync["sync endpoint"] -->|"reads"| MySQLSched
    LR["LearningRecord approve"] -->|"updates"| MySQLSC["MySQL StudentClass"]
  end
  subgraph consumers [Pages read from wrong source]
    CM["CourseManagement"] -->|"calls"| API
    DD["DirectorDashboard"] -->|"reads"| SupaSched
    DD -->|"generates schedules ignoring exceptions"| SupaSched
  end
```



**The problem**: SmartCalendar writes reschedules/leaves to **Supabase** `schedules`, but CourseManagement reads session dates from **MySQL** `schedules` (via the backend API). MySQL `schedules` is empty/stale, so CourseManagement shows wrong dates and the sync creates wrong ClassSessions/LearningRecords.

## Target Architecture

```mermaid
flowchart LR
  subgraph truth [Single Source of Truth: Supabase]
    SupaCourses["student-classes"]
    SupaSched["schedules (leave/reschedule/extra)"]
  end
  subgraph shared [Shared Local Computation]
    Util["sessionDates.js utility"]
    SupaCourses --> Util
    SupaSched --> Util
  end
  subgraph pages [All pages use same computation]
    SC2["SmartCalendar"] --> Util
    CM2["CourseManagement"] --> Util
    DD2["DirectorDashboard"] --> Util
  end
  subgraph backendSync [Backend Sync: receives exceptions]
    SyncEP["sync endpoint"] -->|"receives courses + exceptions"| MySQL2["MySQL ClassSession + LearningRecord"]
    SyncEP -->|"incremental LR creation"| MySQL2
  end
```



---

## Changes

### 1. Extract shared session-date utility

Create [frontend/src/lib/sessionDates.js](frontend/src/lib/sessionDates.js) with the core `computeSessionDatesForCourse(course, exceptions)` function, extracted from SmartCalendar's `getSessionDateSetForCourse`. Both SmartCalendar and CourseManagement will import this.

Key logic (already exists in SmartCalendar lines 994-1069):

- Input: course object (`first_class_date`, `days_of_week`, `sessions_purchased`) + exceptions array
- Build `leaveDates` (leave + rescheduled) and `extraDates` (scheduled) from exceptions matching `student_course_id`
- Iterate from `first_class_date`, count regular days minus leaves plus extras, stop at `sessions_purchased`
- Return `Set<string>` of YYYY-MM-DD dates

### 2. Fix CourseManagement to use shared computation

File: [frontend/src/pages/CourseManagement.vue](frontend/src/pages/CourseManagement.vue)

- **Remove** the call to `/api/v1/student-classes/session-dates` (`loadSessionDates` at line 868)
- **Add** a Supabase query for `schedules` (exceptions) for the branch, same as SmartCalendar does
- **Replace** `getSessionDatesForRow(c)` (line 676) to use the shared `computeSessionDatesForCourse()` with loaded exceptions
- **Remove** `computeEstimatedSessionDates()` (line 512) since the shared utility handles this
- This ensures CourseManagement shows the exact same dates as SmartCalendar

### 3. Fix DirectorDashboard today's schedules

File: [frontend/src/pages/DirectorDashboard.vue](frontend/src/pages/DirectorDashboard.vue)

- **Rewrite `ensureSchedulesFromStudentClasses`** (line 193) to use the shared utility:
  - Import `computeSessionDatesForCourse`
  - Load exceptions from Supabase `schedules` for the branch
  - For each course, compute the full session date set
  - Only generate `schedules` entries for dates in the set (respecting leave/reschedule)
  - This prevents generating schedules for dates that are on leave or beyond session limit
- **Remove the "未繳費課程" section** (template lines 72-79, data loading lines 337-359, `unpaidCourses` ref)
- Keep "繳費提醒" as-is (from `/v1/alerts/tuition`)

### 4. Fix sync endpoint: pass exceptions, incremental LR creation

File: [backend/app/Http/Controllers/StudentClassController.php](backend/app/Http/Controllers/StudentClassController.php)

**Frontend change** (SmartCalendar + DirectorDashboard sync calls):

- Include exceptions in the sync payload: `{ courses: [...], exceptions: [...] }`
- Each exception: `{ student_course_id, schedule_date, status }`

**Backend change** (sync method, line 607):

- Accept `exceptions` from request, build per-course `leaveSet`/`scheduledSet` from them instead of MySQL `Schedule`
- **Make LR creation incremental**: instead of `if (!$hasSession)`, compare existing `ClassSession` dates with computed dates, create missing ones
- For each new `ClassSession` with `SessionDate <= today`, create a pending `LearningRecord`
- This ensures new session dates (from passing time) get LearningRecords created on each sync

### 5. Sync remaining_sessions back to Supabase after approval

File: [frontend/src/pages/LearningRecordsPage.vue](frontend/src/pages/LearningRecordsPage.vue) and [frontend/src/pages/DirectorDashboard.vue](frontend/src/pages/DirectorDashboard.vue)

After successfully calling the approve API:

- Fetch the updated `StudentClass` from MySQL via API to get `RemainingSessions`
- Update Supabase `student-classes.remaining_sessions` for the corresponding course
- This closes the loop: approval decreases MySQL remaining -> syncs back to Supabase -> CourseManagement shows correct count

### 6. SmartCalendar: use shared utility

File: [frontend/src/pages/SmartCalendar.vue](frontend/src/pages/SmartCalendar.vue)

- Import `computeSessionDatesForCourse` from shared utility
- Replace inline `getSessionDateSetForCourse` to delegate to shared utility (keeping the `sessionDatesSetByCourseId` cache layer)
- No behavior change, just deduplication

---

## Data Flow After Fix

```mermaid
sequenceDiagram
  participant User
  participant SmartCal as SmartCalendar
  participant Supa as Supabase
  participant CM as CourseManagement
  participant DD as DirectorDashboard
  participant Backend as Laravel API
  participant MySQL

  User->>SmartCal: Reschedule course
  SmartCal->>Supa: Insert rescheduled + scheduled
  SmartCal->>SmartCal: loadCourses() -> recompute dates
  SmartCal->>Backend: sync(courses + exceptions)
  Backend->>MySQL: Upsert StudentClass + incremental ClassSession/LR

  User->>CM: View CourseManagement
  CM->>Supa: Load courses + exceptions
  CM->>CM: computeSessionDatesForCourse() [same as SmartCal]

  User->>DD: View Dashboard
  DD->>Supa: Load courses + exceptions
  DD->>DD: computeSessionDatesForCourse() for today check
  DD->>Supa: Ensure today's schedule exists
  DD->>Backend: sync(courses + exceptions)
  DD->>Backend: GET learning-records (pending)

  User->>Backend: Approve evaluation
  Backend->>MySQL: deductRemainingSessions
  Backend-->>User: Return updated record
  User->>Supa: Update remaining_sessions
```



