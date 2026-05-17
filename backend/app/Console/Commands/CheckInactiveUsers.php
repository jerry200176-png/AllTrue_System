<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckInactiveUsers extends Command
{
    protected $signature = 'engagement:check-inactive';
    protected $description = 'Find users inactive for 3+ days and queue reminders';

    public function handle(): int
    {
        $threshold3 = now()->subDays(3);
        $threshold14 = now()->subDays(14);

        $inactiveUsers = User::query()
            ->whereIn('type', ['T', 'A'])
            ->where('status', 'approved')
            ->where(function ($q) use ($threshold3) {
                $q->where('last_login_at', '<', $threshold3)
                    ->orWhereNull('last_login_at');
            })
            ->where(function ($q) use ($threshold14) {
                $q->where('last_login_at', '>=', $threshold14)
                    ->orWhereNull('last_login_at');
            })
            ->get();

        $sent = 0;
        foreach ($inactiveUsers as $user) {
            if (!$this->shouldRemind($user)) {
                continue;
            }

            $this->recordReminder($user);
            $sent++;
        }

        $this->info("Queued {$sent} inactivity reminders.");
        Log::info("[engagement:check-inactive] Sent {$sent} reminders, checked {$inactiveUsers->count()} users.");

        return self::SUCCESS;
    }

    private function shouldRemind(User $user): bool
    {
        if (!DB::getSchemaBuilder()->hasTable('engagement_reminders_log')) {
            return false;
        }

        $recentReminder = DB::table('engagement_reminders_log')
            ->where('user_id', $user->id)
            ->where('sent_at', '>=', now()->subDays(7))
            ->exists();

        return !$recentReminder;
    }

    private function recordReminder(User $user): void
    {
        if (!DB::getSchemaBuilder()->hasTable('engagement_reminders_log')) {
            return;
        }

        DB::table('engagement_reminders_log')->insert([
            'user_id' => $user->id,
            'channel' => 'in_app',
            'template_key' => $this->pickTemplate($user),
            'sent_at' => now(),
        ]);
    }

    private function pickTemplate(User $user): string
    {
        $lastLogin = $user->last_login_at;
        if (!$lastLogin) {
            return 'gentle_reminder';
        }
        $daysAway = now()->diffInDays($lastLogin);
        if ($daysAway >= 7) {
            return 'firm_reminder';
        }
        return 'gentle_reminder';
    }
}
