<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Models\UserEngagementXpEvent;
use App\Services\UserEngagementXpAwardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserEngagementXpLearningRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_with_progress_awards_xp_once_per_learning_record(): void
    {
        $token = $this->createDirectorToken([1], 'dir-xp-1@example.com');
        $teacherId = $this->createTeacher(1, 't-xp-1@example.com');
        $student = $this->createStudent(1, '學生XP1');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '23:00',
        ]);
        $courseId = (int) $course->ID;

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '待審',
            'Progress' => '本堂授課進度已填',
            'Status' => 'pending',
            'SessionDate' => $classSession->SessionDate,
            'StartTime' => $classSession->StartTime,
            'EndTime' => $classSession->EndTime,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}/approve", [])
            ->assertOk();

        $this->assertDatabaseHas('user_engagement_xp_events', [
            'idempotency_key' => UserEngagementXpAwardService::idempotencyKeyForLearningRecord((int) $record->id),
            'user_id' => $teacherId,
            'xp_delta' => UserEngagementXpAwardService::XP_PER_APPROVED_LR,
        ]);
        $this->assertDatabaseHas('user_engagement', [
            'user_id' => $teacherId,
            'xp_total' => UserEngagementXpAwardService::XP_PER_APPROVED_LR,
            'role_track' => 'teacher',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}/approve", [])
            ->assertStatus(409);

        $this->assertSame(1, UserEngagementXpEvent::query()->count());
    }

    public function test_approve_without_progress_does_not_award_xp(): void
    {
        $token = $this->createDirectorToken([1], 'dir-xp-2@example.com');
        $teacherId = $this->createTeacher(1, 't-xp-2@example.com');
        $student = $this->createStudent(1, '學生XP2');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '23:00',
        ]);
        $courseId = (int) $course->ID;

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '空白',
            'Progress' => '',
            'Status' => 'pending',
            'SessionDate' => $classSession->SessionDate,
            'StartTime' => $classSession->StartTime,
            'EndTime' => $classSession->EndTime,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}/approve", [])
            ->assertOk();

        $this->assertDatabaseMissing('user_engagement_xp_events', [
            'learning_record_id' => $record->id,
        ]);
    }

    public function test_rollback_revokes_xp(): void
    {
        $token = $this->createDirectorToken([1], 'dir-xp-3@example.com');
        $teacherId = $this->createTeacher(1, 't-xp-3@example.com');
        $student = $this->createStudent(1, '學生XP3');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '23:00',
        ]);
        $courseId = (int) $course->ID;

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '評量',
            'Progress' => '進度',
            'Status' => 'pending',
            'SessionDate' => $classSession->SessionDate,
            'StartTime' => $classSession->StartTime,
            'EndTime' => $classSession->EndTime,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}/approve", [])
            ->assertOk();

        $this->assertDatabaseHas('user_engagement', ['user_id' => $teacherId, 'xp_total' => 10]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}/rollback-approval", [])
            ->assertOk();

        $this->assertDatabaseMissing('user_engagement_xp_events', [
            'idempotency_key' => UserEngagementXpAwardService::idempotencyKeyForLearningRecord((int) $record->id),
        ]);
        $this->assertDatabaseHas('user_engagement', ['user_id' => $teacherId, 'xp_total' => 0]);
    }

    public function test_voiding_approved_record_revokes_xp(): void
    {
        $token = $this->createDirectorToken([1], 'dir-xp-4@example.com');
        $teacherId = $this->createTeacher(1, 't-xp-4@example.com');
        $student = $this->createStudent(1, '學生XP4');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '23:00',
        ]);
        $courseId = (int) $course->ID;

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '評量',
            'Progress' => '有進度',
            'Status' => 'pending',
            'SessionDate' => $classSession->SessionDate,
            'StartTime' => $classSession->StartTime,
            'EndTime' => $classSession->EndTime,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}/approve", [])
            ->assertOk();

        $this->assertDatabaseHas('user_engagement', ['user_id' => $teacherId, 'xp_total' => 10]);

        $record->refresh();
        $record->VoidedAt = now();
        $record->VoidReason = 'test_void';
        $record->save();

        $this->assertDatabaseMissing('user_engagement_xp_events', [
            'learning_record_id' => $record->id,
        ]);
        $this->assertDatabaseHas('user_engagement', ['user_id' => $teacherId, 'xp_total' => 0]);
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

    /**
     * POST /api/v1/student-classes is retired (410); tests seed StudentClass directly.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createStudentClassForTest(int $studentId, int $teacherId, array $overrides = []): StudentClass
    {
        $o = array_merge([
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'rate_per_30min' => 500,
            'duration_hours' => 2,
            'payment_type' => 'session',
            'sessions_purchased' => 8,
            'sessions_used' => 0,
            'remaining_sessions' => 8,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '23:00',
            'Memo' => '測試課程',
        ], $overrides);

        $subjectKey = strtolower((string) $o['subject']);
        $subjectId = match ($subjectKey) {
            'math', '數學' => 1,
            default => 1,
        };

        $course = StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => $subjectId,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => $o['first_class_date'],
            'EndDate' => null,
            'TotalHours' => 40,
            'Memo' => $o['Memo'],
            'Charge' => null,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => (float) $o['rate_per_30min'],
            'LearnTimeID' => null,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => $o['schedule_mode'] ?? 'count',
            'SessionCount' => (int) $o['sessions_purchased'],
            'RemainingSessions' => (int) $o['remaining_sessions'],
            'UsedSessions' => (int) $o['sessions_used'],
            'SessionDuration' => max(30, (int) $o['duration_hours'] * 60),
            'ClassType' => $o['class_type'],
        ]);

        $this->assertTrue($course->ID > 0, 'Course ID should be available.');

        return $course;
    }
}
