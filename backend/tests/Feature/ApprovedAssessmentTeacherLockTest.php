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
    private int $dirId;
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
        $this->dirId = (int) $dir->id;
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

    public function test_backdoor_approve_does_not_resync_a_protected_row(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'approved', true);
        $before = $this->snapshot($lr);
        $this->api()->postJson('/api/v1/learning-records/backdoor-approve', [
            'StudentClassID' => $this->sc->ID, 'TeacherID' => $this->bId, 'DirectorID' => $this->dirId, 'SessionDate' => self::DATE,
        ])->assertStatus(201);
        $after = $this->snapshot($lr);
        $this->assertSame($before['lr'], $after['lr'], 'assessment row untouched');
        $this->assertSame($before['tsc'], $after['tsc']);
        $this->assertSame($before['units'], $after['units']);
        $this->assertSame($before['audit'], $after['audit']);
    }

    // ---- Codex review r4246700730 / 742 / 747 / 758 ----------------------------------------------------------

    public function test_contract_change_locks_only_the_approved_occurrence_not_the_whole_day(): void
    {
        $day = '2026-04-18';
        $locked = $this->mkSession('attended', $day);
        $this->lr($locked, 'approved', true, $day);
        ClassSession::create(['StudentClassID' => $this->sc->ID, 'SessionDate' => $day, 'StartTime' => '20:00:00', 'EndTime' => '22:00:00', 'Status' => 'scheduled']);
        foreach (['16:00' => '18:00', '20:00' => '22:00'] as $st => $en) {
            $this->schedule($day, $st, $en, $this->aId);
        }
        $this->api()->putJson("/api/v1/student-classes/{$this->sc->ID}", ['teacher_id' => $this->bId])->assertOk();
        $this->assertSame([$this->bId], Schedule::where('student_course_id', $this->sc->ID)->whereDate('schedule_date', $day)->where('start_time', '20:00')->pluck('teacher_id')->map(fn ($v) => (int) $v)->unique()->all(), 'mutable 20:00 follows the new teacher');
        $this->assertNotContains($this->bId, Schedule::where('student_course_id', $this->sc->ID)->whereDate('schedule_date', $day)->where('start_time', '16:00')->pluck('teacher_id')->map(fn ($v) => (int) $v)->all(), 'locked 16:00 keeps the old teacher');
    }

    public function test_legacy_schedule_writers_cannot_move_a_protected_occurrence(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'approved', true);
        $row = $this->schedule(self::DATE, '16:00', '18:00', $this->aId);
        $before = $this->snapshot($lr);
        $this->api()->putJson("/api/v1/schedules/{$row->id}", ['teacher_id' => $this->bId])->assertStatus(409);
        $this->api()->postJson('/api/v1/schedules', [
            'student_id' => $this->sc->StudentID, 'teacher_id' => $this->bId, 'day_of_week' => 5, 'start_time' => '16:00', 'end_time' => '18:00',
            'branch_id' => 1, 'schedule_date' => self::DATE, 'student_course_id' => $this->sc->ID, 'status' => 'scheduled', 'original_schedule_id' => $row->id,
        ])->assertStatus(409);
        $after = $this->snapshot($lr);
        $this->assertSame($before['schedules'], $after['schedules'], 'no schedule row written');
        $this->assertSame($before['lr'], $after['lr']);
        $this->assertSame($before['tsc'], $after['tsc']);
        $this->assertSame($before['units'], $after['units']);
    }

    public function test_auto_approval_placeholder_row_can_still_be_corrected(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'approved', false, null, '（系統自動核准）');
        $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId, 'reason' => 'auto row'])->assertOk();
        $this->assertSame($this->bId, (int) $lr->fresh()->TeacherID);
    }

    public function test_race_after_precheck_still_returns_409_for_substitute_and_undo(): void
    {
        $s = $this->mkSession('scheduled');
        $lr = $this->lr($s, 'pending', true);
        $flip = function () use ($lr) {
            \Illuminate\Support\Facades\Event::forget('eloquent.retrieved: ' . LearningRecord::class);
            DB::table('LearningRecord')->where('id', $lr->id)->update(['Status' => 'approved', 'ApprovedAt' => now(), 'ApprovedBy' => 1]);
        };
        \Illuminate\Support\Facades\Event::listen('eloquent.retrieved: ' . LearningRecord::class, $flip);
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertStatus(409);
        $this->assertSame($this->aId, (int) $lr->fresh()->TeacherID);

        // undo: substitute while still pending, then approve between precheck and transaction
        DB::table('LearningRecord')->where('id', $lr->id)->update(['Status' => 'pending', 'ApprovedAt' => null]);
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
        DB::table('LearningRecord')->where('id', $lr->id)->update(['Status' => 'pending', 'ApprovedAt' => null]);
        \Illuminate\Support\Facades\Event::listen('eloquent.retrieved: ' . LearningRecord::class, $flip);
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute/undo")->assertStatus(409);
    }

    /** #3938 item 3: an approval landing after the batch precheck rolls the batch back with the domain 409, not a 500. */
    public function test_batch_substitute_race_after_precheck_returns_409(): void
    {
        $s = $this->mkSession('scheduled');
        $lr = $this->lr($s, 'pending', true);
        \Illuminate\Support\Facades\Event::listen('eloquent.retrieved: ' . LearningRecord::class, function () use ($lr) {
            \Illuminate\Support\Facades\Event::forget('eloquent.retrieved: ' . LearningRecord::class);
            DB::table('LearningRecord')->where('id', $lr->id)->update(['Status' => 'approved', 'ApprovedAt' => now(), 'ApprovedBy' => 1]);
        });
        $this->api()->postJson('/api/v1/teacher-leaves/batch-substitute', [
            'assignments' => [['class_session_id' => $s->id, 'substitute_teacher_id' => $this->bId]],
        ])->assertStatus(409)->assertJsonPath('rolled_back', true);
        $this->assertSame($this->aId, (int) $lr->fresh()->TeacherID);
    }

    // ---- Codex review r4247039986 / 997 / 4247040009 / 020 / 032 / 043 / 049 ----------------------------------

    public function test_legacy_schedule_writers_without_teacher_id_or_deleting_the_pin_are_refused(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'approved', true);
        $row = $this->schedule(self::DATE, '16:00', '18:00', $this->aId);
        DB::table('StudentClass')->where('ID', $this->sc->ID)->update(['TeacherID' => $this->bId]); // contract moved to B, pin keeps A
        $this->assertLocked($lr, fn () => $this->api()->postJson('/api/v1/schedules', [ // teacher inferred from the contract (B)
            'student_id' => $this->sc->StudentID, 'day_of_week' => 5, 'start_time' => '16:00', 'end_time' => '18:00',
            'branch_id' => 1, 'schedule_date' => self::DATE, 'student_course_id' => $this->sc->ID, 'status' => 'scheduled', 'original_schedule_id' => $row->id,
        ]));
        $this->assertLocked($lr, fn () => $this->api()->putJson("/api/v1/schedules/{$row->id}", ['status' => 'cancelled']));
        $this->assertLocked($lr, fn () => $this->api()->putJson("/api/v1/schedules/{$row->id}", ['start_time' => '19:00', 'end_time' => '21:00']));
        $this->assertLocked($lr, fn () => $this->api()->deleteJson("/api/v1/schedules/{$row->id}"));
        $this->api()->putJson("/api/v1/schedules/{$row->id}", ['end_time' => '18:00'])->assertOk(); // non-moving edit still allowed
    }

    public function test_foreign_campus_course_is_rejected_before_the_assessment_is_read(): void
    {
        $other = Student::create(['name' => 'Y student', 'CampusID' => 2, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $foreign = $this->sc->replicate();
        $foreign->StudentID = $other->id;
        $foreign->save();
        $fs = ClassSession::create(['StudentClassID' => $foreign->ID, 'SessionDate' => self::DATE, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'attended']);
        $this->lr($fs, 'approved', true);
        $this->api()->postJson('/api/v1/schedules', [
            'student_id' => $this->sc->StudentID, 'teacher_id' => $this->bId, 'day_of_week' => 5, 'start_time' => '16:00', 'end_time' => '18:00',
            'branch_id' => 1, 'schedule_date' => self::DATE, 'student_course_id' => $foreign->ID, 'status' => 'scheduled',
        ])->assertStatus(422); // not the 409 that would reveal the foreign assessment
    }

    public function test_reassign_between_same_teacher_contracts_is_refused_when_the_assessment_names_another_teacher(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'approved', true);
        DB::table('LearningRecord')->where('id', $lr->id)->update(['TeacherID' => $this->bId]); // substitute B wrote it
        $this->scB->update(['TeacherID' => $this->aId]);
        $this->assertLocked($lr, fn () => $this->api()->postJson("/api/v1/class-sessions/{$s->id}/reassign-contract", ['new_student_class_id' => $this->scB->ID, 'reason' => 'synthetic']), $this->bId);
    }

    public function test_approved_row_without_approved_at_is_still_protected(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'approved', true);
        DB::table('LearningRecord')->where('id', $lr->id)->update(['ApprovedAt' => null]);
        $this->assertLocked($lr, fn () => $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId]));
    }

    // ---- Codex review r4254200253 .. r4254248273 --------------------------------------------------------------

    public function test_schedule_writers_cannot_zero_the_teacher_repost_a_rescheduled_anchor_or_move_onto_the_slot(): void
    {
        $lr = $this->lr($this->mkSession('attended'), 'approved', true);
        $row = $this->schedule(self::DATE, '16:00', '18:00', $this->aId);
        $other = $this->schedule(self::DATE, '19:00', '21:00', $this->bId);
        DB::table('StudentClass')->where('ID', $this->sc->ID)->update(['TeacherID' => $this->bId]);
        $this->assertLocked($lr, fn () => $this->api()->putJson("/api/v1/schedules/{$row->id}", ['teacher_id' => 0])); // 0 falls back to the contract teacher
        $this->assertLocked($lr, fn () => $this->api()->putJson("/api/v1/schedules/{$other->id}", ['schedule_date' => self::DATE, 'start_time' => '16:00', 'end_time' => '18:00']));
        $this->assertLocked($lr, fn () => $this->api()->postJson('/api/v1/schedules', [
            'student_id' => $this->sc->StudentID, 'teacher_id' => $this->aId, 'day_of_week' => 5, 'start_time' => '16:00', 'end_time' => '18:00',
            'branch_id' => 1, 'schedule_date' => self::DATE, 'student_course_id' => $this->sc->ID, 'status' => 'rescheduled', 'original_schedule_id' => $row->id,
        ])); // the rescheduled branch would delete the pin row
    }

    public function test_an_approved_makeup_cannot_be_deleted_or_cancelled_and_its_assessment_cannot_be_deleted(): void
    {
        $lr = $this->lr($this->mkSession('attended'), 'approved', true);
        $this->approve($lr, $this->bId); // substitute B taught it; the makeup row keeps the contract teacher A
        $row = $this->schedule(self::DATE, '16:00', '18:00', $this->aId);
        $row->update(['type' => 'extra']);
        $this->assertLocked($lr, fn () => $this->api()->deleteJson("/api/v1/schedules/{$row->id}"), $this->bId);
        $this->assertLocked($lr, fn () => $this->api()->postJson("/api/v1/schedules/{$row->id}/cancel-makeup"), $this->bId);
        $this->assertLocked($lr, fn () => $this->api()->deleteJson("/api/v1/learning-records/{$lr->id}"), $this->bId);
        $this->assertNull(LearningRecord::find($lr->id)->VoidedAt);
    }

    public function test_substitute_repair_that_keeps_the_assessments_teacher_is_allowed(): void
    {
        $s = $this->mkSession('scheduled');
        $lr = $this->lr($s, 'approved', true);
        DB::table('StudentClass')->where('ID', $this->sc->ID)->update(['TeacherID' => $this->bId]); // stale pin: the contract teacher B is effective
        $this->api()->postJson("/api/v1/class-sessions/{$s->id}/substitute", ['substitute_teacher_id' => $this->aId])->assertOk();
        $this->assertSame($this->aId, (int) $lr->fresh()->TeacherID);
    }

    /** #3966: the batch leave workflow allows the same repair; a reassignment is still refused (test_batch_substitute_is_refused). */
    public function test_batch_substitute_repair_that_keeps_the_assessments_teacher_is_allowed(): void
    {
        $s = $this->mkSession('scheduled');
        $lr = $this->lr($s, 'approved', true);
        DB::table('StudentClass')->where('ID', $this->sc->ID)->update(['TeacherID' => $this->bId]); // stale pin: the contract teacher B is effective
        $this->api()->postJson('/api/v1/teacher-leaves/batch-substitute', [
            'assignments' => [['class_session_id' => $s->id, 'substitute_teacher_id' => $this->aId]],
        ])->assertOk()->assertJsonPath('summary.success', 1)->assertJsonPath('results.0.ok', true);
        $this->assertSame($this->aId, (int) $lr->fresh()->TeacherID);
    }

    /** #3979 (1): a type change takes a makeup row out of the makeup branch, so it is refused like a move. */
    public function test_a_type_change_on_a_protected_makeup_row_is_refused(): void
    {
        $lr = $this->lr($this->mkSession('attended'), 'approved', true);
        $this->approve($lr, $this->bId); // substitute B taught it; the makeup row keeps the contract teacher A
        $row = $this->schedule(self::DATE, '16:00', '18:00', $this->aId);
        $row->update(['type' => 'extra']);
        $this->assertLocked($lr, fn () => $this->api()->putJson("/api/v1/schedules/{$row->id}", ['type' => 'normal']), $this->bId);
        $this->assertSame('extra', (string) $row->fresh()->type);
    }

    /** #3979 (2): a director may edit an approved assessment but not empty it, since the lock reads the text. */
    public function test_an_approved_assessment_cannot_be_edited_down_to_no_content(): void
    {
        $lr = $this->lr($this->mkSession('attended'), 'approved', true);
        $this->api()->putJson("/api/v1/learning-records/{$lr->id}", ['Content' => '（評量表）', 'Progress' => ''])
            ->assertStatus(422)->assertJsonPath('code', 'approved_assessment_content_required');
        $fresh = $lr->fresh();
        $this->assertSame('老師實際填寫的評量正文', (string) $fresh->Content);
        $this->assertSame('第三章', (string) $fresh->Progress);
        $this->assertSame('approved', (string) $fresh->Status);

        // A drifted time mirror makes the update heal (and save) the record; the refusal must come first (Codex r4347282693).
        DB::table('LearningRecord')->where('id', $lr->id)->update(['StartTime' => '15:00:00']);
        $this->api()->putJson("/api/v1/learning-records/{$lr->id}", ['Content' => '（評量表）', 'Progress' => ''])->assertStatus(422);
        $drifted = $lr->fresh();
        $this->assertSame('老師實際填寫的評量正文', (string) $drifted->Content);
        $this->assertSame('第三章', (string) $drifted->Progress);
        $this->assertSame($this->aId, (int) $drifted->TeacherID);

        $this->api()->putJson("/api/v1/learning-records/{$lr->id}", ['Content' => '主任修正後的評量正文', 'Progress' => ''])->assertOk();
        $this->assertSame('approved', (string) $lr->fresh()->Status, 'an edit that keeps content stays approved');
        $this->assertSame('主任修正後的評量正文', (string) $lr->fresh()->Content);
    }

    public function test_transfer_and_recover_transfer_to_another_teachers_contract_are_refused(): void
    {
        $s = $this->mkSession('attended');
        $lr = $this->lr($s, 'approved', true);
        $body = ['session_ids' => [$s->id], 'target_student_class_id' => $this->scB->ID, 'reason' => 'synthetic'];
        $this->assertLocked($lr, fn () => $this->api()->postJson("/api/v1/student-classes/{$this->sc->ID}/transfer-sessions", $body));
        $this->assertLocked($lr, fn () => $this->api()->postJson("/api/v1/student-classes/{$this->sc->ID}/recover-transfer-sessions", $body));
    }

    public function test_reschedule_without_teacher_id_keeps_the_assessments_teacher_on_the_moved_occurrence(): void
    {
        $lr = $this->lr($this->mkSession('attended'), 'approved', true);
        DB::table('StudentClass')->where('ID', $this->sc->ID)->update(['TeacherID' => $this->bId]);
        $this->api()->postJson('/api/v1/learning-records/reschedule-session', ['student_class_id' => $this->sc->ID, 'old_date' => self::DATE,
            'old_start_time' => '16:00', 'new_date' => '2026-04-20', 'start_time' => '16:00', 'end_time' => '18:00', 'ensure_schedule_exception' => true])->assertOk();
        $this->assertSame($this->aId, (int) DB::table('schedules')->whereDate('schedule_date', '2026-04-20')->value('teacher_id'));
        $this->assertSame($this->aId, (int) $lr->fresh()->TeacherID);
    }

    public function test_ensure_past_and_atomic_reschedule_do_not_rewrite_the_recorded_teacher(): void
    {
        $lr = $this->lr($this->mkSession('attended'), 'approved', true);
        DB::table('StudentClass')->where('ID', $this->sc->ID)->update(['TeacherID' => $this->bId]);
        $this->api()->postJson('/api/v1/learning-records/ensure-past', ['branch_id' => 1])->assertOk();
        $this->assertSame($this->aId, (int) $lr->fresh()->TeacherID);
        $this->assertLocked($lr, fn () => $this->api()->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => $this->sc->ID, 'old_date' => self::DATE, 'old_start_time' => '16:00', 'new_date' => '2026-04-20',
            'start_time' => '16:00', 'end_time' => '18:00', 'teacher_id' => $this->bId,
        ]));
        $this->assertLocked($lr, fn () => $this->api()->postJson('/api/v1/learning-records/reschedule-session', [ // legacy caller: no old_start_time
            'student_class_id' => $this->sc->ID, 'old_date' => self::DATE, 'new_date' => self::DATE, 'start_time' => '18:00', 'end_time' => '20:00', 'teacher_id' => $this->bId,
        ]));
    }

    public function test_contract_change_keeps_a_protected_child_of_a_stale_anchor_and_reads_the_bound_session_slot(): void
    {
        $day = '2026-04-18';
        $this->lr($this->mkSession('attended', $day), 'approved', true, $day);
        DB::table('LearningRecord')->update(['SessionDate' => '2026-01-01', 'StartTime' => '09:00:00']); // drifted mirror columns
        $anchor = $this->schedule($day, '16:00', '18:00', $this->aId);
        $anchor->update(['status' => 'rescheduled']);
        $kept = $this->schedule($day, '16:00', '18:00', $this->aId);
        $drop = $this->schedule($day, '20:00', '22:00', $this->aId);
        Schedule::whereIn('id', [$kept->id, $drop->id])->update(['original_schedule_id' => $anchor->id]);
        $this->api()->putJson("/api/v1/student-classes/{$this->sc->ID}", ['teacher_id' => $this->bId])->assertOk();
        $this->assertTrue(Schedule::whereKey($kept->id)->exists(), 'the protected pin survives the stale-anchor cleanup');
        $this->assertSame($this->aId, (int) Schedule::find($kept->id)->teacher_id);
    }

    private function schedule(string $date, string $start, string $end, int $teacherId): Schedule
    {
        return Schedule::create([
            'student_id' => $this->sc->StudentID, 'teacher_id' => $teacherId, 'subject' => 'Math', 'day_of_week' => 5, 'start_time' => $start, 'end_time' => $end,
            'duration_hours' => 2, 'class_type' => 'regular', 'status' => 'scheduled', 'type' => 'normal', 'deduction' => 1, 'branch_id' => 1,
            'schedule_date' => $date, 'student_course_id' => $this->sc->ID,
        ]);
    }

    // ---- NOT protected: legitimate corrections still work and are audited -------------------------------------

    public function test_blank_or_system_filled_approved_row_can_still_be_corrected(): void
    {
        foreach (['', '（系統補登/扣除堂數）', '（評量表）', '補登／納入科目數'] as $i => $content) {
            $s = $this->mkSession('attended', '2026-04-0' . ($i + 1));
            $lr = $this->lr($s, 'approved', false, '2026-04-0' . ($i + 1), $content);
            $tscA = (int) User::find($this->aId)->TeachingSessionCount;
            $this->api()->patchJson("/api/v1/learning-records/{$lr->id}/teacher", ['TeacherID' => $this->bId, 'reason' => 'wrongly named at attendance'])->assertOk();
            $this->assertSame($this->bId, (int) $lr->fresh()->TeacherID);
            $this->assertSame($tscA - 1, (int) User::find($this->aId)->TeachingSessionCount, 'moved once, no double count');
            $this->assertDatabaseHas('learning_record_teacher_changes', ['learning_record_id' => $lr->id, 'old_teacher_id' => $this->aId, 'new_teacher_id' => $this->bId, 'reason' => 'wrongly named at attendance', 'changed_by' => $this->dirId]); // who/what/why audit snapshot
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
        $this->assertDatabaseHas('learning_record_teacher_changes', ['learning_record_id' => $lr->id, 'new_teacher_id' => $this->bId, 'reason' => 'after rollback', 'changed_by' => $this->dirId]);
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
