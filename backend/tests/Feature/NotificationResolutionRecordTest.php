<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\NotificationSyncService;
use App\Services\StudentPresenceBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** In-app #413 / #4154: resolved-by-system ≠ read; recurrence re-notifies; read never resolves; system alerts stay manual. */
class NotificationResolutionRecordTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(bool $deducted): StudentSignIn
    {
        return StudentSignIn::create(['StudentID' => 1, 'CampusID' => 1, 'SignInDT' => now(), 'MDT' => now(), 'Status' => 'present', 'SessionDeducted' => $deducted]);
    }

    private function deductionNotice(StudentSignIn $signIn): Notification
    {
        StudentPresenceBackfillService::alertIfNotDeducted($signIn, 1);

        return Notification::where('SourceKey', 'deduction_failed:' . $signIn->id)->firstOrFail();
    }

    private function events(Notification $notification): array
    {
        return DB::table('NotificationResolutionEvents')->where('NotificationID', $notification->id)->orderBy('id')->pluck('Event')->all();
    }

    public function test_system_resolution_is_recorded_and_is_not_a_read(): void
    {
        $signIn = $this->signIn(false);
        $notice = $this->deductionNotice($signIn);

        NotificationSyncService::sync([1], 1);
        $this->assertNull($notice->fresh()->ResolvedAt, 'still not deducted: stays open');

        $signIn->update(['SessionDeducted' => true]);
        NotificationSyncService::sync([1], 1);

        $row = $notice->fresh();
        $this->assertNotNull($row->ResolvedAt);
        $this->assertSame('system:sync:deduction_failed', $row->ResolvedBy);
        $this->assertSame('系統確認已補扣堂', $row->ResolvedReason);
        $this->assertSame(0, NotificationRead::where('NotificationID', $row->id)->count(), 'resolving writes no read state');
        $this->assertSame(['resolved'], $this->events($row));
    }

    public function test_recurrence_reopens_and_clears_every_read(): void
    {
        $signIn = $this->signIn(false);
        $notice = $this->deductionNotice($signIn);
        NotificationRead::create(['NotificationID' => $notice->id, 'UserID' => 7, 'ReadAt' => now()]);

        $signIn->update(['SessionDeducted' => true]);
        NotificationSyncService::sync([1], 1);
        $signIn->update(['SessionDeducted' => false]);
        $this->deductionNotice($signIn);

        $row = $notice->fresh();
        $this->assertNull($row->ResolvedAt);
        $this->assertNull($row->ResolvedBy);
        $this->assertNull($row->ResolvedReason);
        $this->assertSame(0, NotificationRead::where('NotificationID', $row->id)->count(), 'shows as new again');
        $this->assertSame(['resolved', 'reopened'], $this->events($row));
    }

    public function test_sync_managed_recurrence_renotifies_users_who_had_read_it(): void
    {
        $student = Student::create(['name' => '低堂數', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $class = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now(), 'TotalHours' => 20, 'Charge' => 5000, 'Paid' => 1, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 1,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        NotificationSyncService::sync([1], 1);
        $notice = Notification::where('Type', 'low_sessions')->firstOrFail();
        NotificationRead::create(['NotificationID' => $notice->id, 'UserID' => 7, 'ReadAt' => now()]);

        $class->update(['RemainingSessions' => 5]);
        NotificationSyncService::sync([1], 1);
        $this->assertSame('system:sync:low_sessions', $notice->fresh()->ResolvedBy);

        $class->update(['RemainingSessions' => 2]);
        NotificationSyncService::sync([1], 1);
        $row = $notice->fresh();
        $this->assertNull($row->ResolvedAt);
        $this->assertNull($row->ResolvedBy);
        $this->assertSame(0, NotificationRead::where('NotificationID', $row->id)->count());
        $this->assertSame(['resolved', 'reopened'], $this->events($row));
    }

    public function test_reading_never_resolves_and_system_alerts_stay_manual(): void
    {
        $user = User::create(['LoginName' => 'director-413@example.com', 'Name' => '主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $alerts = [
            Notification::create(['CampusID' => 1, 'Type' => 'system_alert', 'Severity' => 'high', 'Title' => '夜間對帳', 'SourceKey' => 'nightly_reconcile:test', 'OccurredAt' => now()]),
            Notification::create(['CampusID' => 1, 'Type' => 'grade_promotion_scheduler', 'Severity' => 'error', 'Title' => '排程失敗', 'SourceKey' => 'grade-promotion:scheduler-failure:test', 'OccurredAt' => now()]),
            $this->deductionNotice($this->signIn(false)),
        ];

        foreach ($alerts as $alert) {
            $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
                ->postJson("/api/v1/notifications/{$alert->id}/read")->assertOk();
        }
        NotificationSyncService::sync([1], 1);

        foreach ($alerts as $alert) {
            $row = $alert->fresh();
            $this->assertNull($row->ResolvedAt, "{$row->Type}: read must not resolve");
            $this->assertNull($row->ResolvedBy);
            $this->assertSame(1, NotificationRead::where('NotificationID', $row->id)->whereNotNull('ReadAt')->count());
            $this->assertSame([], $this->events($row));
        }
    }
}
