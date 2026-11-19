<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #766 and TD-065: a model observer registered directly in AppServiceProvider::boot() never fires (no listener lands
 * on the app's event dispatcher). Every `::observe(` must sit inside the `$this->app->booted(...)` callback.
 */
class AppServiceProviderObserverRegistrationTest extends TestCase
{
    public function test_observers_are_registered_only_inside_the_booted_callback(): void
    {
        $src = file_get_contents(__DIR__ . '/../../app/Providers/AppServiceProvider.php');
        $boot = substr($src, strpos($src, 'public function boot()'));
        $beforeBooted = substr($boot, 0, (int) strpos($boot, '$this->app->booted('));

        $this->assertStringContainsString('$this->app->booted(', $boot);
        $this->assertStringNotContainsString('::observe(', $beforeBooted, 'Move ::observe() into $this->app->booted().');
    }
}
