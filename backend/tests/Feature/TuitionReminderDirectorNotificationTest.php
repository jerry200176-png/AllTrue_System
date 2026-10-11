<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Services\NotificationSyncService;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #3376: the director notification must be a schema-valid, idempotent campus row. */
class TuitionReminderDirectorNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Campus,1:User,2:StudentClass,3:Student} */
    private function fixture(): array
    {
        $campus = Campus::factory()->create();
        $director = User::create([
            'LoginName' => 'tuition-rem-'.uniqid('', true).'@example.com', 'Name' => 'Director',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT), 'type' => 'D',
            'phone' => '09'.random_int(10000000, 99999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $director->id, 'Admin' => 0, 'Approved' => 1]);
        $s = Student::create(['name' => 'S', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $s->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->subDays(30), 'TotalHours' => 20, 'Charge' => 5000, 'Paid' => 0,
            'MDate' => now()->subDays(30), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10,
            'SessionDuration' => 120, 'RemainingSessions' => 5, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);

        return [$campus, $director, $course, $s];
    }

    private function addCourse(Student $s): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $s->id, 'GradeID' => 1, 'SubjectID' => 2, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->subDays(30), 'TotalHours' => 20, 'Charge' => 3000, 'Paid' => 0,
            'MDate' => now()->subDays(30), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10,
            'SessionDuration' => 120, 'RemainingSessions' => 5, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
    }

    private function markRead(Notification $n, User $u): void
    {
        NotificationRead::create(['NotificationID' => $n->id, 'UserID' => $u->id, 'ReadAt' => now()]);
    }

    private function aggregate(): Notification
    {
        return Notification::where('SourceType', 'TuitionReminder')->firstOrFail();
    }

    public function test_command_then_sync_keeps_one_live_actionable_alert_per_course(): void
    {
        [$campus, , $course, $s] = $this->fixture();
        $second = $this->addCourse($s);
        NotificationSyncService::sync([$campus->id]); // cooldown scenario: per-course rows already exist

        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        NotificationSyncService::sync([$campus->id]);
        NotificationSyncService::sync();

        $live = Notification::where('Type', 'tuition')->where('CampusID', $campus->id)->whereNull('ResolvedAt')->pluck('SourceKey')->sort()->values()->all();
        $this->assertSame(
            collect(["tuition:{$campus->id}:{$course->ID}", "tuition:{$campus->id}:{$second->ID}"])->sort()->values()->all(),
            $live
        );
        $this->assertSame(0, Notification::where('SourceType', 'TuitionReminder')->count());
    }

    public function test_paid_course_is_retired_by_the_next_run(): void
    {
        [$campus, , $course] = $this->fixture();
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $course->forceFill(['Paid' => 1])->save();
        NotificationSyncService::sync([$campus->id]);

        $this->assertSame(0, Notification::where('Type', 'tuition')->whereNull('ResolvedAt')->count());
    }

    public function test_changed_owed_amount_or_reopen_is_unread_again_but_unchanged_rerun_keeps_read(): void
    {
        [$campus, $director, $course] = $this->fixture();
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $n = Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->firstOrFail();
        $this->markRead($n, $director);

        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->assertSame(1, NotificationRead::count(), 'unchanged rerun keeps read state');

        $course->forceFill(['Charge' => 7000])->save();
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->assertSame(0, NotificationRead::count(), 'changed amount shows unread again');

        $this->markRead($n, $director);
        $course->forceFill(['Paid' => 1])->save();
        NotificationSyncService::sync([$campus->id]);
        $course->forceFill(['Paid' => 0])->save();
        NotificationSyncService::sync([$campus->id]);
        $this->assertSame(0, NotificationRead::count(), 'reopened alert is unread');
    }
}
