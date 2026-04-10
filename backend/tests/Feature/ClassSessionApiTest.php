<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClassSessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_fetch_class_sessions_with_learning_record_status(): void
    {
        $token = $this->createDirectorToken([1], 'director-class-session-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-class-session-a@example.com');
        $student = $this->createStudent(1, '課堂來源測試A');
        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId)->assertCreated();
        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'completed',
            'Note' => '',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '測試評量',
            'Status' => 'pending',
            'SessionDate' => $classSession->SessionDate,
            'StartTime' => $classSession->StartTime,
            'EndTime' => $classSession->EndTime,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?branch_id=1&student_class_id={$courseId}&per_page=100");

        $res->assertOk();
        $items = $res->json('data') ?? [];
        $this->assertNotEmpty($items);

        $hit = collect($items)->firstWhere('id', $classSession->id);
        $this->assertNotNull($hit);
        $this->assertSame($courseId, (int) ($hit['student_class_id'] ?? 0));
        $this->assertSame($record->id, (int) ($hit['learning_record_id'] ?? 0));
        $this->assertSame('pending', $hit['learning_record_status'] ?? null);
        $this->assertSame(substr((string) $classSession->SessionDate, 0, 10), $hit['session_date'] ?? null);
    }

    public function test_director_cannot_query_unassigned_branch(): void
    {
        $token = $this->createDirectorToken([1], 'director-class-session-b@example.com');
        $creatorToken = $this->createDirectorToken([2], 'director-class-session-c@example.com');
        $teacherId = $this->createTeacher(2, 'teacher-class-session-b@example.com');
        $student = $this->createStudent(2, '課堂來源測試B');
        $courseRes = $this->createCourseViaApi($creatorToken, $student->id, $teacherId)->assertCreated();
        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);

        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '17:00',
            'EndTime' => '19:00',
            'Status' => 'completed',
            'Note' => '',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?branch_id=2')
            ->assertStatus(403);
    }

    /**
     * @param array<int> $campusIds
     */
    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911111111',
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

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922111111',
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

    private function createCourseViaApi(string $token, int $studentId, int $teacherId): \Illuminate\Testing\TestResponse
    {
        $branchId = (int) (Student::where('id', $studentId)->value('CampusID') ?? 0);
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => $branchId > 0 ? $branchId : 1,
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 1,
            'confirmed_dates' => [now()->subDay()->toDateString()],
            'future_dates' => [],
            'days_of_week' => [1],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);
    }

    private function resolveCourseId(\Illuminate\Testing\TestResponse $courseRes, int $studentId, int $teacherId): int
    {
        $courseId = (int) ($courseRes->json('student_class_id') ?? 0);
        if ($courseId <= 0) {
            $courseId = (int) (DB::table('StudentClass')
                ->where('StudentID', $studentId)
                ->where('TeacherID', $teacherId)
                ->max('ID') ?? 0);
        }
        $this->assertTrue($courseId > 0, 'Course ID should be available.');
        return $courseId;
    }
}

