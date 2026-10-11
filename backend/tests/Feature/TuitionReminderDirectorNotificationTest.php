<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Notification;
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
