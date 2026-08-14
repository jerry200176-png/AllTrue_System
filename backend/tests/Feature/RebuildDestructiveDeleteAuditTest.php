<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\ScheduleAuditLog;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * in-app #219 follow-up: maybeRebuildSessionsAfterUpdate()'s full-rebuild path used to
 * mass-delete ClassSession/LearningRecord via query-builder ::delete(), which bypasses
 * Eloquent model events entirely — so the existing schedule_audit_logs trail silently
 * missed the deletion, leaving no way to recover what was destroyed (this is exactly
 * what happened to a real trial course's evaluation content in production). Both
 * deletions must now leave a recoverable audit trail.
 */
class RebuildDestructiveDeleteAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_full_rebuild_logs_deleted_learning_record_content_before_wiping_it(): void
    {
        $token = $this->createDirectorToken([1]);

        $student = Student::create([
            'name' => '重建審計測試學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        // No StudentSignIn, no approved LearningRecord → hasImmutableSessionHistory() is
        // false → the StartDate change below takes the destructive full-rebuild path.
        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-08-06',
            'EndDate' => '2026-08-06',
            'TotalHours' => 2,
            'Charge' => 500,
            'Paid' => 1,
            'Rate' => 500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 1,
            'SessionDuration' => 120,
            'RemainingSessions' => 0,
            'UsedSessions' => 1,
            'ClassType' => 'trial',
            'week' => 2,
            'time' => '18:00:00',
        ]);

        $session = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-08-06',
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'attended',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $course->ID,
            'ClassSessionID' => $session->id,
            'TeacherID' => 99,
            'Content' => '學生上課表現積極，數學運算已掌握，下次加強應用題。',
            'Subject' => '數學',
            'SessionDate' => '2026-08-06',
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'submitted',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'first_class_date' => '2026-06-18',
            'end_date' => '2026-06-18',
        ]);

        $res->assertOk();

        // The original session/record must actually be gone (this is what the rebuild does).
        $this->assertDatabaseMissing('ClassSession', ['id' => $session->id]);
        $this->assertDatabaseMissing('LearningRecord', ['id' => $record->id]);

        // But unlike before, deleting them must now leave a recoverable trace.
        $sessionDeleteLog = ScheduleAuditLog::where('session_id', $session->id)
            ->where('action_type', 'delete')
            ->where('description', 'like', '刪除課堂%')
            ->first();
        $this->assertNotNull(
            $sessionDeleteLog,
            'ClassSession deletion during full rebuild must be logged (per-row delete(), not mass ::delete())'
        );
        $this->assertSame('attended', $sessionDeleteLog->old_data['Status'] ?? null);

        $recordDeleteLog = ScheduleAuditLog::where('action_type', 'delete')
            ->where('session_id', $session->id)
            ->where('description', 'like', '%評量記錄%')
            ->first();
        $this->assertNotNull(
            $recordDeleteLog,
            'LearningRecord content must be snapshotted before the full-rebuild delete wipes it'
        );
        $this->assertSame(
            '學生上課表現積極，數學運算已掌握，下次加強應用題。',
            $recordDeleteLog->old_data['Content'] ?? null,
            'The evaluation text itself must be recoverable from the audit log'
        );
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-rebuild-audit-' . bin2hex(random_bytes(4)) . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
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
}
