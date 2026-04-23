<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middleware = [
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
        'api' => [
            \App\Http\Middleware\DebugSwipeRfidRequest::class,
            'throttle:200,1,global-api',
            \App\Http\Middleware\LogSlowRequests::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\AttachAuthUser::class,
        ],
    ];

    protected $routeMiddleware = [
        'role'           => \App\Http\Middleware\RequireRole::class,
        'super_admin'    => \App\Http\Middleware\RequireSuperAdmin::class,
        'api_key'        => \App\Http\Middleware\ApiKeyAuth::class,
        'require_campus' => \App\Http\Middleware\RequireCampus::class,
        'require_password_change' => \App\Http\Middleware\RequirePasswordChange::class,
        'throttle'       => \App\Http\Middleware\ThrottleRequestsByIp::class,
    ];
}

