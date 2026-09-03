<?php

namespace App\Console\Commands;

use App\Operations\PopOperationService;
use Illuminate\Console\Command;

/** Local Pi adapter. Approval and execution policy remain in PopOperationService. */
final class PopExecute extends Command
{
    protected $signature = 'pop:execute-approved {--request= : Optional approved request UUID}';

    protected $description = 'Execute one approved POP operation locally on the production host';

    public function handle(PopOperationService $service): int
    {
        try {
            $result = $service->runApprovedLocally($this->option('request') ?: null);
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('POP failed closed. See the execution record or server log for the correlation id.');

            return self::FAILURE;
        }
    }
}
