<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Register test-only migration fixtures (e.g. the legacy teacher_branches table that has no production
        // migration) so they run during RefreshDatabase's migrate:fresh. This path is never used in production.
        $app->make('migrator')->path(__DIR__.'/database/migrations');

        return $app;
    }
}
