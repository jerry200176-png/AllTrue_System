<?php

return [
    'enabled' => env('PAYROLL_FEATURE_ENABLED', true),
    'max_per_page' => (int) env('PAYROLL_MAX_PER_PAGE', 200),
    'max_export_rows' => (int) env('PAYROLL_MAX_EXPORT_ROWS', 5000),

    'base_rates' => [
        'high'       => 400,
        'junior'     => 350,
        'elementary' => 300,
        'tutoring'   => 200,
    ],
    'headcount_bonus' => 50,
    'concurrency_bonus_per_student' => 50,

    'grade_level_map' => [
        1 => 'elementary', 2 => 'elementary', 3 => 'elementary',
        4 => 'elementary', 5 => 'elementary', 6 => 'elementary',
        7 => 'junior', 8 => 'junior', 9 => 'junior',
        10 => 'high', 11 => 'high', 12 => 'high',
    ],
];
