<?php

namespace App\Console\Commands;

use App\Operations\PopOperationService;
use Illuminate\Console\Command;

/** Thin self-hosted-runner adapter; policy and domain work stay in POP services. */
class PopExecute extends Command
{
    protected $signature = 'pop:execute
        {--request=}
        {--phase= : dry-run|execute|verify|rollback}
        {--token=}
        {--commit-sha=}
        {--actor=pop-self-hosted-runner}';

    protected $description = 'Run one catalog-bound POP phase on the self-hosted executor';

    public function handle(PopOperationService $service): int
    {
        $request = (string) $this->option('request');
        $phase = (string) $this->option('phase');
        if ($request === '' || !in_array($phase, ['dry-run', 'execute', 'verify', 'rollback'], true)) {
            $this->error('request and a valid phase are required');
            return self::INVALID;
        }
        try {
            $result = $service->run($request, $phase, $this->option('token'), $this->option('commit-sha'), (string) $this->option('actor'));
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $this->error('POP failed closed. See the execution record or server log for the correlation id.');
            return self::FAILURE;
        }
    }
}
