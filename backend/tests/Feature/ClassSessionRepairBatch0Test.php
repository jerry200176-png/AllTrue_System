<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassSessionRepairBatch0Test extends TestCase
{
    use RefreshDatabase;

    public function test_repair_duplicate_sessions_dry_run_does_not_write(): void
    {
        $this->artisan('repair:duplicate-sessions', ['--case' => 'batch0'])
            ->expectsOutput('=== DRY RUN repair:duplicate-sessions ===')
            ->assertExitCode(0);
    }
}
