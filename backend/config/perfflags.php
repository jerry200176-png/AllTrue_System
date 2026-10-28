<?php

/*
|--------------------------------------------------------------------------
| Performance Feature Flags
|--------------------------------------------------------------------------
|
| Controls for gradual rollout and instant rollback of perf optimizations.
| Override via .env or directly in this file. All flags default to the
| optimized (new) behavior. Set to false/old value to revert.
|
*/

return [
    // #410 / #4210: a leave whose make-up lesson lands in a pre-created renewal shifts the renewal after a director's
    // preview and confirmation. On by default now that the #4210 pre-enable checklist is done on main.
    // Founder 2026-10-28: 「好」 to 「開放 410/433「請假時續約課自動往後排」給主任用（開之前會先顯示哪些課會移、主任確認後才改）」.
    // Rollback: LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED=false refuses every apply again (403). Shifts already applied stay,
    // and the undo-leave guard for them does not depend on this flag.
    'leave_successor_shift_apply_enabled' => (bool) env('LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED', true),
    // Throttle notification sync to once per 5 min per branch.
    // false = always sync on every unread-count request (old behavior)
    'throttle_notification_sync' => (bool) env('PERF_THROTTLE_NOTIF_SYNC', true),

    // Sync cooldown in seconds (only used if throttle_notification_sync = true)
    'notification_sync_cooldown_seconds' => (int) env('PERF_NOTIF_SYNC_COOLDOWN', 300),

    // Learning records default per_page (old: 200, new: 50)
    'learning_records_default_per_page' => (int) env('PERF_LR_DEFAULT_PER_PAGE', 50),

    // Max per_page cap for learning records (safety limit)
    'learning_records_max_per_page' => (int) env('PERF_LR_MAX_PER_PAGE', 200),

    // Default time window (in days) for initial LearningRecords fetch when caller omits start_date.
    // Prevents the page from having to render years of historical records as the cram school ages.
    // 0 = disabled (old behavior: no default window). Recommended: 90 (≈ one typical course cycle).
    'learning_records_default_window_days' => (int) env('PERF_LR_DEFAULT_WINDOW_DAYS', 90),

    // Course packages (multi-subject shared session pool).
    // false = feature hidden, package ledger hooks are no-ops.
    'course_packages_enabled' => (bool) env('PERF_COURSE_PACKAGES', true),

    // Log session-count mismatch diagnostics (course_id, purchased, status breakdown).
    // Only fires when effective != purchased. Default off to avoid Pi I/O noise.
    'log_session_count_mismatch' => (bool) env('PERF_LOG_SESSION_MISMATCH', false),

    // Learning-record feedback/reply push notifications (學習回饋接推播).
    // DARK LAUNCH: default OFF. When false, FeedbackPushNotifier is a complete no-op
    // (no in-app Notification rows, no parent LINE push) — production behaviour unchanged.
    // Flip to true (PERF_FEEDBACK_PUSH=true) only after notification cadence/opt-out copy is confirmed.
    'feedback_push_enabled' => (bool) env('PERF_FEEDBACK_PUSH', false),

    // Merge window (seconds): within this window, repeated feedback/reply events on the same
    // thread+direction are merged into a single push (anti-notification-fatigue). Default 600 = 10 min.
    'feedback_push_merge_window_seconds' => (int) env('PERF_FEEDBACK_PUSH_MERGE_WINDOW', 600),

    // Actual-duration billing (RFC_NONSTANDARD_SESSION_DURATION_BILLING).
    // DARK LAUNCH: default OFF. This is the environment-level "does this feature exist
    // here" switch; StudentClass.deduction_basis is the per-course "does this course
    // use it" switch. Both must be true for a session to consume minutes by its actual
    // clock duration.
    //
    // Fail-safe, not fail-open: while this is false, a course already marked
    // actual_duration still behaves exactly like fixed_session, so turning the flag
    // back off is a complete rollback with no data migration.
    'actual_duration_deduction_enabled' => (bool) env('PERF_ACTUAL_DURATION_DEDUCTION', false),

    // Pilot boundary for that feature (Founder GO 2026-10-13, #3834): the campuses where a course may NEWLY opt in
    // to deduction_basis=actual_duration. "11,13" = 新莊分校 + 新莊中平分校 (campus ids as in frontend useBranches),
    // "*" = every campus, "" = none. An unknown campus (0) never passes. Creating or editing a course at any other
    // campus answers 422. Courses that already are actual_duration are not touched by this list, so deploying it
    // only narrows what can be created and changes no balance.
    'actual_duration_campus_ids' => env('PERF_ACTUAL_DURATION_CAMPUS_IDS', '11,13'),

    // Multi-guardian (Student 1:N). Flag gates staff UX + Portal canonical authZ
    // (guardians/student_guardians). Dual-write runs whenever tables exist.
    // Flag off ⇒ Portal/LINE auth falls back to verified SLB + legacy parent_phone.
    // Rollback: PERF_MULTI_GUARDIAN=false (tables retained; login still works).
    'multi_guardian_enabled' => (bool) env('PERF_MULTI_GUARDIAN', false),

    // Admissions funnel V1: public inquiry + director trial handoff.
    // DARK LAUNCH: tables may exist while both public and staff routes stay off.
    'admissions_funnel_v1' => (bool) env('ADMISSIONS_FUNNEL_V1', false),

    // TrueFit V1: teacher learning workspace shell (read-only Slice 0).
    // DARK LAUNCH: default OFF. Requires VITE_TRUEFIT_V1 on frontend build too.
    'truefit_v1' => (bool) env('TRUEFIT_V1', false),

    // in-app #413: inbox sync re-checks deduction_failed / lr_feedback source rows and
    // auto-resolves handled items. DARK LAUNCH: default OFF until the product decision
    // on lanes + "handled" (docs/plans/2026-10-20-inapp-closeout-product-decisions.md #413)
    // is recorded. Rollback: PERF_NOTIFICATION_SOURCE_RESOLVE=false stops further auto-resolve/reopen;
    // rows it already resolved stay resolved and carry Payload.source_resolved_at, so they can be found
    // and reopened by a (Founder-approved) data repair if needed.
    'notification_source_resolve_enabled' => (bool) env('PERF_NOTIFICATION_SOURCE_RESOLVE', false),
];
