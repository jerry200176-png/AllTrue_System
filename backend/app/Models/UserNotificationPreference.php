<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotificationPreference extends Model
{
    protected $table = 'user_notification_preferences';

    protected $fillable = [
        'user_id',
        'in_app_enabled',
        'email_enabled',
        'line_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
        'event_tuition',
        'event_learning_review',
        'event_attendance',
        'event_system',
    ];
}
