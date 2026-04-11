<?php

return [
    'driver'          => env('SESSION_DRIVER', 'cookie'),
    'lifetime'        => env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'encrypt'         => true,
    'cookie'          => env('SESSION_COOKIE', 'alltrue_session'),
    'path'            => '/',
    'domain'          => env('SESSION_DOMAIN'),
    'secure'          => env('SESSION_SECURE_COOKIE'),
    'http_only'       => true,
    'same_site'       => 'lax',
];
