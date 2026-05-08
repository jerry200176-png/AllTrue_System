<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassSessionsTeacherAutoMaterializeMonthlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_index_materializes_missing_monthly_projected_session_for_same_day_query(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:00', 'Asia/Taipei'));
        try {
            $campusId = 1;

            $teacher = User::create([
                'LoginName' => 'teacher-materialize@example.com',
                'Name' => '自動補建老師',
                'PSW' => 'secret',
                'type' => 'T',
                'phone' => '0911222333',
                'MustChangePassword' => false,
            ]);
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $teacher->id,
                'Admin' => 0,
                'Approved' => 1,
            ]);
            $token = bin2hex(random_bytes(16));
            AuthToken::create([
                'user_id' => $teacher->id,
                'token' => $token,
                'expires_at' => now()->addDay(),
            ]);

            $student = Student::create([
                'name' => '名單補建學生',
                'CampusID' => $campusId,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => $teacher->id,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-05-01',
                'EndDate' => '2026-05-31',
                'TotalHours' => 20,
                'Charge' => 0,
                'Paid' => 1,
                'Rate' => 500,
                'RoomID' => '1',
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'date',
                'SessionDuration' => 120,
                // 2026-05-08 is Friday (ISO 5)
                'week' => 5,
                'time' => '15:00:00',
            ]);

            $this->assertDatabaseMissing('ClassSession', [
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-05-08',
                'StartTime' => '15:00:00',
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->getJson('/api/v1/class-sessions?start=2026-05-08&end=2026-05-08&per_page=100');

            $res->assertOk();
            $rows = collect($res->json('data'));
            $matched = $rows->first(fn ($r) => (int) ($r['student_class_id'] ?? 0) === (int) $course->ID);
            $this->assertNotNull($matched, 'Teacher same-day class-sessions list should include auto-materialized monthly slot');

            $this->assertDatabaseHas('ClassSession', [
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-05-08',
                'StartTime' => '15:00:00',
                'Status' => 'scheduled',
                'Note' => 'projected-monthly-materialized-auto',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}

