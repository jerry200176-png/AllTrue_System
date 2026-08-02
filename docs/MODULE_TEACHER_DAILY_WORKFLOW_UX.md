# Teacher Daily Workflow UX Spec

Issue: #1618
Bounded context: teacher home and attendance
Status: implemented in PR #1619; this document records the design, constraints, and evidence.

## Problem and root cause

TeacherHomePage previously loaded and displayed overlapping task centers, KPI cards, analytics, progress, ranking, and navigation blocks. A teacher had to interpret several competing lists before knowing the next action. AttendancePage similarly started with general statistics rather than the operational next step.

This created duplicated counts, competing CTAs, mobile cognitive load, and a risk that a leave-related state reappeared in a work list.

## Research basis

- Microsoft Teams Assignments groups work by actionable status (`Upcoming`, `Ready to grade`, `Past due`, `Returned`, `Draft`) and opens directly into the next work item.
- Google Classroom To-do separates `To review` from `Reviewed`; grading keeps draft and returned states explicit.
- GitHub Primer ActionList and Button favor a single-column list with a concise description and one primary action.
- Gibbon Markbook/Attendance filters by role and course scope before showing operational rows. Chatwoot, Vben Admin, and Filament use stable headers and details-on-demand rather than a dashboard of unrelated widgets.
- AllTrue's `RULE_DESIGN_SYSTEM.md`, `AI_REGRESSION_LESSONS.md`, and `GUIDE_DESIGN_QA_SMOKE.md` require token-based visual hierarchy, one primary action per group, and consistent leave semantics.

Sources:

- [Microsoft Teams educator assignments](https://support.microsoft.com/en-us/education/view-and-navigate-your-assignments-educator)
- [Microsoft Teams assignments and grades](https://support.microsoft.com/en-us/education/assignments/assignments-and-grades-in-your-class-team)
- [Google Classroom teacher To-do](https://support.google.com/edu/classroom/answer/9849192?hl=en)
- [Google Classroom grading and feedback](https://support.google.com/edu/classroom/answer/16643267?hl=en)
- [Primer ActionList](https://primer.style/product/components/action-list/)
- [Primer Button](https://primer.style/product/components/button/)

## Design decision

The first answer on TeacherHomePage is now **「今天要完成」**: a single task queue. Each task has type, state, student/course/time context, deadline, and exactly one action:

| Work | Primary action |
| --- | --- |
| Pending attendance | 開始點名 |
| Missing learning record | 填寫評量 |
| Changes requested | 修改評量 |
| Parent reply | 查看並回覆 |

The pure `teacherTask` shape is:

`id / type / severity / title / summary / count / owner / dueAt / actionLabel / target / source`

Sorting is `changes requested / overdue → today incomplete → feedback`, then time. Learning work deduplicates by record/session identity. The leave family `leave`, `leave_requested`, `leave_adjusted`, and `excused` is never added to the attendance or learning task queues.

Progress, feedback analytics, rank, SystemTrust, and chat unread are on the secondary disclosure and do not block the priority queue. The weekly schedule remains below the queue as context for the teacher's next class.

AttendancePage replaces the teacher's four-card KPI entry with a concise **「先完成今日點名」** snapshot and a direct mobile-safe CTA. Existing status choice, explicit confirm, batch behavior, discrepancy reporting, permissions, and attendance/charge contracts remain unchanged.

## Architecture and boundaries

- No database, API, authorization, attendance, leave, or learning-record contract changed.
- Task conversion and leave exclusion live in `frontend/src/lib/teacherDailyWorkflow.js`, with pure regression tests.
- Priority requests load the task queue, pending/overdue data, weekly context, and clock-in state. Secondary analytics load only after expanding the secondary panel.
- Existing backend scope remains authoritative for branch access, effective teacher/substitute rules, and derived attendance/charge behavior.

## Acceptance evidence

- Pure task tests cover sorting, dedupe, CTA mapping, empty results, and leave-request exclusion.
- Real Vue Playwright covers 390, 412, and 1280px; normal, empty, schedule API error, teacher attendance mobile CTA, deferred analytics, and no unexpected horizontal overflow.
- Tests derive mock session dates from the browser's local date, preventing CI timezone drift.
- `lint:no-undef`, design token guard, Vite build, UI foundation evidence, production UI smoke, and required PR gates passed for PR #1619.

## Rollback

Revert the merge commit for PR #1619. There is no schema migration or data backfill.
