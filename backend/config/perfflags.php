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

    // Multi-guardian (Student 1:N). Flag gates staff UX + Portal canonical authZ
    // (guardians/student_guardians). Dual-write runs whenever tables exist.
    // Flag off ⇒ Portal/LINE auth falls back to verified SLB + legacy parent_phone.
    // Rollback: PERF_MULTI_GUARDIAN=false (tables retained; login still works).
    'multi_guardian_enabled' => (bool) env('PERF_MULTI_GUARDIAN', false),
];
