<?php

// Grade promotion (in-app #297 Phase A). Admin date is centralized here.

return [
    'timezone' => env('GRADE_PROMOTION_TZ', 'Asia/Taipei'),
    'admin_month' => (int) env('GRADE_PROMOTION_ADMIN_MONTH', 8),
    'admin_day' => (int) env('GRADE_PROMOTION_ADMIN_DAY', 1),
    // Phase-B.1: preview+reminder only. Phase-B.2 auto-confirm requires separate Founder GO.
    'auto_confirm' => filter_var(env('GRADE_PROMOTION_AUTO_CONFIRM', false), FILTER_VALIDATE_BOOL),
    // Empty allowlist = fail closed. Initial rollout: campus 9 only (set in env when enabling scheduler).
    'campus_allowlist' => array_values(array_filter(array_map(
        static fn (string $part) => (int) trim($part),
        explode(',', (string) env('GRADE_PROMOTION_CAMPUS_ALLOWLIST', ''))
    ), static fn (int $id) => $id > 0)),
    'grade_order' => ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'J1', 'J2', 'J3', 'H1', 'H2', 'H3'],
    'grade_to_class_id' => [
        'P1' => 1, 'P2' => 2, 'P3' => 3, 'P4' => 4, 'P5' => 5, 'P6' => 6,
        'J1' => 7, 'J2' => 8, 'J3' => 9, 'H1' => 10, 'H2' => 11, 'H3' => 12,
    ],
];
