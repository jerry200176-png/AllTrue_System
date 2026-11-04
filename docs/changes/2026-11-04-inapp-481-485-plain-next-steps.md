## 2026-11-04 — fix: say which lesson, which blocker, and what to do (in-app #481–#485)
<!-- release-notes: staff_update=staff-2026-11-04-inapp-481-485-plain-next-steps -->
- #482 (#4855): part-time payroll notice rows carry `start_time`/`end_time`, `student_name`, `subject_name` and `student_class_id`; the page shows `14:00–16:00　student・subject` instead of `堂次 #id`.
- #483 (#4856): course delete refusal returns `code: course_has_history` with `reasons[]` from `StudentClass::operationalHistoryReasons()` and names them in the message; `hasOperationalHistory()` is now a thin wrapper (same checks, first hit stops).
- #481 (#4854): the course page review panel lists the same per-reason next step as 帳務中心 (`REVIEW_REASON_FIX`) and no longer offers the POP-only 預覽分期更正 there (the 帳務 tab button stays).
- #485 (#4858): 續課 dialog (course page and students page) says unfinished lessons stay and the source only settles when paid and fully used, as pinned by `PurchaseBatchClosesSourceTest`.
- #484 (#4857): no code; a super admin reopens the course with a reason.
