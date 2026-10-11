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

    public function test_double_run_yields_one_live_row_per_course(): void
    {
        [$campus] = $this->fixture();
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->artisan('tuition:send-reminders')->assertExitCode(0);

        $rows = Notification::where('Type', 'tuition')->where('CampusID', $campus->id)->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->ResolvedAt);
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

    /** @return array<string, array{0: callable(StudentClass, Student): void}> */
    public static function visibleChanges(): array
    {
        return [
            'title (student name)' => [fn ($c, $s) => $s->forceFill(['name' => 'Renamed'])->save()],
            'body (remaining sessions)' => [fn ($c, $s) => $c->forceFill(['RemainingSessions' => 2])->save()],
            'owed amount' => [fn ($c, $s) => $c->forceFill(['Charge' => 7000])->save()],
            'deep-link payload (student id)' => [function ($c, $s) {
                $other = Student::create(['name' => $s->name, 'CampusID' => $s->CampusID, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
                $c->forceFill(['StudentID' => $other->id])->save();
            }],
        ];
    }

    /** @dataProvider visibleChanges */
    public function test_director_visible_change_is_unread_again(callable $change): void
    {
        [$campus, $director, $course, $s] = $this->fixture();
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $n = Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->firstOrFail();
        $this->markRead($n, $director);

        $change($course, $s);
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->assertSame(0, NotificationRead::count());
    }

    public function test_unchanged_rerun_keeps_read_and_reopen_is_unread(): void
    {
        [$campus, $director, $course] = $this->fixture();
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $n = Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->firstOrFail();
        $this->markRead($n, $director);

        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->assertSame(1, NotificationRead::count(), 'unchanged rerun keeps read state');

        $course->forceFill(['Paid' => 1])->save();
        NotificationSyncService::sync([$campus->id]);
        $course->forceFill(['Paid' => 0])->save();
        NotificationSyncService::sync([$campus->id]);
        $this->assertSame(0, NotificationRead::count(), 'reopened alert is unread');
    }

    public function test_severity_only_change_is_unread_again_and_no_change_keeps_read(): void
    {
        [$campus, $director, $course] = $this->fixture();
        NotificationSyncService::sync([$campus->id]);
        $n = Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->firstOrFail();
        $this->markRead($n, $director);

        NotificationSyncService::sync([$campus->id]);
        $this->assertSame(1, NotificationRead::count(), 'nothing changed: read kept');

        $n->forceFill(['Severity' => 'low'])->save(); // only Severity differs from what sync derives
        NotificationSyncService::sync([$campus->id]);
        $this->assertSame(0, NotificationRead::count(), 'severity-only change: unread again');
    }
}
