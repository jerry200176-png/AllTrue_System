<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'test {path?* : Test file(s) or directory(ies) to run}';

    /**
     * The console command description.
     */
    protected $description = 'Run PHPUnit tests (compat wrapper for php artisan test).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $phpunitBinary = base_path('vendor/bin/phpunit');
        if (!is_file($phpunitBinary)) {
            $this->error('PHPUnit binary not found at vendor/bin/phpunit. Run composer install first.');
            return self::FAILURE;
        }

        $paths = $this->argument('path') ?? [];
        $command = [PHP_BINARY, $phpunitBinary];
        if (!empty($paths)) {
            $command = array_merge($command, $paths);
        }

        $process = new Process($command, base_path());
        $process->setTimeout(null);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? self::FAILURE;
    }
}
