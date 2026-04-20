<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter([
        env('APP_URL', 'https://daan.lifenet.com.tw'),
        env('FRONTEND_URL'),
        'http://localhost:5173',
    ]),
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
