<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceRemainingSessionsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_present_attendance_deducts_one_session(): void
    {
        $beforeRemaining = 8;
        $token = $this->createDirectorToken([1], 'director-att-present@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-att-present@example.com');
        $student = $this->createStudent(1, '點名到班測試');

        $courseId = $this->bootstrapCourse($token, $student->id, $teacherId, $beforeRemaining);
        $classSession = $this->pastClassSession($courseId);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'ClassSessionID' => $classSession->id,
            'Status' => 'present',
        ])->assertCreated();

        $after = StudentClass::findOrFail($courseId);
        $this->assertSame($beforeRemaining - 1, (int) $after->RemainingSessions);
        $this->assertSame(1, (int) $after->UsedSessions);
    }

    public function test_late_attendance_deducts_one_session_like_present(): void
    {
        $beforeRemaining = 8;
        $token = $this->createDirectorToken([1], 'director-att-late@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-att-late@example.com');
        $student = $this->createStudent(1, '點名遲到測試');

        $courseId = $this->bootstrapCourse($token, $student->id, $teacherId, $beforeRemaining);
        $classSession = $this->pastClassSession($courseId);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'ClassSessionID' => $classSession->id,
            'Status' => 'late',
        ])->assertCreated();

        $after = StudentClass::findOrFail($courseId);
        $this->assertSame($beforeRemaining - 1, (int) $after->RemainingSessions);
        $this->assertSame(1, (int) $after->UsedSessions);
    }

    public function test_absent_attendance_does_not_deduct_sessions(): void
    {
        $beforeRemaining = 7;
        $token = $this->createDirectorToken([1], 'director-att-absent@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-att-absent@example.com');
        $student = $this->createStudent(1, '點名缺席測試');

        $courseId = $this->bootstrapCourse($token, $student->id, $teacherId, $beforeRemaining);
        $classSession = $this->pastClassSession($courseId);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'ClassSessionID' => $classSession->id,
            'Status' => 'absent',
        ])->assertCreated();

        $after = StudentClass::findOrFail($courseId);
        $this->assertSame($beforeRemaining, (int) $after->RemainingSessions);
        $this->assertSame(0, (int) $after->UsedSessions);

        $signIn = StudentSignIn::where('ClassSessionID', $classSession->id)->first();
        $this->assertNotNull($signIn);
        $this->assertFalse((bool) $signIn->SessionDeducted);
    }

    public function test_parent_dashboard_attendance_shows_late_label(): void
    {
        $token = $this->createDirectorToken([1], 'director-parent-late@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-parent-late@example.com');
        $student = $this->createStudent(1, '家長遲到標籤測試', '0911999888');

        $courseId = $this->bootstrapCourse($token, $student->id, $teacherId, 6);
        $classSession = $this->pastClassSession($courseId);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'ClassSessionID' => $classSession->id,
            'Status' => 'late',
        ])->assertCreated();

        $login = $this->postJson('/api/v1/parent/login', [
            'Name' => $student->name,
            'Phone' => '0911999888',
        ])->assertOk();

        $parentToken = (string) $login->json('token');
        $this->assertNotSame('', $parentToken);

        $dash = $this->withHeaders([
            'Authorization' => "Bearer {$parentToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/parent/dashboard')->assertOk();

        $history = $dash->json('attendance_history');
        $this->assertIsArray($history);
        $found = false;
        foreach ($history as $row) {
            if (($row['Status'] ?? null) === 'late' && ($row['status_label'] ?? '') === '遲到' && ($row['is_late'] ?? false) === true) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Parent dashboard should expose late rows with 遲到 and is_late');
    }

    public function test_student_classes_index_uses_completed_sessions_when_sign_in_not_deducted(): void
    {
        $token = $this->createDirectorToken([1], 'director-index-observed@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-index-observed@example.com');
        $student = $this->createStudent(1, '列表剩餘堂數對齊測試');

        $purchased = 8;
        $courseId = $this->bootstrapCourse($token, $student->id, $teacherId, $purchased);

        for ($i = 1; $i <= 5; $i++) {
            ClassSession::create([
                'StudentClassID' => $courseId,
                'SessionDate' => now()->subDays(10 + $i)->toDateString(),
                'StartTime' => '16:00',
                'EndTime' => '18:00',
                'Status' => 'completed',
                'Note' => '',
            ]);
        }

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1&student_id=' . $student->id . '&per_page=100')
            ->assertOk();

        $rows = $res->json('data');
        $this->assertIsArray($rows);
        $hit = null;
        foreach ($rows as $row) {
            $rid = (int) ($row['id'] ?? $row['ID'] ?? 0);
            if ($rid === $courseId) {
                $hit = $row;
                break;
            }
        }
        $this->assertNotNull($hit, 'Course row should appear in index');
        $this->assertSame(3, (int) ($hit['remaining_sessions'] ?? -1), '8 購買 − 5 堂 completed = 3 剩餘');
        $this->assertSame(5, (int) ($hit['sessions_used'] ?? -1));
    }

    private function bootstrapCourse(string $token, int $studentId, int $teacherId, int $remaining): int
    {
        unset($token);

        $course = StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-03-01',
            'EndDate' => null,
            'TotalHours' => 40,
            'Memo' => 'regression',
            'Paid' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => $remaining,
            'RemainingSessions' => $remaining,
            'UsedSessions' => 0,
            'SessionDuration' => 120,
            'ClassType' => 'one_on_one',
            'RoomID' => 'R1',
            'MDate' => now(),
            'Rate' => 500,
        ]);
        $this->assertTrue($course->ID > 0);

        return (int) $course->ID;
    }

    private function pastClassSession(int $courseId): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'completed',
            'Note' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createStudent(int $campusId, string $name, ?string $phone = null): Student
    {
        return Student::create(array_filter([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => $phone,
        ], fn ($v) => $v !== null));
    }

    /**
     * @param  array<int>  $campusIds
     */
    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $raw = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $raw,
            'expires_at' => now()->addDay(),
        ]);

        return $raw;
    }

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922000000',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return (int) $teacher->id;
    }
}
