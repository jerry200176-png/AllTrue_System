<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationRead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-app #413 / #4154 (Founder): the processing record for a notification's source problem.
 *
 * - "Resolved" lives on Notifications (ResolvedAt / ResolvedBy / ResolvedReason) and means the
 *   system (or a named workflow) confirmed the source condition no longer holds.
 * - "Read" lives per user in NotificationReads and never resolves anything.
 * - When the source problem recurs, the row is re-opened and every user's read state is cleared,
 *   so the problem is notified again. Each transition is appended to NotificationResolutionEvents.
 */
class NotificationResolution
{
    private static ?bool $recordReady = null;

    /** Marks the source problem resolved. Caller passes an unsaved or saved model; this saves it. */
    public static function resolve(Notification $notification, string $actor, string $reason): void
    {
        $now = now();
        $notification->ResolvedAt = $now;
        if (self::recordReady()) {
            $notification->ResolvedBy = mb_substr($actor, 0, 64);
            $notification->ResolvedReason = mb_substr($reason, 0, 255);
        }
        $notification->save();
        self::append($notification, 'resolved', $actor, $reason, $now);
    }

    /**
     * The source problem is back: clear the resolution and every user's read state so it shows as new.
     * Does not save the notification; the caller applies its fresh payload and saves.
     */
    public static function reopen(Notification $notification, string $actor, string $reason): void
    {
        $notification->ResolvedAt = null; // Notification::saving clears ResolvedBy / ResolvedReason.
        NotificationRead::query()->where('NotificationID', $notification->id)->delete();
        self::append($notification, 'reopened', $actor, $reason, now());
    }

    private static function append(Notification $notification, string $event, string $actor, string $reason, $at): void
    {
        if (!self::recordReady() || !$notification->id) {
            return;
        }
        DB::table('NotificationResolutionEvents')->insert([
            'NotificationID' => $notification->id,
            'Event' => $event,
            'Actor' => mb_substr($actor, 0, 64),
            'Reason' => mb_substr($reason, 0, 255),
            'OccurredAt' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** Fail open to the pre-#413 behaviour until the additive migration has run. */
    private static function recordReady(): bool
    {
        return self::$recordReady ??= Schema::hasTable('NotificationResolutionEvents')
            && Schema::hasColumn('Notifications', 'ResolvedBy');
    }
}
