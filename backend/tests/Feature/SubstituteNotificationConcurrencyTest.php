<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Observers\NotificationObserver;
use App\Services\SubstituteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubstituteNotificationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string|null> */
    protected $connectionsToTransact = [];

    public function test_concurrent_substitute_notification_insert_reuses_committed_source_key(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This regression covers MySQL REPEATABLE READ visibility.');
        }

        $campusId = 1;
        $classSessionId = 991234;
        $sourceKey = "substitute:{$classSessionId}";
        $connectionName = 'substitute_notification_race';
        config([
            "database.connections.{$connectionName}" => config('database.connections.' . config('database.default')),
        ]);
        DB::purge($connectionName);
        $concurrent = DB::connection($connectionName);
        $injected = false;

        Notification::creating(function (Notification $notification) use (
            $concurrent,
            &$injected,
            $sourceKey
        ): void {
            if ($injected || $notification->SourceKey !== $sourceKey) {
                return;
            }

            $attributes = $notification->getAttributes();
            $attributes['created_at'] = now();
            $attributes['updated_at'] = now();
            $concurrent->table('Notifications')->insert($attributes);
            $injected = true;
        });

        try {
            $notification = app(SubstituteService::class)->createParentNotification([
                'campus_id' => $campusId,
                'class_session_id' => $classSessionId,
                'student_name' => '並行代課通知測試學生',
                'subject' => 'Math',
                'session_date' => '2026-09-09',
                'start_time' => '10:00',
                'end_time' => '12:00',
                'old_teacher_name' => '原老師',
                'new_teacher_name' => '代課老師',
            ]);

            $this->assertTrue($injected, 'The test must inject the competing committed row.');
            $this->assertNotNull($notification);
            $this->assertSame(1, $concurrent->table('Notifications')->where('SourceKey', $sourceKey)->count());
            $this->assertSame('Math', data_get($notification->Payload, 'subject'));
            $this->assertNull($concurrent->table('Notifications')->where('SourceKey', $sourceKey)->value('ResolvedAt'));
        } finally {
            // The competing insert is committed on the second connection, so
            // clean it up there as well as on the default connection. This
            // test intentionally opts out of RefreshDatabase transactions
            // because the committed race fixture must not survive teardown.
            $concurrent->table('Notifications')->where('SourceKey', $sourceKey)->delete();
            Notification::where('SourceKey', $sourceKey)->delete();
            DB::disconnect($connectionName);

            // The creating hook is process-global in Eloquent. Restore the
            // application observer so this concurrency test cannot alter the
            // event listeners seen by later feature tests.
            Notification::flushEventListeners();
            Notification::observe(NotificationObserver::class);
        }
    }
}
