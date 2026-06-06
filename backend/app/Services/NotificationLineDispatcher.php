<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\UserNotificationPreference;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationLineDispatcher
{
    /**
     * 當 high severity 通知建立時，推送 LINE 給有綁定的老師/主任。
     * 沒綁 LineID 或 line_enabled=false 的使用者只收 in-app。
     */
    public function dispatch(Notification $notification): void
    {
        if ($notification->Severity !== 'high') {
            return;
        }

        $campusId = $notification->CampusID;
        if (!$campusId) {
            return;
        }

        $token = DB::table('Campus')
            ->where('id', $campusId)
            ->value('messaging_channel_token');

        if (empty($token)) {
            return;
        }

        // 找該分校所有有 LineID 且 line_enabled=true 的在職人員
        $staff = DB::table('UserCampus as uc')
            ->join('User as u', 'u.id', '=', 'uc.UserID')
            ->leftJoin('user_notification_preferences as pref', 'pref.user_id', '=', 'uc.UserID')
            ->where('uc.CampusID', $campusId)
            ->where('uc.Approved', 1)
            ->where('u.status', 'active')
            ->whereNotNull('u.LineID')
            ->where('u.LineID', '!=', '')
            ->where(function ($q) {
                $q->whereNull('pref.line_enabled')
                  ->orWhere('pref.line_enabled', true);
            })
            ->select('uc.UserID', 'u.LineID', 'pref.quiet_hours_start', 'pref.quiet_hours_end')
            ->get();

        foreach ($staff as $member) {
            if ($this->isQuietHour($member->quiet_hours_start, $member->quiet_hours_end)) {
                continue;
            }

            $this->push($token, $member->LineID, $notification);
        }
    }

    private function isQuietHour(?string $start, ?string $end): bool
    {
        if (empty($start) || empty($end)) {
            return false;
        }

        $now   = Carbon::now('Asia/Taipei')->format('H:i');
        // 支援跨午夜（例如 22:00 - 08:00）
        if ($start <= $end) {
            return $now >= $start && $now <= $end;
        }
        return $now >= $start || $now <= $end;
    }

    private function push(string $token, string $lineUserId, Notification $notification): void
    {
        $text = "[AllTrue] {$notification->Title}";
        if ($notification->Body) {
            $text .= "\n" . $notification->Body;
        }

        try {
            Http::withToken($token)
                ->timeout(5)
                ->post('https://api.line.me/v2/bot/message/push', [
                    'to'       => $lineUserId,
                    'messages' => [['type' => 'text', 'text' => $text]],
                ]);
        } catch (\Throwable $e) {
            Log::warning('line_push_failed', [
                'line_user_id'    => $lineUserId,
                'notification_id' => $notification->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}
