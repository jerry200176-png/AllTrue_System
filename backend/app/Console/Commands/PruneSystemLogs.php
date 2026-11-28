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
        ];
        [$counts['chat'], $stuck] = $this->scrubDeletedChat();

        Log::info('Pruned system logs', $counts + ['chat_files_not_deleted' => $stuck]);
        $this->info('Pruned ' . array_sum($counts) . ' rows (' . http_build_query($counts, '', ', ') . ').');
        if ($stuck > 0) {
            // The row keeps its path, so the next run retries; the failure shows in the scheduler evidence.
            $this->error("Could not delete {$stuck} chat attachment(s); kept for the next run.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} scrubbed messages, attachments that could not be deleted */
    private function scrubDeletedChat(): array
    {
        [$scrubbed, $stuck] = [0, 0];
        ChatMessage::query()->where('deleted_at', '<', now()->subDays(30))
            ->where(fn ($q) => $q->where('body', '!=', '')->orWhereNotNull('media_url'))
            ->chunkById(500, function ($messages) use (&$scrubbed, &$stuck): void {
                foreach ($messages as $message) {
                    $path = (string) $message->media_url;
                    // ponytail: a legacy absolute media path (leading '/') is not on either disk; only its link is cleared.
                    if ($path !== '' && !str_starts_with($path, '/')) {
                        $failedDelete = false;
                        foreach (['local', 'public'] as $diskName) {
                            $disk = Storage::disk($diskName);
                            if (!$disk->delete($path) && $disk->exists($path)) {
                                $failedDelete = true;
                            }
                        }
                        if ($failedDelete) {
                            $stuck++;
                            continue;
                        }
                    }
                    $message->forceFill(['body' => '', 'media_url' => null, 'media_name' => null])->save();
                    $scrubbed++;
                }
            });

        return [$scrubbed, $stuck];
    }
}
