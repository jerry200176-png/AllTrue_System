<?php

namespace Tests\Feature;

use App\Http\Controllers\ScheduleController;
use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #4001: schedule update() is serialized with other student writes and rechecks after the lock; store() locks the course owner. */
class ScheduleWriteGuards4001Test extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $teacherId;
    private StudentClass $sc;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
        config(['feature_flags.values' => ['FEATURE_SCHEDULE_OCCURRENCE_V2' => false]]);
        $mk = function (string $name, string $type, int $admin = 0) {
            $u = User::create(['LoginName' => "{$name}@w4001.example.com", 'Name' => $name, 'PSW' => 'x', 'type' => $type,
                'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false, 'TeachingSessionCount' => 0]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);

            return $u;
        };
        $director = $mk('dir4001', 'A', 1);
        $this->teacherId = (int) $mk('teacher4001', 'T')->id;
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
        $this->sc = $this->course('owner');
    }

    protected function tearDown(): void
    {
        ScheduleController::$beforeStudentLockForTests = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function course(string $name): StudentClass
    {
        $student = Student::create(['name' => $name, 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $this->teacherId, 'by1' => 1,
            'Period' => 8, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000,
            'SessionCount' => 8, 'RemainingSessions' => 5, 'SessionDuration' => 120, 'week' => 5, 'time' => '16:00:00',
            'class_type' => 'one_on_one', 'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
    }

    private function api()
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json']);
    }

    public function test_update_rechecks_after_the_student_lock_and_refuses_a_slot_taken_in_between(): void
    {
        $row = Schedule::create(['student_id' => $this->sc->StudentID, 'student_course_id' => $this->sc->ID, 'teacher_id' => $this->teacherId,
            'subject' => 'Math', 'day_of_week' => 5, 'start_time' => '16:00', 'end_time' => '18:00', 'class_type' => 'one_on_one',
            'status' => 'scheduled', 'type' => 'normal', 'branch_id' => 1, 'schedule_date' => '2026-04-24']);
        $other = $this->course('renewal');
        // The interleaved write: a renewal materializes the same teacher's lesson at the target slot after update()'s first check.
        ScheduleController::$beforeStudentLockForTests = fn () => ClassSession::create(['StudentClassID' => $other->ID,
            'SessionDate' => '2026-04-25', 'StartTime' => '17:00:00', 'EndTime' => '19:00:00', 'Status' => 'scheduled']);

        $this->api()->putJson("/api/v1/schedules/{$row->id}", ['schedule_date' => '2026-04-25', 'start_time' => '17:00', 'end_time' => '19:00'])
            ->assertStatus(409);

        $this->assertSame('2026-04-24', substr((string) $row->fresh()->schedule_date, 0, 10));
        $this->assertSame(0, ClassSession::where('StudentClassID', $this->sc->ID)->whereDate('SessionDate', '2026-04-25')->count());
        $this->assertSame(1, ClassSession::whereDate('SessionDate', '2026-04-25')->count());
    }

    public function test_store_refuses_a_payload_student_that_does_not_own_the_course(): void
    {
        $stranger = $this->course('stranger');
        $this->api()->postJson('/api/v1/schedules', ['student_id' => $stranger->StudentID, 'student_course_id' => $this->sc->ID,
            'teacher_id' => $this->teacherId, 'subject' => 'Math', 'day_of_week' => 6, 'start_time' => '16:00', 'end_time' => '18:00',
            'class_type' => 'one_on_one', 'status' => 'scheduled', 'type' => 'normal', 'branch_id' => 1, 'schedule_date' => '2026-04-25'])
            ->assertStatus(422)->assertJsonPath('code', 'student_course_mismatch');

        $this->assertSame(0, Schedule::count());
    }
}
