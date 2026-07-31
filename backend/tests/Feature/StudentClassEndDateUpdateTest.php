<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassEndDateUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_course_end_date_can_be_corrected_from_course_edit(): void
    {
        $token = $this->createDirectorToken();
        $student = Student::create([
            'name' => '結束日回歸測試學生',
            'CampusID' => 1,
            'ClassID' => 1,
        ]);
        $teacher = User::create([
            'LoginName' => 'end-date-teacher@example.test',
            'Name' => '結束日測試老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0911222333',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 66,
            'TeacherID' => $teacher->id,
            'by1' => 1,
            'Period' => 2,
            'ClassType' => 'one_on_one',
            'ScheduleMode' => 'date',
            'StartDate' => '2026-07-24',
            'EndDate' => '2026-07-30',
            'TotalHours' => 2,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'week' => 5,
            'time' => '13:00:00',
            'SessionCount' => 2,
            'SessionDuration' => 120,
            'RemainingSessions' => 2,
            'UsedSessions' => 0,
            'Stop' => 0,
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => '社會',
                'teacher_id' => $teacher->id,
                'payment_type' => 'monthly',
                'end_date' => '2026-07-31',
            ])
            ->assertOk();

        $course->refresh();
        $this->assertStringStartsWith('2026-07-31', (string) $course->EndDate);
    }

    private function createDirectorToken(): string
    {
        $director = User::create([
            'LoginName' => 'end-date-director@example.test',
            'Name' => '結束日測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }
}
