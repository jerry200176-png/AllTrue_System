<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\ParentBindingAttempt;
use App\Models\PasswordResetRequest;
use App\Models\UserLoginActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 資料保存期限 (Founder 2026-11-17, #889 option A): system logs are kept only while they are useful.
 * Money, students and lessons are never touched here. Windows: login history 1 year; password-reset
 * requests 30 days; parent binding attempts 90 days (parent-binding:report reads up to 90); a chat message
 * deleted over 30 days ago loses its text and attachment, and its 「已刪除」 placeholder stays so the thread reads.
 */
class PruneSystemLogs extends Command
{
    protected $signature = 'privacy:prune-logs';

    protected $description = 'Delete system logs past their retention window (#889)';

    public function handle(): int
    {
        $counts = [
            'login' => UserLoginActivity::query()->where('created_at', '<', now()->subYear())->delete(),
            'reset' => PasswordResetRequest::query()->where('created_at', '<', now()->subDays(30))->delete(),
            'binding' => ParentBindingAttempt::query()->where('occurred_at', '<', now()->subDays(90))->delete(),
            'chat' => $this->scrubDeletedChat(),
        ];

        Log::info('Pruned system logs', $counts);
        $this->info('Pruned ' . array_sum($counts) . ' rows (' . http_build_query($counts, '', ', ') . ').');

        return self::SUCCESS;
    }

    private function scrubDeletedChat(): int
    {
        $scrubbed = 0;
        ChatMessage::query()->where('deleted_at', '<', now()->subDays(30))
            ->where(fn ($q) => $q->where('body', '!=', '')->orWhereNotNull('media_url'))
            ->chunkById(500, function ($messages) use (&$scrubbed): void {
                foreach ($messages as $message) {
                    // ponytail: a legacy absolute media path (leading '/') is not on the public disk; only its link is cleared.
                    if ($message->media_url && !str_starts_with($message->media_url, '/')) {
                        Storage::disk('public')->delete($message->media_url);
                    }
                    $message->forceFill(['body' => '', 'media_url' => null, 'media_name' => null])->save();
                    $scrubbed++;
                }
            });

        return $scrubbed;
    }
}
