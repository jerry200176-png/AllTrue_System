<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reviewer recorded by a learning-record review action is the logged-in user (Founder 2026-10-22). A DirectorID
 * in the body naming a colleague is ignored: ApprovedBy, CreatedByUserID, the approval sign-in and the reversal
 * ledger row all carry the caller. Who may review is unchanged. Synthetic data only; fixtures follow
 * LearningRecordReviewCampusScopeTest.
 */
class LearningRecordReviewerFromLoginTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-04-10';

    private int $teacherId;
    private int $directorId;
    private int $colleagueId;
    private string $directorToken;
    private StudentClass $course;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
        $this->teacherId = (int) $this->user('teacher-login', 'T', [1], 0)->id;
        $director = $this->user('dir-login', 'A', [1], 1);
        $this->directorId = (int) $director->id;
        $this->directorToken = $this->token($director);
        $this->colleagueId = (int) $this->user('dir-colleague', 'A', [1], 1)->id;
        $student = Student::create(['name' => 'Reviewer student', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $this->course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $this->teacherId, 'by1' => 1,
            'Period' => 8, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000,
            'SessionCount' => 8, 'RemainingSessions' => 8, 'UsedSessions' => 0, 'SessionDuration' => 120, 'week' => 5, 'time' => '16:00:00',
            'class_type' => 'one_on_one', 'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_approve_records_the_logged_in_director_and_the_sync_uses_that_id(): void
    {
        $record = $this->record('pending');

        $this->api($this->directorToken)
            ->postJson("/api/v1/learning-records/{$record->id}/approve", ['DirectorID' => $this->colleagueId])
            ->assertOk();

        $this->assertSame($this->directorId, (int) $record->fresh()->ApprovedBy);
        $signIn = $this->approvalSignIn($record);
        $this->assertNotNull($signIn, 'approve writes the approval sign-in');
        $this->assertSame($this->directorId, (int) $signIn->RecordedByUserID, 'syncOnApprove receives the logged-in id');
    }

    public function test_rollback_records_the_logged_in_director_on_the_void_and_the_ledger(): void
    {
        $record = $this->record('pending');
        $this->api($this->directorToken)->postJson("/api/v1/learning-records/{$record->id}/approve", [])->assertOk();
        $this->assertTrue((bool) $this->approvalSignIn($record)->SessionDeducted, 'precondition: approve deducted the session');

        $this->api($this->directorToken)
            ->postJson("/api/v1/learning-records/{$record->id}/rollback-approval", ['DirectorID' => $this->colleagueId, 'ReviewNote' => 'recheck'])
            ->assertOk();

        $this->assertNull($record->fresh()->ApprovedBy);
        $signIn = $this->approvalSignIn($record);
        $this->assertNotNull($signIn->VoidedAt);
        $this->assertSame($this->directorId, (int) $signIn->VoidedByUserID, 'syncOnRollback receives the logged-in id');
        $reversedBy = SessionDeductionLedger::where('class_session_id', $record->ClassSessionID)->where('event_type', 'reverse')->value('created_by');
        $this->assertSame($this->directorId, (int) $reversedBy);
    }

    public function test_batch_approve_records_the_logged_in_director_and_no_longer_needs_director_id(): void
    {
        $forged = $this->record('pending');
        $this->api($this->directorToken)
            ->postJson('/api/v1/learning-records/batch-approve', ['ids' => [$forged->id], 'DirectorID' => $this->colleagueId, 'branch_id' => 1])
            ->assertOk()->assertJsonPath('approved', 1);
        $omitted = $this->record('pending', '2026-04-11');
        $this->api($this->directorToken)
            ->postJson('/api/v1/learning-records/batch-approve', ['ids' => [$omitted->id], 'branch_id' => 1])
            ->assertOk()->assertJsonPath('approved', 1);

        foreach ([$forged, $omitted] as $record) {
            $this->assertSame($this->directorId, (int) $record->fresh()->ApprovedBy);
            $this->assertSame($this->directorId, (int) $this->approvalSignIn($record)->RecordedByUserID);
        }
    }

    public function test_reject_and_request_changes_record_the_logged_in_director(): void
    {
        $single = ['reject' => $this->record('pending'), 'request-changes' => $this->record('pending', '2026-04-11')];
        foreach ($single as $action => $record) {
            $this->api($this->directorToken)
                ->postJson("/api/v1/learning-records/{$record->id}/{$action}", ['DirectorID' => $this->colleagueId, 'ReviewNote' => 'x'])
                ->assertOk();
        }
        $batch = ['batch-reject' => $this->record('pending', '2026-04-12'), 'batch-request-changes' => $this->record('pending', '2026-04-13')];
        foreach ($batch as $action => $record) {
            $this->api($this->directorToken)
                ->postJson("/api/v1/learning-records/{$action}", ['ids' => [$record->id], 'DirectorID' => $this->colleagueId, 'ReviewNote' => 'x'])
                ->assertOk();
        }

        foreach (array_merge(array_values($single), array_values($batch)) as $record) {
            $this->assertSame($this->directorId, (int) $record->fresh()->ApprovedBy);
        }
        $this->assertSame(2, LearningRecord::where('Status', 'rejected')->count());
        $this->assertSame(2, LearningRecord::where('Status', 'changes_requested')->count());
    }

    public function test_backfill_approval_records_the_logged_in_director_as_creator_and_approver(): void
    {
        $session = ClassSession::create(['StudentClassID' => $this->course->ID, 'SessionDate' => '2026-04-11', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'completed']);
        $created = $this->api($this->directorToken)->postJson('/api/v1/learning-records/backdoor-approve', [
            'StudentClassID' => $this->course->ID, 'TeacherID' => $this->teacherId, 'DirectorID' => $this->colleagueId, 'SessionDate' => '2026-04-11',
        ])->assertCreated();
        $record = LearningRecord::findOrFail((int) $created->json('id'));
        $this->assertSame((int) $session->id, (int) $record->ClassSessionID);
        $this->assertSame($this->directorId, (int) $record->CreatedByUserID);
        $this->assertSame($this->directorId, (int) $record->ApprovedBy);

        // An existing pending record is approved in place; DirectorID is no longer required.
        $pending = $this->record('pending');
        $this->api($this->directorToken)->postJson('/api/v1/learning-records/backdoor-approve', [
            'StudentClassID' => $this->course->ID, 'TeacherID' => $this->teacherId, 'SessionDate' => self::DATE,
        ])->assertCreated();
        $this->assertSame('approved', (string) $pending->fresh()->Status);
        $this->assertSame($this->directorId, (int) $pending->fresh()->ApprovedBy);
    }

    public function test_naming_a_campus_director_does_not_let_other_callers_review(): void
    {
        $pending = $this->record('pending');
        $approved = $this->record('approved', '2026-04-11');
        $body = ['DirectorID' => $this->directorId, 'ReviewNote' => 'x'];
        $foreign = $this->token($this->user('dir-foreign', 'A', [2], 1));
        $teacher = $this->token(User::findOrFail($this->teacherId));
        $recordsBefore = LearningRecord::count();

        foreach (['approve', 'reject', 'request-changes'] as $action) {
            $this->api($foreign)->postJson("/api/v1/learning-records/{$pending->id}/{$action}", $body)->assertForbidden();
            $this->api($teacher)->postJson("/api/v1/learning-records/{$pending->id}/{$action}", $body)->assertForbidden();
            $this->flushHeaders()->postJson("/api/v1/learning-records/{$pending->id}/{$action}", $body)->assertUnauthorized();
        }
        $this->api($foreign)->postJson("/api/v1/learning-records/{$approved->id}/rollback-approval", $body)->assertForbidden();
        $this->api($teacher)->postJson("/api/v1/learning-records/{$approved->id}/rollback-approval", $body)->assertForbidden();
        foreach (['batch-reject', 'batch-request-changes'] as $action) {
            $this->api($foreign)->postJson("/api/v1/learning-records/{$action}", $body + ['ids' => [$pending->id]])->assertForbidden();
        }
        $this->api($foreign)->postJson('/api/v1/learning-records/batch-approve', $body + ['ids' => [$pending->id], 'branch_id' => 1])->assertForbidden();
        $this->api($foreign)->postJson('/api/v1/learning-records/backdoor-approve', $body + [
            'StudentClassID' => $this->course->ID, 'TeacherID' => $this->teacherId, 'SessionDate' => '2026-04-12',
        ])->assertForbidden();

        $this->assertSame('pending', (string) $pending->fresh()->Status);
        $this->assertNull($pending->fresh()->ApprovedBy);
        $this->assertSame('approved', (string) $approved->fresh()->Status);
        $this->assertSame($recordsBefore, LearningRecord::count(), 'no backfill record was created');
        $this->assertSame(0, (int) User::find($this->teacherId)->TeachingSessionCount);
    }

    public function test_the_controller_never_reads_a_client_sent_director_id(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/LearningRecordController.php'));

        $this->assertDoesNotMatchRegularExpression(
            "/\\[\\s*'DirectorID'\\s*\\]|(input|get|post|query|json|only)\\(\\s*\\[?\\s*'DirectorID'/",
            $source,
            'reviewer identity comes from the login (reviewerUserId), never from the request body'
        );
    }

    private function approvalSignIn(LearningRecord $record): ?StudentSignIn
    {
        return StudentSignIn::where('ClassSessionID', (int) $record->ClassSessionID)->where('Memo', 'lr_approve')->first();
    }

    private function record(string $status, string $date = self::DATE): LearningRecord
    {
        $session = ClassSession::create(['StudentClassID' => $this->course->ID, 'SessionDate' => $date, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'scheduled']);

        return LearningRecord::create([
            'StudentClassID' => $this->course->ID, 'ClassSessionID' => $session->id, 'TeacherID' => $this->teacherId, 'Subject' => 'Math',
            'Content' => '老師實際填寫的評量正文', 'Progress' => '第三章', 'Status' => $status,
            'ApprovedBy' => $status === 'approved' ? $this->colleagueId : null, 'ApprovedAt' => $status === 'approved' ? now() : null,
            'SessionDate' => $date, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00',
        ]);
    }

    /** @param list<int> $campusIds */
    private function user(string $name, string $type, array $campusIds, int $admin): User
    {
        $u = User::create(['LoginName' => $name . '-' . uniqid() . '@reviewer.example.com', 'Name' => $name, 'PSW' => 'x', 'type' => $type,
            'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false, 'TeachingSessionCount' => 0]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);
        }

        return $u;
    }

    private function token(User $user): string
    {
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}
