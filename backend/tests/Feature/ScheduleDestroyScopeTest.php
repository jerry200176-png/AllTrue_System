<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** DELETE /schedules/{id} is limited to directors of the row's campus (and super_admin), like cancel-makeup. Synthetic data only. */
class ScheduleDestroyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_director_of_the_rows_campus_can_delete_it(): void
    {
        $teacher = $this->user('T', [1]);
        $student = Student::create(['name' => 'Schedule scope', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacher->id, 'by1' => 1, 'Period' => 8,
            'StartDate' => '2026-04-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000, 'SessionCount' => 8,
            'RemainingSessions' => 5, 'SessionDuration' => 120, 'week' => 5, 'time' => '16:00:00', 'class_type' => 'one_on_one',
            'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
        $row = Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $teacher->id, 'subject' => 'Math', 'day_of_week' => 5,
            'start_time' => '16:00', 'end_time' => '18:00', 'duration_hours' => 2, 'class_type' => 'one_on_one',
            'status' => 'scheduled', 'type' => 'normal', 'deduction' => 1, 'branch_id' => 1,
            'schedule_date' => '2026-04-10', 'student_course_id' => $course->ID,
        ]);

        $this->api($this->token($this->user('A', [2])))->deleteJson("/api/v1/schedules/{$row->id}")->assertForbidden();
        $this->api($this->token($teacher))->deleteJson("/api/v1/schedules/{$row->id}")->assertForbidden();
        $this->assertNotNull(Schedule::find($row->id));

        $this->api($this->token($this->user('A', [1])))->deleteJson("/api/v1/schedules/{$row->id}")->assertOk();
        $this->assertNull(Schedule::find($row->id));
    }

    /** @param list<int> $campusIds */
    private function user(string $type, array $campusIds): User
    {
        $user = User::create(['LoginName' => strtolower($type) . '-' . uniqid() . '@example.com', 'Name' => 'u', 'type' => $type,
            'PSW' => 'x', 'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Admin' => $type === 'T' ? 0 : 1, 'Approved' => 1]);
        }

        return $user;
    }

    private function token(User $user): string
    {
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}
