<?php

return [
    'name' => env('APP_NAME', 'AllTrueAdmin'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'Asia/Taipei',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    'providers' => [
        App\Providers\AppServiceProvider::class,
        Illuminate\Auth\AuthServiceProvider::class,
        Illuminate\Broadcasting\BroadcastServiceProvider::class,
        Illuminate\Bus\BusServiceProvider::class,
        Illuminate\Cache\CacheServiceProvider::class,
        Illuminate\Cookie\CookieServiceProvider::class,
        Illuminate\Database\DatabaseServiceProvider::class,
        Illuminate\Encryption\EncryptionServiceProvider::class,
        Illuminate\Filesystem\FilesystemServiceProvider::class,
        Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
        Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        Illuminate\Hashing\HashServiceProvider::class,
        // Registers the paginator's current-page / current-path resolvers.
        // Without it, Paginator::resolveCurrentPage() falls back to a hardcoded 1,
        // so EVERY paginated endpoint silently ignores ?page= and always returns
        // page 1 (prod incident 2026-06-28: 課程管理 stuck on first 50 of 144,
        // "下一頁沒反應"; same for students/bugs). See PaginationResolverTest.
        Illuminate\Pagination\PaginationServiceProvider::class,
        Illuminate\Pipeline\PipelineServiceProvider::class,
        Illuminate\Queue\QueueServiceProvider::class,
        Illuminate\Routing\RoutingServiceProvider::class,
        Illuminate\Session\SessionServiceProvider::class,
        Illuminate\Translation\TranslationServiceProvider::class,
        Illuminate\Validation\ValidationServiceProvider::class,
        Illuminate\View\ViewServiceProvider::class,
        Maatwebsite\Excel\ExcelServiceProvider::class,
    ],
    'aliases' => [
        'Excel' => Maatwebsite\Excel\Facades\Excel::class,
    ],

    'deploy_secret' => env('DEPLOY_SECRET', ''),

    // SEC-F1: When set, POST /api/v1/directors/register requires this token.
    // Leave empty to allow open self-registration (default for new installs).
    'director_registration_token' => env('DIRECTOR_REGISTRATION_TOKEN', null),
];
