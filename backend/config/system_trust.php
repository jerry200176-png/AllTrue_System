<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Curated Known Issues (User-facing)
    |--------------------------------------------------------------------------
    |
    | Only sanitized, non-sensitive issues should appear here.
    | Do not include student names, credentials, URLs with tokens, or stack traces.
    |
    */
    'known_issues' => [
        [
            'id' => 'KNOWN-001',
            'title' => '部分頁面首次載入可能需要較長時間',
            'status' => 'investigating',
            'severity' => 'medium',
            'workaround' => '遇到長時間載入時請先按一次重新整理，若持續發生請用「回報問題」附上發生時間。',
            'updated_at' => '2026-05-23',
            'branch_ids' => [],
        ],
        [
            'id' => 'KNOWN-002',
            'title' => '行動裝置下特定表單欄位排版偶發擁擠',
            'status' => 'in_progress',
            'severity' => 'low',
            'workaround' => '先將手機轉為直向或放大頁面後輸入，資料仍可正常儲存。',
            'updated_at' => '2026-05-23',
            'branch_ids' => [],
        ],
    ],
];
