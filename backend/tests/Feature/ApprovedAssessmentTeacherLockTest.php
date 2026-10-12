<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #3824 (Founder GO 2026-10-08): a filled AND approved assessment keeps its teacher from every entry point.
 * Unfilled / filled-but-unapproved rows keep the legitimate correction behaviour. Synthetic data only.
 */
class ApprovedAssessmentTeacherLockTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-04-10';

    private string $token;
    private int $aId;
    private int $bId;
    private StudentClass $sc;
    private StudentClass $scB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
        config(['feature_flags.values' => ['FEATURE_SCHEDULE_OCCURRENCE_V2' => false]]);
        $mk = function (string $name, string $type, int $admin) {
            $u = User::create(['LoginName' => "{$name}@lock3824.example.com", 'Name' => $name, 'PSW' => 'x', 'type' => $type,
                'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false, 'TeachingSessionCount' => 0]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);

            return $u;
        };
        $dir = $mk('dir3824', 'A', 1);
        $this->aId = (int) $mk('teachera', 'T', 0)->id;
        $this->bId = (int) $mk('teacherb', 'T', 0)->id;
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dir->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
        $stu = Student::create(['name' => 'X student', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $contract = fn (int $t) => StudentClass::create([
            'StudentID' => $stu->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $t, 'by1' => 1,
            'Period' => 8, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000,
            'SessionCount' => 8, 'RemainingSessions' => 5, 'SessionDuration' => 120, 'week' => 5, 'time' => '16:00:00',
            'class_type' => 'one_on_one', 'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
        $this->sc = $contract($this->aId);
        $this->scB = $contract($this->bId);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- protected: filled AND approved -> every entry point refuses, nothing changes -------------------------

    public function test_single_substitute_is_refused(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'approved', true);
        $this->assertLocked($lr, fn () => $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId]));
    }

    public function test_restore_original_teacher_is_refused(): void
    {
        $s = $this->mkSession('scheduled');
        $lr = $this->lr($s, 'pending', true);
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
        $this->approve($lr, $this->bId);
        $this->assertLocked($lr, fn () => $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->aId]), $this->bId);
    }

    public function test_substitute_undo_is_refused(): void
    {
        $s = $this->mkSession('scheduled');
        $lr = $this->lr($s, 'pending', true);
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
        $this->approve($lr, $this->bId);
        $this->assertLocked($lr, fn () => $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute/undo"), $this->bId);
    }

    public function test_batch_substitute_is_refused(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'approved', true);
        $this->assertLocked($lr, fn () => $this->api()->postJson('/api/v1/teacher-leaves/batch-substitute', [
            'assignments' => [['class_session_id' => $s->id, 'substitute_teacher_id' => $this->bId]],
        ]), null, 422); // atomic precheck rejects the whole batch
    }

    public function test_change_teacher_single_and_series_are_refused(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'approved', true);
        $this->assertLocked($lr, fn () => $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId]));
        $this->assertLocked($lr, fn () => $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId, 'update_class' => true]));
        $this->assertSame($this->aId, (int) $this->sc->fresh()->TeacherID, 'series flag must not move the contract either');
    }

    /** @dataProvider flags */
    public function test_contract_teacher_change_keeps_a_locked_same_day_occurrence(bool $flag): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-18 20:00:00', 'Asia/Taipei')); // the 16:00-18:00 lesson is over
        config(['feature_flags.values' => ['FEATURE_SCHEDULE_OCCURRENCE_V2' => false, 'FEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_1' => $flag]]);
        $s = $this->mkSession('attended', '2026-04-18');
        $lr = $this->lr($s, 'approved', true, '2026-04-18');
        $before = $this->snapshot($lr);
        $this->api()->putJson("/api/v1/student-classes/{$this->sc->ID}", ['teacher_id' => $this->bId])->assertOk();
        $after = $this->snapshot($lr);
        $this->assertSame($before['lr'], $after['lr'], 'LR untouched');
        $this->assertSame($before['tsc'], $after['tsc'], 'TeachingSessionCount');
        $this->assertSame($before['units'], $after['units'], 'subject units');
        $this->assertSame($this->aId, (int) \App\Services\SubstituteScheduleService::effectiveInstructorUserId((int) $this->sc->ID, '2026-04-18', $this->bId, '16:00'), 'occurrence teacher');
    }

    public static function flags(): array
    {
        return ['flag off' => [false], 'flag on' => [true]];
    }

    public function test_reassign_to_a_different_teachers_contract_is_refused_but_same_teacher_is_allowed(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'approved', true);
        $this->assertLocked($lr, fn () => $this->api()->postJson("/api/v1/class-sessions/{$s->id}/reassign-contract", ['new_student_class_id' => $this->scB->ID, 'reason' => 'synthetic']));
        $this->assertSame((int) $this->sc->ID, (int) $s->fresh()->StudentClassID);

        $this->scB->update(['TeacherID' => $this->aId]);
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/reassign-contract", ['new_student_class_id' => $this->scB->ID, 'reason' => 'synthetic'])->assertOk();
        $this->assertSame((int) $this->scB->ID, (int) $s->fresh()->StudentClassID);
    }

    // ---- NOT protected: legitimate corrections still work and are audited -------------------------------------

    public function test_blank_or_system_filled_approved_row_can_still_be_corrected(): void
    {
        foreach (['', '（系統補登/扣除堂數）', '（評量表）'] as $i => $content) {
            $s = $this->mkSession('attended', '2026-04-0' . ($i + 1));
            $lr = $this->lr($s, 'approved', false, '2026-04-0' . ($i + 1), $content);
            $tscA = (int) User::find($this->aId)->TeachingSessionCount;
            $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId, 'reason' => 'wrongly named at attendance'])->assertOk();
            $this->assertSame($this->bId, (int) $lr->fresh()->TeacherID);
            $this->assertSame($tscA - 1, (int) User::find($this->aId)->TeachingSessionCount, 'moved once, no double count');
            $this->assertDatabaseHas('learning_record_teacher_changes', ['learning_record_id' => $lr->id, 'old_teacher_id' => $this->aId, 'new_teacher_id' => $this->bId, 'reason' => 'wrongly named at attendance']);
        }
    }

    public function test_filled_but_unapproved_row_keeps_old_behaviour(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'pending', true);
        $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId, 'reason' => 'x'])->assertOk();
        $this->assertSame($this->bId, (int) $lr->fresh()->TeacherID);
        $this->assertSame(0, (int) User::find($this->aId)->TeachingSessionCount);
        $this->assertSame(0, (int) User::find($this->bId)->TeachingSessionCount, 'pending never counted');
    }

    public function test_correction_path_for_a_filled_approved_row_is_rollback_then_audited_change(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'approved', true);
        $this->api()->postJson("/api/v1/learning-records/{$lr->id}/rollback-approval", ['DirectorID' => 1])->assertOk();
        $this->assertSame(0, (int) User::find($this->aId)->TeachingSessionCount);
        $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId, 'reason' => 'after rollback'])->assertOk();
        $this->assertSame($this->bId, (int) $lr->fresh()->TeacherID);
        $this->assertSame(0, (int) User::find($this->bId)->TeachingSessionCount, 'rolled-back row is not counted for B');
        $this->assertDatabaseHas('learning_record_teacher_changes', ['learning_record_id' => $lr->id, 'new_teacher_id' => $this->bId, 'reason' => 'after rollback']);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    /** Request must be refused (409) and leave LR, TeachingSessionCount, subject units, schedules and audit rows identical. */
    private function assertLocked(LearningRecord $lr, \Closure $call, ?int $lrTeacher = null, int $status = 409): void
    {
        $lrTeacher ??= $this->aId;
        $before = $this->snapshot($lr);
        $this->assertSame($lrTeacher, (int) $before['lr']['TeacherID']);
        $this->assertNotEmpty($before['units'], 'fixture must produce subject units');
        $call()->assertStatus($status);
        $after = $this->snapshot($lr);
        $this->assertSame($before['lr'], $after['lr'], 'assessment row untouched');
        $this->assertSame($before['tsc'], $after['tsc'], 'TeachingSessionCount untouched');
        $this->assertSame($before['units'], $after['units'], 'subject-unit read model untouched');
        $this->assertSame($before['schedules'], $after['schedules'], 'schedules/pins untouched');
        $this->assertSame($before['audit'], $after['audit'], 'no teacher-change audit row for a refused request');
        $this->assertSame($before['session'], $after['session'], 'session untouched');
    }

    private function snapshot(LearningRecord $lr): array
    {
        $date = (string) substr((string) DB::table('LearningRecord')->where('id', $lr->id)->value('SessionDate'), 0, 10);
        $units = $this->api()->getJson("/api/v1/finance/subject-units?branch_id=1&start={$date}&end={$date}")->json('teachers') ?? [];

        return [
            'lr' => (array) DB::table('LearningRecord')->where('id', $lr->id)->first(),
            'tsc' => User::whereIn('id', [$this->aId, $this->bId])->orderBy('id')->pluck('TeachingSessionCount', 'id')->all(),
            'units' => $units,
            'schedules' => DB::table('schedules')->orderBy('id')->get()->toJson(),
            'audit' => DB::table('learning_record_teacher_changes')->count(),
            'session' => (array) DB::table('ClassSession')->where('id', $lr->ClassSessionID)->first(),
        ];
    }

    private function mkSession(string $status, string $date = self::DATE): ClassSession
    {
        $s = ClassSession::create(['StudentClassID' => $this->sc->ID, 'SessionDate' => $date, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => $status]);
        if ($status === 'attended') {
            StudentSignIn::create([
                'StudentClassID' => $this->sc->ID, 'StudentID' => $this->sc->StudentID, 'TeacherID' => $this->aId, 'RecordedByUserID' => $this->aId,
                'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => "{$date} 16:05:00", 'MDT' => now(),
                'ClassSessionID' => $s->id, 'Status' => 'present', 'SessionDeducted' => 1,
            ]);
        }

        return $s;
    }

    private function lr(ClassSession $s, string $status, bool $filled, ?string $date = null, string $content = '老師實際填寫的評量正文'): LearningRecord
    {
        $lr = LearningRecord::create([
            'StudentClassID' => $s->StudentClassID, 'ClassSessionID' => $s->id, 'TeacherID' => $this->aId, 'Subject' => 'Math',
            'Content' => $filled ? '老師實際填寫的評量正文' : $content, 'Progress' => $filled ? '第三章' : null,
            'Status' => $status, 'ApprovedBy' => $status === 'approved' ? 1 : null, 'ApprovedAt' => $status === 'approved' ? now() : null,
            'SessionDate' => $date ?? $s->SessionDate, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'SessionDeducted' => true,
        ]);
        if ($status === 'approved') {
            User::whereKey($this->aId)->increment('TeachingSessionCount');
        }

        return $lr;
    }

    /** Simulate the director approving a (substituted) assessment owned by $teacherId. */
    private function approve(LearningRecord $lr, int $teacherId): void
    {
        $lr->update(['Status' => 'approved', 'ApprovedBy' => 1, 'ApprovedAt' => now(), 'TeacherID' => $teacherId]);
        User::whereKey($teacherId)->increment('TeachingSessionCount');
    }

    private function api()
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json']);
    }
}
