<?php

namespace App\Console\Commands;

use App\Models\PendingSwipe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PrunePendingSwipes extends Command
{
    protected $signature = 'rfid:prune-pending';

    protected $description = 'Delete PendingSwipe records older than 30 days';

    public function handle(): int
    {
        $threshold = now()->subDays(30);
        $deleted = PendingSwipe::where('created_at', '<', $threshold)->delete();

        Log::info('Pruned pending swipes', [
            'deleted' => $deleted,
            'threshold' => $threshold->toDateTimeString(),
        ]);

        $this->info("Deleted {$deleted} pending swipes.");

        return Command::SUCCESS;
    }
}
