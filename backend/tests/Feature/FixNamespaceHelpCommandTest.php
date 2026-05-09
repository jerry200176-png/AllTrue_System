<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixNamespaceHelpCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fix_namespace_command_returns_help_instead_of_namespace_exception(): void
    {
        $this->artisan('fix')
            ->expectsOutput('Please run a specific fix command:')
            ->expectsOutput('  php artisan fix:orphan-scheduled-sessions [--dry-run]')
            ->assertExitCode(0);
    }
}

