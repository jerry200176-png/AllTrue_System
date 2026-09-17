<?php

/*
|--------------------------------------------------------------------------
| Grade promotion (in-app #297 Phase A)
|--------------------------------------------------------------------------
|
| Default administrative promotion date is centralized here (not scattered
| literals). Season year = calendar year of that 8/1 boundary.
|
*/

return [
    'timezone' => env('GRADE_PROMOTION_TZ', 'Asia/Taipei'),

    // V1 default administrative date: August 1
    'admin_month' => (int) env('GRADE_PROMOTION_ADMIN_MONTH', 8),
    'admin_day' => (int) env('GRADE_PROMOTION_ADMIN_DAY', 1),

    'grade_order' => ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'J1', 'J2', 'J3', 'H1', 'H2', 'H3'],

    'grade_to_class_id' => [
        'P1' => 1, 'P2' => 2, 'P3' => 3, 'P4' => 4, 'P5' => 5, 'P6' => 6,
        'J1' => 7, 'J2' => 8, 'J3' => 9, 'H1' => 10, 'H2' => 11, 'H3' => 12,
    ],
];
