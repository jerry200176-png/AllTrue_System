<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * in-app #300: approving a learning review must reconcile ops inbox
 * (Notification.ResolvedAt) without requiring a manual sync click.
 */
class LearningRecordApproveResolvesNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_resolves_learning_review_notification(): void
    {
        $token = $this->directorToken([1]);
        $teacherId = $this->teacher(1);

        $student = Student::create([
            'name' => '收件匣學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->toDateString(),
            'TotalHours' => 16,
            'Charge' => 5000,
            'Paid' => 1,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'RemainingSessions' => 5,
            'SessionDuration' => 120,
            'MDate' => now(),
        ]);

        $session = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => now()->toDateString(),
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'attended',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $course->ID,
            'ClassSessionID' => $session->id,
            'TeacherID' => $teacherId,
            'Content' => '待審',
            'Status' => 'pending',
            'Subject' => 'Math',
            'SessionDate' => now()->toDateString(),
            'StartTime' => '10:00',
            'EndTime' => '12:00',
        ]);

        $sourceKey = "learning:1:{$record->id}";
        Notification::create([
            'CampusID' => 1,
            'Type' => 'learning_review',
            'Severity' => 'medium',
            'Title' => '待審評量：收件匣學生',
            'Body' => 'Math',
            'SourceType' => 'LearningRecord',
            'SourceID' => (string) $record->id,
            'SourceKey' => $sourceKey,
            'Payload' => ['record_id' => $record->id],
            'OccurredAt' => now(),
            'ResolvedAt' => null,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}/approve", [
            'DirectorID' => 1,
        ]);
        $res->assertOk();

        $this->assertNotNull(
            Notification::where('SourceKey', $sourceKey)->value('ResolvedAt'),
            'learning_review notification must resolve after approve'
        );
    }

    private function directorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-300-' . bin2hex(random_bytes(3)) . '@test.com',
            'Name' => '主任300',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912000300',
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }
        $tok = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $tok,
            'expires_at' => now()->addDay(),
        ]);

        return $tok;
    }

    private function teacher(int $campusId): int
    {
        $user = User::create([
            'LoginName' => 'teacher-300-' . bin2hex(random_bytes(3)) . '@test.com',
            'Name' => '老師300',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0912' . substr(md5((string) microtime(true)), 0, 6),
        ]);
        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $user->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return (int) $user->id;
    }
}
