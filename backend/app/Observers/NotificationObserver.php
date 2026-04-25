<?php

namespace App\Observers;

use App\Models\Notification;
use App\Services\NotificationLineDispatcher;

class NotificationObserver
{
    public function created(Notification $notification): void
    {
        app(NotificationLineDispatcher::class)->dispatch($notification);
    }
}
