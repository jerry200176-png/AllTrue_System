<?php

return [
    'default' => env('LOG_CHANNEL') ?: 'stack',

    'channels' => [
        'stack' => [
            'driver'   => 'stack',
            'channels' => ['daily'],
        ],
        'daily' => [
            'driver'     => 'daily',
            'path'       => storage_path('logs/laravel.log'),
            'level'      => 'debug',
            'days'       => 14,
            'permission' => 0664,
        ],
        'perf' => [
            'driver'     => 'daily',
            'path'       => storage_path('logs/perf.log'),
            'level'      => 'info',
            'days'       => 14,
            'permission' => 0664,
        ],
    ],
];
