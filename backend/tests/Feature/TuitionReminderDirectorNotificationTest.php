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

    public function test_sync_keeps_the_aggregate_live_and_per_course_stream_does_not_duplicate_it(): void
    {
        [$campus, , $course] = $this->fixture();
        NotificationSyncService::sync([$campus->id]); // per-course row exists (cooldown scenario)
        $this->assertNotNull(Notification::where('SourceKey', "tuition:{$campus->id}:{$course->ID}")->whereNull('ResolvedAt')->first());

        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        NotificationSyncService::sync([$campus->id]);
        NotificationSyncService::sync(); // all-campus scope too

        $this->assertNull($this->aggregate()->ResolvedAt);
        $this->assertSame(1, Notification::where('Type', 'tuition')->where('CampusID', $campus->id)->whereNull('ResolvedAt')->count());
    }

    public function test_changed_content_reopens_read_state_but_unchanged_rerun_keeps_it(): void
    {
        [$campus, $director, , $s] = $this->fixture();
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->markRead($this->aggregate(), $director);

        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->assertSame(1, NotificationRead::count(), 'unchanged rerun keeps read state');

        $this->addCourse($s);
        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->assertSame(0, NotificationRead::count(), 'changed content shows as unread again');
        $this->assertSame(1, Notification::where('SourceType', 'TuitionReminder')->count());
    }

    public function test_overdue_unpaid_course_creates_one_campus_notification_even_when_run_twice(): void
    {
        $campus = Campus::factory()->create();
        $director = User::create([
            'LoginName' => 'tuition-rem-'.uniqid('', true).'@example.com', 'Name' => 'Director',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT), 'type' => 'D',
            'phone' => '09'.random_int(10000000, 99999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $director->id, 'Admin' => 0, 'Approved' => 1]);
        $s = Student::create(['name' => 'S', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        StudentClass::create([
            'StudentID' => $s->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->subDays(30), 'TotalHours' => 20, 'Charge' => 5000, 'Paid' => 0,
            'MDate' => now()->subDays(30), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10,
            'SessionDuration' => 120, 'RemainingSessions' => 5, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);

        $this->artisan('tuition:send-reminders')->assertExitCode(0);
        $this->artisan('tuition:send-reminders')->assertExitCode(0);

        $rows = Notification::where('Type', 'tuition')->get();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $campus->id, (int) $rows[0]->CampusID);
        $this->assertStringStartsWith("tuition-reminder:{$campus->id}:", $rows[0]->SourceKey);
    }
}
