<?php

/*
 * In-app report families (Founder 2A, 2026-10-08). The ONE place that maps a report to an
 * `area:*` GitHub label so same-kind reports can be fixed together. Advisory only: it labels
 * and cross-links issues, it never closes or merges anything.
 *
 * Score = 3 per `pages` hit on page_key + 1 per `keywords` hit in title/description (both
 * case-insensitive substrings). Highest score wins, ties go to the first family listed,
 * score 0 = no family. Only the family name ever leaves production (public repo).
 */
return [
    'families' => [
        'billing' => [
            'pages' => ['tuition', 'payment', 'invoice', 'billing', 'receipt'],
            'keywords' => ['繳費', '收費', '帳務', '學費', '發票', '收據', '欠費', '退費', '折扣'],
        ],
        'attendance' => [
            'pages' => ['attendance', 'sign-in', 'signin', 'leave'],
            'keywords' => ['出缺勤', '簽到', '簽退', '請假', '出席', '刷卡', '補課'],
        ],
        'calendar' => [
            'pages' => ['calendar', 'schedule', 'session'],
            'keywords' => ['行事曆', '排課', '課表', '代課', '調課', '堂次', '時段'],
        ],
        'learning-records' => [
            'pages' => ['learning', 'assessment', 'record'],
            'keywords' => ['學習紀錄', '評量', '成績', '聯絡簿'],
        ],
        'parent-portal' => [
            'pages' => ['parent'],
            'keywords' => ['家長端', '家長登入', '綁定'],
        ],
        'ui' => [
            'pages' => [],
            'keywords' => ['畫面', '按鈕', '點不了', '沒反應', '跑版', '顯示不出', '打不開'],
        ],
    ],
];
