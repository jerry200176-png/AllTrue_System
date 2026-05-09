<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FixNamespaceHelpCommand extends Command
{
    protected $signature = 'fix';

    protected $description = 'Show available fix:* maintenance commands';

    public function handle(): int
    {
        $this->warn('Please run a specific fix command:');
        $this->line('  php artisan fix:orphan-scheduled-sessions [--dry-run]');

        return self::SUCCESS;
    }
}

