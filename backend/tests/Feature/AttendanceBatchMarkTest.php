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

class AttendanceBatchMarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_mark_all_present(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = $this->createTeacher(1);
        $student = $this->createStudent(1, '批次到班A');
        $courseId = $this->bootstrapCourse($student->id, $teacherId, 10);

        $cs1 = $this->pastClassSession($courseId, '16:00', '17:00');
        $cs2 = $this->pastClassSession($courseId, '17:00', '18:00');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance/batch-mark', [
            'items' => [
                ['ClassSessionID' => $cs1->id, 'StudentID' => $student->id, 'StudentClassID' => $courseId, 'Status' => 'present', 'mark_mode' => 'arrival'],
                ['ClassSessionID' => $cs2->id, 'StudentID' => $student->id, 'StudentClassID' => $courseId, 'Status' => 'present', 'mark_mode' => 'arrival'],
            ],
        ])->assertOk();

        $body = $res->json();
        $this->assertSame(2, $body['total']);
        $this->assertSame(2, $body['success_count']);
        $this->assertSame(0, $body['fail_count']);
        $this->assertCount(2, $body['results']);
        $this->assertTrue($body['results'][0]['success']);
        $this->assertTrue($body['results'][1]['success']);

        $after = StudentClass::findOrFail($courseId);
        $this->assertSame(8, (int) $after->RemainingSessions);
        $this->assertSame(2, (int) $after->UsedSessions);
    }

    public function test_batch_max_50_items(): void
    {
        $token = $this->createDirectorToken([1]);
        $items = array_fill(0, 51, [
            'ClassSessionID' => 1,
            'StudentID' => 1,
            'StudentClassID' => 1,
            'Status' => 'present',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance/batch-mark', ['items' => $items])
            ->assertStatus(422);
    }

    public function test_batch_partial_failure_idempotency(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = $this->createTeacher(1);
        $student = $this->createStudent(1, '批次部分失敗');
        $courseId = $this->bootstrapCourse($student->id, $teacherId, 10);

        $cs1 = $this->pastClassSession($courseId, '16:00', '17:00');
        $cs2 = $this->pastClassSession($courseId, '17:00', '18:00');

        // Pre-mark cs1 so it will 409 on the batch
        StudentSignIn::create([
            'StudentClassID' => $courseId,
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'SignInDT' => now(),
            'MDT' => now(),
            'ClassSessionID' => $cs1->id,
            'Status' => 'present',
            'CampusID' => 1,
            'SessionDeducted' => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance/batch-mark', [
            'items' => [
                ['ClassSessionID' => $cs1->id, 'StudentID' => $student->id, 'StudentClassID' => $courseId, 'Status' => 'present', 'mark_mode' => 'arrival'],
                ['ClassSessionID' => $cs2->id, 'StudentID' => $student->id, 'StudentClassID' => $courseId, 'Status' => 'present', 'mark_mode' => 'arrival'],
            ],
        ])->assertOk();

        $body = $res->json();
        $this->assertSame(2, $body['total']);
        $this->assertSame(1, $body['success_count']);
        $this->assertSame(1, $body['fail_count']);

        $this->assertFalse($body['results'][0]['success']);
        $this->assertSame(409, $body['results'][0]['status_code']);
        $this->assertTrue($body['results'][1]['success']);
    }

    public function test_teacher_cannot_batch_mark_others_classes(): void
    {
        $teacherUser = User::create([
            'LoginName' => 'teacher-batch-forbidden@example.com',
            'Name' => '他校老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0900000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacherUser->id, 'Admin' => 0, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacherUser->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        $otherTeacherId = $this->createTeacher(1);
        $student = $this->createStudent(1, '他人班級學生');
        $courseId = $this->bootstrapCourse($student->id, $otherTeacherId, 10);
        $cs = $this->pastClassSession($courseId, '16:00', '18:00');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$raw}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance/batch-mark', [
            'items' => [
                ['ClassSessionID' => $cs->id, 'StudentID' => $student->id, 'StudentClassID' => $courseId, 'Status' => 'present', 'mark_mode' => 'arrival'],
            ],
        ]);

        $body = $res->json();
        $this->assertSame(0, $body['success_count'] ?? -1);
        $this->assertFalse($body['results'][0]['success'] ?? true);
    }

    public function test_batch_empty_items_rejected(): void
    {
        $token = $this->createDirectorToken([1]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance/batch-mark', ['items' => []])
            ->assertStatus(422);
    }

    public function test_batch_invalid_status_rejected(): void
    {
        $token = $this->createDirectorToken([1]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance/batch-mark', [
            'items' => [
                ['ClassSessionID' => 1, 'StudentID' => 1, 'StudentClassID' => 1, 'Status' => 'invalid_status'],
            ],
        ])->assertStatus(422);
    }

    // ── Helpers ──

    private function bootstrapCourse(int $studentId, int $teacherId, int $remaining): int
    {
        $course = StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-03-01',
            'TotalHours' => 40,
            'Memo' => 'batch-test',
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

        return (int) $course->ID;
    }

    private function pastClassSession(int $courseId, string $start = '16:00', string $end = '18:00'): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => $start,
            'EndTime' => $end,
            'Status' => 'scheduled',
            'Note' => '',
        ]);
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createDirectorToken(array $campusIds): string
    {
        static $i = 0;
        $i++;
        $user = User::create([
            'LoginName' => "director-batch-{$i}@example.com",
            'Name' => '主任',
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

    private function createTeacher(int $campusId): int
    {
        static $j = 0;
        $j++;
        $teacher = User::create([
            'LoginName' => "teacher-batch-{$j}@example.com",
            'Name' => '老師',
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
