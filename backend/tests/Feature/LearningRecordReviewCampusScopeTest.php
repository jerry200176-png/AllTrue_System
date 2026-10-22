<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Learning-record review actions stay within the director's campuses, like update() and updateTeacher():
 * a director of another campus cannot approve, reject, request changes, roll back, delete or backfill
 * a record of this campus by id. Synthetic data only.
 */
class LearningRecordReviewCampusScopeTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-04-10';

    private int $teacherId;
    private StudentClass $course;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
        $this->teacherId = (int) $this->user('teacher-scope', 'T', [1], 0)->id;
        $student = Student::create(['name' => 'Scope student', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $this->course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $this->teacherId, 'by1' => 1,
            'Period' => 8, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000,
            'SessionCount' => 8, 'RemainingSessions' => 5, 'SessionDuration' => 120, 'week' => 5, 'time' => '16:00:00',
            'class_type' => 'one_on_one', 'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_director_of_another_campus_cannot_review_or_delete_the_record(): void
    {
        $foreign = $this->token([2]);
        $pending = $this->record('pending');
        foreach (['approve', 'request-changes', 'reject'] as $action) {
            $this->api($foreign)->postJson("/api/v1/learning-records/{$pending->id}/{$action}", [])->assertForbidden();
        }
        $this->api($foreign)->deleteJson("/api/v1/learning-records/{$pending->id}")->assertForbidden();
        $this->assertSame('pending', (string) $pending->fresh()->Status);
        $this->assertSame(0, (int) User::find($this->teacherId)->TeachingSessionCount, 'no payroll count from a refused approve');

        $approved = $this->record('approved', '2026-04-03');
        $this->api($foreign)->postJson("/api/v1/learning-records/{$approved->id}/rollback-approval", [])->assertForbidden();
        $this->assertSame('approved', (string) $approved->fresh()->Status);
    }

    public function test_a_director_of_another_campus_cannot_batch_reject_or_request_changes(): void
    {
        $foreign = $this->token([2]);
        $record = $this->record('pending');
        foreach (['batch-reject', 'batch-request-changes'] as $action) {
            $this->api($foreign)->postJson("/api/v1/learning-records/{$action}", ['ids' => [$record->id], 'ReviewNote' => 'x'])->assertForbidden();
        }
        $this->assertSame('pending', (string) $record->fresh()->Status);
        $this->assertSame(0, LearningRecord::whereIn('Status', ['rejected', 'changes_requested'])->count());
    }

    public function test_a_director_of_another_campus_cannot_backfill_an_approval(): void
    {
        $before = LearningRecord::count();
        $this->api($this->token([2]))->postJson('/api/v1/learning-records/backdoor-approve', [
            'StudentClassID' => $this->course->ID, 'TeacherID' => $this->teacherId, 'DirectorID' => 1, 'SessionDate' => self::DATE,
        ])->assertForbidden();
        $this->assertSame($before, LearningRecord::count());
    }

    public function test_directors_of_the_records_campus_keep_access(): void
    {
        $this->api($this->token([1]))->postJson("/api/v1/learning-records/{$this->record('pending')->id}/approve", [])->assertOk();
        $this->api($this->token([2, 1]))->postJson("/api/v1/learning-records/{$this->record('pending', '2026-04-03')->id}/reject", [])->assertOk();
        $this->api($this->token([1]))->postJson('/api/v1/learning-records/batch-request-changes', ['ids' => [$this->record('pending', '2026-04-04')->id]])
            ->assertOk()->assertJsonPath('changed', 1);
    }

    private function record(string $status, string $date = self::DATE): LearningRecord
    {
        $session = ClassSession::create(['StudentClassID' => $this->course->ID, 'SessionDate' => $date, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'attended']);

        return LearningRecord::create([
            'StudentClassID' => $this->course->ID, 'ClassSessionID' => $session->id, 'TeacherID' => $this->teacherId, 'Subject' => 'Math',
            'Content' => '老師實際填寫的評量正文', 'Progress' => '第三章', 'Status' => $status,
            'ApprovedBy' => $status === 'approved' ? 1 : null, 'ApprovedAt' => $status === 'approved' ? now() : null,
            'SessionDate' => $date, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'SessionDeducted' => true,
        ]);
    }

    /** @param list<int> $campusIds */
    private function user(string $name, string $type, array $campusIds, int $admin): User
    {
        $u = User::create(['LoginName' => $name . '-' . uniqid() . '@scope.example.com', 'Name' => $name, 'PSW' => 'x', 'type' => $type,
            'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false, 'TeachingSessionCount' => 0]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);
        }

        return $u;
    }

    /** @param list<int> $campusIds */
    private function token(array $campusIds): string
    {
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $this->user('dir-scope', 'A', $campusIds, 1)->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}
