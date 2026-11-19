<?php

namespace App\Providers;

use App\Models\ClassSession;
use App\Observers\ClassSessionObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public const BUG_LIMIT_MESSAGE = '回報有點多，請稍後再送；急事請直接找主任。';

    public function boot(): void
    {
        // 意見與建議 anti-abuse: per signed-in user (not per IP: a whole campus shares one), same Chinese answer for every limit.
        $tooMany = fn (Request $r, array $headers) => response()->json(['message' => self::BUG_LIMIT_MESSAGE, 'code' => 'bug_report_rate_limited'], 429, $headers);
        // Throttle middleware is sorted ahead of AttachAuthUser, so auth_user may not be set yet: read the bearer token's user directly.
        $who = fn (Request $r) => 'user:' . (data_get($r->attributes->get('auth_user'), 'id')
            ?? ($r->bearerToken() ? \App\Models\AuthToken::where('token', $r->bearerToken())->value('user_id') : null) ?? $r->ip());
        RateLimiter::for('bug-report', fn (Request $r) => [
            Limit::perHour(5)->by($who($r))->response($tooMany),
            Limit::perDay(20)->by($who($r))->response($tooMany),
        ]);
        RateLimiter::for('bug-comment', fn (Request $r) => Limit::perHour(30)->by($who($r))->response($tooMany));

        // N+1 guard: lazy loads throw outside production (tests/CI/local), so a
        // missing with()/load() fails the suite; production is unaffected.
        Model::preventLazyLoading(! $this->app->environment('production'));

        // Register model observers only inside booted(). An observer registered directly in boot() never fires:
        // #766 (ClassSession audit log stayed empty) and TD-065 (NotificationObserver; a CI check on 2026-11-19 found
        // no `eloquent.created` listener on the app dispatcher). AppServiceProviderObserverRegistrationTest guards it.
        $this->app->booted(function () {
            ClassSession::observe(ClassSessionObserver::class);
        });

        // Force HTTPS for all URL generation when behind Cloudflare / proxy (production)
        if (!$this->app->environment('local') && str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
