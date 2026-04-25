<?php

return [
    'default' => env('LOG_CHANNEL') ?: 'stack',

    'channels' => [
        'stack' => [
            'driver'   => 'stack',
            'channels' => ['daily', 'json'],
        ],
        'daily' => [
            'driver'     => 'daily',
            'path'       => storage_path('logs/laravel.log'),
            'level'      => 'debug',
            'days'       => 14,
            'permission' => 0664,
        ],
        // JSON 結構化 log，供未來接 ELK/Loki 使用
        'json' => [
            'driver'     => 'daily',
            'path'       => storage_path('logs/laravel.json.log'),
            'level'      => 'warning',  // 只記 warning+，避免 debug noise
            'days'       => 30,
            'permission' => 0664,
            'formatter'  => Monolog\Formatter\JsonFormatter::class,
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
