<?php

namespace App\Providers;

use App\Models\ClassSession;
use App\Models\Notification;
use App\Observers\ClassSessionObserver;
use App\Observers\NotificationObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Notification::observe(NotificationObserver::class);

        // N+1 guard: lazy loads throw outside production (tests/CI/local), so a
        // missing with()/load() fails the suite; production is unaffected.
        Model::preventLazyLoading(! $this->app->environment('production'));

        // AppServiceProvider is registered before Illuminate's
        // DatabaseServiceProvider in config/app.php, so the Eloquent event
        // dispatcher is not bound yet when this boot() runs. Registering an
        // observer here would silently no-op. Defer until the framework has
        // fully booted so the dispatcher exists and audit events actually fire.
        $this->app->booted(function () {
            ClassSession::observe(ClassSessionObserver::class);
        });

        // Force HTTPS for all URL generation when behind Cloudflare / proxy (production)
        if (!$this->app->environment('local') && str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
