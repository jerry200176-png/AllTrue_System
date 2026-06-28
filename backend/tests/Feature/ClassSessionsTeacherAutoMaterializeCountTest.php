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

class ClassSessionsTeacherAutoMaterializeCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_same_day_index_materializes_sparse_count_mode_contract_gap(): void
    {
        // 2026-06-20 is Saturday (ISO 6) — matches handoff Bug 2 scenario.
        Carbon::setTestNow(Carbon::parse('2026-06-20 09:00:00', 'Asia/Taipei'));
        try {
            $campusId = 1;

            $teacher = User::create([
                'LoginName' => 'teacher-count-mat@example.com',
                'Name' => '堂數補建老師',
                'PSW' => 'secret',
                'type' => 'T',
                'phone' => '0911000111',
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
                'name' => '稀疏堂次學生',
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
                'StartDate' => '2026-06-06',
                'TotalHours' => 24,
                'Charge' => 0,
                'Paid' => 1,
                'Rate' => 500,
                'RoomID' => '1',
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 12,
                'SessionDuration' => 120,
                'RemainingSessions' => 10,
                'UsedSessions' => 2,
                'ClassType' => 'one_on_one',
                'week' => 6,
                'time' => '10:00:00',
            ]);

            foreach (['2026-06-06', '2026-06-13'] as $date) {
                ClassSession::create([
                    'StudentClassID' => $course->ID,
                    'SessionDate' => $date,
                    'StartTime' => '10:00:00',
                    'EndTime' => '12:00:00',
                    'Status' => 'attended',
                ]);
            }

            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->getJson('/api/v1/class-sessions?start=2026-06-20&end=2026-06-20&per_page=100');

            $response->assertOk();

            $this->assertDatabaseHas('ClassSession', [
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-06-20',
                'StartTime' => '10:00:00',
            ]);

            $rows = collect($response->json('data'));
            $matched = $rows->first(fn ($r) => (int) ($r['student_class_id'] ?? 0) === (int) $course->ID);
            $this->assertNotNull($matched, 'Same-day class-sessions list should include materialized count-mode slot');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_auto_materialize_skips_shared_package_members(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 09:00:00', 'Asia/Taipei'));
        try {
            $campusId = 1;

            $teacher = User::create([
                'LoginName' => 'teacher-pkg-skip@example.com',
                'Name' => '方案跳過老師',
                'PSW' => 'secret',
                'type' => 'T',
                'phone' => '0911000222',
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
                'name' => '共用方案學生',
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
                'PackageID' => 115,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-06-06',
                'TotalHours' => 24,
                'Charge' => 0,
                'Paid' => 1,
                'Rate' => 500,
                'RoomID' => '1',
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 12,
                'SessionDuration' => 120,
                'RemainingSessions' => 10,
                'UsedSessions' => 2,
                'ClassType' => 'one_on_one',
                'week' => 6,
                'time' => '10:00:00',
            ]);

            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-06-06',
                'StartTime' => '10:00:00',
                'EndTime' => '12:00:00',
                'Status' => 'attended',
            ]);

            $before = ClassSession::where('StudentClassID', $course->ID)->count();

            $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->getJson('/api/v1/class-sessions?start=2026-06-20&end=2026-06-20&per_page=100')->assertOk();

            $after = ClassSession::where('StudentClassID', $course->ID)->count();
            $this->assertSame($before, $after, 'shared-package count courses must not auto-materialize per course SessionCount');
        } finally {
            Carbon::setTestNow();
        }
    }
}
