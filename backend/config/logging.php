<?php

return [
    'default' => env('LOG_CHANNEL') ?: 'stack',

    'channels' => [
        'stack' => [
            'driver'   => 'stack',
            'channels' => ['single'],
        ],
        'single' => [
            'driver'     => 'single',
            'path'       => storage_path('logs/laravel.log'),
            'level'      => 'debug',
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
